<?php

use App\Domain\Travel\Exceptions\DomainException;
use App\Domain\Travel\Suppliers\SupplierUnavailable;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsureIdempotency;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [AssignCorrelationId::class]);
        $middleware->alias(['idempotent' => EnsureIdempotency::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Regras de negócio: 422 com código estável para o cliente tratar.
        $exceptions->render(fn (DomainException $e) => response()->json([
            'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
        ], 422));

        // Falha do fornecedor: 503 (não é erro do cliente). A compensação das reservas
        // parciais já rodou em BookTravelRequest.
        $exceptions->render(fn (SupplierUnavailable $e) => response()->json([
            'error' => ['code' => 'supplier_unavailable', 'message' => 'O fornecedor não confirmou a reserva e a emissão não foi concluída. Tente novamente.'],
        ], 503));

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Recurso não encontrado.']], 404);
            }

            return null;
        });

        $exceptions->dontReport([DomainException::class]);
    })->create();
