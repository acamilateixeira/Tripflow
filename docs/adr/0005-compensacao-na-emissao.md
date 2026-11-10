# ADR 0005 — Compensação na emissão e lock compartilhado com o cancelamento

- **Status:** aceito
- **Data:** 2026-11-10
- **Complementa:** [ADR 0003](0003-concorrencia-orcamento-e-reservas.md)

## Contexto

O ADR 0003 tirou a chamada ao fornecedor da transação e protegeu a emissão com
um lock no Redis. Dois casos ficaram descobertos, e ambos foram reproduzidos em
`tests/Feature/BookingConsistencyTest.php` antes da correção:

1. **Falha parcial.** Com voo e hotel no mesmo pedido, o voo era confirmado e o
   hotel falhava. A exceção subia, nada era gravado, e o voo continuava
   reservado no fornecedor sem registro no TripFlow.
2. **Cancelamento durante a emissão.** O cancelamento não usava o lock da
   emissão. Enquanto a emissão esperava o fornecedor, um cancelamento passava,
   a emissão quebrava na transição `cancelled → booked` e as reservas já
   confirmadas ficavam órfãs. O evento `cancelled` saía sem reservas para o
   consumidor desfazer.

## Decisão

- `SupplierGateway` ganha `cancel(BookingConfirmation)`. Se a emissão não
  termina (fornecedor falha no meio, ou a gravação falha depois das chamadas),
  `BookTravelRequest` cancela no fornecedor tudo o que já foi confirmado e só
  então deixa o erro subir (saga com compensação, em processo).
- Falha da compensação não esconde o erro original: vira log `critical`
  `booking.compensation_failed` com o localizador, para tratamento manual.
- Falha do fornecedor é `SupplierUnavailable` e responde **503
  `supplier_unavailable`**. Por ser 5xx, a idempotência não guarda a resposta e
  o cliente pode repetir com a mesma chave.
- `CancelTravelRequest` adquire o mesmo lock (`BookTravelRequest::lockKey`).
  Quem não consegue em 5 s recebe **422 `booking_in_progress`**.

## Alternativas consideradas

- **Gravar as confirmações antes de chamar o próximo fornecedor** (estado
  `booking` intermediário). Dá rastreabilidade mesmo com o processo morrendo no
  meio, mas exige um novo estado na máquina, um reconciliador e mudança no
  contrato do cliente. Fica como próximo passo se houver adapter real.
- **Cancelamento sem lock, deixando o consumidor do evento desfazer.** Não
  funciona: o evento sai antes de as reservas existirem no banco.

## Consequências

- A compensação roda no mesmo processo. Se ele morrer entre a confirmação do
  fornecedor e o `cancel`, a reserva fica órfã (o log não chega a ser escrito).
  Fechar essa janela exige o estado intermediário descrito acima.
- `cancel` no fornecedor pode ter custo ou multa no mundo real; o fake não modela isso.
- O TTL do lock (30 s) continua precisando ficar acima do timeout HTTP do
  adapter, como no ADR 0003.
