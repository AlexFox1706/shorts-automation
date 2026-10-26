<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Одно задание раннера: скачать -> собрать -> проверить -> отправить кусками. */
final class RunnerJob
{
    public function __construct(private RunnerClient $client, private VideoRenderer $renderer, private $log) {}

    /** @param array<string,mixed> $job ответ render_next */
    public function run(array $job, string $workDir): void
    {
        $id = (int)$job['id'];
        $m = $job['manifest'];
        @mkdir($workDir, 0777, true);
        ($this->log)("задание для выпуска #$id: сцен " . count($m['scenes']));

        $audio = "$workDir/audio.wav";
        $this->client->fetchHost('job_audio', ['id' => $id], $audio);
        $files = [];
        foreach ($m['scenes'] as $sc) {
            $i = (int)$sc['index'];
            $ext = $sc['type'] === 'image' ? 'jpg' : 'mp4';
            $files[$i] = "$workDir/src_$i.$ext";
            if (!empty($sc['url'])) {
                $this->client->download((string)$sc['url'], $files[$i]);
            } else {
                $this->client->fetchHost('job_asset', ['id' => $id, 'asset' => (int)$sc['asset_id']], $files[$i]);
            }
        }

        $out = "$workDir/out.mp4";
        $this->renderer->render($m, $audio, $files, $out, "$workDir/work");

        $info = Mp4Inspector::inspect($out);   // проверка до отправки: не гонять заведомо плохой файл
        if ($info['width'] !== (int)$m['width'] || $info['height'] !== (int)$m['height'] || !$info['has_audio']) {
            throw new \RuntimeException('собранный файл не прошёл локальную проверку');
        }
        ($this->log)(sprintf('собрано: %.1f с, %.1f МБ, отправляю', $info['duration'], $info['size'] / 1048576));

        $chunk = max(262144, (int)$job['chunk_bytes']);
        $fh = fopen($out, 'rb');
        $n = 0;
        while (!feof($fh)) {
            $data = (string)fread($fh, $chunk);
            if ($data === '') {
                break;
            }
            for ($try = 1; ; $try++) {
                try {
                    $this->client->post('job_chunk', ['id' => $id, 'index' => $n], $data);
                    break;
                } catch (TransientError $e) {
                    if ($try >= 4) {
                        throw $e;
                    }
                    sleep($try * 3);
                }
            }
            $n++;
        }
        fclose($fh);
        $this->client->post('job_done', [], json_encode(['id' => $id, 'chunks' => $n, 'size' => filesize($out), 'sha256' => hash_file('sha256', $out)]));
        ($this->log)("видео #$id отправлено ($n кусков)");
    }
}
