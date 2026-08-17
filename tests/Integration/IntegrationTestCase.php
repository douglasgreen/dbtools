<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Integration;

use DouglasGreen\DbTools\Config\ConnectionConfig;
use DouglasGreen\DbTools\Db\Connection;
use DouglasGreen\DbTools\Sql;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DBSYNC_TEST_DSN') === false) {
            self::markTestSkipped(
                'Set DBSYNC_TEST_DSN to run integration tests, e.g. '
                    . '"host=127.0.0.1;port=3306;user=root;password=secret"',
            );
        }
    }

    protected function connectionConfig(): ConnectionConfig
    {
        return $this->parseDsn('src', (string) getenv('DBSYNC_TEST_DSN'));
    }

    /**
     * A genuinely separate server. dbsync copies every database to the same
     * name on the target, so an end-to-end test against one server would have
     * the tool overwrite its own source data. Tests needing this must skip
     * when DBSYNC_TEST_TARGET_DSN is unset.
     */
    protected function targetConnectionConfig(): ConnectionConfig
    {
        $raw = getenv('DBSYNC_TEST_TARGET_DSN');
        if ($raw === false) {
            self::markTestSkipped(
                'Set DBSYNC_TEST_TARGET_DSN to a SECOND MySQL server to run end-to-end tests. '
                    . 'It must not be the same host and port as DBSYNC_TEST_DSN.',
            );
        }

        $target = $this->parseDsn('dst', (string) $raw);
        $source = $this->connectionConfig();

        if (strcasecmp($source->host, $target->host) === 0 && $source->port === $target->port) {
            self::markTestSkipped(
                'DBSYNC_TEST_TARGET_DSN points at the same host and port as DBSYNC_TEST_DSN.',
            );
        }

        return $target;
    }

    private function parseDsn(string $name, string $raw): ConnectionConfig
    {
        $parts = [];

        foreach (explode(';', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[trim($key)] = $value;
        }

        return new ConnectionConfig(
            $name,
            $parts['host'] ?? '127.0.0.1',
            $parts['user'] ?? 'root',
            $parts['password'] ?? '',
            isset($parts['port']) ? (int) $parts['port'] : 3306,
        );
    }

    protected function connect(?string $database = null, bool $unbuffered = false): Connection
    {
        $connection = Connection::open($this->connectionConfig(), $database, $unbuffered);
        $connection->applySessionDefaults(true);

        return $connection;
    }

    protected function connectTarget(?string $database = null): Connection
    {
        $connection = Connection::open($this->targetConnectionConfig(), $database);
        $connection->applySessionDefaults(true);

        return $connection;
    }

    protected function recreateDatabase(Connection $connection, string $database): void
    {
        $connection->exec('DROP DATABASE IF EXISTS ' . Sql::quoteIdentifier($database));
        $connection->exec('CREATE DATABASE ' . Sql::quoteIdentifier($database));
    }

    protected function dropDatabase(Connection $connection, string $database): void
    {
        $connection->exec('DROP DATABASE IF EXISTS ' . Sql::quoteIdentifier($database));
    }
}
