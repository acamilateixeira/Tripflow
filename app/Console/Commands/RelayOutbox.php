<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Outbox\OutboxRelay;
use Illuminate\Console\Command;

final class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay {--once : Processa um lote e sai} {--batch= : Tamanho do lote}';

    protected $description = 'Publica no broker os eventos pendentes da outbox';

    private bool $running = true;

    public function handle(OutboxRelay $relay): int
    {
        $batch = (int) ($this->option('batch') ?: config('messaging.outbox.batch_size'));
        $sleepMs = (int) config('messaging.outbox.idle_sleep_ms');

        // Encerramento gracioso no deploy (SIGTERM do container).
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->running = false);
            pcntl_signal(SIGINT, fn () => $this->running = false);
        }

        do {
            $result = $relay->relayBatch($batch);

            if ($result['published'] + $result['failed'] > 0) {
                $this->line(sprintf('publicadas=%d falhas=%d', $result['published'], $result['failed']));
            } elseif (! $this->option('once')) {
                usleep($sleepMs * 1000);
            }
        } while ($this->running && ! $this->option('once'));

        return self::SUCCESS;
    }
}
