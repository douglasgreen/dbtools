<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Schema;

final readonly class ForeignKey
{
    public function __construct(
        public string $childTable,
        public string $childColumn,
        public string $parentTable,
        public string $parentColumn,
        public bool $isDeclared,
    ) {
    }

    public function isSelfReference(): bool
    {
        return $this->childTable === $this->parentTable;
    }
}
