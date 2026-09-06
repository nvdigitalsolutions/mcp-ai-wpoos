#!/bin/sh
# port-cluster.sh — driver for the ecosystem port cluster loop.
#
# Companion to docs/project/plans/ecosystem-port-cluster-loop.md. Automates
# the mechanical steps of the loop; the porting judgment stays with the agent.
#
# Commands:
#   port-cluster.sh status                      Print remaining clusters + open port PRs.
#   port-cluster.sh new <wave> <slug>           Branch off a fresh origin/alpha-working.
#   port-cluster.sh gates [--only <php-file>]   phpcs + both docker PHPUnit matrices.
#   port-cluster.sh ship <pr-title>             Push + open the PR (stage/commit manually first!).
#
# Environment overrides (Windows Git Bash, MSYS_NO_PATHCONV=1 handled internally):
#   PORT_IMAGE      wordpress image          (default wordpress:6.9-php8.2-apache)
#   PORT_NETWORK    docker network           (default oos-wp_default)
#   PORT_CORE_VOL   wp-core volume           (default oos-wp_wp_core)
#   PORT_VENDOR_VOL composer vendor volume   (default pearl-snipe-vendor)
#   PORT_SUITE_DIR  phpunit config dir       (default plugins/nvoos-content-graph-ai-platform)
#   PORT_EXTRA_ARGS extra phpunit args       (default "")

set -u

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || echo .)"
cd "$REPO_ROOT" || exit 1

IMAGE="${PORT_IMAGE:-wordpress:6.9-php8.2-apache}"
NETWORK="${PORT_NETWORK:-oos-wp_default}"
CORE_VOL="${PORT_CORE_VOL:-oos-wp_wp_core}"
VENDOR_VOL="${PORT_VENDOR_VOL:-pearl-snipe-vendor}"
SUITE_DIR="${PORT_SUITE_DIR:-plugins/nvoos-content-graph-ai-platform}"
EXTRA_ARGS="${PORT_EXTRA_ARGS:-}"
WIN_ROOT="$(cygpath -w "$REPO_ROOT" 2>/dev/null || echo "$REPO_ROOT")"

usage() {
	sed -n '2,18p' "$0" | sed 's/^# \{0,1\}//'
}

cmd_status() {
	echo "== Remaining clusters (tracker) =="
	sed -n '/## Wave E /,/^## Wave G /p' docs/project/ecosystem-port-tracker.md \
		| grep -E '^\| (E[0-9]|E-UI|F[0-9]|F-UI|G[0-9])' \
		| sed -E 's/\| +\| */| /g'
	echo
	echo "== Open port PRs (gh) =="
	gh pr list --state open --limit 30 2>/dev/null | grep -iE "port|wave|ecosystem" || echo "  (gh unavailable or none found)"
}

cmd_new() {
	[ "$#" -eq 2 ] || { echo "usage: port-cluster.sh new <wave> <slug>"; exit 2; }
	local wave="$1" slug="$2" branch
	branch="feat/ecosystem-port-${wave}-${slug}"
	[ -z "$(git status --porcelain)" ] || { echo "error: working tree dirty — commit or stash first"; exit 1; }
	git fetch origin alpha-working || exit 1
	git checkout -b "$branch" origin/alpha-working || exit 1
	echo "created branch: $branch"
}

run_matrix() {
	# $1 = extra -e args (standalone flag or empty)
	MSYS_NO_PATHCONV=1 docker run --rm \
		-e WP_CORE_DIR=/var/www/html -e WP_DB_HOST=db -e WP_DB_NAME=wordpress_test \
		-e WP_DB_USER=wordpress -e WP_DB_PASSWORD=wordpress \
		$1 \
		-v "${CORE_VOL}:/var/www/html" \
		-v "${WIN_ROOT}:/var/www/html/wp-content/plugins/mcp-ai-wpoos" \
		-v "${VENDOR_VOL}:/var/www/html/wp-content/plugins/mcp-ai-wpoos/vendor" \
		--network "$NETWORK" "$IMAGE" \
		sh -c "cd /var/www/html/wp-content/plugins/mcp-ai-wpoos && php -d memory_limit=1G vendor/bin/phpunit -c ${SUITE_DIR}/phpunit.xml.dist --no-coverage ${EXTRA_ARGS}"
}

cmd_gates() {
	local only_files=""
	if [ "$#" -ge 2 ] && [ "$1" = "--only" ]; then
		shift
		only_files="$*"
	fi

	echo "== phpcs =="
	if [ -n "$only_files" ]; then
		php vendor/bin/phpcs --standard="${SUITE_DIR}/phpcs.xml.dist" $only_files
	else
		php vendor/bin/phpcs --standard="${SUITE_DIR}/phpcs.xml.dist" \
			"$(git diff --name-only origin/alpha-working...HEAD -- '*.php' | tr '\n' ' ')" \
			"$(git status --porcelain | grep '^??' | grep '\.php$' | sed 's/^?? //' | tr '\n' ' ')"
	fi
	PHPCS_EXIT=$?
	echo "phpcs exit: $PHPCS_EXIT"

	echo "== standalone matrix =="
	run_matrix "-e WP_MCP_AI_PLATFORM_STANDALONE=1" 2>&1 | grep -E "^(Tests|There (was|were)|FAILURES|OK)|risky" || true
	echo "== monolith matrix =="
	run_matrix "" 2>&1 | grep -E "^(Tests|There (was|were)|FAILURES|OK)|risky" || true
}

cmd_ship() {
	[ "$#" -ge 1 ] || { echo "usage: port-cluster.sh ship <pr-title>"; exit 2; }
	local title="$1"
	git push -u origin HEAD || exit 1
	gh pr create --base alpha-working --title "$title" --fill || exit 1
}

case "${1:-}" in
	status) shift; cmd_status "$@" ;;
	new) shift; cmd_new "$@" ;;
	gates) shift; cmd_gates "$@" ;;
	ship) shift; cmd_ship "$@" ;;
	*) usage; exit 2 ;;
esac
