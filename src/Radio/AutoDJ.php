<?php

declare(strict_types=1);

namespace App\Radio;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity;
use App\Environment;
use App\Event\Radio\AnnotateNextSong;
use App\Event\Radio\BuildQueue;
use App\LockFactory;
use App\Radio\AutoDJ\Scheduler;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\EventDispatcher\EventDispatcherInterface;

class AutoDJ
{
    public function __construct(
        protected ReloadableEntityManagerInterface $em,
        protected Entity\Repository\SongHistoryRepository $songHistoryRepo,
        protected Entity\Repository\StationQueueRepository $queueRepo,
        protected EventDispatcherInterface $dispatcher,
        protected Logger $logger,
        protected Scheduler $scheduler,
        protected Environment $environment,
        protected LockFactory $lockFactory
    ) {
    }

    /**
     * Pulls the next song from the AutoDJ, dispatches the AnnotateNextSong event and returns the built result.
     *
     * @param Entity\Station $station
     * @param bool $asAutoDj
     * @param int $iteration The iteration of the current attempt to
     */
    public function annotateNextSong(
        Entity\Station $station,
        bool $asAutoDj = false,
        int $iteration = 1
    ): string {
        if ($iteration > 3) {
            $this->logger->error(
                'Too many attempts to get next song; giving up.',
                [
                    'station' => [
                        'id' => $station->getId(),
                        'name' => $station->getName(),
                    ],
                ]
            );
            return '';
        }

        $queueRow = $this->queueRepo->getNextToSendToAutoDj($station);

        // Try to rebuild the queue if it's empty.
        if (!($queueRow instanceof Entity\StationQueue)) {
            $this->logger->info(
                'Queue is empty; rebuilding before attempting to get next song.',
                [
                    'station' => [
                        'id' => $station->getId(),
                        'name' => $station->getName(),
                    ],
                ]
            );

            $this->buildQueue($station);
            return $this->annotateNextSong($station, $asAutoDj, $iteration + 1);
        }

        // Check that the song coming up isn't the same song as what's currently being played.
        $currentSong = $this->songHistoryRepo->getCurrent($station);
        if (
            ($currentSong instanceof Entity\SongHistory)
            && $queueRow->getSongId() === $currentSong->getSongId()
        ) {
            $this->em->remove($queueRow);
            $this->em->flush();

            $this->logger->info(
                'Queue would play the same song again; removing and attempting to get next song.',
                [
                    'station' => [
                        'id' => $station->getId(),
                        'name' => $station->getName(),
                    ],
                ]
            );

            return $this->annotateNextSong($station, $asAutoDj, $iteration + 1);
        }

        $event = new AnnotateNextSong($queueRow, $asAutoDj);
        $this->dispatcher->dispatch($event);

        // Refill station queue while taking into context that LS queues songs 40s before they are played
        $this->buildQueue($station, true);

        return $event->buildAnnotations();
    }

