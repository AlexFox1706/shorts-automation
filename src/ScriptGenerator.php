<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Этап script: тема, сценарий по сценам, заголовок, описание, утверждения с источниками. */
final class ScriptGenerator implements StageHandler
{
    public function __construct(
        private Database $db,
        private Config $config,
        private LlmClient $llm,
        private TopicHistory $history,
        private Logger $log,
    ) {}

    public function type(): string
    {
        return 'script';
    }

    public function run(array $video): ?string
    {
        $videoId = (int)$video['id'];
        $categories = (array)$this->config->get('content.categories');
        $category = $this->history->pickCategory($categories, (string)$video['scheduled_date'] . ':' . $video['script_attempts']);
        $hint = (string)($video['regen_hint'] ?? '');
        $tries = (int)$this->config->get('content.generation_tries', 3);
        $problem = '';

        for ($i = 1; $i <= $tries; $i++) {
            $res = $this->llm->generateJson($this->system(), $this->prompt($category, $hint, $problem));
            $this->log->info('script', "Gemini ответил (попытка $i)", ['video_id' => $videoId, 'cost' => $res['cost']]);
            try {
                $s = $this->validate($res['data']);
                $dup = $this->history->duplicateOf($s['topic_key'], $s['title']);
                if ($dup !== null) {
                    throw new InvalidModelOutput("Тема повторяет уже использованную: «{$dup}». Выбери другую.");
                }
            } catch (InvalidModelOutput $e) {
                $problem = $e->getMessage();
                $this->log->warning('script', 'сценарий отклонён: ' . $problem, ['video_id' => $videoId]);
                continue;
            }
            $this->save($videoId, $s, $category);
            return null;
        }
        throw new TransientError("Не удалось получить корректный сценарий за $tries попыток: $problem");
    }

    private function system(): string
    {
        return 'Ты автор сценариев для коротких познавательных видео (YouTube Shorts) на русском языке. '
            . 'Ты пишешь точно и не выдумываешь факты. Отвечаешь ТОЛЬКО одним JSON-объектом, без пояснений и без markdown.';
    }

    private function prompt(string $category, string $hint, string $problem): string
    {
        $min = (int)$this->config->get('content.words_min', 105);
        $max = (int)$this->config->get('content.words_max', 140);
        $avoid = [];
        foreach ($this->history->recent((int)$this->config->get('content.history_limit', 120)) as $r) {
            $avoid[] = $r['title'];
        }
        $p = "Придумай тему и напиши сценарий видео на 35-60 секунд. Рубрика: «{$category}».\n\n"
            . "Требования:\n"
            . "- Тема конкретная и проверяемая, не общие слова. Не выбирай темы, в которых не уверен.\n"
            . "- Сценарий разбит на 5-9 сцен. Первая сцена - цепляющий крючок (до 3 секунд речи). Последняя - короткий вывод.\n"
            . "- Суммарно в поле narration всех сцен от {$min} до {$max} слов. Простые короткие предложения для озвучки, без сокращений, без цифр в виде значков; числа и годы пиши так, как их читают вслух.\n"
            . "- Если что-то гипотеза, версия или легенда, прямо говори об этом в тексте («по одной из версий»), не подавай как установленный факт.\n"
            . "- visual_query: короткий поисковый запрос НА АНГЛИЙСКОМ (2-4 слова) для поиска стоковой съёмки сцены. Только то, что реально можно снять (места, предметы, природа, люди), без названий конкретных событий и фильмов.\n"
            . "- claims: 3-6 ключевых проверяемых утверждений из сценария. Для каждого дай source_url: реальную страницу, в существование которой ты уверен "
            . "(Википедия ru/en, Британника, NASA, Смитсоновский институт, научные издания). Не выдумывай ссылки. kind: fact, hypothesis или legend.\n"
            . "- hashtags: 3-5 штук на русском, без #shorts (я добавлю сам).\n\n"
            . "Формат ответа (JSON):\n"
            . '{"topic":"...","topic_key":"2-5 слов, суть темы","title":"до 80 символов, интригующий, без кликбейта-лжи","hook":"...",'
            . '"scenes":[{"narration":"...","visual_query":"..."}],"description":"2-3 предложения","hashtags":["#..."],'
            . '"claims":[{"claim":"...","kind":"fact","source_url":"https://...","source_title":"..."}]}' . "\n\n";
        if ($avoid !== []) {
            $p .= "Эти темы уже были, не повторяй их и близкие по сути:\n- " . implode("\n- ", array_slice($avoid, 0, 120)) . "\n\n";
        }
        if ($hint !== '') {
            $p .= "ВАЖНО, предыдущая попытка не подошла: {$hint}\nВыбери другую тему или исправь проблему.\n\n";
        }
        if ($problem !== '') {
            $p .= "ВАЖНО, прошлый ответ был отклонён: {$problem}\nИсправь это.\n";
        }
        return $p;
    }

