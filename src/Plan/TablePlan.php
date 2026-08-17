<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Plan;

final readonly class TablePlan
{
    public const FULL = 'FULL';

    public const SUBNET = 'SUBNET';

    public const STRUCTURE_ONLY = 'STRUCTURE_ONLY';

    public function __construct(
        public string $table,
        public string $class,
        public ?int $modulus,
        public int $remainder,
        public string $whereClause,
        public bool $pureModulus,
        public bool $isComplete,
        public string $reason,
    ) {
    }

    public function copiesRows(): bool
    {
        return $this->class !== self::STRUCTURE_ONLY;
    }
}
