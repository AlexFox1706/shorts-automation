<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Retry later with backoff (network, 5xx, rate limit). */
class TransientError extends \RuntimeException {}
