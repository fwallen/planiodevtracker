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
        'resolved', 'closed', 'done' => 'done',
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
// two exceptions:
//   1. Plan.io reaching a terminal state (resolved/closed/done → 'done')
//      is authoritative: the ticket is finished, so the local task is marked done
//      no matter what state the developer left it in.
//   2. A deploy approval means the requester has responded, so a task still in
//      flight (in_progress or awaiting_feedback — the "send for feedback" step is
//      easy to forget) is nudged into feedback_received.
// on_hold is left alone. Keeps the sync/import guard in one place.
function resolvePlanioStatus(string $localStatus, string $mappedStatus, ?string $approval): string
{
    if ($localStatus === 'new') {
        return $mappedStatus;
    }
    if ($mappedStatus === 'done') {
        return 'done';
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
        $planio = planioService();
        $issues = $planio->syncIssues();
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
                // except: a resolved/closed ticket forces 'done', and a deploy
                // approval nudges in-flight tasks → feedback_received.
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

        // Reconcile tasks that dropped out of the open-issue set. The sync query
        // is restricted to open issues, so a ticket resolved/closed upstream never
        // appears above — it just goes missing. Re-fetch each still-active tracked
        // task that wasn't in this sync and apply the terminal-status rule so a
        // resolved/closed issue lands in Done. Genuinely-open tasks that merely got
        // reassigned away from us map to a non-terminal status and are left alone.
        $syncedIds = array_map('intval', array_column($issues, 'id'));
        $active = $db->query(
            "SELECT id, planio_issue_id FROM tasks
             WHERE planio_issue_id IS NOT NULL
               AND status IN ('new', 'in_progress', 'awaiting_feedback', 'feedback_received')"
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($active as $task) {
            $pid = (int)$task['planio_issue_id'];
            if (in_array($pid, $syncedIds, true)) {
                continue; // already handled in the loop above
            }
            try {
                $issue = $planio->fetchIssue($pid);
            } catch (\Throwable $e) {
                continue; // deleted or inaccessible upstream — leave local state alone
            }
            if (mapPlanioStatus($issue['status']['name'] ?? '') === 'done') {
                $db->prepare('UPDATE tasks SET status = ? WHERE id = ?')->execute(['done', $task['id']]);
                $updated++;
            }
        }

        return json($response, ['imported' => $new, 'updated' => $updated, 'total' => count($issues)]);
    } catch (\Throwable $e) {
        return json($response, null, false, $e->getMessage(), 400);
    }
});
