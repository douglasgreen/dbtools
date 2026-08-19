<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Exception;

use RuntimeException;

/**
 * A copy failed in a way that carries diagnostic context beyond the driver's
 * own message. Raised instead of letting a bare PDOException escape, so the
 * operator sees which table, batch, and packet size were involved.
 */
final class CopyException extends RuntimeException
{
}
