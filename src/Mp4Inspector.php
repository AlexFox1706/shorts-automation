<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Читает заголовок MP4 на чистом PHP: хостингу не нужны ffprobe и exec(). */
final class Mp4Inspector
{
    /**
     * @return array{size:int,duration:float,width:int,height:int,has_audio:bool,vcodec:string,acodec:string,faststart:bool}
     */
    public static function inspect(string $path): array
    {
        $size = (int)@filesize($path);
        if ($size < 1024 || !($fh = @fopen($path, 'rb'))) {
            throw new \RuntimeException('файл отсутствует или пустой');
        }
        $pos = 0;
        $top = [];
        $moov = null;
        while ($pos < $size) {
            fseek($fh, $pos);
            $h = fread($fh, 16);
            if (strlen($h) < 8) {
                throw new \RuntimeException('файл обрезан (заголовок блока)');
            }
            $len = unpack('N', substr($h, 0, 4))[1];
            $type = substr($h, 4, 4);
            $hdr = 8;
            if ($len === 1) {
                $len = unpack('J', substr($h, 8, 8))[1];
                $hdr = 16;
            } elseif ($len === 0) {
                $len = $size - $pos;
            }
            if ($len < $hdr || $pos + $len > $size) {
                throw new \RuntimeException("файл обрезан или повреждён (блок $type)");
            }
            $top[] = $type;
            if ($type === 'moov') {
                if ($len > 32 * 1024 * 1024) {
                    throw new \RuntimeException('слишком большой moov');
                }
                fseek($fh, $pos + $hdr);
                $moov = (string)fread($fh, $len - $hdr);
            }
            $pos += $len;
        }
        fclose($fh);
        if (!in_array('ftyp', $top, true) || $moov === null || !in_array('mdat', $top, true)) {
            throw new \RuntimeException('это не полный MP4 (нет ftyp/moov/mdat)');
        }

        $info = ['size' => $size, 'duration' => 0.0, 'width' => 0, 'height' => 0, 'has_audio' => false,
            'vcodec' => '', 'acodec' => '', 'faststart' => array_search('moov', $top, true) < array_search('mdat', $top, true)];
        foreach (self::boxes($moov, 0, strlen($moov)) as [$t, $s, $e]) {
            if ($t === 'mvhd') {
                $v = ord($moov[$s]);
                [$scale, $dur] = $v === 1
                    ? [unpack('N', substr($moov, $s + 20, 4))[1], unpack('J', substr($moov, $s + 24, 8))[1]]
                    : [unpack('N', substr($moov, $s + 12, 4))[1], unpack('N', substr($moov, $s + 16, 4))[1]];
                $info['duration'] = $scale > 0 ? $dur / $scale : 0.0;
            } elseif ($t === 'trak') {
                self::track($moov, $s, $e, $info);
            }
        }
        return $info;
    }

    /** @return list<array{0:string,1:int,2:int}> тип, начало тела, конец */
    private static function boxes(string $d, int $start, int $end): array
    {
        $out = [];
        $p = $start;
        while ($p + 8 <= $end) {
            $len = unpack('N', substr($d, $p, 4))[1];
            $type = substr($d, $p + 4, 4);
            $hdr = 8;
            if ($len === 1) {
                $len = unpack('J', substr($d, $p + 8, 8))[1];
                $hdr = 16;
            } elseif ($len === 0) {
                $len = $end - $p;
            }
            if ($len < $hdr || $p + $len > $end) {
                break;
            }
            $out[] = [$type, $p + $hdr, $p + $len];
            $p += $len;
        }
        return $out;
    }

    private static function find(string $d, int $s, int $e, string $type): ?array
    {
        foreach (self::boxes($d, $s, $e) as $b) {
            if ($b[0] === $type) {
                return $b;
            }
        }
        return null;
    }

    private static function track(string $d, int $s, int $e, array &$info): void
    {
        $tkhd = self::find($d, $s, $e, 'tkhd');
        $mdia = self::find($d, $s, $e, 'mdia');
        if ($tkhd === null || $mdia === null) {
            return;
        }
        $v = ord($d[$tkhd[1]]);
        $o = $tkhd[1] + ($v === 1 ? 88 : 76);
        $w = unpack('N', substr($d, $o, 4))[1] >> 16;
        $h = unpack('N', substr($d, $o + 4, 4))[1] >> 16;
        $hdlr = self::find($d, $mdia[1], $mdia[2], 'hdlr');
        $kind = $hdlr ? substr($d, $hdlr[1] + 8, 4) : '';
        $codec = '';
        if (($minf = self::find($d, $mdia[1], $mdia[2], 'minf')) && ($stbl = self::find($d, $minf[1], $minf[2], 'stbl'))
            && ($stsd = self::find($d, $stbl[1], $stbl[2], 'stsd'))) {
            $codec = substr($d, $stsd[1] + 12, 4);
        }
        if ($kind === 'vide' && $info['vcodec'] === '') {
            $info['vcodec'] = $codec;
            $info['width'] = $w;
            $info['height'] = $h;
        } elseif ($kind === 'soun' && $info['acodec'] === '') {
            $info['acodec'] = $codec;
            $info['has_audio'] = true;
        }
    }
}
