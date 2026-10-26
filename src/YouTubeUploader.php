<?php
declare(strict_types=1);

namespace ShortsFactory;

/**
 * Этап upload. Порядок: тестовый режим -> подтверждение администратором -> повторная проверка файла ->
 * загрузка по протоколу resumable (куски кратны 256 КБ, сессия хранится в БД) -> проверка результата.
 * После сбоя повторная загрузка вслепую не запускается: сначала спрашиваем статус сессии, затем ищем видео на канале.
 */
final class YouTubeUploader implements StageHandler
{
    private const API = 'https://www.googleapis.com';

    public function __construct(
        private Database $db,
        private Config $config,
        private HttpClient $http,
        private YouTubeAuth $auth,
        private VideoValidator $validator,
        private TelegramReporter $reporter,
        private TopicHistory $history,
        private Logger $log,
    ) {}

    public function type(): string
    {
        return 'upload';
    }

    public function run(array $video): ?string
    {
        $videoId = (int)$video['id'];
        if (!empty($video['youtube_video_id'])) {
            return 'PUBLISHED';   // защита от повторной публикации
        }
        $meta = json_decode((string)$video['meta_json'], true) ?: [];

        if (!$this->config->isLive()) {
            if (empty($meta['previewed'])) {
                $this->reporter->sendApproval($video, false);
                $meta['previewed'] = true;
                $this->db->exec('UPDATE videos SET meta_json = ? WHERE id = ?', [json_encode($meta, JSON_UNESCAPED_UNICODE), $videoId]);
            }
            $this->log->info('upload', 'тестовый режим: на YouTube не загружаем', ['video_id' => $videoId]);
            return 'VIDEO_READY';
        }

        if ($this->config->get('publish_mode', 'approve') === 'approve') {
            $this->gate($video, $meta);
        }

        $check = $this->validator->validate($video);
        if ($check['file'] !== [] || $check['content'] !== []) {
            throw new PermanentError('Публикация запрещена: ' . implode('; ', array_merge($check['file'], $check['content'])));
        }

        $started = microtime(true);
        $soft = (int)$this->config->get('youtube.soft_deadline', 40);
        $token = $this->auth->accessToken();
        $path = (string)$video['video_path'];
        $size = filesize($path);
        $privacy = (string)$this->config->get('youtube.privacy', 'public');

        $session = (string)($video['upload_session'] ?? '');
        if ($session === '') {
            if (!empty($video['upload_started_at'])) {
                $dup = $this->findOnChannel($token, (string)$video['title'], (string)$video['upload_started_at']);
                if ($dup !== null) {
                    return $this->finish($video, $dup, $privacy);
                }
            }
            $session = $this->startSession($token, $video, $size, $privacy);
        }

        $offset = 0;
        $done = null;
        while ($done === null) {
            [$state, $val] = $this->status($session, $size, $token);
            if ($state === 'done') {
                $done = $val;
                break;
            }
            if ($state === 'expired') {
                $this->db->exec('UPDATE videos SET upload_session = NULL WHERE id = ?', [$videoId]);
                $dup = $this->findOnChannel($token, (string)$video['title'], (string)$video['upload_started_at']);
                if ($dup !== null) {
                    return $this->finish($video, $dup, $privacy);
                }
                throw new TransientError('Сессия загрузки истекла, начнём заново');
            }
            $offset = (int)$val;
            if (microtime(true) - $started > $soft) {
                throw new Deferred(0, "загрузка продолжится с байта $offset из $size");
            }
            $done = $this->sendChunk($session, $path, $offset, $size);
        }
        return $this->finish($video, $done, $privacy);
    }

    /** Подтверждение: none -> запросить, pending -> ждать, rejected -> стоп. */
    private function gate(array $video, array $meta): void
    {
        $videoId = (int)$video['id'];
        switch ($video['approval']) {
            case 'approved':
                return;
            case 'rejected':
                throw new PermanentError('Публикация отклонена администратором');
            case 'none':
                if (!$this->reporter->sendApproval($video)) {
                    throw new TransientError('Не удалось отправить ролик на подтверждение в Telegram');
                }
                $meta['approval_requested_at'] = Clock::now();
                $this->db->exec("UPDATE videos SET approval = 'pending', meta_json = ?, updated_at = ? WHERE id = ?",
                    [json_encode($meta, JSON_UNESCAPED_UNICODE), Clock::now(), $videoId]);
                throw new Deferred(120, 'ролик отправлен на подтверждение');
            default:
                $since = strtotime(($meta['approval_requested_at'] ?? Clock::now()) . ' UTC');
                if ($since < Clock::ts() - 48 * 3600) {
                    throw new PermanentError('Ролик не подтверждён за 48 часов. Команда /retry вернёт его на подтверждение.');
                }
                throw new Deferred(120, 'ждём подтверждения');
        }
    }

