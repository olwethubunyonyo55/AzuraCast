<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Console\Command\GenerateApiDocsCommand;
use App\Http\Response;
use App\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;

class OpenApiAction
{
    public function __invoke(
        ServerRequest $request,
        Response $response,
        GenerateApiDocsCommand $apiDocsCommand
    ): ResponseInterface {
        $apiBaseUrl = str_replace(
            '/openapi.yml',
            '',
            (string)$request->getRouter()->fromHere(absolute: true)
        );

        $yaml = $apiDocsCommand->generate(true, $apiBaseUrl)->toYaml();

        $response->getBody()->write($yaml);
        return $response->withHeader('Content-Type', 'text/x-yaml');
    }
}
