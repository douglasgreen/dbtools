<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Plan;

use DouglasGreen\DbTools\Config\SyncOptions;
use DouglasGreen\DbTools\Schema\DatabaseSchema;
use DouglasGreen\DbTools\Schema\RelationshipGraph;
use DouglasGreen\DbTools\Schema\TableInfo;
use DouglasGreen\DbTools\Sql;

final class SyncPlanner
{
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly SyncOptions $options)
    {
    }

    /**
     * @return array<string, TablePlan>
     */
    public function plan(DatabaseSchema $schema, RelationshipGraph $graph): array
    {
        $this->warnings = [];

        $classes = [];
        foreach ($schema->tables as $name => $table) {
            $classes[$name] = $this->classify($schema->name, $table, $graph);
        }

        $plans = [];

        foreach ($graph->topologicalOrder() as $name) {
            $table = $schema->table($name);
            if ($table === null) {
                continue;
            }

            [$class, $modulus, $reason] = $classes[$name];
            $inCycle = $graph->isInCycle($name);

            $terms = [];
            $remainder = $this->options->subnetRemainder;
            if ($class === TablePlan::SUBNET) {
                $key = $table->singleIntegerPrimaryKey();
                $remainder = self::clampRemainder($remainder, (int) $modulus);
                // classify() only returns SUBNET when this key exists.
                $terms[] = sprintf(
                    '(%s %% %d) = %d',
                    Sql::quoteIdentifier((string) $key?->name),
                    (int) $modulus,
                    $remainder,
                );
            }

            $pushed = 0;
            $complete = $class === TablePlan::FULL && ! $inCycle;

            foreach ($graph->edgesForChild($name) as $edge) {
                if ($edge->isSelfReference()) {
                    continue;
                }

                $parentPlan = $plans[$edge->parentTable] ?? null;
                if ($parentPlan === null) {
                    // Parent is outside this database, or ordered after us
                    // because of a cycle. Assume nothing about it.
                    $complete = false;
                    continue;
                }

                if (! $parentPlan->isComplete) {
                    $complete = false;
                }

                if ($class !== TablePlan::SUBNET || $inCycle) {
                    continue;
                }

                if ($parentPlan->class !== TablePlan::SUBNET || ! $parentPlan->pureModulus) {
                    continue;
                }

                $parentTable = $schema->table($edge->parentTable);
                $parentKey = $parentTable?->singleIntegerPrimaryKey();
                if ($parentKey === null || $parentKey->name !== $edge->parentColumn) {
                    // The edge doesn't reference the parent's primary key, so
                    // the parent's modulus says nothing about this column.
                    continue;
                }

                $column = $table->column($edge->childColumn);
                if ($column === null) {
                    continue;
                }

                $quoted = Sql::quoteIdentifier($edge->childColumn);
                $test = sprintf(
                    '(%s %% %d) = %d',
                    $quoted,
                    (int) $parentPlan->modulus,
                    $parentPlan->remainder,
                );

                $terms[] = $column->isNullable
                    ? sprintf('(%s IS NULL OR %s)', $quoted, $test)
                    : $test;
                ++$pushed;
            }

            if ($class === TablePlan::FULL && $graph->hasSelfReference($name) && ! $complete) {
                $this->warnings[] = sprintf(
                    'Table %s is self-referencing and also references an incomplete table. '
                        . 'Its own hierarchy is never pruned, so some self-referencing values may dangle.',
                    $name,
                );
            }

            $plans[$name] = new TablePlan(
                $name,
                $class,
                $modulus,
                $remainder,
                implode(' AND ', $terms),
                $class === TablePlan::SUBNET && $pushed === 0 && ! $inCycle,
                $complete,
                $reason,
            );
        }

        return $plans;
    }

    /**
     * @return array{string, int|null, string}
     */
    private function classify(string $database, TableInfo $table, RelationshipGraph $graph): array
    {
        if ($this->options->isForcedFull($database, $table->name)) {
            return [TablePlan::FULL, null, 'listed in force_full_tables config'];
        }

        if ($graph->hasSelfReference($table->name)) {
            return [
                TablePlan::FULL,
                null,
                'self-referencing foreign key; subnetting would destroy the hierarchy',
            ];
        }

        if ($table->sizeBytes <= $this->options->thresholdBytes) {
            return [TablePlan::FULL, null, 'at or under threshold'];
        }

        if ($table->singleIntegerPrimaryKey() === null) {
            $this->warnings[] = sprintf(
                'Table %s.%s is %s but has no single-column integer primary key '
                    . '(primary key: %s). Structure created, no rows copied.',
                $database,
                $table->name,
                self::humanBytes($table->sizeBytes),
                $table->primaryKey === [] ? 'none' : implode(', ', $table->primaryKey),
            );

            return [TablePlan::STRUCTURE_ONLY, null, 'no single-column integer primary key'];
        }

        $modulus = (int) max(1, (int) ceil($table->sizeBytes / $this->options->thresholdBytes));

        return [TablePlan::SUBNET, $modulus, sprintf('%s over threshold', self::humanBytes($table->sizeBytes))];
    }

    /**
     * A residue class mod N is only meaningful for 0 <= R < N, so an
     * out-of-range or negative configured remainder is reduced into that
     * range rather than producing a clause that matches nothing (or every
     * row, for a negative value under naive modulo).
     */
    private static function clampRemainder(int $remainder, int $modulus): int
    {
        if ($modulus <= 0) {
            return 0;
        }

        return (($remainder % $modulus) + $modulus) % $modulus;
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024.0 && $unit < count($units) - 1) {
            $value /= 1024.0;
            ++$unit;
        }

        return sprintf($unit === 0 ? '%.0f %s' : '%.1f %s', $value, $units[$unit]);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
