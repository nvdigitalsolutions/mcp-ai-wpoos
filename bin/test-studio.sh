#!/usr/bin/env bash
# ============================================================
#  WordPress Studio Test Runner (macOS/Linux bash)
#
#  Runs the NV oOS test suite against a WordPress Studio site.
#
#  Usage:
#    bin/test-studio.sh mysite          Run all tests
#    bin/test-studio.sh mysite --filter "test_logger"
#
#  Prerequisites:
#    - WordPress Studio installed and site "mysite" created
#    - composer install (run once)
#    - SQLite Database Integration plugin in the Studio site
#      (copy from tests/fixtures/sqlite-database-integration/)
# ============================================================
set -euo pipefail

SITE_SLUG="${1:-}"
if [ -z "$SITE_SLUG" ]; then
    echo "Usage: bin/test-studio.sh <site-slug> [phpunit-args...]"
    echo ""
    echo "Example: bin/test-studio.sh mysite --filter \"test_logger\""
    exit 1
fi

shift
export WP_STUDIO_SITE_SLUG="$SITE_SLUG"
echo "Running tests against WordPress Studio site: $WP_STUDIO_SITE_SLUG"
echo ""

vendor/bin/phpunit "$@"
