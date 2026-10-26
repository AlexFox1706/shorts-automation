<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Небольшие текстовые утилиты (русский язык, без внешних библиотек). */
final class Text
{
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace('ё', 'е', $s);
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '');
    }

    /** Токены с грубым «стеммингом» (обрезка до 5 букв): хватает для сравнения тем. @return list<string> */
    public static function tokens(string $s): array
    {
        $out = [];
        foreach (explode(' ', self::norm($s)) as $w) {
            if (mb_strlen($w) >= 3) {
                $out[] = mb_substr($w, 0, 5);
            }
        }
        return array_values(array_unique($out));
    }

    public static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $i = count(array_intersect($a, $b));
        $u = count(array_unique(array_merge($a, $b)));
        return $u === 0 ? 0.0 : $i / $u;
    }

    public static function wordCount(string $s): int
    {
        return preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'-]*/u', $s) ?: 0;
    }

    /** Признаки незаполненного шаблона или фиктивных данных. */
    public static function looksPlaceholder(string $s): bool
    {
        return (bool)preg_match('~example\.(com|org|net)|\{\{|\}\}|\[\[|\]\]|lorem ipsum|\bTODO\b|\bTBD\b|<[^>]+>|твой[_ ]текст|название источника~iu', $s);
    }

    /** Выбрать из текста абзацы, ближе всего к утверждению. */
    public static function excerpt(string $text, string $claim, int $max = 1800): string
    {
        $want = self::tokens($claim);
        $scored = [];
        foreach (preg_split('/\n+/u', $text) ?: [] as $i => $para) {
            $para = trim($para);
            if (mb_strlen($para) < 40) {
                continue;
            }
            $score = count(array_intersect($want, self::tokens($para)));
            if ($score > 0) {
                $scored[] = [$score, $i, $para];
            }
        }
        usort($scored, fn($a, $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);
        $picked = [];
        $len = 0;
        foreach ($scored as [$score, $i, $para]) {
            if ($len + mb_strlen($para) > $max && $picked !== []) {
                continue;
            }
            $picked[$i] = mb_substr($para, 0, $max);
            $len += mb_strlen($picked[$i]);
            if (count($picked) >= 4 || $len >= $max) {
                break;
            }
        }
        ksort($picked);
        return implode("\n", $picked);
    }

    /** Разбить фразу на строки не длиннее $width символов. @return list<string> */
    public static function wrap(string $s, int $width): array
    {
        $lines = [];
        $cur = '';
        foreach (preg_split('/\s+/u', trim($s)) ?: [] as $w) {
            if ($cur !== '' && mb_strlen($cur . ' ' . $w) > $width) {
                $lines[] = $cur;
                $cur = $w;
            } else {
                $cur = $cur === '' ? $w : "$cur $w";
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $lines;
    }
}
