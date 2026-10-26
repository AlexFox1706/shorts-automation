<?php
declare(strict_types=1);

namespace ShortsFactory;

/**
 * Этап factcheck. Ссылку от модели нельзя принимать на веру, поэтому:
 * 1) страница каждого источника скачивается; 2) модель получает только реальные фрагменты страницы;
 * 3) цитату-доказательство код проверяет сам: она должна дословно встретиться на странице.
 */
final class FactChecker implements StageHandler
{
    public function __construct(
        private Database $db,
        private Config $config,
        private LlmClient $llm,
        private SourceFetcher $fetcher,
        private Logger $log,
    ) {}

    public function type(): string
    {
        return 'factcheck';
    }

    public function run(array $video): ?string
    {
        $videoId = (int)$video['id'];
        $meta = json_decode((string)$video['meta_json'], true);
        $claims = $meta['claims'] ?? [];
        if (!is_array($claims) || $claims === []) {
            throw new RegenerateScript('в сценарии нет утверждений для проверки');
        }

        // 1. Скачать уникальные источники.
        $pages = [];
        foreach (array_slice(array_values(array_unique(array_column($claims, 'source_url'))), 0, 8) as $url) {
            $pages[$url] = $this->fetcher->fetchText($url);
            $this->log->info('factcheck', ($pages[$url]['ok'] ? 'источник доступен: ' : 'источник недоступен: ') . $url
                . ($pages[$url]['ok'] ? '' : ' (' . $pages[$url]['error'] . ')'), ['video_id' => $videoId]);
        }

        // 2. Фрагменты страниц для каждого утверждения.
        $items = [];
        foreach ($claims as $i => $c) {
            $page = $pages[$c['source_url']] ?? ['ok' => false];
            $excerpt = $page['ok'] ? Text::excerpt($page['text'], $c['claim']) : '';
            $items[] = ['id' => $i + 1, 'claim' => $c['claim'], 'source_url' => $c['source_url'], 'excerpt' => $excerpt];
        }

        // 3. Один запрос к модели по тем утверждениям, где есть что показать.
        $askable = array_values(array_filter($items, fn($it) => $it['excerpt'] !== ''));
        $verdicts = [];
        $uncertain = [];
        $unsupported = [];
        if ($askable !== []) {
            $res = $this->llm->generateJson($this->system(), $this->prompt($askable, $meta['scenes'] ?? []));
            $this->log->info('factcheck', 'модель проверила утверждения', ['video_id' => $videoId, 'cost' => $res['cost']]);
            foreach ((array)($res['data']['claims'] ?? []) as $v) {
                if (is_array($v) && isset($v['id'])) {
                    $verdicts[(int)$v['id']] = $v;
                }
            }
            $uncertain = array_values(array_filter((array)($res['data']['presented_as_fact_but_uncertain'] ?? []), 'is_string'));
            $unsupported = array_values(array_filter((array)($res['data']['unsupported_statements'] ?? []), 'is_string'));
        }

        // 4. Проверка цитат кодом и решение.
        $report = [];
        foreach ($items as $it) {
            $page = $pages[$it['source_url']] ?? ['ok' => false, 'text' => ''];
            $v = $verdicts[$it['id']] ?? null;
            $result = self::judge($it, $page, $v);
            $report[] = $result;
        }
        $decision = self::decide($report, $uncertain, (float)$this->config->get('factcheck.min_ratio', 0.75), (int)$this->config->get('factcheck.min_sources', 2));

        $meta['factcheck'] = ['report' => $report, 'uncertain' => $uncertain, 'unsupported' => $unsupported, 'ok' => $decision['ok']];
        $verified = [];
        foreach ($report as $r) {
            if ($r['status'] === 'verified') {
                $verified[$r['source_url']] = ['title' => $claims[$r['id'] - 1]['source_title'] ?: $r['source_url'], 'url' => $r['source_url']];
            }
        }
        $this->db->exec(
            'UPDATE videos SET meta_json = ?, sources_json = ?, updated_at = ? WHERE id = ?',
            [json_encode($meta, JSON_UNESCAPED_UNICODE), json_encode(array_values($verified), JSON_UNESCAPED_UNICODE), Clock::now(), $videoId]
        );
        if ($unsupported !== []) {
            $this->log->warning('factcheck', 'утверждения без источника: ' . implode(' | ', array_slice($unsupported, 0, 3)), ['video_id' => $videoId]);
        }
        if (!$decision['ok']) {
            throw new RegenerateScript('проверка фактов не пройдена: ' . $decision['why']);
        }
        return null;
    }

