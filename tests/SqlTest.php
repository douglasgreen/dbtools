<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests;

use DouglasGreen\DbTools\Sql;
use PHPUnit\Framework\TestCase;

final class SqlTest extends TestCase
{
    public function testWrapsIdentifierInBackticks(): void
    {
        self::assertSame('`orders`', Sql::quoteIdentifier('orders'));
    }

    public function testDoublesEmbeddedBackticks(): void
    {
        self::assertSame('`we``ird`', Sql::quoteIdentifier('we`ird'));
    }

    public function testQualifiesDatabaseAndTable(): void
    {
        self::assertSame('`shop`.`orders`', Sql::qualify('shop', 'orders'));
    }
}
