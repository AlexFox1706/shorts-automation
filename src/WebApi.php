<?php
declare(strict_types=1);

namespace ShortsFactory;

/**
 * Все действия веб-входа. Не знает про HTTP-заголовки: получает параметры, возвращает описание ответа.
 * @phpstan-type Resp array{status:int,type:string,data:mixed}
 */
final class WebApi
{
    public function __construct(private array $app) {}

    /** @return array{status:int,type:string,data:mixed} type: json|text|file|redirect */
    public function handle(string $action, array $q, string $body): array
    {
        $runner = new Runner($this->app);
        $config = $this->app['config'];
        $jobs = $this->app['jobs'];
        $budget = (int)$config->get('queue.web_budget', 40);
        try {
            switch ($action) {
                case 'tick':
                    return $this->json(['ok' => true, 'result' => $runner->tick($budget)]);
                case 'daily':
                    return $this->json(['ok' => true, 'result' => $runner->daily(isset($q['force']))]);
                case 'worker':
                    return $this->json(['ok' => true, 'result' => $runner->worker($budget)]);
                case 'cleanup':
                    return $this->json(['ok' => true, 'result' => $runner->cleanup()]);
                case 'status':
                    return $this->json(['ok' => true, 'result' => $runner->status()]);
                case 'doctor':
                    return $this->text($this->lines((new Doctor($config, $this->app['settings']))->checks()));
                case 'selftest':
                    return $this->text($this->lines($runner->selftest()));
                case 'render_peek':
                    return $this->json(['ok' => true, 'job' => $jobs->peek()]);
                case 'render_next':
                    return $this->json(['ok' => true, 'job' => $jobs->next()]);
                case 'job_audio':
                    return ['status' => 200, 'type' => 'file', 'data' => ['path' => $jobs->audioPath((int)($q['id'] ?? 0)), 'mime' => 'audio/wav']];
                case 'job_asset':
                    return ['status' => 200, 'type' => 'file', 'data' => ['path' => $jobs->assetPath((int)($q['id'] ?? 0), (int)($q['asset'] ?? 0)), 'mime' => 'application/octet-stream']];
                case 'job_chunk':
                    $jobs->chunk((int)($q['id'] ?? 0), (int)($q['index'] ?? -1), $body);
                    return $this->json(['ok' => true]);
                case 'job_done':
                    $d = json_decode($body, true) ?: [];
                    return $this->json(['ok' => true, 'result' => $jobs->done((int)($d['id'] ?? 0), (int)($d['chunks'] ?? 0), (string)($d['sha256'] ?? ''), (int)($d['size'] ?? 0))]);
                case 'job_failed':
                    $d = json_decode($body, true) ?: [];
                    $jobs->fail((int)($d['id'] ?? 0), (string)($d['message'] ?? 'без описания'));
                    return $this->json(['ok' => true]);
                case 'youtube_auth':
                    return ['status' => 302, 'type' => 'redirect', 'data' => $this->app['auth']->authUrl()];
                case 'youtube_code':
                    $this->app['auth']->exchange((string)($q['code'] ?? ''));
                    return $this->text("Готово: YouTube авторизован. Токен сохранён.\nПроверка: ?action=selftest\n");
                default:
                    return $this->json(['ok' => false, 'error' => 'unknown action', 'actions' => [
                        'tick', 'daily', 'worker', 'cleanup', 'status', 'doctor', 'selftest', 'youtube_auth', 'youtube_code']], 400);
            }
        } catch (TransientError $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage(), 'retry' => true], 503);
        } catch (\Throwable $e) {
            $this->app['log']->error('web', $action . ': ' . get_class($e) . ': ' . $e->getMessage());
            return $this->json(['ok' => false, 'error' => $e->getMessage()], $e instanceof PermanentError ? 400 : 500);
        }
    }

    private function json(array $d, int $status = 200): array
    {
        return ['status' => $status, 'type' => 'json', 'data' => $d];
    }

    private function text(string $t): array
    {
        return ['status' => 200, 'type' => 'text', 'data' => $t];
    }

    /** @param list<array{0:string,1:string,2:string}> $rows */
    private function lines(array $rows): string
    {
        return implode('', array_map(fn($r) => sprintf("[%-4s] %s: %s\n", $r[0], $r[1], $r[2]), $rows));
    }
}
