<?php

declare(strict_types=1);

/**
 * Phase 3b soak harness — Proposal 090-B, task 5.6.
 *
 * Exercises every Phase 3b surface (approvals, triggers, security center,
 * paper store) against a running Laravel console for a fixed wall-clock
 * duration, then reports latency percentiles and any contract violations.
 *
 * Usage:
 *   php docker/laravel/soak/phase3b-soak.php \
 *       --base=http://127.0.0.1:8001 \
 *       --token='<sanctum-bearer>' \
 *       --minutes=30
 *
 * Exit codes:
 *   0  every iteration passed and the latency budget held
 *   1  at least one contract violation or latency-budget breach
 *   2  configuration / pre-flight failure
 *
 * The harness creates and then deletes its own paper records and trigger.
 * Approvals are append-only by design; rows created with `session_id=soak`
 * are removed by the operator afterwards:
 *
 *   docker exec oos-laravel-app php artisan tinker --execute="\DB::table('nvoos_approvals')->where('session_id','soak')->delete();"
 */

$options = getopt('', ['base:', 'token:', 'minutes:', 'rate:', 'out:', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php phase3b-soak.php --base=<url> --token=<bearer> [--minutes=30] [--rate=1] [--out=report.json]\n");
    exit(2);
}

$base = rtrim((string) ($options['base'] ?? ''), '/');
$token = (string) ($options['token'] ?? getenv('NVOOS_SOAK_TOKEN') ?: '');
$minutes = (float) ($options['minutes'] ?? 30);
$rate = max(0.05, (float) ($options['rate'] ?? 1.0));
$out = (string) ($options['out'] ?? '');

if ($base === '' || $token === '' || $minutes <= 0) {
    fwrite(STDERR, "Missing --base, --token or --minutes.\n");
    exit(2);
}

/** @var array<string, list<float>> $latencies */
$latencies = [];
$violations = [];
$requests = 0;
$iterations = 0;
$startedAt = microtime(true);
$deadline = $startedAt + ($minutes * 60);
$nextTick = $startedAt;

/**
 * Perform one authenticated JSON request and validate the response.
 *
 * @param list<int>                 $expect Acceptable HTTP status codes.
 * @param array<string, mixed>|null $body   Request payload, or null for none.
 * @param array<string, list<float>> $latencies Latency buckets, by label.
 * @param list<array<string, mixed>> $violations Violation log.
 * @return array{status:int, body:mixed, ms:float, error:string|null}
 */
function request(
    string $method,
    string $url,
    string $token,
    ?array $body,
    array $expect,
    string $label,
    array &$latencies,
    array &$violations,
    int &$requests,
): array {
    $handle = curl_init($url);

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer '.$token,
    ];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, (string) json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $began = microtime(true);
    $raw = curl_exec($handle);
    $ms = (microtime(true) - $began) * 1000;
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    $requests++;
    $latencies[$label][] = $ms;

    if ($raw === false || $curlError !== '') {
        $violations[] = ['label' => $label, 'kind' => 'transport', 'detail' => $curlError !== '' ? $curlError : 'no response'];

        return ['status' => 0, 'body' => null, 'ms' => $ms, 'error' => $curlError !== '' ? $curlError : 'no response'];
    }

    $decoded = json_decode((string) $raw, true);

    if (! in_array($status, $expect, true)) {
        $violations[] = [
            'label' => $label,
            'kind' => 'status',
            'detail' => 'HTTP '.$status.' (expected '.implode('/', $expect).')',
            'body' => substr((string) $raw, 0, 300),
        ];
    } elseif ($decoded === null && trim((string) $raw) !== '') {
        $violations[] = ['label' => $label, 'kind' => 'json', 'detail' => 'response was not valid JSON'];
    }

    return ['status' => $status, 'body' => $decoded, 'ms' => $ms, 'error' => null];
}

/**
 * @param list<float> $values Sorted-agnostic latency values.
 */
function percentile(array $values, float $p): float
{
    if ($values === []) {
        return 0.0;
    }

    sort($values);
    $index = (int) ceil(($p / 100) * count($values)) - 1;

    return $values[max(0, min($index, count($values) - 1))];
}

