<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Schema;

use DouglasGreen\DbTools\Schema\ColumnInfo;
use DouglasGreen\DbTools\Schema\DatabaseSchema;
use DouglasGreen\DbTools\Schema\ForeignKey;
use DouglasGreen\DbTools\Schema\RelationshipGraph;
use DouglasGreen\DbTools\Schema\TableInfo;
use PHPUnit\Framework\TestCase;

final class RelationshipGraphTest extends TestCase
{
    public function testInfersEdgeFromMatchingNameAndIntegerType(): void
    {
        $graph = RelationshipGraph::build($this->shop());

        $edges = $graph->edgesForChild('orders');

        self::assertCount(1, $edges);
        self::assertSame('customer_id', $edges[0]->childColumn);
        self::assertSame('customers', $edges[0]->parentTable);
        self::assertSame('customer_id', $edges[0]->parentColumn);
        self::assertFalse($edges[0]->isDeclared);
    }

    public function testDoesNotInferWhenIntegerSizeDiffers(): void
    {
        $schema = $this->schema([
            $this->table('customers', ['customer_id' => 'int(10) unsigned'], ['customer_id']),
            $this->table('orders', [
                'order_id' => 'int(10) unsigned',
                'customer_id' => 'bigint(20) unsigned',
            ], ['order_id']),
        ]);

        self::assertSame([], RelationshipGraph::build($schema)->edgesForChild('orders'));
    }

    public function testDoesNotInferWhenSignednessDiffers(): void
    {
        $schema = $this->schema([
            $this->table('customers', ['customer_id' => 'int(10) unsigned'], ['customer_id']),
            $this->table('orders', [
                'order_id' => 'int(10) unsigned',
                'customer_id' => 'int(11)',
            ], ['order_id']),
        ]);

        self::assertSame([], RelationshipGraph::build($schema)->edgesForChild('orders'));
    }

    public function testAmbiguousInferenceWarnsAndProducesNoEdge(): void
    {
        $schema = $this->schema([
            $this->table('customers', ['customer_id' => 'int(10) unsigned'], ['customer_id']),
            $this->table('clients', ['customer_id' => 'int(10) unsigned'], ['customer_id']),
            $this->table('orders', [
                'order_id' => 'int(10) unsigned',
                'customer_id' => 'int(10) unsigned',
            ], ['order_id']),
        ]);

        $graph = RelationshipGraph::build($schema);

        self::assertSame([], $graph->edgesForChild('orders'));
        self::assertStringContainsString('ambiguous', implode("\n", $graph->warnings()));
        self::assertStringContainsString('clients', implode("\n", $graph->warnings()));
    }

    public function testDoesNotInferBackwardsFromOwnPrimaryKey(): void
    {
        $schema = $this->schema([
            $this->table('customers', ['customer_id' => 'int(10) unsigned'], ['customer_id']),
            $this->table('customer_profiles', ['customer_id' => 'int(10) unsigned'], ['customer_id']),
        ]);

        $graph = RelationshipGraph::build($schema);

        self::assertSame([], $graph->edgesForChild('customers'));
        self::assertSame([], $graph->edgesForChild('customer_profiles'));
    }

    public function testDeclaredKeyOverridesInferenceOnSameColumn(): void
    {
        $schema = new DatabaseSchema('shop', $this->shop()->tables, [
            new ForeignKey('orders', 'customer_id', 'people', 'customer_id', true),
        ]);

        $edges = RelationshipGraph::build($schema)->edgesForChild('orders');

        self::assertCount(1, $edges);
        self::assertSame('people', $edges[0]->parentTable);
        self::assertTrue($edges[0]->isDeclared);
    }

    public function testDetectsDeclaredSelfReference(): void
    {
        $schema = new DatabaseSchema('shop', [
            'categories' => $this->table('categories', [
                'category_id' => 'int(10) unsigned',
                'parent_id' => 'int(10) unsigned',
            ], ['category_id']),
        ], [
            new ForeignKey('categories', 'parent_id', 'categories', 'category_id', true),
        ]);

        $graph = RelationshipGraph::build($schema);

        self::assertTrue($graph->hasSelfReference('categories'));
        self::assertFalse($graph->isInCycle('categories'));
    }

    public function testTopologicalOrderPutsParentsFirst(): void
    {
        $order = RelationshipGraph::build($this->shop())->topologicalOrder();

        self::assertSame(
            ['customers', 'orders', 'order_items'],
            array_values(array_intersect($order, ['customers', 'orders', 'order_items'])),
        );
    }

    public function testMutualCycleIsReportedAndStillOrdered(): void
    {
        $schema = new DatabaseSchema('shop', [
            'a' => $this->table('a', ['a_id' => 'int(10) unsigned', 'b_id' => 'int(10) unsigned'], ['a_id']),
            'b' => $this->table('b', ['b_id' => 'int(10) unsigned', 'a_id' => 'int(10) unsigned'], ['b_id']),
        ], []);

        $graph = RelationshipGraph::build($schema);

        self::assertTrue($graph->isInCycle('a'));
        self::assertTrue($graph->isInCycle('b'));
        self::assertCount(2, $graph->topologicalOrder());
        self::assertStringContainsString('cycle', implode("\n", $graph->warnings()));
    }

    private function shop(): DatabaseSchema
    {
        return $this->schema([
            $this->table('customers', [
                'customer_id' => 'int(10) unsigned',
                'name' => 'varchar(100)',
            ], ['customer_id']),
            $this->table('orders', [
                'order_id' => 'int(10) unsigned',
                'customer_id' => 'int(10) unsigned',
            ], ['order_id']),
            $this->table('order_items', [
                'order_item_id' => 'int(10) unsigned',
                'order_id' => 'int(10) unsigned',
            ], ['order_item_id']),
        ]);
    }

    /**
     * @param list<TableInfo> $tables
     */
    private function schema(array $tables): DatabaseSchema
    {
        $keyed = [];
        foreach ($tables as $table) {
            $keyed[$table->name] = $table;
        }

        return new DatabaseSchema('shop', $keyed, []);
    }

    /**
     * @param array<string, string> $columns    column name => COLUMN_TYPE
     * @param list<string>          $primaryKey
     */
    private function table(string $name, array $columns, array $primaryKey, int $bytes = 1024): TableInfo
    {
        $infos = [];
        foreach ($columns as $column => $columnType) {
            $dataType = strtolower((string) strtok($columnType, '( '));
            $infos[$column] = new ColumnInfo($column, $dataType, $columnType, false);
        }

        return new TableInfo('shop', $name, $bytes, 10, $infos, $primaryKey);
    }
}
