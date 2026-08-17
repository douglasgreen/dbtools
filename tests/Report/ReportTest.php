<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Report;

use DouglasGreen\DbTools\Db\CopyResult;
use DouglasGreen\DbTools\Db\PruneResult;
use DouglasGreen\DbTools\Plan\TablePlan;
use DouglasGreen\DbTools\Report\Report;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    public function testFormatsDurations(): void
    {
        self::assertSame('0.45s', Report::formatDuration(0.4517));
        self::assertSame('12.30s', Report::formatDuration(12.3));
        self::assertSame('2m 05s', Report::formatDuration(125.0));
        self::assertSame('1h 01m 05s', Report::formatDuration(3665.0));
    }

    public function testPlanRenderShowsClassAndModulus(): void
    {
        $report = new Report(0.0);
        $report->addPlan('shop', $this->subnetPlan(), 120 * 1048576, 900000);
        $report->addPlan('shop', $this->fullPlan(), 4096, 12);

        $output = $report->renderPlan();

        self::assertStringContainsString('orders', $output);
        self::assertStringContainsString('SUBNET', $output);
        // Modulus 23 is unique to the orders table and appears nowhere else
        self::assertStringContainsString('23', $output);
        self::assertStringContainsString('countries', $output);
        self::assertStringContainsString('FULL', $output);
        self::assertStringContainsString('DRY RUN', $output);
    }

    public function testSummaryCountsRowsAndPrunes(): void
    {
        $report = new Report(0.0);
        $report->addPlan('shop', $this->subnetPlan(), 120 * 1048576, 900000);
        $report->addCopy('shop', new CopyResult('orders', 75000, 75000, 4_500_000, 3.5));
        $report->addPrune('shop', new PruneResult('orders', 'customer_id', 'customers', 1200));

        $output = $report->renderSummary(10.0, 200 * 1048576, 20 * 1048576);

        self::assertStringContainsString('75,000', $output);
        self::assertStringContainsString('1,200', $output);
        self::assertStringContainsString('200.0 MB', $output);
        self::assertStringContainsString('20.0 MB', $output);
        self::assertStringContainsString('10.0%', $output);
    }

    public function testWarningsAreCollectedAndDeduplicated(): void
    {
        $report = new Report(0.0);
        $report->addWarnings(['first', 'second']);
        $report->addWarning('first');

        self::assertTrue($report->hasWarnings());
        self::assertSame(['first', 'second'], $report->warnings());
        self::assertStringContainsString('Warnings (2)', $report->renderSummary(1.0, 100, 50));
        self::assertStringContainsString('Warnings (2)', $report->renderPlan());
    }

    public function testNoWarningsMeansCleanSummary(): void
    {
        $report = new Report(0.0);

        self::assertFalse($report->hasWarnings());
        self::assertStringNotContainsString('Warnings', $report->renderSummary(1.0, 100, 50));
    }

    public function testPhaseAccumulatesAcrossMultipleCalls(): void
    {
        $report = new Report(0.0);

        // Simulate Task 12 calling startPhase/endPhase for 'Copy' twice (once per database)
        $report->startPhase('Copy');
        usleep(50000); // 0.05 seconds
        $report->endPhase('Copy');

        $report->startPhase('Copy');
        usleep(50000); // 0.05 seconds
        $report->endPhase('Copy');

        $output = $report->renderSummary(1.0, 100, 50);

        // Total should be ~0.1 seconds (two 0.05s calls), which formats as "0.10s"
        self::assertStringContainsString('Copy:', $output);
        // The timing should show accumulated time, not just the last call
        self::assertStringContainsString('0.1', $output);
    }

    public function testSummaryWithZeroSourceBytes(): void
    {
        $report = new Report(0.0);
        $report->addPlan('shop', $this->subnetPlan(), 100, 1000);

        // Call renderSummary with sourceBytes=0 to test the zero-division guard
        $output = $report->renderSummary(1.0, 0, 0);

        self::assertStringContainsString('0.0%', $output);
        self::assertStringNotContainsString('NaN', $output);
        self::assertStringNotContainsString('INF', $output);
    }

    public function testCopyOfUnplannedTableAddsWarning(): void
    {
        $report = new Report(0.0);

        // Try to copy a table that was never added via addPlan
        $report->addCopy('shop', new CopyResult('unknown_table', 100, 100, 1000, 1.0));

        self::assertTrue($report->hasWarnings());
        $warnings = $report->warnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('unknown_table', $warnings[0]);
        self::assertStringContainsString('shop', $warnings[0]);
    }

    private function subnetPlan(): TablePlan
    {
        return new TablePlan(
            'orders',
            TablePlan::SUBNET,
            23,
            0,
            '(`order_id` % 23) = 0',
            false,
            false,
            '120.0 MB over threshold',
        );
    }

    private function fullPlan(): TablePlan
    {
        return new TablePlan('countries', TablePlan::FULL, null, 0, '', false, true, 'at or under threshold');
    }
}
