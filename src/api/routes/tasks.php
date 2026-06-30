<?php
declare(strict_types=1);

use App\db\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

$app->group('/api/tasks', function (RouteCollectorProxy $group) {

    $group->get('', function (Request $request, Response $response): Response {
        $db = Database::get();
        $params = $request->getQueryParams();

        $sql = 'SELECT * FROM tasks';
        $bindings = [];
        if (!empty($params['status'])) {
            $sql .= ' WHERE status = ?';
            $bindings[] = $params['status'];
        }
        $sql .= ' ORDER BY priority DESC, due_date ASC, created_at ASC';

        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);
        $tasks = $stmt->fetchAll();

        return json($response, $tasks);
    });

    $group->post('', function (Request $request, Response $response): Response {
        $db = Database::get();
        $body = (array)$request->getParsedBody();

        if (empty($body['title'])) {
            return json($response, null, false, 'Title is required', 422);
        }

        $stmt = $db->prepare(
            'INSERT INTO tasks (title, project, requester, status, priority, due_date, notes)
             VALUES (:title, :project, :requester, :status, :priority, :due_date, :notes)'
        );
        $stmt->execute([
            'title'     => trim($body['title']),
            'project'   => $body['project'] ?? null,
            'requester' => $body['requester'] ?? null,
            'status'    => $body['status'] ?? 'new',
            'priority'  => $body['priority'] ?? 2,
            'due_date'  => $body['due_date'] ?? null,
            'notes'     => $body['notes'] ?? null,
        ]);

        $task = $db->query('SELECT * FROM tasks WHERE id = ' . $db->lastInsertId())->fetch();
        return json($response, $task, true, null, 201);
    });

    $group->get('/{id}', function (Request $request, Response $response, array $args): Response {
        $db = Database::get();
        $task = $db->prepare('SELECT * FROM tasks WHERE id = ?');
        $task->execute([(int)$args['id']]);
        $task = $task->fetch();

        if (!$task) {
            return json($response, null, false, 'Task not found', 404);
        }

        $log = $db->prepare('SELECT * FROM feedback_log WHERE task_id = ? ORDER BY sent_at DESC');
        $log->execute([(int)$args['id']]);
        $task['feedback_log'] = $log->fetchAll();

        $links = $db->prepare('SELECT * FROM task_links WHERE task_id = ? ORDER BY created_at ASC');
        $links->execute([(int)$args['id']]);
        $task['links'] = $links->fetchAll();

        return json($response, $task);
    });

    $group->patch('/{id}', function (Request $request, Response $response, array $args): Response {
        $db = Database::get();
        $body = (array)$request->getParsedBody();
        $allowed = ['title', 'project', 'requester', 'status', 'priority', 'due_date', 'notes'];
        $sets = [];
        $values = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $sets[] = "$field = ?";
                $values[] = $body[$field];
            }
        }

        if (empty($sets)) {
            return json($response, null, false, 'No updatable fields provided', 422);
        }

        $values[] = (int)$args['id'];
        $db->prepare('UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);

        $task = $db->prepare('SELECT * FROM tasks WHERE id = ?');
        $task->execute([(int)$args['id']]);
        return json($response, $task->fetch());
    });

    $group->delete('/{id}', function (Request $request, Response $response, array $args): Response {
        $db = Database::get();
        $db->prepare('DELETE FROM tasks WHERE id = ?')->execute([(int)$args['id']]);
        return json($response, null);
    });

    // --- Links ---

    $group->post('/{id}/links', function (Request $request, Response $response, array $args): Response {
        $db   = Database::get();
        $body = (array)$request->getParsedBody();

        if (empty($body['title']) || empty($body['url'])) {
            return json($response, null, false, 'title and url are required', 422);
        }

        $stmt = $db->prepare('INSERT INTO task_links (task_id, title, url) VALUES (?, ?, ?)');
        $stmt->execute([(int)$args['id'], trim($body['title']), trim($body['url'])]);

        $link = $db->query('SELECT * FROM task_links WHERE id = ' . $db->lastInsertId())->fetch();
        return json($response, $link, true, null, 201);
    });

    $group->put('/{id}/links/{link_id}', function (Request $request, Response $response, array $args): Response {
        $db   = Database::get();
        $body = (array)$request->getParsedBody();

        if (empty($body['title']) || empty($body['url'])) {
            return json($response, null, false, 'title and url are required', 422);
        }

        $db->prepare('UPDATE task_links SET title = ?, url = ? WHERE id = ? AND task_id = ?')
           ->execute([trim($body['title']), trim($body['url']), (int)$args['link_id'], (int)$args['id']]);

        $link = $db->prepare('SELECT * FROM task_links WHERE id = ?');
        $link->execute([(int)$args['link_id']]);
        return json($response, $link->fetch());
    });

    $group->delete('/{id}/links/{link_id}', function (Request $request, Response $response, array $args): Response {
        $db = Database::get();
        $db->prepare('DELETE FROM task_links WHERE id = ? AND task_id = ?')
           ->execute([(int)$args['link_id'], (int)$args['id']]);
        return json($response, null);
    });
});
