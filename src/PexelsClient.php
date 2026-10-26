<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Pexels API. Поля ответа сверены с публичной документацией Pexels. */
final class PexelsClient implements StockProvider
{
    public function name(): string { return 'pexels'; }
    public function label(): string { return 'Pexels'; }

    public function __construct(private HttpClient $http, private Config $config) {}

    private function headers(): array
    {
        $k = (string)$this->config->get('pexels.api_key', '');
        if ($k === '') {
            throw new PermanentError('Не задан pexels.api_key в config.php');
        }
        return ['Authorization: ' . $k];
    }

    /** @return list<array{id:string,page_url:string,author:string,duration:float,link:string,width:int,height:int}> */
    public function searchVideos(string $query, int $perPage = 15): array
    {
        $d = $this->http->json('GET', 'https://api.pexels.com/videos/search?' . http_build_query([
            'query' => $query, 'orientation' => 'portrait', 'size' => 'medium', 'per_page' => $perPage,
        ]), $this->headers());
        $out = [];
        foreach ((array)($d['videos'] ?? []) as $v) {
            $f = self::pickFile((array)($v['video_files'] ?? []));
            if ($f === null) {
                continue;
            }
            $out[] = [
                'id' => (string)$v['id'], 'page_url' => (string)($v['url'] ?? ''), 'author' => (string)($v['user']['name'] ?? ''),
                'duration' => (float)($v['duration'] ?? 0), 'link' => (string)$f['link'],
                'width' => (int)($f['width'] ?? 0), 'height' => (int)($f['height'] ?? 0),
            ];
        }
        return $out;
    }

    /** @return list<array{id:string,page_url:string,author:string,link:string,width:int,height:int}> */
    public function searchPhotos(string $query, int $perPage = 12): array
    {
        $d = $this->http->json('GET', 'https://api.pexels.com/v1/search?' . http_build_query([
            'query' => $query, 'orientation' => 'portrait', 'size' => 'large', 'per_page' => $perPage,
        ]), $this->headers());
        $out = [];
        foreach ((array)($d['photos'] ?? []) as $p) {
            $link = (string)($p['src']['large2x'] ?? $p['src']['large'] ?? '');
            if ($link === '') {
                continue;
            }
            $out[] = [
                'id' => (string)$p['id'], 'page_url' => (string)($p['url'] ?? ''), 'author' => (string)($p['photographer'] ?? ''),
                'link' => $link, 'width' => (int)($p['width'] ?? 0), 'height' => (int)($p['height'] ?? 0),
            ];
        }
        return $out;
    }

    /** Лучший mp4: ближе к ширине 1080, вертикальные предпочтительнее. @param list<array<string,mixed>> $files */
    public static function pickFile(array $files): ?array
    {
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($files as $f) {
            if (($f['file_type'] ?? '') !== 'video/mp4' || empty($f['link'])) {
                continue;
            }
            $w = (int)($f['width'] ?? 0);
            $h = (int)($f['height'] ?? 0);
            $score = abs($w - 1080) + ($h >= $w ? 0 : 5000) + ($w < 720 ? 3000 : 0);
            if ($score < $bestScore) {
                $best = $f;
                $bestScore = $score;
            }
        }
        return $best;
    }
}
