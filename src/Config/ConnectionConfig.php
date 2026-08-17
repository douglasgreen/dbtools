<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Config;

final readonly class ConnectionConfig
{
    public function __construct(
        public string $name,
        public string $host,
        public string $user,
        public string $password,
        public int $port = 3306,
    ) {
    }
}
