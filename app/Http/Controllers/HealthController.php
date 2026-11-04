<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OutboxMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Readiness: checa as dependências e expõe o backlog da outbox, que é o
 * principal sinal de que o relay ou o broker estão com problema.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(fn () => Cache::put('health', 1, 5)),
        ];

        $pending = OutboxMessage::query()->whereNull('published_at')->count();
        $oldest = OutboxMessage::query()->whereNull('published_at')->min('occurred_at');

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return new JsonResponse([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'outbox' => [
                'pending' => $pending,
                'oldest_pending_at' => $oldest,
            ],
        ], $healthy ? 200 : 503);
    }

    /** @return array{ok: bool, error?: string} */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => class_basename($e)];
        }
    }
}
