<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Integration;

use DouglasGreen\DbTools\Db\SchemaInspector;
use DouglasGreen\DbTools\Db\TableCopier;
use DouglasGreen\DbTools\Plan\TablePlan;

final class TableCopierIntegrationTest extends IntegrationTestCase
{
    private const SOURCE = 'dbsync_copier_src';

    private const TARGET = 'dbsync_copier_dst';

    protected function tearDown(): void
    {
        if (getenv('DBSYNC_TEST_DSN') !== false) {
            $connection = $this->connect();
            $this->dropDatabase($connection, self::SOURCE);
            $this->dropDatabase($connection, self::TARGET);
        }
    }

    public function testCopiesFilteredRowsAndPreservesBinaryAndNullValues(): void
    {
        $source = $this->connect();
        $this->recreateDatabase($source, self::SOURCE);
        $this->recreateDatabase($source, self::TARGET);
        $source->useDatabase(self::SOURCE);

        $source->exec(
            'CREATE TABLE `widgets` ('
                . '`widget_id` INT UNSIGNED NOT NULL,'
                . '`payload` VARBINARY(64) NULL,'
                . '`label` VARCHAR(50) NULL,'
                . 'PRIMARY KEY (`widget_id`)) ENGINE=InnoDB',
        );

        $binary = "\x00\xff\xfe\x01text";
        $insert = $source->pdo()->prepare('INSERT INTO `widgets` VALUES (?, ?, ?)');
        for ($id = 1; $id <= 20; ++$id) {
            $insert->execute([$id, $id === 4 ? $binary : null, $id === 4 ? null : 'row ' . $id]);
        }

        $schema = (new SchemaInspector($source))->inspect(self::SOURCE);
        $widgets = $schema->table('widgets');
        self::assertNotNull($widgets);

        $target = $this->connect();
        $copier = new TableCopier($source, $target, 3);
        $copier->recreate(
            self::TARGET,
            'widgets',
            (new SchemaInspector($source))->createTableStatement(self::SOURCE, 'widgets'),
        );

        $plan = new TablePlan(
            'widgets',
            TablePlan::SUBNET,
            4,
            0,
            '(`widget_id` % 4) = 0',
            true,
            false,
            'test',
        );

        $result = $copier->copy($widgets, $plan);

        self::assertSame(5, $result->rowsRead);
        self::assertSame(5, $result->rowsInserted);

        $target->useDatabase(self::TARGET);
        $ids = array_map(
            static fn (array $r): int => (int) $r['widget_id'],
            $target->fetchAll('SELECT `widget_id` FROM `widgets` ORDER BY `widget_id`'),
        );
        self::assertSame([4, 8, 12, 16, 20], $ids);

        $row = $target->fetchAll('SELECT `payload`, `label` FROM `widgets` WHERE `widget_id` = 4');
        self::assertSame($binary, $row[0]['payload']);
        self::assertNull($row[0]['label']);
    }

    /**
     * An oversized row must be skipped rather than sent. MySQL answers a packet
     * larger than max_allowed_packet by closing the connection, after which
     * every later statement — including the prune pass — reports the misleading
     * "MySQL server has gone away".
     */
    public function testRowLargerThanTheTargetPacketIsSkippedAndTheConnectionSurvives(): void
    {
        $source = $this->connect();
        $packet = $source->maxAllowedPacket();

        if ($packet > 16 * 1024 * 1024) {
            self::markTestSkipped(sprintf(
                'max_allowed_packet is %d bytes; building an oversized row would need that much memory.',
                $packet,
            ));
        }

        $this->recreateDatabase($source, self::SOURCE);
        $this->recreateDatabase($source, self::TARGET);
        $source->useDatabase(self::SOURCE);
        $source->exec(
            'CREATE TABLE `blobs` ('
                . '`id` INT NOT NULL,'
                . '`payload` LONGBLOB NULL,'
                . 'PRIMARY KEY (`id`)) ENGINE=InnoDB',
        );

        $oversized = str_repeat('x', TableCopier::usableRowBytes($packet) + 1024);
        $insert = $source->pdo()->prepare('INSERT INTO `blobs` VALUES (?, ?)');
        $insert->execute([1, 'small']);
        // Written with a packet this size available on the source, which is the
        // situation dbsync hits: a row legal here, too large for the target.
        $insert->execute([2, $oversized]);
        $insert->execute([3, 'also small']);

        $inspector = new SchemaInspector($source);
        $schema = $inspector->inspect(self::SOURCE);
        $blobs = $schema->table('blobs');
        self::assertNotNull($blobs);

        $target = $this->connect();
        $copier = new TableCopier($source, $target, 1000);
        $copier->recreate(self::TARGET, 'blobs', $inspector->createTableStatement(self::SOURCE, 'blobs'));

        $result = $copier->copy(
            $blobs,
            new TablePlan('blobs', TablePlan::FULL, null, 0, '', true, true, 'test'),
        );

        self::assertSame(3, $result->rowsRead);
        self::assertSame(2, $result->rowsInserted);
        self::assertSame(1, $result->rowsSkipped);

        $warnings = $copier->warnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('id=2', $warnings[0]);
        self::assertStringContainsString('max_allowed_packet', $warnings[0]);

        // The whole point: the link is still usable for later tables and prunes.
        self::assertTrue($target->isAlive());

        $target->useDatabase(self::TARGET);
        $ids = array_map(
            static fn (array $r): int => (int) $r['id'],
            $target->fetchAll('SELECT `id` FROM `blobs` ORDER BY `id`'),
        );
        self::assertSame([1, 3], $ids);
    }

