<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Db;

use DouglasGreen\DbTools\Plan\TablePlan;
use DouglasGreen\DbTools\Schema\TableInfo;
use DouglasGreen\DbTools\Sql;
use PDO;
use PDOStatement;

final class TableCopier
{
    private const PLACEHOLDER_BUDGET = 60000;

    private int $byteBudget;

    /** @var array<string, PDOStatement> keyed by the generated INSERT SQL */
    private array $prepared = [];

    public function __construct(
        private readonly Connection $source,
        private readonly Connection $target,
        private readonly int $batchSize,
    ) {
        $this->byteBudget = intdiv($this->target->maxAllowedPacket(), 3);
    }

    public static function rowsPerStatement(int $columnCount, int $batchSize): int
    {
        if ($columnCount < 1) {
            return 1;
        }

        return max(1, min($batchSize, intdiv(self::PLACEHOLDER_BUDGET, $columnCount)));
    }

    /**
     * SHOW CREATE TABLE output is unqualified, so the target session must be
     * pointed at the database first. Keeping the statement byte-for-byte
     * preserves engine, charset, collation, indexes, row format, and the
     * AUTO_INCREMENT counter.
     */
    public function recreate(string $database, string $table, string $createStatement): void
    {
        $this->target->useDatabase($database);
        $this->target->exec('DROP TABLE IF EXISTS ' . Sql::quoteIdentifier($table));
        $this->target->exec($createStatement);
        $this->prepared = [];
    }

    public function copy(TableInfo $table, TablePlan $plan): CopyResult
    {
        $started = microtime(true);

        if (! $plan->copiesRows() || $table->columnCount() === 0) {
            return new CopyResult($table->name, 0, 0, 0, microtime(true) - $started);
        }

        $columns = $table->columnNames();
        $quoted = array_map(static fn (string $c): string => Sql::quoteIdentifier($c), $columns);

        $select = 'SELECT ' . implode(', ', $quoted) . ' FROM ' . Sql::qualify($table->database, $table->name);
        if ($plan->whereClause !== '') {
            $select .= ' WHERE ' . $plan->whereClause;
        }

        if ($table->primaryKey !== []) {
            $select .= ' ORDER BY ' . implode(', ', array_map(
                static fn (string $c): string => Sql::quoteIdentifier($c),
                $table->primaryKey,
            ));
        }

        $insertPrefix = 'INSERT INTO ' . Sql::quoteIdentifier($table->name)
            . ' (' . implode(', ', $quoted) . ') VALUES ';

        $perStatement = self::rowsPerStatement(count($columns), $this->batchSize);

        $reader = $this->source->pdo()->query($select);
        if ($reader === false) {
            return new CopyResult($table->name, 0, 0, 0, microtime(true) - $started);
        }

        $rowsRead = 0;
        $rowsInserted = 0;
        $bytes = 0;
        $buffer = [];
        $bufferBytes = 0;

        try {
            while (($row = $reader->fetch(PDO::FETCH_NUM)) !== false) {
                ++$rowsRead;
                $buffer[] = $row;

                foreach ($row as $value) {
                    $bufferBytes += $value === null ? 4 : strlen((string) $value) + 3;
                }

                if (count($buffer) >= $perStatement || $bufferBytes >= $this->byteBudget) {
                    $rowsInserted += $this->flush($insertPrefix, count($columns), $buffer);
                    $bytes += $bufferBytes;
                    $buffer = [];
                    $bufferBytes = 0;
                }
            }

            if ($buffer !== []) {
                $rowsInserted += $this->flush($insertPrefix, count($columns), $buffer);
                $bytes += $bufferBytes;
            }
        } finally {
            $reader->closeCursor();
        }

        return new CopyResult($table->name, $rowsRead, $rowsInserted, $bytes, microtime(true) - $started);
    }

    /**
     * @param list<list<scalar|null>> $rows
     */
    private function flush(string $insertPrefix, int $columnCount, array $rows): int
    {
        $count = count($rows);
        $tuple = '(' . implode(', ', array_fill(0, $columnCount, '?')) . ')';
        $sql = $insertPrefix . implode(', ', array_fill(0, $count, $tuple));

        // Cached per row count: one entry for full batches, plus one for
        // the single partial batch at the end of each table.
        $statement = $this->prepared[$sql] ??= $this->target->pdo()->prepare($sql);

        $flat = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $flat[] = $value;
            }
        }

        $statement->execute($flat);
        $statement->closeCursor();

        return $count;
    }
}
