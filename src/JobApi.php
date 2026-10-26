<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Что хостинг отдаёт раннеру: задание, звук, файлы библиотеки; что принимает: кусочки видео и отчёт. */
final class JobApi
{
    private const CLAIM_TTL = 2400; // 40 минут

    public function __construct(private Database $db, private Config $config, private Settings $settings, private Logger $log) {}

    private function storage(): string
    {
        return (string)$this->config->get('paths.storage');
    }

    /** Есть ли задание (без занятия): для дешёвой проверки перед установкой FFmpeg. */
    public function peek(): bool
    {
        $this->settings->set('runner_last_seen', Clock::now());
        return $this->db->one(
            "SELECT 1 x FROM videos WHERE manifest_json IS NOT NULL AND (video_path IS NULL OR video_path = '')
             AND status IN ('VOICE_READY','VIDEO_RENDERING') AND (render_claimed_at IS NULL OR render_claimed_at < ?) LIMIT 1",
            [Clock::at(-self::CLAIM_TTL)]
        ) !== null;
    }

    /** @return array<string,mixed>|null */
    public function next(): ?array
    {
        $this->settings->set('runner_last_seen', Clock::now());
        return $this->db->transaction(function (Database $db) {
            $v = $db->one(
                "SELECT * FROM videos WHERE manifest_json IS NOT NULL AND (video_path IS NULL OR video_path = '')
                 AND status IN ('VOICE_READY','VIDEO_RENDERING') AND (render_claimed_at IS NULL OR render_claimed_at < ?) ORDER BY id LIMIT 1",
                [Clock::at(-self::CLAIM_TTL)]
            );
            if ($v === null) {
                return null;
            }
            $db->exec('UPDATE videos SET render_claimed_at = ? WHERE id = ?', [Clock::now(), $v['id']]);
            $this->clearParts((int)$v['id']);
            return [
                'id' => (int)$v['id'], 'manifest' => json_decode((string)$v['manifest_json'], true),
                'chunk_bytes' => (int)$this->config->get('video.chunk_bytes', 1572864),
                'max_bytes' => (int)$this->config->get('video.max_bytes', 262144000),
            ];
        });
    }

    public function audioPath(int $id): string
    {
        $v = $this->claimed($id);
        $p = (string)$v['audio_path'];
        if (!is_file($p)) {
            throw new PermanentError('Файл озвучки не найден');
        }
        return $p;
    }

    public function assetPath(int $videoId, int $assetId): string
    {
        $this->claimed($videoId);
        $a = $this->db->one('SELECT local_path FROM assets WHERE id = ? AND video_id = ? AND local_path IS NOT NULL', [$assetId, $videoId]);
        $dir = realpath((string)$this->config->get('assets.library_dir', ''));
        $real = $a ? realpath((string)$a['local_path']) : false;
        if ($dir === false || $real === false || !str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
            throw new PermanentError('Файл библиотеки недоступен');
        }
        return $real;
    }

    public function chunk(int $id, int $index, string $bytes): void
    {
        $this->claimed($id);
        $max = (int)$this->config->get('video.max_bytes', 262144000);
        if ($index < 0 || $index > 9999 || $bytes === '' || strlen($bytes) > 4 * 1048576) {
            throw new PermanentError('Некорректный кусок файла');
        }
        $dir = $this->partsDir($id);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new PermanentError('Не удалось создать временную папку');
        }
        $used = 0;
        foreach (glob("$dir/*.part") ?: [] as $f) {
            $used += filesize($f);
        }
        if ($used + strlen($bytes) > $max) {
            throw new PermanentError('Файл больше допустимого размера');
        }
        file_put_contents("$dir/$index.part", $bytes);
    }

    /** Склеить куски, сверить размер и SHA-256, положить файл. @return array{path:string,size:int} */
    public function done(int $id, int $count, string $sha256, int $size): array
    {
        $this->claimed($id);
        $dir = $this->partsDir($id);
        $out = $this->storage() . "/videos/video_$id.mp4";
        if (!is_dir(dirname($out))) {
            mkdir(dirname($out), 0750, true);
        }
        $tmp = $out . '.tmp' . getmypid();
        $w = fopen($tmp, 'wb');
        for ($i = 0; $i < $count; $i++) {
            $part = "$dir/$i.part";
            if (!is_file($part)) {
                fclose($w);
                @unlink($tmp);
                throw new TransientError("Не хватает куска $i из $count");
            }
            fwrite($w, (string)file_get_contents($part));
        }
        fclose($w);
        if (filesize($tmp) !== $size || !hash_equals(strtolower($sha256), hash_file('sha256', $tmp))) {
            @unlink($tmp);
            throw new TransientError('Размер или контрольная сумма файла не совпали, отправьте заново');
        }
        rename($tmp, $out);
        $this->clearParts($id);
        $this->db->exec('UPDATE videos SET video_path = ?, render_claimed_at = NULL, updated_at = ? WHERE id = ?', [$out, Clock::now(), $id]);
        $this->log->info('render', "раннер прислал видео ($size байт)", ['video_id' => $id]);
        return ['path' => $out, 'size' => $size];
    }

    public function fail(int $id, string $message): void
    {
        $v = $this->claimed($id);
        $meta = json_decode((string)$v['meta_json'], true) ?: [];
        $meta['render_failures'][] = mb_substr($message, 0, 300);
        $this->db->exec('UPDATE videos SET render_claimed_at = NULL, meta_json = ? WHERE id = ?', [json_encode($meta, JSON_UNESCAPED_UNICODE), $id]);
        $this->clearParts($id);
        $this->log->error('render', 'раннер сообщил об ошибке: ' . $message, ['video_id' => $id]);
    }

    /** @return array<string,mixed> */
    private function claimed(int $id): array
    {
        $v = $this->db->one('SELECT * FROM videos WHERE id = ?', [$id]);
        if ($v === null || empty($v['render_claimed_at'])) {
            throw new PermanentError('Задание не выдавалось этому раннеру');
        }
        return $v;
    }

    private function partsDir(int $id): string
    {
        return $this->storage() . "/temp/up_$id";
    }

    private function clearParts(int $id): void
    {
        foreach (glob($this->partsDir($id) . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->partsDir($id));
    }
}
