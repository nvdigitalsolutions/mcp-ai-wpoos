# Test-environment runbooks

Moved out of `SKILL.md` on 2026-11-08, which had reached 97 KB against Zed's
~100 KB skill-load limit. Covers the non-default test runs: which worktree
the `oos-wp` container mounts and how to retarget it, the Pro addon
dual-matrix run, and the standalone-plugin suites.

### Cross-worktree runs (when `oos-wp` mounts a different worktree)

The `oos-wp` plugin path bind-mounts **one** worktree. Check which one:

```bash
docker inspect oos-wp --format "{{json .Mounts}}"
```

If the mounted path is not the worktree you are editing, `docker exec oos-wp`
runs the wrong code. Instead run a one-off container on the same network
sharing the WP-core volume, with your worktree mounted over the plugin path:

```bash
# 1. Build a Linux vendor into a named volume (the Windows-host vendor/
#    breaks inside Linux — classmap paths with backslashes →
#    'Class "PHPUnit\\TextUI\\Application" not found').
#    REUSE an existing complete volume when present (docker volume ls):
#    mcp-ai-wpoos-vendor / mcp-ai-wpoos-alpha-vendor / alpha-working-vendor
#    ship with PHPUnit + wp-phpunit and need no build. On this box
#    (2026-10-27) only `alpha-working-vendor` exists — verify with
#    docker volume ls, never assume the name.
#
#    Session note (2026-10-17): the main checkout may have no vendor/ at all;
#    `docker exec oos-wp ... vendor/bin/phpunit` then fails with
#    "Could not open input file: vendor/bin/phpunit". Use the one-off runner
#    and mount the existing `mcp-ai-wpoos-alpha-vendor` volume over the
#    plugin's vendor/. Pass explicit test files, not a directory (see the
#    PHPUnit 11 suffix trap above).
docker volume create <worktree>-vendor
MSYS_NO_PATHCONV=1 docker run --rm \
  -v F:/GITHUB/worktrees/mcp-ai-wpoos/<worktree>/mcp-ai-wpoos:/app:ro \
  -v <worktree>-vendor:/app/vendor \
  composer:2 sh -c 'cd /app && composer install --no-interaction --prefer-dist --no-progress'

# 2. Run the tests (nested mounts: volume over the worktree's vendor/ works).
MSYS_NO_PATHCONV=1 docker run --rm \
  -e WP_CORE_DIR=/var/www/html -e WP_DB_HOST=db -e WP_DB_NAME=wordpress_test \
  -e WP_DB_USER=wordpress -e WP_DB_PASSWORD=wordpress \
  -v oos-wp_wp_core:/var/www/html \
  -v F:/GITHUB/worktrees/mcp-ai-wpoos/<worktree>/mcp-ai-wpoos:/var/www/html/wp-content/plugins/mcp-ai-wpoos \
  -v <worktree>-vendor:/var/www/html/wp-content/plugins/mcp-ai-wpoos/vendor \
  --network oos-wp_default \
  wordpress:6.9-php8.2-apache \
  sh -c 'cd /var/www/html/wp-content/plugins/mcp-ai-wpoos && php -d memory_limit=1G vendor/bin/phpunit <paths> --no-coverage'
```

**WP 7.1 cross-worktree:** the wp71 core lives in the `oos-wp` container's own
`/tmp/wp71` (NOT in the shared `oos-wp_wp_core` volume). Copy it out, mount it
at `/tmp/wp71` in the one-off run, and swap `WP_CORE_DIR=/tmp/wp71` +
`WP_DB_NAME=wordpress_test_wp71`:

```bash
docker cp oos-wp:/tmp/wp71 ./docker-tmp-wp71
# add: -v F:/GITHUB/worktrees/mcp-ai-wpoos/<worktree>/mcp-ai-wpoos/docker-tmp-wp71:/tmp/wp71
```

**When `/tmp/wp71` is gone** (container restarts wipe it — `docker cp` errors
with `Could not find the file /tmp/wp71 in container oos-wp`), rebuild a
reusable named volume instead of polluting the repo:

