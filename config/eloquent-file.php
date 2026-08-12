<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Storage
    |--------------------------------------------------------------------------
    |
    | File-backed SQLite keeps large dumps out of PHP's process memory. The
    | memory mode is useful for small dumps and tests.
    |
    */
    'storage' => 'file',

    'temporary_directory' => null,

    'cleanup' => [
        // Remove abandoned databases after an unclean process shutdown.
        'max_age' => 86400,

        // Keep headroom for SQLite pages and unrelated application writes.
        'free_space_buffer' => 100 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Import Pragmas
    |--------------------------------------------------------------------------
    |
    | The imported database is disposable and is only exposed after the
    | transaction commits, so durability can be traded for import speed.
    |
    */
    'import' => [
        'journal_mode' => 'OFF',
        'synchronous' => 'OFF',
    ],
];
