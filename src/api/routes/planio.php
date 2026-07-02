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

function mapPlanioStatus(string $planioStatus): string
{
    return match (strtolower(trim($planioStatus))) {
        'in progress' => 'in_progress',
        'feedback' => 'awaiting_feedback',
        'on hold' => 'on_hold',
        'resolved', 'closed', 'done', 'rejected' => 'done',
        default => 'new',
    };
}

$app->post('/api/planio/import', function (Request $request, Response $response): Response {
    try {
        $body     = (array)$request->getParsedBody();
        $planioId = (int)($body['rm_id'] ?? 0);
        if ($planioId <= 0) {
            return json($response, null, false, 'Invalid RM ID', 400);
        }

        $db    = Database::get();
        $issue = planioService()->fetchIssue($planioId);

        $title        = 'RM' . $planioId . ' - ' . ($issue['subject'] ?? '');
        $project      = $issue['project']['name'] ?? null;
        $dueDate      = $issue['due_date'] ?? null;
        $requester    = $issue['author']['name'] ?? null;
        $assignee     = $issue['assigned_to']['name'] ?? null;
        $mappedStatus = mapPlanioStatus($issue['status']['name'] ?? '');

        $existing = $db->prepare('SELECT id, status FROM tasks WHERE planio_issue_id = ?');
        $existing->execute([$planioId]);
        $row = $existing->fetch(\PDO::FETCH_ASSOC);

        $created = !$row;
        if ($row) {
            if ($row['status'] === 'new') {
                $db->prepare(
                    'UPDATE tasks SET title = ?, project = ?, assignee = ?, due_date = ?, status = ? WHERE planio_issue_id = ?'
                )->execute([$title, $project, $assignee, $dueDate, $mappedStatus, $planioId]);
            } else {
                $db->prepare(
                    'UPDATE tasks SET title = ?, project = ?, assignee = ?, due_date = ? WHERE planio_issue_id = ?'
                )->execute([$title, $project, $assignee, $dueDate, $planioId]);
            }
        } else {
            $db->prepare(
                'INSERT INTO tasks (planio_issue_id, title, project, requester, assignee, due_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$planioId, $title, $project, $requester, $assignee, $dueDate, $mappedStatus]);
        }

        $task = $db->prepare('SELECT * FROM tasks WHERE planio_issue_id = ?');
        $task->execute([$planioId]);
        return json($response, ['task' => $task->fetch(\PDO::FETCH_ASSOC), 'created' => $created]);
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
            $planioId      = $issue['id'];
            $title         = 'RM' . $planioId . ' - ' . ($issue['subject'] ?? '');
            $project       = $issue['project']['name'] ?? null;
            $dueDate       = $issue['due_date'] ?? null;
            $requester     = $issue['author']['name'] ?? null;
            $assignee      = $issue['assigned_to']['name'] ?? null;
            $planioStatus  = $issue['status']['name'] ?? 'new';
            $mappedStatus  = mapPlanioStatus($planioStatus);

            $existing = $db->prepare('SELECT id, status FROM tasks WHERE planio_issue_id = ?');
            $existing->execute([$planioId]);
            $row = $existing->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                // Update title/project/due_date always; sync status only when still at 'new'
                // (preserves developer-owned states like awaiting_feedback)
                if ($row['status'] === 'new') {
                    $db->prepare(
                        'UPDATE tasks SET title = ?, project = ?, assignee = ?, due_date = ?, status = ? WHERE planio_issue_id = ?'
                    )->execute([$title, $project, $assignee, $dueDate, $mappedStatus, $planioId]);
                } else {
                    $db->prepare(
                        'UPDATE tasks SET title = ?, project = ?, assignee = ?, due_date = ? WHERE planio_issue_id = ?'
                    )->execute([$title, $project, $assignee, $dueDate, $planioId]);
                }
                $updated++;
            } else {
                $db->prepare(
                    'INSERT INTO tasks (planio_issue_id, title, project, requester, assignee, due_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$planioId, $title, $project, $requester, $assignee, $dueDate, $mappedStatus]);
                $new++;
            }
        }

        return json($response, ['imported' => $new, 'updated' => $updated, 'total' => count($issues)]);
    } catch (\Throwable $e) {
        return json($response, null, false, $e->getMessage(), 400);
    }
});
