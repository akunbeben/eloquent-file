<div align="center">
    <h1>Eloquent File</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/akunbeben/eloquent-file"><img src="https://img.shields.io/packagist/v/akunbeben/eloquent-file.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/akunbeben/eloquent-file"><img src="https://img.shields.io/packagist/php-v/akunbeben/eloquent-file.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/akunbeben/eloquent-file"><img src="https://badge.laravel.cloud/badge/akunbeben/eloquent-file?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/akunbeben/eloquent-file/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/akunbeben/eloquent-file/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/akunbeben/eloquent-file"><img src="https://img.shields.io/packagist/dt/akunbeben/eloquent-file.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Query a MySQL or MariaDB SQL dump with Laravel's Query Builder and Eloquent, without restoring it to a database server. The dump is loaded into a read-only, temporary SQLite connection.

## Installation

You can install the package via Composer:

```bash
composer require akunbeben/eloquent-file
```

The PHP PDO SQLite extension must be enabled. Laravel discovers the package automatically.

Large dumps are imported into a temporary, file-backed SQLite database by default, keeping the reconstructed database out of PHP process memory. The temporary database is removed when the dump is closed, replaced, or the PHP process shuts down normally. Abandoned package-owned databases are cleaned up on a later open after 24 hours by default. Before import, the package requires free space equal to the dump size plus a 100 MB buffer by default. Set `eloquent-file.storage` to `memory` for small dumps or tests, configure `eloquent-file.temporary_directory` when the operating system temporary directory is not suitable, or adjust `eloquent-file.cleanup.max_age` and `eloquent-file.cleanup.free_space_buffer` in the published config.

Publish the optional package configuration with:

```bash
php artisan vendor:publish --tag=eloquent-file-config
```

### Benchmarking

Run a reproducible end-to-end import benchmark against a dump:

```bash
composer benchmark -- sample.sql
```

Use `--temp-dir=/path/to/benchmark-disk` to benchmark a specific filesystem. The output reports import time, SQLite size, free space, PHP memory, process RSS, and whether the temporary database was removed after `close()`.

Use `--json` when the benchmark output will be consumed by another tool.

## Usage

Open a dump, then query it with the package facade:

```php
use EloquentFile\EloquentFile\Facades\EloquentFile;

EloquentFile::open(storage_path('backups/backup.sql'));

$activeUsers = EloquentFile::table('users')
    ->where('status', 'Active')
    ->orderBy('name')
    ->get();
```

The loaded connection is registered as `eloquent-file`, so it can be used by an Eloquent model:

```php
use Illuminate\Database\Eloquent\Model;

class ArchivedUser extends Model
{
    protected $connection = 'eloquent-file';

    protected $table = 'users';
}

EloquentFile::open(storage_path('backups/backup.sql'));

$user = ArchivedUser::find(1);
```

For dependency injection, use the underlying service directly. A custom connection name may be passed as the second argument:

```php
use EloquentFile\EloquentFile\EloquentFile;

$dump = app(EloquentFile::class)->open(
    storage_path('backups/backup.sql'),
    'archive',
);

$total = $dump->table('invoices')->sum('total');

$dump->close();
```

### Schema and Completion Metadata

The loaded dump exposes JSON-friendly metadata for integrations and AI tools without any additional configuration:

```php
$dump = EloquentFile::open(storage_path('backups/backup.sql'));

$schema = $dump->schema();
$tables = $dump->tables('user');
$fields = $dump->columns('users', 'na');
```

`schema()` returns table names and column names. `tables()` and `columns()` filter suggestions by a case-insensitive prefix. Query clauses remain available through Laravel's native Query Builder. The metadata does not include MySQL indexes, constraints, or relationships that are not recreated in SQLite.

Write operations throw an `Illuminate\Database\QueryException`. Opening another dump with the same connection name replaces the previous temporary connection. `table()` keeps Laravel's normal Query Builder behavior. For large result sets, opt into `cursor()` for row-by-row reads or `lazy()` for chunked reads.

### Supported Dumps

The reader supports standard MySQL and MariaDB table dumps containing backtick-quoted `CREATE TABLE` and `INSERT INTO ... VALUES` statements, including extended inserts and MySQL string escapes. Input is streamed while the resulting database is held in a temporary SQLite file by default.

MySQL-specific indexes, constraints, generated expressions, procedures, triggers, and views are not recreated. Queries use SQLite semantics, so raw MySQL-only SQL is not portable. Read-only mode prevents application writes; it is not a security sandbox for untrusted PHP code. The temporary SQLite database requires enough disk space for the reconstructed data; the OS temporary directory may be configured for deployments with a dedicated disk.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Eloquent File! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Benny Rahmat](https://github.com/akunbeben)
- [All Contributors](../../contributors)

## License

Eloquent File is open-sourced software licensed under the [MIT license](LICENSE.md).
