<?php
declare(strict_types=1);

use Phoenix\Migration\AbstractMigration;

final class AddPlanioStatusToTasks extends AbstractMigration
{
    protected function up(): void
    {
        // Last-seen raw Plan.io status name. Sync needs the *previous* value to
        // recognise a hand-back — the requester moving an RM out of "Feedback" —
        // without mistaking "developer sent for feedback but never changed the
        // Plan.io status" for the same thing (see planio_mapping.php).
        // Deliberately left NULL rather than backfilled. Guessing "a task in
        // awaiting_feedback must have been in Feedback upstream" is wrong for any
        // task handed off outside Plan.io — RM8237 was parked on "Waiting on PR
        // reviews" while its ticket never left In Progress, and a guessed value
        // would have read as a hand-back the moment sync ran. NULL means
        // "unknown", which resolvePlanioStatus treats as no-nudge; the next sync
        // records the real status and hand-backs are detected from then on.
        $this->execute(
            'ALTER TABLE tasks ADD COLUMN planio_status VARCHAR(64) NULL AFTER planio_issue_id'
        );
    }

    protected function down(): void
    {
        $this->execute('ALTER TABLE tasks DROP COLUMN planio_status');
    }
}
