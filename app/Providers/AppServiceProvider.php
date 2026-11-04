<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Travel\Suppliers\FakeSupplierGateway;
use App\Domain\Travel\Suppliers\SupplierGateway;
use App\Infrastructure\Messaging\InMemoryPublisher;
use App\Infrastructure\Messaging\LogPublisher;
use App\Infrastructure\Messaging\MessagePublisher;
use App\Infrastructure\Messaging\RabbitMqConnection;
use App\Infrastructure\Messaging\RabbitMqPublisher;
use App\Infrastructure\Outbox\OutboxRelay;
use App\Infrastructure\RequestContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RequestContext::class);

        $this->app->singleton(RabbitMqConnection::class, fn () => new RabbitMqConnection(config('messaging.rabbitmq')));

        $this->app->singleton(MessagePublisher::class, fn (Application $app) => match (config('messaging.driver')) {
            'rabbitmq' => new RabbitMqPublisher($app->make(RabbitMqConnection::class), (string) config('messaging.rabbitmq.exchange')),
            'memory' => new InMemoryPublisher,
            default => $app->make(LogPublisher::class),
        });

        $this->app->bind(OutboxRelay::class, fn (Application $app) => new OutboxRelay(
            $app->make('db.connection'),
            $app->make(MessagePublisher::class),
            $app->make('log'),
            (int) config('messaging.outbox.max_attempts'),
        ));

        $this->app->singleton(SupplierGateway::class, FakeSupplierGateway::class);
    }

    public function boot(): void
    {
        // Em dev/teste: falha alto em N+1, atributo inexistente e mass assignment silencioso.
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
