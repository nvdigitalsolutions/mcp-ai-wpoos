#!/usr/bin/env bash
set -euo pipefail

need_phpcs=false
if ! command -v phpcs >/dev/null 2>&1; then
  need_phpcs=true
fi

need_phpunit=false
if ! command -v phpunit >/dev/null 2>&1; then
  need_phpunit=true
fi

if [ "${need_phpcs}" = false ] && [ "${need_phpunit}" = false ]; then
  exit 0
fi

if ! command -v composer >/dev/null 2>&1; then
  echo "Composer is required to install development tools but was not found." >&2
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
if [ "${need_phpcs}" = true ] && ! composer global show squizlabs/php_codesniffer >/dev/null 2>&1; then
  composer global require --no-interaction --no-progress squizlabs/php_codesniffer:^3.7 >/tmp/phpcs-install.log 2>&1 || {
    cat /tmp/phpcs-install.log >&2
    rm -f /tmp/phpcs-install.log
    exit 1
  }
  rm -f /tmp/phpcs-install.log
fi

# Install PHPUnit globally if it is not already present.
if [ "${need_phpunit}" = true ] && ! composer global show phpunit/phpunit >/dev/null 2>&1; then
  composer global require --no-interaction --no-progress phpunit/phpunit:^9.6 >/tmp/phpunit-install.log 2>&1 || {
    cat /tmp/phpunit-install.log >&2
    rm -f /tmp/phpunit-install.log
    exit 1
  }
  rm -f /tmp/phpunit-install.log
fi

# Link the executables into a directory that is already on PATH, if possible.
composer_bin_dir="$(composer global config bin-dir --absolute 2>/dev/null || true)"
if [ -n "${composer_bin_dir}" ]; then
  if [ -x "${composer_bin_dir}/phpcs" ]; then
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

  if [ -x "${composer_bin_dir}/phpunit" ]; then
    if command -v install >/dev/null 2>&1 && [ -w /usr/local/bin ]; then
      install -m 0755 "${composer_bin_dir}/phpunit" /usr/local/bin/phpunit
    elif command -v ln >/dev/null 2>&1 && [ -w /usr/local/bin ]; then
      ln -sf "${composer_bin_dir}/phpunit" /usr/local/bin/phpunit
    else
      case ":${PATH}:" in
        *:"${composer_bin_dir}":*) ;;
        *) echo "phpunit installed at ${composer_bin_dir}/phpunit. Consider adding ${composer_bin_dir} to your PATH." >&2 ;;
      esac
    fi
  fi
fi
