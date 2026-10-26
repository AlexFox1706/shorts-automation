<?php
declare(strict_types=1);

namespace ShortsFactory;

/** ASS-субтитры: единый стиль, безопасные поля, поднятые над нижней панелью YouTube Shorts. */
final class SubtitleBuilder
{
    /** @param list<array{start:float,end:float,text:string}> $subs */
    public static function ass(array $subs, int $w, int $h, string $font): string
    {
        $size = (int)round($w * 0.066);          // 72 при 1080
        $marginV = (int)round($h * 0.25);        // ниже этой линии кнопки и подпись Shorts
        $marginX = (int)round($w * 0.09);
        $out = "[Script Info]\nScriptType: v4.00+\nPlayResX: $w\nPlayResY: $h\nWrapStyle: 2\nScaledBorderAndShadow: yes\n\n"
            . "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
            . "Style: Main,$font,$size,&H00FFFFFF,&H000000FF,&H00101010,&H80000000,-1,0,0,0,100,100,0,0,1,6,3,2,$marginX,$marginX,$marginV,1\n\n"
            . "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        foreach ($subs as $s) {
            $text = str_replace(['\\', '{', '}'], ['', '(', ')'], $s['text']);
            $text = str_replace("\n", '\N', $text);
            $out .= sprintf("Dialogue: 0,%s,%s,Main,,0,0,0,,%s\n", self::time($s['start']), self::time($s['end']), $text);
        }
        return $out;
    }

    public static function time(float $t): string
    {
        $cs = (int)round($t * 100);
        return sprintf('%d:%02d:%02d.%02d', intdiv($cs, 360000), intdiv($cs % 360000, 6000), intdiv($cs % 6000, 100), $cs % 100);
    }
}
