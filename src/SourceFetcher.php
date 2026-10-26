<?php
declare(strict_types=1);

namespace ShortsFactory;

/**
 * Безопасная загрузка страницы-источника: только http(s), без внутренних адресов (SSRF),
 * лимит размера и редиректов, адрес сервера фиксируется на время запроса.
 */
final class SourceFetcher
{
    /** @var callable|null */
    private $fetch;

    public function __construct(?callable $fetch = null, private int $maxBytes = 1_500_000, private int $timeout = 15)
    {
        $this->fetch = $fetch;
    }

    /** @return array{ok:bool,text:string,error:string} */
    public function fetchText(string $url): array
    {
        if ($this->fetch !== null) {
            return ($this->fetch)($url);
        }
        try {
            $url = trim($url);
            for ($hop = 0; $hop <= 3; $hop++) {
                [$status, $headers, $body] = $this->get($url);
                if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                    $url = self::absolute($url, $headers['location']);
                    continue;
                }
                if ($status !== 200) {
                    return ['ok' => false, 'text' => '', 'error' => "HTTP $status"];
                }
                $ct = strtolower($headers['content-type'] ?? '');
                if ($ct !== '' && !preg_match('~text/|xml|json~', $ct)) {
                    return ['ok' => false, 'text' => '', 'error' => "тип содержимого $ct не поддерживается"];
                }
                $text = self::htmlToText($body, $ct);
                return mb_strlen($text) < 200
                    ? ['ok' => false, 'text' => '', 'error' => 'на странице почти нет текста']
                    : ['ok' => true, 'text' => $text, 'error' => ''];
            }
            return ['ok' => false, 'text' => '', 'error' => 'слишком много перенаправлений'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => '', 'error' => $e->getMessage()];
        }
    }

    /** @return array{0:int,1:array<string,string>,2:string} */
    private function get(string $url): array
    {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        $host = strtolower($p['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('допустимы только http/https адреса');
        }
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            throw new \RuntimeException('нестандартный порт запрещён');
        }
        $ip = self::resolvePublicIp($host);

        $headers = [];
        $body = '';
        $max = $this->maxBytes;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["$host:$port:$ip"],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'ShortsFactoryBot/1.0 (fact checking; contact: site admin)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,text/plain;q=0.9', 'Accept-Language: ru,en;q=0.8'],
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $l) use (&$headers): int {
                if (str_contains($l, ':')) {
                    [$k, $v] = explode(':', $l, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($l);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $max): int {
                $body .= $chunk;
                return strlen($body) > $max ? -1 : strlen($chunk); // слишком большой ответ: берём начало
            },
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($status === 0 && $body === '') {
            throw new \RuntimeException($err ?: 'нет ответа');
        }
        return [$status ?: 200, $headers, $body];
    }

    private static function resolvePublicIp(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = gethostbynamel($host) ?: [];
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
        throw new \RuntimeException('адрес не разрешается в публичный IPv4');
    }

    private static function absolute(string $base, string $loc): string
    {
        if (preg_match('~^https?://~i', $loc)) {
            return $loc;
        }
        $p = parse_url($base);
        $root = $p['scheme'] . '://' . $p['host'];
        if (str_starts_with($loc, '/')) {
            return $root . $loc;
        }
        return $root . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $loc;
    }

    public static function htmlToText(string $html, string $contentType = ''): string
    {
        if (preg_match('~charset=([\w-]+)~i', $contentType, $m) || preg_match('~<meta[^>]+charset=["\']?([\w-]+)~i', substr($html, 0, 4096), $m)) {
            $cs = strtoupper($m[1]);
            if ($cs !== 'UTF-8' && $cs !== 'UTF8') {
                $conv = @mb_convert_encoding($html, 'UTF-8', $cs);
                $html = is_string($conv) ? $conv : $html;
            }
        }
        $html = preg_replace('~<!--.*?-->~s', ' ', $html) ?? $html;
        $html = preg_replace('~<(script|style|noscript|svg|nav|header|footer|aside|form|table)\b.*?</\1>~is', ' ', $html) ?? $html;
        $html = preg_replace('~</?(p|div|br|li|ul|ol|h[1-6]|section|article|tr|blockquote)\b[^>]*>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;
        return trim($text);
    }
}
