<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Config;

final readonly class SyncOptions
{
    /**
     * @param list<string> $excludeDatabases lowercased database names
     * @param list<string> $forceFullTables  lowercased "database.table" entries
     */
    public function __construct(
        public int $thresholdBytes,
        public int $subnetRemainder,
        public int $batchSize,
        public array $excludeDatabases,
        public array $forceFullTables,
    ) {
    }

    public function withThresholdBytes(int $thresholdBytes): self
    {
        return new self(
            $thresholdBytes,
            $this->subnetRemainder,
            $this->batchSize,
            $this->excludeDatabases,
            $this->forceFullTables,
        );
    }

    public function isForcedFull(string $database, string $table): bool
    {
        return in_array(
            strtolower($database) . '.' . strtolower($table),
            $this->forceFullTables,
            true,
        );
    }
}
