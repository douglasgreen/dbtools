<?php

declare(strict_types=1);

namespace DouglasGreen\DbTools\Db;

use DouglasGreen\DbTools\Config\ConnectionConfig;
use DouglasGreen\DbTools\Exception\ConnectionException;
use DouglasGreen\DbTools\Sql;
use PDO;
use PDOException;

final class Connection
{
    /**
     * Driver error codes that mean the server closed the link. 1153 is
     * ER_NET_PACKET_TOO_LARGE: the server aborts the connection rather than
     * reading an oversized packet, so every later statement on the same handle
     * reports 2006 "MySQL server has gone away" instead of the real cause.
     */
    private const LOST_CONNECTION_CODES = [
        1153, // ER_NET_PACKET_TOO_LARGE
        2006, // CR_SERVER_GONE_ERROR
        2013, // CR_SERVER_LOST
        2055, // CR_SERVER_LOST_EXTENDED
    ];

    private ?int $maxAllowedPacket = null;

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $name,
    ) {
    }

    /**
     * True when the exception means the link is dead, so nothing further can be
     * run on this connection.
     */
    public static function isConnectionLost(PDOException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;

        return is_int($code) && in_array($code, self::LOST_CONNECTION_CODES, true);
    }

    /**
     * Driver-level detail PDOException::getMessage() alone does not always
     * carry: SQLSTATE, the MySQL error number, and the server's own text.
     */
    public static function describeError(PDOException $e): string
    {
        $info = $e->errorInfo ?? [];

        return sprintf(
            'SQLSTATE=%s driver_code=%s driver_message=%s',
            (string) ($info[0] ?? $e->getCode()),
            isset($info[1]) ? (string) $info[1] : '-',
            (string) ($info[2] ?? $e->getMessage()),
        );
    }

    public static function dsn(ConnectionConfig $config, ?string $database): string
    {
        $dsn = sprintf('mysql:host=%s;port=%d', $config->host, $config->port);

        return $database === null ? $dsn : $dsn . ';dbname=' . $database;
    }

    public static function open(
        ConnectionConfig $config,
        ?string $database = null,
        bool $unbuffered = false,
    ): self {
        try {
            $pdo = new PDO(
                self::dsn($config, $database),
                $config->user,
                $config->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => ! $unbuffered,
                ],
            );
        } catch (PDOException $e) {
            throw new ConnectionException(sprintf(
                'Cannot connect to "%s" (%s:%d): %s',
                $config->name,
                $config->host,
                $config->port,
                $e->getMessage(),
            ), 0, $e);
        }

        return new self($pdo, $config->name);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Binary transfer, UTC timestamps, and literal zero auto-increment values
     * survive the round trip. Constraint checks are disabled on the target only,
     * so tables can load in any order and the prune pass can delete freely.
     */
    public function applySessionDefaults(bool $isTarget): void
    {
        $this->pdo->exec('SET NAMES binary');
        $this->pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $this->pdo->exec("SET SESSION time_zone = '+00:00'");

        if ($isTarget) {
            $this->pdo->exec('SET SESSION foreign_key_checks = 0');
            $this->pdo->exec('SET SESSION unique_checks = 0');
        }
    }

    public function restoreChecks(): void
    {
        $this->pdo->exec('SET SESSION foreign_key_checks = 1');
        $this->pdo->exec('SET SESSION unique_checks = 1');
    }

    public function useDatabase(string $database): void
    {
        $this->pdo->exec('USE ' . Sql::quoteIdentifier($database));
    }

    public function maxAllowedPacket(): int
    {
        if ($this->maxAllowedPacket !== null) {
            return $this->maxAllowedPacket;
        }

        $rows = $this->fetchAll("SHOW VARIABLES LIKE 'max_allowed_packet'");
        $value = isset($rows[0]['Value']) ? (int) $rows[0]['Value'] : 0;

        return $this->maxAllowedPacket = $value > 0 ? $value : 1048576;
    }

    public function serverVersion(): string
    {
        $version = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        return is_string($version) ? $version : 'unknown';
    }

    /**
     * Cheap round trip used to tell "the link died" apart from "the statement
     * was rejected" when a copy fails.
     */
    public function isAlive(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException) {
            return false;
        }

        return true;
    }

    public function exec(string $sql): int
    {
        return (int) $this->pdo->exec($sql);
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $rows;
    }
}
