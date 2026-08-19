<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Report;

/**
 * Progress trace for --verbose runs. Disabled instances are cheap no-ops, so
 * callers can log freely without guarding every call site.
 */
final class DebugLog
{
    /** @var resource */
    private $out;

    private readonly float $startedAt;

    /**
     * @param resource|null $out defaults to STDERR so a verbose run's trace
     *                           stays out of the report on STDOUT
     */
    public function __construct(
        private readonly bool $enabled = false,
        $out = null,
        ?float $startedAt = null,
    ) {
        $this->out = $out ?? STDERR;
        $this->startedAt = $startedAt ?? microtime(true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function log(string $message): void
    {
        if (! $this->enabled) {
            return;
        }

        fwrite($this->out, sprintf('[%8.2fs] %s%s', microtime(true) - $this->startedAt, $message, PHP_EOL));
    }
}