// ---------------------------------------------------------------- pre-flight

$preflight = request('GET', $base.'/api/v1/security/posture', $token, null, [200], 'preflight', $latencies, $violations, $requests);

if ($preflight['status'] !== 200) {
    fwrite(STDERR, "Pre-flight failed: HTTP ".$preflight['status']."\n");
    exit(2);
}

// One long-lived trigger reused for every toggle, so the soak does not grow
// the trigger table.
$trigger = request('POST', $base.'/api/v1/triggers', $token, [
    'name' => 'Soak trigger',
    'type' => 'soak',
    'config' => ['interval' => 60],
], [201], 'triggers.create', $latencies, $violations, $requests);

$triggerId = is_array($trigger['body']) && isset($trigger['body']['id']) ? (int) $trigger['body']['id'] : 0;

if ($triggerId === 0) {
    fwrite(STDERR, sprintf(
        "Pre-flight failed: could not create the soak trigger (HTTP %d): %s\n",
        $trigger['status'],
        substr((string) json_encode($trigger['body']), 0, 400),
    ));
    exit(2);
}

fwrite(STDOUT, sprintf(
    "Soak start — %.1f min at %.2f iter/s against %s\n",
    $minutes,
    $rate,
    $base,
));

$iteration = 0;
$lastProgress = $startedAt;

// --------------------------------------------------------------------- loop

while (microtime(true) < $deadline) {
    $iterations++;
    $iteration++;

    // 1. Security posture — the heaviest read path.
    $posture = request('GET', $base.'/api/v1/security/posture', $token, null, [200], 'security.posture', $latencies, $violations, $requests);

    if (is_array($posture['body'])) {
        if (! isset($posture['body']['score']) || ! is_int($posture['body']['score'])) {
            $violations[] = ['label' => 'security.posture', 'kind' => 'shape', 'detail' => 'score is not an integer'];
        }

        if (! isset($posture['body']['signals']) || ! is_array($posture['body']['signals'])) {
            $violations[] = ['label' => 'security.posture', 'kind' => 'shape', 'detail' => 'signals is not a list'];
        }
    }

    // 2. Header preview — cheap read.
    request('GET', $base.'/api/v1/security/preview-headers', $token, null, [200], 'security.headers', $latencies, $violations, $requests);

    // 3. Paper store write → update → delete (bounded table growth).
    $slug = 'soak-'.$iteration.'-'.substr((string) mt_rand(), 0, 6);
    $record = request('POST', $base.'/api/v1/paper-store/soak', $token, [
        'id' => $slug,
        'title' => 'Soak record '.$iteration,
        'tags' => ['soak'],
        'status' => 'draft',
        'body' => ['iteration' => $iteration],
    ], [201], 'paper.create', $latencies, $violations, $requests);

    if (is_array($record['body']) && isset($record['body']['id'])) {
        $id = (string) $record['body']['id'];
        request('PUT', $base.'/api/v1/paper-store/soak/'.$id, $token, ['title' => 'Soak record '.$iteration.' v2'], [200], 'paper.update', $latencies, $violations, $requests);
        request('DELETE', $base.'/api/v1/paper-store/soak/'.$id, $token, null, [200], 'paper.delete', $latencies, $violations, $requests);
    }

    // 4. Paper store list + search — read paths.
    request('GET', $base.'/api/v1/paper-store/soak?limit=10', $token, null, [200], 'paper.list', $latencies, $violations, $requests);
    request('GET', $base.'/api/v1/paper-store/search?q=Soak&limit=10', $token, null, [200], 'paper.search', $latencies, $violations, $requests);

    // 5. Approval create → approve (the 409 double-resolve path lives in the
    //    feature test, not in the soak).
    $approval = request('POST', $base.'/api/v1/approvals', $token, [
        'tool' => 'soak_probe',
        'arguments' => ['iteration' => $iteration],
        'session_id' => 'soak',
        'reason' => 'Soak harness',
    ], [201], 'approvals.create', $latencies, $violations, $requests);

    if (is_array($approval['body']) && isset($approval['body']['id'])) {
        request('POST', $base.'/api/v1/approvals/'.$approval['body']['id'].'/approve', $token, ['note' => 'soak'], [200], 'approvals.resolve', $latencies, $violations, $requests);
    }

    request('GET', $base.'/api/v1/approvals?limit=10', $token, null, [200], 'approvals.list', $latencies, $violations, $requests);

    // 6. Trigger toggle + list.
    request('PUT', $base.'/api/v1/triggers/'.$triggerId, $token, ['enabled' => $iteration % 2 === 0], [200], 'triggers.update', $latencies, $violations, $requests);
    request('GET', $base.'/api/v1/triggers', $token, null, [200], 'triggers.list', $latencies, $violations, $requests);

    if (microtime(true) - $lastProgress >= 60) {
        $lastProgress = microtime(true);
        fwrite(STDOUT, sprintf(
            "  %4.1f min elapsed · %d iterations · %d requests · %d violations\n",
            ($lastProgress - $startedAt) / 60,
            $iterations,
            $requests,
            count($violations),
        ));
    }

    $nextTick += 1 / $rate;
    $sleep = $nextTick - microtime(true);

    if ($sleep > 0) {
        usleep((int) ($sleep * 1_000_000));
    }
}

