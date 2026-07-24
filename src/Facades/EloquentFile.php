<?php

declare(strict_types=1);

namespace EloquentFile\EloquentFile\Facades;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \EloquentFile\EloquentFile\EloquentFile open(string $path, string $connectionName = 'eloquent-file')
 * @method static Builder table(string $table, ?string $as = null)
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
