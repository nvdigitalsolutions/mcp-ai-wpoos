#!/usr/bin/env bash
set -euo pipefail

if command -v phpcs >/dev/null 2>&1; then
  exit 0
fi

if ! command -v composer >/dev/null 2>&1; then
  echo "Composer is required to install PHP_CodeSniffer (phpcs) but was not found." >&2
  exit 0
fi

# Determine the Composer home so we can locate the global vendor bin directory.
if [ -n "${COMPOSER_HOME:-}" ]; then
  composer_home="${COMPOSER_HOME}"
else
  if [ -d "${HOME}/.config/composer" ]; then
    composer_home="${HOME}/.config/composer"
  else
    composer_home="${HOME}/.composer"
  fi
  export COMPOSER_HOME="${composer_home}"
fi

# Install PHP_CodeSniffer globally if it is not already present.
if ! composer global show squizlabs/php_codesniffer >/dev/null 2>&1; then
  composer global require --no-interaction --no-progress squizlabs/php_codesniffer:^3.7 >/tmp/phpcs-install.log 2>&1 || {
    cat /tmp/phpcs-install.log >&2
    rm -f /tmp/phpcs-install.log
    exit 1
  }
  rm -f /tmp/phpcs-install.log
fi

# Link the executable into a directory that is already on PATH, if possible.
composer_bin_dir="$(composer global config bin-dir --absolute 2>/dev/null || true)"
if [ -n "${composer_bin_dir}" ] && [ -x "${composer_bin_dir}/phpcs" ]; then
  if command -v install >/dev/null 2>&1 && [ -w /usr/local/bin ]; then
    install -m 0755 "${composer_bin_dir}/phpcs" /usr/local/bin/phpcs
  elif command -v ln >/dev/null 2>&1 && [ -w /usr/local/bin ]; then
    ln -sf "${composer_bin_dir}/phpcs" /usr/local/bin/phpcs
  else
    case ":${PATH}:" in
      *:"${composer_bin_dir}":*) ;;
      *) echo "phpcs installed at ${composer_bin_dir}/phpcs. Consider adding ${composer_bin_dir} to your PATH." >&2 ;;
    esac
  fi
fi
