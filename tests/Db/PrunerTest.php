<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Db;

use DouglasGreen\DbTools\Db\Pruner;
use DouglasGreen\DbTools\Plan\TablePlan;
use DouglasGreen\DbTools\Schema\ForeignKey;
use PHPUnit\Framework\TestCase;

final class PrunerTest extends TestCase
{
    public function testEdgeToCompleteParentIsNotPruned(): void
    {
        $edges = [new ForeignKey('orders', 'country_id', 'countries', 'country_id', false)];
        $plans = [
            'countries' => $this->plan('countries', TablePlan::FULL, true),
            'orders' => $this->plan('orders', TablePlan::SUBNET, false),
        ];

        self::assertSame([], Pruner::prunableEdges($edges, $plans, ['countries', 'orders']));
    }

    public function testEdgeToIncompleteParentIsPruned(): void
    {
        $edges = [new ForeignKey('orders', 'customer_id', 'customers', 'customer_id', false)];
        $plans = [
            'customers' => $this->plan('customers', TablePlan::SUBNET, false),
            'orders' => $this->plan('orders', TablePlan::SUBNET, false),
        ];

        self::assertCount(1, Pruner::prunableEdges($edges, $plans, ['customers', 'orders']));
    }

    public function testSelfReferenceIsNeverPruned(): void
    {
        $edges = [new ForeignKey('categories', 'parent_id', 'categories', 'category_id', true)];
        $plans = ['categories' => $this->plan('categories', TablePlan::FULL, false)];

        self::assertSame([], Pruner::prunableEdges($edges, $plans, ['categories']));
    }

    public function testStructureOnlyChildIsNotPruned(): void
    {
        $edges = [new ForeignKey('logs', 'customer_id', 'customers', 'customer_id', false)];
        $plans = [
            'customers' => $this->plan('customers', TablePlan::SUBNET, false),
            'logs' => $this->plan('logs', TablePlan::STRUCTURE_ONLY, false),
        ];

        self::assertSame([], Pruner::prunableEdges($edges, $plans, ['customers', 'logs']));
    }

    public function testEdgesAreOrderedByChildTopologicalPosition(): void
    {
        $edges = [
            new ForeignKey('order_items', 'order_id', 'orders', 'order_id', false),
            new ForeignKey('orders', 'customer_id', 'customers', 'customer_id', false),
        ];
        $plans = [
            'customers' => $this->plan('customers', TablePlan::SUBNET, false),
            'orders' => $this->plan('orders', TablePlan::SUBNET, false),
            'order_items' => $this->plan('order_items', TablePlan::SUBNET, false),
        ];

        $ordered = Pruner::prunableEdges($edges, $plans, ['customers', 'orders', 'order_items']);

        self::assertSame(['orders', 'order_items'], array_map(
            static fn (ForeignKey $e): string => $e->childTable,
            $ordered,
        ));
    }

    private function plan(string $table, string $class, bool $isComplete): TablePlan
    {
        return new TablePlan($table, $class, null, 0, '', false, $isComplete, 'test');
    }
}
