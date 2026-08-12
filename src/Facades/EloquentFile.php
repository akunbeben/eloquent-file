<?php

declare(strict_types=1);

namespace EloquentFile\EloquentFile\Facades;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \EloquentFile\EloquentFile\EloquentFile open(string $path, string $connectionName = 'eloquent-file')
 * @method static void close(?string $connectionName = null)
 * @method static Builder table(string $table, ?string $as = null)
 * @method static array{tables: array<string, array{columns: list<string>}>} schema()
 * @method static list<string> tables(string $prefix = '')
 * @method static list<string> columns(string $table, string $prefix = '')
 * @method static Connection connection()
 * @method static string connectionName()
 *
 * @see \EloquentFile\EloquentFile\EloquentFile
 */
class EloquentFile extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \EloquentFile\EloquentFile\EloquentFile::class;
    }
}