```bash
# wordpress:cli runs as www-data — run as root to create the volume,
# and raise PHP memory or the extractor fatals at 128M:
docker volume create mf-wp71-core
docker run --rm --user 0:0 -v mf-wp71-core:/wp wordpress:cli-php8.3 sh -c \
  'php -d memory_limit=512M /usr/local/bin/wp --allow-root --path=/wp core download --version=7.1.3 --quiet && chown -R 33:33 /wp'
# then mount it: -v mf-wp71-core:/tmp/wp71 with WP_CORE_DIR=/tmp/wp71 + WP_DB_NAME=wordpress_test_wp71
```

(`WP_CLI_PHP_ARGS` env is not honoured by the phar shim — pass
`php -d memory_limit=512M /usr/local/bin/wp` explicitly.)

Clean up afterwards: `rm -rf docker-tmp-wp71` and `docker volume rm <worktree>-vendor`
— never stage either artifact. The no-concurrent-phpunit rule applies to
one-off runners too (same shared DB).

**Pruned-vendor carry-over (hit on the 0.4.7 docs-hub pass):** some
worktrees carry a partial `vendor/` whose
`vendor/composer/autoload_classmap.php` has **zero** PHPUnit entries —
`php -r 'require "vendor/autoload.php"; var_dump(class_exists("PHPUnit\\TextUI\\Application"));'`
prints `false` on BOTH host and container, and `vendor/bin/phpunit` dies
with `Class "PHPUnit\TextUI\Application" not found`. Quick check:
`grep -c PHPUnit vendor/composer/autoload_classmap.php` (0 = pruned). The
Linux vendor volume is the fix for container runs; for host-side runs,
`composer install` on the host rebuilds a Windows vendor that then breaks
INSIDE Linux — pick one environment per session.

Also true for the MAIN checkout on this box (2026-10-22, re-verified
2026-10-27): `vendor/` exists but is pruned, so even a direct
`docker exec oos-wp … vendor/bin/phpunit` fails the same way. Use the
one-off runner with the existing **complete** `alpha-working-vendor`
volume (verified: 934 PHPUnit classmap entries + wp-phpunit present;
ran the Gmail/Calendar auto-fallback suites green on
`wordpress:6.9-php8.2-apache`) and, for the WP 7.1 matrix, the reusable
`wp71-core` named volume mounted at `/tmp/wp71` (DB
`wordpress_test_wp71`) — no `docker cp` dance needed when `/tmp/wp71` is
gone from the container.

**Verify the vendor volume BEFORE trusting the ledger (2026-11-04):**
`alpha-working-vendor` was EMPTY despite the notes above (a prune emptied
it) — the one-off run then dies with `Could not open input file:
vendor/bin/phpunit`. Probe first (`docker run --rm -v <vol>:/v
wordpress:6.9-php8.2-apache sh -c 'ls /v/bin/phpunit; grep -c PHPUnit
/v/composer/autoload_classmap.php'`) or rebuild from scratch
(`docker volume create <worktree>-vendor` + the composer:2 step above).
On 2026-11-04 `astro-105106-vendor` was the complete one (934 classmap
entries).

**Host-side recovery without the volume (2026-10-23, main checkout):**
the pruned classmap can be fixed in place — run
`composer install --no-interaction --prefer-dist` on the **host** (the
plugin dir is bind-mounted into `oos-wp`, so the container sees the same
vendor), then `bin/run-tests-docker.sh <file>` works again. Two caveats:
(1) `run-tests-docker.sh` runs its own `composer install` step on the HOST
and, when it fails, silently falls back to `composer dump-autoload` — that
fallback regenerates the classmap from the production-only `installed.json`,
which is precisely what produces the unmapped-dev-packages state, so treat
the line `WARNING: composer install failed — attempting dump-autoload
fallback` as the root cause and repair the host install before rerunning.
(2) `vendor/` is committed — after a host `composer install`, run
`git --no-optional-locks restore vendor` to drop the unrelated churn
(guzzle etc.) from the changeset.

**`docker-tmp-logs/` artifact:** running the root suite writes an untracked
`docker-tmp-logs/` directory into the repo root (random `.jpg` files +
`chunk8.txt`). Delete it before staging anything (`git status --porcelain`
will show it as `??`).

### Pro addon dual-matrix runs (nvoos-content-graph-pro)

The `PHPUnit Pro Addon` CI workflow runs the addon suite twice (monolith +
standalone) — mirror it locally with the same one-off runner:

