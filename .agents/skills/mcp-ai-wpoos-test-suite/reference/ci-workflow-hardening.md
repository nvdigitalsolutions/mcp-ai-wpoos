      before `wp_insert_post` (astro bridge content writes, 2026-11-03).**
      The 6.9 core image's `rest_parse_date()` ends in
      `return strtotime( $date )` (older core returns the `'Y-m-d H:i:s'`
      string). Code that passes the result straight into `post_date` fails
      `wp_checkdate()` (`invalid_date` WP_Error, surfaced as a 500 from a
      REST callback that returns the raw WP_Error). Normalize defensively:
      `if ( is_int( $parsed ) && $parsed > 0 ) { $parsed = gmdate(
      'Y-m-d H:i:s', $parsed ); }`. Related core semantic (same session):
      **a future `post_date` always wins** — `wp_insert_post` flips an
      explicit `publish` to `future` rather than publishing early; pin that
      in tests instead of asserting "explicit status wins".

 ## CI workflow hardening — setup-php `tools: wp-cli` flakes

 `shivammathur/setup-php` `tools: wp-cli` fails intermittently (`✗ wp-cli
 Could not setup wp-cli`) WITHOUT failing the step, so the next `wp core
 download` dies with `wp: command not found` (exit 127) — same code, same
 workflow, alternating green/red runs. Fixed in PR #6998 for
 `.github/workflows/phpunit-content-graph.yml` + the plugin-check job of
 `.github/workflows/build-nvoos-content-graph.yml`:

 ```yaml
 - name: Ensure WP-CLI is available
   run: |
     if ! command -v wp >/dev/null 2>&1; then
       curl -sSLo wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
       chmod +x wp-cli.phar
       sudo mv wp-cli.phar /usr/local/bin/wp
     fi
     wp --version
 ```

 Diagnose via `gh run view <id> --log | grep -iE "wp-cli"` — look for the
 red ✗ tool line in the Set up PHP step, not the step that died. Any new
 workflow using setup-php `tools: wp-cli` should include this fallback step.

69. **`wp_http_validate_url` rejects valid URLs in the Docker test runner
    (astro bridge, 2026-11-03).** The runner's DNS resolution makes core's
    URL validator reject legitimate https URLs. Symptom: shop/checkout URL
    validation in REST tests fails on values that pass on live sites. Fix:
    validate with `filter_var( $url, FILTER_VALIDATE_URL )` + an
    `https?://` scheme whitelist instead of `wp_http_validate_url` (the Pro
    schedule-manager suite documents the same gotcha).

70. **Auth-gate tests: use subscriber → 403, not anonymous (astro bridge,
    2026-11-03).** The root test bootstrap injects a valid admin auth
    cookie, and the bridge's REST cookie-restore path re-authenticates it —
    anonymous assertions are nondeterministic. Assert the gate with a
    subscriber (logged in, wrong capability) for a stable 403.

71. **The test DB persists across runs — pre-delete pinned synthetic posts.
    ** Synthetic fixture posts with fixed ids (the astro bridge draft id
    9902) linger between runs and silently turn id-collision assertions
    flaky. Fix: `wp_delete_post( 9902, true )` in `setUp()` before
    re-creating the fixture.

72. **Woo price reads use the CRUD method, not a standalone function.
    ** There is no `wc_get_price()`; variant/product pricing is
    `$product->get_price()` / `$variation->get_price()`. Attribute labels go
    through `wc_attribute_label()` (the `pa_*` taxonomy slug is not the
    label).

73. **Astro bridge dual-matrix volumes (2026-11-03).** The WP 7.1 suite runs
    against the shared core volume (DB `wordpress_test_astro_wp71`); the
    WP 6.9 matrix needs its own fresh volume (`astro-wp69-core`, DB
    `wordpress_test_astro`). The Linux vendor volume
    (`astro-105106-vendor`: PHPUnit 11.5.56 + wp-phpunit 7.0.2, patches
    applied) is reusable across bridge clusters — host/container vendor
    pruning is safe after it exists.

74. **Verified local bridge invocation (2026-11-03, the 108 cluster).**
    Volumes present on this box as of then: `astro-105106-vendor` (Linux
    vendor), `wp71-core` (WP 7.1 core — the SKILL recipe's `mf-wp71-core`
    example; use `docker volume ls` to pick the real name), and the DBs
    `wordpress_test_astro`, `wordpress_test_astro_fresh`,
    `wordpress_test_astro_wp71` inside `oos-wp-db`. The one-off runner
    form that works (the `oos-wp` container's own `/tmp/wp71` is gone
    after restarts):

    ```bash
    # WP 6.9 matrix
    MSYS_NO_PATHCONV=1 docker run --rm \
      -e WP_CORE_DIR=/var/www/html -e WP_DB_HOST=db \
      -e WP_DB_NAME=wordpress_test_astro -e WP_DB_USER=wordpress -e WP_DB_PASSWORD=wordpress \
      -v oos-wp_wp_core:/var/www/html \
      -v F:/GITHUB/mcp-ai-wpoos:/var/www/html/wp-content/plugins/mcp-ai-wpoos \
      -v astro-105106-vendor:/var/www/html/wp-content/plugins/mcp-ai-wpoos/vendor \
      --network oos-wp_default wordpress:6.9-php8.2-apache \
      sh -c 'cd /var/www/html/wp-content/plugins/mcp-ai-wpoos && php -d memory_limit=1G vendor/bin/phpunit addons/nvoos-astro-bridge/tests/phpunit/<File>.php --no-coverage'

    # WP 7.1 matrix — swap the two core/DB envs and mounts:
    #   -e WP_CORE_DIR=/tmp/wp71 -e WP_DB_NAME=wordpress_test_astro_wp71
    #   -v wp71-core:/tmp/wp71   (instead of oos-wp_wp_core:/var/www/html)
    ```

    The runner image is just the PHP runtime (the mounted core defines the
    WP version) — `wordpress:6.9-php8.2-apache` works for both matrices.
    The bridge PHPUnit boot is slow under the one-off runner (~30-60 s of
    bootstrap logging before the test dots) — that is normal, not a hang.

 ## Production fix vs test fix

- **Fix production** when the test exposes a genuine bug: unsafe coercion
  (pattern 11), a latent fatal on a real code path (Graphify admin classes
  required only under bootstrap `is_admin()`, #6106), or a missing filter seam
  that blocks deterministic testing (#6097). Keep production edits minimal and
  behavior-compatible; document the contract change in the PR.
- **Fix the test** when it documents an outdated contract or an environment
  assumption. Never weaken a security assertion (nonce/capability) to make a
  test pass; prefer structural fixes over `setExpectedIncorrectUsage()`
  (ineffective on WP 7.1).

