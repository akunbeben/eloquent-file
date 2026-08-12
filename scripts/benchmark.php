<?php

declare(strict_types=1);

use EloquentFile\EloquentFile\EloquentFile;
use EloquentFile\EloquentFile\SqlDumpLoader;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $dumpPath = null;
    $temporaryDirectory = sys_get_temp_dir();
    $json = false;

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            fwrite(STDOUT, "Usage: php scripts/benchmark.php <dump.sql> [--temp-dir=/path] [--json]\n");

            return;
        }

        if ($argument === '--json') {
            $json = true;

            continue;
        }

        if (str_starts_with($argument, '--temp-dir=')) {
            $temporaryDirectory = substr($argument, strlen('--temp-dir='));

            continue;
        }

        if (str_starts_with($argument, '-')) {
            throw new InvalidArgumentException("Unknown option [$argument].");
        }

        if ($dumpPath !== null) {
            throw new InvalidArgumentException('Only one SQL dump path may be provided.');
        }

        $dumpPath = $argument;
    }

    if ($dumpPath === null) {
        throw new InvalidArgumentException('A SQL dump path is required.');
    }

    $dumpPath = realpath($dumpPath);
    $temporaryDirectory = realpath($temporaryDirectory);

    if ($dumpPath === false || $temporaryDirectory === false) {
        throw new InvalidArgumentException('The dump path and temporary directory must exist.');
    }

    $dumpBytes = filesize($dumpPath);
    $freeSpaceBefore = disk_free_space($temporaryDirectory);

    if ($dumpBytes === false || $freeSpaceBefore === false) {
        throw new RuntimeException('Unable to read dump size or temporary directory free space.');
    }

    $container = new Container;
    $database = new DatabaseManager($container, new ConnectionFactory($container));
    $config = new Repository([
        'eloquent-file' => [
            'storage' => 'file',
            'temporary_directory' => $temporaryDirectory,
            'import' => [
                'journal_mode' => 'OFF',
                'synchronous' => 'OFF',
            ],
        ],
    ]);
    $dump = new EloquentFile($database, new SqlDumpLoader, $config);
    $connectionName = 'benchmark';
    $databasePath = null;
    $closed = false;
    $rssBefore = rssBytes();
    $importStarted = hrtime(true);

    try {
        $dump->open($dumpPath, $connectionName);
        $importSeconds = elapsedSeconds($importStarted);
        $connection = $dump->connection();
        $databasePath = $connection->getConfig('database');
        $schemaStarted = hrtime(true);
        $schema = $dump->schema();
        $schemaSeconds = elapsedSeconds($schemaStarted);
        $sqliteBytes = sqliteBytes($connection);
        $freeSpaceAfterImport = disk_free_space($temporaryDirectory);
        $fileBytes = is_string($databasePath) && is_file($databasePath) ? filesize($databasePath) : false;
        $rssPeak = rssBytes();
        $phpMemoryPeak = memory_get_peak_usage(true);

        $closeStarted = hrtime(true);
        $dump->close($connectionName);
        $closeSeconds = elapsedSeconds($closeStarted);
        $closed = true;

        $metrics = [
            'dump_path' => $dumpPath,
            'dump_bytes' => $dumpBytes,
            'temporary_directory' => $temporaryDirectory,
            'import_seconds' => $importSeconds,
            'schema_seconds' => $schemaSeconds,
            'close_seconds' => $closeSeconds,
            'table_count' => count($schema['tables']),
            'sqlite_bytes' => $sqliteBytes,
            'sqlite_file_bytes' => $fileBytes,
            'free_space_before_bytes' => $freeSpaceBefore,
            'free_space_after_import_bytes' => $freeSpaceAfterImport,
            'php_memory_peak_bytes' => $phpMemoryPeak,
            'rss_before_bytes' => $rssBefore,
            'rss_peak_bytes' => $rssPeak,
            'temporary_file_exists_after_close' => is_string($databasePath) && is_file($databasePath),
        ];
    } finally {
        if (! $closed) {
            $dump->close($connectionName);
        }
    }

    if ($json) {
        fwrite(STDOUT, json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    } else {
        fwrite(STDOUT, "Eloquent File Benchmark\n=======================\n");

        fwrite(STDOUT, sprintf("Dump                  %s\n", $metrics['dump_path']));
        fwrite(STDOUT, sprintf("Dump size             %s\n", formatBytes($metrics['dump_bytes'])));
        fwrite(STDOUT, sprintf("Temporary directory   %s\n", $metrics['temporary_directory']));
        fwrite(STDOUT, sprintf("Import                %s\n", formatSeconds($metrics['import_seconds'])));
        fwrite(STDOUT, sprintf("Schema                %s\n", formatSeconds($metrics['schema_seconds'])));
        fwrite(STDOUT, sprintf("Cleanup               %s\n", formatSeconds($metrics['close_seconds'])));
        fwrite(STDOUT, sprintf("Tables                %d\n", $metrics['table_count']));
        fwrite(STDOUT, sprintf("SQLite size           %s\n", formatBytes($metrics['sqlite_bytes'])));
        fwrite(STDOUT, sprintf("Free space before     %s\n", formatBytes($metrics['free_space_before_bytes'])));
        fwrite(STDOUT, sprintf("Free space after      %s\n", formatBytes($metrics['free_space_after_import_bytes'])));
        fwrite(STDOUT, sprintf("PHP memory peak       %s\n", formatBytes($metrics['php_memory_peak_bytes'])));
        fwrite(STDOUT, sprintf("RSS before            %s\n", formatBytes($metrics['rss_before_bytes'])));
        fwrite(STDOUT, sprintf("RSS peak              %s\n", formatBytes($metrics['rss_peak_bytes'])));
        fwrite(STDOUT, sprintf("Temporary file cleaned %s\n", $metrics['temporary_file_exists_after_close'] ? 'no' : 'yes'));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);

    exit(1);
}

function elapsedSeconds(int $startedAt): float
{
    return round((hrtime(true) - $startedAt) / 1_000_000_000, 3);
}

function formatBytes(int|float|false|null $bytes): string
{
    if ($bytes === null || $bytes === false) {
        return 'n/a';
    }

    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $value = (float) $bytes;
    $unit = 0;

    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return sprintf('%.2f %s (%s bytes)', $value, $units[$unit], number_format($bytes));
}

function formatSeconds(float $seconds): string
{
    return sprintf('%.3f s', $seconds);
}

function sqliteBytes(Connection $connection): int
{
    $pageCount = (int) $connection->selectOne('PRAGMA page_count')->page_count;
    $pageSize = (int) $connection->selectOne('PRAGMA page_size')->page_size;

    return $pageCount * $pageSize;
}

function rssBytes(): ?int
{
    if (! function_exists('getrusage')) {
        return null;
    }

    $usage = getrusage();
    $rss = $usage['ru_maxrss'] ?? null;

    if (! is_int($rss) && ! is_float($rss)) {
        return null;
    }

    return PHP_OS_FAMILY === 'Darwin' ? (int) $rss : (int) $rss * 1024;
}
