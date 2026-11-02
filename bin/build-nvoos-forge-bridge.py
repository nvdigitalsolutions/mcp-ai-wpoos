"""
Build the wp.org submission ZIP for the NV oOS Forge Bridge plugin.

Usage:
    python bin/build-nvoos-forge-bridge.py [--version 0.1.0]

Output: build/nvoos-forge-bridge-v<version>.zip
Shape: top-level folder nvoos-forge-bridge/ (readme.txt at the folder root),
forward-slash entry separators (the backend upgrader rejects backslash
entries), exclusions synced with plugins/nvoos-forge-bridge/.distignore and
the `plugin-check` staging list in .github/workflows/phpunit-forge-bridge.yml.
"""

import argparse
import pathlib
import sys
import zipfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / "plugins" / "nvoos-forge-bridge"
BUILD = ROOT / "build"

# Must stay in sync with .distignore and the CI staging list.
EXCLUDES = {
    ".git",
    ".gitignore",
    ".github",
    ".distignore",
    "node_modules",
    "composer.json",
    "composer.lock",
    "phpcs.xml.dist",
    "phpunit.xml.dist",
    ".phpunit.cache",
    ".phpunit.result.cache",
    "tests",
    "vendor",
    "README.md",
    "CHANGELOG.md",
    "CONTRIBUTING.md",
    ".wordpress-org",
    ".DS_Store",
    "Thumbs.db",
}

EXCLUDE_GLOBS = ("*.log", "WPORG-REVIEW-*.md")


def excluded(rel: pathlib.PurePosixPath) -> bool:
    parts = rel.parts
    if not parts:
        return True
    if parts[0] in EXCLUDES:
        return True
    name = parts[-1]
    for glob in EXCLUDE_GLOBS:
        if pathlib.PurePosixPath(name).match(glob):
            return True
    return False


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--version", default="0.1.0")
    args = parser.parse_args()
    version = args.version
    if not version or not version.replace(".", "").isdigit():
        print(f"Invalid version: {version!r}", file=sys.stderr)
        return 1

    if not (SRC / "readme.txt").is_file():
        print(f"readme.txt missing from {SRC}", file=sys.stderr)
        return 1

    BUILD.mkdir(exist_ok=True)
    out = BUILD / f"nvoos-forge-bridge-v{version}.zip"

    files = []
    for p in sorted(SRC.rglob("*")):
        if not p.is_file():
            continue
        rel = pathlib.PurePosixPath(p.relative_to(SRC).as_posix())
        if excluded(rel):
            continue
        files.append((rel, p))

    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
        for rel, p in files:
            zf.write(p, f"nvoos-forge-bridge/{rel}")

    # Verify the shape.
    with zipfile.ZipFile(out) as zf:
        names = zf.namelist()
        assert f"nvoos-forge-bridge/readme.txt" in names, "readme.txt not at folder root"
        assert all(n.startswith("nvoos-forge-bridge/") for n in names), "entry outside top-level folder"
        assert all("/" in n or n.endswith("/") for n in names if "\\" in n) is False or all(
            "\\" not in n for n in names
        ), "backslash separators in zip"
        assert not any("vendor/" in n or "tests/" in n or ".wordpress-org" in n for n in names), "dev files leaked"

    print(f"Wrote {out} ({len(files)} files)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
