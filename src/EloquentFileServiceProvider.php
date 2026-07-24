<?php

declare(strict_types=1);

namespace EloquentFile\EloquentFile;

use EloquentFile\EloquentFile\Console\Commands\EloquentFileCommand;
use Illuminate\Support\ServiceProvider;

class EloquentFileServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eloquent-file.php', 'eloquent-file');

        $this->app->singleton(EloquentFile::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/eloquent-file.php' => config_path('eloquent-file.php'),
        ], ['eloquent-file', 'eloquent-file-config']);

        $this->commands([
            EloquentFileCommand::class,
        ]);
    }
}
