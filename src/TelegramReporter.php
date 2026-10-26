<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Уведомления администратору. Сбой Telegram никогда не ломает конвейер: только запись в лог. */
final class TelegramReporter implements Notifier
{
    private const MAX_PREVIEW_BYTES = 49 * 1024 * 1024;   // лимит Bot API на загрузку файла - 50 МБ

    public function __construct(private TelegramClient $tg, private int $adminId, private Logger $log) {}

    public function enabled(): bool
    {
        return $this->tg->configured() && $this->adminId > 0;
    }

    public function send(string $text): void
    {
        $this->log->info('notify', $text);
        if (!$this->enabled()) {
            return;
        }
        try {
            $this->tg->sendMessage($this->adminId, $text);
        } catch (\Throwable $e) {
            $this->log->warning('telegram', 'не удалось отправить сообщение: ' . $e->getMessage());
        }
    }

    /** Ролик на утверждение: видео + кнопки. @param array<string,mixed> $video */
    public function sendApproval(array $video, bool $buttons = true): bool
    {
        if (!$this->enabled()) {
            $this->log->warning('telegram', 'Telegram не настроен: подтверждение невозможно');
            return false;
        }
        $id = (int)$video['id'];
        $text = sprintf("🎬 %s\nТема: %s\nДлительность: %.0f с\nИсточников подтверждено: %d\n\n%s",
            $video['title'], $video['topic'], (float)$video['video_duration'],
            count(json_decode((string)$video['sources_json'], true) ?: []),
            $buttons ? 'Опубликовать на YouTube?' : 'Тестовый режим: на YouTube видео не отправляется.');
        $kb = $buttons ? [[['text' => '✅ Опубликовать', 'callback_data' => "ok:$id"], ['text' => '❌ Отклонить', 'callback_data' => "no:$id"]],
            [['text' => '🔄 Другой сценарий', 'callback_data' => "re:$id"]]] : null;
        try {
            $path = (string)$video['video_path'];
            if (is_file($path) && filesize($path) <= self::MAX_PREVIEW_BYTES) {
                $this->tg->sendVideo($this->adminId, $path, $text, $kb);
            } else {
                $this->tg->sendMessage($this->adminId, $text . "\n(файл слишком большой для предпросмотра в Telegram)", $kb);
            }
            return true;
        } catch (\Throwable $e) {
            $this->log->error('telegram', 'не удалось отправить ролик на подтверждение: ' . $e->getMessage(), ['video_id' => $id]);
            return false;
        }
    }
}
