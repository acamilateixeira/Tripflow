<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use App\Infrastructure\Messaging\MessagePublisher;
use App\Infrastructure\Messaging\PublishFailed;
use App\Models\OutboxMessage;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Lê mensagens pendentes da outbox e publica no broker.
 *
 * - SKIP LOCKED permite várias instâncias do relay sem publicarem a mesma linha.
 * - Falha → backoff exponencial (2^tentativas segundos, máx. 10 min).
 * - Após max_attempts a mensagem fica parada com last_error para análise
 *   (equivalente a uma DLQ do lado do produtor).
 * - Ordem garantida por agregado apenas dentro do mesmo lote; consumidores
 *   devem ser idempotentes e tolerar reordenação. Ver docs/adr/0002.
 */
final readonly class OutboxRelay
{
    public function __construct(
        private ConnectionInterface $db,
        private MessagePublisher $publisher,
        private LoggerInterface $logger,
        private int $maxAttempts,
    ) {}

    /** @return array{published: int, failed: int} */
    public function relayBatch(int $batchSize): array
    {
        return $this->db->transaction(function () use ($batchSize): array {
            $messages = OutboxMessage::query()
                ->readyToPublish()
                ->where('attempts', '<', $this->maxAttempts)
                ->limit($batchSize)
                ->lock('for update skip locked')
                ->get();

            $published = 0;
            $failed = 0;

            foreach ($messages as $message) {
                try {
                    $this->publisher->publish($message);

                    $message->published_at = now()->toImmutable();
                    $message->attempts++;
                    $message->last_error = null;
                    $published++;
                } catch (PublishFailed $e) {
                    $message->attempts++;
                    $message->available_at = now()->addSeconds(min(600, 2 ** $message->attempts))->toImmutable();
                    $message->last_error = $e->getMessage();
                    $failed++;

                    $this->logger->warning('outbox.publish_failed', [
                        'message_id' => $message->id,
                        'event_type' => $message->event_type,
                        'attempts' => $message->attempts,
                        'error' => $e->getMessage(),
                    ]);
                }

                $message->save();
            }

            return ['published' => $published, 'failed' => $failed];
        });
    }
}
