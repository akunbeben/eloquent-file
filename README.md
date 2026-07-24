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

Query a MySQL or MariaDB SQL dump with Laravel's Query Builder and Eloquent, without restoring it to a database server. The dump is loaded into a read-only, in-memory SQLite connection.

## Installation

You can install the package via Composer:

```bash
composer require akunbeben/eloquent-file
```

The PHP PDO SQLite extension must be enabled. Laravel discovers the package automatically.

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
```

Write operations throw an `Illuminate\Database\QueryException`. Opening another dump with the same connection name replaces the previous in-memory connection.

### Supported Dumps

The reader supports standard MySQL and MariaDB table dumps containing backtick-quoted `CREATE TABLE` and `INSERT INTO ... VALUES` statements, including extended inserts and MySQL string escapes. Input is streamed while the resulting database is held in memory.

MySQL-specific indexes, constraints, generated expressions, procedures, triggers, and views are not recreated. Queries use SQLite semantics, so raw MySQL-only SQL is not portable. Read-only mode prevents application writes; it is not a security sandbox for untrusted PHP code.

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
