# ADR 0002 — Transactional outbox para eventos de domínio

- **Status:** aceito
- **Data:** 2026-11-04

## Contexto

Aprovar uma viagem precisa (1) mudar o status no MySQL e (2) avisar outros
sistemas (notificação, integração com fornecedor, financeiro). Publicar direto
no RabbitMQ dentro do request tem dois modos de falha:

- O banco commita e o publish falha → o resto do mundo nunca fica sabendo.
- O publish acontece e o banco faz rollback → o mundo sabe de algo que não aconteceu.

Não existe transação distribuída barata entre MySQL e RabbitMQ.

## Decisão

Usar o padrão *transactional outbox*:

1. A action grava a mudança de estado **e** uma linha em `outbox_messages` na
   mesma transação. `Outbox::record()` lança exceção se não houver transação aberta.
2. Um processo separado (`php artisan outbox:relay`) lê as pendentes com
   `FOR UPDATE SKIP LOCKED`, publica com *publisher confirms* e marca `published_at`.
3. Falha de publish → backoff exponencial (2^tentativas s, máx. 10 min). Após
   `OUTBOX_MAX_ATTEMPTS`, a linha fica parada com `last_error` para análise.
4. `message_id` da mensagem = id da outbox. Consumidores deduplicam por ele
   (tabela `processed_messages`, padrão *inbox*).

## Consequências

- Entrega é **at-least-once**: consumidores precisam ser idempotentes.
- Latência extra de até `OUTBOX_IDLE_SLEEP_MS` entre o commit e a publicação.
- Ordem só é garantida dentro de um lote; consumidores não devem depender dela.
- O backlog da outbox aparece em `/api/health` e é o principal alerta operacional.
- `SKIP LOCKED` permite escalar o relay horizontalmente sem publicação duplicada.
