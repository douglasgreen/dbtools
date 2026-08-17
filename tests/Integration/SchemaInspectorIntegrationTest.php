<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Integration;

use DouglasGreen\DbTools\Db\SchemaInspector;

final class SchemaInspectorIntegrationTest extends IntegrationTestCase
{
    private const DB = 'dbsync_inspect_test';

    protected function tearDown(): void
    {
        if (getenv('DBSYNC_TEST_DSN') !== false) {
            $this->dropDatabase($this->connect(), self::DB);
        }
    }

    public function testInspectReadsColumnsKeysAndDeclaredForeignKeys(): void
    {
        $connection = $this->connect();
        $this->recreateDatabase($connection, self::DB);
        $connection->useDatabase(self::DB);

        $connection->exec(
            'CREATE TABLE `customers` ('
                . '`customer_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`name` VARCHAR(100) NOT NULL,'
                . 'PRIMARY KEY (`customer_id`)) ENGINE=InnoDB',
        );
        $connection->exec(
            'CREATE TABLE `orders` ('
                . '`order_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`customer_id` INT UNSIGNED NULL,'
                . 'PRIMARY KEY (`order_id`),'
                . 'KEY `customer_id` (`customer_id`),'
                . 'CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) '
                . 'REFERENCES `customers` (`customer_id`)) ENGINE=InnoDB',
        );

        $schema = (new SchemaInspector($connection))->inspect(self::DB);

        self::assertSame(['customers', 'orders'], $schema->tableNames());

        $orders = $schema->table('orders');
        self::assertNotNull($orders);
        self::assertSame(['order_id'], $orders->primaryKey);
        self::assertSame('int', $orders->column('customer_id')?->dataType);
        self::assertTrue($orders->column('customer_id')?->isNullable);
        self::assertTrue($orders->column('customer_id')?->isUnsigned());
        self::assertSame('order_id', $orders->singleIntegerPrimaryKey()?->name);

        self::assertCount(1, $schema->declaredForeignKeys);
        $key = $schema->declaredForeignKeys[0];
        self::assertSame('orders', $key->childTable);
        self::assertSame('customer_id', $key->childColumn);
        self::assertSame('customers', $key->parentTable);
        self::assertSame('customer_id', $key->parentColumn);
        self::assertTrue($key->isDeclared);
    }

    public function testCompositeForeignKeyIsWarnedAboutAndDropped(): void
    {
        $connection = $this->connect();
        $this->recreateDatabase($connection, self::DB);
        $connection->useDatabase(self::DB);

        $connection->exec(
            'CREATE TABLE `parents` ('
                . '`a` INT UNSIGNED NOT NULL, `b` INT UNSIGNED NOT NULL,'
                . 'PRIMARY KEY (`a`,`b`)) ENGINE=InnoDB',
        );
        $connection->exec(
            'CREATE TABLE `kids` ('
                . '`kid_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`a` INT UNSIGNED NOT NULL, `b` INT UNSIGNED NOT NULL,'
                . 'PRIMARY KEY (`kid_id`), KEY `ab` (`a`,`b`),'
                . 'CONSTRAINT `fk_kids` FOREIGN KEY (`a`,`b`) '
                . 'REFERENCES `parents` (`a`,`b`)) ENGINE=InnoDB',
        );

        $inspector = new SchemaInspector($connection);
        $schema = $inspector->inspect(self::DB);

        self::assertSame([], $schema->declaredForeignKeys);
        self::assertStringContainsString('composite', implode("\n", $inspector->warnings()));
    }

    public function testCreateTableStatementIsReturnedVerbatim(): void
    {
        $connection = $this->connect();
        $this->recreateDatabase($connection, self::DB);
        $connection->useDatabase(self::DB);
        $connection->exec('CREATE TABLE `t` (`id` INT NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');

        $ddl = (new SchemaInspector($connection))->createTableStatement(self::DB, 't');

        self::assertStringStartsWith('CREATE TABLE', $ddl);
        self::assertStringContainsString('`id`', $ddl);
    }

    public function testSystemSchemasAreNeverListed(): void
    {
        $databases = (new SchemaInspector($this->connect()))->databases([]);

        self::assertNotContains('information_schema', $databases);
        self::assertNotContains('mysql', $databases);
        self::assertNotContains('performance_schema', $databases);
    }

    public function testColumnOrderPreservesOrdinalPositionNotAlphabetical(): void
    {
        $connection = $this->connect();
        $this->recreateDatabase($connection, self::DB);
        $connection->useDatabase(self::DB);

        $connection->exec(
            'CREATE TABLE `ordering_probe` ('
                . '`zebra_id` INT UNSIGNED NOT NULL,'
                . '`alpha` VARCHAR(10) NOT NULL,'
                . '`middle` INT NOT NULL,'
                . 'PRIMARY KEY (`zebra_id`, `middle`)) ENGINE=InnoDB',
        );

        $schema = (new SchemaInspector($connection))->inspect(self::DB);
        $table = $schema->table('ordering_probe');
        self::assertNotNull($table);

        // Ordinal order: zebra_id, alpha, middle
        // Alphabetical order: alpha, middle, zebra_id
        // Assert we get ordinal order, not alphabetical
        self::assertSame(['zebra_id', 'alpha', 'middle'], $table->columnNames());

        // Primary key ordinal order: zebra_id (pos 1), middle (pos 2)
        // Primary key alphabetical order: middle, zebra_id
        // Assert we get ordinal order, not alphabetical
        self::assertSame(['zebra_id', 'middle'], $table->primaryKey);
    }
}
