#!/usr/bin/env python3
"""Loopback sidecar for the Hermes WebUI "backup-download" extension.

Wraps the native ``hermes backup`` command and exposes a small control plane
for the extension's browser widget. Binds 127.0.0.1 ONLY.

Endpoints:
  GET  /health                              -> {"ok": true, "service": ...,
                                               "version": ..., "token": ...}
                                               (open: the WebUI probes this
                                               server-side; the browser gets the
                                               token via the authenticated proxy)
  POST /backup                              -> 202 {"state": "running"}
                                               (header X-Backup-Download-Token;
                                                optional JSON body {"quick": bool})
  GET  /status                              -> backup job state
                                               (header X-Backup-Download-Token)
  GET  /list                                -> recent backups in BACKUP_DIR
                                               (header X-Backup-Download-Token)
  GET  /chunk/<token>/<file>/<offset>/<len> -> raw bytes, <= 448 KB per chunk
                                               (the WebUI proxy caps proxied
                                               responses at 512 KB, so the
                                               browser assembles the zip from
                                               chunks fetched through the proxy)
  GET  /download/<token>/<file>             -> full file with
                                               Content-Disposition: attachment
                                               (local/curl convenience only;
                                               proxied responses are capped)

Security model:
  * Token: generated in memory at startup, returned by /health. The browser
    learns it through the authenticated same-origin WebUI proxy; cross-origin
    pages cannot read it (no CORS headers are ever sent) and cannot send the
    custom token header (would require a preflight this server denies).
  * Filenames are strictly validated against the backup naming pattern and
    resolved inside BACKUP_DIR only (no traversal).
  * No secrets are written to disk here; the zips contain Hermes state and
    stay in the user's own home directory with default permissions.

Env:
  HERMES_BACKUP_SIDECAR_PORT  (default 19001)
  HERMES_BACKUP_DIR           (default ~/.hermes/backups)
"""

import atexit
import datetime
import hmac
import json
import os
import re
import secrets
import shutil
import subprocess
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

SERVICE = "backup-download-sidecar"
VERSION = "0.1.0"

PORT = int(os.environ.get("HERMES_BACKUP_SIDECAR_PORT", "19001"))
PID_FILE = Path(__file__).resolve().parent / ".sidecar.pid"
BACKUP_DIR = Path(
    os.environ.get(
        "HERMES_BACKUP_DIR",
        str(Path.home() / ".hermes" / "backups"),
    )
).resolve()

TOKEN = secrets.token_urlsafe(24)

FILE_RE = re.compile(r"^hermes-backup-(full|quick)-\d{8}T\d{6}Z\.zip$")
CHUNK_MAX = 448 * 1024  # keep proxied responses well under the 512 KB cap
MAX_REQUEST_BODY = 64 * 1024
BACKUP_TIMEOUT = 3600  # seconds
LIST_LIMIT = 10

_LOCK = threading.Lock()
_STATE = {
    "state": "idle",  # idle | running | done | error
    "kind": None,  # "full" | "quick"
    "file": None,  # filename inside BACKUP_DIR
    "size": 0,
    "error": None,
    "started_at": None,
    "finished_at": None,
}


def _remove_pid_file() -> None:
    try:
        PID_FILE.unlink(missing_ok=True)
    except OSError:
        pass


atexit.register(_remove_pid_file)


def _utc_now() -> str:
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")


def _token_ok(supplied):
    if not isinstance(supplied, str) or not supplied:
        return False
    return hmac.compare_digest(
        supplied.encode("utf-8", "replace"), TOKEN.encode("ascii")
    )


