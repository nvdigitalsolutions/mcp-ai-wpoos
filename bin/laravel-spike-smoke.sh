#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────
# NV oOS Laravel spike smoke test (P1–P6).
#
# Usage:
#   bin/laravel-spike-smoke.sh
#   bin/laravel-spike-smoke.sh --no-build
# ─────────────────────────────────────────────────────────────

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE=(docker compose -f "$ROOT/docker/docker-compose.laravel.yml")
BASE="http://localhost:8001"
FAIL=0

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
ok() { printf '  PASS  %s\n' "$*"; }
bad() { printf '  FAIL  %s\n' "$*"; FAIL=1; }

say "Starting the spike stack"
if [[ "${1:-}" == "--no-build" ]]; then
    "${COMPOSE[@]}" up -d
else
    "${COMPOSE[@]}" up -d --build
fi

say "Waiting for /health"
for _ in $(seq 1 90); do
    if curl -fsS "$BASE/health" >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

HEALTH="$(curl -fsS "$BASE/health" || true)"
if echo "$HEALTH" | grep -q '"ok":true'; then ok "P1 /health ok"; else bad "P1 /health"; echo "$HEALTH"; fi
if echo "$HEALTH" | grep -q '"abspath":false'; then ok "P1 no ABSPATH"; else bad "P1 ABSPATH guard"; fi
if echo "$HEALTH" | grep -q '"frankenphp-worker"'; then ok "P1 FrankenPHP worker"; else bad "P1 worker runtime"; fi

say "Doctor"
"${COMPOSE[@]}" exec -T app php artisan nvoos:doctor || bad "doctor command"

TOKEN="$("${COMPOSE[@]}" exec -T app php artisan nvoos:spike-token | tail -n 1 | tr -d '\r')"
if [[ -n "$TOKEN" ]]; then ok "Sanctum token issued"; else bad "Sanctum token"; fi

say "P2 tool execution"
TOOL="$(curl -sS -X POST "$BASE/dev/tools/color_convert" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"color":"#ff0000","to_format":"rgb"}')"
if echo "$TOOL" | grep -q '"success":true' && echo "$TOOL" | grep -q 'rgb(255,0,0)'; then
    ok "P2 color_convert"
else
    bad "P2 color_convert"; echo "$TOOL"
fi

say "P3 SSE stream"
SSE_FILE="$(mktemp)"
curl -sN --max-time 20 -X POST "$BASE/chat/stream" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"messages":[{"role":"user","content":"hi"}],"assistant":{"provider":"spike","model":"spike-1","tools":[]},"options":{}}' \
    >"$SSE_FILE" || true

if grep -q 'event: message' "$SSE_FILE"; then ok "P3 SSE message frames"; else bad "P3 SSE message frames"; fi
if grep -q '\[DONE\]' "$SSE_FILE"; then ok "P3 SSE done marker"; else bad "P3 SSE done marker"; fi
rm -f "$SSE_FILE"

curl -sN --max-time 1 -X POST "$BASE/chat/stream" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"messages":[{"role":"user","content":"disconnect"}],"assistant":{"provider":"spike","model":"spike-1","tools":[]},"options":{}}' \
    >/dev/null 2>&1 || true

if curl -fsS "$BASE/health" >/dev/null 2>&1; then ok "P3 worker survives disconnect"; else bad "P3 worker survives disconnect"; fi

say "P4 queue retry"
QUEUE_OUT="$("${COMPOSE[@]}" exec -T app php artisan nvoos:queue-demo --wait=30 || true)"
if echo "$QUEUE_OUT" | grep -q 'Final: completed'; then ok "P4 job retried to completion"; else bad "P4 queue retry"; echo "$QUEUE_OUT"; fi

say "P5 persistence + P7 adapter suite"
if "${COMPOSE[@]}" exec -T app php vendor/bin/phpunit --filter 'ContentStoreRoundTripTest' >/dev/null 2>&1; then
    ok "P5 content round-trip"
else
    bad "P5 content round-trip"
fi
if "${COMPOSE[@]}" exec -T app php vendor/bin/phpunit >/dev/null 2>&1; then
    ok "P7 app suite"
else
    bad "P7 app suite"
fi

say "P6 MCP (unit + legacy initialize)"
if "${COMPOSE[@]}" exec -T app php vendor/bin/phpunit --filter 'McpServerTest' >/dev/null 2>&1; then
    ok "P6 MCP tool unit"
else
    bad "P6 MCP tool unit"
fi

MCP_FILE="$(mktemp)"
curl -sS --max-time 20 -X POST "$BASE/mcp" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/json, text/event-stream" \
    -H "Content-Type: application/json" \
    -H "MCP-Protocol-Version: 2025-06-18" \
    -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"spike-smoke","version":"1.0"}}}' \
    >"$MCP_FILE" || true

if grep -qE 'serverInfo|NV oOS|result' "$MCP_FILE"; then ok "P6 MCP initialize"; else bad "P6 MCP initialize"; cat "$MCP_FILE"; fi
rm -f "$MCP_FILE"

say "Summary"
if [[ "$FAIL" -eq 0 ]]; then
    echo "All smoke checks passed."
    exit 0
fi

echo "One or more smoke checks failed."
exit 1
