<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Config;

use DouglasGreen\DbTools\Config\Config;
use DouglasGreen\DbTools\Exception\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /**
     * @return array<string, array<string, string>>
     */
    private function parsed(): array
    {
        return [
            'sync' => [
                'threshold_mb' => '10',
                'subnet_remainder' => '0',
                'batch_size' => '1000',
                'exclude_databases' => 'mysql,information_schema',
                'force_full_tables' => 'Shop.Categories, shop.tags',
            ],
            'prod' => [
                'host' => 'db1.example.invalid',
                'user' => 'readonly_user',
                'password' => 'secret',
                'port' => '3307',
            ],
            'dev' => [
                'host' => '127.0.0.1',
                'user' => 'dev_user',
                'password' => 'devpass',
            ],
        ];
    }

    public function testReadsNamedConnection(): void
    {
        $connection = Config::fromArray($this->parsed())->connection('prod');

        self::assertSame('db1.example.invalid', $connection->host);
        self::assertSame('readonly_user', $connection->user);
        self::assertSame('secret', $connection->password);
        self::assertSame(3307, $connection->port);
    }

    public function testPortDefaultsTo3306(): void
    {
        self::assertSame(3306, Config::fromArray($this->parsed())->connection('dev')->port);
    }

    public function testSyncSectionIsNotAConnection(): void
    {
        self::assertSame(['prod', 'dev'], Config::fromArray($this->parsed())->connectionNames());
    }

    public function testUnknownConnectionNamesTheAvailableOnes(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('prod, dev');

        Config::fromArray($this->parsed())->connection('staging');
    }

    public function testThresholdIsConvertedToBytes(): void
    {
        self::assertSame(10 * 1024 * 1024, Config::fromArray($this->parsed())->sync()->thresholdBytes);
    }

    public function testListsAreTrimmedAndLowercased(): void
    {
        $sync = Config::fromArray($this->parsed())->sync();

        self::assertSame(['mysql', 'information_schema'], $sync->excludeDatabases);
        self::assertSame(['shop.categories', 'shop.tags'], $sync->forceFullTables);
    }

    public function testEmptyListYieldsNoEntries(): void
    {
        $parsed = $this->parsed();
        $parsed['sync']['force_full_tables'] = '';

        self::assertSame([], Config::fromArray($parsed)->sync()->forceFullTables);
    }

    public function testSyncDefaultsApplyWhenSectionIsAbsent(): void
    {
        $parsed = $this->parsed();
        unset($parsed['sync']);

        $sync = Config::fromArray($parsed)->sync();

        self::assertSame(10 * 1024 * 1024, $sync->thresholdBytes);
        self::assertSame(0, $sync->subnetRemainder);
        self::assertSame(1000, $sync->batchSize);
        self::assertSame(
            ['mysql', 'information_schema', 'performance_schema', 'sys'],
            $sync->excludeDatabases,
        );
    }

    public function testConnectionMissingHostIsRejected(): void
    {
        $parsed = $this->parsed();
        unset($parsed['dev']['host']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('dev');

        Config::fromArray($parsed)->connection('dev');
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('no-such-file.ini');

        Config::load('no-such-file.ini');
    }
}
