<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Storage Driver
    |--------------------------------------------------------------------------
    |
    | The default StorageDriver implementation used when no explicit driver is
    | requested. Rclone is the initial transfer engine used by the legacy CLI.
    |
    */

    'default_driver' => env('STORAGE_DEFAULT_DRIVER', 'rclone'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    */

    'drivers' => [
        'rclone' => [
            'binary' => env('STORAGE_RCLONE_BINARY', 'rclone'),
            'timeout' => (int) env('STORAGE_RCLONE_TIMEOUT', 3600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Storage operation logs are written through Laravel's logging component to
    | the directory below (the `~` prefix is expanded to the user home at
    | runtime; the Logging component never sees credentials).
    |
    */

    'logs' => [
        'path' => env('STORAGE_LOG_PATH', '~/.config/storage-cli/logs'),
        'max_files' => (int) env('STORAGE_LOG_MAX_FILES', 14),
        'level' => env('STORAGE_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transfer Defaults
    |--------------------------------------------------------------------------
    |
    | Defaults for shared transfer options. Command-line options always take
    | precedence over these values.
    |
    */

    'defaults' => [
        'transfers' => (int) env('STORAGE_DEFAULT_TRANSFERS', 8),
        'retries' => (int) env('STORAGE_DEFAULT_RETRIES', 3),
        'overwrite' => (bool) env('STORAGE_OVERWRITE', false),
        'recursive' => (bool) env('STORAGE_RECURSIVE', false),

        /*
         * ACL applied to written objects when no --acl is given. Accepts the
         * application-level values below ("private"/"public") as well as raw
         * canned ACLs; either way the value is mapped through 'visibility'
         * before it reaches the backend.
         *
         * Leave this null to inherit the ACL configured on the destination
         * remote itself (also mapped). Set it to pin one value for every
         * transfer regardless of how each remote is configured.
         */
        'acl' => env('STORAGE_DEFAULT_ACL') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery Cache
    |--------------------------------------------------------------------------
    |
    | Remotes and bucket listings call out to rclone subprocesses. Results are
    | cached for the TTL below (seconds). Set to 0 to disable caching.
    |
    */

    'cache' => [
        'ttl' => (int) env('STORAGE_CACHE_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Visibility / ACL Mapping
    |--------------------------------------------------------------------------
    |
    | Application-level visibility values mapped to backend ACL values. The
    | command layer only knows "private" and "public"; additional values are
    | available to drivers and may be added without changing commands.
    |
    */

    'visibility' => [
        'private' => 'private',
        'public' => 'public-read',
        'public-read-write' => 'public-read-write',
        'authenticated-read' => 'authenticated-read',
    ],

];
