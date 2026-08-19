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

    public function testUsableRowBytesLeavesHeadroomUnderMaxAllowedPacket(): void
    {
        // The default 1 MB packet on an older target: a row estimated above this
        // cannot be sent at all, because MySQL closes the link rather than
        // rejecting the statement.
        self::assertSame(983040, TableCopier::usableRowBytes(1048576));
        self::assertLessThan(1048576, TableCopier::usableRowBytes(1048576));
    }

    public function testUsableRowBytesStaysPositiveForTinyPackets(): void
    {
        self::assertSame(1024, TableCopier::usableRowBytes(4096));
    }

    public function testRowByteEstimateExceedsTheRawPayload(): void
    {
        $payload = str_repeat('x', 2890532);

        // Over-estimating is deliberate: under-estimating pushes the batch past
        // max_allowed_packet, which drops the connection.
        self::assertGreaterThan(
            strlen($payload),
            TableCopier::estimateRowBytes([1, $payload]),
        );
    }

    public function testNullsAreCountedAsProtocolOverheadOnly(): void
    {
        self::assertSame(
            TableCopier::estimateRowBytes([null, null]),
            TableCopier::estimateRowBytes(['', '']),
        );
    }

    public function testAKnownOversizedRowIsRejectedByTheLimit(): void
    {
        // MARC.Outlines holds a 2,890,532-byte longblob; the sandbox target
        // allows a 1 MB packet, so this row cannot be copied there.
        $row = [1, str_repeat('x', 2890532)];

        self::assertGreaterThan(
            TableCopier::usableRowBytes(1048576),
            TableCopier::estimateRowBytes($row),
        );
        self::assertLessThan(
            TableCopier::usableRowBytes(268435456),
            TableCopier::estimateRowBytes($row),
        );
    }
}
