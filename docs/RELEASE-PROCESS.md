# Release Process & Upgrade Testing Guide

## Overview

This document outlines the release process, upgrade testing procedures, and guardrails for WP oOS to ensure stable, secure releases that maintain backward compatibility.

## Release Checklist

### Pre-Release

- [ ] All tests pass (`composer run test`)
- [ ] PHP linting passes (`composer run lint`)
- [ ] JavaScript linting passes (`npm run lint:js`)
- [ ] PHP compatibility check passes (`composer run lint:compat`)
- [ ] CHANGELOG.md updated with version and changes
- [ ] Version bumped in main plugin file (`wp-mcp-ai.php`)
- [ ] Version bumped in `readme.txt`
- [ ] Security scan completed (no new vulnerabilities)
- [ ] Documentation updated for new features
- [ ] SDK versions pinned in `composer.json`
- [ ] `composer.lock` committed
- [ ] Assets built (`npm run build`)

### Testing

- [ ] Fresh installation tested
- [ ] Upgrade from previous version tested
- [ ] Authentication methods tested (WP, JWT, Auth0, Guest)
- [ ] Tool execution tested (sample of 5+ critical tools)
- [ ] Rate limiting tested
- [ ] Token budget enforcement tested
- [ ] Multisite compatibility tested (if applicable)
- [ ] Database migrations tested (if applicable)

### Release

- [ ] Tag created: `vX.Y.Z`
- [ ] GitHub release created with changelog
- [ ] Release assets uploaded (if applicable)
- [ ] WordPress.org repository updated (if applicable)
- [ ] Documentation site updated
- [ ] Security advisories reviewed

## Version Numbering

