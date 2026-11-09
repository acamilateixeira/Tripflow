<?php

declare(strict_types=1);

/*
 * Grava a demo publicada: executa cenários reais contra uma API rodando e
 * salva cada requisição e resposta em JSON. A página docs/demo/index.html
 * mostra a gravação. Se alguma verificação falhar, o script termina com
 * código 1 e a demo não é publicada.
 *
 *   php scripts/record-demo.php http://127.0.0.1:8000/api docs/demo/recording.json
 *
 * Requer o banco com o seed (php artisan migrate:fresh --seed).
 */

[$_, $base, $output] = $argv + [null, 'http://127.0.0.1:8000/api', 'docs/demo/recording.json'];
$artisan = getenv('ARTISAN') ?: 'php artisan';

$steps = [];
$failures = 0;
$tokens = [];
$currentScenario = '';

/** @param array{pt: string, en: string} $title */
function scenario(string $id, array $title, array $summary): void
{
    global $scenarios, $currentScenario;
    $scenarios[] = ['id' => $id, 'title' => $title, 'summary' => $summary];
    $currentScenario = $id;
}

function masked(array $headers): array
{
    global $tokens;
    $out = [];
    foreach ($headers as $header) {
        foreach ($tokens as $who => $token) {
            $header = str_replace($token, "<token: {$who}>", $header);
        }
        $out[] = $header;
    }

    return $out;
}

function prepare(string $method, string $path, ?string $token = null, ?array $body = null, array $extra = []): CurlHandle
{
    global $base;
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $headers = array_merge($headers, $extra);
    $curl = curl_init($base.$path);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return $curl;
}

/** @return array{status: int, headers: array<string, string>, body: mixed, ms: int} */
function parse(CurlHandle $curl, string $raw): array
{
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $headers = [];
    foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return [
        'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'headers' => $headers,
        'body' => json_decode(substr($raw, $size), true),
        'ms' => (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000),
    ];
}

/** Requisição simples. Devolve a resposta para as verificações. */
function call(array $title, array $note, string $method, string $path, ?string $token = null, ?array $body = null, array $extra = [], bool $record = true): array
{
    $curl = prepare($method, $path, $token, $body, $extra);
    $response = parse($curl, (string) curl_exec($curl));
    if ($record) {
        record($title, $note, [[$method, $path, $token, $body, $extra, $response]]);
    }

    return $response;
}

/** Duas ou mais requisições disparadas ao mesmo tempo (curl_multi). */
function parallel(array $title, array $note, array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($requests as $request) {
        $handle = prepare(...$request);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi);
    } while ($running > 0);
    $responses = [];
    $calls = [];
    foreach ($handles as $i => $handle) {
        $response = parse($handle, (string) curl_multi_getcontent($handle));
        $responses[] = $response;
        $calls[] = [...array_pad($requests[$i], 5, null), $response];
        curl_multi_remove_handle($multi, $handle);
    }
    record($title, $note, $calls, true);

    return $responses;
}

function record(array $title, array $note, array $calls, bool $parallel = false): void
{
    global $steps, $currentScenario, $base;
    $keep = ['idempotent-replayed', 'content-type'];
    $steps[] = [
        'scenario' => $currentScenario,
        'title' => $title,
        'note' => $note,
        'parallel' => $parallel,
        'calls' => array_map(function (array $call) use ($keep) {
            [$method, $path, $token, $body, $extra, $response] = $call;
            $headers = ['Accept: application/json'];
            if ($token !== null) {
                $headers[] = "Authorization: Bearer {$token}";
            }
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
            }

            return [
                'request' => [
                    'method' => $method,
                    'url' => '/api'.$path,
                    'headers' => masked(array_merge($headers, $extra ?? [])),
                    'body' => $body,
                ],
                'response' => [
                    'status' => $response['status'],
                    'headers' => array_intersect_key($response['headers'], array_flip($keep)),
                    'body' => $response['body'],
                    'ms' => $response['ms'],
                ],
            ];
        }, $calls),
        'checks' => [],
    ];
}

function check(bool $ok, string $pt, string $en): void
{
    global $steps, $failures;
    $steps[array_key_last($steps)]['checks'][] = ['ok' => $ok, 'label' => ['pt' => $pt, 'en' => $en]];
    fwrite(STDOUT, ($ok ? '  ✓ ' : '  ✗ ').$pt.PHP_EOL);
    if (! $ok) {
        $failures++;
    }
}

