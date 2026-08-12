<?php

declare(strict_types=1);

namespace EloquentFile\EloquentFile;

use DirectoryIterator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\SQLiteConnection;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

class EloquentFile
{
    private ?SQLiteConnection $connection = null;

    private ?string $connectionName = null;

    /**
     * @var array<string, string>
     */
    private array $temporaryPaths = [];

    /**
     * @var array<string, resource>
     */
    private array $temporaryLocks = [];

    private bool $shutdownCleanupRegistered = false;

    /**
     * @var array{tables: array<string, array{columns: list<string>}>}|null
     */
    private ?array $schema = null;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly SqlDumpLoader $loader,
        private readonly Repository $config,
    ) {}

    public function open(string $path, string $connectionName = 'eloquent-file'): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("SQL dump [$path] is not a readable file.");
        }

        if ($connectionName === '') {
            throw new InvalidArgumentException('The connection name cannot be empty.');
        }

        $this->close();

        $this->connection = null;
        $this->connectionName = null;
        $this->schema = null;

        $databasePath = $this->databasePath($path);

        $connected = false;

        try {
            $connection = $this->database->connectUsing($connectionName, [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
                'foreign_key_constraints' => false,
                'journal_mode' => $this->config->get('eloquent-file.import.journal_mode', 'OFF'),
                'synchronous' => $this->config->get('eloquent-file.import.synchronous', 'OFF'),
            ]);
            $connected = true;

            if (! $connection instanceof SQLiteConnection) {
                throw new LogicException('Unable to create the SQLite dump connection.');
            }

            $this->loader->load($path, $connection);
            $connection->statement('PRAGMA query_only = ON');
        } catch (Throwable $exception) {
            if ($connected) {
                $this->database->purge($connectionName);
            }

            $this->deleteDatabase($databasePath);

            throw $exception;
        }

        if ($databasePath !== ':memory:') {
            $this->temporaryPaths[$connectionName] = $databasePath;
        }

        $this->connection = $connection;
        $this->connectionName = $connectionName;
        $this->registerShutdownCleanup();

        return $this;
    }

    public function close(?string $connectionName = null): void
    {
        $connectionName ??= $this->connectionName;

        if ($connectionName === null) {
            return;
        }

        if ($this->connectionName !== $connectionName) {
            return;
        }

        $this->database->purge($connectionName);

        $databasePath = $this->temporaryPaths[$connectionName] ?? null;

        unset($this->temporaryPaths[$connectionName]);

        if ($this->connectionName === $connectionName) {
            $this->connection = null;
            $this->connectionName = null;
            $this->schema = null;
        }

        if ($databasePath !== null) {
            $this->deleteDatabase($databasePath);
        }
    }

    public function __destruct()
    {
        foreach (array_keys($this->temporaryPaths) as $connectionName) {
            try {
                $this->close($connectionName);
            } catch (Throwable) {
                // Cleanup must not turn application shutdown into an exception.
            }
        }
    }

    public function table(string $table, ?string $as = null): Builder
    {
        return $this->connection()->table($table, $as);
    }

    /**
     * Return the loaded tables and their columns for integrations.
     *
     * @return array{tables: array<string, array{columns: list<string>}>}
     */
    public function schema(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $schema = $this->connection()->getSchemaBuilder();
        $tables = [];

        foreach ($schema->getTables() as $table) {
            $name = $table['name'];

            $tables[$name] = [
                'columns' => $schema->getColumnListing($name),
            ];
        }

        return $this->schema = ['tables' => $tables];
    }

    /**
     * @return list<string>
     */
    public function tables(string $prefix = ''): array
    {
        return $this->matching(array_keys($this->schema()['tables']), $prefix);
    }

    /**
     * @return list<string>
     */
    public function columns(string $table, string $prefix = ''): array
    {
        foreach ($this->schema()['tables'] as $name => $definition) {
            if (strcasecmp($name, $table) === 0) {
                return $this->matching($definition['columns'], $prefix);
            }
        }

        return [];
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

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function matching(array $values, string $prefix): array
    {
        $prefix = strtolower($prefix);

        return array_values(array_filter(
            $values,
            fn (string $value): bool => str_starts_with(strtolower($value), $prefix),
        ));
    }

    private function databasePath(string $dumpPath): string
    {
        $storage = $this->config->get('eloquent-file.storage', 'file');

        if ($storage === 'memory') {
            return ':memory:';
        }

        if ($storage !== 'file') {
            throw new InvalidArgumentException('The eloquent-file storage must be either [file] or [memory].');
        }

        $directory = $this->config->get('eloquent-file.temporary_directory') ?? sys_get_temp_dir();

        if (! is_string($directory)) {
            throw new InvalidArgumentException('The eloquent-file temporary directory must be a string or null.');
        }

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new InvalidArgumentException("The temporary directory [$directory] is not writable.");
        }

        $this->cleanupStaleDatabases($directory);

        $dumpSize = filesize($dumpPath);

        if ($dumpSize === false) {
            throw new RuntimeException("Unable to determine the SQL dump size [$dumpPath].");
        }

        $freeSpace = disk_free_space($directory);
        $freeSpaceBuffer = $this->config->get('eloquent-file.cleanup.free_space_buffer', 100 * 1024 * 1024);

        if ($freeSpace === false) {
            throw new RuntimeException("Unable to determine free space for the temporary directory [$directory].");
        }

        if (! is_int($freeSpaceBuffer) || $freeSpaceBuffer < 0) {
            throw new InvalidArgumentException('The eloquent-file free space buffer must be a non-negative integer.');
        }

        if ($freeSpace < $dumpSize + $freeSpaceBuffer) {
            throw new RuntimeException(sprintf(
                'The temporary directory [%s] does not have enough free space for the SQL dump.',
                $directory,
            ));
        }

        $prefix = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $path = $prefix.'eloquent-file-'.bin2hex(random_bytes(16)).'.sqlite';
            $lockPath = $path.'.lock';
            $handle = @fopen($lockPath, 'x+b');

            if ($handle === false || ! @flock($handle, LOCK_EX | LOCK_NB)) {
                if ($handle !== false) {
                    fclose($handle);
                    @unlink($lockPath);
                }

                continue;
            }

            $database = @fopen($path, 'x+b');

            if ($database !== false) {
                fclose($database);
                $this->temporaryLocks[$path] = $handle;

                return $path;
            }

            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($lockPath);
        }

        throw new RuntimeException("Unable to create a temporary SQLite database in [$directory].");
    }

    private function deleteDatabase(string $databasePath): void
    {
        if ($databasePath === ':memory:') {
            return;
        }

        if (isset($this->temporaryLocks[$databasePath])) {
            flock($this->temporaryLocks[$databasePath], LOCK_UN);
            fclose($this->temporaryLocks[$databasePath]);
            unset($this->temporaryLocks[$databasePath]);
        }

        $this->deleteDatabaseFiles($databasePath);
        $this->deleteFile($databasePath.'.lock');
    }

    private function deleteDatabaseFiles(string $databasePath): void
    {
        foreach ([$databasePath, $databasePath.'-journal', $databasePath.'-wal', $databasePath.'-shm'] as $path) {
            $this->deleteFile($path);
        }
    }

    private function deleteFile(string $path): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            clearstatcache(true, $path);

            if (! is_file($path) || @unlink($path)) {
                return;
            }

            usleep(10_000);
        }
    }

    private function cleanupStaleDatabases(string $directory): void
    {
        $maxAge = $this->config->get('eloquent-file.cleanup.max_age', 86400);

        if ($maxAge === null) {
            return;
        }

        if (! is_int($maxAge) || $maxAge < 0) {
            throw new InvalidArgumentException('The eloquent-file cleanup max age must be a non-negative integer or null.');
        }

        $cutoff = time() - $maxAge;

        try {
            foreach (new DirectoryIterator($directory) as $entry) {
                if ($entry->isDot() || ! $entry->isFile()) {
                    continue;
                }

                $filename = $entry->getFilename();

                $databasePath = match (true) {
                    preg_match('/^eloquent-file-[a-f0-9]{32}\.sqlite$/', $filename) === 1 => $entry->getPathname(),
                    preg_match('/^eloquent-file-[a-f0-9]{32}\.sqlite\.lock$/', $filename) === 1 => substr($entry->getPathname(), 0, -5),
                    default => null,
                };

                if ($databasePath === null) {
                    continue;
                }

                $modifiedAt = is_file($databasePath)
                    ? filemtime($databasePath)
                    : filemtime($databasePath.'.lock');

                if ($modifiedAt === false || $modifiedAt >= $cutoff) {
                    continue;
                }

                $lock = @fopen($databasePath.'.lock', 'c+b');

                if ($lock === false || ! @flock($lock, LOCK_EX | LOCK_NB)) {
                    if ($lock !== false) {
                        fclose($lock);
                    }

                    continue;
                }

                $this->deleteDatabaseFiles($databasePath);
                flock($lock, LOCK_UN);
                fclose($lock);
                $this->deleteFile($databasePath.'.lock');
            }
        } catch (Throwable) {
            // Stale cleanup is best effort and must not block a valid import.
        }
    }

    private function registerShutdownCleanup(): void
    {
        if ($this->shutdownCleanupRegistered) {
            return;
        }

        $this->shutdownCleanupRegistered = true;

        register_shutdown_function(function (): void {
            foreach (array_keys($this->temporaryPaths) as $connectionName) {
                try {
                    $this->close($connectionName);
                } catch (Throwable) {
                    // Shutdown cleanup is best effort.
                }
            }
        });
    }
}
