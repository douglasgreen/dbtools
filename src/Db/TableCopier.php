<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Db;

use DouglasGreen\DbTools\Exception\CopyException;
use DouglasGreen\DbTools\Plan\TablePlan;
use DouglasGreen\DbTools\Report\DebugLog;
use DouglasGreen\DbTools\Schema\TableInfo;
use DouglasGreen\DbTools\Sql;
use PDO;
use PDOException;
use PDOStatement;

final class TableCopier
{
    private const PLACEHOLDER_BUDGET = 60000;

    /**
     * Bytes of the target's max_allowed_packet left for the statement text,
     * per-parameter type and length prefixes, and the protocol header. A single
     * row estimated above the remainder cannot be sent at all: MySQL closes the
     * connection on an oversized packet instead of rejecting the statement.
     */
    private const PACKET_RESERVE = 65536;

    /** Worst-case protocol overhead per bound value: 8-byte length prefix plus type bytes. */
    private const VALUE_OVERHEAD = 11;

    private readonly int $byteBudget;

    private readonly int $maxRowBytes;

    /** @var array<string, PDOStatement> keyed by the generated INSERT SQL */
    private array $prepared = [];

    /** @var list<string> */
    private array $warnings = [];

    private readonly DebugLog $debug;

    public function __construct(
        private readonly Connection $source,
        private readonly Connection $target,
        private readonly int $batchSize,
        ?DebugLog $debug = null,
    ) {
        $packet = $this->target->maxAllowedPacket();
        $this->maxRowBytes = self::usableRowBytes($packet);
        $this->byteBudget = min(intdiv($packet, 3), $this->maxRowBytes);
        $this->debug = $debug ?? new DebugLog(false);

        $this->debug->log(sprintf(
            'copier: target max_allowed_packet=%d batch byte budget=%d max single row=%d',
            $packet,
            $this->byteBudget,
            $this->maxRowBytes,
        ));
    }

    public static function rowsPerStatement(int $columnCount, int $batchSize): int
    {
        if ($columnCount < 1) {
            return 1;
        }

        return max(1, min($batchSize, intdiv(self::PLACEHOLDER_BUDGET, $columnCount)));
    }

    /**
     * Largest row the target will accept, given its max_allowed_packet.
     */
    public static function usableRowBytes(int $maxAllowedPacket): int
    {
        return max(1024, $maxAllowedPacket - self::PACKET_RESERVE);
    }

