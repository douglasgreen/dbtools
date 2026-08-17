<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Db;

final readonly class PruneResult
{
    public function __construct(
        public string $table,
        public string $column,
        public string $parentTable,
        public int $rowsDeleted,
    ) {
    }
}
