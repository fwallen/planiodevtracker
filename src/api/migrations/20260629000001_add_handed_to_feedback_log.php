<?php
declare(strict_types=1);

use Phoenix\Migration\AbstractMigration;

final class AddHandedToFeedbackLog extends AbstractMigration
{
    protected function up(): void
    {
        $this->execute(
            'ALTER TABLE feedback_log ADD COLUMN handed_to VARCHAR(255) DEFAULT NULL AFTER sent_at'
        );
    }

    protected function down(): void
    {
        $this->execute(
            'ALTER TABLE feedback_log DROP COLUMN handed_to'
        );
    }
}