```bash
# Standalone matrix (base plugin skipped; add the env var):
-e WP_MCP_AI_PRO_STANDALONE=1 \
# Config: the addon's own phpunit config (bootstrap loads the ecosystem
# plugins + honours the env var):
php -d memory_limit=1G vendor/bin/phpunit -c plugins/nvoos-content-graph-pro/phpunit.xml.dist <paths> --no-coverage

# Monolith matrix: same command WITHOUT the env var.
```

### Standalone plugin suites (plugins/nvoos-content-graph, -ai, -pro)

`plugins/nvoos-content-graph` ships its **own** composer.json +
phpunit.xml.dist (PHPUnit 9.6, wp-phpunit 7.0.2, PSR-4-only autoload — no
Windows classmap hazard, so a host-side `composer install` in the plugin dir
is safe and works inside the Linux container). **No monorepo CI workflow runs
its `phpunit.xml.dist`** (phpunit-ai.yml only covers the AI addon's
`phpunit-ecosystem.xml.dist`), so run it explicitly:

```bash
# Host: install the plugin's dev deps (creates
# plugins/nvoos-content-graph/vendor — gitignored):
cd plugins/nvoos-content-graph && composer install --no-interaction --prefer-dist
```

**Mount-path caution:** `git rev-parse --show-toplevel` BEFORE mounting —
the MAIN checkout is `F:/GITHUB/mcp-ai-wpoos` while
`F:/GITHUB/worktrees/mcp-ai-wpoos` is the PARENT of the git worktrees
(worktree repos live at `.../mcp-ai-wpoos/<worktree-name>/mcp-ai-wpoos`).
Mounting the parent silently runs the WRONG code — verify with
`git worktree list`. The Zed file tools may report the worktrees path as
the allowed directory even when the terminal's repo root is the main
checkout; trust `git rev-parse`, not the tool metadata.

# Write the wp-phpunit config the plugin bootstrap expects. wp-phpunit 7.x
# ships vendor/wp-phpunit/wp-phpunit/wp-tests-config.php as a "DO NOT EDIT"
# LOADER stub that requires the file named by the WP_PHPUNIT__TESTS_CONFIG
# env var (and reads WP_PHPUNIT__TABLE_PREFIX). Write the real config to a
# sibling path and point the env var at it — overwriting the loader with the
# config also works, but the loader + env-var pattern survives vendor
# refreshes. vendor/ paths are excluded from repo file tools — use the shell:
cat > vendor/wp-tests-config.php <<'EOF'
<?php
define( 'ABSPATH', '/var/www/html/' );
define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', 'db' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
$table_prefix = 'wptests_';
EOF

# Ensure the DB exists (bootstrap auto-runs install.php single-site unless
# WP_TESTS_SKIP_INSTALL=1):
docker exec oos-wp-db mysql -uroot -pwordpress -e \
  "CREATE DATABASE IF NOT EXISTS wordpress_test; GRANT ALL PRIVILEGES ON wordpress_test.* TO 'wordpress'@'%'; FLUSH PRIVILEGES;"

# Run (php:8.2-cli LACKS mysqli — wp_die "missing the MySQL extension"; use
# wordpress:6.9-php8.2-apache with --entrypoint php, which ships mysqli):
MSYS_NO_PATHCONV=1 docker run --rm --entrypoint php \
  -v oos-wp_wp_core:/var/www/html \
  -v F:/GITHUB/mcp-ai-wpoos:/var/www/html/wp-content/plugins/mcp-ai-wpoos \
  --network oos-wp_default \
  -e WP_TESTS_DIR=/var/www/html/wp-content/plugins/mcp-ai-wpoos/plugins/nvoos-content-graph/vendor/wp-phpunit/wp-phpunit \
  -e WP_PHPUNIT__TESTS_CONFIG=/var/www/html/wp-content/plugins/mcp-ai-wpoos/plugins/nvoos-content-graph/vendor/wp-tests-config.php \
  -e WP_PHPUNIT__TABLE_PREFIX=wptests_ \
  wordpress:6.9-php8.2-apache \
  /var/www/html/wp-content/plugins/mcp-ai-wpoos/plugins/nvoos-content-graph/vendor/bin/phpunit \
  -c /var/www/html/wp-content/plugins/mcp-ai-wpoos/plugins/nvoos-content-graph/phpunit.xml.dist --testsuite=Unit
