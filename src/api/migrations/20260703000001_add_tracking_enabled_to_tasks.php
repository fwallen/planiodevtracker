<?php
declare(strict_types=1);

use Phoenix\Migration\AbstractMigration;

final class AddTrackingEnabledToTasks extends AbstractMigration
{
    protected function up(): void
    {
        // Lets the developer pull a task off the board without breaking its tie
        // to Plan.io. A re-import or a sync-driven status change on the RM flips
        // this back to 1, re-surfacing the task (see routes/planio.php).
        $this->execute(
            "ALTER TABLE tasks ADD COLUMN tracking_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER status"
        );
    }

    protected function down(): void
    {
        $this->execute('ALTER TABLE tasks DROP COLUMN tracking_enabled');
    }
}
