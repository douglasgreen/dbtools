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
    private function __construct(
        private readonly PDO $pdo,
        private readonly string $name,
    ) {
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
        $rows = $this->fetchAll("SHOW VARIABLES LIKE 'max_allowed_packet'");
        $value = isset($rows[0]['Value']) ? (int) $rows[0]['Value'] : 0;

        return $value > 0 ? $value : 1048576;
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
