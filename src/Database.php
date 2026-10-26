<?php
declare(strict_types=1);

namespace ShortsFactory;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(string $file)
    {
        $dir = dirname($file);
        if ($file !== ':memory:' && !is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create database directory: $dir");
        }
        $this->pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 10000');
        if ($file !== ':memory:') {
            // WAL may be unsupported on some network filesystems; fall back silently.
            $this->pdo->query('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function exec(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function lastId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    /** Short transaction. Never perform HTTP calls inside $fn. */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn($this);
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    /** Apply pending migrations/NNN_*.sql in order. @return list<string> applied names */
    public function migrate(string $dir): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $done = array_column($this->all('SELECT name FROM schema_migrations'), 'name');
        $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $sql = file_get_contents($file);
            $this->transaction(function () use ($sql, $name) {
                $this->pdo->exec($sql);
                $this->exec('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)', [$name, Clock::now()]);
            });
            $applied[] = $name;
        }
        return $applied;
    }

    /** Consistent online backup via VACUUM INTO. */
    public function backup(string $target): void
    {
        if (is_file($target)) {
            unlink($target);
        }
        $this->pdo->exec('VACUUM INTO ' . $this->pdo->quote($target));
    }
}
