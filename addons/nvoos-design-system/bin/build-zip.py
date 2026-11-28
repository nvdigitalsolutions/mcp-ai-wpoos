#!/usr/bin/env python3
"""Build the distributable ZIP for NV oOS Design System.

Honours ``.distignore`` (development-only paths are excluded) and writes
forward-slash entry names, which WordPress.org requires. The archive root is
``nvoos-design-system/`` so the folder name matches the requested slug.

Usage:
    python bin/build-zip.py [--out dist]
"""

from __future__ import annotations

import argparse
import hashlib
import os
import shutil
import sys
import tempfile
import zipfile
from pathlib import Path

SLUG = "nvoos-design-system"
ROOT = Path(__file__).resolve().parent.parent
VERSION = None


def read_version() -> str:
    """Read the plugin version from the main file header."""
    main = (ROOT / f"{SLUG}.php").read_text(encoding="utf-8")
    for line in main.splitlines():
        if "Version:" in line and line.strip().startswith("*"):
            return line.split("Version:", 1)[1].strip()
    raise SystemExit("Could not read Version from the plugin header")


def excluded(path: Path) -> bool:
    """Return True when a path must stay out of the archive."""
    parts = path.relative_to(ROOT).parts
    head = parts[0]
    if head in {".git", ".github", ".wordpress-org", "bin", "node_modules", "tests", "vendor"}:
        return True
    if head in {".distignore", ".gitattributes", ".gitignore", "composer.json", "composer.lock"}:
        return True
    if head in {"phpcs.xml", "phpcs.xml.dist", "phpunit.xml.dist"}:
        return True
    if head in {".DS_Store", "NUL"} or path.name.endswith(".log"):
        return True
    return False


def stage(destination: Path) -> int:
    """Copy the shippable tree into ``destination``; return the file count."""
    count = 0
    for path in sorted(ROOT.rglob("*")):
        if not path.is_file():
            continue
        if excluded(path):
            continue
        target = destination / path.relative_to(ROOT)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, target)
        count += 1
    return count


def build(out_dir: Path) -> Path:
    """Create the ZIP and return its path."""
    global VERSION
    VERSION = read_version()
    zip_path = out_dir / f"{SLUG}-{VERSION}.zip"

    with tempfile.TemporaryDirectory() as tmp:
        staging = Path(tmp) / SLUG
        staging.mkdir(parents=True)
        count = stage(staging)

        if zip_path.exists():
            zip_path.unlink()

        out_dir.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
            for path in sorted(staging.rglob("*")):
                if not path.is_file():
                    continue
                arcname = path.relative_to(Path(tmp)).as_posix()
                if os.sep in arcname:
                    raise SystemExit(f"Backslash in archive name: {arcname}")
                zf.write(path, arcname)

    digest = hashlib.sha256(zip_path.read_bytes()).hexdigest()
    print(f"version:    {VERSION}")
    print(f"files:      {count}")
    print(f"zip:        {zip_path}")
    print(f"bytes:      {zip_path.stat().st_size}")
    print(f"sha256:     {digest}")
    return zip_path


def main() -> int:
    """CLI entry point."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", default=str(ROOT.parent.parent / "dist"), help="Output directory")
    args = parser.parse_args()
    build(Path(args.out))
    return 0


if __name__ == "__main__":
    sys.exit(main())
