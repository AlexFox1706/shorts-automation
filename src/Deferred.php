<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Этап не готов, но это не ошибка: вернуть задачу в очередь без траты попытки. */
final class Deferred extends \RuntimeException
{
    public function __construct(public readonly int $seconds, string $reason = '')
    {
        parent::__construct($reason);
    }
}
