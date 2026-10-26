<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Pixabay API (pixabay.com/api/docs). Лимит 100 запросов/60 с на ключ, у нас их единицы за выпуск. */
final class PixabayClient implements StockProvider
{
    public function __construct(private HttpClient $http, private Config $config) {}

    public function name(): string { return 'pixabay'; }
    public function label(): string { return 'Pixabay'; }

    private function key(): string
    {
        $k = (string)$this->config->get('pixabay.api_key', '');
        if ($k === '') {
            throw new PermanentError('Не задан pixabay.api_key в config.php');
        }
        return $k;
    }

    public function searchVideos(string $query, int $perPage = 15): array
    {
        $d = $this->http->json('GET', 'https://pixabay.com/api/videos/?' . http_build_query([
            'key' => $this->key(), 'q' => mb_substr($query, 0, 100), 'video_type' => 'film', 'safesearch' => 'true',
            'min_height' => 1080, 'per_page' => max(3, $perPage),
        ]));
        $out = [];
        foreach ((array)($d['hits'] ?? []) as $v) {
            $f = self::pickFile((array)($v['videos'] ?? []));
            if ($f === null) {
                continue;
            }
            $out[] = [
                'id' => (string)$v['id'], 'page_url' => (string)($v['pageURL'] ?? ''), 'author' => (string)($v['user'] ?? ''),
                'duration' => (float)($v['duration'] ?? 0), 'link' => (string)$f['url'],
                'width' => (int)($f['width'] ?? 0), 'height' => (int)($f['height'] ?? 0),
            ];
        }
        return $out;
    }

    public function searchPhotos(string $query, int $perPage = 12): array
    {
        $d = $this->http->json('GET', 'https://pixabay.com/api/?' . http_build_query([
            'key' => $this->key(), 'q' => mb_substr($query, 0, 100), 'image_type' => 'photo', 'orientation' => 'vertical',
            'safesearch' => 'true', 'min_width' => 1000, 'min_height' => 1280, 'per_page' => max(3, $perPage),
        ]));
        $out = [];
        foreach ((array)($d['hits'] ?? []) as $p) {
            $link = (string)($p['largeImageURL'] ?? '');
            if ($link === '') {
                continue;
            }
            $out[] = [
                'id' => (string)$p['id'], 'page_url' => (string)($p['pageURL'] ?? ''), 'author' => (string)($p['user'] ?? ''),
                'link' => $link, 'width' => (int)($p['imageWidth'] ?? 0), 'height' => (int)($p['imageHeight'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Ролики Pixabay почти всегда горизонтальные, под вертикаль они обрезаются по центру.
     * Чтобы после обрезки хватало пикселей, берём самый большой вариант (large 4K, иначе medium 1080p).
     * @param array<string,mixed> $sizes
     */
    public static function pickFile(array $sizes): ?array
    {
        foreach (['large', 'medium', 'small'] as $k) {
            $f = (array)($sizes[$k] ?? []);
            if (!empty($f['url']) && (int)($f['size'] ?? 1) > 0 && (int)($f['height'] ?? 0) >= 1080) {
                return $f;
            }
        }
        return null;
    }
}
