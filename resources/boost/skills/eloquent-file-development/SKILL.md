---
name: eloquent-file-development
description: Query MySQL or MariaDB SQL dumps through Laravel Query Builder or Eloquent using Eloquent File.
license: MIT
metadata:
  author: Benny Rahmat
---

# Eloquent File

Use this skill when a Laravel application needs read-only access to data in a MySQL or MariaDB dump without restoring it to a database server.

## Primary Goal

- open a table dump and query its data through Laravel's native database APIs

## Workflow

### 1. Confirm the input and runtime

- require `akunbeben/eloquent-file` and confirm PDO SQLite is enabled
- use a standard MySQL or MariaDB table dump with `CREATE TABLE` and `INSERT INTO ... VALUES` statements
- use the default temporary file-backed SQLite storage for large dumps; configure `eloquent-file.temporary_directory` when needed
- abandoned package-owned temporary databases are cleaned up after 24 hours by default
- imports require free space for the dump plus a default 100 MB safety buffer

### 2. Open and query the dump

- call `EloquentFile::open($path)` before querying
- use `EloquentFile::table($table)` for Query Builder access
- use the registered `eloquent-file` connection on Eloquent models
- pass a second argument to `open()` only when a custom connection name is needed

### 3. Discover schema for integrations

- call `schema()` for a JSON-friendly map of loaded tables and columns
- call `tables($prefix)` to suggest table names
- call `columns($table, $prefix)` to suggest fields for a table

## Rules, References, and Templates

- facade: `EloquentFile\EloquentFile\Facades\EloquentFile`
- injectable service: `EloquentFile\EloquentFile\EloquentFile`
- default connection name: `eloquent-file`
- write attempts fail with `Illuminate\Database\QueryException`

## Examples

```php
use EloquentFile\EloquentFile\Facades\EloquentFile;

EloquentFile::open(storage_path('backups/backup.sql'));

$users = EloquentFile::table('users')
    ->where('status', 'Active')
    ->get();
```

For an integration or AI tool:

```php
$dump = EloquentFile::open(storage_path('backups/backup.sql'));

$context = $dump->schema();
$tableSuggestions = $dump->tables('user');
$fieldSuggestions = $dump->columns('users', 'na');

foreach ($dump->table('users')->orderBy('id')->cursor() as $user) {
    // Process one row at a time without materializing the full result.
}
```

For Eloquent, set `protected $connection = 'eloquent-file';` and the dump table name on the model before querying it.

## Anti-patterns

- `table()` keeps Laravel's normal Query Builder behavior; use `cursor()` or `lazy()` explicitly for large result sets
- call `$dump->close()` when a long-lived worker no longer needs the dump
- do not attempt inserts, updates, deletes, migrations, or model saves against the dump connection
- do not expect MySQL-only raw SQL, indexes, constraints, procedures, triggers, views, or generated expressions to be available
- do not treat schema metadata as a source of MySQL relationships or constraints; the loader exposes the recreated SQLite tables and columns
- do not treat read-only mode as a sandbox for untrusted application code
