<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Report;

use DouglasGreen\DbTools\Db\CopyResult;
use DouglasGreen\DbTools\Db\PruneResult;
use DouglasGreen\DbTools\Plan\SyncPlanner;
use DouglasGreen\DbTools\Plan\TablePlan;

final class Report
{
    /**
     * @var array<string, array{
     *     database: string, table: string, class: string, modulus: int|null,
     *     sourceBytes: int, estimatedRows: int, rowsRead: int, rowsInserted: int,
     *     rowsPruned: int, rowsSkipped: int, bytes: int, seconds: float
     * }>
     */
    private array $rows = [];

    /** @var array<string, float> */
    private array $phaseStarts = [];

    /** @var array<string, float> */
    private array $phaseSeconds = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly float $startedAt)
    {
    }

    public function startPhase(string $name): void
    {
        $this->phaseStarts[$name] = microtime(true);
    }

    public function endPhase(string $name): void
    {
        if (! isset($this->phaseStarts[$name])) {
            return;
        }

        $this->phaseSeconds[$name] = ($this->phaseSeconds[$name] ?? 0.0)
            + (microtime(true) - $this->phaseStarts[$name]);

        unset($this->phaseStarts[$name]);
    }

    public function addPlan(string $database, TablePlan $plan, int $sourceBytes, int $estimatedRows): void
    {
        $this->rows[$database . '.' . $plan->table] = [
            'database' => $database,
            'table' => $plan->table,
            'class' => $plan->class,
            'modulus' => $plan->modulus,
            'sourceBytes' => $sourceBytes,
            'estimatedRows' => $estimatedRows,
            'rowsRead' => 0,
            'rowsInserted' => 0,
            'rowsPruned' => 0,
            'rowsSkipped' => 0,
            'bytes' => 0,
            'seconds' => 0.0,
        ];
    }

    public function addCopy(string $database, CopyResult $result): void
    {
        $key = $database . '.' . $result->table;
        if (! isset($this->rows[$key])) {
            $this->addWarning(sprintf(
                'Copy result for unplanned table %s.%s was discarded',
                $database,
                $result->table,
            ));
            return;
        }

        $this->rows[$key]['rowsRead'] = $result->rowsRead;
        $this->rows[$key]['rowsInserted'] = $result->rowsInserted;
        $this->rows[$key]['rowsSkipped'] = $result->rowsSkipped;
        $this->rows[$key]['bytes'] = $result->bytesTransferred;
        $this->rows[$key]['seconds'] = $result->seconds;
    }

    public function addPrune(string $database, PruneResult $result): void
    {
        $key = $database . '.' . $result->table;
        if (! isset($this->rows[$key])) {
            $this->addWarning(sprintf(
                'Prune result for unplanned table %s.%s was discarded',
                $database,
                $result->table,
            ));
            return;
        }

        $this->rows[$key]['rowsPruned'] += $result->rowsDeleted;
    }

    public function addWarning(string $warning): void
    {
        if (! in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
    }

    /**
     * @param list<string> $warnings
     */
    public function addWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            $this->addWarning($warning);
        }
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function renderPlan(): string
    {
        $lines = ['DRY RUN — no data was written.', ''];
        $lines[] = sprintf(
            '%-24s %-32s %-15s %6s %12s %12s',
            'DATABASE',
            'TABLE',
            'CLASS',
            'N',
            'SIZE',
            'EST. ROWS',
        );
        $lines[] = str_repeat('-', 108);

        foreach ($this->rows as $row) {
            $lines[] = sprintf(
                '%-24s %-32s %-15s %6s %12s %12s',
                $row['database'],
                $row['table'],
                $row['class'],
                $row['modulus'] === null ? '-' : (string) $row['modulus'],
                SyncPlanner::humanBytes($row['sourceBytes']),
                number_format($row['estimatedRows']),
            );
        }

        return implode("\n", array_merge($lines, [''], $this->warningLines())) . "\n";
    }

    public function renderSummary(float $finishedAt, int $sourceBytes, int $targetBytes): string
    {
        $lines = ['', 'COPY COMPLETE', ''];
        $lines[] = sprintf(
            '%-24s %-32s %-15s %12s %10s %10s %12s %10s',
            'DATABASE',
            'TABLE',
            'CLASS',
            'ROWS COPIED',
            'PRUNED',
            'SKIPPED',
            'BYTES',
            'TIME',
        );
        $lines[] = str_repeat('-', 131);

        $totalRows = 0;
        $totalPruned = 0;
        $totalSkipped = 0;

        foreach ($this->rows as $row) {
            $totalRows += $row['rowsInserted'];
            $totalPruned += $row['rowsPruned'];
            $totalSkipped += $row['rowsSkipped'];

            $lines[] = sprintf(
                '%-24s %-32s %-15s %12s %10s %10s %12s %10s',
                $row['database'],
                $row['table'],
                $row['class'],
                number_format($row['rowsInserted']),
                number_format($row['rowsPruned']),
                number_format($row['rowsSkipped']),
                SyncPlanner::humanBytes($row['bytes']),
                self::formatDuration($row['seconds']),
            );
        }

        $ratio = $sourceBytes > 0 ? ($targetBytes / $sourceBytes) * 100.0 : 0.0;

        $lines[] = '';
        $lines[] = sprintf('Tables:        %s', number_format(count($this->rows)));
        $lines[] = sprintf('Rows copied:   %s', number_format($totalRows));
        $lines[] = sprintf('Rows pruned:   %s', number_format($totalPruned));
        $lines[] = sprintf('Rows skipped:  %s', number_format($totalSkipped));
        $lines[] = sprintf('Source size:   %s', SyncPlanner::humanBytes($sourceBytes));
        $lines[] = sprintf('Target size:   %s', SyncPlanner::humanBytes($targetBytes));
        $lines[] = sprintf('Target/source: %.1f%%', $ratio);

        foreach ($this->phaseSeconds as $phase => $seconds) {
            $lines[] = sprintf('%-14s %s', $phase . ':', self::formatDuration($seconds));
        }

        $lines[] = sprintf('Total time:    %s', self::formatDuration($finishedAt - $this->startedAt));

        return implode("\n", array_merge($lines, [''], $this->warningLines())) . "\n";
    }

    /**
     * @return list<string>
     */
    private function warningLines(): array
    {
        if ($this->warnings === []) {
            return [];
        }

        $lines = [sprintf('Warnings (%d):', count($this->warnings))];
        foreach ($this->warnings as $warning) {
            $lines[] = '  - ' . $warning;
        }

        return array_merge($lines, ['']);
    }

    public static function formatDuration(float $seconds): string
    {
        if ($seconds < 60.0) {
            return sprintf('%.2fs', $seconds);
        }

        $whole = (int) round($seconds);

        if ($whole < 3600) {
            return sprintf('%dm %02ds', intdiv($whole, 60), $whole % 60);
        }

        return sprintf(
            '%dh %02dm %02ds',
            intdiv($whole, 3600),
            intdiv($whole % 3600, 60),
            $whole % 60,
        );
    }
}
