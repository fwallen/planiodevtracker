<?php
declare(strict_types=1);

use Phoenix\Migration\AbstractMigration;

final class CreateInitialSchema extends AbstractMigration
{
    protected function up(): void
    {
        // Base schema, formerly seeded by db/init.sql. Later migrations add the
        // on_hold status, sort_order, handed_to, and task_links on top of this,
        // so those columns are intentionally absent here. IF NOT EXISTS / INSERT
        // IGNORE keep this idempotent on databases already provisioned by init.sql.
        $this->execute(
            "CREATE TABLE IF NOT EXISTS tasks (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                planio_issue_id INT DEFAULT NULL,
                title           VARCHAR(500) NOT NULL,
                project         VARCHAR(255) DEFAULT NULL,
                requester       VARCHAR(255) DEFAULT NULL,
                status          ENUM('new','in_progress','awaiting_feedback','feedback_received','done') NOT NULL DEFAULT 'new',
                priority        TINYINT DEFAULT 2,
                due_date        DATE DEFAULT NULL,
                notes           TEXT DEFAULT NULL,
                feedback_rounds TINYINT DEFAULT 0,
                last_sent_at    DATETIME DEFAULT NULL,
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->execute(
            "CREATE TABLE IF NOT EXISTS feedback_log (
                id      INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                note    TEXT DEFAULT NULL,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->execute(
            "CREATE TABLE IF NOT EXISTS settings (
                key_name VARCHAR(100) PRIMARY KEY,
                value    TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->execute(
            "INSERT IGNORE INTO settings (key_name, value) VALUES
                ('planio_base_url', ''),
                ('planio_api_key', ''),
                ('planio_user_id', ''),
                ('feedback_warning_days', '3')"
        );
    }

    protected function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS feedback_log');
        $this->execute('DROP TABLE IF EXISTS settings');
        $this->execute('DROP TABLE IF EXISTS tasks');
    }
}
