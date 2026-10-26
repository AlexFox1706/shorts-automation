<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Общая логика для cron-скриптов и веб-входа: один код, два способа запуска. */
final class Runner
{
    /** @param array<string,mixed> $app результат app() */
    public function __construct(private array $app) {}

    private function storage(): string
    {
        return (string)$this->app['config']->get('paths.storage');
    }

    /** Блокировка от параллельного запуска (cron + веб одновременно). */
    private function lock(string $name)
    {
        $dir = $this->storage() . '/temp';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $fh = fopen("$dir/$name.lock", 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            return null;
        }
        return $fh;
    }

    /** Чего не хватает в config.php для работы. @return list<string> */
    public function missingConfig(): array
    {
        $c = $this->app['config'];
        $need = ['gemini.api_key', 'gemini.model', 'gemini.tts_model', $this->app['config']->get('stock.provider', 'pixabay') === 'pexels' ? 'pexels.api_key' : 'pixabay.api_key'];
        if ($c->get('publish_mode', 'approve') === 'approve') {
            $need = array_merge($need, ['telegram.bot_token', 'telegram.admin_id']);
        }
        if ($c->isLive()) {
            $need = array_merge($need, ['youtube.client_id', 'youtube.client_secret']);
        }
        $missing = array_values(array_filter($need, fn($k) => empty($c->get($k))));
        if ($c->isLive() && !$this->app['auth']->hasToken()) {
            $missing[] = 'авторизация YouTube (action=youtube_auth)';
        }
        return $missing;
    }

    public function daily(bool $force = false): array
    {
        $missing = $this->missingConfig();
        if ($missing !== []) {
            return ['created' => false, 'reason' => 'не настроено: ' . implode(', ', $missing), 'video_id' => null];
        }
        return $this->app['scheduler']->ensureDailyVideo($force);
    }

    public function worker(?int $budget = null): array
    {
        $lock = $this->lock('worker');
        if ($lock === null) {
            return ['skipped' => 'worker already running'];
        }
        $budget ??= (int)$this->app['config']->get('queue.worker_budget', 240);
        $deadline = time() + $budget;
        $done = 0;
        while (time() < $deadline && $this->app['pipeline']->runOne()) {
            $done++;
        }
        return ['processed' => $done];
    }

    public function cleanup(): array
    {
        $lock = $this->lock('cleanup');
        if ($lock === null) {
            return ['skipped' => 'cleanup already running'];
        }
        $db = $this->app['db'];
        $storage = $this->storage();
        $backupDir = $storage . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }
        $db->backup($backupDir . '/shorts-' . gmdate('Ymd') . '.sqlite');
        $backups = glob($backupDir . '/shorts-*.sqlite') ?: [];
        sort($backups);
        foreach (array_slice($backups, 0, max(0, count($backups) - 7)) as $old) {
            unlink($old);
        }
        $days = (int)$this->app['config']->get('logging.db_days', 30);
        $logs = $db->exec('DELETE FROM logs WHERE created_at < ?', [Clock::at(-$days * 86400)]);
        $tmp = 0;
        foreach (glob($storage . '/temp/*') ?: [] as $f) {
            if (is_file($f) && !str_ends_with($f, '.lock') && filemtime($f) < time() - 2 * 86400) {
                unlink($f);
                $tmp++;
            }
        }
        $this->app['settings']->set('last_cleanup', Clock::now());
        $this->app['log']->info('cleanup', "backup ok, $logs log rows and $tmp temp files removed");
        return ['backup' => 'ok', 'log_rows_removed' => $logs, 'temp_removed' => $tmp];
    }

    /**
     * Один вызов «сделай всё, что пора»: создать выпуск на сегодня (если пора),
     * обработать очередь, раз в сутки сделать очистку. Для внешнего cron-сервиса раз в 5 минут.
     */
    public function tick(?int $budget = null): array
    {
        $res = [];
        try {
            $res['telegram'] = ['processed' => $this->app['bot']->poll()];
        } catch (\Throwable $e) {
            $res['telegram'] = ['error' => $e->getMessage()];
            $this->app['log']->warning('telegram', 'опрос не удался: ' . $e->getMessage());
        }
        $res['daily'] = $this->daily();
        $res['worker'] = $this->worker($budget);
        $last = $this->app['settings']->get('last_cleanup');
        if ($last === '' || strtotime($last . ' UTC') < Clock::ts() - 20 * 3600) {
            $res['cleanup'] = $this->cleanup();
        }
        return $res;
    }

    public function status(): array
    {
        $db = $this->app['db'];
        return [
            'paused' => $this->app['settings']->get('paused') === '1',
            'mode' => $this->app['config']->get('mode'),
            'videos' => $db->all('SELECT id, scheduled_date, status, youtube_url, last_error FROM videos ORDER BY id DESC LIMIT 10'),
            'tasks' => $db->all('SELECT id, video_id, task_type, status, attempts, next_run_at, last_error FROM tasks ORDER BY id DESC LIMIT 15'),
        ];
    }

    /**
     * Живая проверка подключений: что работает, а что нет. Читает ответы настоящих сервисов.
     * @return list<array{0:string,1:string,2:string}>
     */
    public function selftest(): array
    {
        $r = [];
        $try = function (string $name, callable $fn) use (&$r): void {
            try {
                $r[] = ['OK', $name, (string)$fn()];
            } catch (\Throwable $e) {
                $r[] = ['FAIL', $name, $e->getMessage()];
            }
        };
        $a = $this->app;
        $try('Gemini: текст', function () use ($a) {
            $x = $a['gemini']->generateJson('Отвечай одним JSON-объектом.', 'Верни {"ok": true, "слово": "привет"}');
            return 'ответ получен, формат API: ' . $a['settings']->get('gemini_variant') . ', данные: ' . json_encode($x['data'], JSON_UNESCAPED_UNICODE);
        });
        $try('Gemini: озвучка (русский)', function () use ($a) {
            $x = $a['gemini']->tts('Проверка связи. Раз, два, три. Сегодня мы расскажем интересный факт.');
            $f = (string)$a['config']->get('paths.storage') . '/temp/selftest.wav';
            file_put_contents($f, $x['wav']);
            return sprintf('%.1f с аудио, файл storage/temp/selftest.wav (скачайте и послушайте качество)', \ShortsFactory\Wav::seconds($x['wav']));
        });
        $try($a['pexels']->label(), function () use ($a) {
            $v = $a['pexels']->searchVideos('ocean waves', 3);
            return 'найдено видео: ' . count($v) . ($v ? ', пример: ' . $v[0]['link'] : '');
        });
        $try('Telegram', function () use ($a) {
            $me = $a['tg']->call('getMe');
            $a['reporter']->send('Проверка связи: бот работает.');
            return 'бот @' . ($me['username'] ?? '?') . ', тестовое сообщение отправлено администратору (если не пришло, нажмите Start у бота)';
        });
        $try('YouTube', function () use ($a) {
            $res = $a['http']->json('GET', 'https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true', ['Authorization: Bearer ' . $a['auth']->accessToken()]);
            return 'канал: ' . ($res['items'][0]['snippet']['title'] ?? 'не найден, проверьте аккаунт');
        });
        return $r;
    }
}
