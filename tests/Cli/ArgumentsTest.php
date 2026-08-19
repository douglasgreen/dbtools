<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Tests\Cli;

use DouglasGreen\DbTools\Cli\Arguments;
use DouglasGreen\DbTools\Exception\ConfigException;
use PHPUnit\Framework\TestCase;

final class ArgumentsTest extends TestCase
{
    public function testParsesRequiredOptions(): void
    {
        $arguments = Arguments::parse(['dbsync.php', '--source=prod', '--target=dev']);

        self::assertSame('prod', $arguments->source);
        self::assertSame('dev', $arguments->target);
        self::assertNull($arguments->database);
        self::assertFalse($arguments->drop);
        self::assertFalse($arguments->dryRun);
        self::assertSame('config.ini', $arguments->configPath);
        self::assertNull($arguments->thresholdMb);
    }

    public function testParsesOptionalOptionsAndFlags(): void
    {
        $arguments = Arguments::parse([
            'dbsync.php',
            '--source=prod',
            '--target=dev',
            '--database=shop',
            '--drop',
            '--dry-run',
            '--config=/etc/dbtools.ini',
            '--threshold=25',
        ]);

        self::assertSame('shop', $arguments->database);
        self::assertTrue($arguments->drop);
        self::assertTrue($arguments->dryRun);
        self::assertSame('/etc/dbtools.ini', $arguments->configPath);
        self::assertSame(25, $arguments->thresholdMb);
    }

    public function testHelpSkipsRequiredValidation(): void
    {
        $arguments = Arguments::parse(['dbsync.php', '--help']);

        self::assertTrue($arguments->helpRequested);
        self::assertSame('', $arguments->source);
    }

    public function testMissingSourceIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('--source');

        Arguments::parse(['dbsync.php', '--target=dev']);
    }

    public function testMissingTargetIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('--target');

        Arguments::parse(['dbsync.php', '--source=prod']);
    }

    public function testSourceAndTargetMustDiffer(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('same connection');

        Arguments::parse(['dbsync.php', '--source=prod', '--target=prod']);
    }

    public function testUnknownOptionIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('--nonsense');

        Arguments::parse(['dbsync.php', '--source=prod', '--target=dev', '--nonsense']);
    }

    public function testVerboseFlagIsRecognized(): void
    {
        $arguments = Arguments::parse(['dbsync.php', '--source=prod', '--target=dev', '--verbose']);

        self::assertTrue($arguments->verbose);
    }

    public function testVerboseDefaultsOff(): void
    {
        self::assertFalse(Arguments::parse(['dbsync.php', '--source=prod', '--target=dev'])->verbose);
    }

    public function testThresholdMustBePositive(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('--threshold');

        Arguments::parse(['dbsync.php', '--source=prod', '--target=dev', '--threshold=0']);
    }

    public function testUsageMentionsEveryOption(): void
    {
        $usage = Arguments::usage();

        foreach (['--source', '--target', '--database', '--drop', '--config', '--dry-run', '--threshold', '--help'] as $option) {
            self::assertStringContainsString($option, $usage);
        }
    }
}
