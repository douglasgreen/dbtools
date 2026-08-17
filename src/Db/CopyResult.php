<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Db;

final readonly class CopyResult
{
    public function __construct(
        public string $table,
        public int $rowsRead,
        public int $rowsInserted,
        public int $bytesTransferred,
        public float $seconds,
    ) {
    }
}