```

- "Full plugin run" = both suites: `--testsuite=Unit` then
  `--testsuite=Integration` (integration needs only WP + bundled JetEngine
  stubs). The no-concurrent-phpunit rule still applies (same DB).
- JS contract checks need no browser: `node scripts/verify-commerce-fallback.js`
  (purchase-modal fallback redirect contract) and
  `node scripts/verify-theme-engine.js` run on the host from the plugin dir.
- When only the client plugin is in scope this is faster than the root suite
  (which boots the full base+Pro plugin); the root suite covers
  `addons/checkout-api/tests` via the root phpunit.xml.dist.
- **`plugins/nvoos-forge-bridge` also runs natively on the Windows host** (no
  Docker; host PHP 8.3 has mysqli) — from the plugin dir:
  `WP_PHPUNIT__TESTS_CONFIG=<abs>/wp-tests-config.php vendor/bin/phpunit -c phpunit.xml.dist --no-coverage`.
  Three traps, each hit on 2026-11-08: (1) the config's `ABSPATH` must point
  at a **full** WP core (a dir containing `wp-settings.php`) —
  `%TEMP%\wordpress-tests-lib` (`includes/` + `data/` only) and a stripped
  `%TEMP%\wordpress` (`wp-admin/wp-content/wp-includes` only) both die at
  `require_once ABSPATH . 'wp-settings.php'`; find a real one by walking
  `os.tmpdir()` for `wp-settings.php`; (2) without
  `WP_PHPUNIT__TESTS_CONFIG` the loader stub defines nothing and the error is
  *"The following required constants are not defined: WP_TESTS_DOMAIN, …"*,
  not "config missing"; (3) create `wordpress_test` against the host's
  `oos-wp-db` (3306, `root`/`wordpress`) first — the bootstrap installs
  single-site itself.
- **DDL escapes the per-test transaction.** For a plugin suite that creates
  its provider's tables (the redirects export), `CREATE TABLE` in `setUp()`
  implicitly commits and leaks state. Create in `setUpBeforeClass()`, `DROP`
  in `tearDownAfterClass()`, and keep all row writes (`DELETE`/`INSERT`) in
  `setUp()` so wp-phpunit's rollback still isolates tests. Delete the
  generated `wp-tests-config.php` afterwards so another suite cannot silently
  adopt `wordpress_test`.
- **`WordPress.DB.PreparedSQL.NotPrepared` fires on a `$sql` VARIABLE**
  passed to `$wpdb->prepare()` (even when it contains `%d`). Inline the query
  literal into the call and keep the `InterpolatedNotPrepared` disable for
  `{$wpdb->prefix}`. A `phpcs:ignore` on the line ABOVE a multi-line
  statement does not cover sniffs reported on its inner lines — use
  `phpcs:disable <Sniff>` / `phpcs:enable <Sniff>` around it (the plugin's
  `composer run lint` fails on warnings, so exit code 1 is not "clean").
- **Self-guarding addon tests bootstrap (#7134, SA platform):**
  `addons/schedule-anything-platform/tests/bootstrap.php` guards each test
  class with `class_exists()` on the addon's plugin class and marks the suite
  skipped when the addon isn't loaded, then the three platform test files run
  inside the standard single-site root suite (wired via the root
  `phpunit.xml.dist`). Use this pattern for any addon whose tests must run
  in the root run without hard-requiring the addon.

**Shared-DB isolation (cross-worktree clobbering):** every worktree's one-off
runner hits the SAME `wordpress_test` DB by default — a concurrent worktree's
`install.php` re-runs wipe your schema mid-suite (mass "Table doesn't exist"
failures). Isolate per worktree with a dedicated DB:

```bash
# One-time: create the isolated DB (db container name from docker ps; creds
# in docker-compose.yml — oos-wp-db / wordpress:wordpress by default):
docker exec oos-wp-db mysql -uroot -pwordpress -e \
  "CREATE DATABASE IF NOT EXISTS wordpress_test_pearl; GRANT ALL PRIVILEGES ON wordpress_test_pearl.* TO 'wordpress'@'%'; FLUSH PRIVILEGES;"