def _run_backup(kind: str) -> None:
    """Thread target: run `hermes backup` and record the result.

    Full mode writes a zip straight to BACKUP_DIR. Quick mode (`-q`) does NOT
    produce a zip — it creates a state snapshot under ~/.hermes/state-snapshots/
    and ignores `-o` — so we zip that snapshot into BACKUP_DIR ourselves.
    """
    with _LOCK:
        _STATE.update(
            state="running",
            kind=kind,
            file=None,
            size=0,
            error=None,
            started_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),
            finished_at=None,
        )
    hermes_bin = shutil.which("hermes") or "/usr/bin/hermes"
    out_path = BACKUP_DIR / f"hermes-backup-{kind}-{_utc_now()}.zip"
    try:
        if kind == "quick":
            result = subprocess.run(
                [hermes_bin, "backup", "-q"],
                capture_output=True,
                timeout=BACKUP_TIMEOUT,
            )
            output = ((result.stdout or b"") + (result.stderr or b"")).decode(
                "utf-8", "replace"
            )
            match = re.search(r"State snapshot created:\s*([A-Za-z0-9._-]+)", output)
            snapshot_name = match.group(1) if match else None
            snapshots_dir = Path.home() / ".hermes" / "state-snapshots"
            snapshot_dir = snapshots_dir / snapshot_name if snapshot_name else None
            if (
                result.returncode == 0
                and snapshot_dir is not None
                and snapshot_dir.is_dir()
            ):
                shutil.make_archive(
                    str(out_path.with_suffix("")),
                    "zip",
                    root_dir=str(snapshots_dir),
                    base_dir=snapshot_dir.name,
                )
                if out_path.is_file():
                    with _LOCK:
                        _STATE.update(
                            state="done",
                            file=out_path.name,
                            size=out_path.stat().st_size,
                            finished_at=datetime.datetime.now(
                                datetime.timezone.utc
                            ).isoformat(),
                        )
                    return
            tail = output.strip()[-400:] or f"hermes backup exited {result.returncode}"
            with _LOCK:
                _STATE.update(
                    state="error",
                    error=tail,
                    finished_at=datetime.datetime.now(
                        datetime.timezone.utc
                    ).isoformat(),
                )
            return

        cmd = [hermes_bin, "backup", "-o", str(out_path)]
        result = subprocess.run(cmd, capture_output=True, timeout=BACKUP_TIMEOUT)
        if result.returncode == 0 and out_path.is_file():
            with _LOCK:
                _STATE.update(
                    state="done",
                    file=out_path.name,
                    size=out_path.stat().st_size,
                    finished_at=datetime.datetime.now(
                        datetime.timezone.utc
                    ).isoformat(),
                )
        else:
            tail = (result.stderr or result.stdout or b"").decode("utf-8", "replace")[
                -400:
            ]
            with _LOCK:
                _STATE.update(
                    state="error",
                    error=(tail.strip() or f"hermes backup exited {result.returncode}"),
                    finished_at=datetime.datetime.now(
                        datetime.timezone.utc
                    ).isoformat(),
                )
    except subprocess.TimeoutExpired:
        with _LOCK:
            _STATE.update(state="error", error="backup timed out")
    except Exception as exc:  # noqa: BLE001 — surface any failure to the UI
        with _LOCK:
            _STATE.update(state="error", error=str(exc))


def _state_snapshot() -> dict:
    with _LOCK:
        return dict(_STATE)


def _list_backups() -> list:
    items = []
    for path in BACKUP_DIR.iterdir():
        if not path.is_file():
            continue
        m = FILE_RE.match(path.name)
        if not m:
            continue
        try:
            st = path.stat()
        except OSError:
            continue
        items.append(
            {
                "file": path.name,
                "kind": m.group(1),
                "size": st.st_size,
                "created_at": datetime.datetime.fromtimestamp(
                    st.st_mtime, datetime.timezone.utc
                ).isoformat(),
            }
        )
    items.sort(key=lambda it: it["created_at"], reverse=True)
    return items[:LIST_LIMIT]


def _human_size(num: int) -> str:
    size = float(num)
    for unit in ("B", "KB", "MB", "GB"):
        if size < 1024 or unit == "GB":
            return f"{int(size)} B" if unit == "B" else f"{size:.1f} {unit}"
        size /= 1024
    return f"{size:.1f} GB"


