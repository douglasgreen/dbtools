<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Schema;

final readonly class TableInfo
{
    /**
     * @param array<string, ColumnInfo> $columns keyed by column name, in ordinal order
     * @param list<string>              $primaryKey column names in key order
     */
    public function __construct(
        public string $database,
        public string $name,
        public int $sizeBytes,
        public int $estimatedRows,
        public array $columns,
        public array $primaryKey,
    ) {
    }

    public function column(string $name): ?ColumnInfo
    {
        return $this->columns[$name] ?? null;
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    /**
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_keys($this->columns);
    }

    public function singleIntegerPrimaryKey(): ?ColumnInfo
    {
        if (count($this->primaryKey) !== 1) {
            return null;
        }

        $column = $this->column($this->primaryKey[0]);

        return $column !== null && $column->isInteger() ? $column : null;
    }
}
