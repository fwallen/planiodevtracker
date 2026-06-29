<?php
declare(strict_types=1);

use App\db\Database;
use App\services\PlanioService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

function planioService(): PlanioService
{
    $db   = Database::get();
    $rows = $db->query("SELECT key_name, value FROM settings WHERE key_name IN ('planio_base_url','planio_api_key')")->fetchAll();
    $cfg  = array_column($rows, 'value', 'key_name');

    if (empty($cfg['planio_base_url']) || empty($cfg['planio_api_key'])) {
        throw new \RuntimeException('Plan.io is not configured. Add base URL and API key in Settings.');
    }

    return new PlanioService($cfg['planio_base_url'], $cfg['planio_api_key']);
}

$app->get('/api/planio/status', function (Request $request, Response $response): Response {
    try {
        $user = planioService()->status();
        return json($response, $user);
    } catch (\Throwable $e) {
        return json($response, null, false, $e->getMessage(), 400);
    }
});

$app->get('/api/planio/sync', function (Request $request, Response $response): Response {
    try {
        $db     = Database::get();
        $issues = planioService()->syncIssues();
        $new    = 0;
        $updated = 0;

        foreach ($issues as $issue) {
            $planioId = $issue['id'];
            $title    = $issue['subject'] ?? '';
            $project  = $issue['project']['name'] ?? null;
            $dueDate  = $issue['due_date'] ?? null;
            $requester = $issue['author']['name'] ?? null;

            $existing = $db->prepare('SELECT id FROM tasks WHERE planio_issue_id = ?');
            $existing->execute([$planioId]);

            if ($existing->fetch()) {
                $db->prepare(
                    'UPDATE tasks SET title = ?, project = ?, due_date = ? WHERE planio_issue_id = ?'
                )->execute([$title, $project, $dueDate, $planioId]);
                $updated++;
            } else {
                $db->prepare(
                    'INSERT INTO tasks (planio_issue_id, title, project, requester, due_date, status) VALUES (?, ?, ?, ?, ?, \'new\')'
                )->execute([$planioId, $title, $project, $requester, $dueDate]);
                $new++;
            }
        }

        return json($response, ['imported' => $new, 'updated' => $updated, 'total' => count($issues)]);
    } catch (\Throwable $e) {
        return json($response, null, false, $e->getMessage(), 400);
    }
});
