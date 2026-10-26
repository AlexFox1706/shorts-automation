<?php
declare(strict_types=1);

namespace ShortsFactory;

/** HTTP через cURL: тайм-ауты, повторы с экспоненциальной задержкой, единая обработка ошибок. */
final class HttpClient
{
    /** @var callable */
    private $transport;
    /** @var callable */
    private $sleep;

    public function __construct(
        ?callable $transport = null,
        private int $timeout = 30,
        private int $retries = 2,
        ?callable $sleep = null,
    ) {
        $this->transport = $transport ?? [self::class, 'curl'];
        $this->sleep = $sleep ?? static function (int $s): void { sleep($s); };
    }

    /** @return array{status:int,headers:array<string,string>,body:string,error:string} */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?int $timeout = null): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $res = ($this->transport)($method, $url, $headers, $body, $timeout ?? $this->timeout);
            $s = $res['status'];
            if (($s === 0 || $s === 429 || $s >= 500) && $attempt <= $this->retries) {
                $wait = isset($res['headers']['retry-after']) ? min(30, (int)$res['headers']['retry-after']) : min(20, 2 ** $attempt);
                ($this->sleep)(max(1, $wait));
                continue;
            }
            return $res;
        }
    }

    /**
     * Запрос с JSON-ответом. Исключения: TransientError (сеть, 429, 5xx), PermanentError (остальные 4xx).
     * @return array<string,mixed>
     */
    public function json(string $method, string $url, array $headers = [], array|string|null $body = null, ?int $timeout = null): array
    {
        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }
        $res = $this->request($method, $url, $headers, $body, $timeout);
        self::assertOk($res);
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            throw new TransientError('Ответ API не JSON: ' . mb_substr($res['body'], 0, 200));
        }
        return $data;
    }

    public static function assertOk(array $res): void
    {
        $s = $res['status'];
        if ($s >= 200 && $s < 300) {
            return;
        }
        $msg = self::errorMessage($res);
        if ($s === 0) {
            throw new TransientError('Нет связи: ' . $res['error']);
        }
        if ($s === 429 || $s >= 500) {
            throw new TransientError("HTTP $s: $msg");
        }
        throw new PermanentError("HTTP $s: $msg");
    }

    public static function errorMessage(array $res): string
    {
        $d = json_decode($res['body'], true);
        if (is_array($d)) {
            $m = $d['error']['message'] ?? $d['error_description'] ?? $d['description'] ?? (is_string($d['error'] ?? null) ? $d['error'] : null);
            if (is_string($m)) {
                return mb_substr($m, 0, 400);
            }
        }
        return mb_substr(trim(strip_tags($res['body'])), 0, 300);
    }

    /** @return array{status:int,headers:array<string,string>,body:string,error:string} */
    public static function curl(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => $out === false ? 0 : $status, 'headers' => $respHeaders, 'body' => is_string($out) ? $out : '', 'error' => $err];
    }
}
