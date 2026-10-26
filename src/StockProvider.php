<?php
declare(strict_types=1);

namespace ShortsFactory;

/** Источник стоковых видео и фото (Pexels, Pixabay). Лицензия и автор записываются для каждой сцены. */
interface StockProvider
{
    public function name(): string;     // pexels | pixabay (префикс id и подпись лицензии)
    public function label(): string;    // Pexels | Pixabay (для людей)
    /** @return list<array{id:string,page_url:string,author:string,duration:float,link:string,width:int,height:int}> */
    public function searchVideos(string $query, int $perPage = 15): array;
    /** @return list<array{id:string,page_url:string,author:string,link:string,width:int,height:int}> */
    public function searchPhotos(string $query, int $perPage = 12): array;
}
