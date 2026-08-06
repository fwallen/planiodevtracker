<?php
declare(strict_types=1);

use App\db\Database;
use App\services\PlanioService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require_once __DIR__ . '/../planio_mapping.php';

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

// Columns every caller needs to refresh a tracked task from a Plan.io issue.
const PLANIO_TASK_COLUMNS = 'id, planio_issue_id, status, planio_status, tracking_enabled';

// Refreshes an already-tracked task from its Plan.io issue and returns the status
// it ended up in. The single write path for sync's main loop, sync's drop-out
// reconcile pass, and manual import — keeping them here is what stops one path
// from tracking fewer columns than another.
//
// $forceTracking re-surfaces the task regardless of whether anything changed;
// import sets it because asking for an RM by number is an explicit request to see
// it on the board.
function updateTaskFromIssue(\PDO $db, array $row, array $issue, bool $forceTracking = false): string
{
    $fields    = taskFieldsFromIssue($issue);
    $newStatus = resolvePlanioStatus(
        $row['status'],
        mapPlanioStatus((string)$fields['planio_status']),
        $fields['deploy_approval'],
        $row['planio_status']
    );

    // A real status change on the RM re-surfaces a task the developer stopped
    // tracking; a mere title/project refresh does not.
    $trackingEnabled = ($forceTracking || $newStatus !== $row['status'])
        ? 1
        : (int)$row['tracking_enabled'];

    $db->prepare(
        'UPDATE tasks SET title = ?, project = ?, assignee = ?, due_date = ?, deploy_approval = ?,
                          planio_status = ?, status = ?, tracking_enabled = ?
         WHERE id = ?'
    )->execute([
        $fields['title'],
        $fields['project'],
        $fields['assignee'],
        $fields['due_date'],
        $fields['deploy_approval'],
        $fields['planio_status'],
        $newStatus,
        $trackingEnabled,
        (int)$row['id'],
    ]);

    return $newStatus;
}

// Inserts a task for a Plan.io issue we have never seen. `requester` is only set
// here — an issue's author doesn't change, so refreshes leave it alone.
function insertTaskFromIssue(\PDO $db, array $issue): void
{
    $fields = taskFieldsFromIssue($issue);

    $db->prepare(
        'INSERT INTO tasks (planio_issue_id, title, project, requester, assignee, due_date,
                            deploy_approval, planio_status, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        (int)$issue['id'],
        $fields['title'],
        $fields['project'],
        $issue['author']['name'] ?? null,
        $fields['assignee'],
        $fields['due_date'],
        $fields['deploy_approval'],
        $fields['planio_status'],
        mapPlanioStatus((string)$fields['planio_status']),
    ]);
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

        $existing = $db->prepare('SELECT ' . PLANIO_TASK_COLUMNS . ' FROM tasks WHERE planio_issue_id = ?');
        $existing->execute([$planioId]);
        $row = $existing->fetch(\PDO::FETCH_ASSOC);

        $created = !$row;
        if ($row) {
            // A manual import is an explicit ask to bring this RM back onto the
            // board, so it always re-enables tracking — even if nothing else
            // about the RM changed.
            updateTaskFromIssue($db, $row, $issue, forceTracking: true);
        } else {
            insertTaskFromIssue($db, $issue);
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
            $existing = $db->prepare('SELECT ' . PLANIO_TASK_COLUMNS . ' FROM tasks WHERE planio_issue_id = ?');
            $existing->execute([(int)$issue['id']]);
            $row = $existing->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                // title/project/assignee/due_date/deploy_approval always track
                // Plan.io. status is developer-owned once past 'new' (see
                // resolvePlanioStatus), except: a resolved/closed ticket forces
                // 'done', a deploy approval nudges in-flight tasks →
                // feedback_received, and an RM moving out of "Feedback" upstream is
                // a hand-back → feedback_received.
                updateTaskFromIssue($db, $row, $issue);
                $updated++;
            } else {
                insertTaskFromIssue($db, $issue);
                $new++;
            }
        }

        // Reconcile tasks that dropped out of the open-issue set. The sync query is
        // restricted to issues open *and* assigned to us, so a ticket that was
        // resolved upstream or handed to someone else never appears above — it just
        // goes missing. Re-fetch every not-yet-done tracked task that wasn't in this
        // sync and refresh it exactly as the main loop would.
        //
        // These tasks go through the same updateTaskFromIssue() as everything else.
        // This pass used to write a hand-picked subset of columns, which is why a
        // task assigned away kept a stale assignee and title for as long as it
        // stayed away — the RM most likely to be edited by someone else was the one
        // we refreshed least.
        //
        // The predicate is every queue except 'done' rather than an explicit status
        // list: an omitted queue silently strands tasks forever (on_hold used to be
        // missing here, so a parked ticket resolved upstream never left On Hold),
        // and any column added later is covered automatically.
        $syncedIds = array_map('intval', array_column($issues, 'id'));
        $unfinished = $db->query(
            'SELECT ' . PLANIO_TASK_COLUMNS . " FROM tasks
             WHERE planio_issue_id IS NOT NULL
               AND status <> 'done'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($unfinished as $task) {
            $pid = (int)$task['planio_issue_id'];
            if (in_array($pid, $syncedIds, true)) {
                continue; // already handled in the loop above
            }
            try {
                $issue = $planio->fetchIssue($pid);
            } catch (\Throwable $e) {
                continue; // deleted or inaccessible upstream — leave local state alone
            }

            if (updateTaskFromIssue($db, $task, $issue) !== $task['status']) {
                $updated++;
            }
        }

        return json($response, ['imported' => $new, 'updated' => $updated, 'total' => count($issues)]);
    } catch (\Throwable $e) {
        return json($response, null, false, $e->getMessage(), 400);
    }
});
