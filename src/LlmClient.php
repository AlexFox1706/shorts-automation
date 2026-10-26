<?php
declare(strict_types=1);

namespace ShortsFactory;

interface LlmClient
{
    /**
     * Запросить JSON. Ответ разбирается и возвращается как массив.
     * @return array{data:array<string,mixed>,cost:float,raw:string}
     */
    public function generateJson(string $system, string $prompt): array;

    /**
     * Озвучить текст. @return array{wav:string,cost:float}  WAV 16 бит моно
     */
    public function tts(string $text): array;
}
