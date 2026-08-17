<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Config;

use DouglasGreen\DbTools\Exception\ConfigException;

final class Config
{
    private const SYNC_SECTION = 'sync';

    private const DEFAULT_EXCLUDED = ['mysql', 'information_schema', 'performance_schema', 'sys'];

    /**
     * @param array<string, array<string, string>> $sections
     */
    private function __construct(private readonly array $sections)
    {
    }

    public static function load(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ConfigException(sprintf('Config file not readable: %s', $path));
        }

        $parsed = @parse_ini_file($path, true, INI_SCANNER_RAW);
        if ($parsed === false) {
            throw new ConfigException(sprintf('Config file is not valid INI: %s', $path));
        }

        /** @var array<string, array<string, string>> $parsed */
        return self::fromArray($parsed);
    }

    /**
     * @param array<string, array<string, string>> $sections
     */
    public static function fromArray(array $sections): self
    {
        return new self($sections);
    }

    /**
     * @return list<string>
     */
    public function connectionNames(): array
    {
        $names = array_keys($this->sections);

        return array_values(array_filter($names, static fn (string $n): bool => $n !== self::SYNC_SECTION));
    }

    public function connection(string $name): ConnectionConfig
    {
        if ($name === self::SYNC_SECTION || ! isset($this->sections[$name])) {
            throw new ConfigException(sprintf(
                'Unknown connection "%s". Available connections: %s',
                $name,
                implode(', ', $this->connectionNames()) ?: '(none defined)',
            ));
        }

        $section = $this->sections[$name];

        foreach (['host', 'user'] as $required) {
            if (! isset($section[$required]) || trim($section[$required]) === '') {
                throw new ConfigException(sprintf(
                    'Connection "%s" is missing required key "%s".',
                    $name,
                    $required,
                ));
            }
        }

        return new ConnectionConfig(
            $name,
            trim($section['host']),
            trim($section['user']),
            $section['password'] ?? '',
            isset($section['port']) ? (int) $section['port'] : 3306,
        );
    }

    public function sync(): SyncOptions
    {
        $section = $this->sections[self::SYNC_SECTION] ?? [];

        $thresholdMb = isset($section['threshold_mb']) ? (int) $section['threshold_mb'] : 10;
        if ($thresholdMb < 1) {
            throw new ConfigException('threshold_mb must be at least 1.');
        }

        $batchSize = isset($section['batch_size']) ? (int) $section['batch_size'] : 1000;
        if ($batchSize < 1) {
            throw new ConfigException('batch_size must be at least 1.');
        }

        $excluded = isset($section['exclude_databases'])
            ? self::splitList($section['exclude_databases'])
            : self::DEFAULT_EXCLUDED;

        return new SyncOptions(
            $thresholdMb * 1024 * 1024,
            isset($section['subnet_remainder']) ? (int) $section['subnet_remainder'] : 0,
            $batchSize,
            $excluded,
            isset($section['force_full_tables']) ? self::splitList($section['force_full_tables']) : [],
        );
    }

    /**
     * @return list<string>
     */
    private static function splitList(string $value): array
    {
        $parts = array_map(
            static fn (string $p): string => strtolower(trim($p)),
            explode(',', $value),
        );

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
