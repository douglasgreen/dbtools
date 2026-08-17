<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Cli;

use DouglasGreen\DbTools\Exception\ConfigException;

final readonly class Arguments
{
    private const VALUE_OPTIONS = ['source', 'target', 'database', 'config', 'threshold'];

    private const FLAG_OPTIONS = ['drop', 'dry-run', 'help'];

    public function __construct(
        public string $source,
        public string $target,
        public ?string $database,
        public bool $drop,
        public string $configPath,
        public bool $dryRun,
        public ?int $thresholdMb,
        public bool $helpRequested,
    ) {
    }

    /**
     * @param list<string> $argv including the script name at index 0
     */
    public static function parse(array $argv): self
    {
        $values = [];
        $flags = [];

        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '-h') {
                $flags['help'] = true;
                continue;
            }

            if (! str_starts_with($argument, '--')) {
                throw new ConfigException(sprintf(
                    'Unexpected argument "%s". Options must start with --.',
                    $argument,
                ));
            }

            $body = substr($argument, 2);
            $equals = strpos($body, '=');

            if ($equals === false) {
                if (! in_array($body, self::FLAG_OPTIONS, true)) {
                    throw new ConfigException(sprintf('Unknown option "--%s".', $body));
                }

                $flags[$body] = true;
                continue;
            }

            $name = substr($body, 0, $equals);
            if (! in_array($name, self::VALUE_OPTIONS, true)) {
                throw new ConfigException(sprintf('Unknown option "--%s".', $name));
            }

            $values[$name] = substr($body, $equals + 1);
        }

        $help = isset($flags['help']);

        if (! $help) {
            foreach (['source', 'target'] as $required) {
                if (($values[$required] ?? '') === '') {
                    throw new ConfigException(sprintf('Missing required option --%s.', $required));
                }
            }

            if ($values['source'] === $values['target']) {
                throw new ConfigException(
                    'Source and target are the same connection. Refusing to copy a server onto itself.',
                );
            }
        }

        $threshold = null;
        if (isset($values['threshold'])) {
            $threshold = (int) $values['threshold'];
            if ($threshold < 1) {
                throw new ConfigException('--threshold must be at least 1 megabyte.');
            }
        }

        $database = $values['database'] ?? null;

        return new self(
            $values['source'] ?? '',
            $values['target'] ?? '',
            $database === '' ? null : $database,
            isset($flags['drop']),
            ($values['config'] ?? '') === '' ? 'config.ini' : $values['config'],
            isset($flags['dry-run']),
            $threshold,
            $help,
        );
    }

    public static function usage(): string
    {
        return <<<'USAGE'
            dbsync — copy MySQL databases between servers without copying every row.

            Usage:
              dbsync.php --source=NAME --target=NAME [options]

            Required:
              --source=NAME     Named connection in config.ini to read from.
              --target=NAME     Named connection in config.ini to write to.

            Options:
              --database=NAME   Limit to one database. Default: every readable database.
              --drop            DROP DATABASE before creating it. Without this, tables
                                are still replaced completely.
              --config=PATH     Config file path. Default: config.ini
              --dry-run         Print the plan and exit without writing anything.
              --threshold=MB    Size above which a table is subnetted. Default: 10
              --help, -h        Show this message.

            Exit codes:
              0  success
              1  configuration or argument error
              2  connection failure
              3  completed with warnings
              4  fatal error during copy
            USAGE;
    }
}
