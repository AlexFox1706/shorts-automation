<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Telegram Bot API (https://core.telegram.org/bots/api). Токен в адресе маскируется логгером. */
final class TelegramClient
{
    /** @var callable|null */
    private $multipart;

    public function __construct(private HttpClient $http, private string $token, ?callable $multipart = null)
    {
        $this->multipart = $multipart;
    }

    public function configured(): bool
    {
        return $this->token !== '';
    }

    /** @return mixed result */
    public function call(string $method, array $params = [], int $timeout = 30): mixed
    {
        if ($this->token === '') {
            throw new PermanentError('Не задан telegram.bot_token');
        }
        $res = $this->http->request('POST', "https://api.telegram.org/bot{$this->token}/{$method}",
            ['Content-Type: application/json'], json_encode($params, JSON_UNESCAPED_UNICODE), $timeout);
        $d = json_decode($res['body'], true);
        if (is_array($d) && ($d['ok'] ?? false) === true) {
            return $d['result'] ?? null;
        }
        $desc = is_array($d) ? (string)($d['description'] ?? '') : mb_substr($res['body'], 0, 200);
        if ($res['status'] === 0 || $res['status'] === 429 || $res['status'] >= 500) {
            throw new TransientError("Telegram $method: " . ($desc ?: $res['error']));
        }
        throw new PermanentError("Telegram $method: HTTP {$res['status']} $desc");
    }

    public function sendMessage(int|string $chat, string $text, ?array $keyboard = null): void
    {
        $p = ['chat_id' => $chat, 'text' => mb_substr($text, 0, 4000), 'disable_web_page_preview' => true];
        if ($keyboard !== null) {
            $p['reply_markup'] = ['inline_keyboard' => $keyboard];
        }
        $this->call('sendMessage', $p);
    }

    public function sendVideo(int|string $chat, string $path, string $caption, ?array $keyboard = null): void
    {
        $fields = ['chat_id' => (string)$chat, 'caption' => mb_substr($caption, 0, 1000), 'supports_streaming' => 'true',
            'width' => '1080', 'height' => '1920'];
        if ($keyboard !== null) {
            $fields['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
        }
        if ($this->multipart !== null) {
            ($this->multipart)('sendVideo', $fields, $path);
            return;
        }
        $ch = curl_init("https://api.telegram.org/bot{$this->token}/sendVideo");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS => $fields + ['video' => new \CURLFile($path, 'video/mp4', 'preview.mp4')]]);
        $out = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $d = is_string($out) ? json_decode($out, true) : null;
        if (!is_array($d) || !($d['ok'] ?? false)) {
            $msg = 'Telegram sendVideo: ' . ($d['description'] ?? $err ?: "HTTP $status");
            throw ($status === 0 || $status >= 500 || $status === 429) ? new TransientError($msg) : new PermanentError($msg);
        }
    }
}
