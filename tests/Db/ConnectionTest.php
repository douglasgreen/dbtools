<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Db;

use DouglasGreen\DbTools\Config\ConnectionConfig;
use DouglasGreen\DbTools\Db\Connection;
use PDOException;
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

    public function testServerGoneAwayCountsAsLostConnection(): void
    {
        self::assertTrue(Connection::isConnectionLost(
            self::driverError('HY000', 2006, 'MySQL server has gone away'),
        ));
    }

    /**
     * The packet-too-large error is the usual cause of the 2006 seen on every
     * later statement, so it has to abort the run the same way.
     */
    public function testPacketTooLargeCountsAsLostConnection(): void
    {
        self::assertTrue(Connection::isConnectionLost(
            self::driverError('08S01', 1153, "Got a packet bigger than 'max_allowed_packet' bytes"),
        ));
    }

    public function testOrdinaryStatementErrorIsNotLostConnection(): void
    {
        self::assertFalse(Connection::isConnectionLost(
            self::driverError('42S02', 1146, "Table 'x.y' doesn't exist"),
        ));
    }

    public function testDescribeErrorIncludesSqlStateAndDriverCode(): void
    {
        $description = Connection::describeError(
            self::driverError('HY000', 2006, 'MySQL server has gone away'),
        );

        self::assertStringContainsString('SQLSTATE=HY000', $description);
        self::assertStringContainsString('driver_code=2006', $description);
        self::assertStringContainsString('gone away', $description);
    }

    private static function driverError(string $sqlState, int $code, string $message): PDOException
    {
        $exception = new PDOException(sprintf('SQLSTATE[%s]: %s', $sqlState, $message));
        $exception->errorInfo = [$sqlState, $code, $message];

        return $exception;
    }
}
