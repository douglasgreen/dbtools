<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Schema;

final class RelationshipGraph
{
    /**
     * @param list<ForeignKey>              $edges
     * @param array<string, list<ForeignKey>> $byChild
     * @param list<string>                  $order
     * @param array<string, true>           $cycleMembers
     * @param list<string>                  $warnings
     */
    private function __construct(
        private readonly array $edges,
        private readonly array $byChild,
        private readonly array $order,
        private readonly array $cycleMembers,
        private readonly array $warnings,
    ) {
    }

    public static function build(DatabaseSchema $schema): self
    {
        $warnings = [];
        $edges = [];
        $claimed = [];

        foreach ($schema->declaredForeignKeys as $declared) {
            if ($schema->table($declared->childTable) === null) {
                continue;
            }

            $edges[] = $declared;
            $claimed[$declared->childTable . '.' . $declared->childColumn] = true;
        }

        foreach (self::infer($schema, $claimed, $warnings) as $inferred) {
            $edges[] = $inferred;
        }

        $byChild = [];
        foreach ($edges as $edge) {
            $byChild[$edge->childTable][] = $edge;
        }

        [$order, $cycleMembers] = self::sort($schema->tableNames(), $edges);

        if ($cycleMembers !== []) {
            $warnings[] = sprintf(
                'Foreign key cycle detected among: %s. Pruning will iterate to a fixpoint.',
                implode(', ', array_keys($cycleMembers)),
            );
        }

        return new self($edges, $byChild, $order, $cycleMembers, $warnings);
    }

    /**
     * @param array<string, true> $claimed
     * @param list<string>        $warnings
     *
     * @return list<ForeignKey>
     */
    private static function infer(DatabaseSchema $schema, array $claimed, array &$warnings): array
    {
        $parentsByKeyName = [];
        foreach ($schema->tables as $table) {
            $key = $table->singleIntegerPrimaryKey();
            if ($key !== null) {
                $parentsByKeyName[$key->name][] = $table;
            }
        }

        $inferred = [];

        foreach ($schema->tables as $table) {
            $ownKey = $table->singleIntegerPrimaryKey();

            foreach ($table->columns as $column) {
                if (! $column->isInteger()) {
                    continue;
                }

                if (isset($claimed[$table->name . '.' . $column->name])) {
                    continue;
                }

                if ($ownKey !== null && $ownKey->name === $column->name) {
                    continue;
                }

                $candidates = [];
                foreach ($parentsByKeyName[$column->name] ?? [] as $parent) {
                    if ($parent->name === $table->name) {
                        continue;
                    }

                    $parentKey = $parent->singleIntegerPrimaryKey();
                    if ($parentKey !== null && $column->matchesIntegerType($parentKey)) {
                        $candidates[] = $parent->name;
                    }
                }

                if (count($candidates) === 1) {
                    $inferred[] = new ForeignKey(
                        $table->name,
                        $column->name,
                        $candidates[0],
                        $column->name,
                        false,
                    );
                } elseif (count($candidates) > 1) {
                    $warnings[] = sprintf(
                        'Relationship for %s.%s is ambiguous: %s all have a matching primary key. No edge inferred.',
                        $table->name,
                        $column->name,
                        implode(', ', $candidates),
                    );
                }
            }
        }

        return $inferred;
    }

    /**
     * Kahn's algorithm. Self-references are ignored for ordering; anything left
     * over when the queue empties is in a cycle.
     *
     * @param list<string>     $tables
     * @param list<ForeignKey> $edges
     *
     * @return array{list<string>, array<string, true>}
     */
    private static function sort(array $tables, array $edges): array
    {
        $inDegree = array_fill_keys($tables, 0);
        $children = [];
        $seen = [];

        foreach ($edges as $edge) {
            if ($edge->isSelfReference()) {
                continue;
            }

            if (! isset($inDegree[$edge->childTable]) || ! isset($inDegree[$edge->parentTable])) {
                continue;
            }

            $pair = $edge->parentTable . "\0" . $edge->childTable;
            if (isset($seen[$pair])) {
                continue;
            }

            $seen[$pair] = true;
            $children[$edge->parentTable][] = $edge->childTable;
            ++$inDegree[$edge->childTable];
        }

        $queue = [];
        foreach ($tables as $table) {
            if ($inDegree[$table] === 0) {
                $queue[] = $table;
            }
        }

        $order = [];
        while ($queue !== []) {
            $table = array_shift($queue);
            $order[] = $table;

            foreach ($children[$table] ?? [] as $child) {
                if (--$inDegree[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }

        $cycleMembers = [];
        foreach ($tables as $table) {
            if (! in_array($table, $order, true)) {
                $cycleMembers[$table] = true;
                $order[] = $table;
            }
        }

        return [$order, $cycleMembers];
    }

    /**
     * @return list<ForeignKey>
     */
    public function edges(): array
    {
        return $this->edges;
    }

    /**
     * @return list<ForeignKey>
     */
    public function edgesForChild(string $table): array
    {
        return $this->byChild[$table] ?? [];
    }

    public function hasSelfReference(string $table): bool
    {
        foreach ($this->edgesForChild($table) as $edge) {
            if ($edge->isSelfReference()) {
                return true;
            }
        }

        return false;
    }

    public function isInCycle(string $table): bool
    {
        return isset($this->cycleMembers[$table]);
    }

    /**
     * @return list<string>
     */
    public function topologicalOrder(): array
    {
        return $this->order;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
