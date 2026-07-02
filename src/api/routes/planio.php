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
    // A deploy approval means the requester has responded, so it lands the task
    // in feedback_received (see resolvePlanioStatus for the developer-owned guard).
    if (mapPlanioApproval($planioStatus) !== null) {
        return 'feedback_received';
    }

    return match (strtolower(trim($planioStatus))) {
        'in progress' => 'in_progress',
        'feedback' => 'awaiting_feedback',
        'on hold' => 'on_hold',
        'resolved', 'closed', 'done', 'rejected' => 'done',
        default => 'new',
    };
}

// Plan.io deploy-approval statuses map to a badge on the card. Returns null for
// any status that isn't an approval so the badge clears once the RM moves on.
function mapPlanioApproval(string $planioStatus): ?string
{
    return match (strtolower(trim($planioStatus))) {
        'approved for staging' => 'staging',
        'approved for production' => 'production',
        default => null,
    };
}

// Plan.io never clobbers a developer-owned status once a task leaves 'new' — with
// one exception: a deploy approval means the requester has responded, so a task
// still in flight (in_progress or awaiting_feedback — the "send for feedback" step
// is easy to forget) is nudged into feedback_received. Done/on_hold are left alone.
// Keeps the sync/import guard in one place.
function resolvePlanioStatus(string $localStatus, string $mappedStatus, ?string $approval): string
{
    if ($localStatus === 'new') {
        return $mappedStatus;
    }
    if ($approval !== null && in_array($localStatus, ['in_progress', 'awaiting_feedback'], true)) {
        return 'feedback_received';
    }
    return $localStatus;
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
        $planioStatus = $issue['status']['name'] ?? '';
        $mappedStatus = mapPlanioStatus($planioStatus);
        $approval     = mapPlanioApproval($planioStatus);

        $existing = $db->prepare('SELECT id, status FROM tasks WHERE planio_issue_id = ?');
        $existing->execute([$planioId]);
        $row = $existing->fetch(\PDO::FETCH_ASSOC);

        $created = !$row;
        if ($row) {
            $newStatus = resolvePlanioStatus($row['status'], $mappedStatus, $approval);
            $db->prepare(
                'UPDATE tasks SET title = ?, project = ?, assignee = ?, due_date = ?, deploy_approval = ?, status = ? WHERE planio_issue_id = ?'
            )->execute([$title, $project, $assignee, $dueDate, $approval, $newStatus, $planioId]);
        } else {
            $db->prepare(
                'INSERT INTO tasks (planio_issue_id, title, project, requester, assignee, due_date, deploy_approval, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$planioId, $title, $project, $requester, $assignee, $dueDate, $approval, $mappedStatus]);
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
            $approval      = mapPlanioApproval($planioStatus);

            $existing = $db->prepare('SELECT id, status FROM tasks WHERE planio_issue_id = ?');
            $existing->execute([$planioId]);
            $row = $existing->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                // title/project/due_date/deploy_approval always track Plan.io.
                // status is developer-owned once past 'new' (see resolvePlanioStatus),
                // except a deploy approval nudges awaiting_feedback → feedback_received.
                $newStatus = resolvePlanioStatus($row['status'], $mappedStatus, $approval);
                $db->prepare(
                    'UPDATE tasks SET title = ?, project = ?, assignee = ?, due_date = ?, deploy_approval = ?, status = ? WHERE planio_issue_id = ?'
                )->execute([$title, $project, $assignee, $dueDate, $approval, $newStatus, $planioId]);
                $updated++;
            } else {
                $db->prepare(
                    'INSERT INTO tasks (planio_issue_id, title, project, requester, assignee, due_date, deploy_approval, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$planioId, $title, $project, $requester, $assignee, $dueDate, $approval, $mappedStatus]);
                $new++;
            }
        }

        return json($response, ['imported' => $new, 'updated' => $updated, 'total' => count($issues)]);
    } catch (\Throwable $e) {
        return json($response, null, false, $e->getMessage(), 400);
    }
});
