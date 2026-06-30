<?php
declare(strict_types=1);

use Phoenix\Migration\AbstractMigration;

final class AddOnHoldStatus extends AbstractMigration
{
    protected function up(): void
    {
        $this->execute(
            "ALTER TABLE tasks MODIFY COLUMN status ENUM('new','on_hold','in_progress','awaiting_feedback','feedback_received','done') NOT NULL DEFAULT 'new'"
        );
    }

    protected function down(): void
    {
        $this->execute(
            "UPDATE tasks SET status = 'new' WHERE status = 'on_hold'"
        );
        $this->execute(
            "ALTER TABLE tasks MODIFY COLUMN status ENUM('new','in_progress','awaiting_feedback','feedback_received','done') NOT NULL DEFAULT 'new'"
        );
    }
}
