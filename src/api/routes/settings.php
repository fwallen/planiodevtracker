<?php
declare(strict_types=1);

use App\db\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->get('/api/settings', function (Request $request, Response $response): Response {
    $db   = Database::get();
    $rows = $db->query('SELECT key_name, value FROM settings')->fetchAll();
    $out  = [];
    foreach ($rows as $row) {
        $out[$row['key_name']] = $row['value'];
    }
    return json($response, $out);
});

$app->post('/api/settings', function (Request $request, Response $response): Response {
    $db   = Database::get();
    $body = (array)$request->getParsedBody();

    $allowed = ['planio_base_url', 'planio_api_key', 'planio_user_id', 'feedback_warning_days'];
    $stmt    = $db->prepare(
        'INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );

    foreach ($allowed as $key) {
        if (array_key_exists($key, $body)) {
            $stmt->execute([$key, (string)$body[$key]]);
        }
    }

    $rows = $db->query('SELECT key_name, value FROM settings')->fetchAll();
    $out  = [];
    foreach ($rows as $row) {
        $out[$row['key_name']] = $row['value'];
    }
    return json($response, $out);
});
