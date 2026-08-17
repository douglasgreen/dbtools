<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools;

final class Sql
{
    public static function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public static function qualify(string $database, string $table): string
    {
        return self::quoteIdentifier($database) . '.' . self::quoteIdentifier($table);
    }
}