// ---------------------------------------------------------------- teardown

request('DELETE', $base.'/api/v1/triggers/'.$triggerId, $token, null, [200], 'triggers.delete', $latencies, $violations, $requests);

// ------------------------------------------------------------------ report

$elapsed = microtime(true) - $startedAt;
$all = $latencies === [] ? [] : array_merge(...array_values($latencies));
$summary = [
    'started_at' => gmdate('c', (int) $startedAt),
    'duration_seconds' => round($elapsed, 2),
    'iterations' => $iterations,
    'requests' => $requests,
    'throughput_rps' => round($requests / max(0.001, $elapsed), 2),
    'latency_ms' => [
        'p50' => round(percentile($all, 50), 1),
        'p95' => round(percentile($all, 95), 1),
        'p99' => round(percentile($all, 99), 1),
        'max' => round(max($all === [] ? [0.0] : $all), 1),
    ],
    'violations' => $violations,
    'per_endpoint' => [],
];

foreach ($latencies as $label => $values) {
    $summary['per_endpoint'][$label] = [
        'count' => count($values),
        'p50' => round(percentile($values, 50), 1),
        'p95' => round(percentile($values, 95), 1),
        'max' => round(max($values), 1),
    ];
}

fwrite(STDOUT, "\n".str_repeat('-', 68)."\n");
fwrite(STDOUT, sprintf("Duration   : %.1f s (%.1f min)\n", $elapsed, $elapsed / 60));
fwrite(STDOUT, sprintf("Iterations : %d\n", $iterations));
fwrite(STDOUT, sprintf("Requests   : %d (%.2f req/s)\n", $requests, $summary['throughput_rps']));
fwrite(STDOUT, sprintf(
    "Latency    : p50 %.1f ms · p95 %.1f ms · p99 %.1f ms · max %.1f ms\n",
    $summary['latency_ms']['p50'],
    $summary['latency_ms']['p95'],
    $summary['latency_ms']['p99'],
    $summary['latency_ms']['max'],
));
fwrite(STDOUT, sprintf("Violations : %d\n", count($violations)));

foreach (array_slice($violations, 0, 10) as $violation) {
    fwrite(STDOUT, sprintf("  - [%s] %s: %s\n", $violation['kind'], $violation['label'], $violation['detail']));
}

if ($out !== '') {
    file_put_contents($out, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    fwrite(STDOUT, "Report     : ".$out."\n");
}

$latencyBudget = 500.0;
$passed = $violations === [] && $summary['latency_ms']['p95'] <= $latencyBudget;

fwrite(STDOUT, "Result     : ".($passed ? 'PASS' : 'FAIL')."\n");

exit($passed ? 0 : 1);
