<?php
declare(strict_types=1);

namespace ShortsFactory;

/**
 * Сборка ролика FFmpeg (работает на раннере, не на хостинге).
 * Сцена -> сегмент (видео обрезается/зацикливается, фото получает плавный зум) -> склейка -> финальный проход:
 * субтитры, озвучка (нормализация громкости), H.264/AAC 1080x1920, faststart.
 */
final class VideoRenderer
{
    /** @var callable|null */
    private $log;

    public function __construct(
        private string $ffmpeg = 'ffmpeg',
        private string $font = 'DejaVu Sans',
        ?callable $log = null,
        private string $preset = 'medium',
    ) {
        $this->log = $log;
    }

    /**
     * @param array<string,mixed> $m манифест
     * @param array<int,string> $files номер сцены => локальный файл
     */
    public function render(array $m, string $audioPath, array $files, string $out, string $workDir): void
    {
        $w = (int)$m['width'];
        $h = (int)$m['height'];
        $fps = (int)$m['fps'];
        if (!is_dir($workDir) && !mkdir($workDir, 0777, true) && !is_dir($workDir)) {
            throw new \RuntimeException("Нет папки $workDir");
        }

        $list = '';
        foreach ($m['scenes'] as $sc) {
            $i = (int)$sc['index'];
            $src = $files[$i] ?? throw new \RuntimeException("Нет файла для сцены $i");
            $d = max(0.5, (float)$sc['end'] - (float)$sc['start']);
            $seg = "$workDir/seg_$i.mp4";
            $this->say("сцена $i: {$sc['type']}, " . round($d, 2) . ' с');
            $args = ['-y', '-v', 'error'];
            if ($sc['type'] === 'image') {
                $frames = (int)ceil($d * $fps);
                $zoom = $i % 2 === 0 ? "min(1+0.12*on/$frames,1.12)" : "max(1.12-0.12*on/$frames,1)";
                $vf = sprintf("scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,zoompan=z='%s':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=1:s=%dx%d:fps=%d,setsar=1,format=yuv420p",
                    (int)($w * 1.5), (int)($h * 1.5), (int)($w * 1.5), (int)($h * 1.5), $zoom, $w, $h, $fps);
                array_push($args, '-loop', '1', '-framerate', (string)$fps, '-i', $src);
            } else {
                $vf = sprintf('scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,setsar=1,fps=%d,format=yuv420p', $w, $h, $w, $h, $fps);
                array_push($args, '-stream_loop', '-1', '-i', $src);
            }
            array_push($args, '-t', sprintf('%.3f', $d), '-vf', $vf, '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '17', '-pix_fmt', 'yuv420p', $seg);
            $this->run($args);
            $list .= "file 'seg_$i.mp4'\n";
        }
        file_put_contents("$workDir/list.txt", $list);
        $this->run(['-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', "$workDir/list.txt", '-c', 'copy', "$workDir/joined.mp4"]);

        file_put_contents("$workDir/subs.ass", SubtitleBuilder::ass($m['subtitles'], $w, $h, $this->font));
        $dur = sprintf('%.3f', (float)$m['duration']);
        $this->say('финальный проход (субтитры, звук, кодирование)');
        $this->run([
            '-y', '-v', 'error', '-i', 'joined.mp4', '-i', $audioPath,
            '-vf', 'ass=subs.ass', '-map', '0:v:0', '-map', '1:a:0',
            '-c:v', 'libx264', '-preset', $this->preset, '-crf', '20', '-maxrate', '6M', '-bufsize', '12M', '-pix_fmt', 'yuv420p', '-r', (string)$fps,
            '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11,aresample=44100,apad',
            '-c:a', 'aac', '-b:a', '160k', '-ac', '1', '-t', $dur, '-movflags', '+faststart', $out,
        ], $workDir);
    }

    private function say(string $m): void
    {
        if ($this->log) {
            ($this->log)($m);
        }
    }

    /** @param list<string> $args */
    private function run(array $args, ?string $cwd = null): void
    {
        $p = proc_open(array_merge([$this->ffmpeg], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($p)) {
            throw new \RuntimeException('Не удалось запустить ffmpeg');
        }
        stream_set_blocking($pipes[1], false);
        $err = '';
        while (true) {
            $err .= (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) {
                break;
            }
            usleep(50000);
        }
        $err .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        if ($code !== 0 && $st['exitcode'] !== 0) {
            throw new \RuntimeException('ffmpeg завершился с ошибкой: ' . mb_substr(trim($err), -600));
        }
    }
}