# Then point BOTH the schema install and every phpunit run at it:
-e WP_DB_NAME=wordpress_test_pearl \
# Schema install (once, or after the DB gets dropped):
php vendor/wp-phpunit/wp-phpunit/includes/install.php tests/wp-tests-config.php
```

The env var overrides `DB_NAME` in `tests/wp-tests-config.php` (which reads
`getenv( 'WP_DB_NAME' )`) — never edit the tracked config file.

- Run `--filter Test_X` first, then the full suite — the full run is ~10-20
  min locally.
- Sequential monolith + standalone runs may reuse `wordpress_test`; keep the
  no-concurrent rule.
- The `<worktree>-vendor` volume is reusable across clusters: re-run the
  composer step (a no-op when current).
- Expected skips: standalone ~1 skip; monolith skips every standalone-gated
test (hundreds — not failures). A summary of "OK, but there were issues"
with only warnings/deprecations/skips is a pass.
- phpcs for the addon: `--standard=plugins/nvoos-content-graph-pro/phpcs.xml.dist`
(phpcbf exit 1 = fixed files, not an error; re-run phpcs to confirm exit 0).
- **content-graph session notes (1.0.9 → 1.0.12 passes):** the plugin's
  `vendor/` may be PRUNED of PHPUnit — sometimes partially, with
  `vendor/bin/phpunit` present while `vendor/phpunit/phpunit/phpunit` is
  gone (the run then dies with a PHP file-open warning from the bin shim,
  `Failed to open stream: .../phpunit/phpunit/phpunit`, not "Could not open
  input file") — a plain `composer install` in the plugin dir restores the
  locked set (PHPUnit 9.6.35 + wp-phpunit 7.0.2) and the recipe above then
  works as written. Point the gitignored `vendor/wp-tests-config.php` at an
  isolated DB (`wordpress_test_cg12` pattern) before the run. Current
  sizes: Unit 164 tests / 743 assertions (~7 s) and Integration 29 / 80
  (~10 s) on `wordpress:6.9-php8.2-apache`, green on WP 6.9 AND WP 7.1
  (for 7.1 mount the `wp71-core` volume at `/tmp/wp71`, set `ABSPATH` to
  `/tmp/wp71/`, and reuse the same isolated DB).
- **Do not let local test configs leak into the standalone ZIP.** The
  config file above lives in the gitignored `vendor/`, and
  `bin/build-nvoos-content-graph.sh` rsyncs `vendor/` wholesale into the
  distribution ZIP — DB credentials and all. Delete `vendor/wp-tests-config*.php`
  before building and verify the built ZIP listing has no `wp-tests-config`
  entries (hit on the 1.0.12 pass: the first build shipped the scratch
  config).

### No-dev vendor shim (PHPUnit without `composer install`)

Use when the plugin's `vendor/` is a `--no-dev` install (PHPUnit package
files present but unmapped: `Class "PHPUnit\TextUI\Application" not found`)
**and** `composer` is unavailable in the container (`which composer` → 127),
so the `composer install` + `git restore vendor/composer` route cannot run —
on this box the committed `vendor/composer` map is itself no-dev (2026-11-12),
so restoring it re-breaks PHPUnit anyway.

Write this shim to the repo root (the bind mount makes it visible in the
container), run PHPUnit through it, then **delete it — never commit it**:

```php
<?php
/**
 * Throwaway harness: rebuild PSR-4/classmap maps for dev packages that exist
 * on disk but were dropped from the no-dev composer map, then run PHPUnit.
 */

$root     = __DIR__;
$maps     = array();
$classmap = array();
$files    = array(
	$root . '/vendor/phpunit/phpunit/src/Framework/Assert/Functions.php',
);

require $root . '/vendor/autoload.php';

/**
 * Extract declared class-like names from one PHP file.
 *
 * @param string $path File path.
 * @return array<string,string> Class name => path.
 */
function tmp_scan_classmap( $path ) {
	$tokens = token_get_all( (string) file_get_contents( $path ) );
	$count  = count( $tokens );
	$ns     = '';
	$out    = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) ) {
			continue;
		}

		if ( T_NAMESPACE === $token[0] ) {
			$ns = '';
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$next = $tokens[ $j ];
				if ( is_array( $next ) && in_array( $next[0], array( T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR ), true ) ) {
					$ns .= $next[1];
					continue;
				}
				if ( is_string( $next ) && ( ';' === $next || '{' === $next ) ) {
					break;
				}
			}
			continue;
		}

		if ( ! in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) ) {
			continue;
		}

		// Skip `::class` and anonymous classes.
		$prev = $i - 1;
		while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
			--$prev;
		}
		if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_DOUBLE_COLON === $tokens[ $prev ][0] ) {
			continue;
		}

		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}
		if ( $j < $count && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
			$out[ ( '' !== $ns ? $ns . '\\' : '' ) . $tokens[ $j ][1] ] = $path;
		}
	}

	return $out;
}