class Handler(BaseHTTPRequestHandler):
    server_version = f"BackupDownloadSidecar/{VERSION}"
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):  # keep logs off stdout; go to stderr
        sys.stderr.write(
            f"[{datetime.datetime.now(datetime.timezone.utc).isoformat()}] "
            + (fmt % args)
            + "\n"
        )

    # ── helpers ────────────────────────────────────────────────────────────
    def _send_json(self, status: int, obj: dict) -> None:
        body = json.dumps(obj).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        try:
            self.wfile.write(body)
        except OSError:
            pass

    def _send_bytes(self, status: int, data: bytes, content_type: str) -> None:
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        try:
            self.wfile.write(data)
        except OSError:
            pass

    def _require_token(self) -> bool:
        supplied = self.headers.get("X-Backup-Download-Token")
        if not _token_ok(supplied):
            self._send_json(401, {"ok": False, "error": "invalid or missing token"})
            return False
        return True

    def _read_json_body(self) -> dict:
        raw_len = self.headers.get("Content-Length", "0")
        try:
            length = int(raw_len)
        except ValueError:
            return {}
        if length <= 0 or length > MAX_REQUEST_BODY:
            return {}
        try:
            data = json.loads(self.rfile.read(length).decode("utf-8", "replace"))
        except (json.JSONDecodeError, UnicodeDecodeError):
            return {}
        return data if isinstance(data, dict) else {}

    # ── GET ────────────────────────────────────────────────────────────────
    def do_GET(self):
        path = self.path.split("?", 1)[0]
        parts = [p for p in path.split("/") if p]

        if len(parts) == 1 and parts[0] == "health":
            self._send_json(
                200,
                {
                    "ok": True,
                    "service": SERVICE,
                    "version": VERSION,
                    "token": TOKEN,
                    "backups_dir": str(BACKUP_DIR),
                },
            )
            return

        if len(parts) == 1 and parts[0] == "status":
            if not self._require_token():
                return
            self._send_json(200, _state_snapshot())
            return

        if len(parts) == 1 and parts[0] == "list":
            if not self._require_token():
                return
            self._send_json(200, {"ok": True, "backups": _list_backups()})
            return

        if len(parts) == 5 and parts[0] == "chunk":
            _, req_token, filename, offset_raw, length_raw = parts
            if not _token_ok(req_token):
                self._send_json(401, {"ok": False, "error": "invalid or missing token"})
                return
            if not FILE_RE.match(filename):
                self._send_json(400, {"ok": False, "error": "invalid filename"})
                return
            try:
                offset = int(offset_raw)
                length = int(length_raw)
            except ValueError:
                self._send_json(400, {"ok": False, "error": "invalid offset/length"})
                return
            file_path = (BACKUP_DIR / filename).resolve()
            if file_path.parent != BACKUP_DIR or not file_path.is_file():
                self._send_json(404, {"ok": False, "error": "backup not found"})
                return
            file_size = file_path.stat().st_size
            if (
                offset < 0
                or length <= 0
                or length > CHUNK_MAX
                or offset + length > file_size
            ):
                self._send_json(416, {"ok": False, "error": "chunk out of range"})
                return
            try:
                with file_path.open("rb") as fh:
                    fh.seek(offset)
                    data = fh.read(length)
            except OSError as exc:
                self._send_json(500, {"ok": False, "error": str(exc)})
                return
            self._send_bytes(200, data, "application/octet-stream")
            return

        if len(parts) == 3 and parts[0] == "download":
            _, req_token, filename = parts
            if not _token_ok(req_token):
                self._send_json(401, {"ok": False, "error": "invalid or missing token"})
                return
            if not FILE_RE.match(filename):
                self._send_json(400, {"ok": False, "error": "invalid filename"})
                return
            file_path = (BACKUP_DIR / filename).resolve()
            if file_path.parent != BACKUP_DIR or not file_path.is_file():
                self._send_json(404, {"ok": False, "error": "backup not found"})
                return
            self.send_response(200)
            self.send_header("Content-Type", "application/zip")
            self.send_header(
                "Content-Disposition", f'attachment; filename="{filename}"'
            )
            self.send_header("Content-Length", str(file_path.stat().st_size))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            try:
                with file_path.open("rb") as fh:
                    while True:
                        chunk = fh.read(256 * 1024)
                        if not chunk:
                            break
                        self.wfile.write(chunk)
            except OSError:
                pass
            return

        self._send_json(404, {"ok": False, "error": "not found"})

    # ── POST ───────────────────────────────────────────────────────────────
    def do_POST(self):
        path = self.path.split("?", 1)[0]
        parts = [p for p in path.split("/") if p]

        if len(parts) == 1 and parts[0] == "backup":
            if not self._require_token():
                return
            with _LOCK:
                if _STATE["state"] == "running":
                    self._send_json(
                        409, {"ok": False, "error": "backup already running"}
                    )
                    return
            body = self._read_json_body()
            quick = bool(body.get("quick", False))
            kind = "quick" if quick else "full"
            BACKUP_DIR.mkdir(parents=True, exist_ok=True)
            thread = threading.Thread(
                target=_run_backup, args=(kind,), daemon=True, name="hermes-backup"
            )
            thread.start()
            self._send_json(202, {"ok": True, "state": "running", "kind": kind})
            return

        self._send_json(404, {"ok": False, "error": "not found"})


def main() -> int:
    BACKUP_DIR.mkdir(parents=True, exist_ok=True)
    try:
        httpd = ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
        httpd.daemon_threads = True
    except OSError as exc:
        sys.stderr.write(
            f"{SERVICE}: cannot bind 127.0.0.1:{PORT} ({exc}). "
            f"Is another sidecar running? Try HERMES_BACKUP_SIDECAR_PORT.\n"
        )
        return 1
    try:
        PID_FILE.write_text(str(os.getpid()), encoding="ascii")
    except OSError as exc:
        sys.stderr.write(f"{SERVICE}: cannot write pid file ({exc})\n")
        httpd.server_close()
        return 1
    sys.stderr.write(
        f"{SERVICE} v{VERSION} listening on http://127.0.0.1:{PORT} "
        f"(backups: {BACKUP_DIR})\n"
    )
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        httpd.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
