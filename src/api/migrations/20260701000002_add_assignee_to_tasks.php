<?php
declare(strict_types=1);

use Phoenix\Migration\AbstractMigration;

final class AddAssigneeToTasks extends AbstractMigration
{
    protected function up(): void
    {
        // Plan.io assigned_to.name. Populated by sync and single-issue import;
        // may differ from the developer for issues imported by RM id where the
        // developer is in the pipeline but not the direct assignee.
        $this->execute('ALTER TABLE tasks ADD COLUMN assignee VARCHAR(255) DEFAULT NULL AFTER requester');
    }

    protected function down(): void
    {
        $this->execute('ALTER TABLE tasks DROP COLUMN assignee');
    }
}
