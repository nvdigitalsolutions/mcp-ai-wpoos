# WordPress Development Best Practices Guide

This guide documents coding standards and best practices for the WP oOS plugin.

## Table of Contents

1. [Code Standards](#code-standards)
2. [Security Guidelines](#security-guidelines)
3. [Performance Best Practices](#performance-best-practices)
4. [Testing Requirements](#testing-requirements)
5. [Git Workflow](#git-workflow)
6. [Documentation Standards](#documentation-standards)

---

## Code Standards

### PHP Coding Standards

**Follow WordPress Coding Standards**

```bash
# Check code standards
composer lint

# Auto-fix issues
composer format
```

#### Variable Naming

✅ **DO:** Use snake_case
```php
$user_id = get_current_user_id();
$post_count = wp_count_posts( 'post' );
```

❌ **DON'T:** Use camelCase
```php
$userId = get_current_user_id();        // Wrong
$postCount = wp_count_posts( 'post' );  // Wrong
```

#### File Headers

✅ **DO:** Include @package tag
```php
<?php
/**
 * Description of file purpose.
 *
 * @package WP_MCP_AI
 */
```

#### Indentation

✅ **DO:** Use tabs for indentation
```php
if ( $condition ) {
	do_something();
}
```

❌ **DON'T:** Use spaces
```php
if ( $condition ) {
    do_something();  // Wrong - uses spaces
}
```

### JavaScript Coding Standards

**Use ESLint with WordPress config**

```bash
# Install dependencies
npm install

# Check JavaScript
npm run lint:js

# Auto-fix issues
npm run lint:js:fix
```

#### Variable Declaration

✅ **DO:** Use const/let
```javascript
const apiKey = config.apiKey;
let messageCount = 0;
```

❌ **DON'T:** Use var
```javascript
var apiKey = config.apiKey;  // Wrong
```

#### JSDoc Comments

✅ **DO:** Document functions
```javascript
/**
 * Initialize chat interface
 * 
 * @param {string} containerId - DOM container ID
 * @param {Object} config - Configuration object
 * @returns {Object} Chat instance
 */
function initChat( containerId, config ) {
	// Implementation
}
```

---

## Security Guidelines

### Input Validation

✅ **DO:** Sanitize ALL user input
```php
$user_input = sanitize_text_field( $_POST['field'] );
$email = sanitize_email( $_POST['email'] );
$url = esc_url_raw( $_POST['url'] );
$int = absint( $_POST['number'] );
```

❌ **DON'T:** Use raw input
```php
$user_input = $_POST['field'];  // Wrong - no sanitization
```

### Output Escaping

✅ **DO:** Escape ALL output
```php
echo esc_html( $user_data );
echo esc_attr( $attribute );
echo esc_url( $link );
echo wp_kses_post( $html_content );
```

❌ **DON'T:** Output raw data
```php
echo $user_data;  // Wrong - no escaping
```

### Nonce Verification

✅ **DO:** Verify nonces for forms
```php
// Create nonce
wp_nonce_field( 'my_action', 'my_nonce' );

// Verify nonce
if ( ! isset( $_POST['my_nonce'] ) || ! wp_verify_nonce( $_POST['my_nonce'], 'my_action' ) ) {
	wp_die( 'Security check failed' );
}
```

### Capability Checks

✅ **DO:** Check user capabilities
```php
if ( ! current_user_can( 'edit_posts' ) ) {
	wp_die( 'Insufficient permissions' );
}
```

### SQL Queries

✅ **DO:** Use prepared statements
```php
global $wpdb;
$results = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT * FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
		$post_type,
		$status
	)
);
```

❌ **DON'T:** Use raw SQL
```php
$results = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type = '$post_type'" );  // Wrong
```

### API Keys

✅ **DO:** Store in environment or wp-config
```php
// wp-config.php
define( 'WP_MCP_AI_OPENAI_API_KEY', getenv('OPENAI_API_KEY') );

// Or use WordPress options with encryption
$encrypted_key = wp_hash( $api_key );
```

❌ **DON'T:** Hardcode in source
```php
$api_key = 'sk-1234567890abcdef';  // Wrong - exposed in repo
```

---

## Performance Best Practices

### Caching

✅ **DO:** Cache expensive operations
```php
// Transient caching (temporary)
$cache_key = 'wp_mcp_ai_data_' . $id;
$data = get_transient( $cache_key );

if ( false === $data ) {
	$data = expensive_operation();
	set_transient( $cache_key, $data, HOUR_IN_SECONDS );
}

// Object caching (per-request)
$cache_key = 'assistant_tools_' . $assistant_id;
$tools = wp_cache_get( $cache_key, 'wp_mcp_ai' );

if ( false === $tools ) {
	$tools = get_assistant_tools( $assistant_id );
	wp_cache_set( $cache_key, $tools, 'wp_mcp_ai' );
}
```

### Database Queries

✅ **DO:** Use efficient queries
```php
// Good - specific fields, limited results
$posts = get_posts( array(
	'post_type'      => 'ai_assistant',
	'posts_per_page' => 10,
	'fields'         => 'ids',
	'no_found_rows'  => true,
) );
```

❌ **DON'T:** Query all posts
```php
$posts = get_posts( array(
	'post_type'      => 'ai_assistant',
	'posts_per_page' => -1,  // Wrong - gets everything
) );
```

### Asset Loading

✅ **DO:** Enqueue scripts conditionally
```php
function wp_mcp_ai_enqueue_scripts() {
	// Only load on chat pages
	if ( ! is_page( 'chat' ) ) {
		return;
	}
	
	wp_enqueue_script(
		'wp-mcp-ai-chat',
		WP_MCP_AI_URL . 'assets/js/chat.js',
		array( 'jquery' ),
		WP_MCP_AI_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'wp_mcp_ai_enqueue_scripts' );
```

---

## Testing Requirements

### Unit Tests

✅ **DO:** Write tests for all new code
```php
class Test_My_Feature extends WP_UnitTestCase {
	/**
	 * Test description
	 */
	public function test_feature_works_correctly() {
		$result = my_feature( 'input' );
		$this->assertEquals( 'expected', $result );
	}
}
```

### Running Tests

```bash
# Run all tests
composer test

# Run specific test
vendor/bin/phpunit tests/test-my-feature.php

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/
```

### Test Coverage Goals

- **Core classes:** >90%
- **Tools:** >80%
- **REST endpoints:** >85%
- **Overall:** >80%

---

## Git Workflow

### Commit Messages

✅ **DO:** Write clear, descriptive commits
```bash
git commit -m "Add caching for assistant tool queries"
git commit -m "Fix XSS vulnerability in chat output"
git commit -m "Refactor WP_MCP_AI_REST class into smaller components"
```

❌ **DON'T:** Use vague messages
```bash
git commit -m "fix stuff"          # Wrong
git commit -m "updates"            # Wrong
git commit -m "WIP"                # Wrong
```

### Branch Naming

✅ **DO:** Use descriptive branch names
```bash
git checkout -b feature/add-caching-layer
git checkout -b fix/security-xss-chat
git checkout -b refactor/split-rest-class
```

### Pull Requests

✅ **DO:** Include in PR description:
- What changed
- Why it changed
- How to test
- Related issues

```markdown
## Description
Add transient caching for assistant tool queries to improve performance.

## Changes
- Added caching layer in WP_MCP_AI_Assistant_CPT
- Cache expires after 1 hour
- Added cache invalidation on post save

## Testing
1. Load assistant edit page
2. Check query count with Query Monitor
3. Verify cache is used on subsequent loads

## Related Issues
Fixes #123
```

---

## Documentation Standards

### PHPDoc Comments

✅ **DO:** Document all public methods
```php
/**
 * Retrieve assistant configuration.
 *
 * Returns the complete configuration array for the specified assistant,
 * including tools, defaults, and knowledge base settings.
 *
 * @since 1.0.0
 *
 * @param int $assistant_id Assistant post ID.
 * @return array|WP_Error Assistant configuration or error.
 */
public function get_assistant_config( $assistant_id ) {
	// Implementation
}
```

### Inline Comments

✅ **DO:** Explain complex logic
```php
// Check if the user has permission to access this assistant.
// Public assistants (capability = 'public') skip the check.
$capability = $this->get_assistant_capability( $assistant_id );
if ( 'public' !== $capability && ! current_user_can( $capability ) ) {
	return new WP_Error( 'insufficient_permissions', __( 'You do not have permission to access this assistant.', 'wp-mcp-ai' ) );
}
```

❌ **DON'T:** State the obvious
```php
// Increment counter
$counter++;  // Wrong - comment doesn't add value
```

### README Updates

✅ **DO:** Update docs with new features
- Add to relevant section
- Include code examples
- Document requirements
- Add to changelog

---

## Code Review Checklist

Before submitting code for review, ensure:

- [ ] Code follows WordPress coding standards (`composer lint` passes)
- [ ] All user input is sanitized
- [ ] All output is escaped
- [ ] Nonces are verified for forms
- [ ] Capabilities are checked
- [ ] SQL uses prepared statements
- [ ] Tests are written and passing
- [ ] Documentation is updated
- [ ] No credentials in code
- [ ] Performance impact is minimal
- [ ] Backwards compatibility maintained

---

## Pre-Commit Hook

Add this to `.git/hooks/pre-commit`:

```bash
#!/bin/sh

echo "Running pre-commit checks..."

# PHP linting
composer lint
if [ $? -ne 0 ]; then
    echo "❌ PHP linting failed. Please fix errors before committing."
    echo "Run: composer format"
    exit 1
fi

# JavaScript linting
npm run lint:js
if [ $? -ne 0 ]; then
    echo "❌ JavaScript linting failed. Please fix errors before committing."
    echo "Run: npm run lint:js:fix"
    exit 1
fi

# Run tests
composer test
if [ $? -ne 0 ]; then
    echo "❌ Tests failed. Please fix before committing."
    exit 1
fi

echo "✅ All pre-commit checks passed!"
exit 0
```

Make it executable:
```bash
chmod +x .git/hooks/pre-commit
```

---

## Common Pitfalls to Avoid

### Security

❌ **NEVER:**
- Use `eval()` on user input
- Trust `$_GET`, `$_POST`, `$_REQUEST` without sanitization
- Output data without escaping
- Skip nonce verification
- Hardcode credentials
- Use `stripslashes()` on user input (WordPress handles this)

### Performance

❌ **AVOID:**
- N+1 query problems
- Loading all posts with `posts_per_page => -1`
- Not using caching for expensive operations
- Loading assets on every page when not needed

### Code Quality

❌ **DON'T:**
- Write functions longer than 50 lines
- Create classes larger than 500 lines
- Use magic numbers (use constants)
- Duplicate code (use helpers/traits)

---

## Resources

### WordPress Documentation
- [Plugin Handbook](https://developer.wordpress.org/plugins/)
- [Coding Standards](https://developer.wordpress.org/coding-standards/)
- [Security Handbook](https://developer.wordpress.org/apis/security/)
- [Common APIs](https://developer.wordpress.org/apis/)

### Tools
- [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer)
- [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards)
- [ESLint](https://eslint.org/)
- [PHPUnit](https://phpunit.de/)

### This Project
- [Code Review](guides/developer/best-practices/CODE-REVIEW-MASTER.md)
- [Security Policy](../SECURITY.md)
- [Action Items](guides/developer/planning/ACTION_ITEMS.md)

---

**Last Updated:** November 2, 2024  
**Maintained By:** NV Digital Solutions Development Team