function get(mixed $data, string $path): mixed
{
    foreach (explode('.', $path) as $key) {
        $data = is_array($data) ? ($data[$key] ?? null) : null;
    }

    return $data;
}

function login(string $email, string $who): string
{
    global $tokens;
    $response = call(['pt' => '', 'en' => ''], ['pt' => '', 'en' => ''], 'POST', '/v1/auth/token', null, ['email' => $email, 'password' => 'password', 'device_name' => 'demo'], record: false);
    $token = (string) get($response['body'], 'token');
    if ($token === '') {
        fwrite(STDERR, "Login falhou para {$email}. Rode o seed.\n");
        exit(1);
    }
    $tokens[$who] = $token;

    return $token;
}

$scenarios = [];
$run = bin2hex(random_bytes(3));
$traveler = login('viajante@acme.test', 'viajante');
$approver = login('gestora@acme.test', 'gestora');
$other = login('viajante@globex.test', 'outra empresa');
$day = static fn (int $days): string => date('Y-m-d', strtotime("+{$days} days"));

// 1 --------------------------------------------------------------------------
scenario('idempotency', ['pt' => 'Reenvio não duplica', 'en' => 'Retries do not duplicate'], [
    'pt' => 'O app do cliente perde a resposta e envia de novo. Com a mesma Idempotency-Key, a API devolve a resposta salva em vez de criar outra solicitação.',
    'en' => 'The client app loses the response and sends again. With the same Idempotency-Key, the API returns the saved response instead of creating another request.',
]);
$payload = ['cost_center_id' => 1, 'origin' => 'CNF', 'destination' => 'GRU', 'departure_on' => $day(40), 'return_on' => $day(42), 'estimated_cost_cents' => 350000, 'reason' => 'Kickoff presencial com cliente em São Paulo.'];
$key = "demo-create-{$run}";
$first = call(['pt' => 'Viajante cria a solicitação', 'en' => 'Traveler creates the request'], ['pt' => 'Primeira chamada com Idempotency-Key.', 'en' => 'First call with an Idempotency-Key.'], 'POST', '/v1/travel-requests', $traveler, $payload, ["Idempotency-Key: {$key}"]);
check($first['status'] === 201, 'HTTP 201, solicitação criada como rascunho', 'HTTP 201, request created as a draft');
$id = get($first['body'], 'data.id');
$again = call(['pt' => 'O mesmo pedido chega de novo', 'en' => 'The same call arrives again'], ['pt' => 'Mesma chave, mesmo corpo.', 'en' => 'Same key, same body.'], 'POST', '/v1/travel-requests', $traveler, $payload, ["Idempotency-Key: {$key}"]);
check(get($again['body'], 'data.id') === $id, 'devolve a mesma solicitação, sem criar outra', 'returns the same request instead of creating another');
check(($again['headers']['idempotent-replayed'] ?? '') === 'true', 'header Idempotent-Replayed: true', 'Idempotent-Replayed: true header');

// 2 --------------------------------------------------------------------------
scenario('workflow', ['pt' => 'Fluxo de aprovação', 'en' => 'Approval workflow'], [
    'pt' => 'Cada transição é validada: quem pode aprovar e em que estado. Erros de regra voltam com código próprio.',
    'en' => 'Every transition is checked: who may approve and from which state. Rule errors come back with their own code.',
]);
$r = call(['pt' => 'Viajante tenta aprovar a própria viagem', 'en' => 'Traveler tries to approve their own trip'], ['pt' => 'A policy só permite gestores.', 'en' => 'The policy only allows managers.'], 'POST', "/v1/travel-requests/{$id}/approve", $traveler);
check($r['status'] === 403, 'HTTP 403', 'HTTP 403');
$r = call(['pt' => 'Gestora tenta aprovar um rascunho', 'en' => 'Manager tries to approve a draft'], ['pt' => 'Rascunho ainda não foi enviado.', 'en' => 'A draft has not been submitted yet.'], 'POST', "/v1/travel-requests/{$id}/approve", $approver);
check($r['status'] === 422 && get($r['body'], 'error.code') === 'invalid_transition', 'HTTP 422 invalid_transition', 'HTTP 422 invalid_transition');
$r = call(['pt' => 'Viajante envia para aprovação', 'en' => 'Traveler submits for approval'], ['pt' => '', 'en' => ''], 'POST', "/v1/travel-requests/{$id}/submit", $traveler);
check(get($r['body'], 'data.status') === 'pending_approval', 'status pending_approval', 'status pending_approval');
$r = call(['pt' => 'Gestora aprova', 'en' => 'Manager approves'], ['pt' => 'O orçamento é conferido dentro da transação.', 'en' => 'The budget is checked inside the transaction.'], 'POST', "/v1/travel-requests/{$id}/approve", $approver);
check(get($r['body'], 'data.status') === 'approved', 'status approved', 'status approved');

