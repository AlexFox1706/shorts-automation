<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Placeholder until TelegramReporter exists (stage 5): writes notifications to the log. */
final class LogNotifier implements Notifier
{
    public function __construct(private Logger $log) {}

    public function send(string $text): void
    {
        $this->log->info('notify', $text);
    }
}
