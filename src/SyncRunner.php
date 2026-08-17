<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools;

use DouglasGreen\DbTools\Cli\Arguments;
use DouglasGreen\DbTools\Cli\ExitCode;
use DouglasGreen\DbTools\Config\Config;
use DouglasGreen\DbTools\Db\Connection;
use DouglasGreen\DbTools\Db\Pruner;
use DouglasGreen\DbTools\Db\SchemaInspector;
use DouglasGreen\DbTools\Db\TableCopier;
use DouglasGreen\DbTools\Exception\ConfigException;
use DouglasGreen\DbTools\Plan\SyncPlanner;
use DouglasGreen\DbTools\Report\Report;
use DouglasGreen\DbTools\Schema\RelationshipGraph;
use PDOException;

final class SyncRunner
{
    /** @var resource */
    private $out;

    /**
     * @param resource|null $out defaults to STDOUT
     */
    public function __construct(
        private readonly Config $config,
        private readonly Arguments $arguments,
        private readonly Report $report,
        $out = null,
    ) {
        $this->out = $out ?? STDOUT;
    }

    public function run(): int
    {
        $options = $this->config->sync();
        if ($this->arguments->thresholdMb !== null) {
            $options = $options->withThresholdBytes($this->arguments->thresholdMb * 1024 * 1024);
        }

        $sourceConfig = $this->config->connection($this->arguments->source);
        $targetConfig = $this->config->connection($this->arguments->target);

        // Each database is copied to the same name on the target, so two
        // connections pointing at one server would have dbsync overwrite the
        // very data it is reading. Distinct connection names are not enough.
        if (
            strcasecmp($sourceConfig->host, $targetConfig->host) === 0
            && $sourceConfig->port === $targetConfig->port
        ) {
            throw new ConfigException(sprintf(
                'Connections "%s" and "%s" both point at %s:%d. Every database is copied '
                    . 'to the same name on the target, so this would overwrite the source.',
                $sourceConfig->name,
                $targetConfig->name,
                $sourceConfig->host,
                $sourceConfig->port,
            ));
        }

        // Unbuffered so a table larger than PHP's memory limit still streams.
        $source = Connection::open($sourceConfig, null, true);
        $source->applySessionDefaults(false);
        $inspector = new SchemaInspector($source);

        $databases = $this->resolveDatabases($inspector, $options->excludeDatabases);

        $target = null;
        if (! $this->arguments->dryRun) {
            $target = Connection::open($targetConfig);
            $target->applySessionDefaults(true);
        }

        $sourceBytes = 0;
        $targetBytes = 0;

        foreach ($databases as $database) {
            $this->report->startPhase('Inspect');
            $schema = $inspector->inspect($database);
            $graph = RelationshipGraph::build($schema);
            $this->report->endPhase('Inspect');

            $planner = new SyncPlanner($options);
            $plans = $planner->plan($schema, $graph);

            $this->report->addWarnings($graph->warnings());
            $this->report->addWarnings($planner->warnings());

            foreach ($graph->topologicalOrder() as $name) {
                $table = $schema->table($name);
                if ($table !== null && isset($plans[$name])) {
                    $this->report->addPlan($database, $plans[$name], $table->sizeBytes, $table->estimatedRows);
                }
            }

            $sourceBytes += $inspector->databaseSizeBytes($database);

            if ($target === null) {
                continue;
            }

            $this->createTargetDatabase($inspector, $target, $database);

            $this->report->startPhase('Copy');
            $copier = new TableCopier($source, $target, $options->batchSize);

            foreach ($graph->topologicalOrder() as $name) {
                $table = $schema->table($name);
                $plan = $plans[$name] ?? null;
                if ($table === null || $plan === null) {
                    continue;
                }

                try {
                    $copier->recreate($database, $name, $inspector->createTableStatement($database, $name));
                    $this->report->addCopy($database, $copier->copy($table, $plan));
                } catch (PDOException $e) {
                    // TableCopier::copy() streams rows in batches, so this
                    // exception can arrive after some batches already
                    // committed. The table is not untouched — it may hold a
                    // fraction of its rows on the target.
                    $this->report->addWarning(sprintf(
                        'Table %s.%s failed mid-copy and may be left partially populated on the target: %s',
                        $database,
                        $name,
                        $e->getMessage(),
                    ));
                }
            }

            $this->report->endPhase('Copy');

            $this->report->startPhase('Prune');
            $pruner = new Pruner($target);
            foreach ($pruner->prune($database, $graph->edges(), $plans, $graph->topologicalOrder()) as $result) {
                $this->report->addPrune($database, $result);
            }

            $this->report->addWarnings($pruner->warnings());
            $this->report->endPhase('Prune');

            $targetBytes += (new SchemaInspector($target))->databaseSizeBytes($database);
        }

        $this->report->addWarnings($inspector->warnings());

        if ($target !== null) {
            $target->restoreChecks();
        }

        if ($this->arguments->dryRun) {
            fwrite($this->out, $this->report->renderPlan());

            return ExitCode::SUCCESS;
        }

        fwrite($this->out, $this->report->renderSummary(microtime(true), $sourceBytes, $targetBytes));

        return $this->report->hasWarnings() ? ExitCode::COMPLETED_WITH_WARNINGS : ExitCode::SUCCESS;
    }

    /**
     * @param list<string> $excluded
     *
     * @return list<string>
     */
    private function resolveDatabases(SchemaInspector $inspector, array $excluded): array
    {
        if ($this->arguments->database === null) {
            return $inspector->databases($excluded);
        }

        $visible = $inspector->databases([]);
        foreach ($visible as $name) {
            if (strcasecmp($name, $this->arguments->database) === 0) {
                return [$name];
            }
        }

        throw new ConfigException(sprintf(
            'Database "%s" is not visible to connection "%s".',
            $this->arguments->database,
            $this->arguments->source,
        ));
    }

    private function createTargetDatabase(
        SchemaInspector $inspector,
        Connection $target,
        string $database,
    ): void {
        $charset = $inspector->databaseCharset($database);
        $quoted = Sql::quoteIdentifier($database);

        if ($this->arguments->drop) {
            $target->exec('DROP DATABASE IF EXISTS ' . $quoted);
        }

        $target->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS %s DEFAULT CHARACTER SET %s COLLATE %s',
            $quoted,
            $charset['charset'],
            $charset['collation'],
        ));
    }
}