// 3 --------------------------------------------------------------------------
scenario('race', ['pt' => 'Duas aprovações ao mesmo tempo', 'en' => 'Two approvals at once'], [
    'pt' => 'O centro de custo tem R$ 10.000 por mês. Duas viagens de R$ 6.000 são aprovadas no mesmo instante. Sem lock, as duas leriam o mesmo saldo e passariam. Com SELECT ... FOR UPDATE na linha do centro de custo, a segunda espera a primeira e é recusada.',
    'en' => 'The cost center has R$ 10,000 per month. Two R$ 6,000 trips are approved at the same instant. Without a lock both would read the same balance and pass. With SELECT ... FOR UPDATE on the cost-center row, the second waits for the first and is refused.',
]);
$month = $day(100);
$race = [];
foreach (['A', 'B'] as $label) {
    $created = call(['pt' => '', 'en' => ''], ['pt' => '', 'en' => ''], 'POST', '/v1/travel-requests', $traveler, ['cost_center_id' => 1, 'origin' => 'CNF', 'destination' => 'POA', 'departure_on' => $month, 'return_on' => $day(102), 'estimated_cost_cents' => 600000, 'reason' => "Viagem {$label} disputando o mesmo orçamento."], record: false);
    $raceId = get($created['body'], 'data.id');
    call(['pt' => '', 'en' => ''], ['pt' => '', 'en' => ''], 'POST', "/v1/travel-requests/{$raceId}/submit", $traveler, record: false);
    $race[] = $raceId;
}
$both = parallel(
    ['pt' => 'Duas aprovações de R$ 6.000 disparadas juntas', 'en' => 'Two R$ 6,000 approvals fired together'],
    ['pt' => 'As duas solicitações já estavam enviadas. As chamadas saem em paralelo.', 'en' => 'Both requests were already submitted. The calls go out in parallel.'],
    [['POST', "/v1/travel-requests/{$race[0]}/approve", $approver], ['POST', "/v1/travel-requests/{$race[1]}/approve", $approver]],
);
$statuses = array_map(fn (array $r) => $r['status'], $both);
sort($statuses);
check($statuses === [200, 422], 'uma aprovada (200) e uma recusada (422)', 'one approved (200) and one refused (422)');
$refused = array_values(array_filter($both, fn (array $r) => $r['status'] === 422))[0] ?? null;
check(get($refused['body'] ?? [], 'error.code') === 'budget_exceeded', 'a recusada volta com budget_exceeded', 'the refused one returns budget_exceeded');
$budget = call(['pt' => 'Orçamento do mês depois da disputa', 'en' => 'Monthly budget after the race'], ['pt' => '', 'en' => ''], 'GET', '/v1/cost-centers/1/budget?month='.substr($month, 0, 7), $approver);
check(get($budget['body'], 'data.committed_cents') === 600000, 'só R$ 6.000 comprometidos, o orçamento não estourou', 'only R$ 6,000 committed; the budget held');

