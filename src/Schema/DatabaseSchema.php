<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Schema;

final readonly class DatabaseSchema
{
    /**
     * @param array<string, TableInfo> $tables             keyed by table name
     * @param list<ForeignKey>         $declaredForeignKeys single-column declared keys only
     */
    public function __construct(
        public string $name,
        public array $tables,
        public array $declaredForeignKeys,
    ) {
    }

    public function table(string $name): ?TableInfo
    {
        return $this->tables[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function tableNames(): array
    {
        return array_keys($this->tables);
    }

}
