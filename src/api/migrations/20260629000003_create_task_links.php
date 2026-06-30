<?php
declare(strict_types=1);

use Phoenix\Migration\AbstractMigration;

final class CreateTaskLinks extends AbstractMigration
{
    protected function up(): void
    {
        $this->execute('
            CREATE TABLE task_links (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                task_id    INT          NOT NULL,
                title      VARCHAR(255) NOT NULL,
                url        TEXT         NOT NULL,
                created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    protected function down(): void
    {
        $this->execute('DROP TABLE task_links');
    }
}
