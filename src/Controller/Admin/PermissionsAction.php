<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Repository\StationRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;

class PermissionsAction
{
    public function __invoke(
        ServerRequest $request,
        Response $response,
        StationRepository $stationRepo
    ): ResponseInterface {
        $router = $request->getRouter();

        $actions = $request->getAcl()->listPermissions();

        return $request->getView()->renderVuePage(
            response: $response,
            component: 'Vue_AdminPermissions',
            id: 'admin-permissions',
            title: __('Roles & Permissions'),
            props: [
                'listUrl' => (string)$router->fromHere('api:admin:roles'),
                'stations' => $stationRepo->fetchSelect(),
                'globalPermissions' => $actions['global'],
                'stationPermissions' => $actions['station'],
            ]
        );
    }
}
