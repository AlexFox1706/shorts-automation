<?php
declare(strict_types=1);

namespace ShortsFactory;

/**
 * Этап voice. Озвучка по сценам (точный тайминг для картинки и субтитров), каждая сцена кэшируется на диске
 * по хэшу текста, поэтому после сбоя или паузы продолжается с того же места. Хостингу не нужен ffmpeg.
 */
final class SpeechGenerator implements StageHandler
{
    public function __construct(
        private Database $db,
        private Config $config,
        private LlmClient $llm,
        private Logger $log,
    ) {}

    public function type(): string
    {
        return 'voice';
    }

    public function run(array $video): ?string
    {
        $videoId = (int)$video['id'];
        $meta = json_decode((string)$video['meta_json'], true);
        $scenes = $meta['scenes'] ?? [];
        if ($scenes === []) {
            throw new PermanentError('Нет сцен для озвучки');
        }
        $storage = (string)$this->config->get('paths.storage');
        $dir = "$storage/audio/video_$videoId";
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new PermanentError("Не удалось создать папку $dir");
        }
        $deadline = time() + (int)$this->config->get('voice.soft_deadline', 35);

        $parts = [];
        $keep = [];
        foreach ($scenes as $i => $sc) {
            $file = sprintf('%s/scene_%d_%s.wav', $dir, $i, substr(md5($sc['narration']), 0, 10));
            $keep[] = $file;
            if (!is_file($file) || filesize($file) < 1000) {
                if (time() > $deadline) {
                    throw new Deferred(0, "озвучка продолжится ({$i} из " . count($scenes) . ')');
                }
                $r = $this->llm->tts($sc['narration']);
                self::writeAtomic($file, $r['wav']);
                $this->log->info('voice', 'озвучена сцена ' . ($i + 1), ['video_id' => $videoId, 'cost' => $r['cost']]);
            }
            $parts[$i] = (string)file_get_contents($file);
        }
        foreach (glob("$dir/scene_*.wav") ?: [] as $old) {
            if (!in_array($old, $keep, true)) {
                unlink($old);
            }
        }

        $gap = (float)$this->config->get('voice.gap_seconds', 0.25);
        $lead = (float)$this->config->get('voice.lead_seconds', 0.2);
        $durations = array_map(fn($w) => Wav::seconds($w), $parts);
        $total = $lead + array_sum($durations) + $gap * (count($parts) - 1);

        $min = (int)$this->config->get('video.min_seconds', 35);
        $max = (int)$this->config->get('video.max_seconds', 60);
        if ($total > $max) {
            throw new RegenerateScript(sprintf('озвучка получилась %.0f с, нужно не больше %d: сделай сценарий короче (меньше слов)', $total, $max));
        }
        if ($total < $min) {
            throw new RegenerateScript(sprintf('озвучка получилась %.0f с, нужно не меньше %d: сделай сценарий длиннее (больше слов)', $total, $min));
        }

        $audioPath = "$storage/audio/video_$videoId.wav";
        self::writeAtomic($audioPath, Wav::concat($parts, $gap, $lead));
        $assets = $this->db->all('SELECT * FROM assets WHERE video_id = ? ORDER BY scene_index', [$videoId]);
        $manifest = ManifestBuilder::build($video, $scenes, $durations, $assets, $gap, $lead, (array)$this->config->get('video'));
        $this->db->exec(
            'UPDATE videos SET audio_path = ?, audio_duration = ?, manifest_json = ?, video_path = NULL, updated_at = ? WHERE id = ?',
            [$audioPath, $total, json_encode($manifest, JSON_UNESCAPED_UNICODE), Clock::now(), $videoId]
        );
        $this->log->info('voice', sprintf('озвучка готова: %.1f с', $total), ['video_id' => $videoId]);
        return null;
    }

    private static function writeAtomic(string $path, string $data): void
    {
        $tmp = $path . '.tmp' . getmypid();
        if (file_put_contents($tmp, $data) === false || !rename($tmp, $path)) {
            throw new PermanentError("Не удалось записать файл $path");
        }
    }
}
