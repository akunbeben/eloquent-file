<?php

declare(strict_types=1);

namespace EloquentFile\EloquentFile\Tests;

use EloquentFile\EloquentFile\EloquentFileServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            EloquentFileServiceProvider::class,
        ];
    }
}