    /**
     * Проверка структуры и содержания. @param array<string,mixed> $d
     * @return array<string,mixed> нормализованный сценарий
     */
    public function validate(array $d): array
    {
        $str = static function (string $k, int $max) use ($d): string {
            $v = $d[$k] ?? null;
            if (!is_string($v) || trim($v) === '') {
                throw new InvalidModelOutput("Нет обязательного поля «{$k}»");
            }
            return trim($v);
        };
        $title = $str('title', 100);
        if (mb_strlen($title) > 100) {
            throw new InvalidModelOutput('Заголовок длиннее 100 символов');
        }
        $s = [
            'topic' => $str('topic', 200), 'topic_key' => $str('topic_key', 100), 'title' => $title,
            'hook' => $str('hook', 300), 'description' => $str('description', 1000),
        ];

        $scenes = $d['scenes'] ?? null;
        $smin = (int)$this->config->get('content.scenes_min', 5);
        $smax = (int)$this->config->get('content.scenes_max', 9);
        if (!is_array($scenes) || count($scenes) < $smin || count($scenes) > $smax) {
            throw new InvalidModelOutput("Нужно от {$smin} до {$smax} сцен");
        }
        $words = 0;
        $s['scenes'] = [];
        foreach (array_values($scenes) as $i => $sc) {
            $n = is_array($sc) ? trim((string)($sc['narration'] ?? '')) : '';
            $q = is_array($sc) ? trim((string)($sc['visual_query'] ?? '')) : '';
            if ($n === '' || $q === '') {
                throw new InvalidModelOutput('В сцене ' . ($i + 1) . ' нет narration или visual_query');
            }
            if (mb_strlen($n) > 450) {
                throw new InvalidModelOutput('Сцена ' . ($i + 1) . ' слишком длинная');
            }
            $words += Text::wordCount($n);
            $s['scenes'][] = ['narration' => $n, 'visual_query' => mb_strtolower($q)];
        }
        $wmin = (int)$this->config->get('content.words_min', 105);
        $wmax = (int)$this->config->get('content.words_max', 140);
        if ($words < $wmin - 10 || $words > $wmax + 10) {
            throw new InvalidModelOutput("Сейчас {$words} слов в сценах, нужно от {$wmin} до {$wmax}");
        }

        $tags = [];
        foreach ((array)($d['hashtags'] ?? []) as $t) {
            $t = '#' . preg_replace('/[^\p{L}\p{N}_]+/u', '', ltrim((string)$t, '#'));
            if (mb_strlen($t) > 2 && !in_array(mb_strtolower($t), array_map('mb_strtolower', $tags), true) && mb_strtolower($t) !== '#shorts') {
                $tags[] = $t;
            }
        }
        $s['hashtags'] = array_slice($tags, 0, 5);

        $claims = [];
        foreach ((array)($d['claims'] ?? []) as $c) {
            $url = is_array($c) ? trim((string)($c['source_url'] ?? '')) : '';
            $claim = is_array($c) ? trim((string)($c['claim'] ?? '')) : '';
            $kind = is_array($c) && in_array($c['kind'] ?? '', ['fact', 'hypothesis', 'legend'], true) ? $c['kind'] : 'fact';
            if ($claim === '' || !preg_match('~^https?://[^\s/]+\.[^\s/]+~i', $url) || Text::looksPlaceholder($url . $claim)) {
                continue;
            }
            $claims[] = ['claim' => $claim, 'kind' => $kind, 'source_url' => $url, 'source_title' => trim((string)($c['source_title'] ?? ''))];
        }
        if (count($claims) < 2) {
            throw new InvalidModelOutput('Нужно минимум 2 проверяемых утверждения с реальными ссылками на источники');
        }
        $s['claims'] = array_slice($claims, 0, 8);

        foreach ([$s['title'], $s['description'], implode(' ', array_column($s['scenes'], 'narration'))] as $text) {
            if (Text::looksPlaceholder($text)) {
                throw new InvalidModelOutput('В тексте остались шаблонные или фиктивные вставки');
            }
        }
        return $s;
    }

    /** @param array<string,mixed> $s */
    private function save(int $videoId, array $s, string $category): void
    {
        $script = implode(' ', array_column($s['scenes'], 'narration'));
        $meta = ['hook' => $s['hook'], 'scenes' => $s['scenes'], 'hashtags' => $s['hashtags'], 'claims' => $s['claims']];
        $sources = array_map(fn($c) => ['title' => $c['source_title'], 'url' => $c['source_url']], $s['claims']);
        $this->db->transaction(function (Database $db) use ($videoId, $s, $script, $meta, $sources, $category) {
            $db->exec(
                'UPDATE videos SET topic = ?, title = ?, script = ?, description = ?, sources_json = ?, meta_json = ?,
                 topic_key = ?, category = ?, regen_hint = NULL, updated_at = ? WHERE id = ?',
                [$s['topic'], $s['title'], $script, $s['description'],
                 json_encode($sources, JSON_UNESCAPED_UNICODE), json_encode($meta, JSON_UNESCAPED_UNICODE),
                 $s['topic_key'], $category, Clock::now(), $videoId]
            );
            $this->history->record($videoId, $s['topic_key'], $s['title'], $category);
        });
    }
}
