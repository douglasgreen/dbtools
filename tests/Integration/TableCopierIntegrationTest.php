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
