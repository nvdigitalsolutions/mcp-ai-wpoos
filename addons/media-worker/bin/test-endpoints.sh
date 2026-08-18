#!/usr/bin/env bash
# Media Worker Sidecar — Endpoint Test Suite
# Usage: bash bin/test-endpoints.sh [http://localhost:3100]
# Optional: set MEDIA_WORKER_TOKEN to authenticate against v2.2.0+ workers
set -euo pipefail
BASE="${1:-http://localhost:3100}"
TOKEN="${MEDIA_WORKER_TOKEN:-}"
if [ -n "$TOKEN" ]; then
  AUTH_HDR=(-H "X-Site-Token: $TOKEN")
else
  AUTH_HDR=()
fi
PASS=0; FAIL=0
test_ep() {
  local label="$1" method="$2" path="$3" data="$4"
  resp=$(curl -s -w "\n%{http_code}" -X "$method" "$BASE$path" -H "Content-Type: application/json" "${AUTH_HDR[@]}" ${data:+-d "$data"} --max-time 15 2>/dev/null)
  code=$(echo "$resp" | tail -1)
  if [ "$code" = "200" ]; then echo "  ✅ $label"; PASS=$((PASS+1)); else echo "  ❌ $label (HTTP $code)"; FAIL=$((FAIL+1)); fi
}
echo "Media Worker Test Suite — $BASE"
echo ""
echo "Code:"; test_ep "Format JS" POST /api/code/format '{"code":"const x=1;","options":{"parser":"babel"}}'
test_ep "Check syntax" POST /api/code/check-syntax '{"code":"const x=1;","parser":"babel"}'
echo ""; echo "Email:"; test_ep "MJML" POST /api/email/compile-mjml '{"mjml":"<mjml><mj-body><mj-section><mj-column><mj-text>Hi</mj-text></mj-column></mj-section></mj-body></mjml>"}'
echo ""; echo "Data:"; test_ep "Translate" POST /api/data/translate '{"text":"Hello","to":"fr"}'
test_ep "Language" POST /api/data/language-detect '{"text":"Bonjour"}'
test_ep "QR Code" POST /api/data/qrcode '{"text":"https://example.com"}'
test_ep "Math" POST /api/data/render-math '{"latex":"E=mc^2"}'
test_ep "ICS" POST /api/data/generate-ics '{"events":[{"title":"Test","start":[2026,8,10,12,0],"duration":{"hours":1}}]}'
test_ep "Chart" POST /api/data/render-chart '{"type":"bar","data":{"labels":["A","B"],"datasets":[{"label":"T","data":[10,20]}]}}'
test_ep "Geo" POST /api/data/analyze-geospatial '{"operation":"area","geojson":{"type":"Polygon","coordinates":[[[0,0],[0,1],[1,1],[1,0],[0,0]]]}}'
echo ""; echo "Document:"; test_ep "Excel" POST /api/document/excel '{"sheets":[{"name":"T","rows":[["A","B"],[1,2]]}]}'
test_ep "Word" POST /api/document/word '{"content":[{"type":"paragraph","text":"Hi"}]}'
echo ""; echo "PDF:"; test_ep "Generate" POST /api/pdf/generate '{"html":"<h1>T</h1>"}'
echo ""; echo "Browser:"; test_ep "Screenshot" POST /api/browser/screenshot '{"url":"https://example.com","format":"png"}'
echo ""; echo "Crawl:"; test_ep "Markdown (static)" POST /api/crawl/markdown '{"url":"https://example.com","render":"never"}'
test_ep "Links (static)" POST /api/crawl/links '{"url":"https://example.com","render":"never"}'
echo ""; echo "Crawl4AI facade:"
crawl4ai_roundtrip() {
  sub=$(curl -s -X POST "$BASE/api/crawl4ai/crawl" -H "Content-Type: application/json" "${AUTH_HDR[@]}" -d '{"urls":["https://example.com"]}' --max-time 15)
  task_id=$(echo "$sub" | grep -o '"task_id":"[^"]*"' | cut -d'"' -f4 || true)
  if [ -z "$task_id" ]; then echo "  ❌ Crawl4AI facade submit ($sub)"; FAIL=$((FAIL+1)); return; fi
  sleep 3
  resp=$(curl -s -w "\n%{http_code}" "$BASE/api/crawl4ai/task/$task_id" "${AUTH_HDR[@]}" --max-time 15 2>/dev/null)
  code=$(echo "$resp" | tail -1)
  if [ "$code" = "200" ]; then echo "  ✅ Crawl4AI facade round-trip ($task_id)"; PASS=$((PASS+1)); else echo "  ❌ Crawl4AI facade poll (HTTP $code)"; FAIL=$((FAIL+1)); fi
}
crawl4ai_roundtrip
echo ""; echo "──────────────"; echo "$PASS passed, $FAIL failed"
