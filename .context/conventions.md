# NV oOS Coding Conventions

> **GSD Context File** — Load this at the start of every AI development session.
> Keep this file under 500 lines. Last reviewed: March 2026.

---

## PHP Compatibility Requirements

NV oOS has **two PHP version targets** — always match the target to the code location:

| Distribution | Minimum PHP | Why |
|-------------|-------------|-----|
| **Base plugin** (`includes/`, `mcp-ai-wpoos.php`) | **PHP 7.4+** | WordPress.org compatibility; widest host support |
| **Pro addon** (`addons/pro/`) | **PHP 8.1+** | Uses enums, fibers, `readonly` properties, named args, intersection types |

### Practical Rules

- **Base plugin code:** must run on PHP 7.4. No enums, no named arguments, no `readonly`, no union types `int|string` (use PHPDoc instead).
- **Pro addon code:** may use PHP 8.1+ features freely.
- The `composer run lint:compat` check covers PHP 7.4–8.3 for the base plugin.
- `CONTRIBUTING.md` asks contributors to have **PHP 8.1+** locally — this is so they can work on both tiers without switching runtimes.
- When writing a PRD/Architecture Spec, the "Compatibility" field should read **PHP 7.4+** for base features or **PHP 8.1+** for Pro features.

---

## Class & Function Naming

| Type | Convention | Example |
|------|-----------|---------|
| PHP Classes | `WP_MCP_AI_{FeatureName}_{Component}` | `WP_MCP_AI_Tool_Manage_Redirects` |
| Tool Classes | `WP_MCP_AI_Tool_{ToolName}` | `WP_MCP_AI_Tool_Log_Vital_Signs` |
| PHP Functions | `wp_mcp_ai_{function_name}()` | `wp_mcp_ai_get_assistant()` |
| Action Hooks | `wp_mcp_ai_{hook_name}` | `wp_mcp_ai_register_tools` |
| Filter Hooks | `wp_mcp_ai_{filter_name}` | `wp_mcp_ai_tool_response` |
| Nonces | `wp_mcp_ai_{context}_{action}` | `wp_mcp_ai_assistant_save` |
| Option Keys | `wp_mcp_ai_{setting_name}` | `wp_mcp_ai_settings` |
| CPT Slugs | `mcp_ai_{post_type}` | `mcp_ai_assistant` |
| CCT Slugs | `{descriptive_name}` | `vitals_log`, `channel_messages` |

## File Organization

```
includes/
├── tools/class-wp-mcp-ai-tool-{name}.php    # Base tools (165 core)
├── admin/class-wp-mcp-ai-{component}.php    # Admin UI classes
├── class-wp-mcp-ai-{component}.php          # Core classes
addons/pro/includes/
├── tools/class-wp-mcp-ai-pro-tool-{name}.php  # Pro tools (348+)
├── tools/{category}/class-wp-mcp-ai-tool-{name}.php  # Categorized pro tools
```

## PHP Code Style

- **Standard:** WordPress Coding Standards (WPCS) — zero violations
- **Indentation:** Tabs (not spaces)
- **Braces:** Opening brace on same line for functions/methods, Allman style for classes
- **Line length:** 120 characters max
- **PHP tags:** Full `<?php` only, never short tags

## PHPDoc Requirements

Every class, method, and function **must** have a PHPDoc block:

```php
/**
 * Brief description of what this does.
 *
 * Longer description if needed.
 *
 * @since 1.x.x
 * @param string $param_name Description of parameter.
 * @param int    $another     Another parameter.
 * @return array|WP_Error Result or error.
 */
```

## Security Requirements (Always Apply)

### Input Sanitization
```php
sanitize_text_field( $input )      // General strings
absint( $input )                    // Positive integers
intval( $input )                    // Any integers
sanitize_email( $input )            // Email addresses
esc_url_raw( $input )               // URLs for storage
wp_kses_post( $input )              // HTML content (post-safe)
wp_kses( $input, $allowed_html )    // HTML content (custom allowed)
sanitize_key( $input )              // Option keys, slugs
```

### Output Escaping
```php
esc_html( $value )                  // Plain text output
esc_attr( $value )                  // HTML attribute values
esc_url( $url )                     // URLs in href/src
esc_js( $value )                    // Inline JavaScript
wp_json_encode( $data )             // JSON output
wp_kses_post( $content )            // HTML content output
```

### Capability Checks
```php
if ( ! current_user_can( 'manage_options' ) ) {
    return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
}
```

### Nonce Verification
```php
// In form/AJAX handler:
check_ajax_referer( 'wp_mcp_ai_assistant_save', 'nonce' );
// Or:
if ( ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wp_mcp_ai_action' ) ) {
    wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos' ) );
}
```

### ABSPATH Guard (All Non-Root PHP Files)
```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

## WordPress APIs

### Data Retrieval (Always Use WP APIs)
```php
get_option( 'wp_mcp_ai_settings', array() )
get_post_meta( $post_id, '_meta_key', true )
get_posts( array( 'post_type' => 'mcp_ai_assistant', ... ) )
```

### Internationalization
```php
__( 'String', 'mcp-ai-wpoos' )
esc_html__( 'String', 'mcp-ai-wpoos' )
_e( 'String', 'mcp-ai-wpoos' )
sprintf( __( 'Hello %s', 'mcp-ai-wpoos' ), esc_html( $name ) )
```

### Database (Direct Queries via $wpdb)
```php
global $wpdb;
$results = $wpdb->get_results(
    $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id )
);
```

## Tool Implementation Pattern

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tool: example_tool — Brief description.
 *
 * @package MCP_AI_WPooS
 * @since   1.x.x
 */
class WP_MCP_AI_Tool_Example_Tool extends WP_MCP_AI_Tool_Base {

    /**
     * Returns the tool slug.
     *
     * @return string
     */
    public function get_slug() {
        return 'example_tool';
    }

    /**
     * Returns the tool definition.
     *
     * @return array
     */
    public function get_definition() {
        return array(
            'name'                 => 'Example Tool',
            'description'          => 'Tool description for the LLM.',
            'required_capability'  => 'edit_posts',
            'parameters'           => array(
                'type'       => 'object',
                'properties' => array(
                    'action' => array(
                        'type'        => 'string',
                        'description' => 'Action to perform.',
                        'enum'        => array( 'create', 'list', 'delete' ),
                    ),
                ),
                'required' => array( 'action' ),
            ),
        );
    }

    /**
     * Executes the tool.
     *
     * @param array $arguments Tool arguments.
     * @param array $context   Execution context.
     * @return array|WP_Error
     */
    public function execute( $arguments, $context ) {
        if ( ! current_user_can( $this->get_required_capability() ) ) {
            return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
        }
        // Implementation...
    }
}
```

## Commit Message Convention

```
feat(scope): brief description
fix(scope): brief description
docs(scope): brief description
test(scope): brief description
refactor(scope): brief description
chore(scope): brief description
```

Examples:
- `feat(tools): add manage_redirects tool with create/list/delete actions`
- `fix(rest-api): sanitize assistant_id in chat endpoint`
- `test(tools): add PHPUnit tests for manage_redirects tool`

## Version Locations (Must All Match)

When bumping the version, update ALL of these:
1. `mcp-ai-wpoos.php` — plugin header `Version:`
2. `mcp-ai-wpoos-base.php` — plugin header `Version:` (if exists)
3. `composer.json` — `"version"` field
4. `package.json` — `"version"` field
5. Constant: `WP_MCP_AI_VERSION`
