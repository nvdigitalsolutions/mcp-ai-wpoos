#!/bin/sh
#
# Run the WordPress Plugin Check (PCP) gate against a ZIP-shaped
# nvoos-design-system tree, mirroring the CI plugin-check job for SPA addons.
#
# Stages the addon with its .distignore exclusions, provisions a scratch MySQL
# DB, downloads fresh WP core + plugin-check into a one-off container, runs
# `wp plugin check nvoos-design-system`, and gates on ERROR-severity findings
# minus the allowlisted OffloadedContent code (see
# .agents/skills/mcp-ai-wpoos-wporg-submission/SKILL.md -> "Known PCP findings
# triage").
#
# Usage:
#   bin/run-design-system-plugin-check.sh [repo-root]
#
# Env overrides:
#   DB_CONTAINER  name of the MySQL container (default: oos-wp-db)
#   DB_ROOT_PASS  MySQL root password (default: wordpress)
#   DB_NAME       scratch DB to create/use (default: wordpress_nds_chk)
#   NETWORK       docker network (default: oos-wp_default)
#   STAGE_DIR     host staging dir (default: /f/GITHUB/tmp-nds-pcp)
#
# Requirements: docker, the QA stack DB container, and network access for
# `wp core download` / `wp plugin install plugin-check` inside the container.
#
# Exit: 0 when PCP exits 0 AND there are no blocking ERROR findings.
#       The report is copied to $STAGE_DIR/pcp-report.json.

set -u

export MSYS_NO_PATHCONV=1

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT_DIR=${1:-$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)}
DB_CONTAINER=${DB_CONTAINER:-oos-wp-db}
DB_ROOT_PASS=${DB_ROOT_PASS:-wordpress}
DB_NAME=${DB_NAME:-wordpress_nds_chk}
NETWORK=${NETWORK:-oos-wp_default}
STAGE_DIR=${STAGE_DIR:-/f/GITHUB/tmp-nds-pcp}
SLUG=nvoos-design-system
PLUGIN_SRC="$ROOT_DIR/addons/$SLUG"

# Convert a MSYS/Git-Bash path to a Windows path docker can mount.
stage_win_path() {
	case "$(uname -s 2>/dev/null)" in
		MINGW*|MSYS*|CYGWIN*)
			if command -v cygpath >/dev/null 2>&1; then
				cygpath -m "$STAGE_DIR"
			else
				echo "$STAGE_DIR" | sed 's|^/\([a-zA-Z]\)/|\1:/|'
			fi
			;;
		*)
			echo "$STAGE_DIR"
			;;
	esac
}

if [ ! -d "$PLUGIN_SRC" ]; then
	echo "ERROR: design-system source not found at $PLUGIN_SRC" >&2
	exit 2
fi

command -v docker >/dev/null 2>&1 || { echo "ERROR: docker not found in PATH" >&2; exit 2; }
docker info >/dev/null 2>&1 || { echo "ERROR: docker daemon not running" >&2; exit 2; }
docker exec "$DB_CONTAINER" true >/dev/null 2>&1 || {
	echo "ERROR: DB container '$DB_CONTAINER' not reachable (override with DB_CONTAINER=...)" >&2
	exit 2
}

echo "[1/4] Provisioning scratch DB '$DB_NAME'..."
docker exec -i "$DB_CONTAINER" sh -c "mysql -uroot -p$DB_ROOT_PASS" 2>/dev/null <<SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4;
GRANT ALL PRIVILEGES ON $DB_NAME.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
SQL

echo "[2/4] Staging ZIP-shaped tree from $PLUGIN_SRC..."
rm -rf "$STAGE_DIR/$SLUG"
mkdir -p "$STAGE_DIR/$SLUG"
tar -C "$PLUGIN_SRC" -cf - \
	--exclude='./.git' --exclude='./.github' --exclude='./.gitattributes' \
	--exclude='./.gitignore' \
	--exclude='./.distignore' --exclude='./tests' --exclude='./bin' \
	--exclude='./node_modules' --exclude='./vendor' \
	--exclude='./composer.json' --exclude='./composer.lock' \
	--exclude='./phpunit.xml.dist' --exclude='./phpcs.xml.dist' \
	--exclude='./phpcs.xml' --exclude='./.wordpress-org' \
	--exclude='./SCREENSHOTS.md' --exclude='./*.log' \
	--exclude='./languages/.gitkeep' \
	--exclude='./.DS_Store' . | tar -C "$STAGE_DIR/$SLUG" -xf -

