<?php

declare(strict_types=1);

namespace EloquentFile\EloquentFile\Console\Commands;

use Illuminate\Console\Command;

class EloquentFileCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'eloquent-file:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package eloquent-file.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('EloquentFile placeholder command executed.');

        return self::SUCCESS;
    }
}
