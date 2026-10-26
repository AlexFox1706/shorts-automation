<?php
/**
 * Скопируйте в config/config.php и впишите ключи. Подробно: docs/INSTRUCTIONS.md
 * Всё, что не нужно менять, оставьте как есть.
 */
return [
    // 'test' = собирать видео, но НЕ загружать на YouTube. 'live' = загружать (нужен YouTube).
    'mode' => 'test',

    // 'approve' = готовое видео сначала приходит вам в Telegram, на YouTube уходит после кнопки «Опубликовать».
    // 'auto'    = публикуется сразу.
    'publish_mode' => 'approve',

    'timezone' => 'Asia/Almaty',

    // Ваш код доступа для запуска из браузера и для GitHub (придумайте любой, от 8 символов).
    'web' => [
        'token'      => '',
        'allow_http' => true,   // если у сайта есть HTTPS, лучше поставить false
    ],

    'gemini' => [
        'api_key'   => '',
        // Точные имена скопируйте из Google AI Studio / страницы моделей (я не подставляю их наугад).
        'model'     => '',       // текстовая модель для сценариев и проверки фактов
        'tts_model' => '',       // модель озвучки (TTS)
        'voice'     => 'Kore',   // имя голоса, список: ai.google.dev/gemini-api/docs/speech-generation
        'tts_style' => 'Читай по-русски, чётко и увлечённо, как рассказчик познавательного ролика: ',
        'api'       => 'auto',   // auto сам подбирает формат адреса API
        'temperature' => 0.9,
        // Цена за 1 млн токенов, только для оценки стоимости в отчётах. 0 = бесплатный уровень.
        'pricing'   => ['text_in' => 0, 'text_out' => 0, 'tts_in' => 0, 'tts_out' => 0],
    ],

    // Источник картинок и видеофонов: 'pixabay' или 'pexels'. Вписан нужный ключ ниже.
    'stock'  => ['provider' => 'pixabay'],
    'pixabay' => ['api_key' => ''],
    'pexels' => ['api_key' => ''],

    'telegram' => [
        'bot_token' => '',
        'admin_id'  => 0,        // ваш числовой Telegram ID (узнать: бот @userinfobot)
    ],

    'youtube' => [
        'client_id'     => '',
        'client_secret' => '',
        'privacy'       => 'public',
        'category_id'   => '27',   // 27 = Образование
        'language'      => 'ru',
        'synthetic'     => true,   // отметка «синтетический контент» (озвучка ИИ)
        'redirect_uri'  => 'http://127.0.0.1:8080/',   // для клиента типа «Приложение для ПК»
    ],

    'content' => [
        'categories' => [
            'Необычные исторические факты', 'Научные факты', 'Загадки и парадоксы',
            'Необычные явления', 'Малоизвестные события', 'Интересные истории',
        ],
        'words_min' => 105, 'words_max' => 140,   // примерно 35-60 секунд озвучки
        'scenes_min' => 5, 'scenes_max' => 9,
        'history_limit' => 120,
        'generation_tries' => 3,
        'script_attempts' => 3,                   // сколько раз можно пересоздать сценарий
    ],

    'factcheck' => ['min_ratio' => 0.75, 'min_sources' => 2],

    'assets' => [
        'library_dir' => __DIR__ . '/../storage/library',   // свои файлы + library.json (необязательно)
        'fallback_queries' => ['old paper texture', 'night sky stars', 'abstract light', 'ancient stone wall'],
        'reuse_days' => 60,                                  // не повторять один и тот же стоковый ролик
    ],

    'voice' => ['gap_seconds' => 0.25, 'lead_seconds' => 0.2, 'soft_deadline' => 35],

    'video' => [
        'width' => 1080, 'height' => 1920, 'fps' => 30,
        'min_seconds' => 35, 'max_seconds' => 60,
        'max_bytes' => 262144000,
        'font' => 'DejaVu Sans',
        'ffmpeg' => 'ffmpeg', 'ffprobe' => 'ffprobe',
        'render_wait_hours' => 12,
        'chunk_bytes' => 1572864,   // размер куска при передаче видео на хостинг (1.5 МБ)
    ],

    'paths' => [
        'storage'  => __DIR__ . '/../storage',
        'database' => __DIR__ . '/../storage/shorts.sqlite',
        'tokens'   => __DIR__ . '/../storage/youtube_token.json',
    ],

    'queue' => [
        'retry_limit'   => 3,
        'backoff_base'  => 60,
        'backoff_max'   => 3600,
        'stale_lock'    => 1800,
        'worker_budget' => 240,
        'web_budget'    => 40,      // секунд на один веб-запуск (потом следующий запуск продолжит)
    ],

    'logging' => ['level' => 'INFO', 'max_bytes' => 2097152, 'keep' => 5, 'db_days' => 30],
];
