<?php
declare(strict_types=1);

// Plan.io ⇄ local translation: which fields a task takes from an issue, and how a
// Plan.io status resolves against the developer-owned local status. Pure functions,
// no DB or HTTP, so the sync rules can be exercised directly by
// tools/test_planio_mapping.php without booting Slim. routes/planio.php is the
// only consumer.

// The task columns that track Plan.io, derived from one issue payload. Every
// caller that refreshes a tracked task goes through here so no code path can
// track a narrower set than another: sync's main loop and its drop-out reconcile
// pass used to keep separate column lists, and the reconcile pass's was shorter,
// so a task assigned away from the developer kept a stale assignee and title for
// as long as it stayed away.
//
// `requester` is deliberately absent — an issue's author never changes, so it is
// only set on insert. `status` is not here either; it is developer-owned and
// resolved separately by resolvePlanioStatus().
function taskFieldsFromIssue(array $issue): array
{
    $planioStatus = trim((string)($issue['status']['name'] ?? ''));

    return [
        'title'           => 'RM' . (int)($issue['id'] ?? 0) . ' - ' . ($issue['subject'] ?? ''),
        'project'         => $issue['project']['name'] ?? null,
        'assignee'        => $issue['assigned_to']['name'] ?? null,
        'due_date'        => $issue['due_date'] ?? null,
        'deploy_approval' => mapPlanioApproval($planioStatus),
        // NULL means "no status recorded" — the value resolvePlanioStatus treats
        // as unknown. Never store '' for a missing status, or the hand-back rule
        // would read it as a real observation.
        'planio_status'   => $planioStatus !== '' ? $planioStatus : null,
    ];
}

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
// three exceptions:
//   1. Plan.io reaching a terminal state (resolved/closed/done → 'done')
//      is authoritative: the ticket is finished, so the local task is marked done
//      no matter what state the developer left it in.
//   2. A deploy approval means the requester has responded, so a task still in
//      flight (in_progress or awaiting_feedback — the "send for feedback" step is
//      easy to forget) is nudged into feedback_received.
//   3. A hand-back: the RM was in Plan.io's "Feedback" state last time we looked
//      and has since moved out of it, which means the requester answered and the
//      ball is back with us → feedback_received.
// on_hold is left alone. Keeps the sync/import guard in one place.
//
// $previousPlanioStatus is the raw Plan.io status name recorded on the last sync
// (tasks.planio_status). The hand-back rule is deliberately gated on it rather
// than on the current status alone: sending for feedback from the app while the
// RM still sits in "In Progress" upstream would otherwise bounce the card
// straight back to feedback_received on the next sync and destroy the
// days-blocked tracking. null means "we have never recorded one" — treated as
// unknown, so no nudge.
function resolvePlanioStatus(
    string $localStatus,
    string $mappedStatus,
    ?string $approval,
    ?string $previousPlanioStatus = null
): string {
    if ($localStatus === 'new') {
        return $mappedStatus;
    }
    if ($mappedStatus === 'done') {
        return 'done';
    }
    if ($approval !== null && in_array($localStatus, ['in_progress', 'awaiting_feedback'], true)) {
        return 'feedback_received';
    }
    if (isHandBack($localStatus, $mappedStatus, $previousPlanioStatus)) {
        return 'feedback_received';
    }
    return $localStatus;
}

// True when the requester has handed the RM back to us: we are waiting on them,
// Plan.io was in "Feedback" when we last synced, and it no longer is. Moving
// upstream to "On hold" is not a hand-back — nobody has answered, the ticket was
// parked — so it leaves the card waiting.
function isHandBack(string $localStatus, string $mappedStatus, ?string $previousPlanioStatus): bool
{
    if ($localStatus !== 'awaiting_feedback' || $previousPlanioStatus === null) {
        return false;
    }
    if (mapPlanioStatus($previousPlanioStatus) !== 'awaiting_feedback') {
        return false;
    }
    return !in_array($mappedStatus, ['awaiting_feedback', 'on_hold'], true);
}
