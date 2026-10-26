<?php
declare(strict_types=1);

namespace ShortsFactory;

interface Notifier
{
    public function send(string $text): void;
}
