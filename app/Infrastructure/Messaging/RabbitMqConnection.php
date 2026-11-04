<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Conexão preguiçosa e reaproveitada pelo processo (relay e consumidor são
 * processos de longa duração). Em erro, é descartada e recriada na próxima chamada.
 */
final class RabbitMqConnection
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /**
     * @param  array{host: string, port: int, user: string, password: string, vhost: string}  $config
     */
    public function __construct(private readonly array $config) {}

    public function channel(): AMQPChannel
    {
        if ($this->channel?->is_open()) {
            return $this->channel;
        }

        $this->connection ??= new AMQPStreamConnection(
            $this->config['host'],
            $this->config['port'],
            $this->config['user'],
            $this->config['password'],
            $this->config['vhost'],
            heartbeat: 30,
        );

        $this->channel = $this->connection->channel();
        $this->channel->confirm_select();

        return $this->channel;
    }

    public function reset(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (\Throwable) {
            // conexão já estava quebrada
        }

        $this->channel = null;
        $this->connection = null;
    }
}
