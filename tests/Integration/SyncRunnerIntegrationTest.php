<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Integration;

use DouglasGreen\DbTools\Cli\Arguments;
use DouglasGreen\DbTools\Cli\ExitCode;
use DouglasGreen\DbTools\Config\Config;
use DouglasGreen\DbTools\Db\Connection;
use DouglasGreen\DbTools\Exception\ConfigException;
use DouglasGreen\DbTools\Report\Report;
use DouglasGreen\DbTools\SyncRunner;
use Throwable;

final class SyncRunnerIntegrationTest extends IntegrationTestCase
{
    private const DB = 'dbsync_e2e_test';

    /**
     * Large enough that, with the 250-byte pad shared with customers, the
     * self-reference exemption in SyncPlanner::classify() is the only reason
     * categories stays FULL — at 200 rows (the original fixture) it is ~114
     * KB, comfortably under the threshold on the size rule alone, so the
     * exemption assertion would pass even if the exemption were deleted.
     */
    private const CATEGORY_COUNT = 4000;

    /**
     * Both drops are attempted even if one fails, so a source-server outage
     * at teardown time cannot leave dbsync_e2e_test behind on the target (or
     * vice versa) — these servers hold roughly 50 other people's databases.
     * The target is dropped first per review guidance, but the ordering is
     * secondary to the guarantee that both attempts run regardless.
     *
     * The source drop is gated on DBSYNC_TEST_DSN ALONE, not on both DSNs.
     * seedSource() creates the database on the source before either test
     * reaches the DBSYNC_TEST_TARGET_DSN skip inside targetConnectionConfig(),
     * so a run with only the source DSN set still writes dbsync_e2e_test to a
     * shared server — and a teardown that returned early on the missing target
     * DSN would leave it there permanently.
     */
    protected function tearDown(): void
    {
        if (getenv('DBSYNC_TEST_DSN') === false) {
            return;
        }

        $connectors = [];

        if (getenv('DBSYNC_TEST_TARGET_DSN') !== false) {
            $connectors[] = fn (): Connection => $this->connectTarget();
        }

        $connectors[] = fn (): Connection => $this->connect();

        $failure = null;

        foreach ($connectors as $connect) {
            try {
                $this->dropDatabase($connect(), self::DB);
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function testEndToEndCopyLeavesNoOrphans(): void
    {
        $this->seedSource();

        [$output, $code] = $this->runSync(['--drop', '--threshold=1']);

        self::assertContains($code, [ExitCode::SUCCESS, ExitCode::COMPLETED_WITH_WARNINGS], $output);
        self::assertStringContainsString('COPY COMPLETE', $output);

        $target = $this->connectTarget(self::DB);

        self::assertSame(0, $this->orphanCount($target, 'orders', 'customer_id', 'customers', 'customer_id'));
        self::assertSame(0, $this->orphanCount($target, 'order_items', 'order_id', 'orders', 'order_id'));

        // Small tables are copied whole.
        self::assertSame(
            5,
            (int) $target->fetchAll('SELECT COUNT(*) AS c FROM `countries`')[0]['c'],
        );

        // The hierarchy table is self-referencing, so it is exempt from
        // subnetting: every row is copied whole (not merely internally
        // consistent), and every parent_id still resolves.
        self::assertSame(
            self::CATEGORY_COUNT,
            (int) $target->fetchAll('SELECT COUNT(*) AS c FROM `categories`')[0]['c'],
        );
        self::assertSame(
            0,
            $this->orphanCount($target, 'categories', 'parent_id', 'categories', 'category_id'),
        );

        // Something was actually left behind, and something was actually cut.
        $orders = (int) $target->fetchAll('SELECT COUNT(*) AS c FROM `orders`')[0]['c'];
        self::assertGreaterThan(0, $orders);
        self::assertLessThan(4000, $orders);

        // Precondition for the prune assertions below. The orders-pruned-0
        // line is the only live coverage predicate pushdown has anywhere in
        // the suite, and it only means anything while customers is genuinely
        // subnetted: if InnoDB's size estimate ever drifts under the
        // threshold, customers is classified FULL, every order's customer
        // survives trivially, and orders prunes 0 for a reason that has
        // nothing to do with pushdown. Pinning the class here makes that drift
        // fail on the fact that caused it rather than on a downstream count.
        self::assertSame(
            1,
            preg_match('/^dbsync_e2e_test\s+customers\s+SUBNET\s+([\d,]+)\s/m', $output, $matches),
            $output,
        );
        $customersCopied = (int) str_replace(',', '', $matches[1]);
        self::assertGreaterThan(0, $customersCopied);
        self::assertLessThan(4000, $customersCopied);

        // Pruning genuinely did work, and specifically at the depth that
        // predicate pushdown cannot reach: pruning zero rows from orders
        // (pushdown already excluded any order whose customer was dropped)
        // but a strictly positive number from order_items (whose own subnet
        // predicate has no relationship to orders' surviving set) proves both
        // stages are independently load-bearing, without depending on the
        // exact subnet modulus InnoDB's size estimate happens to produce.
        self::assertMatchesRegularExpression('/Rows pruned:\s+[1-9]/', $output);
        self::assertMatchesRegularExpression(
            '/^dbsync_e2e_test\s+orders\s+\S+\s+[\d,]+\s+0\s/m',
            $output,
        );
        self::assertMatchesRegularExpression(
            '/^dbsync_e2e_test\s+order_items\s+\S+\s+[\d,]+\s+[1-9][\d,]*\s/m',
            $output,
        );
    }

    public function testDryRunWritesNothing(): void
    {
        $this->seedSource();
        $this->dropDatabase($this->connectTarget(), self::DB);

        [$output, $code] = $this->runSync(['--dry-run', '--threshold=1']);

        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertStringContainsString('DRY RUN', $output);
        self::assertStringContainsString('SUBNET', $output);
        self::assertStringNotContainsString('COPY COMPLETE', $output);

        // The target database must still not exist.
        $found = $this->connectTarget()->fetchAll(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [self::DB],
        );
        self::assertSame([], $found);
    }

    /**
     * Task 12's same-server guard is the most consequential line in
     * SyncRunner: without it, two connections that resolve to the same host
     * and port would have dbsync overwrite the very source database it is
     * reading from, and with --drop destroy it before reading a row. Task
     * 10's name-inequality check happily accepts this input because "src"
     * and "dst" are different connection names, so only the host/port guard
     * in SyncRunner::run() can catch it. This test needs no database
     * connection at all: the guard runs before SyncRunner opens one.
     *
     * No --database is passed, so if the guard were ever removed this call
     * would be the one place in the suite where run() enumerates every
     * database on the target host and recreates each one. The host below is
     * deliberately unresolvable (RFC 2606 .invalid) rather than 127.0.0.1,
     * so that guarantee holds on every machine — not only on ones that
     * happen to have no MySQL listening locally. If the guard is removed,
     * this fails with ConnectionException on any machine, without ever
     * reaching a database.
     */
    public function testRefusesSameHostAndPortUnderDifferentConnectionNames(): void
    {
        $config = Config::fromArray([
            'sync' => [],
            'src' => [
                'host' => 'dbsync-same-server.invalid',
                'user' => 'root',
                'password' => '',
                'port' => '3306',
            ],
            'dst' => [
                'host' => 'dbsync-same-server.invalid',
                'user' => 'root',
                'password' => '',
                'port' => '3306',
            ],
        ]);

        $arguments = Arguments::parse(['dbsync.php', '--source=src', '--target=dst']);

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $this->expectException(ConfigException::class);

        try {
            (new SyncRunner($config, $arguments, new Report(microtime(true)), $stream))->run();
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param list<string> $extraArguments
     *
     * @return array{string, int}
     */
    private function runSync(array $extraArguments): array
    {
        $source = $this->connectionConfig();
        $target = $this->targetConnectionConfig();

        $parsed = [
            'sync' => ['batch_size' => '250'],
            'src' => [
                'host' => $source->host,
                'user' => $source->user,
                'password' => $source->password,
                'port' => (string) $source->port,
            ],
            'dst' => [
                'host' => $target->host,
                'user' => $target->user,
                'password' => $target->password,
                'port' => (string) $target->port,
            ],
        ];

        $arguments = Arguments::parse(array_merge(
            ['dbsync.php', '--source=src', '--target=dst', '--database=' . self::DB],
            $extraArguments,
        ));

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $code = (new SyncRunner(
            Config::fromArray($parsed),
            $arguments,
            new Report(microtime(true)),
            $stream,
        ))->run();

        rewind($stream);
        $output = (string) stream_get_contents($stream);
        fclose($stream);

        return [$output, $code];
    }

    private function orphanCount(
        Connection $connection,
        string $child,
        string $childColumn,
        string $parent,
        string $parentColumn,
    ): int {
        $rows = $connection->fetchAll(sprintf(
            'SELECT COUNT(*) AS c FROM `%s` c LEFT JOIN `%s` p ON c.`%s` = p.`%s` '
                . 'WHERE c.`%s` IS NOT NULL AND p.`%s` IS NULL',
            $child,
            $parent,
            $childColumn,
            $parentColumn,
            $childColumn,
            $parentColumn,
        ));

        return (int) $rows[0]['c'];
    }

    /**
     * Rows are padded so InnoDB reports well over the 1 MB test threshold for
     * customers, orders, order_items, and categories, while countries stays
     * under it.
     *
     * order_items uses a wider pad than customers/orders on purpose: at a
     * shared 250-byte pad, all three tables land at the same modulus (2), and
     * because order_item_id and order_id are the same loop counter, orders'
     * own subnet predicate would coincide exactly with order_items'
     * independent selection, masking the exact regression this test exists
     * to catch. Giving order_items modulus 3 (coprime with orders'/
     * customers' modulus 2) breaks that coincidence: roughly half of the
     * order_items rows that survive their own predicate end up referencing
     * an order_id that did NOT survive on orders, so genuine orphans exist
     * and only Pruner removes them. See testEndToEndCopyLeavesNoOrphans()
     * for why this is also verified directly via the prune counts, since a
     * future band drift in the InnoDB size estimate could silently restore
     * an even modulus and the coincidence with it.
     */
    private function seedSource(): void
    {
        $connection = $this->connect();
        $this->recreateDatabase($connection, self::DB);
        $connection->useDatabase(self::DB);

        $connection->exec(
            'CREATE TABLE `countries` (`country_id` INT UNSIGNED NOT NULL,'
                . '`name` VARCHAR(80) NOT NULL, PRIMARY KEY (`country_id`)) ENGINE=InnoDB',
        );
        $connection->exec(
            'CREATE TABLE `categories` (`category_id` INT UNSIGNED NOT NULL,'
                . '`parent_id` INT UNSIGNED NULL, `pad` VARCHAR(255) NOT NULL,'
                . 'PRIMARY KEY (`category_id`), KEY `parent_id` (`parent_id`),'
                . 'CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) '
                . 'REFERENCES `categories` (`category_id`)) ENGINE=InnoDB',
        );
        $connection->exec(
            'CREATE TABLE `customers` (`customer_id` INT UNSIGNED NOT NULL,'
                . '`pad` VARCHAR(255) NOT NULL, PRIMARY KEY (`customer_id`)) ENGINE=InnoDB',
        );
        $connection->exec(
            'CREATE TABLE `orders` (`order_id` INT UNSIGNED NOT NULL,'
                . '`customer_id` INT UNSIGNED NOT NULL, `pad` VARCHAR(255) NOT NULL,'
                . 'PRIMARY KEY (`order_id`), KEY `customer_id` (`customer_id`)) ENGINE=InnoDB',
        );
        $connection->exec(
            'CREATE TABLE `order_items` (`order_item_id` INT UNSIGNED NOT NULL,'
                . '`order_id` INT UNSIGNED NOT NULL, `pad` VARCHAR(400) NOT NULL,'
                . 'PRIMARY KEY (`order_item_id`), KEY `order_id` (`order_id`)) ENGINE=InnoDB',
        );

        $pad = str_repeat('x', 250);
        $wideOrderItemsPad = str_repeat('x', 400);

        $insert = $connection->pdo()->prepare('INSERT INTO `countries` VALUES (?, ?)');
        for ($id = 1; $id <= 5; ++$id) {
            $insert->execute([$id, 'Country ' . $id]);
        }

        $insert = $connection->pdo()->prepare('INSERT INTO `categories` VALUES (?, ?, ?)');
        $insert->execute([1, null, $pad]);
        for ($id = 2; $id <= self::CATEGORY_COUNT; ++$id) {
            $insert->execute([$id, intdiv($id, 2), $pad]);
        }

        $insert = $connection->pdo()->prepare('INSERT INTO `customers` VALUES (?, ?)');
        for ($id = 1; $id <= 4000; ++$id) {
            $insert->execute([$id, $pad]);
        }

        // customer_id is deliberately NOT $id: with customer_id == order_id,
        // orders' own subnet predicate (order_id % 2 = 0) would ALGEBRAICALLY
        // imply the pushed-down customers predicate (customer_id % 2 = 0),
        // since they'd be testing the same value twice. That makes every
        // kept order's customer survive by identity, with pushdown AND
        // pruning both deleted, so predicate pushdown would have zero live
        // coverage in this suite. intdiv($id, 2) + 1 keeps referenced
        // customers in range 1..2001 with a parity independent of order_id's,
        // so roughly half of the even (kept) order_ids point at an odd
        // (dropped) customer — a real test of the pushdown term.
        $insert = $connection->pdo()->prepare('INSERT INTO `orders` VALUES (?, ?, ?)');
        for ($id = 1; $id <= 4000; ++$id) {
            $insert->execute([$id, intdiv($id, 2) + 1, $pad]);
        }

        $insert = $connection->pdo()->prepare('INSERT INTO `order_items` VALUES (?, ?, ?)');
        for ($id = 1; $id <= 4000; ++$id) {
            $insert->execute([$id, $id, $wideOrderItemsPad]);
        }

        $connection->exec('ANALYZE TABLE `customers`, `orders`, `order_items`, `categories`, `countries`');
    }
}
