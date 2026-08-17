<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Schema;

final readonly class ColumnInfo
{
    private const INTEGER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];

    public function __construct(
        public string $name,
        public string $dataType,
        public string $columnType,
        public bool $isNullable,
    ) {
    }

    public function isInteger(): bool
    {
        return in_array(strtolower($this->dataType), self::INTEGER_TYPES, true);
    }

    public function isUnsigned(): bool
    {
        return str_contains(strtolower($this->columnType), 'unsigned');
    }

    public function matchesIntegerType(self $other): bool
    {
        if (! $this->isInteger() || ! $other->isInteger()) {
            return false;
        }

        return strtolower($this->dataType) === strtolower($other->dataType)
            && $this->isUnsigned() === $other->isUnsigned();
    }
}
