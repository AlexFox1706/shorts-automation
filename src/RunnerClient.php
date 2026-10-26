<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Клиент раннера: общается с хостингом по HTTP (код доступа в заголовке) и скачивает файлы сцен. */
final class RunnerClient
{
    public function __construct(private HttpClient $http, private string $baseUrl, private string $token) {}

    private function url(string $action, array $q = []): string
    {
        return $this->baseUrl . (str_contains($this->baseUrl, '?') ? '&' : '?') . http_build_query(['action' => $action] + $q);
    }

    private function headers(): array
    {
        return ['X-Shorts-Token: ' . $this->token];
    }

    /** @return array<string,mixed> */
    public function get(string $action, array $q = [], int $timeout = 120): array
    {
        return $this->http->json('GET', $this->url($action, $q), $this->headers(), null, $timeout);
    }

    /** @return array<string,mixed> */
    public function post(string $action, array $q, string $body, int $timeout = 120): array
    {
        $res = $this->http->request('POST', $this->url($action, $q), array_merge($this->headers(), ['Content-Type: application/octet-stream']), $body, $timeout);
        HttpClient::assertOk($res);
        return json_decode($res['body'], true) ?: [];
    }

    /** Файл с самого хостинга (звук, файл библиотеки). */
    public function fetchHost(string $action, array $q, string $dest): void
    {
        $this->downloadTo($this->url($action, $q), $dest, $this->headers(), 400 * 1048576, true);
    }

    /** Любой публичный файл по ссылке (Pexels CDN). */
    public function download(string $url, string $dest, int $maxBytes = 200 * 1048576): void
    {
        $this->downloadTo($url, $dest, [], $maxBytes);
    }

    private function downloadTo(string $url, string $dest, array $headers, int $maxBytes = 400 * 1048576, bool $ownHost = false): void
    {
        if (!preg_match('~^https?://~i', $url)) {
            throw new \RuntimeException('Недопустимая ссылка: ' . $url);
        }
        for ($try = 1; $try <= 3; $try++) {
            $fh = fopen($dest, 'wb');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 240, CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_FAILONERROR => false, CURLOPT_USERAGENT => 'ShortsFactoryRunner/1.0',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => static fn($ch, $dt, $dl) => $dl > $maxBytes ? 1 : 0,
            ]);
            if ($ownHost && getenv('SHORTS_INSECURE_TLS') === '1') {
                // только для вашего хостинга (самоподписанный сертификат); сайты Pixabay/Pexels проверяются как обычно
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fh);
            if ($ok !== false && $status === 200 && filesize($dest) > 0) {
                return;
            }
            @unlink($dest);
            if ($status >= 400 && $status < 500 && $status !== 429) {
                throw new \RuntimeException("Скачивание не удалось: HTTP $status ($url)");
            }
            sleep($try * 2);
        }
        throw new \RuntimeException("Скачивание не удалось после 3 попыток: $url $err");
    }
}
