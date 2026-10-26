<?php
declare(strict_types=1);

use ShortsFactory\{Config, Database, Logger, Settings, Queue, Pipeline, Scheduler, HttpClient, GeminiClient, TopicHistory,
    ScriptGenerator, FactChecker, SourceFetcher, PexelsClient, PixabayClient, StockProvider, AssetManager, SpeechGenerator, VideoValidator, RenderCoordinator,
    JobApi, TelegramClient, TelegramReporter, TelegramBot, YouTubeAuth, YouTubeUploader};

// Из веба разрешён только web/index.php (он объявляет SHORTS_WEB и проверяет токен).
if (PHP_SAPI !== 'cli' && !defined('SHORTS_WEB')) {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/autoload.php';

/** Собрать все объекты. Возвращает простой массив-контейнер. */
function app(?Config $config = null): array
{
    $config ??= Config::load();
    date_default_timezone_set('UTC'); // время в БД - UTC; бизнес-дата считается по settings.timezone
    $storage = (string)$config->get('paths.storage');
    foreach (['logs', 'temp', 'audio', 'videos', 'backups'] as $d) {
        if (!is_dir("$storage/$d")) {
            @mkdir("$storage/$d", 0750, true);
        }
    }
    $db = new Database((string)$config->get('paths.database'));
    $db->migrate(__DIR__ . '/migrations');

    $log = new Logger(
        $storage . '/logs/app.log', $db, $config->secrets(),
        (string)$config->get('logging.level', 'INFO'),
        (int)$config->get('logging.max_bytes', 2097152),
        (int)$config->get('logging.keep', 5),
    );
    $settings = new Settings($db);
    if ($settings->get('timezone') !== (string)$config->get('timezone', 'Asia/Almaty')) {
        $settings->set('timezone', (string)$config->get('timezone', 'Asia/Almaty'));
    }
    $queue = new Queue(
        $db, $settings,
        (int)$config->get('queue.backoff_base', 60), (int)$config->get('queue.backoff_max', 3600),
        (int)$config->get('queue.stale_lock', 1800), (int)$config->get('queue.retry_limit', 3),
    );

    $http = new HttpClient(null, 30, 2);
    $tg = new TelegramClient($http, (string)$config->get('telegram.bot_token', ''));
    $reporter = new TelegramReporter($tg, (int)$config->get('telegram.admin_id', 0), $log);
    $pipeline = new Pipeline($db, $queue, $log, $reporter, (int)$config->get('content.script_attempts', 3));

    $gemini = new GeminiClient($http, $config, $settings);
    $history = new TopicHistory($db);
    $pexels = $config->get('stock.provider', 'pixabay') === 'pexels' ? new PexelsClient($http, $config) : new PixabayClient($http, $config);
    $validator = new VideoValidator($config);
    $auth = new YouTubeAuth($http, $config);
    $pipeline->register(new ScriptGenerator($db, $config, $gemini, $history, $log));
    $pipeline->register(new FactChecker($db, $config, $gemini, new SourceFetcher(), $log));
    $pipeline->register(new AssetManager($db, $config, $pexels, $log));
    $pipeline->register(new SpeechGenerator($db, $config, $gemini, $log));
    $pipeline->register(new RenderCoordinator($db, $config, $validator, $log));
    $pipeline->register(new YouTubeUploader($db, $config, $http, $auth, $validator, $reporter, $history, $log));

    $scheduler = new Scheduler($db, $settings, $queue, $log);
    $bot = new TelegramBot($tg, (int)$config->get('telegram.admin_id', 0), $db, $settings, $queue, $pipeline, $log);
    $jobs = new JobApi($db, $config, $settings, $log);

    return compact('config', 'db', 'log', 'settings', 'queue', 'pipeline', 'scheduler', 'http', 'gemini', 'tg', 'reporter',
        'bot', 'jobs', 'auth', 'pexels');
}
