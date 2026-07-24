<?php

declare(strict_types=1);

use EloquentFile\EloquentFile\EloquentFile;

it('resolves the singleton', function () {
    expect(app(EloquentFile::class))->toBeInstanceOf(EloquentFile::class);
});

it('returns the same instance from the container', function () {
    expect(app(EloquentFile::class))->toBe(app(EloquentFile::class));
});

it('merges the package config', function () {
    expect(config('eloquent-file.placeholder'))->toBe('default');
});

it('registers the artisan command', function () {
    $this->artisan('eloquent-file:placeholder')
        ->expectsOutputToContain('EloquentFile placeholder command executed.')
        ->assertSuccessful();
});
