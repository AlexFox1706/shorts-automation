<?php
declare(strict_types=1);

namespace ShortsFactory;

/**
 * Runs queue tasks through the fixed stage chain. State lives in SQLite, so a crashed
 * run resumes from the last unfinished stage instead of starting over.
 */
final class Pipeline
{
    /**
     * type => [status while running (or null), status when done (or null), next task type (or null)]
     * FACT_CHECKING has no "done" status in the spec, the video stays in it until assets are ready.
     */
    public const STAGES = [
        'script'    => ['SCRIPT_GENERATING', 'SCRIPT_READY', 'factcheck'],
        'factcheck' => ['FACT_CHECKING',     null,           'assets'],
        'assets'    => [null,                'ASSETS_READY', 'voice'],
        'voice'     => [null,                'VOICE_READY',  'render'],
        'render'    => ['VIDEO_RENDERING',   'VIDEO_READY',  'upload'],
        'upload'    => ['UPLOADING',         'PUBLISHED',    null],
    ];

    /** @var array<string,StageHandler> */
    private array $handlers = [];

    public function __construct(
        private Database $db,
        private Queue $queue,
        private Logger $log,
        private Notifier $notifier,
        private int $scriptAttemptsLimit = 3,
    ) {}

    public function setNotifier(Notifier $n): void
    {
        $this->notifier = $n;
    }

    public function register(StageHandler $h): void
    {
        if (!isset(self::STAGES[$h->type()])) {
            throw new \InvalidArgumentException('Unknown stage: ' . $h->type());
        }
        $this->handlers[$h->type()] = $h;
    }

    /** @return list<string> */
    public function supportedTypes(): array
    {
        return array_keys($this->handlers);
    }

    /** Process at most one task. Returns false if nothing was runnable. */
    public function runOne(): bool
    {
        $this->queue->recoverStale();
        $task = $this->queue->claim($this->supportedTypes());
        if ($task === null) {
            return false;
        }
        $taskId = (int)$task['id'];
        $videoId = (int)$task['video_id'];
        $type = (string)$task['task_type'];
        $ctx = ['video_id' => $videoId, 'task_id' => $taskId];
        $started = microtime(true);

        $video = $this->db->one('SELECT * FROM videos WHERE id = ?', [$videoId]);
        if ($video === null) {
            $this->queue->fail($taskId, 'video row missing', true);
            return true;
        }
        [$running, $done, $next] = self::STAGES[$type];
        if ($running !== null) {
            $this->setStatus($videoId, $running);
        }
        $this->log->info($type, "start attempt {$task['attempts']}", $ctx);

        try {
            $override = $this->handlers[$type]->run($video);
        } catch (Deferred $d) {
            $this->queue->defer($taskId, $d->seconds);
            if ($d->getMessage() !== '') {
                $this->log->info($type, 'wait: ' . $d->getMessage(), $ctx);
            }
            return true;
        } catch (RegenerateScript $r) {
            $this->log->warning($type, 'regenerate script: ' . $r->getMessage(), $ctx);
            if (!$this->regenerate($videoId, $r->getMessage())) {
                $this->failVideo($task, 'Сценарий не удалось довести до публикации: ' . $r->getMessage());
            }
            return true;
        } catch (\Throwable $e) {
            $this->onFailure($task, $e, $ctx);
            return true;
        }

        $this->queue->complete($taskId);
        $final = is_string($override) ? $override : $done;
        if ($final !== null) {
            $this->setStatus($videoId, $final);
        }
        if ($next !== null) {
            $this->queue->enqueue($videoId, $next);
        }
        $this->log->info($type, 'done', $ctx + ['duration_ms' => (int)((microtime(true) - $started) * 1000)]);
        return true;
    }

    private function onFailure(array $task, \Throwable $e, array $ctx): void
    {
        $permanent = $e instanceof PermanentError || !($e instanceof TransientError);
        // Unknown exceptions are treated as permanent: retrying a bug hides it.
        $outcome = $this->queue->fail((int)$task['id'], $e->getMessage(), $permanent);
        $this->log->error((string)$task['task_type'], get_class($e) . ': ' . $e->getMessage() . " ($outcome)", $ctx);

        if ($outcome === 'failed') {
            $this->failVideo($task, $e->getMessage());
        }
    }

    private function failVideo(array $task, string $message): void
    {
        $this->db->exec(
            "UPDATE videos SET status = 'FAILED', last_error = ?, updated_at = ? WHERE id = ?",
            [mb_substr($message, 0, 1000), Clock::now(), $task['video_id']]
        );
        $this->db->exec("UPDATE tasks SET status = 'FAILED', locked_at = NULL, last_error = ? WHERE id = ?", [mb_substr($message, 0, 1000), $task['id']]);
        $this->notifier->send(sprintf(
            "❗ Выпуск #%d: сбой на этапе %s после %d попыток.\n%s\nЗадача #%d. Повтор: команда /retry",
            $task['video_id'], $task['task_type'], $task['attempts'], $message, $task['id']
        ));
    }

    /**
     * Начать выпуск заново с генерации сценария (после неудачной проверки, неподходящей длительности
     * или решения администратора). Возвращает false, если лимит пересозданий исчерпан.
     */
    public function regenerate(int $videoId, string $reason): bool
    {
        return (bool)$this->db->transaction(function (Database $db) use ($videoId, $reason) {
            $v = $db->one('SELECT script_attempts FROM videos WHERE id = ?', [$videoId]);
            if ($v === null) {
                return false;
            }
            $n = (int)$v['script_attempts'] + 1;
            if ($n > $this->scriptAttemptsLimit) {
                return false;
            }
            $now = Clock::now();
            $db->exec("DELETE FROM tasks WHERE video_id = ? AND task_type != 'script'", [$videoId]);
            $db->exec("DELETE FROM assets WHERE video_id = ?", [$videoId]);
            $db->exec("UPDATE topic_history SET outcome = 'rejected' WHERE video_id = ? AND outcome = 'generated'", [$videoId]);
            $db->exec(
                "UPDATE videos SET script_attempts = ?, regen_hint = ?, status = 'NEW', audio_path = NULL, video_path = NULL,
                 manifest_json = NULL, render_claimed_at = NULL, approval = 'none', upload_session = NULL, last_error = NULL, updated_at = ?
                 WHERE id = ?",
                [$n, mb_substr($reason, 0, 1500), $now, $videoId]
            );
            if ($db->exec("UPDATE tasks SET status = 'PENDING', attempts = 0, locked_at = NULL, last_error = NULL, next_run_at = ?, updated_at = ?
                           WHERE video_id = ? AND task_type = 'script'", [$now, $now, $videoId]) === 0) {
                $this->queue->enqueue($videoId, 'script');
            }
            return true;
        });
    }

    private function setStatus(int $videoId, string $status): void
    {
        $this->db->exec('UPDATE videos SET status = ?, updated_at = ? WHERE id = ?', [$status, Clock::now(), $videoId]);
    }
}
