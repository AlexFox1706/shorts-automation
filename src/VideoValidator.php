<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Проверка перед публикацией. Любая проблема запрещает загрузку. */
final class VideoValidator
{
    public function __construct(private Config $config) {}

    /**
     * @param array<string,mixed> $video строка videos
     * @return array{file:list<string>,content:list<string>,info:?array<string,mixed>}
     */
    public function validate(array $video): array
    {
        $file = [];
        $content = [];
        $info = null;
        $path = (string)($video['video_path'] ?? '');
        $storage = realpath((string)$this->config->get('paths.storage'));
        $real = $path !== '' ? realpath($path) : false;

        if ($real === false || !is_file($real)) {
            $file[] = 'видеофайл не найден';
        } elseif ($storage === false || !str_starts_with($real, $storage . DIRECTORY_SEPARATOR)) {
            $file[] = 'путь к видео вне хранилища';
        } elseif (!is_readable($real)) {
            $file[] = 'нет прав на чтение файла';
        } else {
            try {
                $info = Mp4Inspector::inspect($real);
                $w = (int)$this->config->get('video.width', 1080);
                $h = (int)$this->config->get('video.height', 1920);
                if ($info['size'] > (int)$this->config->get('video.max_bytes', 262144000)) {
                    $file[] = 'файл слишком большой';
                }
                if ($info['width'] !== $w || $info['height'] !== $h) {
                    $file[] = "разрешение {$info['width']}x{$info['height']}, нужно {$w}x{$h}";
                }
                $min = (int)$this->config->get('video.min_seconds', 35);
                $max = (int)$this->config->get('video.max_seconds', 60);
                if ($info['duration'] < $min - 2 || $info['duration'] > $max + 3) {
                    $file[] = sprintf('длительность %.1f с вне диапазона %d-%d', $info['duration'], $min, $max);
                }
                if ($info['vcodec'] !== 'avc1') {
                    $file[] = 'видеокодек не H.264 (' . $info['vcodec'] . ')';
                }
                if (!$info['has_audio'] || $info['acodec'] !== 'mp4a') {
                    $file[] = 'нет звуковой дорожки AAC';
                }
            } catch (\Throwable $e) {
                $file[] = 'файл повреждён: ' . $e->getMessage();
            }
        }

        $title = trim((string)($video['title'] ?? ''));
        $desc = trim((string)($video['description'] ?? ''));
        if ($title === '' || mb_strlen($title) > 100) {
            $content[] = 'заголовок пуст или длиннее 100 символов';
        }
        if ($desc === '') {
            $content[] = 'нет описания';
        }
        foreach ([$title, $desc, (string)($video['script'] ?? '')] as $t) {
            if (Text::looksPlaceholder($t)) {
                $content[] = 'в тексте шаблонные вставки';
                break;
            }
        }
        $sources = json_decode((string)($video['sources_json'] ?? ''), true);
        if (!is_array($sources) || $sources === []) {
            $content[] = 'нет подтверждённых источников';
        } else {
            foreach ($sources as $s) {
                if (!preg_match('~^https?://~i', (string)($s['url'] ?? '')) || Text::looksPlaceholder((string)$s['url'])) {
                    $content[] = 'фиктивный источник: ' . ($s['url'] ?? '');
                }
            }
        }
        return ['file' => $file, 'content' => $content, 'info' => $info];
    }
}
