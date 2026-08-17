<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Integration;

final class ConnectionIntegrationTest extends IntegrationTestCase
{
    public function testSessionDefaultsAreApplied(): void
    {
        $connection = $this->connect();

        $rows = $connection->fetchAll(
            'SELECT @@session.time_zone AS tz, @@session.sql_mode AS mode, '
                . '@@session.foreign_key_checks AS fk',
        );

        self::assertSame('+00:00', $rows[0]['tz']);
        self::assertStringContainsString('NO_AUTO_VALUE_ON_ZERO', (string) $rows[0]['mode']);
        self::assertSame('0', (string) $rows[0]['fk']);
    }

    public function testMaxAllowedPacketIsPositive(): void
    {
        self::assertGreaterThan(0, $this->connect()->maxAllowedPacket());
    }

    public function testSourcePathLeavesChecksEnabled(): void
    {
        // Open directly via Connection::open() to bypass $this->connect()'s applySessionDefaults(true)
        $connection = \DouglasGreen\DbTools\Db\Connection::open($this->connectionConfig());
        $connection->applySessionDefaults(false);

        $rows = $connection->fetchAll(
            'SELECT @@session.time_zone AS tz, @@session.sql_mode AS mode, '
                . '@@session.foreign_key_checks AS fk, @@session.unique_checks AS uc',
        );

        // Shared settings apply to both source and target paths
        self::assertSame('+00:00', $rows[0]['tz']);
        self::assertStringContainsString('NO_AUTO_VALUE_ON_ZERO', (string) $rows[0]['mode']);

        // Constraint checks are left enabled on the source path only
        self::assertSame('1', (string) $rows[0]['fk']);
        self::assertSame('1', (string) $rows[0]['uc']);
    }
}