    private function system(): string
    {
        return 'Ты строгий проверяющий фактов. Используешь только предоставленные фрагменты источников, ничего не додумываешь. '
            . 'Отвечаешь ТОЛЬКО одним JSON-объектом.';
    }

    /** @param list<array<string,mixed>> $items @param list<array<string,string>> $scenes */
    private function prompt(array $items, array $scenes): string
    {
        $p = "Для каждого утверждения даны ФРАГМЕНТЫ его источника. Определи verdict:\n"
            . "- supported: фрагмент прямо подтверждает утверждение;\n- contradicted: фрагмент прямо ему противоречит;\n"
            . "- not_found: во фрагментах нет подтверждения.\n"
            . "Для supported и contradicted приведи evidence_quote: ДОСЛОВНАЯ цитата из фрагмента (до 200 символов, без изменений).\n"
            . "Затем прочитай текст сценария и перечисли в presented_as_fact_but_uncertain реплики, где гипотеза, легенда или спорное подано как установленный факт, "
            . "а в unsupported_statements - фактические утверждения сценария, которых нет среди проверяемых.\n\n";
        foreach ($items as $it) {
            $p .= "### Утверждение {$it['id']}: {$it['claim']}\nФРАГМЕНТЫ ({$it['source_url']}):\n{$it['excerpt']}\n\n";
        }
        $p .= "### Текст сценария:\n" . implode("\n", array_column($scenes, 'narration')) . "\n\n"
            . 'Формат: {"claims":[{"id":1,"verdict":"supported","evidence_quote":"...","note":"..."}],'
            . '"presented_as_fact_but_uncertain":["..."],"unsupported_statements":["..."]}';
        return $p;
    }

    /**
     * Итог по одному утверждению. Чистая функция, проверяется тестами.
     * @param array{id:int,claim:string,source_url:string,excerpt:string} $item
     * @param array{ok:bool,text?:string} $page
     * @return array{id:int,claim:string,source_url:string,status:string,note:string}
     */
    public static function judge(array $item, array $page, ?array $verdict): array
    {
        $base = ['id' => $item['id'], 'claim' => $item['claim'], 'source_url' => $item['source_url']];
        if (!$page['ok']) {
            return $base + ['status' => 'source_unavailable', 'note' => 'страница источника недоступна'];
        }
        if ($item['excerpt'] === '') {
            return $base + ['status' => 'not_found', 'note' => 'на странице нет текста, близкого к утверждению'];
        }
        $v = (string)($verdict['verdict'] ?? '');
        if ($v === 'contradicted') {
            return $base + ['status' => 'contradicted', 'note' => (string)($verdict['note'] ?? '')];
        }
        if ($v !== 'supported') {
            return $base + ['status' => 'not_found', 'note' => (string)($verdict['note'] ?? 'подтверждения нет')];
        }
        $quote = Text::norm((string)($verdict['evidence_quote'] ?? ''));
        if (mb_strlen($quote) < 15 || !str_contains(Text::norm((string)($page['text'] ?? '')), $quote)) {
            return $base + ['status' => 'quote_not_on_page', 'note' => 'цитата-доказательство не найдена на странице'];
        }
        return $base + ['status' => 'verified', 'note' => ''];
    }

    /**
     * @param list<array<string,mixed>> $report
     * @param list<string> $uncertain
     * @return array{ok:bool,why:string}
     */
    public static function decide(array $report, array $uncertain, float $minRatio, int $minSources): array
    {
        $total = count($report);
        $verified = array_filter($report, fn($r) => $r['status'] === 'verified');
        $contra = array_filter($report, fn($r) => $r['status'] === 'contradicted');
        $domains = [];
        foreach ($verified as $r) {
            $domains[strtolower((string)parse_url($r['source_url'], PHP_URL_HOST))] = true;
        }
        if ($contra !== []) {
            return ['ok' => false, 'why' => 'источник противоречит: «' . array_values($contra)[0]['claim'] . '»'];
        }
        if ($total === 0 || count($verified) / $total < $minRatio) {
            $bad = array_values(array_filter($report, fn($r) => $r['status'] !== 'verified'));
            return ['ok' => false, 'why' => sprintf('подтверждено %d из %d утверждений (не подтверждено: «%s»)', count($verified), $total, $bad[0]['claim'] ?? '-')];
        }
        if (count($domains) < $minSources) {
            return ['ok' => false, 'why' => 'подтверждающих источников меньше ' . $minSources];
        }
        if ($uncertain !== []) {
            return ['ok' => false, 'why' => 'спорное подано как факт: «' . mb_substr($uncertain[0], 0, 120) . '»'];
        }
        return ['ok' => true, 'why' => ''];
    }
}
