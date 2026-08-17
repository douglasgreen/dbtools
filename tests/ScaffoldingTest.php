<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests;

use PHPUnit\Framework\TestCase;

final class ScaffoldingTest extends TestCase
{
    public function testSampleConfigParsesWithSectionsAndDefaults(): void
    {
        $parsed = parse_ini_file(__DIR__ . '/../config.ini.sample', true, INI_SCANNER_RAW);

        self::assertIsArray($parsed);
        self::assertArrayHasKey('sync', $parsed);
        self::assertArrayHasKey('prod', $parsed);
        self::assertSame('10', $parsed['sync']['threshold_mb']);
        self::assertArrayNotHasKey('port', $parsed['dev']);
    }

    public function testConfigIniIsIgnored(): void
    {
        $ignored = file_get_contents(__DIR__ . '/../.gitignore');

        self::assertIsString($ignored);
        self::assertStringContainsString('config.ini', $ignored);
    }
}
