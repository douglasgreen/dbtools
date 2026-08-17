<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Db;

use DouglasGreen\DbTools\Schema\ColumnInfo;
use DouglasGreen\DbTools\Schema\DatabaseSchema;
use DouglasGreen\DbTools\Schema\ForeignKey;
use DouglasGreen\DbTools\Schema\TableInfo;
use DouglasGreen\DbTools\Sql;
use RuntimeException;

final class SchemaInspector
{
    private const SYSTEM_SCHEMAS = ['mysql', 'information_schema', 'performance_schema', 'sys'];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * SHOW DATABASES only returns schemas the connecting user can see, so
     * "all readable databases" needs no extra permission check.
     *
     * @param list<string> $excluded lowercased names from config
     *
     * @return list<string>
     */
    public function databases(array $excluded): array
    {
        $skip = array_merge(self::SYSTEM_SCHEMAS, $excluded);
        $names = [];

        foreach ($this->connection->fetchAll('SHOW DATABASES') as $row) {
            $name = (string) reset($row);
            if (! in_array(strtolower($name), $skip, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function inspect(string $database): DatabaseSchema
    {
        $tableNames = [];
        foreach (
            $this->connection->fetchAll(
                'SHOW FULL TABLES FROM ' . Sql::quoteIdentifier($database) . " WHERE Table_type = 'BASE TABLE'",
            ) as $row
        ) {
            $tableNames[] = (string) reset($row);
        }

        $sizes = $this->sizes($database);
        $columns = $this->columns($database);
        $primaryKeys = $this->primaryKeys($database);

        $tables = [];
        foreach ($tableNames as $name) {
            if (! isset($columns[$name])) {
                $this->warnings[] = sprintf('Table %s.%s has no readable columns; skipped.', $database, $name);
                continue;
            }

            $tables[$name] = new TableInfo(
                $database,
                $name,
                $sizes[$name]['bytes'] ?? 0,
                $sizes[$name]['rows'] ?? 0,
                $columns[$name],
                $primaryKeys[$name] ?? [],
            );
        }

        return new DatabaseSchema($database, $tables, $this->foreignKeys($database, $tables));
    }

    /**
     * @return array<string, array{bytes: int, rows: int}>
     */
    private function sizes(string $database): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT TABLE_NAME, DATA_LENGTH, INDEX_LENGTH, TABLE_ROWS '
                . 'FROM information_schema.TABLES '
                . "WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
            [$database],
        );

        $sizes = [];
        foreach ($rows as $row) {
            $sizes[(string) $row['TABLE_NAME']] = [
                'bytes' => (int) $row['DATA_LENGTH'] + (int) $row['INDEX_LENGTH'],
                'rows' => (int) $row['TABLE_ROWS'],
            ];
        }

        return $sizes;
    }

    /**
     * @return array<string, array<string, ColumnInfo>>
     */
    private function columns(string $database): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE '
                . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? '
                . 'ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$database],
        );

        $columns = [];
        foreach ($rows as $row) {
            $table = (string) $row['TABLE_NAME'];
            $name = (string) $row['COLUMN_NAME'];

            $columns[$table][$name] = new ColumnInfo(
                $name,
                strtolower((string) $row['DATA_TYPE']),
                (string) $row['COLUMN_TYPE'],
                strtoupper((string) $row['IS_NULLABLE']) === 'YES',
            );
        }

        return $columns;
    }

    /**
     * @return array<string, list<string>>
     */
    private function primaryKeys(string $database): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE '
                . "WHERE TABLE_SCHEMA = ? AND CONSTRAINT_NAME = 'PRIMARY' "
                . 'ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$database],
        );

        $keys = [];
        foreach ($rows as $row) {
            $keys[(string) $row['TABLE_NAME']][] = (string) $row['COLUMN_NAME'];
        }

        return $keys;
    }

    /**
     * @param array<string, TableInfo> $tables
     *
     * @return list<ForeignKey>
     */
    private function foreignKeys(string $database, array $tables): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, '
                . 'REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME '
                . 'FROM information_schema.KEY_COLUMN_USAGE '
                . 'WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL '
                . 'ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
            [$database],
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['TABLE_NAME'] . "\0" . (string) $row['CONSTRAINT_NAME']][] = $row;
        }

        $keys = [];
        foreach ($grouped as $constraint) {
            $first = $constraint[0];
            $child = (string) $first['TABLE_NAME'];
            $parent = (string) $first['REFERENCED_TABLE_NAME'];

            if (count($constraint) > 1) {
                $this->warnings[] = sprintf(
                    'Foreign key %s on %s.%s is composite (%d columns); not used for subnetting or pruning.',
                    (string) $first['CONSTRAINT_NAME'],
                    $database,
                    $child,
                    count($constraint),
                );
                continue;
            }

            if (strcasecmp((string) $first['REFERENCED_TABLE_SCHEMA'], $database) !== 0) {
                $this->warnings[] = sprintf(
                    'Foreign key %s on %s.%s references another schema (%s); not used for pruning.',
                    (string) $first['CONSTRAINT_NAME'],
                    $database,
                    $child,
                    (string) $first['REFERENCED_TABLE_SCHEMA'],
                );
                continue;
            }

            if (! isset($tables[$child]) || ! isset($tables[$parent])) {
                continue;
            }

            $keys[] = new ForeignKey(
                $child,
                (string) $first['COLUMN_NAME'],
                $parent,
                (string) $first['REFERENCED_COLUMN_NAME'],
                true,
            );
        }

        return $keys;
    }

    public function createTableStatement(string $database, string $table): string
    {
        $rows = $this->connection->fetchAll('SHOW CREATE TABLE ' . Sql::qualify($database, $table));

        foreach ($rows[0] ?? [] as $key => $value) {
            if (strcasecmp((string) $key, 'Create Table') === 0) {
                return (string) $value;
            }
        }

        throw new RuntimeException(sprintf('Cannot read CREATE TABLE for %s.%s', $database, $table));
    }

    /**
     * @return array{charset: string, collation: string}
     */
    public function databaseCharset(string $database): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME '
                . 'FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$database],
        );

        return [
            'charset' => (string) ($rows[0]['DEFAULT_CHARACTER_SET_NAME'] ?? 'utf8'),
            'collation' => (string) ($rows[0]['DEFAULT_COLLATION_NAME'] ?? 'utf8_general_ci'),
        ];
    }

    public function databaseSizeBytes(string $database): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS total '
                . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [$database],
        );

        return (int) ($rows[0]['total'] ?? 0);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
