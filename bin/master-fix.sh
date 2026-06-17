#!/bin/sh
# Master fix script - re-applies all proven lint fixes
set -e
cd "$(dirname "$0")/.."

echo "=== Step 1: Fix * / pattern ==="
find addons/pro/includes/tools/ -name "*.php" -not -path "*/crm/*" -not -path "*/financial-planning/*" -not -path "*/site-creator-toolkit/*" -exec sed -i '/[[:space:]]*\* \/$/d' {} +

echo "=== Step 2: Expand short ternaries ==="
php bin/expand-ternaries.php

echo "=== Step 3: Run batch fixers ==="
php bin/fix-lint-batch-2.php
php bin/fix-lint-batch-3.php
php bin/fix-lint-batch-4.php
php bin/fix-lint-batch-5.php
php bin/fix-lint-final.php

echo "=== Step 4: Generate phpcs output ==="
php -d memory_limit=512M vendor/bin/phpcs --error-severity=1 --warning-severity=8 -s --report=emacs addons/pro/includes/tools/ --ignore="*/crm/*,*/financial-planning/*,*/site-creator-toolkit/*" > /tmp/phpcs-errors.txt 2>&1

echo "=== Step 5: Add docblocks ==="
grep "FunctionComment.Missing" /tmp/phpcs-errors.txt | php bin/add-docblocks-stdin.php

echo "=== Step 6: Run phpcbf ==="
php -d memory_limit=512M vendor/bin/phpcbf --error-severity=1 --warning-severity=8 addons/pro/includes/tools/ --ignore="*/crm/*,*/financial-planning/*,*/site-creator-toolkit/*"

echo "=== Step 7: Final check ==="
php -d memory_limit=512M vendor/bin/phpcs --error-severity=1 --warning-severity=8 --report=summary addons/pro/includes/tools/ --ignore="*/crm/*,*/financial-planning/*,*/site-creator-toolkit/*" 2>&1 | tail -5

echo "=== Done ==="