    private function startSession(string $token, array $video, int $size, string $privacy): string
    {
        $meta = json_decode((string)$video['meta_json'], true) ?: [];
        $body = [
            'snippet' => [
                'title' => self::cleanTitle((string)$video['title']),
                'description' => $this->description($video, $meta),
                'tags' => array_slice(array_merge(['shorts'], array_map(fn($h) => ltrim($h, '#'), $meta['hashtags'] ?? [])), 0, 8),
                'categoryId' => (string)$this->config->get('youtube.category_id', '27'),
                'defaultLanguage' => (string)$this->config->get('youtube.language', 'ru'),
                'defaultAudioLanguage' => (string)$this->config->get('youtube.language', 'ru'),
            ],
            'status' => [
                'privacyStatus' => $privacy,
                'selfDeclaredMadeForKids' => false,
                'containsSyntheticMedia' => (bool)$this->config->get('youtube.synthetic', true),
            ],
        ];
        $res = $this->http->request('POST', self::API . '/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
            'Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=UTF-8',
            "X-Upload-Content-Length: $size", 'X-Upload-Content-Type: video/mp4',
        ], json_encode($body, JSON_UNESCAPED_UNICODE));
        HttpClient::assertOk($res);
        $loc = $res['headers']['location'] ?? '';
        if ($loc === '') {
            throw new TransientError('YouTube не вернул адрес сессии загрузки');
        }
        $this->db->exec('UPDATE videos SET upload_session = ?, upload_started_at = ?, updated_at = ? WHERE id = ?',
            [$loc, Clock::now(), Clock::now(), $video['id']]);
        $this->log->info('upload', 'сессия загрузки создана', ['video_id' => $video['id']]);
        return $loc;
    }

    /**
     * Спросить у YouTube, сколько байт получено (пустой PUT с Content-Range: bytes * /размер).
     * @return array{0:string,1:mixed} ['done', ресурс видео] | ['resume', смещение] | ['expired', null]
     */
    private function status(string $session, int $size, string $token): array
    {
        $res = $this->http->request('PUT', $session, ['Authorization: Bearer ' . $token, 'Content-Length: 0', "Content-Range: bytes */$size"], '');
        if ($res['status'] === 200 || $res['status'] === 201) {
            return ['done', $this->videoResource($res)];
        }
        if ($res['status'] === 308) {
            return ['resume', isset($res['headers']['range']) && preg_match('/bytes=0-(\d+)/', $res['headers']['range'], $m) ? (int)$m[1] + 1 : 0];
        }
        if ($res['status'] === 404 || $res['status'] === 410) {
            return ['expired', null];
        }
        HttpClient::assertOk($res);
        throw new TransientError('Неожиданный ответ YouTube при проверке загрузки: HTTP ' . $res['status']);
    }

    /** Отправить следующий кусок. Возвращает ресурс видео, когда загрузка завершена, иначе null. */
    private function sendChunk(string $session, string $path, int $offset, int $size): ?array
    {
        $chunk = max(262144, (int)(floor((int)$this->config->get('youtube.chunk_bytes', 8 * 1048576) / 262144) * 262144));   // кратно 256 КБ
        $len = min($chunk, $size - $offset);
        $fh = fopen($path, 'rb');
        fseek($fh, $offset);
        $data = (string)fread($fh, $len);
        fclose($fh);
        if (strlen($data) !== $len) {
            throw new PermanentError('Не удалось прочитать видеофайл');
        }
        $res = $this->http->request('PUT', $session, [
            'Authorization: Bearer ' . $this->auth->accessToken(), 'Content-Type: video/mp4',
            sprintf('Content-Range: bytes %d-%d/%d', $offset, $offset + $len - 1, $size),
        ], $data, 300);
        if ($res['status'] === 200 || $res['status'] === 201) {
            return $this->videoResource($res);
        }
        if ($res['status'] === 308) {
            return null;
        }
        HttpClient::assertOk($res);
        throw new TransientError('Неожиданный ответ YouTube при загрузке: HTTP ' . $res['status']);
    }

    /** @return array<string,mixed> */
    private function videoResource(array $res): array
    {
        $d = json_decode($res['body'], true);
        if (!is_array($d) || empty($d['id'])) {
            throw new TransientError('YouTube завершил загрузку, но ответ без id видео');
        }
        return $d;
    }

    /** Видео с таким заголовком, загруженное на канал после $since (защита от дубля после сбоя). */
    private function findOnChannel(string $token, string $title, string $since): ?array
    {
        $h = ['Authorization: Bearer ' . $token];
        $ch = $this->http->json('GET', self::API . '/youtube/v3/channels?part=contentDetails&mine=true', $h);
        $uploads = $ch['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
        if ($uploads === null) {
            return null;
        }
        $pl = $this->http->json('GET', self::API . '/youtube/v3/playlistItems?' . http_build_query(['part' => 'snippet', 'playlistId' => $uploads, 'maxResults' => 10]), $h);
        $from = strtotime($since . ' UTC') - 300;
        foreach ($pl['items'] ?? [] as $it) {
            $s = $it['snippet'] ?? [];
            if (($s['title'] ?? '') === self::cleanTitle($title) && strtotime((string)($s['publishedAt'] ?? '1970-01-01')) >= $from) {
                return ['id' => $s['resourceId']['videoId'] ?? ''];
            }
        }
        return null;
    }

    /** Сохранить результат, проверить реальный режим доступа, отчитаться. */
    private function finish(array $video, array $resource, string $privacy): string
    {
        $id = (string)$resource['id'];
        $url = "https://www.youtube.com/shorts/$id";
        $this->db->exec(
            'UPDATE videos SET youtube_video_id = ?, youtube_url = ?, published_at = ?, upload_session = NULL, status = ?, updated_at = ? WHERE id = ?',
            [$id, $url, Clock::now(), 'PUBLISHED', Clock::now(), $video['id']]
        );
        $this->history->markPublished((int)$video['id']);

        $warn = '';
        try {
            $st = $this->http->json('GET', self::API . '/youtube/v3/videos?part=status&id=' . urlencode($id), ['Authorization: Bearer ' . $this->auth->accessToken()]);
            $actual = $st['items'][0]['status']['privacyStatus'] ?? null;
            if ($actual === null) {
                $warn = "\n⚠️ Не удалось подтвердить видео на канале, проверьте YouTube Studio.";
            } elseif ($actual !== $privacy) {
                $warn = "\n⚠️ Запрошен режим «{$privacy}», но YouTube установил «{$actual}». Для непроверенных API-проектов это ожидаемо: видео нужно открыть вручную в Studio или пройти аудит проекта.";
            }
        } catch (\Throwable $e) {
            $warn = "\n⚠️ Проверка статуса не удалась: " . $e->getMessage();
        }

        $row = $this->db->one('SELECT * FROM videos WHERE id = ?', [$video['id']]);
        $attempts = (int)$this->db->one('SELECT SUM(attempts) a FROM tasks WHERE video_id = ?', [$video['id']])['a'];
        $cost = (float)$this->db->one('SELECT COALESCE(SUM(cost_estimate), 0) c FROM logs WHERE video_id = ?', [$video['id']])['c'];
        $minutes = max(0, (strtotime($row['published_at'] . ' UTC') - strtotime($row['created_at'] . ' UTC')) / 60);
        $this->reporter->send(sprintf("✅ Опубликовано: %s\nТема: %s\n%s\nДлительность: %.0f с\nВремя производства: %.0f мин\nПопыток всего: %d\nСтоимость API (оценка): $%.3f%s",
            $row['title'], $row['topic'], $url, (float)$row['video_duration'], $minutes, $attempts, $cost, $warn));
        $this->log->info('upload', "опубликовано: $url", ['video_id' => $video['id']]);
        return 'PUBLISHED';
    }

    public static function cleanTitle(string $t): string
    {
        $t = trim(str_replace(['<', '>'], '', $t));
        return mb_strlen($t) > 100 ? rtrim(mb_substr($t, 0, 99)) . '…' : $t;
    }

    private function description(array $video, array $meta): string
    {
        $parts = [trim((string)$video['description'])];
        $src = json_decode((string)$video['sources_json'], true) ?: [];
        if ($src !== []) {
            $parts[] = "Источники:\n" . implode("\n", array_map(fn($s) => '- ' . $s['url'], $src));
        }
        $authors = [];
        $site = 'Pexels';
        foreach ($this->db->all('SELECT license_info FROM assets WHERE video_id = ?', [$video['id']]) as $a) {
            if (preg_match('/^(Pexels|Pixabay) License; автор: (.+)$/u', (string)$a['license_info'], $m)) {
                $authors[$m[2]] = true;
                $site = $m[1];
            }
        }
        if ($authors !== []) {
            $parts[] = 'Видео и фото: ' . $site . ' (' . implode(', ', array_keys($authors)) . ')';
        }
        $parts[] = 'Озвучка создана с помощью ИИ.';
        $parts[] = trim(implode(' ', $meta['hashtags'] ?? []) . ' #Shorts');
        return mb_substr(implode("\n\n", array_filter($parts)), 0, 4900);
    }
}
