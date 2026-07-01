<?php
declare(strict_types=1);

use Phoenix\Migration\AbstractMigration;

final class AddSortOrderToTasks extends AbstractMigration
{
    protected function up(): void
    {
        $this->execute('ALTER TABLE tasks ADD COLUMN sort_order INT NOT NULL DEFAULT 0');

        // Backfill sequentially using the existing default ordering so nothing
        // visibly reorders on first load after the migration.
        $this->execute('SET @row := 0');
        $this->execute(
            'UPDATE tasks SET sort_order = (@row := @row + 1)
             ORDER BY priority DESC, due_date ASC, created_at ASC'
        );
    }

    protected function down(): void
    {
        $this->execute('ALTER TABLE tasks DROP COLUMN sort_order');
    }
}
