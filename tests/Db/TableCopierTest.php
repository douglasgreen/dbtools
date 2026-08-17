<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Db;

use DouglasGreen\DbTools\Db\TableCopier;
use PHPUnit\Framework\TestCase;

final class TableCopierTest extends TestCase
{
    public function testBatchSizeIsUsedWhenColumnCountIsSmall(): void
    {
        self::assertSame(1000, TableCopier::rowsPerStatement(5, 1000));
    }

    public function testPlaceholderCeilingCapsWideTables(): void
    {
        // 60000 / 200 = 300 rows, well under the 65535 placeholder limit.
        self::assertSame(300, TableCopier::rowsPerStatement(200, 1000));
    }

    public function testAtLeastOneRowIsAlwaysAllowed(): void
    {
        self::assertSame(1, TableCopier::rowsPerStatement(70000, 1000));
        self::assertSame(1, TableCopier::rowsPerStatement(0, 1000));
    }
}
