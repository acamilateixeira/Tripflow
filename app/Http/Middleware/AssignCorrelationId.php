<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aceita X-Request-Id do gateway (ou gera um), coloca no contexto de todos os
 * logs da requisição e devolve no response. O mesmo ID segue nas mensagens
 * publicadas como correlation_id.
 */
final readonly class AssignCorrelationId
{
    public function __construct(private RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-Id');

        if (is_string($incoming) && preg_match('/^[A-Za-z0-9\-_.]{8,64}$/', $incoming) === 1) {
            $this->context->setCorrelationId($incoming);
        }

        $id = $this->context->correlationId();
        Log::shareContext(['correlation_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
