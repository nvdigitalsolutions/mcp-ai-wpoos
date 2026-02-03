# Composer Patches

This directory contains patches applied to vendor packages via the `cweagans/composer-patches` plugin.

## wp-phpunit-wp-lang-dir-guard.patch

**Package:** `wp-phpunit/wp-phpunit`  
**Issue:** Fixes "Constant WP_LANG_DIR already defined" warning during plugin activation and performance tests

### Background

The wp-phpunit package's `includes/bootstrap.php` unconditionally defines the `WP_LANG_DIR` constant without checking if it's already defined. This causes PHP warnings in scenarios where:

1. WordPress core has already loaded and defined `WP_LANG_DIR` in `wp-includes/default-constants.php`
2. Performance tests run via the NV oOS admin interface, which executes PHPUnit in an already-bootstrapped WordPress environment
3. The plugin is activated via WP-CLI during provisioning/startup scripts

### Solution

This patch adds a guard check (`if ( ! defined( 'WP_LANG_DIR' ) )`) before defining the constant, preventing the duplicate definition warning while maintaining backward compatibility.

### Application

The patch is automatically applied when running:
```bash
composer install
composer update
```

The patch is defined in `composer.json` under `extra.patches`.

### Upstream

This fix should ideally be submitted upstream to the wp-phpunit project. However, until merged and released, we maintain this local patch for compatibility.

---

## league-oauth2-client-remove-approval-prompt.patch

**Package:** `league/oauth2-client`  
**Issue:** Fixes "Error 400: invalid_request - Conflict params: approval_prompt and prompt" when connecting to Google OAuth services

### Background

When connecting to Google services (Gmail, Google Drive) via OAuth, the League OAuth2 Client library v2.7-2.9 automatically adds the deprecated `approval_prompt=auto` parameter to authorization URLs. However, our OAuth Manager code also adds the modern `prompt=consent` parameter to follow OAuth best practices.

Google's OAuth server now rejects requests that contain both parameters, returning:
```
Error 400: invalid_request - Conflict params: approval_prompt and prompt
```

The `approval_prompt` parameter was deprecated by Google in 2014 and replaced with `prompt`. While both were accepted for backward compatibility until 2021, Google now actively rejects requests with both parameters present.

### Solution

This patch removes the `'approval_prompt' => 'auto'` default parameter from League OAuth2 Client's `AbstractProvider::getAuthorizationParameters()` method. Our code continues to explicitly set `prompt=consent`, which is the modern, recommended approach.

### Impact

This fix affects all Google OAuth integrations:
- Gmail OAuth (Base version)
- Google Drive OAuth (Base version)  
- Gmail OAuth (Pro addon - Remote Sites)
- Google Drive OAuth (Pro addon - Remote Sites)

All OAuth best practices are preserved:
- `access_type=offline` - Offline access with refresh tokens
- `prompt=consent` - Explicit user consent
- `include_granted_scopes=true` - Incremental authorization
- State parameter - CSRF protection

### Application

The patch is automatically applied when running:
```bash
composer install
composer update
composer reinstall league/oauth2-client
```

### Testing

A test suite is available to verify the fix:
```bash
vendor/bin/phpunit tests/test-league-oauth2-no-approval-prompt.php
```

### Documentation

See full documentation: `docs/fixes/google-oauth-approval-prompt-fix-2026-02-03.md`

### Upstream

This is a known issue with League OAuth2 Client v2.x. Options for upstream resolution:
1. Remove `approval_prompt` entirely (breaking change for very old OAuth providers)
2. Make it configurable via provider options
3. Only add it if `prompt` is not already set

Until an upstream fix is available, we maintain this local patch for Google OAuth compatibility.
