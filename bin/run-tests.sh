#!/bin/bash
#
# WordPress Test Runner Script
# Runs PHPUnit tests with WordPress test environment
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR/.."

# Set WordPress core directory
export WP_CORE_DIR="/tmp/wordpress"

# Check if WordPress core exists
if [ ! -f "$WP_CORE_DIR/wp-settings.php" ]; then
    echo "Error: WordPress core not found at $WP_CORE_DIR"
    echo "Please run the setup first."
    exit 1
fi

# Check if MySQL is running
if ! mysqladmin ping -h localhost --silent 2>/dev/null; then
    echo "Starting MySQL..."
    sudo service mysql start
fi

# Run PHPUnit with arguments
echo "Running PHPUnit tests..."
vendor/bin/phpunit "$@"
