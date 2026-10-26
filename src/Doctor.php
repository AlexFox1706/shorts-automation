<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Проверка хостинга и настроек. Строки: [OK|WARN|FAIL, имя, подробности]. */
final class Doctor
{
    public function __construct(private Config $config, private ?Settings $settings = null) {}

    /** @return list<array{0:string,1:string,2:string}> */
    public function checks(): array
    {
        $c = $this->config;
        $r = [];
        $add = function (string $name, bool $ok, string $detail = '', bool $required = true) use (&$r): void {
            $r[] = [$ok ? 'OK' : ($required ? 'FAIL' : 'WARN'), $name, $detail];
        };

        // Что нужно хостингу. FFmpeg и exec() здесь НЕ нужны: видео собирает раннер GitHub Actions.
        $add('PHP >= 8.2', PHP_VERSION_ID >= 80200, PHP_VERSION . ' (' . PHP_SAPI . ')');
        foreach (['pdo_sqlite', 'curl', 'json', 'mbstring'] as $ext) {
            $add("Расширение $ext", extension_loaded($ext));
        }
        if (extension_loaded('pdo_sqlite')) {
            $v = (string)(new \PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn();
            $add('SQLite >= 3.27', version_compare($v, '3.27.0', '>='), "$v (нужен для резервных копий)");
        }
        $storage = (string)$c->get('paths.storage');
        $add('Папка storage доступна для записи', is_dir($storage) && is_writable($storage), $storage);
        $free = @disk_free_space($storage);
        $add('Свободное место', $free !== false && $free > 1024 ** 3, $free === false ? 'не определено' : round($free / 1024 ** 3, 1) . ' ГБ', false);
        $post = self::bytes((string)ini_get('post_max_size'));
        $chunk = (int)$c->get('video.chunk_bytes', 1572864);
        $add('post_max_size', $post === 0 || $post > $chunk + 262144, ini_get('post_max_size') . ", нужно больше {$chunk} байт (кусок видео от раннера)");
        $add('Часовой пояс', true, date_default_timezone_get() . ' (рабочий: ' . $c->get('timezone') . ')');
        $add('Режим', true, $c->get('mode') . ', публикация: ' . $c->get('publish_mode'));

        if (function_exists('curl_init')) {
            foreach (['Gemini' => 'https://generativelanguage.googleapis.com/', 'Telegram' => 'https://api.telegram.org/',
                      'Pexels' => 'https://api.pexels.com/', 'Pixabay' => 'https://pixabay.com/api/', 'YouTube' => 'https://www.googleapis.com/', 'Google OAuth' => 'https://oauth2.googleapis.com/'] as $name => $url) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_RETURNTRANSFER => true]);
                curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                $add("Связь с сервера: $name", $code > 0, $code > 0 ? "отвечает ($code)" : "нет связи: $err");
            }
        }

        // Настройки: что ещё нужно вписать.
        foreach (['gemini.api_key' => 'Gemini: ключ', 'gemini.model' => 'Gemini: текстовая модель', 'gemini.tts_model' => 'Gemini: модель озвучки',
                  ($c->get('stock.provider', 'pixabay') === 'pexels' ? 'pexels.api_key' : 'pixabay.api_key') => 'Ключ стоков (' . $c->get('stock.provider', 'pixabay') . ')', 'telegram.bot_token' => 'Telegram: токен бота', 'telegram.admin_id' => 'Telegram: ваш ID',
                  'web.token' => 'Код доступа (web)'] as $path => $label) {
            $add($label, !empty($c->get($path)), empty($c->get($path)) ? 'не задан' : 'задан', false);
        }
        $live = $c->isLive();
        foreach (['youtube.client_id' => 'YouTube: client_id', 'youtube.client_secret' => 'YouTube: client_secret'] as $path => $label) {
            $add($label, !empty($c->get($path)), empty($c->get($path)) ? 'не задан' . ($live ? '' : ' (нужен в режиме live)') : 'задан', false);
        }
        $add('YouTube: авторизация канала', is_file((string)$c->get('paths.tokens')), is_file((string)$c->get('paths.tokens')) ? 'токен сохранён' : 'ещё не пройдена (action=youtube_auth)', false);

        // Сборщик видео (GitHub Actions).
        if ($this->settings !== null) {
            $seen = $this->settings->get('runner_last_seen');
            $age = $seen === '' ? null : Clock::ts() - strtotime($seen . ' UTC');
            $add('Сборщик видео (GitHub)', $age !== null && $age < 86400, $seen === '' ? 'ещё ни разу не выходил на связь' : "последний раз $seen UTC (" . round($age / 60) . ' мин назад)', false);
        }
        return $r;
    }

    private static function bytes(string $v): int
    {
        $n = (int)$v;
        return match (strtolower(substr(trim($v), -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        };
    }
}
