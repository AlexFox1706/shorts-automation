<?php
declare(strict_types=1);

namespace ShortsFactory;

/** OAuth 2.0 для YouTube. Токены лежат в отдельном файле (не в SQLite), запись атомарная, права 600. */
final class YouTubeAuth
{
    private const SCOPES = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';

    public function __construct(private HttpClient $http, private Config $config) {}

    private function tokenFile(): string
    {
        return (string)$this->config->get('paths.tokens');
    }

    public function authUrl(): string
    {
        $this->requireClient();
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $this->config->get('youtube.client_id'),
            'redirect_uri' => $this->config->get('youtube.redirect_uri'),
            'response_type' => 'code', 'scope' => self::SCOPES, 'access_type' => 'offline', 'prompt' => 'consent',
        ]);
    }

    /** Принимает код или целиком адрес из строки браузера (…?code=XXX&scope=…). */
    public function exchange(string $codeOrUrl): void
    {
        $this->requireClient();
        $code = trim($codeOrUrl);
        if (str_contains($code, 'code=')) {
            parse_str((string)parse_url($code, PHP_URL_QUERY), $q);
            $code = (string)($q['code'] ?? '');
        }
        if ($code === '') {
            throw new PermanentError('Код авторизации не найден в строке');
        }
        $d = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->config->get('youtube.redirect_uri')]);
        if (empty($d['refresh_token'])) {
            throw new PermanentError('Google не выдал refresh_token. Повторите авторизацию с самого начала (action=youtube_auth).');
        }
        $this->save(['access_token' => $d['access_token'], 'expires_at' => Clock::ts() + (int)$d['expires_in'],
            'refresh_token' => $d['refresh_token'], 'scope' => $d['scope'] ?? '']);
    }

    public function hasToken(): bool
    {
        return is_file($this->tokenFile());
    }

    public function accessToken(): string
    {
        $t = $this->load();
        if ($t === null) {
            throw new PermanentError('YouTube не авторизован. Откройте ...web/index.php?action=youtube_auth&token=ВАШ_КОД');
        }
        if ($t['expires_at'] - Clock::ts() > 120) {
            return $t['access_token'];
        }
        try {
            $d = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
        } catch (PermanentError $e) {
            throw new PermanentError('Доступ к YouTube отозван или истёк (' . $e->getMessage() . '). Пройдите авторизацию заново: action=youtube_auth. '
                . 'Если это повторяется раз в 7 дней, переведите OAuth-приложение из «Testing» в «In production».');
        }
        $t['access_token'] = $d['access_token'];
        $t['expires_at'] = Clock::ts() + (int)$d['expires_in'];
        if (!empty($d['refresh_token'])) {
            $t['refresh_token'] = $d['refresh_token'];
        }
        $this->save($t);
        return $t['access_token'];
    }

    /** @return array<string,mixed> */
    private function tokenRequest(array $params): array
    {
        $body = http_build_query($params + ['client_id' => $this->config->get('youtube.client_id'), 'client_secret' => $this->config->get('youtube.client_secret')]);
        return $this->http->json('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], $body);
    }

    private function requireClient(): void
    {
        if (!$this->config->get('youtube.client_id') || !$this->config->get('youtube.client_secret')) {
            throw new PermanentError('Не заданы youtube.client_id и youtube.client_secret в config.php');
        }
    }

    /** @return array{access_token:string,expires_at:int,refresh_token:string}|null */
    private function load(): ?array
    {
        $raw = @file_get_contents($this->tokenFile());
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) && !empty($d['refresh_token']) ? $d : null;
    }

    private function save(array $t): void
    {
        $file = $this->tokenFile();
        $tmp = $file . '.tmp' . getmypid();
        if (file_put_contents($tmp, json_encode($t)) === false) {
            throw new PermanentError('Не удалось записать токен YouTube: ' . $file);
        }
        @chmod($tmp, 0600);
        rename($tmp, $file);
    }
}
