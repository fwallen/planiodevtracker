<?php
declare(strict_types=1);

use App\db\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->post('/api/tasks/{id}/send-feedback', function (Request $request, Response $response, array $args): Response {
    $db   = Database::get();
    $id   = (int)$args['id'];
    $body = (array)$request->getParsedBody();
    $note = $body['note'] ?? null;

    $task = $db->prepare('SELECT id FROM tasks WHERE id = ?');
    $task->execute([$id]);
    if (!$task->fetch()) {
        return json($response, null, false, 'Task not found', 404);
    }

    // Atomic: update task + log entry in one transaction
    $db->beginTransaction();
    $db->prepare(
        'UPDATE tasks SET status = \'awaiting_feedback\', feedback_rounds = feedback_rounds + 1, last_sent_at = NOW() WHERE id = ?'
    )->execute([$id]);
    $db->prepare('INSERT INTO feedback_log (task_id, note) VALUES (?, ?)')->execute([$id, $note]);
    $db->commit();

    $task = $db->prepare('SELECT * FROM tasks WHERE id = ?');
    $task->execute([$id]);
    return json($response, $task->fetch());
});

$app->post('/api/tasks/{id}/got-feedback', function (Request $request, Response $response, array $args): Response {
    $db = Database::get();
    $id = (int)$args['id'];

    $task = $db->prepare('SELECT id FROM tasks WHERE id = ?');
    $task->execute([$id]);
    if (!$task->fetch()) {
        return json($response, null, false, 'Task not found', 404);
    }

    $db->prepare('UPDATE tasks SET status = \'feedback_received\' WHERE id = ?')->execute([$id]);

    $task = $db->prepare('SELECT * FROM tasks WHERE id = ?');
    $task->execute([$id]);
    return json($response, $task->fetch());
});