Follow [Semantic Versioning 2.0.0](https://semver.org/):

- **MAJOR** (X.0.0): Breaking changes, incompatible API changes
- **MINOR** (0.X.0): New features, backward compatible
- **PATCH** (0.0.X): Bug fixes, backward compatible

Example:
- `1.0.0` → `1.1.0`: Added per-assistant budgets (new feature, backward compatible)
- `1.1.0` → `1.1.1`: Fixed budget calculation bug (bug fix)
- `1.1.1` → `2.0.0`: Changed assistant CPT structure (breaking change)

## Upgrade Testing Procedures

### 1. Setup Test Environment

```bash
# Create clean WordPress installation
wp core download --path=/tmp/wp-test
wp config create --dbname=wp_test --dbuser=root --dbpass='' --path=/tmp/wp-test
wp db create --path=/tmp/wp-test
wp core install --url=http://localhost:8000 --title="WP oOS Test" \
  --admin_user=admin --admin_password=admin --admin_email=test@example.com \
  --path=/tmp/wp-test
```

### 2. Install Previous Version

```bash
# Clone repository and checkout previous version
git clone https://github.com/nvdigitalsolutions/wp-mcp-ai.git /tmp/wp-test/wp-content/plugins/wp-mcp-ai
cd /tmp/wp-test/wp-content/plugins/wp-mcp-ai
git checkout v1.0.0  # Previous version

# Install dependencies
composer install
npm install
npm run build

# Activate plugin
wp plugin activate wp-mcp-ai --path=/tmp/wp-test
```

### 3. Create Test Data

```bash
# Create assistants with various configurations
wp post create --post_type=mcp_ai_assistant --post_title="Test Assistant 1" \
  --post_status=publish --path=/tmp/wp-test

# Set metadata
wp post meta update 123 _wp_mcp_ai_provider openai --path=/tmp/wp-test
wp post meta update 123 _wp_mcp_ai_model gpt-4o-mini --path=/tmp/wp-test
wp post meta update 123 _wp_mcp_ai_tools '["post_reader","query_posts"]' \
  --format=json --path=/tmp/wp-test
```

### 4. Test Existing Functionality

Before upgrading, verify that:
- [ ] Assistants load correctly
- [ ] Chat functionality works
- [ ] Tools execute successfully
- [ ] Settings save properly
- [ ] Authentication works
- [ ] API endpoints respond correctly

### 5. Perform Upgrade

```bash
# Pull latest code
git fetch --tags
git checkout vX.Y.Z  # New version

# Update dependencies
composer install
npm install
npm run build

# Refresh WordPress
wp plugin deactivate wp-mcp-ai --path=/tmp/wp-test
wp plugin activate wp-mcp-ai --path=/tmp/wp-test
```

### 6. Post-Upgrade Verification

Verify that:
- [ ] **Existing Assistants**: All previous assistants still exist and load
- [ ] **Existing Configuration**: Previous settings preserved
- [ ] **Backward Compatibility**: Old features still work
- [ ] **New Features**: New features are available and functional
- [ ] **Database Schema**: Migrations applied successfully (if any)
- [ ] **Meta Data**: All post meta intact
- [ ] **Credentials**: Authentication still works
- [ ] **API Compatibility**: REST endpoints maintain compatibility

### 7. Specific Feature Testing

#### Authentication
```bash
# Test WordPress auth
wp http get http://localhost:8000/wp-json/mcp-ai/v1/assistants \
  --user=admin:admin

# Test credential-based auth
wp http get http://localhost:8000/wp-json/mcp-ai/v1/assistants \
  --headers='Authorization: Bearer cred_xxxxx.SECRET'
```

#### Tools & Chat
```php
// Test chat with tools
$messages = array(
    array( 'role' => 'user', 'content' => 'List recent posts' ),
);

$response = wp_remote_post( 'http://localhost:8000/wp-json/mcp-ai/v1/chat', array(
    'headers' => array( 'Authorization' => 'Bearer ...' ),
    'body'    => wp_json_encode( array(
        'assistant_id' => 123,
        'messages'     => $messages,
    ) ),
) );
```

#### Token Budgets (New in 1.1.0)
```php
// Test budget enforcement
update_post_meta( 123, '_wp_mcp_ai_token_budget', 100 );
update_post_meta( 123, '_wp_mcp_ai_budget_window', 3600 );

// Verify budget is enforced
$result = WP_MCP_AI_Token_Budget_Manager::check_budget(
    $user_id,
    123,
    $large_message_array
);

// Should return WP_Error when exceeded
```

### 8. Rollback Testing

Test that rollback is possible:

```bash
# Rollback to previous version
git checkout v1.0.0
composer install
npm install
npm run build

# Verify functionality
wp plugin deactivate wp-mcp-ai --path=/tmp/wp-test
wp plugin activate wp-mcp-ai --path=/tmp/wp-test
```

Ensure:
- [ ] Previous version activates without errors
- [ ] Data remains intact
- [ ] No database corruption
- [ ] Features from new version gracefully degraded

## Dependency Management

### PHP Dependencies (composer.json)

**Pinning Strategy:**
```json
{
    "require": {
        "vendor/package": "^2.0",           // Good: Allows 2.x updates
        "vendor/specific": "2.5.3",         // Good: Exact version
        "vendor/dev": "dev-master#abc123"   // Good: Dev branch pinned to commit
    }
}
```

**Avoid:**
```json
{
    "require": {
        "vendor/package": "*",        // Bad: Any version
        "vendor/package": "dev-master" // Bad: Unpinned dev branch
    }
}
```

### JavaScript Dependencies (package.json)

**Current Dependencies:**
```json
{
    "devDependencies": {
        "clean-css-cli": "^5.6.0",           // Locked in package-lock.json
        "uglify-js": "^3.17.0",               // Locked in package-lock.json
        "eslint": "^8.34.0",                  // Locked in package-lock.json
        "@wordpress/eslint-plugin": "^14.1.0" // Locked in package-lock.json
    }
}
```

**Best Practices:**
- Always commit `package-lock.json`
- Run `npm ci` in CI/CD (not `npm install`)
- Audit dependencies: `npm audit`
- Update cautiously: `npm update --save`

### Verifying Reproducible Builds

```bash
# Clean install should produce identical results
rm -rf node_modules vendor
composer install
npm ci

# Compare lock files
git diff composer.lock  # Should be unchanged
git diff package-lock.json  # Should be unchanged
```

## Security Scanning

### Before Each Release

1. **PHP Dependencies**
```bash
# Check for known vulnerabilities
composer audit

# If vulnerabilities found, update affected packages
composer update vendor/package
```

2. **JavaScript Dependencies**
```bash
# Check for vulnerabilities
npm audit

# Auto-fix if possible
npm audit fix

# Manual review for breaking changes
npm audit fix --force
```

3. **Code Analysis**
```bash
# WordPress coding standards
composer run lint

# PHP compatibility (7.4-8.3)
composer run lint:compat

# JavaScript standards
npm run lint:js
```

4. **Manual Security Review**
- Review all user input handling
- Check SQL query sanitization
- Verify nonce usage
- Check capability checks
- Review file upload handling
- Audit API key storage

## Breaking Changes Protocol

When introducing breaking changes:

### 1. Documentation
- Add `BREAKING CHANGE:` in CHANGELOG.md
- Update major version number
- Document migration path
- Provide upgrade script if needed

### 2. Deprecation Process
```php
// Mark old method as deprecated
/**
 * @deprecated 2.0.0 Use new_method() instead.
 */
public function old_method() {
    _deprecated_function( __METHOD__, '2.0.0', 'new_method' );
    return $this->new_method();
}
```

### 3. Migration Support
```php
// Detect old data format and migrate
public function maybe_migrate_data() {
    $version = get_option( 'wp_mcp_ai_db_version' );
    
    if ( version_compare( $version, '2.0.0', '<' ) ) {
        $this->migrate_from_1x_to_2x();
        update_option( 'wp_mcp_ai_db_version', '2.0.0' );
    }
}
```

## Continuous Integration

### GitHub Actions Workflow

```yaml
name: Release Tests

on:
  push:
    tags:
      - 'v*'

jobs:
  test-upgrade:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['7.4', '8.0', '8.1', '8.2', '8.3']
        wordpress: ['6.0', '6.4', 'latest']
    
    steps:
      - name: Checkout
        uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
      
      - name: Install Composer Dependencies
        run: composer install --no-dev --prefer-dist
      
      - name: Install WordPress Test Suite
        run: bash bin/install-wp-tests.sh wordpress_test root '' localhost ${{ matrix.wordpress }}
      
      - name: Run Tests
        run: composer run test
      
      - name: PHP Lint
        run: composer run lint
      
      - name: PHP Compatibility
        run: composer run lint:compat
```

## Test Coverage

### Current Coverage

Run full test suite:
```bash
composer run test
```

**Test Summary:**
- **Total Test Files**: 165+
- **Token Budget Tests**: 8 tests (per-assistant budgets)
- **Rate Limit Tests**: 7 tests (429 error handling)
- **TPM Validation Tests**: Comprehensive TPM limit testing
- **Integration Tests**: REST API, SSE, tool execution
- **Unit Tests**: Individual components and managers

### Coverage by Component

| Component | Test Count | Coverage |
|-----------|-----------|----------|
| Token Budget Manager | 15+ | High |
| Rate Limit Manager | 10+ | High |
| REST API | 20+ | Medium |
| Tool Registry | 30+ | High |
| Authentication | 15+ | High |
| Assistant CPT | 10+ | Medium |
| Chat Service | 15+ | Medium |
| SSE Handler | 8+ | Medium |

### Running Specific Tests

```bash
# Run specific test file
vendor/bin/phpunit tests/test-per-assistant-budget.php

# Run with coverage (requires Xdebug)
vendor/bin/phpunit --coverage-html coverage/

# Run specific test method
vendor/bin/phpunit --filter test_check_budget_passes_when_within_limit
```

## Release Artifacts

### Creating Release Package

```bash
# Clean build
rm -rf node_modules vendor
composer install --no-dev --prefer-dist
npm ci
npm run build

# Create zip
zip -r wp-mcp-ai-vX.Y.Z.zip \
    wp-mcp-ai.php \
    includes/ \
    assets/ \
    languages/ \
    vendor/ \
    readme.txt \
    LICENSE \
    CHANGELOG.md \
    -x "*.git*" "*/node_modules/*" "*/tests/*"
```

## Post-Release

- [ ] Monitor error logs
- [ ] Check GitHub issues
- [ ] Monitor WordPress.org support forum
- [ ] Update documentation site
- [ ] Announce release (blog, Twitter, etc.)
- [ ] Archive old versions

## Troubleshooting Upgrades

### Database Migration Fails

```bash
# Check migration status
wp option get wp_mcp_ai_db_version

# Manually trigger migration
wp eval 'WP_MCP_AI_Upgrader::run_migrations();'
```

### Composer Dependencies Conflict

```bash
# Clear composer cache
composer clear-cache

# Remove lock and reinstall
rm composer.lock
composer install
```

### Asset Build Fails

```bash
# Clean and rebuild
rm -rf node_modules package-lock.json
npm install
npm run build
```

## Support & Resources

- **Documentation**: `/docs` directory
- **Changelog**: `CHANGELOG.md`
- **Security**: `SECURITY.md`
- **Build Process**: `BUILD.md`
- **Contributing**: `CONTRIBUTING.md`

## License

GPL-3.0-or-later
