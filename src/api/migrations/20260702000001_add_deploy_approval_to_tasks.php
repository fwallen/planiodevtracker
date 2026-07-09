<?php
declare(strict_types=1);

use Phoenix\Migration\AbstractMigration;

final class AddDeployApprovalToTasks extends AbstractMigration
{
    protected function up(): void
    {
        // Plan.io "Approved for Staging" / "Approved for Production" statuses,
        // normalised to staging/production. Plan.io-owned (recomputed on every
        // sync/import), unlike the developer-owned pipeline `status`.
        $this->execute(
            "ALTER TABLE tasks ADD COLUMN deploy_approval ENUM('staging','production') DEFAULT NULL AFTER status"
        );
    }

    protected function down(): void
    {
        $this->execute('ALTER TABLE tasks DROP COLUMN deploy_approval');
    }
}
