<?php

declare(strict_types=1);

namespace EloquentFile\EloquentFile;

use Illuminate\Database\Connection;
use PDOStatement;
use RuntimeException;
use SplFileObject;
use Throwable;
use UnexpectedValueException;

final class SqlDumpLoader
{
    public function load(string $path, Connection $connection): void
    {
        $file = new SplFileObject($path, 'rb');
        $schemas = [];
        $createTable = null;
        $createColumns = [];
        $insertTable = null;
        $statement = null;
        $row = [];
        $token = '';
        $inRow = false;
        $inString = false;
        $escaped = false;
        $quoted = false;

        $connection->beginTransaction();

        try {
            while (! $file->eof()) {
                $line = $file->fgets();

                if ($insertTable !== null) {
                    $ended = $this->insertRows(
                        $line,
                        $statement,
                        $row,
                        $token,
                        $inRow,
                        $inString,
                        $escaped,
                        $quoted,
                    );

                    if ($ended) {
                        $insertTable = null;
                        $statement = null;
                    }

                    continue;
                }

                if ($createTable !== null) {
                    if (preg_match('/^\s*\).*;\s*$/', $line) === 1) {
                        if ($createColumns === []) {
                            throw new UnexpectedValueException("Table [$createTable] has no readable columns.");
                        }

                        $definitions = [];

                        foreach ($createColumns as $column => $type) {
                            $definitions[] = $this->quote($column).' '.$type;
                        }

                        $connection->statement(sprintf(
                            'CREATE TABLE %s (%s)',
                            $this->quote($createTable),
                            implode(', ', $definitions),
                        ));

                        $schemas[$createTable] = array_keys($createColumns);
                        $createTable = null;
                        $createColumns = [];

                        continue;
                    }

                    if (preg_match('/^\s*`((?:``|[^`])+)`\s+([a-zA-Z]+)/', $line, $column) === 1) {
                        $createColumns[$this->identifier($column[1])] = $this->affinity($column[2]);
                    }

                    continue;
                }

                if (preg_match('/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`((?:``|[^`])+)`\s*\(/i', ltrim($line), $table) === 1) {
                    $createTable = $this->identifier($table[1]);

                    continue;
                }

                if (preg_match('/^INSERT\s+INTO\s+`((?:``|[^`])+)`\s*(?:\((.*?)\)\s*)?VALUES\s*(.*)$/is', ltrim($line), $insert) !== 1) {
                    continue;
                }

                $insertTable = $this->identifier($insert[1]);

                if (! isset($schemas[$insertTable])) {
                    throw new UnexpectedValueException("INSERT references unknown table [$insertTable].");
                }

                $columns = $schemas[$insertTable];

                if ($insert[2] !== '') {
                    preg_match_all('/`((?:``|[^`])+)`/', $insert[2], $matches);
                    $columns = array_map($this->identifier(...), $matches[1]);
                }

                if ($columns === []) {
                    throw new UnexpectedValueException("INSERT for table [$insertTable] has no readable columns.");
                }

                $statement = $connection->getPdo()->prepare(sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    $this->quote($insertTable),
                    implode(', ', array_map($this->quote(...), $columns)),
                    implode(', ', array_fill(0, count($columns), '?')),
                ));

                if (! $statement instanceof PDOStatement) {
                    throw new RuntimeException("Unable to prepare INSERT for table [$insertTable].");
                }

                $ended = $this->insertRows(
                    $insert[3],
                    $statement,
                    $row,
                    $token,
                    $inRow,
                    $inString,
                    $escaped,
                    $quoted,
                );

                if ($ended) {
                    $insertTable = null;
                    $statement = null;
                }
            }

            if ($createTable !== null || $insertTable !== null || $inRow || $inString) {
                throw new UnexpectedValueException('The SQL dump ends in an incomplete statement.');
            }

            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            throw new UnexpectedValueException(sprintf(
                'Unable to load SQL dump [%s] near line %d: %s',
                $path,
                $file->key() + 1,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function insertRows(
        string $chunk,
        ?PDOStatement $statement,
        array &$row,
        string &$token,
        bool &$inRow,
        bool &$inString,
        bool &$escaped,
        bool &$quoted,
    ): bool {
        if (! $statement instanceof PDOStatement) {
            throw new RuntimeException('The INSERT statement is unavailable.');
        }

        $length = strlen($chunk);

        for ($position = 0; $position < $length; $position++) {
            $character = $chunk[$position];

            if ($inString) {
                if ($escaped) {
                    $token .= $this->unescape($character);
                    $escaped = false;

                    continue;
                }

                if ($character === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($character === "'") {
                    if (($chunk[$position + 1] ?? null) === "'") {
                        $token .= "'";
                        $position++;

                        continue;
                    }

                    $inString = false;

                    continue;
                }

                $token .= $character;

                continue;
            }

            if (! $inRow) {
                if ($character === '(') {
                    $inRow = true;
                    $row = [];
                    $token = '';
                    $quoted = false;
                } elseif ($character === ';') {
                    return true;
                }

                continue;
            }

            if ($character === "'") {
                $prefix = strtolower(trim($token));

                if ($prefix === '' || $prefix === '_binary' || $prefix === 'n') {
                    $token = '';
                    $quoted = true;
                    $inString = true;

                    continue;
                }
            }

            if ($character === ',') {
                $row[] = $this->value($token, $quoted);
                $token = '';
                $quoted = false;

                continue;
            }

            if ($character === ')') {
                $row[] = $this->value($token, $quoted);
                $statement->execute($row);
                $row = [];
                $token = '';
                $inRow = false;
                $quoted = false;

                continue;
            }

            $token .= $character;
        }

        return false;
    }

    private function affinity(string $mysqlType): string
    {
        $type = strtolower($mysqlType);

        // ponytail: Preserve query semantics, not MySQL schema metadata; add a dialect layer if metadata is needed.
        return match (true) {
            str_contains($type, 'int'), $type === 'bool', $type === 'boolean' => 'INTEGER',
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real'], true) => 'NUMERIC',
            in_array($type, ['blob', 'binary', 'varbinary'], true) => 'BLOB',
            default => 'TEXT',
        };
    }

    private function value(string $value, bool $quoted): ?string
    {
        if ($quoted) {
            return $value;
        }

        $value = trim($value);

        if ($value === '') {
            throw new UnexpectedValueException('An INSERT contains an empty value.');
        }

        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        if (preg_match('/^0x([a-f0-9]+)$/i', $value, $hex) === 1) {
            $binary = hex2bin($hex[1]);

            if ($binary === false) {
                throw new UnexpectedValueException('An INSERT contains invalid hexadecimal data.');
            }

            return $binary;
        }

        return $value;
    }

    private function unescape(string $character): string
    {
        return match ($character) {
            '0' => "\0",
            'b' => "\x08",
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'Z' => "\x1a",
            default => $character,
        };
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function identifier(string $identifier): string
    {
        return str_replace('``', '`', $identifier);
    }
}
