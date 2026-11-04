<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use LogicException;

/**
 * Acesso tipado aos locks atômicos do cache. Falha cedo se o store
 * configurado não suportar locks (ex.: "file" em produção com várias réplicas).
 */
final class Locks
{
    public static function from(Repository $cache): LockProvider
    {
        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException(sprintf('O cache store %s não suporta locks atômicos.', $store::class));
        }

        return $store;
    }
}
