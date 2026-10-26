<?php
declare(strict_types=1);

namespace ShortsFactory;

/**
 * Команды и кнопки через getUpdates (без webhook): опрос выполняется при каждом запуске tick.
 * Любое сообщение не от администратора игнорируется.
 */
final class TelegramBot
{
    public function __construct(
        private TelegramClient $tg,
        private int $adminId,
        private Database $db,
        private Settings $settings,
        private Queue $queue,
        private Pipeline $pipeline,
        private Logger $log,
    ) {}

    /** Обработать накопившиеся обновления. @return int сколько обработано */
    public function poll(): int
    {
        if (!$this->tg->configured() || $this->adminId <= 0) {
            return 0;
        }
        $offset = $this->settings->int('telegram_offset', 0);
        try {
            $updates = $this->tg->call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'limit' => 50, 'allowed_updates' => ['message', 'callback_query']]);
        } catch (PermanentError $e) {
            if (str_contains($e->getMessage(), 'webhook')) {
                $this->tg->call('deleteWebhook');   // опрос и webhook несовместимы
                return 0;
            }
            throw $e;
        }
        $n = 0;
        foreach ((array)$updates as $u) {
            $this->settings->set('telegram_offset', (string)((int)$u['update_id'] + 1));
            try {
                $this->handle($u);
            } catch (\Throwable $e) {
                $this->log->error('telegram', 'ошибка обработки команды: ' . $e->getMessage());
            }
            $n++;
        }
        return $n;
    }

    public function handle(array $u): void
    {
        if (isset($u['callback_query'])) {
            $cb = $u['callback_query'];
            if ((int)($cb['from']['id'] ?? 0) !== $this->adminId) {
                $this->log->warning('telegram', 'кнопка от постороннего пользователя проигнорирована');
                return;
            }
            $this->callback($cb);
            return;
        }
        $m = $u['message'] ?? null;
        if ($m === null || (int)($m['from']['id'] ?? 0) !== $this->adminId) {
            if ($m !== null) {
                $this->log->warning('telegram', 'сообщение от постороннего пользователя проигнорировано');
            }
            return;
        }
        $text = trim((string)($m['text'] ?? ''));
        $cmd = strtolower(explode('@', explode(' ', $text)[0])[0]);
        $chat = (int)$m['chat']['id'];
        $reply = match ($cmd) {
            '/start', '/help' => $this->help(),
            '/status' => $this->status(),
            '/last' => $this->last(),
            '/history' => $this->history(),
            '/retry' => $this->retry(),
            '/pause' => $this->pause(true),
            '/resume' => $this->pause(false),
            default => $text === '' ? null : 'Не знаю такой команды. Список: /help',
        };
        if ($reply !== null) {
            $this->tg->sendMessage($chat, $reply);
        }
    }

    private function callback(array $cb): void
    {
        [$action, $id] = array_pad(explode(':', (string)($cb['data'] ?? ''), 2), 2, '0');
        $id = (int)$id;
        $v = $this->db->one('SELECT * FROM videos WHERE id = ?', [$id]);
        $chat = (int)($cb['message']['chat']['id'] ?? $this->adminId);
        $note = 'Ролик не найден';
        if ($v !== null) {
            $note = match ($action) {
                'ok' => $this->approve($v),
                'no' => $this->reject($v),
                're' => $this->regenerate($v),
                default => 'Неизвестная кнопка',
            };
        }
        $this->tg->call('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => mb_substr($note, 0, 150)]);
        if (isset($cb['message']['message_id'])) {
            try {
                $this->tg->call('editMessageReplyMarkup', ['chat_id' => $chat, 'message_id' => $cb['message']['message_id'], 'reply_markup' => ['inline_keyboard' => []]]);
            } catch (\Throwable) {
                // сообщение уже изменено: не критично
            }
        }
        $this->tg->sendMessage($chat, "Выпуск #{$id}: " . $note);
    }

    private function approve(array $v): string
    {
        if ($v['status'] === 'PUBLISHED') {
            return 'уже опубликован';
        }
        if (!in_array($v['approval'], ['none', 'pending'], true)) {
            return 'решение уже принято (' . $v['approval'] . ')';
        }
        $this->db->exec("UPDATE videos SET approval = 'approved', updated_at = ? WHERE id = ?", [Clock::now(), $v['id']]);
        $this->db->exec("UPDATE tasks SET next_run_at = ? WHERE video_id = ? AND task_type = 'upload' AND status = 'PENDING'", [Clock::now(), $v['id']]);
        return 'одобрен, публикую при ближайшем запуске';
    }

    private function reject(array $v): string
    {
        if ($v['status'] === 'PUBLISHED') {
            return 'уже опубликован';
        }
        $this->db->exec("UPDATE videos SET approval = 'rejected', status = 'FAILED', last_error = 'Отклонено администратором', updated_at = ? WHERE id = ?", [Clock::now(), $v['id']]);
        $this->db->exec("UPDATE tasks SET status = 'FAILED', last_error = 'Отклонено администратором' WHERE video_id = ? AND task_type = 'upload'", [$v['id']]);
        $this->db->exec("UPDATE topic_history SET outcome = 'rejected' WHERE video_id = ?", [$v['id']]);
        return 'отклонён, не публикуется';
    }

    private function regenerate(array $v): string
    {
        if ($v['status'] === 'PUBLISHED') {
            return 'уже опубликован';
        }
        return $this->pipeline->regenerate((int)$v['id'], 'Администратор отклонил этот сценарий. Выбери другую тему и другой подход.')
            ? 'запущена генерация другого сценария'
            : 'лимит пересозданий исчерпан';
    }

    private function help(): string
    {
        return "YouTube Shorts Factory. Команды:\n/status - состояние последнего выпуска\n/last - ссылка на последнее опубликованное видео\n"
            . "/history - последние выпуски\n/retry - повторить последний неудачный выпуск\n/pause - не создавать новые выпуски\n/resume - возобновить\n/help - эта справка\n\n"
            . 'Новые ролики приходят сюда с кнопками «Опубликовать / Отклонить / Другой сценарий».';
    }

    private function status(): string
    {
        $v = $this->db->one('SELECT * FROM videos ORDER BY id DESC LIMIT 1');
        $seen = $this->settings->get('runner_last_seen');
        $out = ($this->settings->isPaused() ? "⏸ Создание выпусков приостановлено\n" : "▶️ Работаю\n")
            . 'Сборщик видео на связи: ' . ($seen !== '' ? $seen . ' UTC' : 'ещё не выходил') . "\n";
        if ($v === null) {
            return $out . 'Выпусков пока нет.';
        }
        $out .= sprintf("\nВыпуск #%d (%s): %s\n%s", $v['id'], $v['scheduled_date'], $v['status'], $v['title'] ?: '(сценарий ещё не готов)');
        if ($v['approval'] === 'pending') {
            $out .= "\nЖдёт вашего подтверждения.";
        }
        if ($v['last_error']) {
            $out .= "\nОшибка: " . $v['last_error'];
        }
        foreach ($this->db->all("SELECT task_type, status, attempts, next_run_at FROM tasks WHERE video_id = ? AND status != 'DONE'", [$v['id']]) as $t) {
            $out .= sprintf("\n• %s: %s (попыток %d)", $t['task_type'], $t['status'], $t['attempts']);
        }
        return $out;
    }

    private function last(): string
    {
        $v = $this->db->one("SELECT title, youtube_url FROM videos WHERE youtube_url IS NOT NULL ORDER BY published_at DESC LIMIT 1");
        return $v ? "{$v['title']}\n{$v['youtube_url']}" : 'Опубликованных видео пока нет.';
    }

    private function history(): string
    {
        $rows = $this->db->all('SELECT id, scheduled_date, status, title, youtube_url FROM videos ORDER BY id DESC LIMIT 10');
        if ($rows === []) {
            return 'Выпусков пока нет.';
        }
        return implode("\n", array_map(fn($r) => sprintf('#%d %s %s %s %s', $r['id'], $r['scheduled_date'], $r['status'], $r['title'] ?: '-', $r['youtube_url'] ?? ''), $rows));
    }

    private function retry(): string
    {
        $v = $this->db->one("SELECT id FROM videos WHERE status = 'FAILED' ORDER BY id DESC LIMIT 1");
        if ($v === null) {
            return 'Неудачных выпусков нет.';
        }
        $n = $this->queue->retryVideo((int)$v['id']);
        if ($n === 0) {
            return "У выпуска #{$v['id']} нет задач для повтора. Если его отклонили вручную, дождитесь следующего дня.";
        }
        $this->db->exec("UPDATE videos SET status = 'NEW', approval = 'none', updated_at = ? WHERE id = ?", [Clock::now(), $v['id']]);
        return "Повторяю выпуск #{$v['id']} с места остановки.";
    }

    private function pause(bool $on): string
    {
        $this->settings->set('paused', $on ? '1' : '0');
        return $on ? 'Приостановлено: новые выпуски не создаются.' : 'Возобновлено.';
    }
}
