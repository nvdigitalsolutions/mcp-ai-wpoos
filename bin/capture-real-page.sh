#!/bin/sh
# Capture the real (theme-rendered, admin-bar) Ollama Test Lab page from a
# local Playground server for offline analysis.
#
# Usage: sh bin/capture-real-page.sh
#
# Boots `@wp-playground/cli server` against blueprints/ollama-demo.json in
# the background, polls until /ollama-test-lab/ returns 200, saves the HTML
# to verify-out/real-page.html, then kills the server process tree.

cd "$(dirname "$0")/.." || exit 1

PORT=9411
GOT=0
TRIES=0

MSYS_NO_PATHCONV=1 npx -y @wp-playground/cli@3.1.54 server \
	--blueprint=blueprints/ollama-demo.json \
	--port="$PORT" \
	--login \
	--mount-dir-before-install "F:\\GITHUB\\mcp-ai-wpoos\\verify-out" "/verify-out" \
	--verbosity=quiet > verify-out/server.log 2>&1 &
SERVER_PID=$!
echo "$SERVER_PID" > verify-out/server.pid
echo "server pid: $SERVER_PID"

while [ "$TRIES" -lt 60 ]; do
	TRIES=$((TRIES + 1))
	# The FIRST request triggers the full blueprint boot (download + install
	# + activate) inside the request — give it minutes. Later polls are
	# bounded so a hung worker cannot stall the loop forever.
	if [ "$TRIES" -eq 1 ]; then
		MAX_TIME=360
	else
		MAX_TIME=15
	fi
	CODE=$(curl -sL --max-time "$MAX_TIME" -o verify-out/real-page.html -w "%{http_code}" "http://127.0.0.1:$PORT/ollama-test-lab/" 2>/dev/null || echo "000")
	if [ "$TRIES" -le 5 ]; then
		echo "try $TRIES -> http $CODE" >> verify-out/poll.log
	fi
	if [ "$CODE" = "200" ]; then
		GOT=1
		echo "page fetched on try $TRIES (http $CODE)"
		break
	fi
	sleep 3
done

if [ "$GOT" = "1" ]; then
	SIZE=$(wc -c < verify-out/real-page.html)
	LINES=$(wc -l < verify-out/real-page.html)
	echo "saved: $SIZE bytes, $LINES lines"
else
	echo "never got 200; server log tail:"
	tail -n 20 verify-out/server.log
fi

# Kill the whole process tree (npx wrapper + node child).
taskkill //F //T //PID "$SERVER_PID" >/dev/null 2>&1
echo "done GOT=$GOT TRIES=$TRIES"
