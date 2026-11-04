# ADR 0003 — Concorrência no orçamento e na emissão de reservas

- **Status:** aceito
- **Data:** 2026-11-04

## Contexto

Dois cenários de corrida:

1. **Orçamento.** Duas gestoras aprovam, ao mesmo tempo, duas viagens do mesmo
   centro de custo. As duas leem "R$ 4.000 disponíveis", as duas aprovam
   R$ 3.000 e o centro de custo termina com R$ 2.000 negativos.
2. **Emissão.** O app reenvia a requisição de emissão por timeout. A chamada ao
   fornecedor é externa e lenta (segundos), então segurar um lock de linha no
   banco durante ela prenderia conexões e travaria outras operações.

## Decisão

- **Orçamento:** a aprovação abre transação e faz `SELECT ... FOR UPDATE` na linha
  do centro de custo antes de somar o comprometido. Aprovações no mesmo centro
  de custo ficam serializadas; em centros diferentes, seguem em paralelo.
  Ordem de locks fixa (centro de custo → solicitação) para evitar deadlock.
- **Leitura de orçamento** (dashboard) usa cache no Redis com TTL de 5 min,
  invalidado após aprovação e cancelamento. A **decisão** nunca lê do cache.
- **Emissão:** lock distribuído no Redis (`Cache::lock`, 30 s) em volta do fluxo.
  A chamada ao fornecedor acontece fora da transação; só a gravação do
  resultado é transacional. Quem não consegue o lock em 5 s recebe
  `booking_in_progress`.
- **Retry do cliente:** POSTs com efeito colateral aceitam `Idempotency-Key`
  (resposta guardada no Redis por 24 h).

## Consequências

- Um centro de custo muito disputado vira gargalo de aprovação. Aceitável:
  aprovação é uma ação humana, de baixa frequência.
- O lock do Redis depende de TTL: se o fornecedor demorar mais que 30 s, outro
  processo poderia entrar. O TTL deve ficar acima do timeout HTTP do adapter.
- O cache store precisa suportar locks atômicos (`Locks::from` falha cedo caso não suporte).
