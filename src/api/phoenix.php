<?php
declare(strict_types=1);

return [
    'migration_dirs' => [
        'default' => __DIR__ . '/migrations',
    ],
    'environments' => [
        'local' => [
            'adapter'   => 'mysql',
            'host'      => getenv('DB_HOST') ?: 'mysql',
            'port'      => (int)(getenv('DB_PORT') ?: 3306),
            'username'  => getenv('DB_USER') ?: 'devtracker',
            'password'  => getenv('DB_PASS') ?: 'devtracker',
            'db_name'   => getenv('DB_NAME') ?: 'devtracker',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    'default_environment' => 'local',
    'log_table_name'      => 'phoenix_log',
];
