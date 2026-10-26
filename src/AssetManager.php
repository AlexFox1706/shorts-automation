<?php
declare(strict_types=1);

namespace ShortsFactory;

/**
 * Этап assets. Приоритет: своя библиотека (library.json) -> видео Pexels -> фото Pexels.
 * Файлы на хостинг не скачиваются: сборщик возьмёт их по ссылкам. Лицензия и автор пишутся в assets.
 */
final class AssetManager implements StageHandler
{
    public function __construct(
        private Database $db,
        private Config $config,
        private StockProvider $pexels,
        private Logger $log,
    ) {}

    public function type(): string
    {
        return 'assets';
    }

    public function run(array $video): ?string
    {
        $videoId = (int)$video['id'];
        $meta = json_decode((string)$video['meta_json'], true);
        $scenes = $meta['scenes'] ?? [];
        if ($scenes === []) {
            throw new PermanentError('Нет сцен для подбора видеоряда');
        }
        $this->db->exec('DELETE FROM assets WHERE video_id = ?', [$videoId]);

        $days = (int)$this->config->get('assets.reuse_days', 60);
        $used = [];
        foreach ($this->db->all('SELECT external_id FROM assets WHERE external_id IS NOT NULL AND video_id != ? AND created_at > ?',
            [$videoId, Clock::at(-$days * 86400)]) as $r) {
            $used[$r['external_id']] = true;
        }
        $library = $this->loadLibrary();
        $fallbacks = (array)$this->config->get('assets.fallback_queries', []);

        foreach ($scenes as $i => $scene) {
            $query = (string)$scene['visual_query'];
            $pick = $this->pickLibrary($library, $query, $used)
                ?? $this->pickPexels($query, $used)
                ?? $this->pickPexels(self::simplify($query), $used);
            foreach ($fallbacks as $fb) {
                if ($pick !== null) {
                    break;
                }
                $pick = $this->pickPexels((string)$fb, $used);
            }
            if ($pick === null) {
                throw new TransientError('Не нашлось видеоряда для сцены ' . ($i + 1) . " («{$query}»)");
            }
            $used[$pick['external_id']] = true;
            $this->db->exec(
                'INSERT INTO assets (video_id, source_url, local_path, license_info, asset_type, content_hash, created_at, scene_index, remote_url, external_id)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)',
                [$videoId, $pick['source_url'], $pick['local_path'], $pick['license'], $pick['type'], Clock::now(), $i, $pick['remote_url'], $pick['external_id']]
            );
        }
        $this->log->info('assets', 'подобрано сцен: ' . count($scenes), ['video_id' => $videoId]);
        return null;
    }

    /** Первые два слова запроса: шире поиск. */
    public static function simplify(string $q): string
    {
        $w = preg_split('/\s+/', trim($q)) ?: [];
        return implode(' ', array_slice($w, 0, 2));
    }

    /** @return list<array{file:string,tags:list<string>,type:string,license:string}> */
    private function loadLibrary(): array
    {
        $dir = (string)$this->config->get('assets.library_dir', '');
        $file = $dir . '/library.json';
        if ($dir === '' || !is_file($file)) {
            return [];
        }
        $list = json_decode((string)file_get_contents($file), true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $e) {
            $path = realpath($dir . '/' . ($e['file'] ?? ''));
            if ($path !== false && str_starts_with($path, (string)realpath($dir) . DIRECTORY_SEPARATOR) && !empty($e['license']) && !empty($e['tags'])) {
                $out[] = ['file' => $path, 'tags' => array_map('mb_strtolower', (array)$e['tags']),
                    'type' => ($e['type'] ?? 'video') === 'image' ? 'image' : 'video', 'license' => (string)$e['license']];
            }
        }
        return $out;
    }

    private function pickLibrary(array $library, string $query, array $used): ?array
    {
        $words = array_filter(explode(' ', mb_strtolower($query)));
        foreach ($library as $e) {
            $id = 'lib:' . $e['file'];
            if (isset($used[$id]) || array_intersect($e['tags'], $words) === []) {
                continue;
            }
            return ['source_url' => 'local library', 'local_path' => $e['file'], 'license' => $e['license'],
                'type' => $e['type'], 'remote_url' => null, 'external_id' => $id];
        }
        return null;
    }

    private function pickPexels(string $query, array $used): ?array
    {
        if (trim($query) === '') {
            return null;
        }
        foreach ($this->pexels->searchVideos($query) as $v) {
            $id = $this->pexels->name() . '-v:' . $v['id'];
            if (isset($used[$id]) || $v['duration'] < 3) {
                continue;
            }
            return ['source_url' => $v['page_url'], 'local_path' => null, 'type' => 'video', 'remote_url' => $v['link'], 'external_id' => $id,
                'license' => $this->pexels->label() . ' License; автор: ' . $v['author']];
        }
        foreach ($this->pexels->searchPhotos($query) as $p) {
            $id = $this->pexels->name() . '-p:' . $p['id'];
            if (isset($used[$id])) {
                continue;
            }
            return ['source_url' => $p['page_url'], 'local_path' => null, 'type' => 'image', 'remote_url' => $p['link'], 'external_id' => $id,
                'license' => $this->pexels->label() . ' License; автор: ' . $p['author']];
        }
        return null;
    }
}
