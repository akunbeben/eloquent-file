<?php

declare(strict_types=1);

use EloquentFile\EloquentFile\EloquentFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

function openDump(string $sql): EloquentFile
{
    $path = tempnam(sys_get_temp_dir(), 'eloquent-file-');

    if ($path === false || file_put_contents($path, $sql) === false) {
        throw new RuntimeException('Unable to create the test SQL dump.');
    }

    try {
        return app(EloquentFile::class)->open($path);
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
