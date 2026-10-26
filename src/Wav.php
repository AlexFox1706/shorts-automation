<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Минимальная работа с WAV (PCM 16 бит) на чистом PHP: хостингу не нужен ffmpeg. */
final class Wav
{
    /** @return array{rate:int,channels:int,bits:int,pcm:string} */
    public static function parse(string $bytes): array
    {
        if (strlen($bytes) < 12 || !str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WAVE') {
            // Сырой PCM без заголовка: по документации Gemini это L16, 24 кГц, моно.
            return ['rate' => 24000, 'channels' => 1, 'bits' => 16, 'pcm' => $bytes];
        }
        $pos = 12;
        $len = strlen($bytes);
        $fmt = null;
        while ($pos + 8 <= $len) {
            $id = substr($bytes, $pos, 4);
            $size = unpack('V', substr($bytes, $pos + 4, 4))[1];
            $body = $pos + 8;
            if ($id === 'fmt ') {
                $f = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', substr($bytes, $body, 16));
                $fmt = $f;
            } elseif ($id === 'data') {
                if ($size === 0xFFFFFFFF || $body + $size > $len) {
                    $size = $len - $body; // потоковый или обрезанный заголовок
                }
                if ($fmt === null) {
                    throw new PermanentError('WAV без блока fmt');
                }
                if ($fmt['format'] !== 1 || $fmt['bits'] !== 16) {
                    throw new PermanentError("Неподдерживаемый WAV: format={$fmt['format']}, bits={$fmt['bits']}");
                }
                return ['rate' => $fmt['rate'], 'channels' => $fmt['channels'], 'bits' => 16, 'pcm' => substr($bytes, $body, $size)];
            }
            $pos = $body + $size + ($size & 1);
        }
        throw new PermanentError('В WAV нет блока data');
    }

    public static function build(string $pcm, int $rate = 24000, int $channels = 1): string
    {
        $byteRate = $rate * $channels * 2;
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, $channels, $rate, $byteRate, $channels * 2, 16)
            . 'data' . pack('V', strlen($pcm)) . $pcm;
    }

    public static function seconds(string $wav): float
    {
        $p = self::parse($wav);
        return strlen($p['pcm']) / max(1, $p['rate'] * $p['channels'] * 2);
    }

    /**
     * Склеить WAV-файлы с паузами. Все должны иметь одинаковую частоту и каналы.
     * @param list<string> $wavs
     */
    public static function concat(array $wavs, float $gapSeconds = 0.0, float $leadSeconds = 0.0): string
    {
        $rate = null;
        $ch = null;
        $pcm = '';
        foreach ($wavs as $i => $w) {
            $p = self::parse($w);
            $rate ??= $p['rate'];
            $ch ??= $p['channels'];
            if ($p['rate'] !== $rate || $p['channels'] !== $ch) {
                throw new PermanentError('Части озвучки с разной частотой или каналами');
            }
            if ($i === 0 && $leadSeconds > 0) {
                $pcm .= self::silence($leadSeconds, $rate, $ch);
            }
            $pcm .= $p['pcm'];
            if ($i < count($wavs) - 1 && $gapSeconds > 0) {
                $pcm .= self::silence($gapSeconds, $rate, $ch);
            }
        }
        return self::build($pcm, $rate ?? 24000, $ch ?? 1);
    }

    private static function silence(float $s, int $rate, int $ch): string
    {
        return str_repeat("\0", (int)round($s * $rate) * $ch * 2);
    }
}
