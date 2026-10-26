<?php
declare(strict_types=1);

// Раннер (GitHub Actions): 1) пинает хостинг (tick), 2) забирает задание на сборку, 3) собирает и отправляет видео.
// Переменные окружения: SHORTS_HOST_URL (адрес web/index.php), SHORTS_TOKEN (код доступа).
require __DIR__ . '/../autoload.php';

use ShortsFactory\{HttpClient, RunnerClient, RunnerJob, VideoRenderer};

$url = (string)getenv('SHORTS_HOST_URL');
$token = (string)getenv('SHORTS_TOKEN');
if ($url === '' || $token === '') {
    fwrite(STDERR, "Задайте SHORTS_HOST_URL и SHORTS_TOKEN\n");
    exit(2);
}
$log = static function (string $m): void { echo '[' . gmdate('H:i:s') . "] $m\n"; };
$client = new RunnerClient(new HttpClient(null, 120, 2), $url, $token);

$tick = static function () use ($client, $log): int {
    $r = $client->get('tick', [], 150);
    $w = $r['result']['worker']['processed'] ?? 0;
    $log('tick: обработано задач ' . $w . (isset($r['result']['daily']['reason']) && !$r['result']['daily']['created'] ? ', выпуск: ' . $r['result']['daily']['reason'] : ''));
    return (int)$w;
};

for ($i = 0; $i < 12 && $tick() > 0; $i++) {
    // пока хостинг что-то делает, продолжаем
}

$job = $client->get('render_next')['job'] ?? null;
if ($job === null) {
    $log('заданий на сборку нет');
    exit(0);
}

$work = sys_get_temp_dir() . '/shorts_job_' . (int)$job['id'];
$font = (string)(getenv('SHORTS_FONT') ?: 'DejaVu Sans');
try {
    (new RunnerJob($client, new VideoRenderer('ffmpeg', $font, $log), $log))->run($job, $work);
} catch (Throwable $e) {
    $log('ОШИБКА: ' . $e->getMessage());
    try {
        $client->post('job_failed', [], json_encode(['id' => (int)$job['id'], 'message' => $e->getMessage()]));
    } catch (Throwable $e2) {
        $log('не удалось сообщить об ошибке: ' . $e2->getMessage());
    }
    exit(1);
}
for ($i = 0; $i < 6 && $tick() > 0; $i++) {
    // хостинг проверит видео и отправит на подтверждение
}
exit(0);
