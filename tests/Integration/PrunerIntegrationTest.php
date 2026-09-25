<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Integration;

use DouglasGreen\DbTools\Db\Pruner;
use DouglasGreen\DbTools\Plan\TablePlan;
use DouglasGreen\DbTools\Schema\ForeignKey;

final class PrunerIntegrationTest extends IntegrationTestCase
{
    private const DB = 'dbsync_prune_test';

    protected function tearDown(): void
    {
        if (getenv('DBSYNC_TEST_DSN') !== false) {
            $this->dropDatabase($this->connect(), self::DB);
        }
    }

    public function testGrandchildOrphansAreRemoved(): void
    {
        $target = $this->connect();
        $this->recreateDatabase($target, self::DB);
        $target->useDatabase(self::DB);

        $target->exec('CREATE TABLE `customers` (`customer_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`customer_id`)) ENGINE=InnoDB');
        $target->exec('CREATE TABLE `orders` (`order_id` INT UNSIGNED NOT NULL, `customer_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`order_id`)) ENGINE=InnoDB');
        $target->exec('CREATE TABLE `order_items` (`order_item_id` INT UNSIGNED NOT NULL, `order_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`order_item_id`)) ENGINE=InnoDB');

        // customer 1 survived the subnet, customer 2 did not.
        $target->exec('INSERT INTO `customers` VALUES (1)');
        $target->exec('INSERT INTO `orders` VALUES (10, 1), (20, 2)');
        $target->exec('INSERT INTO `order_items` VALUES (100, 10), (200, 20)');

        $edges = [
            new ForeignKey('orders', 'customer_id', 'customers', 'customer_id', false),
            new ForeignKey('order_items', 'order_id', 'orders', 'order_id', false),
        ];
        $plans = [
            'customers' => new TablePlan('customers', TablePlan::SUBNET, 3, 0, '', true, false, 't'),
            'orders' => new TablePlan('orders', TablePlan::SUBNET, 12, 0, '', false, false, 't'),
            'order_items' => new TablePlan('order_items', TablePlan::SUBNET, 20, 0, '', true, false, 't'),
        ];

        $results = (new Pruner($target))->prune(
            self::DB,
            $edges,
            $plans,
            ['customers', 'orders', 'order_items'],
        );

        self::assertSame(
            [10],
            array_map(
                static fn (array $r): int => (int) $r['order_id'],
                $target->fetchAll('SELECT `order_id` FROM `orders`'),
            ),
        );
        self::assertSame(
            [100],
            array_map(
                static fn (array $r): int => (int) $r['order_item_id'],
                $target->fetchAll('SELECT `order_item_id` FROM `order_items`'),
            ),
        );
        self::assertCount(2, $results);
    }

    public function testNullForeignKeysSurvive(): void
    {
        $target = $this->connect();
        $this->recreateDatabase($target, self::DB);
        $target->useDatabase(self::DB);

        $target->exec('CREATE TABLE `customers` (`customer_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`customer_id`)) ENGINE=InnoDB');
        $target->exec('CREATE TABLE `orders` (`order_id` INT UNSIGNED NOT NULL, `customer_id` INT UNSIGNED NULL, PRIMARY KEY (`order_id`)) ENGINE=InnoDB');
        $target->exec('INSERT INTO `customers` VALUES (1)');
        $target->exec('INSERT INTO `orders` VALUES (10, 1), (20, NULL), (30, 99)');

        (new Pruner($target))->prune(
            self::DB,
            [new ForeignKey('orders', 'customer_id', 'customers', 'customer_id', false)],
            [
                'customers' => new TablePlan('customers', TablePlan::SUBNET, 3, 0, '', true, false, 't'),
                'orders' => new TablePlan('orders', TablePlan::FULL, null, 0, '', false, false, 't'),
            ],
            ['customers', 'orders'],
        );

        $ids = array_map(
            static fn (array $r): int => (int) $r['order_id'],
            $target->fetchAll('SELECT `order_id` FROM `orders` ORDER BY `order_id`'),
        );

        self::assertSame([10, 20], $ids);
    }

