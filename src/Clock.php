<?php
declare(strict_types=1);

namespace ShortsFactory;

/** All timestamps in the DB are UTC "Y-m-d H:i:s". Business dates use the configured timezone. */
final class Clock
{
    public static ?int $fixed = null; // for tests

    public static function ts(): int
    {
        return self::$fixed ?? time();
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s', self::ts());
    }

    public static function at(int $offsetSeconds): string
    {
        return gmdate('Y-m-d H:i:s', self::ts() + $offsetSeconds);
    }

    public static function today(string $tz): string
    {
        return (new \DateTimeImmutable('@' . self::ts()))
            ->setTimezone(new \DateTimeZone($tz))->format('Y-m-d');
    }

    public static function hour(string $tz): int
    {
        return (int)(new \DateTimeImmutable('@' . self::ts()))
            ->setTimezone(new \DateTimeZone($tz))->format('G');
    }
}