    public function buildQueue(
        Entity\Station $station,
        bool $force = false
    ): void {
        $lock = $this->lockFactory->createAndAcquireLock(
            resource: 'autodj_queue_' . $station->getId(),
            ttl: 60,
            force: $force
        );

        if (false === $lock) {
            return;
        }

        try {
            $this->logger->pushProcessor(
                function ($record) use ($station) {
                    $record['extra']['station'] = [
                        'id' => $station->getId(),
                        'name' => $station->getName(),
                    ];
                    return $record;
                }
            );

            // Adjust "expectedCueTime" time from current queue.
            $tzObject = $station->getTimezoneObject();
            $expectedCueTime = CarbonImmutable::now($tzObject);

            // Get expected play time of each item.
            $currentSong = $this->songHistoryRepo->getCurrent($station);
            if (null !== $currentSong) {
                $expectedPlayTime = $this->addDurationToTime(
                    $station,
                    CarbonImmutable::createFromTimestamp($currentSong->getTimestampStart(), $tzObject),
                    $currentSong->getDuration()
                );

                if ($expectedPlayTime < $expectedCueTime) {
                    $expectedPlayTime = $expectedCueTime;
                }
            } else {
                $expectedPlayTime = $expectedCueTime;
            }

            $maxQueueLength = $station->getBackendConfig()->getAutoDjQueueLength();
            if ($maxQueueLength < 2) {
                $maxQueueLength = 2;
            }

            $upcomingQueue = $this->queueRepo->getUnplayedQueue($station);

            $lastSongId = null;
            $queueLength = 0;

            foreach ($upcomingQueue as $queueRow) {
                // Prevent the exact same track from being played twice during this loop
                if (null !== $lastSongId && $lastSongId === $queueRow->getSongId()) {
                    $this->em->remove($queueRow);
                    continue;
                }

                $queueLength++;
                $lastSongId = $queueRow->getSongId();

                if ($queueRow->getSentToAutodj()) {
                    $expectedCueTime = $this->addDurationToTime(
                        $station,
                        CarbonImmutable::createFromTimestamp($queueRow->getTimestampCued(), $tzObject),
                        $queueRow->getDuration()
                    );
                } else {
                    $queueRow->setTimestampCued($expectedCueTime->getTimestamp());
                    $queueRow->setTimestampPlayed($expectedPlayTime->getTimestamp());
                    $this->em->persist($queueRow);

                    $expectedCueTime = $this->addDurationToTime($station, $expectedCueTime, $queueRow->getDuration());
                }

                $expectedPlayTime = $this->addDurationToTime($station, $expectedPlayTime, $queueRow->getDuration());
            }

            $this->em->flush();

            // Build the remainder of the queue.
            while ($queueLength < $maxQueueLength) {
                $queueRow = $this->cueNextSong($station, $expectedCueTime, $expectedPlayTime);
                if ($queueRow instanceof Entity\StationQueue) {
                    $this->em->persist($queueRow);

                    // Prevent the exact same track from being played twice during this loop
                    if (null !== $lastSongId && $lastSongId === $queueRow->getSongId()) {
                        $this->em->remove($queueRow);
                    } else {
                        $lastSongId = $queueRow->getSongId();

                        $expectedCueTime = $this->addDurationToTime(
                            $station,
                            $expectedCueTime,
                            $queueRow->getDuration()
                        );
                        $expectedPlayTime = $this->addDurationToTime(
                            $station,
                            $expectedPlayTime,
                            $queueRow->getDuration()
                        );
                    }
                } else {
                    break;
                }

                $this->em->flush();
                $queueLength++;
            }

            $this->logger->popProcessor();
        } finally {
            $lock->release();
        }
    }

    protected function addDurationToTime(Entity\Station $station, CarbonInterface $now, ?int $duration): CarbonInterface
    {
        $duration ??= 1;

        $startNext = $station->getBackendConfig()->getCrossfadeDuration();

        $now = $now->addSeconds($duration);
        return ($duration >= $startNext)
            ? $now->subMilliseconds((int)($startNext * 1000))
            : $now;
    }

    protected function cueNextSong(
        Entity\Station $station,
        CarbonInterface $expectedCueTime,
        CarbonInterface $expectedPlayTime
    ): ?Entity\StationQueue {
        $this->logger->debug(
            'Adding to station queue.',
            [
                'now' => (string)$expectedPlayTime,
            ]
        );

        // Push another test handler specifically for this one queue task.
        $testHandler = new TestHandler($this->environment->getLogLevel(), true);
        $this->logger->pushHandler($testHandler);

        $event = new BuildQueue($station, $expectedCueTime, $expectedPlayTime);
        $this->dispatcher->dispatch($event);

        $this->logger->popHandler();

        $queueRow = $event->getNextSong();
        if ($queueRow instanceof Entity\StationQueue) {
            $queueRow->setTimestampCued($expectedCueTime->getTimestamp());
            $queueRow->setTimestampPlayed($expectedPlayTime->getTimestamp());

            $queueRow->setLog($testHandler->getRecords());
        }

        return $queueRow;
    }
}
