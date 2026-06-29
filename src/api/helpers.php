<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;

function json(Response $response, mixed $data, bool $success = true, ?string $error = null, int $status = 200): Response
{
    $payload = $success
        ? ['success' => true, 'data' => $data]
        : ['success' => false, 'error' => $error];

    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
}
