#!/bin/sh
# Start/stop/status the backup-download sidecar for the Hermes WebUI.
# Usage: run-sidecar.sh {start|stop|status}
#
# The sidecar is started with setsid + nohup so it survives the shell that
# launched it (otherwise process-group teardown kills it). The sidecar writes
# its own pid file at startup and removes it on exit.

EXT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PID_FILE="$EXT_DIR/.sidecar.pid"
LOG_FILE="$EXT_DIR/sidecar.log"
PORT="${HERMES_BACKUP_SIDECAR_PORT:-19001}"

is_running() {
  [ -f "$PID_FILE" ] || return 1
  pid=$(cat "$PID_FILE" 2>/dev/null) || return 1
  kill -0 "$pid" 2>/dev/null
}

case "${1:-}" in
  start)
    if is_running; then
      echo "already running (pid $(cat "$PID_FILE"), port $PORT)"
      exit 0
    fi
    rm -f "$PID_FILE"  # clear any stale pid file
    setsid nohup python3 "$EXT_DIR/sidecar.py" >>"$LOG_FILE" 2>&1 &
    i=0
    while [ ! -s "$PID_FILE" ] && [ "$i" -lt 30 ]; do
      sleep 0.1
      i=$((i + 1))
    done
    if [ -s "$PID_FILE" ]; then
      echo "started (pid $(cat "$PID_FILE"), port $PORT, log $LOG_FILE)"
    else
      echo "FAILED to start — check $LOG_FILE" >&2
      exit 1
    fi
    ;;
  stop)
    if is_running; then
      kill "$(cat "$PID_FILE")" 2>/dev/null
      rm -f "$PID_FILE"
      echo "stopped"
    else
      echo "not running"
    fi
    ;;
  status)
    if is_running; then
      echo "running (pid $(cat "$PID_FILE"), port $PORT)"
    else
      echo "stopped"
    fi
    ;;
  *)
    echo "usage: $0 {start|stop|status}" >&2
    exit 2
    ;;
esac