    /**
     * Wire size of one row, over-estimated on purpose. Under-estimating pushes a
     * batch past max_allowed_packet, which the server answers by dropping the
     * connection.
     *
     * @param list<scalar|null> $row
     */
    public static function estimateRowBytes(array $row): int
    {
        $bytes = 0;
        foreach ($row as $value) {
            $bytes += $value === null
                ? self::VALUE_OVERHEAD
                : strlen((string) $value) + self::VALUE_OVERHEAD;
        }

        return $bytes;
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

        // The prepared statement's text travels in a packet of its own, so the
        // row count is capped by the placeholder limit and by the text budget.
        $tupleLength = 3 * count($columns) + 4;
        $perStatement = min(
            self::rowsPerStatement(count($columns), $this->batchSize),
            max(1, intdiv($this->byteBudget - strlen($insertPrefix), $tupleLength)),
        );

        $this->debug->log(sprintf(
            'copy %s.%s: class=%s rows/statement=%d select=%s',
            $table->database,
            $table->name,
            $plan->class,
            $perStatement,
            $select,
        ));

        $reader = $this->source->pdo()->query($select);
        if ($reader === false) {
            $this->warnings[] = sprintf(
                'Source query for %s.%s returned no result set; table left empty on the target.',
                $table->database,
                $table->name,
            );

            return new CopyResult($table->name, 0, 0, 0, microtime(true) - $started);
        }

        $rowsRead = 0;
        $rowsInserted = 0;
        $rowsSkipped = 0;
        $bytes = 0;
        $buffer = [];
        $bufferBytes = 0;

        try {
            while (($row = $reader->fetch(PDO::FETCH_NUM)) !== false) {
                ++$rowsRead;
                $rowBytes = self::estimateRowBytes($row);

                // The target would drop the connection on this packet, and every
                // later statement on that dead handle reports the useless "MySQL
                // server has gone away". Skip the row loudly instead.
                if ($rowBytes > $this->maxRowBytes) {
                    ++$rowsSkipped;
                    $this->warnings[] = $this->oversizedRowWarning($table, $row, $rowBytes);
                    continue;
                }

                // Flush before appending, so the buffer never exceeds the budget.
                if ($buffer !== [] && $bufferBytes + $rowBytes > $this->byteBudget) {
                    $rowsInserted += $this->flush($table, $insertPrefix, count($columns), $buffer, $bufferBytes);
                    $bytes += $bufferBytes;
                    $buffer = [];
                    $bufferBytes = 0;
                }

                $buffer[] = $row;
                $bufferBytes += $rowBytes;

                if (count($buffer) >= $perStatement) {
                    $rowsInserted += $this->flush($table, $insertPrefix, count($columns), $buffer, $bufferBytes);
                    $bytes += $bufferBytes;
                    $buffer = [];
                    $bufferBytes = 0;
                }
            }

            if ($buffer !== []) {
                $rowsInserted += $this->flush($table, $insertPrefix, count($columns), $buffer, $bufferBytes);
                $bytes += $bufferBytes;
            }
        } finally {
            $reader->closeCursor();
        }

        $this->debug->log(sprintf(
            'copy %s.%s: read=%d inserted=%d skipped=%d bytes=%d',
            $table->database,
            $table->name,
            $rowsRead,
            $rowsInserted,
            $rowsSkipped,
            $bytes,
        ));

        return new CopyResult(
            $table->name,
            $rowsRead,
            $rowsInserted,
            $bytes,
            microtime(true) - $started,
            $rowsSkipped,
        );
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param list<list<scalar|null>> $rows
     */
    private function flush(
        TableInfo $table,
        string $insertPrefix,
        int $columnCount,
        array $rows,
        int $estimatedBytes,
    ): int {
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

        try {
            $statement->execute($flat);
            $statement->closeCursor();
        } catch (PDOException $e) {
            throw new CopyException($this->flushFailureMessage($table, $count, $estimatedBytes, $e), 0, $e);
        }

        $this->debug->log(sprintf(
            'flush %s.%s: rows=%d params=%d est_bytes=%d',
            $table->database,
            $table->name,
            $count,
            count($flat),
            $estimatedBytes,
        ));

        return $count;
    }

    private function flushFailureMessage(
        TableInfo $table,
        int $rowCount,
        int $estimatedBytes,
        PDOException $e,
    ): string {
        $message = sprintf(
            'INSERT of %d row(s) into %s.%s failed (estimated %d bytes on the wire, '
                . 'target max_allowed_packet=%d, per-batch budget=%d, largest sendable row=%d). %s',
            $rowCount,
            $table->database,
            $table->name,
            $estimatedBytes,
            $this->target->maxAllowedPacket(),
            $this->byteBudget,
            $this->maxRowBytes,
            Connection::describeError($e),
        );

        if (Connection::isConnectionLost($e)) {
            $message .= sprintf(
                ' The target server closed the connection. When a statement exceeds '
                    . 'max_allowed_packet (%d bytes on "%s"), MySQL drops the link and every '
                    . 'later query reports "MySQL server has gone away". Raise '
                    . 'max_allowed_packet on the target, or lower batch_size in config.ini.',
                $this->target->maxAllowedPacket(),
                $this->target->name(),
            );
        }

        return $message;
    }

    /**
     * @param list<scalar|null> $row
     */
    private function oversizedRowWarning(TableInfo $table, array $row, int $rowBytes): string
    {
        return sprintf(
            'Skipped one row of %s.%s (%s): estimated %d bytes exceeds the largest row the '
                . 'target "%s" can accept (%d bytes, from max_allowed_packet=%d). Sending it '
                . 'would make MySQL close the connection. Raise max_allowed_packet on the target '
                . 'to at least %d bytes to copy this row.',
            $table->database,
            $table->name,
            $this->describeKey($table, $row),
            $rowBytes,
            $this->target->name(),
            $this->maxRowBytes,
            $this->target->maxAllowedPacket(),
            $rowBytes + self::PACKET_RESERVE,
        );
    }

    /**
     * Primary key of the row, so a skipped row can be found in the source.
     *
     * @param list<scalar|null> $row
     */
    private function describeKey(TableInfo $table, array $row): string
    {
        if ($table->primaryKey === []) {
            return 'no primary key';
        }

        $positions = array_flip($table->columnNames());
        $parts = [];

        foreach ($table->primaryKey as $column) {
            $index = $positions[$column] ?? null;
            $value = $index === null ? null : ($row[$index] ?? null);
            $parts[] = $column . '=' . ($value === null ? 'NULL' : (string) $value);
        }

        return implode(', ', $parts);
    }
}
