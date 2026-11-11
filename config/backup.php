<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup Storage
    |--------------------------------------------------------------------------
    |
    | Disk and directory (relative to the disk root) where backup archives
    | are stored. The "local" disk root is storage/app/private, which is
    | not publicly accessible.
    |
    */

    'disk' => env('BACKUP_DISK', 'local'),

    'directory' => env('BACKUP_DIRECTORY', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Database Tools
    |--------------------------------------------------------------------------
    |
    | Full paths (or command names resolvable via PATH) to the mysqldump and
    | mysql executables used to create and restore backups. These must be
    | configured per-environment via .env and must never be hardcoded in
    | application code.
    |
    */

    'mysqldump_path' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),

    'mysql_path' => env('BACKUP_MYSQL_PATH', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Process Execution
    |--------------------------------------------------------------------------
    |
    | Maximum number of seconds a backup or restore process is allowed to
    | run before it is considered timed out.
    |
    */

    'process_timeout' => (int) env('BACKUP_PROCESS_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Concurrency Lock
    |--------------------------------------------------------------------------
    |
    | Number of seconds to wait for the MySQL GET_LOCK() used to prevent
    | concurrent backup/restore operations before giving up.
    |
    */

    'lock_wait_timeout' => (int) env('BACKUP_LOCK_WAIT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Default number of days a completed backup is retained before it is
    | eligible for cleanup (used to populate the retention_until column).
    |
    */

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Download Token
    |--------------------------------------------------------------------------
    |
    | Lifetime, in minutes, of a temporary signed download token.
    |
    */

    'download_token_ttl_minutes' => (int) env('BACKUP_DOWNLOAD_TOKEN_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Scheduled Backup
    |--------------------------------------------------------------------------
    |
    | Enables a daily scheduled full backup run by the Laravel scheduler.
    | Disabled by default. Time uses HH:MM (24h) in the given timezone.
    | The scheduler itself must be triggered every minute by the OS
    | (for example Windows Task Scheduler running php artisan schedule:run).
    | Invalid time or timezone values are ignored and logged as a warning.
    |
    */

    'schedule_enabled' => filter_var(env('BACKUP_SCHEDULE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'schedule_time' => env('BACKUP_SCHEDULE_TIME', '02:00'),

    'schedule_timezone' => env('BACKUP_SCHEDULE_TIMEZONE', 'UTC'),

];
