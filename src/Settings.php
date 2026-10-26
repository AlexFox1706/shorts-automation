<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Operational settings stored in SQLite (changeable from Telegram without editing files). */
final class Settings
{
    public function __construct(private Database $db) {}

    public function get(string $key, string $default = ''): string
    {
        $row = $this->db->one('SELECT value FROM settings WHERE key = ?', [$key]);
        return $row === null ? $default : (string)$row['value'];
    }

    public function set(string $key, string $value): void
    {
        $this->db->exec(
            'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [$key, $value, Clock::now()]
        );
    }

    public function int(string $key, int $default = 0): int
    {
        return (int)$this->get($key, (string)$default);
    }

    public function isPaused(): bool
    {
        return $this->get('paused', '0') === '1';
    }
}
