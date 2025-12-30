# Security Checks Documentation

## Overview

The WP oOS plugin includes comprehensive automated security checks that run on every push, pull request, and weekly via GitHub Actions. The security workflow provides multi-layered analysis to identify vulnerabilities, unsafe coding patterns, and potential security risks.

## Security Badge

[![Security Checks](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/security.yml/badge.svg)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/security.yml)

The security badge on the README shows the current status of all security checks. Click the badge to view detailed results.

## Security Checks Performed

### 1. Dependency Vulnerability Scanning

**What it checks:**
- Composer dependencies for known vulnerabilities (via `composer audit`)
- npm dependencies for high/critical vulnerabilities (via `npm audit`)

**Why it matters:**
Third-party dependencies can contain security vulnerabilities. Regular scanning ensures we catch and address these issues promptly.

**How to fix issues:**
```bash
# Update vulnerable Composer packages
composer update [package-name]

# Update vulnerable npm packages
npm audit fix
```

### 2. PHP Static Analysis

**What it checks:**
- Code quality and type safety issues using PHPStan
- Potential bugs that could lead to security issues
- Undefined variables, methods, and properties

**Why it matters:**
Static analysis catches programming errors that could lead to security vulnerabilities, crashes, or unexpected behavior.

**How to fix issues:**
```bash
# Run PHPStan locally
composer require --dev phpstan/phpstan phpstan/phpstan-wordpress
vendor/bin/phpstan analyse
```

### 3. WordPress-Specific Security Checks

#### SQL Injection Prevention

**What it checks:**
- Direct use of `$wpdb->query()` without `prepare()`
- Raw SQL queries that could be vulnerable to injection

**Why it matters:**
SQL injection is a critical vulnerability that allows attackers to manipulate database queries.

**Best practice:**
```php
// ❌ Bad - Vulnerable to SQL injection
$wpdb->query( "SELECT * FROM table WHERE id = {$_GET['id']}" );

// ✅ Good - Using prepare()
$wpdb->query( $wpdb->prepare( "SELECT * FROM table WHERE id = %d", $_GET['id'] ) );
```

#### XSS (Cross-Site Scripting) Prevention

**What it checks:**
- Direct output of superglobals ($_GET, $_POST, $_REQUEST, $_SERVER)
- Echo or print statements without escaping

**Why it matters:**
XSS vulnerabilities allow attackers to inject malicious JavaScript into your site.

**Best practice:**
```php
// ❌ Bad - Vulnerable to XSS
echo $_GET['name'];

// ✅ Good - Properly escaped
echo esc_html( $_GET['name'] );
```

#### Nonce Verification

**What it checks:**
- AJAX handlers that register `wp_ajax_*` actions
- Presence of `wp_verify_nonce()` or `check_ajax_referer()` calls

**Why it matters:**
Nonces prevent CSRF (Cross-Site Request Forgery) attacks by verifying requests came from your site.

**Best practice:**
```php
// AJAX handler
add_action( 'wp_ajax_my_action', 'handle_my_action' );

function handle_my_action() {
    // ✅ Always verify nonce first
    check_ajax_referer( 'my_action_nonce', 'security' );
    
    // Process request...
}
```

#### Hardcoded Secrets Detection

**What it checks:**
- API keys, passwords, tokens, and secrets in code
- Common patterns for hardcoded credentials

**Why it matters:**
Hardcoded secrets in version control can be exposed and exploited.

**Best practice:**
```php
// ❌ Bad - Hardcoded API key
define( 'API_KEY', 'sk_live_abc123...' );

// ✅ Good - Use environment variables
define( 'API_KEY', getenv('OPENAI_API_KEY') );

// ✅ Good - Use wp-config.php constants
if ( defined( 'WP_MCP_AI_OPENAI_API_KEY' ) ) {
    $api_key = WP_MCP_AI_OPENAI_API_KEY;
}
```

#### Dangerous Function Detection

**What it checks:**
- Usage of `eval()`
- System command execution (`exec()`, `system()`, `shell_exec()`, `passthru()`)

**Why it matters:**
These functions can be dangerous if not properly sanitized and may indicate security risks.

