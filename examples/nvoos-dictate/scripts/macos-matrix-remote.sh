#!/usr/bin/env bash
# Remote-mac automatable subset of docs/ptt-matrix-macos.md.
#
# Runs on a macos-latest runner (CI step or SSH session) or any Mac with the
# debug .app already built. Covers what needs no human: bundle surface,
# sidecar NDJSON framing, 30 s boot residency, and Aqua screenshots (the
# Home page shows the permission states — denied on CI by design).
#
# TCC *grants*, real PTT keystrokes, paste-in-target-app, and meeting
# capture need a human at the GUI (Namespace VNC or a physical Mac) — see
# docs/ptt-matrix-macos.md for the full matrix.
#
# Usage: bash scripts/macos-matrix-remote.sh [path-to-.app]
set -euo pipefail

APP="${1:-src-tauri/target/debug/bundle/macos/NV oOS Dictation.app}"
OUT="${NVOS_MATRIX_OUT:-/tmp/nvoos-matrix}"
mkdir -p "$OUT"

step() { echo; echo "=== $*"; }

if [[ ! -d "$APP" ]]; then
  echo "bundle not found: $APP" >&2
  echo "build it first: npm run tauri -- build --debug --bundles app" >&2
  exit 1
fi

step "Bundle surface (Info.plist keys of interest)"
plutil -p "$APP/Contents/Info.plist" \
  | grep -E 'UsageDescription|MinimumSystemVersion|CFBundleIdentifier|CFBundleShortVersionString|CFBundleName' \
  || true

step "Sidecar framing (--check prints hello/ready/stopped)"
HELPER="$(find "$APP/Contents/MacOS" -maxdepth 1 -type f -name '*audio-helper*' -print -quit)"
if [[ -n "$HELPER" ]]; then
  "$HELPER" --check
else
  echo "WARNING: sidecar binary not found in bundle"
fi

step "Boot residency (30 s)"
BIN="$APP/Contents/MacOS/NV oOS Dictation"
"$BIN" > "$OUT/boot.log" 2>&1 &
BOOT_PID=$!
for i in $(seq 1 30); do
  sleep 1
  if ! kill -0 "$BOOT_PID" 2>/dev/null; then
    echo "app exited before the 30 s mark — boot log:"
    cat "$OUT/boot.log"
    exit 1
  fi
done
echo "boot OK (pid $BOOT_PID)"
cat "$OUT/boot.log"

step "Aqua screenshot (Home page shows permission states)"
sleep 3
if screencapture -x "$OUT/boot.png" && [[ -s "$OUT/boot.png" ]]; then
  echo "saved $OUT/boot.png"
else
  echo "WARNING: screencapture unavailable in this session"
fi

step "Stop the app"
kill "$BOOT_PID" 2>/dev/null || true
sleep 1

echo
echo "evidence in $OUT"
ls -l "$OUT"
