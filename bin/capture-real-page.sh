#!/bin/sh
# Capture the real (theme-rendered, admin-bar) Ollama Test Lab page from a
# local Playground server for offline analysis.
#
# Usage: sh bin/capture-real-page.sh
#
# Boots `@wp-playground/cli server` against blueprints/ollama-demo.json in
# the background, waits for the seeded page to appear, then fetches
# /ollama-test-lab/ over HTTP and saves the HTML to verify-out/real-page.html
# before killing the server process tree.
#
# Server-mode gotchas this script handles (see the
# mcp-ai-wpoos-playground-demos skill, "Validation harness (CLI)"):
#
# - The CLI answers "502 WordPress is not ready yet" while it boots, and the
#   blueprint steps (installPlugin + runPHP seed) keep running lazily in the
#   BACKGROUND after the first request — the seeded page can take minutes to
#   appear. The script polls the REST page index until the page exists.
# - The login step answers the first request from each cookie-less client
#   with a one-time 302 self-redirect (auto-login). curl -L without a cookie
#   jar loops on it forever, so every request here uses -c/-b.

cd "$(dirname "$0")/.." || exit 1
mkdir -p verify-out

PORT=9411
JAR=verify-out/capture-cookies.txt
rm -f "$JAR" verify-out/server.log verify-out/poll.log verify-out/real-page.html verify-out/pages.json

MSYS_NO_PATHCONV=1 npx -y @wp-playground/cli@3.1.54 server \
	--blueprint=blueprints/ollama-demo.json \
	--port="$PORT" \
	--login \
	--verbosity=quiet > verify-out/server.log 2>&1 &
SERVER_PID=$!
echo "$SERVER_PID" > verify-out/server.pid
echo "server pid: $SERVER_PID"

# Kill the whole process tree (npx wrapper + node child). taskkill on
# Windows; plain kill elsewhere.
kill_server() {
	if command -v taskkill >/dev/null 2>&1; then
		taskkill //F //T //PID "$SERVER_PID" >/dev/null 2>&1
	else
		kill "$SERVER_PID" >/dev/null 2>&1
	fi
}

# Phase 1: wait until the server accepts connections (curl exit 0 = HTTP
# reply, even a 502).
LISTEN=0
PROBE=0
while [ "$PROBE" -lt 60 ]; do
	PROBE=$((PROBE + 1))
	if curl -s --max-time 5 -o /dev/null "http://127.0.0.1:$PORT/" 2>/dev/null; then
		LISTEN=1
		break
	fi
	sleep 5
done
echo "listening after probe $PROBE" >> verify-out/poll.log

if [ "$LISTEN" != "1" ]; then
	echo "server never started listening; log tail:"
	tail -n 20 verify-out/server.log
	kill_server
	exit 1
fi

# Phase 2: wait for the seed — the demo page appears in the REST index once
# the blueprint's runPHP step has finished.
SEEDED=0
POLLS=0
while [ "$POLLS" -lt 45 ]; do
	POLLS=$((POLLS + 1))
	curl -sL -c "$JAR" -b "$JAR" --max-time 30 \
		"http://127.0.0.1:$PORT/wp-json/wp/v2/pages?slug=ollama-test-lab" \
		-o verify-out/pages.json
	if grep -q '"slug":"ollama-test-lab"' verify-out/pages.json; then
		SEEDED=1
		break
	fi
	sleep 20
done
echo "seed polled $POLLS times (seeded=$SEEDED)" >> verify-out/poll.log

# Phase 3: fetch the rendered landing page (cookie jar follows the
# auto-login 302 the way a browser does).
CODE=$(curl -sL -c "$JAR" -b "$JAR" --max-time 600 \
	-o verify-out/real-page.html -w "%{http_code}" \
	"http://127.0.0.1:$PORT/ollama-test-lab/" 2>/dev/null || echo "ERR")

if [ "$CODE" = "200" ] && [ -f verify-out/real-page.html ]; then
	SIZE=$(wc -c < verify-out/real-page.html)
	LINES=$(wc -l < verify-out/real-page.html)
	echo "page fetched: http 200, $SIZE bytes, $LINES lines"
	for M in "Ollama Test Lab" "nvoos-ollama-status" "wp-mcp-ai-chat"; do
		echo "marker '$M': $(grep -c "$M" verify-out/real-page.html)"
	done
else
	echo "page fetch failed (http $CODE); server log tail:"
	tail -n 20 verify-out/server.log
fi

kill_server
echo "done (seeded=$SEEDED, http=$CODE)"