**Best practice:**
```php
// ❌ Avoid - eval() is dangerous
eval( $_GET['code'] );

// ✅ Better - Use specific functions
if ( $_GET['action'] === 'calculate' ) {
    $result = calculate_something();
}

// For system commands in Pro addon - use Symfony Process with validation
// See: includes/services/class-wp-mcp-ai-process-service.php
```

## Workflow Schedule

The security checks run:
- **On every push** to the `main` branch
- **On every pull request**
- **Weekly** on Mondays at 00:00 UTC (scheduled scan)
- **Manually** via GitHub Actions workflow dispatch

## Viewing Security Results

### In GitHub Actions

1. Navigate to the repository's [Actions tab](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions)
2. Click on the "Security Checks" workflow
3. Select a specific run to view detailed results
4. Check the "Summary" tab for a comprehensive security report

### Artifacts

Security scan results are uploaded as artifacts and retained for 30 days:
- `audit-results` - Composer and npm audit JSON reports
- `phpstan-results` - PHPStan analysis output

### Step Summary

Each security workflow run generates a summary table showing:
- Status of each security check (✅ pass, ⚠️ warning)
- Number of issues found
- Overall security status
- Timestamp of scan completion

## Continuous Monitoring

### Weekly Scans

Automated weekly scans ensure:
- New vulnerabilities in dependencies are caught promptly
- Code changes don't introduce security issues
- Dependency updates are flagged if they introduce risks

### Scheduled Maintenance

Review security scan results weekly:
1. Check for new dependency vulnerabilities
2. Update vulnerable packages
3. Review any new warnings from static analysis
4. Address WordPress-specific security issues

## Integration with Development Workflow

### Pre-commit Checks

Run security checks locally before committing:

```bash
# Check dependencies
composer audit
npm audit

# Run static analysis
vendor/bin/phpstan analyse

# Scan for common security patterns
grep -rn "eval\|system\|exec" includes/ addons/
```

### Pull Request Reviews

All pull requests automatically trigger security checks. Review:
- Security workflow status (must pass)
- Any new warnings or issues introduced
- Changes to security-sensitive code areas

## False Positives

Some security warnings may be false positives. In such cases:

1. **Document the exception** - Add a code comment explaining why it's safe
2. **Use exclusions carefully** - Only exclude specific files/patterns if truly necessary
3. **Request review** - Have another developer verify it's a false positive
4. **Track in issues** - Document persistent false positives

## Security Contacts

For security vulnerabilities:
- **Email:** security@nvdigitalsolutions.com
- **See:** [SECURITY.md](../../SECURITY.md) for reporting guidelines

## Additional Security Resources

- [WordPress Security Handbook](https://developer.wordpress.org/apis/security/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [Plugin Security Best Practices](../../guides/developer/best-practices/BEST_PRACTICES.md)
- [Root Security Key Documentation](root-security-key.md)
- [Security Hardening Guide](SECURITY_HARDENING.md)
- [Master Key Rotation](master-key-rotation.md)

## Frequently Asked Questions

### Q: What happens if a security check fails?

A: The workflow will show a failed status, but it won't block merges automatically. Review the failure, assess the severity, and address issues appropriately before merging.

### Q: Can I run security checks locally?

A: Yes! All checks can be run locally using the commands documented above. This is recommended before pushing changes.

### Q: How do I exclude false positives?

A: Update the workflow patterns in `.github/workflows/security.yml` to exclude specific files or patterns. Document why exclusions are necessary.

### Q: What's the difference between warnings and errors?

A: 
- **Errors** - Critical issues that should be fixed immediately
- **Warnings** - Potential issues that need review but may be acceptable
- The workflow currently treats both as informational to avoid blocking development

### Q: How do I update the security checks?

A: Edit `.github/workflows/security.yml` and adjust:
- Scan patterns
- Threshold values
- New check types
- Schedule frequency

### Q: Are there any performance impacts?

A: Security checks only run in CI/CD and have no impact on plugin performance. They typically complete in 2-5 minutes.

## Changelog

- **2025-12-29** - Initial security workflow implementation
  - Dependency scanning (Composer + npm)
  - PHP static analysis with PHPStan
  - WordPress-specific security checks
  - Weekly automated scans
  - Security badge added to README

---

**Last Updated:** 2025-12-29  
**Maintainer:** NV Digital Solutions  
**Related:** [SECURITY.md](../../SECURITY.md), [Security Hardening](SECURITY_HARDENING.md)
