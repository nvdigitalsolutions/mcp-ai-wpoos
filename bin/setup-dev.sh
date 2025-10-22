#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

if ! command -v composer >/dev/null 2>&1; then
  echo "Composer is required to install development dependencies." >&2
  exit 1
fi

composer install --no-interaction --prefer-dist --no-progress

echo "\nDevelopment tooling installed. Available composer scripts include:\n"
composer run-script --list | sed 's/^/  /'
