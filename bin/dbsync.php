#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools;

use DouglasGreen\DbTools\Cli\Arguments;
use DouglasGreen\DbTools\Cli\ExitCode;
use DouglasGreen\DbTools\Config\Config;
use DouglasGreen\DbTools\Exception\ConfigException;
use DouglasGreen\DbTools\Exception\ConnectionException;
use DouglasGreen\DbTools\Db\Connection;
use DouglasGreen\DbTools\Report\DebugLog;
use DouglasGreen\DbTools\Report\Report;
use PDOException;
use Throwable;

$autoloaders = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];

$loaded = false;
foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader)) {
        require $autoloader;
        $loaded = true;
        break;
    }
}

if (! $loaded) {
    fwrite(STDERR, "Autoloader not found. Run: composer install\n");
    // ExitCode is not loadable here — no autoloader has run, so the class
    // constant lookup below would itself throw. Use the literal.
    exit(1);
}

$startedAt = microtime(true);

try {
    $arguments = Arguments::parse($argv);
} catch (ConfigException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n\n" . Arguments::usage() . "\n");
    exit(ExitCode::CONFIG_ERROR);
}

if ($arguments->helpRequested) {
    fwrite(STDOUT, Arguments::usage() . "\n");
    exit(ExitCode::SUCCESS);
}

$debug = new DebugLog($arguments->verbose, STDERR, $startedAt);

try {
    $config = Config::load($arguments->configPath);
    $runner = new SyncRunner($config, $arguments, new Report($startedAt), STDOUT, $debug);
    exit($runner->run());
} catch (ConfigException $e) {
    fwrite(STDERR, 'Configuration error: ' . $e->getMessage() . "\n");
    exit(ExitCode::CONFIG_ERROR);
} catch (ConnectionException $e) {
    fwrite(STDERR, 'Connection error: ' . $e->getMessage() . "\n");
    exit(ExitCode::CONNECTION_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, 'Fatal error during copy: ' . $e->getMessage() . "\n");

    // The driver's own message ("MySQL server has gone away") rarely names the
    // cause. Print the exception chain, including SQLSTATE and the MySQL error
    // number, so the real failure is visible without rerunning with --verbose.
    for ($cause = $e->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
        fwrite(STDERR, sprintf(
            '  caused by %s: %s%s',
            $cause::class,
            $cause instanceof PDOException
                ? Connection::describeError($cause)
                : $cause->getMessage(),
            PHP_EOL,
        ));
    }

    fwrite(STDERR, sprintf('  at %s:%d%s', $e->getFile(), $e->getLine(), PHP_EOL));

    if (! $arguments->verbose) {
        fwrite(STDERR, "  Rerun with --verbose to trace every table and batch.\n");
    } else {
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }

    exit(ExitCode::COPY_ERROR);
}
