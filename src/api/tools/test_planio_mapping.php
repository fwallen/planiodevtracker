<?php
declare(strict_types=1);

// Exercises the Plan.io → local mapping rules against the real production code.
// No framework in this project, so this is a plain script:
//
//   docker compose exec -T app php /var/www/api/tools/test_planio_mapping.php

require __DIR__ . '/../planio_mapping.php';

$pass = 0;
$fail = 0;

function check(bool $ok, string $desc, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        printf("  ok   %s\n", $desc);
    } else {
        $fail++;
        printf("  FAIL %s\n", $desc);
        if ($detail !== '') {
            printf("       %s\n", $detail);
        }
    }
}

// ── Status resolution ─────────────────────────────────────────────────────────
// Cases are (local status, last-seen Plan.io status, current Plan.io status) →
// expected local status, mirroring exactly what routes/planio.php computes.
echo "Status resolution\n";

$cases = [
    // description, local, previous Plan.io status, current Plan.io status, expected
    ['requester hands an RM back: Feedback → In Progress',
        'awaiting_feedback', 'Feedback', 'In Progress', 'feedback_received'],
    ['requester hands back to a fresh/New state',
        'awaiting_feedback', 'Feedback', 'New', 'feedback_received'],

    // The false positive this rule must not cause: sending for feedback from the
    // app without touching Plan.io leaves the RM sitting in In Progress. That is
    // not a hand-back, so the card must keep waiting.
    ['sent for feedback locally, Plan.io never left In Progress',
        'awaiting_feedback', 'In Progress', 'In Progress', 'awaiting_feedback'],
    ['last-seen Plan.io status unknown (pre-migration row)',
        'awaiting_feedback', null, 'In Progress', 'awaiting_feedback'],

    ['still waiting: Plan.io still in Feedback',
        'awaiting_feedback', 'Feedback', 'Feedback', 'awaiting_feedback'],
    ['parked upstream is not a hand-back',
        'awaiting_feedback', 'Feedback', 'On hold', 'awaiting_feedback'],

    // Pre-existing rules must survive.
    ['terminal status still wins over a hand-back',
        'awaiting_feedback', 'Feedback', 'Resolved', 'done'],
    ['deploy approval still nudges',
        'awaiting_feedback', 'Feedback', 'Approved for staging', 'feedback_received'],
    ['in_progress is not affected by the hand-back rule',
        'in_progress', 'Feedback', 'In Progress', 'in_progress'],
    ['a parked task stays parked',
        'on_hold', 'Feedback', 'In Progress', 'on_hold'],
    ['a new task always takes the Plan.io status',
        'new', 'Feedback', 'In Progress', 'in_progress'],
    ['terminal status still moves a parked task to done',
        'on_hold', 'Feedback', 'Closed', 'done'],
];

foreach ($cases as [$desc, $local, $prev, $current, $expected]) {
    $actual = resolvePlanioStatus(
        $local,
        mapPlanioStatus($current),
        mapPlanioApproval($current),
        $prev
    );

    check(
        $actual === $expected,
        $desc,
        sprintf('local=%s prev=%s planio=%s | expected %s, got %s',
            $local, $prev ?? 'null', $current, $expected, $actual)
    );
}

// ── Field extraction ──────────────────────────────────────────────────────────
// The set of columns a task takes from its Plan.io issue. This lives in one
// function because sync's main loop and its drop-out reconcile pass previously
// each had their own column list, and the reconcile pass's was narrower — so a
// task assigned away from you kept a stale assignee and title indefinitely.
echo "\nField extraction\n";

$issue = [
    'id'          => 15497,
    'subject'     => 'Global - Invalid characters remain on vehicle plates',
    'project'     => ['name' => 'Enterprise: Stallion'],
    'assigned_to' => ['name' => 'Misun Gweon'],
    'author'      => ['name' => 'Misun Gweon'],
    'due_date'    => '2026-08-14',
    'status'      => ['name' => 'Feedback'],
];

$fields = taskFieldsFromIssue($issue);

$expectedFields = [
    'title'           => 'RM15497 - Global - Invalid characters remain on vehicle plates',
    'project'         => 'Enterprise: Stallion',
    'assignee'        => 'Misun Gweon',
    'due_date'        => '2026-08-14',
    'deploy_approval' => null,
    'planio_status'   => 'Feedback',
];

foreach ($expectedFields as $key => $want) {
    check(
        array_key_exists($key, $fields) && $fields[$key] === $want,
        sprintf('extracts %s', $key),
        sprintf('expected %s, got %s', var_export($want, true), var_export($fields[$key] ?? '(absent)', true))
    );
}

check(
    array_keys($fields) === array_keys($expectedFields),
    'tracks exactly the expected column set',
    'got: ' . implode(', ', array_keys($fields))
);

// An issue can legitimately be unassigned, or carry an approval status.
$unassigned = taskFieldsFromIssue(['id' => 1, 'subject' => 'x', 'status' => ['name' => 'New']]);
check($unassigned['assignee'] === null, 'unassigned issue yields a null assignee');
check($unassigned['project'] === null, 'projectless issue yields a null project');
check($unassigned['due_date'] === null, 'issue with no due date yields null');

$approved = taskFieldsFromIssue(['id' => 2, 'subject' => 'y', 'status' => ['name' => 'Approved for Production']]);
check($approved['deploy_approval'] === 'production', 'approval status yields a deploy_approval');
check($approved['planio_status'] === 'Approved for Production', 'planio_status keeps the raw Plan.io name');

$statusless = taskFieldsFromIssue(['id' => 3, 'subject' => 'z']);
check($statusless['planio_status'] === null, 'a missing Plan.io status records NULL, not an empty string');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
