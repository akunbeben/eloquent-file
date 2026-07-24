<?php

declare(strict_types=1);

namespace EloquentFile\EloquentFile;

use Illuminate\Support\ServiceProvider;

class EloquentFileServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EloquentFile::class);
    }
}
