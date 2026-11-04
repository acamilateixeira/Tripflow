# ADR 0004 — Isolamento multi-tenant por escopo global

- **Status:** aceito
- **Data:** 2026-11-04

## Contexto

Várias empresas usam o mesmo banco. O pior incidente possível é uma empresa ver
viagens, valores ou centros de custo de outra. Depender de cada desenvolvedor
lembrar de filtrar `company_id` em cada consulta não é seguro.

## Decisão

- Modelos com dados de empresa usam o trait `BelongsToCompany`, que aplica um
  escopo global `company_id = usuário autenticado`.
- Registro de outra empresa responde **404**, não 403, para não revelar que existe.
- A policy cuida só de papel e segregação de funções (quem aprova o quê).
- Jobs, comandos e consumidores rodam sem usuário e, portanto, sem escopo.
  Eles trabalham com IDs que vieram de eventos já validados na origem.

## Alternativas consideradas

- **Banco por empresa:** isolamento máximo, mas migrations e operação multiplicadas.
  Faz sentido só com requisito contratual de isolamento físico.
- **Filtro manual em cada query:** simples, mas um esquecimento vira vazamento.

## Consequências

- `withoutGlobalScope('company')` é explícito e fácil de procurar em code review.
- Os testes em `TenantIsolationTest` cobrem leitura, escrita e listagem entre empresas.
