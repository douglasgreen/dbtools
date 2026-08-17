<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Plan;

use DouglasGreen\DbTools\Config\SyncOptions;
use DouglasGreen\DbTools\Plan\SyncPlanner;
use DouglasGreen\DbTools\Plan\TablePlan;
use DouglasGreen\DbTools\Schema\ColumnInfo;
use DouglasGreen\DbTools\Schema\DatabaseSchema;
use DouglasGreen\DbTools\Schema\ForeignKey;
use DouglasGreen\DbTools\Schema\RelationshipGraph;
use DouglasGreen\DbTools\Schema\TableInfo;
use PHPUnit\Framework\TestCase;

final class SyncPlannerTest extends TestCase
{
    private const MB = 1048576;

    public function testSmallTableIsCopiedWhole(): void
    {
        $plans = $this->planOf($this->threeLevelShop());

        self::assertSame(TablePlan::FULL, $plans['countries']->class);
        self::assertSame('', $plans['countries']->whereClause);
        self::assertNull($plans['countries']->modulus);
        self::assertTrue($plans['countries']->isComplete);
    }

    public function testTableExactlyAtThresholdIsCopiedWhole(): void
    {
        $schema = $this->schema([
            $this->table('logs', ['log_id' => 'int(10) unsigned'], ['log_id'], 10 * self::MB),
        ], []);

        self::assertSame(TablePlan::FULL, $this->planOf($schema)['logs']->class);
    }

    public function testModulusIsDerivedFromSize(): void
    {
        $plans = $this->planOf($this->threeLevelShop());

        self::assertSame(TablePlan::SUBNET, $plans['customers']->class);
        self::assertSame(3, $plans['customers']->modulus);
        self::assertSame('(`customer_id` % 3) = 0', $plans['customers']->whereClause);
        self::assertTrue($plans['customers']->pureModulus);
        self::assertFalse($plans['customers']->isComplete);
    }

    public function testPushdownAppliesToPureParentOnly(): void
    {
        $plans = $this->planOf($this->threeLevelShop());

        self::assertSame(
            '(`order_id` % 12) = 0 AND (`customer_id` % 3) = 0',
            $plans['orders']->whereClause,
        );
        self::assertFalse($plans['orders']->pureModulus);

        // orders is impure, so order_items inherits nothing — this is the
        // three-level case that pushdown alone cannot solve. Task 9 prunes it.
        self::assertSame('(`order_item_id` % 20) = 0', $plans['order_items']->whereClause);
        self::assertTrue($plans['order_items']->pureModulus);
        self::assertFalse($plans['order_items']->isComplete);
    }

    public function testNullableForeignKeyIsAllowedThroughPushdown(): void
    {
        $schema = $this->schema([
            $this->table('customers', ['customer_id' => 'int(10) unsigned'], ['customer_id'], 30 * self::MB),
            $this->table('orders', [
                'order_id' => 'int(10) unsigned',
                'customer_id' => 'int(10) unsigned',
            ], ['order_id'], 120 * self::MB, ['customer_id']),
        ], []);

        self::assertSame(
            '(`order_id` % 12) = 0 AND (`customer_id` IS NULL OR (`customer_id` % 3) = 0)',
            $this->planOf($schema)['orders']->whereClause,
        );
    }

    public function testLargeTableWithoutSingleIntegerKeyIsStructureOnly(): void
    {
        $schema = $this->schema([
            $this->table('events', [
                'host' => 'varchar(64)',
                'occurred_at' => 'datetime',
            ], ['host', 'occurred_at'], 500 * self::MB),
        ], []);

        $planner = new SyncPlanner($this->options());
        $plans = $planner->plan($schema, RelationshipGraph::build($schema));

        self::assertSame(TablePlan::STRUCTURE_ONLY, $plans['events']->class);
        self::assertFalse($plans['events']->copiesRows());
        self::assertFalse($plans['events']->isComplete);
        self::assertStringContainsString('events', implode("\n", $planner->warnings()));
    }

    public function testSelfReferencingTableIsCopiedWholeDespiteSize(): void
    {
        $schema = new DatabaseSchema('shop', $this->keyed([
            $this->table('categories', [
                'category_id' => 'int(10) unsigned',
                'parent_id' => 'int(10) unsigned',
            ], ['category_id'], 800 * self::MB, ['parent_id']),
        ]), [
            new ForeignKey('categories', 'parent_id', 'categories', 'category_id', true),
        ]);

        $plan = $this->planOf($schema)['categories'];

        self::assertSame(TablePlan::FULL, $plan->class);
        self::assertSame('', $plan->whereClause);
        self::assertTrue($plan->isComplete);
        self::assertStringContainsString('self-referencing', $plan->reason);
    }

    public function testForcedFullTableBypassesSubnetting(): void
    {
        $schema = $this->schema([
            $this->table('big', ['big_id' => 'int(10) unsigned'], ['big_id'], 900 * self::MB),
        ], []);

        $options = new SyncOptions(10 * self::MB, 0, 1000, [], ['shop.big']);
        $plans = (new SyncPlanner($options))->plan($schema, RelationshipGraph::build($schema));

        self::assertSame(TablePlan::FULL, $plans['big']->class);
        self::assertStringContainsString('config', $plans['big']->reason);
    }

