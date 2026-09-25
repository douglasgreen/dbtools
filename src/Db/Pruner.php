<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Db;

use DouglasGreen\DbTools\Plan\TablePlan;
use DouglasGreen\DbTools\Schema\ForeignKey;
use DouglasGreen\DbTools\Sql;
use PDOException;

final class Pruner
{
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly Connection $target,
        private readonly int $maxSweeps = 10,
    ) {
    }

    /**
     * @param list<ForeignKey>        $edges
     * @param array<string, TablePlan> $plans
     * @param list<string>            $order topological, parents first
     *
     * @return list<ForeignKey>
     */
    public static function prunableEdges(array $edges, array $plans, array $order): array
    {
        $position = array_flip($order);
        $keep = [];

        foreach ($edges as $edge) {
            if ($edge->isSelfReference()) {
                continue;
            }

            $child = $plans[$edge->childTable] ?? null;
            $parent = $plans[$edge->parentTable] ?? null;

            if ($child === null || $parent === null) {
                continue;
            }

            if (! $child->copiesRows() || $parent->isComplete) {
                continue;
            }

            $keep[] = $edge;
        }

        usort($keep, static fn (ForeignKey $a, ForeignKey $b): int
            => ($position[$a->childTable] ?? PHP_INT_MAX) <=> ($position[$b->childTable] ?? PHP_INT_MAX));

        return $keep;
    }

    /**
     * @param list<ForeignKey>        $edges
     * @param array<string, TablePlan> $plans
     * @param list<string>            $order
     *
     * @return list<PruneResult>
     */
    public function prune(string $database, array $edges, array $plans, array $order): array
    {
        $prunable = self::prunableEdges($edges, $plans, $order);
        if ($prunable === []) {
            return [];
        }

        foreach ($prunable as $edge) {
            $parent = $plans[$edge->parentTable] ?? null;
            if ($parent !== null && $parent->class === TablePlan::STRUCTURE_ONLY) {
                $this->warnings[] = sprintf(
                    'Parent table %s was created with no rows (STRUCTURE_ONLY); '
                        . 'pruning will delete every row in %s where %s is not NULL.',
                    $edge->parentTable,
                    $edge->childTable,
                    $edge->childColumn,
                );
            }
        }

        $totals = [];
        $converged = false;

        for ($sweep = 0; $sweep < $this->maxSweeps; ++$sweep) {
            $deletedThisSweep = 0;

            foreach ($prunable as $index => $edge) {
                // One broken edge must not abort the pass: an exception here
                // escapes the run before the summary prints, hiding both this
                // error and every warning collected during the copy.
                try {
                    $deleted = $this->deleteOrphans($database, $edge);
                } catch (PDOException $e) {
                    if (Connection::isConnectionLost($e)) {
                        throw $e;
                    }

                    $this->warnings[] = sprintf(
                        'Could not prune %s.%s against %s.%s in %s; orphaned rows may remain: %s',
                        $edge->childTable,
                        $edge->childColumn,
                        $edge->parentTable,
                        $edge->parentColumn,
                        $database,
                        $e->getMessage(),
                    );
                    unset($prunable[$index]);

                    continue;
                }

                $deletedThisSweep += $deleted;

                $key = $edge->childTable . '.' . $edge->childColumn;
                $totals[$key] = ($totals[$key] ?? 0) + $deleted;
            }

            if ($deletedThisSweep === 0) {
                $converged = true;
                break;
            }
        }

        if (! $converged) {
            $this->warnings[] = sprintf(
                'Pruning did not converge after %d sweeps in %s. Orphaned rows may remain.',
                $this->maxSweeps,
                $database,
            );
        }

        $results = [];
        foreach ($prunable as $edge) {
            $key = $edge->childTable . '.' . $edge->childColumn;
            if (($totals[$key] ?? 0) > 0) {
                $results[] = new PruneResult(
                    $edge->childTable,
                    $edge->childColumn,
                    $edge->parentTable,
                    $totals[$key],
                );
                $totals[$key] = 0;
            }
        }

        return $results;
    }

    private function deleteOrphans(string $database, ForeignKey $edge): int
    {
        $child = Sql::qualify($database, $edge->childTable);
        $parent = Sql::qualify($database, $edge->parentTable);
        $childColumn = Sql::quoteIdentifier($edge->childColumn);
        $parentColumn = Sql::quoteIdentifier($edge->parentColumn);

        return $this->target->exec(sprintf(
            'DELETE c FROM %s c LEFT JOIN %s p ON c.%s = p.%s '
                . 'WHERE c.%s IS NOT NULL AND p.%s IS NULL',
            $child,
            $parent,
            $childColumn,
            $parentColumn,
            $childColumn,
            $parentColumn,
        ));
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
