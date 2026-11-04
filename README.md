# TripFlow — API de viagens corporativas

API em **Laravel 12 / PHP 8.3** para o ciclo de uma viagem a trabalho: a pessoa
solicita, a gestora aprova dentro do orçamento do centro de custo, as reservas
são emitidas e os outros sistemas ficam sabendo por eventos.

O domínio é pequeno de propósito. O foco está nos problemas que aparecem quando
esse fluxo roda em produção, com várias empresas, várias réplicas e integrações
que falham:

| Problema real | Como foi resolvido | Onde |
|---|---|---|
| Evento publicado sem o banco ter commitado (ou o contrário) | Transactional outbox + relay com `SKIP LOCKED` e backoff | [ADR 0002](docs/adr/0002-transactional-outbox.md) |
| Duas aprovações simultâneas estourando o orçamento | `SELECT … FOR UPDATE` no centro de custo, ordem fixa de locks | [ADR 0003](docs/adr/0003-concorrencia-orcamento-e-reservas.md) |
| App reenvia a requisição por timeout e duplica a reserva | `Idempotency-Key` com replay da resposta (Redis) + lock distribuído | `EnsureIdempotency`, `BookTravelRequest` |
| Broker reentrega a mesma mensagem | Consumidor idempotente com tabela *inbox* | `NotifyTravelerOnDecision` |
| Empresa A enxergando dados da empresa B | Escopo global por `company_id`, 404 para IDs de outra empresa | [ADR 0004](docs/adr/0004-isolamento-multi-tenant.md) |
| Status "pulando" etapas | Máquina de estados no enum; única porta é `transitionTo()` | `TravelRequestStatus` |
| Seguir uma operação da API até o consumidor | `X-Request-Id` → logs JSON → `correlation_id` da mensagem | `AssignCorrelationId` |

## Arquitetura

```mermaid
flowchart LR
    C[Cliente / App] -->|HTTP + Idempotency-Key| API[API Laravel]
    API -->|transação: estado + evento| DB[(MySQL)]
    API <-->|cache, locks, idempotência| R[(Redis)]
    DB -->|outbox_messages| RL[outbox:relay]
    RL -->|publisher confirms| MQ{{RabbitMQ<br/>exchange topic}}
    MQ -->|travel_request.*| CO[events:consume]
    CO -->|inbox: dedup por message_id| DB
    MQ -.->|nack| DLQ[(DLQ)]
```

```
app/
├── Domain/                 regras de negócio (não conhece HTTP)
│   ├── Travel/Actions/     um caso de uso por classe: Submit, Approve, Book…
│   ├── Travel/Enums/       máquina de estados da solicitação
│   └── Budget/             orçamento mensal por centro de custo
├── Infrastructure/         outbox, RabbitMQ, locks, correlation id
└── Http/                   controllers finos, validação, middleware de idempotência
```

### Fluxo da solicitação

```
draft ──submit──▶ pending_approval ──approve──▶ approved ──book──▶ booked
                       └──reject──▶ rejected
cancel: a partir de draft, pending_approval, approved e booked
```

Cada transição publica um evento (`travel_request.submitted`, `.approved`, `.rejected`, `.booked`, `.cancelled`).

## Como rodar

### No navegador, sem instalar nada (GitHub Codespaces)

