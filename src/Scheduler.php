<?php
declare(strict_types=1);

namespace ShortsFactory;

final class Scheduler
{
    public function __construct(
        private Database $db,
        private Settings $settings,
        private Queue $queue,
        private Logger $log,
    ) {}

    /**
     * Create today's video + first task. Idempotent: scheduled_date is UNIQUE.
     * @return array{created:bool, reason:string, video_id:?int}
     */
    public function ensureDailyVideo(bool $force = false): array
    {
        if ($this->settings->isPaused()) {
            return ['created' => false, 'reason' => 'paused', 'video_id' => null];
        }
        $tz = $this->settings->get('timezone', 'Asia/Almaty');
        $date = Clock::today($tz);
        if (!$force && Clock::hour($tz) < $this->settings->int('publish_hour', 12)) {
            return ['created' => false, 'reason' => 'before publish_hour', 'video_id' => null];
        }

        // daily_publish_limit is 1 in the MVP: UNIQUE(scheduled_date) enforces one video per day.
        $existing = $this->db->one('SELECT id FROM videos WHERE scheduled_date = ?', [$date]);
        if ($existing !== null) {
            return ['created' => false, 'reason' => 'already exists', 'video_id' => (int)($existing['id'] ?? 0)];
        }

        $id = $this->db->transaction(function (Database $db) use ($date) {
            $now = Clock::now();
            $db->exec(
                "INSERT INTO videos (scheduled_date, status, created_at, updated_at) VALUES (?, 'NEW', ?, ?)",
                [$date, $now, $now]
            );
            $id = $db->lastId();
            $this->queue->enqueue($id, 'script');
            return $id;
        });
        $this->settings->set('last_daily_run', Clock::now());
        $this->log->info('scheduler', "created video #$id for $date", ['video_id' => $id]);
        return ['created' => true, 'reason' => 'ok', 'video_id' => $id];
    }
}
