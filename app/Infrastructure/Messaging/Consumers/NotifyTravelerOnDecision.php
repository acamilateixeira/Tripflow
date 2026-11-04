<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Consumers;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Consumidor de exemplo: avisa o viajante quando a solicitação é decidida
 * ou emitida. Mostra o padrão "inbox": o message_id é gravado na mesma
 * transação do efeito colateral, então uma reentrega do broker não gera
 * notificação duplicada.
 */
final class NotifyTravelerOnDecision
{
    public const CONSUMER = 'notify-traveler';

    private const SUBJECTS = [
        'travel_request.approved' => 'Sua viagem foi aprovada',
        'travel_request.rejected' => 'Sua viagem foi recusada',
        'travel_request.booked' => 'Suas reservas foram emitidas',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return bool true se processou; false se era duplicada ou irrelevante
     */
    public function handle(string $messageId, string $eventType, array $payload): bool
    {
        $subject = self::SUBJECTS[$eventType] ?? null;

        if ($subject === null) {
            return false;
        }

        return DB::transaction(function () use ($messageId, $payload, $subject): bool {
            $inserted = DB::table('processed_messages')->insertOrIgnore([
                'message_id' => $messageId,
                'consumer' => self::CONSUMER,
                'processed_at' => now(),
            ]);

            if ($inserted === 0) {
                return false; // já processada
            }

            $travelerId = $payload['traveler_id'] ?? null;
            $traveler = is_int($travelerId) ? User::query()->find($travelerId) : null;

            if ($traveler === null) {
                return false;
            }

            DB::table('notifications_log')->insert([
                'user_id' => $traveler->id,
                'channel' => 'email',
                'subject' => $subject,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });
    }
}