[![Abrir no GitHub Codespaces](https://github.com/codespaces/badge.svg)](https://codespaces.new/acamilateixeira/tripflow?quickstart=1)

O Codespaces sobe PHP 8.3, MySQL, Redis e RabbitMQ, instala as dependências,
roda as migrations com o seed e inicia a API, o relay da outbox e o consumidor.
Quando o terminal mostrar "Ambiente pronto", rode:

```bash
bash scripts/demo.sh
```

O script percorre o fluxo inteiro contra a API real (login, idempotência,
aprovação, orçamento, reservas, isolamento entre empresas e publicação dos
eventos) e mostra ✔ ou ✘ em cada verificação. O mesmo script roda no CI.

### Com Docker (ambiente completo)

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec api php artisan key:generate
docker compose exec api php artisan migrate --seed
```

Sobe API (`:8000`), worker de filas, relay da outbox, consumidor, MySQL, Redis e
RabbitMQ (painel em `http://localhost:15672`, guest/guest).

### Local, sem Docker

```bash
composer install
cp .env.example .env && php artisan key:generate
# para rodar sem MySQL/RabbitMQ, ajuste no .env:
#   DB_CONNECTION=sqlite  CACHE_STORE=array  MESSAGING_DRIVER=log
php artisan migrate --seed
php artisan serve
```

**Usuários do seed** (senha `password`): `viajante@acme.test`, `gestora@acme.test`,
`admin@acme.test` e `viajante@globex.test` (outra empresa, para testar o isolamento).

### Testando a API

A coleção do **Insomnia** está em [`insomnia/tripflow.json`](insomnia/tripflow.json):
Application → Import → selecione o arquivo. Ela traz o ambiente local, os logins e
todos os endpoints, com exemplos de idempotência e dos erros de negócio.

| Método | Rota | Descrição |
|---|---|---|
| POST | `/api/v1/auth/token` | Login, retorna token Bearer (8 h) |
| GET | `/api/v1/travel-requests` | Lista com filtro `status` e paginação por cursor |
| POST | `/api/v1/travel-requests` | Cria rascunho (aceita `Idempotency-Key`) |
| GET | `/api/v1/travel-requests/{id}` | Detalhe com reservas |
| POST | `/api/v1/travel-requests/{id}/submit` | Envia para aprovação |
| POST | `/api/v1/travel-requests/{id}/approve` | Aprova, validando o orçamento do mês |
| POST | `/api/v1/travel-requests/{id}/reject` | Recusa com motivo |
| POST | `/api/v1/travel-requests/{id}/bookings` | Emite voo/hotel/carro (aceita `Idempotency-Key`) |
| POST | `/api/v1/travel-requests/{id}/cancel` | Cancela e libera o orçamento |
| GET | `/api/v1/cost-centers/{id}/budget?month=AAAA-MM` | Orçamento, comprometido e disponível |
| GET | `/api/health` | Banco, cache e backlog da outbox |

Erros de negócio retornam `422` com um código estável:

```json
{ "error": { "code": "budget_exceeded", "message": "Orçamento insuficiente no centro de custo CC-100: …" } }
```

## Qualidade

```bash
vendor/bin/pest                 # testes de unidade, arquitetura, feature e integração
vendor/bin/phpstan analyse      # Larastan nível 8, sem baseline
vendor/bin/pint --test          # estilo
```

- **Testes de arquitetura** (Pest Arch) garantem que o domínio não depende de HTTP,
  que controllers não acessam o banco e que não sobra `dd()` no código.
- **Teste de integração** com RabbitMQ real verifica routing key, `message_id` e
  `correlation_id`. Roda no CI e é pulado localmente quando não há broker.

## CI/CD

| Workflow | Quando | O que faz |
|---|---|---|
| [`ci.yml`](.github/workflows/ci.yml) | push e PR | Pint, Larastan, `composer audit`, testes com MySQL + Redis + RabbitMQ, cobertura mínima de 80% e build da imagem |
| [`pr-validation.yml`](.github/workflows/pr-validation.yml) | PR | Título em Conventional Commits, descrição preenchida, alerta de PR grande, migrations reversíveis |
| [`ai-review.yml`](.github/workflows/ai-review.yml) | PR | Revisão do Claude com comentários inline, guiada pelo [`CLAUDE.md`](CLAUDE.md) e pelos ADRs |
| [`release.yml`](.github/workflows/release.yml) | tag `v*` | Publica a imagem no GHCR e cria o release com notas dos PRs |

**Para ativar a revisão por IA:** Settings → Secrets and variables → Actions →
*New repository secret* → `ANTHROPIC_API_KEY`.

**Para bloquear merge sem CI verde:** Settings → Branches → regra para `main` →
*Require status checks to pass* → marque `Qualidade`, `Testes` e `Título, descrição e tamanho`.

## Decisões de arquitetura

- [0001 — Registrar decisões](docs/adr/0001-registro-de-decisoes.md)
- [0002 — Transactional outbox](docs/adr/0002-transactional-outbox.md)
- [0003 — Concorrência no orçamento e nas reservas](docs/adr/0003-concorrencia-orcamento-e-reservas.md)
- [0004 — Isolamento multi-tenant](docs/adr/0004-isolamento-multi-tenant.md)

## Próximos passos

- Adapter HTTP real de fornecedor, com circuit breaker e timeout abaixo do TTL do lock.
- Front-end em Vue 3 para o painel da gestora.
- Métricas (Prometheus) para backlog da outbox e latência de publicação.

---

Feito por [Camila Teixeira](https://www.linkedin.com/in/acamilateixeira/).
