# Guia para agentes de IA neste repositório

Este arquivo orienta o Claude (revisão automática de PR e desenvolvimento assistido).

## Comandos

- Testes: `vendor/bin/pest` (rápido, SQLite + cache em memória)
- Estilo: `vendor/bin/pint`
- Análise estática: `vendor/bin/phpstan analyse` (nível 8, sem baseline)

## Arquitetura

- `app/Domain` — regras de negócio. Não conhece HTTP.
- `app/Domain/Travel/Actions` — um caso de uso por classe, método `handle`.
- `app/Infrastructure` — outbox, mensageria (RabbitMQ), idempotência, locks.
- `app/Http` — controllers finos: validam, autorizam e delegam para uma action.

## Regras que não podem ser quebradas

1. Status de `TravelRequest` só muda via `transitionTo()`; a máquina de estados está no enum.
2. Toda mudança de estado acontece dentro de transação e grava o evento com `Outbox::record()`.
3. Nunca publicar no broker direto de uma action (ver docs/adr/0002).
4. Decisão de orçamento lê do banco sob lock, nunca do cache (ver docs/adr/0003).
5. Não usar `withoutGlobalScope('company')` em código que roda com usuário logado (ver docs/adr/0004).
6. Dinheiro sempre em centavos (`int`), sufixo `_cents`.
7. Mudou a API → atualizar `insomnia/tripflow.json`.
8. Sem `@phpstan-ignore`: corrija o tipo.