    public function testCompletenessIsNotInheritedThroughAnIncompleteParent(): void
    {
        // notes is small enough to copy whole, but its parent is subnetted, so
        // pruning will remove rows from it and it is not complete either.
        $schema = $this->schema([
            $this->table('customers', ['customer_id' => 'int(10) unsigned'], ['customer_id'], 30 * self::MB),
            $this->table('notes', [
                'note_id' => 'int(10) unsigned',
                'customer_id' => 'int(10) unsigned',
            ], ['note_id'], 1 * self::MB),
        ], []);

        $plans = $this->planOf($schema);

        self::assertSame(TablePlan::FULL, $plans['notes']->class);
        self::assertFalse($plans['notes']->isComplete);
    }

    public function testRemainderIsHonoured(): void
    {
        $schema = $this->schema([
            $this->table('customers', ['customer_id' => 'int(10) unsigned'], ['customer_id'], 30 * self::MB),
        ], []);

        $options = new SyncOptions(10 * self::MB, 2, 1000, [], []);
        $plans = (new SyncPlanner($options))->plan($schema, RelationshipGraph::build($schema));

        self::assertSame('(`customer_id` % 3) = 2', $plans['customers']->whereClause);
    }

    public function testCycleMemberGetsNoPushdown(): void
    {
        $schema = $this->schema([
            $this->table('a', [
                'a_id' => 'int(10) unsigned',
                'b_id' => 'int(10) unsigned',
            ], ['a_id'], 50 * self::MB),
            $this->table('b', [
                'b_id' => 'int(10) unsigned',
                'a_id' => 'int(10) unsigned',
            ], ['b_id'], 50 * self::MB),
        ], []);

        $plans = $this->planOf($schema);

        self::assertSame('(`a_id` % 5) = 0', $plans['a']->whereClause);
        self::assertFalse($plans['a']->pureModulus);
        self::assertFalse($plans['a']->isComplete);
    }

    public function testPushdownIsSkippedWhenForeignKeyDoesNotReferenceParentPrimaryKey(): void
    {
        // customers' primary key is customer_id, but the declared foreign key
        // points at customer_ref, a different column. Pushdown must not apply
        // the parent's customer_id-based modulus to the unrelated customer_ref
        // column.
        $schema = $this->schema([
            $this->table('customers', ['customer_id' => 'int(10) unsigned'], ['customer_id'], 30 * self::MB),
            $this->table('orders', [
                'order_id' => 'int(10) unsigned',
                'customer_ref' => 'int(10) unsigned',
            ], ['order_id'], 120 * self::MB),
        ], [
            new ForeignKey('orders', 'customer_ref', 'customers', 'customer_ref', true),
        ]);

        $plans = $this->planOf($schema);

        self::assertSame('(`order_id` % 12) = 0', $plans['orders']->whereClause);
    }

    public function testRemainderIsClampedToModulus(): void
    {
        $schema = $this->schema([
            $this->table('logs', ['log_id' => 'int(10) unsigned'], ['log_id'], 15 * self::MB),
        ], []);

        $options = new SyncOptions(10 * self::MB, 5, 1000, [], []);
        $plans = (new SyncPlanner($options))->plan($schema, RelationshipGraph::build($schema));

        self::assertSame('(`log_id` % 2) = 1', $plans['logs']->whereClause);
    }

    private function options(): SyncOptions
    {
        return new SyncOptions(10 * self::MB, 0, 1000, [], []);
    }

    /**
     * @return array<string, TablePlan>
     */
    private function planOf(DatabaseSchema $schema): array
    {
        return (new SyncPlanner($this->options()))->plan($schema, RelationshipGraph::build($schema));
    }

    private function threeLevelShop(): DatabaseSchema
    {
        return $this->schema([
            $this->table('countries', ['country_id' => 'int(10) unsigned'], ['country_id'], 64 * 1024),
            $this->table('customers', ['customer_id' => 'int(10) unsigned'], ['customer_id'], 30 * self::MB),
            $this->table('orders', [
                'order_id' => 'int(10) unsigned',
                'customer_id' => 'int(10) unsigned',
            ], ['order_id'], 120 * self::MB),
            $this->table('order_items', [
                'order_item_id' => 'int(10) unsigned',
                'order_id' => 'int(10) unsigned',
            ], ['order_item_id'], 200 * self::MB),
        ], []);
    }

    /**
     * @param list<TableInfo>  $tables
     * @param list<ForeignKey> $declared
     */
    private function schema(array $tables, array $declared): DatabaseSchema
    {
        return new DatabaseSchema('shop', $this->keyed($tables), $declared);
    }

    /**
     * @param list<TableInfo> $tables
     *
     * @return array<string, TableInfo>
     */
    private function keyed(array $tables): array
    {
        $keyed = [];
        foreach ($tables as $table) {
            $keyed[$table->name] = $table;
        }

        return $keyed;
    }

    /**
     * @param array<string, string> $columns  column name => COLUMN_TYPE
     * @param list<string>          $primaryKey
     * @param list<string>          $nullable
     */
    private function table(
        string $name,
        array $columns,
        array $primaryKey,
        int $bytes,
        array $nullable = [],
    ): TableInfo {
        $infos = [];
        foreach ($columns as $column => $columnType) {
            $dataType = strtolower((string) strtok($columnType, '( '));
            $infos[$column] = new ColumnInfo(
                $column,
                $dataType,
                $columnType,
                in_array($column, $nullable, true),
            );
        }

        return new TableInfo('shop', $name, $bytes, 100, $infos, $primaryKey);
    }
}