// 4 --------------------------------------------------------------------------
scenario('booking', ['pt' => 'Reserva sem cobrar duas vezes', 'en' => 'Booking without double charging'], [
    'pt' => 'A emissão chama o fornecedor (simulado na demo). Um lock no Redis coordena chamadas concorrentes e a Idempotency-Key devolve a mesma reserva no reenvio.',
    'en' => 'Booking calls the supplier (simulated in the demo). A Redis lock coordinates concurrent calls, and the Idempotency-Key returns the same booking on retry.',
]);
$items = ['items' => [['type' => 'flight', 'supplier' => 'LATAM', 'amount_cents' => 180000], ['type' => 'hotel', 'supplier' => 'Ibis Paulista', 'amount_cents' => 90000]]];
$bookKey = "demo-book-{$run}";
$r = call(['pt' => 'Emite voo e hotel', 'en' => 'Books flight and hotel'], ['pt' => '', 'en' => ''], 'POST', "/v1/travel-requests/{$id}/bookings", $traveler, $items, ["Idempotency-Key: {$bookKey}"]);
check(get($r['body'], 'data.status') === 'booked' && count(get($r['body'], 'data.bookings') ?? []) === 2, 'status booked com duas reservas', 'status booked with two bookings');
$r = call(['pt' => 'O cliente reenvia a emissão', 'en' => 'The client retries the booking'], ['pt' => 'Mesma chave.', 'en' => 'Same key.'], 'POST', "/v1/travel-requests/{$id}/bookings", $traveler, $items, ["Idempotency-Key: {$bookKey}"]);
check(($r['headers']['idempotent-replayed'] ?? '') === 'true' && count(get($r['body'], 'data.bookings') ?? []) === 2, 'resposta reaproveitada, continuam duas reservas', 'saved response replayed, still two bookings');

// 5 --------------------------------------------------------------------------
scenario('tenancy', ['pt' => 'Isolamento entre empresas', 'en' => 'Tenant isolation'], [
    'pt' => 'Um usuário de outra empresa tenta ler a viagem e o orçamento pelo ID. O escopo por empresa responde como se não existissem.',
    'en' => 'A user from another company tries to read the trip and the budget by ID. Company scoping answers as if they did not exist.',
]);
$r = call(['pt' => 'Outra empresa pede a viagem', 'en' => 'Another company asks for the trip'], ['pt' => '', 'en' => ''], 'GET', "/v1/travel-requests/{$id}", $other);
check($r['status'] === 404, 'HTTP 404', 'HTTP 404');
$r = call(['pt' => 'Outra empresa pede o orçamento', 'en' => 'Another company asks for the budget'], ['pt' => '', 'en' => ''], 'GET', '/v1/cost-centers/1/budget', $other);
check($r['status'] === 404, 'HTTP 404', 'HTTP 404');

// 6 --------------------------------------------------------------------------
scenario('outbox', ['pt' => 'Eventos depois do commit', 'en' => 'Events after commit'], [
    'pt' => 'Cada transição gravou um evento na outbox na mesma transação do estado. O relay publica no broker depois do commit, então uma queda do broker não perde eventos nem publica algo que foi desfeito.',
    'en' => 'Each transition saved an event in the outbox inside the same transaction as the state. The relay publishes to the broker after commit, so a broker outage neither loses events nor publishes something that was rolled back.',
]);
$before = call(['pt' => 'Eventos pendentes na outbox', 'en' => 'Pending events in the outbox'], ['pt' => 'O relay ainda não rodou.', 'en' => 'The relay has not run yet.'], 'GET', '/health');
check((int) get($before['body'], 'outbox.pending') > 0, 'há eventos esperando publicação', 'events are waiting to be published');
exec("{$artisan} outbox:relay --once 2>&1", $relayOutput, $relayCode);
$after = call(['pt' => 'Depois de um lote do relay', 'en' => 'After one relay batch'], ['pt' => 'php artisan outbox:relay --once', 'en' => 'php artisan outbox:relay --once'], 'GET', '/health');
check($relayCode === 0 && (int) get($after['body'], 'outbox.pending') === 0, 'outbox zerada: todos publicados no broker', 'outbox empty: everything published to the broker');

$recording = [
    'recorded_at' => gmdate('c'),
    'commit' => getenv('GITHUB_SHA') ?: trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')),
    'run_url' => getenv('GITHUB_RUN_ID') ? 'https://github.com/'.getenv('GITHUB_REPOSITORY').'/actions/runs/'.getenv('GITHUB_RUN_ID') : null,
    'stack' => getenv('DEMO_STACK') ?: 'local',
    'scenarios' => $scenarios,
    'steps' => $steps,
    'checks' => ['total' => array_sum(array_map(fn ($s) => count($s['checks']), $steps)), 'failed' => $failures],
];
@mkdir(dirname($output), 0777, true);
file_put_contents($output, json_encode($recording, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
fwrite(STDOUT, sprintf("%d verificações, %d falhas. Gravação em %s\n", $recording['checks']['total'], $failures, $output));
exit($failures === 0 ? 0 : 1);
