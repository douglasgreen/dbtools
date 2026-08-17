<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Db;

use DouglasGreen\DbTools\Config\ConnectionConfig;
use DouglasGreen\DbTools\Db\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    public function testDsnWithoutDatabase(): void
    {
        $config = new ConnectionConfig('prod', 'db1.example.invalid', 'u', 'p', 3307);

        self::assertSame('mysql:host=db1.example.invalid;port=3307', Connection::dsn($config, null));
    }

    public function testDsnWithDatabase(): void
    {
        $config = new ConnectionConfig('dev', '127.0.0.1', 'u', 'p');

        self::assertSame('mysql:host=127.0.0.1;port=3306;dbname=shop', Connection::dsn($config, 'shop'));
    }
}
