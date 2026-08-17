<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Cli;

final class ExitCode
{
    public const SUCCESS = 0;

    public const CONFIG_ERROR = 1;

    public const CONNECTION_ERROR = 2;

    public const COMPLETED_WITH_WARNINGS = 3;

    public const COPY_ERROR = 4;
}
