<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Locks;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotência para POSTs com efeito colateral (criar solicitação, emitir reserva).
 *
 * Cliente envia `Idempotency-Key: <uuid>`. Na primeira chamada a resposta é
 * guardada no Redis por 24h; repetições com a mesma chave devolvem a mesma
 * resposta (header Idempotent-Replayed: true) sem executar de novo. Isso cobre
 * o caso clássico de timeout no app mobile seguido de retry.
 *
 * - Mesma chave com payload diferente → 422 (erro do cliente, não replay).
 * - Mesma chave ainda em processamento → 409.
 * - Respostas 5xx não são guardadas: o cliente pode tentar de novo.
 */
final readonly class EnsureIdempotency
{
    private const TTL_SECONDS = 86_400;

    public function __construct(private Cache $cache) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return $next($request);
        }

        if (strlen($key) > 255) {
            return $this->error(400, 'idempotency_key_invalid', 'Idempotency-Key deve ter até 255 caracteres.');
        }

        $scope = sprintf('idem:%s:%s', $request->user()?->getAuthIdentifier() ?? 'guest', hash('sha256', $key));
        $fingerprint = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        /** @var array{fingerprint: string, status: int, body: string, headers: array<string, string>}|null $stored */
        $stored = $this->cache->get($scope);

        if ($stored !== null) {
            return $this->replay($stored, $fingerprint);
        }

        $lock = Locks::from($this->cache)->lock($scope.':lock', 30);

        if (! $lock->get()) {
            return $this->error(409, 'request_in_progress', 'Uma requisição com esta Idempotency-Key ainda está em processamento.');
        }

        try {
            // Segunda checagem já com o lock: outra requisição pode ter terminado entre o get e o lock.
            $stored = $this->cache->get($scope);
            if ($stored !== null) {
                return $this->replay($stored, $fingerprint);
            }

            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                $this->cache->put($scope, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getContent(),
                    'headers' => ['Content-Type' => (string) $response->headers->get('Content-Type', 'application/json')],
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{fingerprint: string, status: int, body: string, headers: array<string, string>}  $stored
     */
    private function replay(array $stored, string $fingerprint): Response
    {
        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            return $this->error(422, 'idempotency_key_reused', 'Esta Idempotency-Key já foi usada com outro payload.');
        }

        return new Response($stored['body'], $stored['status'], [
            ...$stored['headers'],
            'Idempotent-Replayed' => 'true',
        ]);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
