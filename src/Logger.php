<?php
declare(strict_types=1);

namespace ShortsFactory;

/** File log with rotation + mirrored rows in the logs table. Secrets are masked. */
final class Logger
{
    private const LEVELS = ['INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];

    /** @param list<string> $secrets */
    public function __construct(
        private string $file,
        private ?Database $db = null,
        private array $secrets = [],
        private string $minLevel = 'INFO',
        private int $maxBytes = 2097152,
        private int $keep = 5,
    ) {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }

    public function mask(string $text): string
    {
        foreach ($this->secrets as $s) {
            $text = str_replace($s, '***', $text);
        }
        // Common leak patterns: bot tokens in URLs, bearer tokens, key= query params.
        $text = preg_replace('~bot\d+:[A-Za-z0-9_-]{20,}~', 'bot***', $text) ?? $text;
        $text = preg_replace('~(Bearer\s+)[A-Za-z0-9._-]+~i', '$1***', $text) ?? $text;
        $text = preg_replace('~([?&](?:key|access_token|refresh_token|client_secret)=)[^&\s]+~i', '$1***', $text) ?? $text;
        return $text;
    }

    public function log(string $level, string $stage, string $message, array $ctx = []): void
    {
        $level = strtoupper($level);
        if ((self::LEVELS[$level] ?? 1) < (self::LEVELS[$this->minLevel] ?? 1)) {
            return;
        }
        $message = $this->mask($message);
        $line = sprintf("%s [%s] %s: %s\n", Clock::now() . 'Z', $level, $stage, $message);
        $this->rotateIfNeeded();
        file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);

        if ($this->db !== null) {
            try {
                $this->db->exec(
                    'INSERT INTO logs (video_id, task_id, level, stage, message, duration_ms, cost_estimate, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$ctx['video_id'] ?? null, $ctx['task_id'] ?? null, $level, $stage, $message,
                     $ctx['duration_ms'] ?? null, $ctx['cost'] ?? null, Clock::now()]
                );
            } catch (\Throwable) {
                // Logging must never break the pipeline.
            }
        }
    }

    public function info(string $stage, string $m, array $c = []): void { $this->log('INFO', $stage, $m, $c); }
    public function warning(string $stage, string $m, array $c = []): void { $this->log('WARNING', $stage, $m, $c); }
    public function error(string $stage, string $m, array $c = []): void { $this->log('ERROR', $stage, $m, $c); }

    private function rotateIfNeeded(): void
    {
        clearstatcache(true, $this->file);
        if (!is_file($this->file) || filesize($this->file) < $this->maxBytes) {
            return;
        }
        for ($i = $this->keep - 1; $i >= 1; $i--) {
            if (is_file("{$this->file}.$i")) {
                rename("{$this->file}.$i", "{$this->file}." . ($i + 1));
            }
        }
        rename($this->file, "{$this->file}.1");
        $old = "{$this->file}." . ($this->keep + 1);
        if (is_file($old)) {
            unlink($old);
        }
    }
}
