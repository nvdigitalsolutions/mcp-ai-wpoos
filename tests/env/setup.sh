#!/bin/bash
# Studio environment setup for WP-CLI tests.
# Run this before executing test scripts.
#
# Usage: bash tests/env/setup.sh

set -e

echo "=== NV oOS Test Environment Setup ==="

# Studio WASM tmpdir workaround.
if [ -z "$TMPDIR" ]; then
	export TMPDIR=/tmp
	mkdir -p /tmp
	echo "TMPDIR set to $TMPDIR"
fi

# Ensure site is running.
echo "Starting site..."
studio site start --skip-browser

# Enable debug logging.
echo "Enabling debug logging..."
studio wp --user=admin option update wp_mcp_ai_settings '{"enable_logging":true}' --format=json

# Verify plugin is active.
echo "Verifying plugin status..."
studio wp --user=admin plugin status mcp-ai-wpoos

echo ""
echo "=== Environment ready ==="
echo "Run tests with:"
echo "  studio wp --user=admin eval-file tests/wp-cli-smoke.php"
echo "  studio wp --user=admin eval-file tests/fixtures/create.php"
echo "  studio wp --user=admin eval-file tests/regression/bugs.php"
echo "  studio wp --user=admin eval-file tests/fixtures/delete.php"
