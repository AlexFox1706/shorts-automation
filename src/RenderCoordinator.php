<?php
declare(strict_types=1);

namespace ShortsFactory;

/**
 * Этап render на хостинге. Хостинг не запускает программы, поэтому видео собирает раннер (GitHub Actions):
 * забирает задание через JobApi, собирает и присылает файл. Здесь мы ждём файл и проверяем его.
 */
final class RenderCoordinator implements StageHandler
{
    public function __construct(
        private Database $db,
        private Config $config,
        private VideoValidator $validator,
        private Logger $log,
    ) {}

    public function type(): string
    {
        return 'render';
    }

    public function run(array $video): ?string
    {
        $videoId = (int)$video['id'];
        $meta = json_decode((string)$video['meta_json'], true) ?: [];
        if (empty($video['manifest_json'])) {
            throw new PermanentError('Нет задания для сборки (озвучка не завершена)');
        }
        $failures = $meta['render_failures'] ?? [];
        if (count($failures) >= 3) {
            throw new PermanentError('Сборщик 3 раза не смог собрать видео. Последняя ошибка: ' . end($failures));
        }

        $path = (string)($video['video_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            $since = $meta['render_waiting_since'] ?? null;
            if ($since === null) {
                $meta['render_waiting_since'] = Clock::now();
                $this->db->exec('UPDATE videos SET meta_json = ? WHERE id = ?', [json_encode($meta, JSON_UNESCAPED_UNICODE), $videoId]);
            } elseif (strtotime($since . ' UTC') < Clock::ts() - (int)$this->config->get('video.render_wait_hours', 12) * 3600) {
                throw new TransientError('Сборщик видео не откликается дольше ' . $this->config->get('video.render_wait_hours', 12) . ' ч. Проверьте GitHub Actions.');
            }
            throw new Deferred(60, 'ждём сборщика видео');
        }

        $res = $this->validator->validate($video);
        if ($res['file'] !== []) {
            @unlink($path);
            $failures[] = implode('; ', $res['file']);
            $meta['render_failures'] = $failures;
            $this->db->exec('UPDATE videos SET video_path = NULL, render_claimed_at = NULL, meta_json = ? WHERE id = ?',
                [json_encode($meta, JSON_UNESCAPED_UNICODE), $videoId]);
            $this->log->warning('render', 'видео не прошло проверку: ' . end($failures), ['video_id' => $videoId]);
            throw new Deferred(30, 'пересборка');
        }
        if ($res['content'] !== []) {
            throw new PermanentError('Публикация запрещена: ' . implode('; ', $res['content']));
        }
        $this->db->exec('UPDATE videos SET video_duration = ?, updated_at = ? WHERE id = ?', [$res['info']['duration'], Clock::now(), $videoId]);
        $this->log->info('render', sprintf('видео принято: %.1f с, %.1f МБ', $res['info']['duration'], $res['info']['size'] / 1048576), ['video_id' => $videoId]);
        return null;
    }
}
