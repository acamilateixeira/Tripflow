# ADR 0001 — Registrar decisões de arquitetura

- **Status:** aceito
- **Data:** 2026-11-04

## Contexto

Decisões como "por que outbox e não publicar direto?" costumam viver na cabeça de
quem decidiu. Quando essa pessoa sai do time, ou seis meses depois, a decisão é
revertida sem que ninguém lembre do problema que ela resolvia.

## Decisão

Toda decisão que muda a estrutura do sistema (consistência, concorrência,
integração, isolamento de dados) vira um ADR curto nesta pasta, no formato
contexto → decisão → consequências. ADRs não são editados depois de aceitos:
uma mudança de ideia gera um ADR novo que substitui o anterior.

## Consequências

- O template de PR pergunta se algum ADR precisa ser criado.
- A revisão automática por IA lê os ADRs e aponta código que os contradiz.