    public function testStructureOnlyPlanCopiesNoRows(): void
    {
        $source = $this->connect();
        $this->recreateDatabase($source, self::SOURCE);
        $this->recreateDatabase($source, self::TARGET);
        $source->useDatabase(self::SOURCE);
        $source->exec('CREATE TABLE `t` (`id` INT NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
        $source->exec('INSERT INTO `t` VALUES (1), (2)');

        $schema = (new SchemaInspector($source))->inspect(self::SOURCE);
        $table = $schema->table('t');
        self::assertNotNull($table);

        $target = $this->connect();
        $copier = new TableCopier($source, $target, 1000);
        $copier->recreate(
            self::TARGET,
            't',
            (new SchemaInspector($source))->createTableStatement(self::SOURCE, 't'),
        );

        $plan = new TablePlan('t', TablePlan::STRUCTURE_ONLY, null, 0, '', false, false, 'test');
        $result = $copier->copy($table, $plan);

        self::assertSame(0, $result->rowsInserted);

        $target->useDatabase(self::TARGET);
        self::assertSame(0, (int) $target->fetchAll('SELECT COUNT(*) AS c FROM `t`')[0]['c']);
    }

    public function testSameColumnCountTablesDoNotShareACachedPreparedStatement(): void
    {
        $source = $this->connect();
        $this->recreateDatabase($source, self::SOURCE);
        $this->recreateDatabase($source, self::TARGET);
        $source->useDatabase(self::SOURCE);

        $source->exec('CREATE TABLE `alpha` (`id` INT NOT NULL, `val` VARCHAR(20) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
        $source->exec('CREATE TABLE `beta` (`id` INT NOT NULL, `val` VARCHAR(20) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');

        $source->exec("INSERT INTO `alpha` VALUES (1, 'alpha1'), (2, 'alpha2'), (3, 'alpha3')");
        $source->exec("INSERT INTO `beta` VALUES (1, 'beta1'), (2, 'beta2'), (3, 'beta3')");

        $inspector = new SchemaInspector($source);
        $schema = $inspector->inspect(self::SOURCE);
        $alpha = $schema->table('alpha');
        $beta = $schema->table('beta');
        self::assertNotNull($alpha);
        self::assertNotNull($beta);

        $target = $this->connect();
        $copier = new TableCopier($source, $target, 100);

        $copier->recreate(self::TARGET, 'alpha', $inspector->createTableStatement(self::SOURCE, 'alpha'));
        $plan = new TablePlan('alpha', TablePlan::FULL, null, 0, '', true, true, 'test');
        $copier->copy($alpha, $plan);

        $copier->recreate(self::TARGET, 'beta', $inspector->createTableStatement(self::SOURCE, 'beta'));
        $plan = new TablePlan('beta', TablePlan::FULL, null, 0, '', true, true, 'test');
        $copier->copy($beta, $plan);

        $target->useDatabase(self::TARGET);

        $alphaVals = array_map(
            static fn (array $r): string => (string) $r['val'],
            $target->fetchAll('SELECT `val` FROM `alpha` ORDER BY `id`'),
        );
        self::assertSame(['alpha1', 'alpha2', 'alpha3'], $alphaVals);

        $betaVals = array_map(
            static fn (array $r): string => (string) $r['val'],
            $target->fetchAll('SELECT `val` FROM `beta` ORDER BY `id`'),
        );
        self::assertSame(['beta1', 'beta2', 'beta3'], $betaVals);
    }
}
