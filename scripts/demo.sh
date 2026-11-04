#!/usr/bin/env bash
# Demonstração ponta a ponta da API, contra um ambiente rodando (Codespaces,
# docker compose ou php artisan serve). Cada passo verifica o resultado e o
# script termina com código 1 se algo falhar.
#
#   bash scripts/demo.sh                       # http://localhost:8000/api
#   BASE_URL=http://outro:8000/api bash scripts/demo.sh
#
# Requer um banco com o seed: php artisan migrate:fresh --seed

set -uo pipefail

BASE="${BASE_URL:-http://localhost:8000/api}"
ARTISAN="${ARTISAN:-php artisan}"
PASS=0
FAIL=0
LAST_STATUS=""
LAST_BODY=""
LAST_HEADERS=""

bold() { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok() { printf '  \033[32m✔\033[0m %s\n' "$1"; PASS=$((PASS + 1)); }
ko() { printf '  \033[31m✘\033[0m %s\n      %s\n' "$1" "$2"; FAIL=$((FAIL + 1)); }

# req METODO CAMINHO [TOKEN] [JSON] [HEADER_EXTRA]
req() {
  local method=$1 path=$2 token=${3:-} data=${4:-} extra=${5:-}
  local args=(-s -X "$method" "$BASE$path" -H 'Accept: application/json' -D /tmp/tripflow-headers -o /tmp/tripflow-body -w '%{http_code}')
  [ -n "$token" ] && args+=(-H "Authorization: Bearer $token")
  [ -n "$data" ] && args+=(-H 'Content-Type: application/json' -d "$data")
  [ -n "$extra" ] && args+=(-H "$extra")
  LAST_STATUS=$(curl "${args[@]}")
  LAST_BODY=$(cat /tmp/tripflow-body)
  LAST_HEADERS=$(cat /tmp/tripflow-headers)
}

# json 'data.status' → valor do campo no último corpo
json() {
  php -r '$d = json_decode(stream_get_contents(STDIN), true);
    foreach (explode(".", $argv[1]) as $k) { $d = is_array($d) ? ($d[$k] ?? null) : null; }
    if (is_array($d)) { echo json_encode($d); } elseif (is_bool($d)) { echo $d ? "true" : "false"; } elseif ($d !== null) { echo $d; }' "$1" <<< "$LAST_BODY"
}

expect_status() {
  if [ "$LAST_STATUS" = "$1" ]; then ok "$2 (HTTP $1)"; else ko "$2" "esperado HTTP $1, veio $LAST_STATUS: ${LAST_BODY:0:200}"; fi
}

expect_eq() {
  if [ "$1" = "$2" ]; then ok "$3"; else ko "$3" "esperado '$2', veio '$1'"; fi
}

token_for() {
  req POST /v1/auth/token '' "{\"email\":\"$1\",\"password\":\"password\",\"device_name\":\"demo\"}"
  json token
}

DEPARTURE=$(date -d '+40 days' +%F 2>/dev/null || date -v+40d +%F)
RETURN=$(date -d '+42 days' +%F 2>/dev/null || date -v+42d +%F)
MONTH=${DEPARTURE:0:7}

bold "1. Saúde do ambiente"
req GET /health
expect_status 200 "API, banco e cache respondendo"
expect_eq "$(json status)" "ok" "status = ok"

bold "2. Autenticação"
TRAVELER=$(token_for viajante@acme.test)
APPROVER=$(token_for gestora@acme.test)
OTHER=$(token_for viajante@globex.test)
[ -n "$TRAVELER" ] && [ -n "$APPROVER" ] && [ -n "$OTHER" ] && ok "tokens emitidos para viajante, gestora e outra empresa" || ko "login" "rode o seed: php artisan migrate:fresh --seed"
req POST /v1/auth/token '' '{"email":"viajante@acme.test","password":"errada"}'
expect_status 422 "senha errada é recusada"

bold "3. Criação com Idempotency-Key"
KEY="demo-$(date +%s)"
PAYLOAD="{\"cost_center_id\":1,\"origin\":\"CNF\",\"destination\":\"GRU\",\"departure_on\":\"$DEPARTURE\",\"return_on\":\"$RETURN\",\"estimated_cost_cents\":350000,\"reason\":\"Kickoff presencial com cliente em São Paulo.\"}"
req POST /v1/travel-requests "$TRAVELER" "$PAYLOAD" "Idempotency-Key: $KEY"
expect_status 201 "solicitação criada como rascunho"
ID=$(json data.id)
expect_eq "$(json data.status)" "draft" "status = draft"
req POST /v1/travel-requests "$TRAVELER" "$PAYLOAD" "Idempotency-Key: $KEY"
expect_eq "$(json data.id)" "$ID" "reenvio com a mesma chave devolve a mesma solicitação"
grep -qi '^idempotent-replayed: true' <<< "$LAST_HEADERS" && ok "header Idempotent-Replayed: true" || ko "replay" "header Idempotent-Replayed ausente"

bold "4. Fluxo de aprovação"
req POST "/v1/travel-requests/$ID/approve" "$TRAVELER"
expect_status 403 "viajante não pode aprovar"
req POST "/v1/travel-requests/$ID/approve" "$APPROVER"
expect_status 422 "rascunho não pode ser aprovado (invalid_transition)"
req POST "/v1/travel-requests/$ID/submit" "$TRAVELER"
expect_eq "$(json data.status)" "pending_approval" "enviada para aprovação"
req POST "/v1/travel-requests/$ID/approve" "$APPROVER"
expect_eq "$(json data.status)" "approved" "gestora aprova"

bold "5. Orçamento do centro de custo"
req GET "/v1/cost-centers/1/budget?month=$MONTH" "$APPROVER"
expect_eq "$(json data.committed_cents)" "350000" "R$ 3.500,00 comprometidos no mês"
BIG="{\"cost_center_id\":1,\"origin\":\"CNF\",\"destination\":\"REC\",\"departure_on\":\"$DEPARTURE\",\"return_on\":\"$RETURN\",\"estimated_cost_cents\":900000,\"reason\":\"Viagem que estoura o orçamento do mês.\"}"
req POST /v1/travel-requests "$TRAVELER" "$BIG"
BIG_ID=$(json data.id)
req POST "/v1/travel-requests/$BIG_ID/submit" "$TRAVELER"
req POST "/v1/travel-requests/$BIG_ID/approve" "$APPROVER"
expect_status 422 "aprovação acima do orçamento é bloqueada"
expect_eq "$(json error.code)" "budget_exceeded" "código budget_exceeded"

bold "6. Emissão de reservas"
req POST "/v1/travel-requests/$ID/bookings" "$TRAVELER" '{"items":[{"type":"flight","supplier":"LATAM","amount_cents":180000},{"type":"hotel","supplier":"Ibis Paulista","amount_cents":90000}]}' "Idempotency-Key: book-$KEY"
expect_eq "$(json data.status)" "booked" "voo e hotel emitidos"
req GET "/v1/travel-requests/$ID" "$TRAVELER"
expect_eq "$(php -r 'echo count(json_decode(stream_get_contents(STDIN), true)["data"]["bookings"]);' <<< "$LAST_BODY")" "2" "duas reservas com localizador"

bold "7. Isolamento entre empresas"
req GET "/v1/travel-requests/$ID" "$OTHER"
expect_status 404 "outra empresa não enxerga a solicitação"
req GET "/v1/cost-centers/1/budget" "$OTHER"
expect_status 404 "nem o orçamento"

bold "8. Eventos (outbox → broker)"
# Cada transição gravou um evento na outbox. Se o relay estiver rodando
# (Codespaces/docker compose), ele publica sozinho; senão, rodamos um lote.
req GET /health
if [ "$(json outbox.pending)" != "0" ]; then
  $ARTISAN outbox:relay --once >/dev/null 2>&1 || true
fi
for _ in $(seq 1 10); do
  req GET /health
  [ "$(json outbox.pending)" = "0" ] && break
  sleep 1
done
expect_eq "$(json outbox.pending)" "0" "todos os eventos publicados no broker (outbox zerada)"

printf '\n\033[1mResultado: %d ok, %d falhas\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
