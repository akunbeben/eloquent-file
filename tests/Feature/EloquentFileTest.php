<?php

declare(strict_types=1);

use EloquentFile\EloquentFile\EloquentFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function openDump(string $sql, string $connectionName = 'eloquent-file'): EloquentFile
{
    $path = tempnam(sys_get_temp_dir(), 'eloquent-file-');

    if ($path === false || file_put_contents($path, $sql) === false) {
        throw new RuntimeException('Unable to create the test SQL dump.');
    }

    try {
        return app(EloquentFile::class)->open($path, $connectionName);
    } finally {
        unlink($path);
    }
}

function exampleDump(): string
{
    return <<<'SQL'
-- MariaDB dump
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `balance` decimal(10,2) NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` VALUES
(1,'Alice',10.50,NULL),
(2,'O\'Reilly',20.25,'2026-07-24 09:00:00'),
(3,'Line\nbreak',7.00,NULL);

CREATE TABLE `posts` (
  `id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `title` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `posts` (`id`, `user_id`, `title`) VALUES
(1,1,'First post'),
(2,1,'Second post'),
(3,2,'Third post');
SQL;
}

it('queries a SQL dump with Laravel query builder', function () {
    $database = openDump(exampleDump());

    $users = $database->table('users')
        ->whereNull('deleted_at')
        ->orderByDesc('balance')
        ->pluck('name')
        ->all();

    $postCounts = $database->table('users')
        ->join('posts', 'users.id', '=', 'posts.user_id')
        ->selectRaw('users.name, count(posts.id) as post_count')
        ->groupBy('users.id', 'users.name')
        ->orderBy('users.id')
        ->pluck('post_count', 'users.name')
        ->all();

    expect($users)->toBe(['Alice', "Line\nbreak"])
        ->and($postCounts)->toBe(['Alice' => 2, "O'Reilly" => 1]);
});

it('streams rows with Laravel cursor', function () {
    $database = openDump(exampleDump());

    expect($database->table('users')->orderBy('id')->cursor()->pluck('name')->all())
        ->toBe(['Alice', "O'Reilly", "Line\nbreak"]);
});

it('uses a file-backed SQLite database by default', function () {
    $database = openDump(exampleDump());
    $connection = $database->connection();
    $path = $connection->getConfig('database');

    file_put_contents($path.'-journal', 'journal');
    file_put_contents($path.'-wal', 'wal');
    file_put_contents($path.'-shm', 'shm');

    expect($path)
        ->not->toBe(':memory:')
        ->and(is_file($path))->toBeTrue();

    $database->close();

    expect(is_file($path))->toBeFalse()
        ->and(is_file($path.'-journal'))->toBeFalse()
        ->and(is_file($path.'-wal'))->toBeFalse()
        ->and(is_file($path.'-shm'))->toBeFalse();
});

it('does not replace an application-owned connection', function () {
    config()->set('database.connections.taken', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    $hostConnection = DB::connection('taken');
    $hostConnection->statement('CREATE TABLE host_data (value TEXT)');
    $hostConnection->table('host_data')->insert(['value' => 'preserved']);

    expect(fn () => openDump(exampleDump(), 'taken'))
        ->toThrow(RuntimeException::class, 'Cannot establish connection [taken] because another connection with that name already exists.');

    expect($hostConnection->table('host_data')->value('value'))->toBe('preserved');
});

it('does not close an application-owned connection by name', function () {
    config()->set('database.connections.taken', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    $hostConnection = DB::connection('taken');
    $hostConnection->statement('CREATE TABLE host_data (value TEXT)');
    $hostConnection->table('host_data')->insert(['value' => 'preserved']);

    app(EloquentFile::class)->close('taken');

    expect($hostConnection->table('host_data')->value('value'))->toBe('preserved');
});

it('supports memory storage for small dumps', function () {
    config()->set('eloquent-file.storage', 'memory');

    $database = openDump(exampleDump());

    expect($database->connection()->getConfig('database'))->toBe(':memory:');
});

it('cleans up stale file-backed databases', function () {
    $directory = sys_get_temp_dir().'/eloquent-file-stale-'.bin2hex(random_bytes(8));
    mkdir($directory);

    $stalePath = $directory.'/eloquent-file-'.str_repeat('a', 32).'.sqlite';
    file_put_contents($stalePath, 'stale');
    touch($stalePath, time() - 90000);

    config()->set('eloquent-file.temporary_directory', $directory);
    config()->set('eloquent-file.cleanup.max_age', 3600);

    try {
        $database = openDump(exampleDump());
        $databasePath = $database->connection()->getConfig('database');

        expect(is_file($stalePath))->toBeFalse();

        $database->close();
        expect(is_file($databasePath))->toBeFalse();
    } finally {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('rejects a dump when the configured free space buffer cannot be met', function () {
    config()->set('eloquent-file.cleanup.free_space_buffer', PHP_INT_MAX);

    openDump(exampleDump());
})->throws(RuntimeException::class, 'does not have enough free space');

it('provides schema metadata for integrations', function () {
    $database = openDump(exampleDump());

    expect($database->schema())->toBe([
        'tables' => [
            'posts' => [
                'columns' => ['id', 'user_id', 'title'],
            ],
            'users' => [
                'columns' => ['id', 'name', 'balance', 'deleted_at'],
            ],
        ],
    ])
        ->and($database->tables('US'))->toBe(['users'])
        ->and($database->columns('users', 'NA'))->toBe(['name']);
});

it('refreshes schema metadata when another dump is opened', function () {
    $database = openDump("CREATE TABLE `first` (\n  `id` int NOT NULL\n);\n");

    expect($database->tables())->toBe(['first']);

    $database = openDump("CREATE TABLE `second` (\n  `name` varchar(255) NOT NULL\n);\n");

    expect($database->schema())->toBe([
        'tables' => [
            'second' => [
                'columns' => ['name'],
            ],
        ],
    ]);
});

it('registers the dump as an Eloquent connection', function () {
    $database = openDump(exampleDump());
    $model = new class extends Model
    {
        public $timestamps = false;

        protected $table = 'users';

        protected $guarded = [];
    };

    $user = $model->setConnection($database->connectionName())->newQuery()->findOrFail(2);

    expect($database->connectionName())->toBe('eloquent-file')
        ->and($user->name)->toBe("O'Reilly")
        ->and($user->balance)->toBe(20.25);
});

it('rejects writes', function () {
    $database = openDump(exampleDump());

    expect(fn () => $database->table('users')->where('id', 1)->update(['name' => 'Changed']))
        ->toThrow(QueryException::class)
        ->and($database->table('users')->where('id', 1)->value('name'))->toBe('Alice');
});

it('rejects missing dump files', function () {
    app(EloquentFile::class)->open('/missing/backup.sql');
})->throws(InvalidArgumentException::class, 'SQL dump [/missing/backup.sql] is not a readable file.');

it('rejects incomplete dumps', function () {
    openDump("CREATE TABLE `users` (\n  `id` int NOT NULL\n");
})->throws(UnexpectedValueException::class, 'The SQL dump ends in an incomplete statement.');

it('resolves the package service as a singleton', function () {
    expect(app(EloquentFile::class))->toBe(app(EloquentFile::class));
});
