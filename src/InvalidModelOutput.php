<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Модель вернула ответ, не подходящий по формату или содержанию (повторяем запрос). */
final class InvalidModelOutput extends \RuntimeException {}
