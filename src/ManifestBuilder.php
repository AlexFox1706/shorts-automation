<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Собирает задание для сборщика: сцены с таймингом и субтитры. Чистая логика, без ввода-вывода. */
final class ManifestBuilder
{
    /**
     * @param list<array{narration:string,visual_query:string}> $scenes
     * @param list<float> $durations длительность озвучки каждой сцены, секунды
     * @param list<array<string,mixed>> $assets строки assets по scene_index
     * @return array<string,mixed>
     */
    public static function build(array $video, array $scenes, array $durations, array $assets, float $gap, float $lead, array $cfg): array
    {
        $byScene = [];
        foreach ($assets as $a) {
            $byScene[(int)$a['scene_index']] = $a;
        }
        $t = $lead;
        $speechStart = [];
        foreach ($durations as $i => $d) {
            $speechStart[$i] = $t;
            $t += $d + $gap;
        }
        $audioTotal = $t - $gap;
        $end = $audioTotal + 0.4;

        $outScenes = [];
        $subs = [];
        foreach ($scenes as $i => $sc) {
            $start = $i === 0 ? 0.0 : $speechStart[$i];
            $stop = $i === count($scenes) - 1 ? $end : $speechStart[$i + 1];
            $a = $byScene[$i] ?? null;
            if ($a === null) {
                throw new PermanentError('Для сцены ' . ($i + 1) . ' нет подобранного видеоряда');
            }
            $outScenes[] = [
                'index' => $i, 'start' => round($start, 3), 'end' => round($stop, 3), 'type' => $a['asset_type'],
                'url' => $a['remote_url'], 'asset_id' => $a['local_path'] !== null ? (int)$a['id'] : null,
            ];
            foreach (self::subtitleChunks($sc['narration'], $speechStart[$i], $speechStart[$i] + $durations[$i]) as $c) {
                $subs[] = $c;
            }
        }
        return [
            'video_id' => (int)$video['id'], 'version' => 1,
            'width' => (int)$cfg['width'], 'height' => (int)$cfg['height'], 'fps' => (int)$cfg['fps'],
            'duration' => round($end, 3), 'audio_duration' => round($audioTotal, 3),
            'title' => (string)$video['title'], 'scenes' => $outScenes, 'subtitles' => $subs,
        ];
    }

    /**
     * Разбить реплику на короткие субтитры (до ~34 символов, две строки по 20) и распределить время по длине.
     * @return list<array{start:float,end:float,text:string}>
     */
    public static function subtitleChunks(string $text, float $from, float $to, int $maxChars = 34, int $lineWidth = 20): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $chunks = [];
        $cur = '';
        foreach ($words as $w) {
            $next = $cur === '' ? $w : "$cur $w";
            if ($cur !== '' && mb_strlen($next) > $maxChars) {
                $chunks[] = $cur;
                $cur = $w;
            } else {
                $cur = $next;
            }
            if (preg_match('/[.!?…]$/u', $w) && mb_strlen($cur) >= 14) {
                $chunks[] = $cur;
                $cur = '';
            }
        }
        if ($cur !== '') {
            $chunks[] = $cur;
        }
        $total = max(1, array_sum(array_map('mb_strlen', $chunks)));
        $span = max(0.1, $to - $from);
        $t = $from;
        $out = [];
        foreach ($chunks as $c) {
            $d = $span * mb_strlen($c) / $total;
            $out[] = ['start' => round($t, 3), 'end' => round($t + $d, 3), 'text' => implode("\n", Text::wrap($c, $lineWidth))];
            $t += $d;
        }
        return $out;
    }
}
