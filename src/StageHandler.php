<?php
declare(strict_types=1);

namespace ShortsFactory;

interface StageHandler
{
    /** Task type this handler serves, one of Pipeline::STAGES keys. */
    public function type(): string;

    /**
     * Do the work for one video. Must be idempotent: it may be re-run after a crash.
     * Persist results (script, audio_path, ...) to the DB itself.
     * Throw TransientError / PermanentError on failure, Deferred to wait without spending an attempt,
     * RegenerateScript to restart from a new script.
     * Return null for the default status, or a status name to override the stage's "done" status.
     *
     * @param array<string,mixed> $video row from videos
     */
    public function run(array $video): ?string;
}