if [ ! -f "$STAGE_DIR/$SLUG/readme.txt" ] || [ ! -f "$STAGE_DIR/$SLUG/$SLUG.php" ]; then
	echo "ERROR: staging failed — readme.txt or main file missing" >&2
	exit 2
fi
echo "      staged $(find "$STAGE_DIR/$SLUG" -type f | wc -l | tr -d ' ') files"

# Heredoc => LF line endings, no host-shell quoting trap. The bind mount is
# read-only, so copy into the container before executing.
cat > "$STAGE_DIR/run-pcp.sh" <<'RUNNER'
#!/bin/sh
WP=/usr/local/bin/wp
SLUG=nvoos-design-system
php -d memory_limit=512M $WP core download --version=latest --skip-content --path=/tmp/wp --quiet
php -d memory_limit=512M $WP config create --dbname="$PCP_DB_NAME" --dbuser=wordpress --dbpass=wordpress --dbhost="$PCP_DB_CONTAINER" --path=/tmp/wp --quiet
php -d memory_limit=512M $WP core install --url=http://127.0.0.1 --title=PCP --admin_user=admin --admin_password=admin --admin_email=a@a.com --path=/tmp/wp --quiet 2>/dev/null || true
php -d memory_limit=512M $WP plugin install plugin-check --activate --path=/tmp/wp --quiet
cp -r /plugin-src/$SLUG /tmp/wp/wp-content/plugins/$SLUG
php -d memory_limit=1G $WP plugin check $SLUG --path=/tmp/wp --format=json --severity=5 > /tmp/pcp-report.json 2>/tmp/pcp-stderr.log
echo "PCP_EXIT:$?"
# PCP output is a chunked stream of FILE: headers + JSON arrays and is NOT a
# single JSON document; grep-based gate. "type":"ERROR" has NO space in PCP's
# output. Allowlist: PluginCheck.CodeAnalysis.Offloading.OffloadedContent.
BLOCKING=$(grep -o '"type":"ERROR","code":"[^"]*"' /tmp/pcp-report.json | grep -vc 'OffloadedContent' || true)
echo "BLOCKING_ERRORS:$BLOCKING"
echo "--- FINDING CODES ---"
grep -o '"code":"[^"]*"' /tmp/pcp-report.json | sort | uniq -c
echo "--- STDERR TAIL ---"
tail -5 /tmp/pcp-stderr.log
cp /tmp/pcp-report.json /plugin-src/pcp-report.json
echo "REPORT_SAVED"
RUNNER

echo "[3/4] Running wp plugin check in a one-off container (downloads WP + plugin-check, takes minutes)..."
OUTPUT=$(docker run --rm --network "$NETWORK" \
	-v "$(stage_win_path)":/plugin-src \
	-e WORDPRESS_DB_HOST="$DB_CONTAINER" -e WORDPRESS_DB_NAME="$DB_NAME" \
	-e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress \
	-e PCP_DB_NAME="$DB_NAME" -e PCP_DB_CONTAINER="$DB_CONTAINER" \
	wordpress:cli-php8.2 sh -c 'cp /plugin-src/run-pcp.sh /tmp/run-pcp.sh && sh /tmp/run-pcp.sh')
RC=$?
echo "$OUTPUT"

echo "[4/4] Gate..."
PCP_EXIT=$(echo "$OUTPUT" | sed -n 's/^PCP_EXIT://p')
BLOCKING=$(echo "$OUTPUT" | sed -n 's/^BLOCKING_ERRORS://p')
echo "      report: $STAGE_DIR/pcp-report.json"

if [ "$RC" -ne 0 ]; then
	echo "FAIL: container run exited $RC" >&2
	exit 1
fi
if [ "$PCP_EXIT" != "0" ]; then
	echo "FAIL: wp plugin check exited $PCP_EXIT" >&2
	exit 1
fi
if [ -z "$BLOCKING" ] || [ "$BLOCKING" != "0" ]; then
	echo "FAIL: $BLOCKING blocking ERROR finding(s) — see the codes list above" >&2
	exit 1
fi
echo "PASS: 0 blocking errors (allowlisted OffloadedContent rows are expected)."
exit 0
