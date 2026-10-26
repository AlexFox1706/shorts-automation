<?php
declare(strict_types=1);

namespace ShortsFactory;

/**
 * Gemini API. Документация Google описывает Interactions API (/interactions) и старый generateContent,
 * в примерах встречаются адреса /v1beta и /v1beta2. Не проверяя вживую, надёжнее перебрать варианты:
 * при 404 пробуется следующий, рабочий запоминается в settings (gemini_variant).
 */
final class GeminiClient implements LlmClient
{
    private const VARIANTS = [
        'interactions:v1beta', 'interactions:v1beta2', 'generate_content:v1beta',
    ];

    public function __construct(
        private HttpClient $http,
        private Config $config,
        private Settings $settings,
    ) {}

    private function key(): string
    {
        $k = (string)$this->config->get('gemini.api_key', '');
        if ($k === '') {
            throw new PermanentError('Не задан gemini.api_key в config.php');
        }
        return $k;
    }

    private function model(string $kind): string
    {
        $m = (string)$this->config->get($kind === 'tts' ? 'gemini.tts_model' : 'gemini.model', '');
        if ($m === '') {
            throw new PermanentError('Не задана модель Gemini (' . ($kind === 'tts' ? 'gemini.tts_model' : 'gemini.model') . ') в config.php');
        }
        return preg_replace('~^models/~', '', $m);
    }

    /** @return list<string> */
    private function variantOrder(): array
    {
        $forced = (string)$this->config->get('gemini.api', 'auto');
        if ($forced !== 'auto' && in_array($forced, self::VARIANTS, true)) {
            return [$forced];
        }
        $known = $this->settings->get('gemini_variant', '');
        $order = self::VARIANTS;
        if ($known !== '' && in_array($known, $order, true)) {
            $order = array_values(array_unique(array_merge([$known], $order)));
        }
        return $order;
    }

    /** @param callable(string $variant): array{0:string,1:array} $build  returns [path, body] */
    private function call(callable $build): array
    {
        $last = null;
        foreach ($this->variantOrder() as $variant) {
            [$path, $body] = $build($variant);
            $res = $this->http->request(
                'POST',
                rtrim((string)$this->config->get('gemini.base_url', 'https://generativelanguage.googleapis.com'), '/') . $path,
                ['x-goog-api-key: ' . $this->key(), 'Content-Type: application/json'],
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                (int)$this->config->get('gemini.timeout', 90)
            );
            if ($res['status'] === 404) {
                $last = $res;
                continue; // другой формат адреса или неверное имя модели
            }
            HttpClient::assertOk($res);
            $data = json_decode($res['body'], true);
            if (!is_array($data)) {
                throw new TransientError('Gemini вернул не JSON');
            }
            $this->settings->set('gemini_variant', $variant);
            return [$variant, $data];
        }
        throw new PermanentError('Gemini: адрес API или имя модели не найдены (404). Проверьте gemini.model / gemini.tts_model. '
            . ($last ? HttpClient::errorMessage($last) : ''));
    }

