<?php

declare(strict_types=1);

namespace EloquentFile\EloquentFile\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \EloquentFile\EloquentFile\EloquentFile
 */
class EloquentFile extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \EloquentFile\EloquentFile\EloquentFile::class;
    }
}