    public function testMissingTableWarnsAndOtherEdgesStillPrune(): void
    {
        $target = $this->connect();
        $this->recreateDatabase($target, self::DB);
        $target->useDatabase(self::DB);

        // `invoices` is planned but absent, as when its CREATE TABLE failed.
        $target->exec('CREATE TABLE `customers` (`customer_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`customer_id`)) ENGINE=InnoDB');
        $target->exec('CREATE TABLE `orders` (`order_id` INT UNSIGNED NOT NULL, `customer_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`order_id`)) ENGINE=InnoDB');
        $target->exec('INSERT INTO `customers` VALUES (1)');
        $target->exec('INSERT INTO `orders` VALUES (10, 1), (20, 2)');

        $edges = [
            new ForeignKey('invoices', 'customer_id', 'customers', 'customer_id', false),
            new ForeignKey('orders', 'customer_id', 'customers', 'customer_id', false),
        ];
        $plans = [
            'customers' => new TablePlan('customers', TablePlan::SUBNET, 3, 0, '', true, false, 't'),
            'invoices' => new TablePlan('invoices', TablePlan::FULL, null, 0, '', false, false, 't'),
            'orders' => new TablePlan('orders', TablePlan::FULL, null, 0, '', false, false, 't'),
        ];

        $pruner = new Pruner($target);
        $pruner->prune(self::DB, $edges, $plans, ['customers', 'invoices', 'orders']);

        $ids = array_map(
            static fn (array $r): int => (int) $r['order_id'],
            $target->fetchAll('SELECT `order_id` FROM `orders`'),
        );
        self::assertSame([10], $ids);

        $matched = array_filter(
            $pruner->warnings(),
            static fn (string $w): bool => str_contains($w, 'invoices.customer_id'),
        );
        self::assertCount(1, $matched);
    }

    public function testStructureOnlyParentWarnsAndDeletesChildren(): void
    {
        $target = $this->connect();
        $this->recreateDatabase($target, self::DB);
        $target->useDatabase(self::DB);

        // Planned STRUCTURE_ONLY: created empty on the target, e.g. because
        // it lacks a single-column integer primary key.
        $target->exec('CREATE TABLE `widgets` (`widget_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`widget_id`)) ENGINE=InnoDB');
        $target->exec('CREATE TABLE `widget_logs` (`log_id` INT UNSIGNED NOT NULL, `widget_id` INT UNSIGNED NULL, PRIMARY KEY (`log_id`)) ENGINE=InnoDB');

        // No rows in widgets. Two logs reference widgets that were never
        // copied; one log has a NULL foreign key and must survive.
        $target->exec('INSERT INTO `widget_logs` VALUES (1, 5), (2, 6), (3, NULL)');

        $edges = [new ForeignKey('widget_logs', 'widget_id', 'widgets', 'widget_id', true)];
        $plans = [
            'widgets' => new TablePlan('widgets', TablePlan::STRUCTURE_ONLY, null, 0, '', false, false, 't'),
            'widget_logs' => new TablePlan('widget_logs', TablePlan::SUBNET, 6, 0, '', false, false, 't'),
        ];

        $pruner = new Pruner($target);
        $pruner->prune(self::DB, $edges, $plans, ['widgets', 'widget_logs']);

        $ids = array_map(
            static fn (array $r): int => (int) $r['log_id'],
            $target->fetchAll('SELECT `log_id` FROM `widget_logs` ORDER BY `log_id`'),
        );

        self::assertSame([3], $ids);

        $warnings = $pruner->warnings();
        self::assertNotEmpty($warnings);

        $matched = array_filter(
            $warnings,
            static fn (string $w): bool => str_contains($w, 'widget_logs') && str_contains($w, 'widgets'),
        );
        self::assertNotEmpty($matched);
    }
}