foreach ( glob( $root . '/vendor/*/*/composer.json' ) as $file ) {
	$data = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $data ) || empty( $data['autoload'] ) ) {
		continue;
	}

	$base = dirname( $file );

	foreach ( (array) ( isset( $data['autoload']['psr-4'] ) ? $data['autoload']['psr-4'] : array() ) as $prefix => $paths ) {
		foreach ( (array) $paths as $p ) {
			$maps[ $prefix ][] = rtrim( $base . '/' . ltrim( (string) $p, '/' ), '/' ) . '/';
		}
	}

	if ( ! empty( $data['autoload']['classmap'] ) ) {
		foreach ( (array) $data['autoload']['classmap'] as $p ) {
			$path = $base . '/' . ltrim( (string) $p, '/' );
			if ( is_dir( $path ) ) {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
				);
				foreach ( $iterator as $entry ) {
					if ( $entry->isFile() && 'php' === strtolower( $entry->getExtension() ) ) {
						$classmap += tmp_scan_classmap( $entry->getPathname() );
					}
				}
			} elseif ( is_file( $path ) ) {
				$classmap += tmp_scan_classmap( $path );
			}
		}
	}

	if ( ! empty( $data['autoload']['files'] ) ) {
		foreach ( (array) $data['autoload']['files'] as $p ) {
			$files[] = $base . '/' . ltrim( (string) $p, '/' );
		}
	}
}

spl_autoload_register(
	static function ( $class ) use ( $maps, $classmap ) {
		if ( isset( $classmap[ $class ] ) ) {
			require $classmap[ $class ];
			return;
		}
		foreach ( $maps as $prefix => $dirs ) {
			if ( 0 !== strpos( $class, $prefix ) ) {
				continue;
			}
			$rel = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			foreach ( $dirs as $dir ) {
				if ( is_file( $dir . $rel ) ) {
					require $dir . $rel;
					return;
				}
			}
		}
	}
);

foreach ( $files as $file ) {
	if ( is_file( $file ) ) {
		require_once $file;
	}
}

$_SERVER['argv'] = $argv;
$argv[0]         = 'phpunit';

exit( ( new PHPUnit\TextUI\Application() )->run( $argv ) );
```

Invoke (normal PHPUnit flags pass through; config resolves from cwd):

```bash
MSYS_NO_PATHCONV=1 docker exec -e WP_CORE_DIR=/var/www/html -e WP_DB_HOST=db \
  -e WP_DB_NAME=wordpress_test -e WP_DB_USER=wordpress -e WP_DB_PASSWORD=wordpress \
  oos-wp sh -c 'cd /var/www/html/wp-content/plugins/mcp-ai-wpoos && \
  php -d memory_limit=1G tmp-dev-phpunit.php --no-coverage tests/test_foo.php 2>&1'
rm tmp-dev-phpunit.php   # host side, after the run
```

Gotchas baked into the shim (each was a real failure while building it):

- `require vendor/autoload.php` does **not** return the Composer
  `ClassLoader` in Composer 2 — find it with `spl_autoload_functions()`.
- PHPUnit 11 declares `classmap: ["src/"]`, not PSR-4, and its `src/` is
  **not** strictly PSR-4 (`PHPUnit\Event\DispatchingEmitter` lives in
  `src/Event/Emitter/DispatchingEmitter.php`) — a PSR-4-only shim fatals on
  the first such class. Each `classmap` package is token-scanned instead.
- PHPUnit 11's entry point is `( new Application() )->run( $argv )`;
  `Application::main()` was the PHPUnit 9 API.
- `sebastian/version` etc. are classmap packages too — the same scan covers
  them, plus every package's `autoload.files` (Assert functions, polyfills).
- Expected harmless noise:
  `PHP Warning: proc_open(): posix_spawn() failed` from
  `vendor/sebastian/version/src/Version.php` (git-describe version lookup).
- Still pair this with the **hyphen-filename temp-copy** rule above when
  running individual test files; the shim does not change the loader.
- Prefer a completed `*-vendor` volume when one exists (see cross-worktree
  runs) — this shim is the fallback, not the default.

