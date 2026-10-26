<?php
declare(strict_types=1);

namespace ShortsFactory;

final class Queue
{
    public function __construct(
        private Database $db,
        private Settings $settings,
        private int $backoffBase = 60,
        private int $backoffMax = 3600,
        private int $staleLock = 1800,
        private int $defaultRetryLimit = 3,
    ) {}

    public function retryLimit(): int
    {
        return max(1, $this->settings->int('retry_limit', $this->defaultRetryLimit));
    }

    /** Idempotent: UNIQUE(video_id, task_type) prevents duplicates. Returns true if created. */
    public function enqueue(int $videoId, string $type, int $delaySeconds = 0): bool
    {
        $now = Clock::now();
        return $this->db->exec(
            'INSERT OR IGNORE INTO tasks (video_id, task_type, status, next_run_at, created_at, updated_at)
             VALUES (?, ?, \'PENDING\', ?, ?, ?)',
            [$videoId, $type, Clock::at($delaySeconds), $now, $now]
        ) > 0;
    }

    /**
     * Reset RUNNING tasks whose lock is older than staleLock. Returns number recovered.
     * The attempt was already counted at claim time, so a crash loop still ends in FAILED.
     */
    public function recoverStale(): int
    {
        $limit = $this->retryLimit();
        return $this->db->transaction(function (Database $db) use ($limit) {
            $cut = Clock::at(-$this->staleLock);
            $db->exec(
                "UPDATE tasks SET status = 'FAILED', last_error = 'stale lock, attempts exhausted', updated_at = ?
                 WHERE status = 'RUNNING' AND locked_at < ? AND attempts >= ?",
                [Clock::now(), $cut, $limit]
            );
            return $db->exec(
                "UPDATE tasks SET status = 'PENDING', locked_at = NULL, last_error = 'recovered from stale lock', updated_at = ?
                 WHERE status = 'RUNNING' AND locked_at < ?",
                [Clock::now(), $cut]
            );
        });
    }

    /**
     * Atomically claim the oldest runnable task of the given types.
     * BEGIN IMMEDIATE takes the write lock, so two workers can never claim the same row.
     *
     * @param list<string> $types
     * @return array<string,mixed>|null
     */
    public function claim(array $types): ?array
    {
        if ($types === []) {
            return null;
        }
        $in = implode(',', array_fill(0, count($types), '?'));
        return $this->db->transaction(function (Database $db) use ($types, $in) {
            $task = $db->one(
                "SELECT * FROM tasks WHERE status = 'PENDING' AND next_run_at <= ? AND task_type IN ($in)
                 ORDER BY next_run_at, id LIMIT 1",
                array_merge([Clock::now()], $types)
            );
            if ($task === null) {
                return null;
            }
            $db->exec(
                "UPDATE tasks SET status = 'RUNNING', locked_at = ?, attempts = attempts + 1, updated_at = ? WHERE id = ?",
                [Clock::now(), Clock::now(), $task['id']]
            );
            $task['status'] = 'RUNNING';
            $task['attempts'] = (int)$task['attempts'] + 1;
            return $task;
        });
    }

    public function complete(int $taskId): void
    {
        $this->db->exec(
            "UPDATE tasks SET status = 'DONE', locked_at = NULL, last_error = NULL, updated_at = ? WHERE id = ?",
            [Clock::now(), $taskId]
        );
    }

    /**
     * Record a failure. Returns 'retry' (rescheduled with backoff) or 'failed' (final).
     * Permanent errors never retry.
     */
    public function fail(int $taskId, string $error, bool $permanent): string
    {
        $task = $this->db->one('SELECT attempts FROM tasks WHERE id = ?', [$taskId]);
        $attempts = (int)($task['attempts'] ?? 0);
        $error = mb_substr($error, 0, 1000);

        if ($permanent || $attempts >= $this->retryLimit()) {
            $this->db->exec(
                "UPDATE tasks SET status = 'FAILED', locked_at = NULL, last_error = ?, updated_at = ? WHERE id = ?",
                [$error, Clock::now(), $taskId]
            );
            return 'failed';
        }
        $delay = min($this->backoffMax, $this->backoffBase * (2 ** max(0, $attempts - 1)));
        $this->db->exec(
            "UPDATE tasks SET status = 'PENDING', locked_at = NULL, last_error = ?, next_run_at = ?, updated_at = ? WHERE id = ?",
            [$error, Clock::at($delay), Clock::now(), $taskId]
        );
        return 'retry';
    }

    /** Не ошибка: подождать и повторить, попытку не тратим. */
    public function defer(int $taskId, int $seconds): void
    {
        $this->db->exec(
            "UPDATE tasks SET status = 'PENDING', attempts = MAX(attempts - 1, 0), locked_at = NULL, next_run_at = ?, updated_at = ? WHERE id = ?",
            [Clock::at($seconds), Clock::now(), $taskId]
        );
    }

    /** /retry: re-open FAILED tasks of a video from where it stopped. */
    public function retryVideo(int $videoId): int
    {
        return $this->db->transaction(function (Database $db) use ($videoId) {
            $n = $db->exec(
                "UPDATE tasks SET status = 'PENDING', attempts = 0, locked_at = NULL, next_run_at = ?, updated_at = ?
                 WHERE video_id = ? AND status = 'FAILED'",
                [Clock::now(), Clock::now(), $videoId]
            );
            if ($n > 0) {
                $db->exec("UPDATE videos SET last_error = NULL, updated_at = ? WHERE id = ?", [Clock::now(), $videoId]);
            }
            return $n;
        });
    }
}
