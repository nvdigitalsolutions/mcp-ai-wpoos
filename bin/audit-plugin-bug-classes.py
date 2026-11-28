#!/usr/bin/env python3
"""Static audit for the recurring WordPress plugin bug classes.

Companion to `.agents/skills/mcp-ai-wpoos-wporg-submission/reference/
recurring-bug-classes.md`. This is a *signal* scanner, not a verdict: every
REVIEW line needs a human look. It catches the classes that Plugin Check,
PHPCS and a green PHPUnit suite cannot see because they depend on runtime
state (current user, redirects, decoded JSON).

Usage:
    python bin/audit-plugin-bug-classes.py addons/docs-hub
    python bin/audit-plugin-bug-classes.py addons/* plugins/*
    python bin/audit-plugin-bug-classes.py --json addons/nvoos-design-system

Exit code: 0 always (it is a report). CI can grep the totals line.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

SKIP_DIRS = {
    "vendor",
    "node_modules",
    "tests",
    "test",
    "build",
    "dist",
    "src",
    "blueprints",
    ".git",
    ".wordpress-org",
    "languages",
    "assets",
}
SKIP_FILES = {"uninstall.php"}

COMMENT_START = ("*", "//", "/*", "#")


def php_files(root: Path):
    """Yield PHP files that are part of the shipped runtime."""
    for path in root.rglob("*.php"):
        parts = set(path.relative_to(root).parts)
        if parts & SKIP_DIRS:
            continue
        if path.name in SKIP_FILES:
            continue
        yield path


def code_lines(path: Path):
    """Yield (lineno, stripped_line) for lines that are not comments."""
    try:
        text = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return
    for number, raw in enumerate(text.splitlines(), 1):
        stripped = raw.strip()
        if not stripped or stripped.startswith(COMMENT_START):
            continue
        yield number, stripped


def first_hits(root: Path, pattern: re.Pattern, limit: int = 3):
    """Return up to ``limit`` (relative path, line, snippet) hits."""
    hits = []
    for path in php_files(root):
        for number, line in code_lines(path):
            if pattern.search(line):
                rel = path.relative_to(root).as_posix()
                hits.append((rel, number, line[:110]))
                if len(hits) >= limit:
                    return hits
    return hits


def files_with(root: Path, pattern: re.Pattern):
    """Return relative paths whose runtime code matches ``pattern``."""
    found = []
    for path in php_files(root):
        for _, line in code_lines(path):
            if pattern.search(line):
                found.append(path.relative_to(root).as_posix())
                break
    return found


def check_kses(root: Path):
    """CPT / post writes that could be stripped by KSES.

    Only a real risk when the stored content legitimately contains markup
    (``<style>``, VML, doctype). Pre-sanitising with ``wp_kses_post()`` or
    ``sanitize_textarea_field()`` means there is nothing left to preserve, so
    those files are reported as OK with a note.
    """
    writers = files_with(root, re.compile(r"\bwp_(insert|update)_post\s*\("))
    if not writers:
        return "OK", []
    unsafe = []
    for rel in writers:
        text = (root / rel).read_text(encoding="utf-8", errors="replace")
        if "kses_remove_filters" in text:
            continue
        if "wp_kses_post" in text or "sanitize_textarea_field" in text or "sanitize_text_field" in text:
            continue
        # REST routes declare sanitize_callback on their args; WordPress applies
        # it during dispatch, so the handler receives clean values.
        if "sanitize_callback" in text:
            continue
        # Activation-time page creation writes a literal shortcode/block string
        # (no user content), so there is nothing for KSES to strip.
        writes = re.findall(r"'post_content'\s*=>\s*(.+)", text)
        if writes and all(re.match(r"^['\"]", w.strip()) for w in writes):
            continue
        unsafe.append((rel, 0, "writes post content with no KSES lift and no pre-sanitising helper"))
    if unsafe:
        return "REVIEW", unsafe
    return "OK", [("", 0, f"{len(writers)} writer(s): lifted or pre-sanitised")]


def check_notices(root: Path):
    """admin-post redirects that would drop add_settings_error() messages."""
    redirects = set(files_with(root, re.compile(r"\bwp_safe_redirect\s*\(")))
    if not redirects:
        return "OK", []
    problems = []
    for rel in sorted(redirects):
        text = (root / rel).read_text(encoding="utf-8", errors="replace")
        if "add_settings_error" not in text:
            continue
        carries = (
            "set_transient" in text
            or "settings_errors" in text
            or "add_query_arg" in text  # query-flag pattern: handler must render it
        )
        if not carries:
            problems.append((rel, 0, "redirects after add_settings_error() with no transient/query carrier"))
    return ("REVIEW", problems) if problems else ("OK", [("", 0, "redirects carry their notices")])


def check_numeric(root: Path):
    """Arithmetic and float casts in the same file as json_decode().

    ``0 + $x`` is a fatal ``TypeError`` on an array; ``(float) $array``
    silently yields 1.0. ``(int)`` is deliberately excluded: it is benign
    for this class and generates noise.
    """
    decoders = files_with(root, re.compile(r"\bjson_decode\s*\("))
    pattern = re.compile(r"0 \+ \$|\(float\)\s*\$")
    problems = []
    for rel in decoders:
        for number, line in code_lines(root / rel):
            if pattern.search(line):
                problems.append((rel, number, line))
                break
    if not decoders:
        return "OK", []
    return ("REVIEW", problems) if problems else ("OK", [("", 0, f"{len(decoders)} decoder file(s), no risky casts")])


def check_inline_handlers(root: Path):
    """Inline on* handlers (enqueue/escaping review)."""
    hits = first_hits(root, re.compile(r"\son(?:submit|click|change|load|error|focus|blur)\s*=\s*[\"']", re.I))
    return ("REVIEW", hits) if hits else ("OK", [])


SAFE_MARKUP_PATH = re.compile(
    r"(^|/)(emails?|templates?|exports?)/|renderer|template|brand-kit|email-|->render|\.html?$",
    re.I,
)


def check_raw_assets(root: Path):
    """Literal <style>/<script> in PHP.

    Legitimate inside email builders, printable-export builders and template
    renderers. A hit anywhere else means page output is bypassing the
    enqueue API.
    """
    hits = first_hits(root, re.compile(r"<style|<script", re.I), limit=20)
    if not hits:
        return "OK", []
    unexpected = [h for h in hits if not SAFE_MARKUP_PATH.search(h[0])]
    if unexpected:
        return "REVIEW", unexpected[:5]
    files = len({h[0] for h in hits})
    return "OK", [("", 0, f"{files} email/export builder(s) only")]


def check_unescaped_echo(root: Path):
    """echo of a variable without an escaping helper on the same line.

    Honours an explicit ``phpcs:ignore ... EscapeOutput`` annotation: a
    pre-escaped variable is a documented idiom, not a finding.
    """
    safe = re.compile(
        r"echo\s+(esc_|wp_kses|wp_json|intval|absint|\(int\)|\$this->)"
        r"|\?\s*'[^']*'\s*:\s*'[^']*'"  # ternary of literals
        r"|phpcs:ignore[^*]*EscapeOutput"  # documented pre-escaped value
    )
    risky = re.compile(r"\becho\s+\$")
    json_download = re.compile(r"Content-Type:\s*application/(json|design-tokens)")
    hits = []
    for path in php_files(root):
        text = path.read_text(encoding="utf-8", errors="replace")
        is_download = bool(json_download.search(text))
        for number, line in code_lines(path):
            if not risky.search(line) or safe.search(line):
                continue
            if is_download and re.search(r"echo\s+\$json\s*;", line):
                continue  # raw JSON download body — escaping would corrupt it
            hits.append((path.relative_to(root).as_posix(), number, line[:110]))
            if len(hits) >= 3:
                return "REVIEW", hits
    return "OK", []


CLASS_ONLY_TOP = re.compile(
    r"^(namespace|use|final class|abstract class|class|interface|trait)\b"
    r"|^[}];?$"
    r"|^<\?php$"
    r"|^\?>$"
)


def check_abspath(root: Path):
    """Missing direct-access guard on runtime PHP files.

    ``index.php`` directory stubs and class/interface-only files are
    excluded: they declare symbols only, so a direct request is harmless and
    the guard is a convention rather than a protection.
    """
    missing = []
    for path in php_files(root):
        if path.name == "index.php":
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        if "ABSPATH" in text or "WP_UNINSTALL_PLUGIN" in text:
            continue

        # Top-level statements are unindented; class members are not.
        top_level = [
            line.strip()
            for line in text.splitlines()
            if line.strip()
            and not line.startswith((" ", "\t"))
            and not line.strip().startswith(COMMENT_START)
        ]
        if top_level and all(CLASS_ONLY_TOP.match(line) for line in top_level):
            continue

        missing.append((path.relative_to(root).as_posix(), 0, "no ABSPATH guard"))

    return ("REVIEW", missing[:3]) if missing else ("OK", [])


def check_handler_checks(root: Path):
    """Every admin-post handler callback needs a capability and a nonce check.

    Counts **distinct callbacks**, not registrations: a legacy alias pointing
    at an already-guarded handler must not inflate the denominator.
    """
    callbacks = set()
    caps = 0
    nonces = 0
    for path in php_files(root):
        text = path.read_text(encoding="utf-8", errors="replace")
        for match in re.finditer(
            r"add_action\(\s*'admin_post_[a-z0-9_]+'\s*,\s*(?:array\(\s*[^,]+,\s*'([a-z0-9_]+)'|'([a-z0-9_]+)')",
            text,
        ):
            callbacks.add(match.group(1) or match.group(2))
        caps += len(re.findall(r"current_user_can\s*\(", text))
        nonces += len(re.findall(r"check_(admin|ajax)_referer\s*\(", text))
    if not callbacks:
        return "OK", []
    if caps < len(callbacks) or nonces < len(callbacks):
        return "REVIEW", [("", 0, f"{len(callbacks)} handler(s), {caps} capability checks, {nonces} nonce checks")]
    return "OK", [("", 0, f"{len(callbacks)} handler(s), {caps}/{nonces} checks")]


CHECKS = [
    ("1 kses-cpt", check_kses),
    ("2 redirect-notices", check_notices),
    ("3 numeric-untrusted", check_numeric),
    ("4 inline-handlers", check_inline_handlers),
    ("5 raw-style-script", check_raw_assets),
    ("6 unescaped-echo", check_unescaped_echo),
    ("7 abspath-guard", check_abspath),
    ("8 handler-guards", check_handler_checks),
]


def audit(root: Path):
    """Run every check and return a report dict."""
    rows = []
    for name, check in CHECKS:
        try:
            status, details = check(root)
        except Exception as exc:  # noqa: BLE001 - report, never crash the sweep
            status, details = "ERROR", [("", 0, str(exc))]
        rows.append({"check": name, "status": status, "details": details})
    return {
        "plugin": root.name,
        "path": root.as_posix(),
        "review_count": sum(1 for r in rows if r["status"] == "REVIEW"),
        "rows": rows,
    }


def print_report(report):
    """Human-readable per-plugin report."""
    print(f"\n== {report['plugin']}  ({report['path']})")
    for row in report["rows"]:
        mark = "OK    " if row["status"] == "OK" else "REVIEW"
        print(f"  {mark} {row['check']}")
        for rel, number, note in row["details"]:
            where = f"{rel}:{number}" if rel else "-"
            print(f"         {where}  {note}")


def main() -> int:
    """CLI entry point."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("paths", nargs="+", help="Plugin directories")
    parser.add_argument("--json", action="store_true", help="Emit JSON instead of text")
    args = parser.parse_args()

    reports = []
    for raw in args.paths:
        root = Path(raw)
        if not root.is_dir():
            continue
        reports.append(audit(root))

    if args.json:
        print(json.dumps(reports, indent=2))
        return 0

    for report in reports:
        print_report(report)

    total = sum(r["review_count"] for r in reports)
    print(f"\nTOTAL: {len(reports)} plugin(s), {total} check(s) needing review")
    return 0


if __name__ == "__main__":
    sys.exit(main())
