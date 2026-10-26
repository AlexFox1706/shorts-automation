<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Do not retry (bad auth, exhausted quota, invalid data). */
class PermanentError extends \RuntimeException {}