    public function generateJson(string $system, string $prompt): array
    {
        $temperature = (float)$this->config->get('gemini.temperature', 0.9);
        $maxTokens = (int)$this->config->get('gemini.max_output_tokens', 8192);
        $model = $this->model('text');
        [$variant, $data] = $this->call(function (string $variant) use ($system, $prompt, $model, $temperature, $maxTokens) {
            if (str_starts_with($variant, 'interactions')) {
                $v = explode(':', $variant)[1];
                return ["/$v/interactions", [
                    'model' => $model,
                    'input' => $prompt,
                    'system_instruction' => $system,
                    'generation_config' => ['temperature' => $temperature, 'max_output_tokens' => $maxTokens],
                ]];
            }
            return ["/v1beta/models/$model:generateContent", [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens, 'responseMimeType' => 'application/json'],
            ]];
        });
        $text = self::extractText($data);
        return ['data' => self::parseJson($text), 'cost' => $this->cost($data, 'text'), 'raw' => $text];
    }

    public function tts(string $text): array
    {
        $model = $this->model('tts');
        $voice = (string)$this->config->get('gemini.voice', 'Kore');
        $input = (string)$this->config->get('gemini.tts_style', 'Читай по-русски, чётко и увлечённо, как рассказчик познавательного ролика: ') . $text;
        [$variant, $data] = $this->call(function (string $variant) use ($model, $voice, $input) {
            if (str_starts_with($variant, 'interactions')) {
                $v = explode(':', $variant)[1];
                return ["/$v/interactions", [
                    'model' => $model,
                    'input' => $input,
                    'response_format' => ['type' => 'audio'],
                    'generation_config' => ['speech_config' => [['voice' => $voice]]],
                ]];
            }
            return ["/v1beta/models/$model:generateContent", [
                'contents' => [['parts' => [['text' => $input]]]],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'],
                    'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]],
                ],
            ]];
        });
        $b64 = self::extractAudio($data);
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new TransientError('Gemini TTS вернул пустое или повреждённое аудио');
        }
        $p = Wav::parse($bytes);
        if ($p['pcm'] === '') {
            throw new TransientError('Gemini TTS вернул пустое аудио');
        }
        return ['wav' => Wav::build($p['pcm'], $p['rate'], $p['channels']), 'cost' => $this->cost($data, 'tts')];
    }

    public static function extractText(array $data): string
    {
        $out = '';
        if (isset($data['steps'])) {
            if (($data['status'] ?? 'completed') === 'failed') {
                throw new TransientError('Gemini: генерация завершилась ошибкой');
            }
            foreach ($data['steps'] as $step) {
                if (($step['type'] ?? '') === 'model_output') {
                    foreach ($step['content'] ?? [] as $c) {
                        if (($c['type'] ?? '') === 'text') {
                            $out .= (string)($c['text'] ?? '');
                        }
                    }
                }
            }
        } else {
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $p) {
                $out .= (string)($p['text'] ?? '');
            }
        }
        if (trim($out) === '') {
            throw new TransientError('Gemini вернул пустой текст (возможно, сработал фильтр безопасности)');
        }
        return $out;
    }

    public static function extractAudio(array $data): string
    {
        foreach ($data['steps'] ?? [] as $step) {
            foreach ($step['content'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'audio' && isset($c['data'])) {
                    return (string)$c['data'];
                }
            }
        }
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $p) {
            if (isset($p['inlineData']['data'])) {
                return (string)$p['inlineData']['data'];
            }
        }
        throw new TransientError('В ответе Gemini TTS нет аудио');
    }

    /** Достать JSON из ответа модели (с обёрткой ```json или лишним текстом). @return array<string,mixed> */
    public static function parseJson(string $text): array
    {
        $t = trim($text);
        $t = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $t) ?? $t;
        $d = json_decode($t, true);
        if (!is_array($d)) {
            $a = strpos($t, '{');
            $b = strrpos($t, '}');
            if ($a !== false && $b !== false && $b > $a) {
                $d = json_decode(substr($t, $a, $b - $a + 1), true);
            }
        }
        if (!is_array($d)) {
            throw new InvalidModelOutput('Ответ модели не является JSON');
        }
        return $d;
    }

    private function cost(array $data, string $kind): float
    {
        $in = (int)($data['usage']['total_input_tokens'] ?? $data['usageMetadata']['promptTokenCount'] ?? 0);
        $out = (int)(($data['usage']['total_output_tokens'] ?? $data['usageMetadata']['candidatesTokenCount'] ?? 0)
            + ($data['usage']['total_thought_tokens'] ?? $data['usageMetadata']['thoughtsTokenCount'] ?? 0));
        $pin = (float)$this->config->get("gemini.pricing.{$kind}_in", 0);
        $pout = (float)$this->config->get("gemini.pricing.{$kind}_out", 0);
        return ($in * $pin + $out * $pout) / 1_000_000;
    }
}
