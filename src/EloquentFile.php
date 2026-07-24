<?php

declare(strict_types=1);

namespace EloquentFile\EloquentFile;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\SQLiteConnection;
use InvalidArgumentException;
use LogicException;
use Throwable;

class EloquentFile
{
    private ?SQLiteConnection $connection = null;

    private ?string $connectionName = null;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly SqlDumpLoader $loader,
    ) {}

    public function open(string $path, string $connectionName = 'eloquent-file'): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("SQL dump [$path] is not a readable file.");
        }

        if ($connectionName === '') {
            throw new InvalidArgumentException('The connection name cannot be empty.');
        }

        $this->connection = null;
        $this->connectionName = null;

        $connection = $this->database->connectUsing($connectionName, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ], force: true);

        if (! $connection instanceof SQLiteConnection) {
            throw new LogicException('Unable to create the SQLite dump connection.');
        }

        try {
            $this->loader->load($path, $connection);
            $connection->statement('PRAGMA query_only = ON');
        } catch (Throwable $exception) {
            $this->database->purge($connectionName);

            throw $exception;
        }

        $this->connection = $connection;
        $this->connectionName = $connectionName;

        return $this;
    }

    public function table(string $table, ?string $as = null): Builder
    {
        return $this->connection()->table($table, $as);
    }

    public function connection(): Connection
    {
        return $this->connection
            ?? throw new LogicException('Open a SQL dump before querying it.');
    }

    public function connectionName(): string
    {
        return $this->connectionName
            ?? throw new LogicException('Open a SQL dump before querying it.');
    }
}
