# NV oOS Tool Registry Context

> **GSD Context File** — Load this when working on tool implementations.
> Last reviewed: March 2026.

---

## Tool Registry Overview

Tools are the core extensibility unit of NV oOS. Each tool:
- Extends `WP_MCP_AI_Tool_Base` (or `WP_MCP_AI_Tool_Interface`)
- Has a unique slug (snake_case)
- Declares a `required_capability`
- Implements `execute( $arguments, $context )`
- Is registered in `includes/tools-init.php` (base) or `addons/pro/mcp-ai-wpoos-pro.php` (pro)

**Total tools:** 519+ (165 base + 348 pro + 6 core/memory)

---

## File Locations

| Type | Directory | Registration File |
|------|-----------|------------------|
| Base tools | `includes/tools/class-wp-mcp-ai-tool-{name}.php` | `includes/tools-init.php` |
| Pro tools | `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-{name}.php` | `addons/pro/mcp-ai-wpoos-pro.php` |
| Pro categorized | `addons/pro/includes/tools/{category}/class-wp-mcp-ai-tool-{name}.php` | `addons/pro/mcp-ai-wpoos-pro.php` |

---

## Minimal Tool Skeleton

```php
<?php
/**
 * Tool: {slug} — {Brief description}.
 *
 * @package MCP_AI_WPooS
 * @since   1.x.x
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * {ClassName} — {Brief description}.
 *
 * @since 1.x.x
 */
class WP_MCP_AI_Tool_{Name} extends WP_MCP_AI_Tool_Base {

    /**
     * Returns the tool slug.
     *
     * @return string
     */
    public function get_slug() {
        return '{slug}';
    }

    /**
     * Returns the tool definition for the LLM.
     *
     * @return array
     */
    public function get_definition() {
        return array(
            'name'                => '{Display Name}',
            'description'         => '{Description for the LLM model}.',
            'required_capability' => '{wp_capability}',
            'parameters'          => array(
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
     * @param array $arguments Tool arguments from the LLM.
     * @param array $context   Execution context (user_id, assistant, etc.).
     * @return array|WP_Error Result array or WP_Error on failure.
     */
    public function execute( $arguments, $context ) {
        if ( ! current_user_can( $this->get_required_capability() ) ) {
            return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
        }

        $action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';

        switch ( $action ) {
            case 'create':
                return $this->handle_create( $arguments );
            case 'list':
                return $this->handle_list( $arguments );
            case 'delete':
                return $this->handle_delete( $arguments );
            default:
                return new WP_Error( 'invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos' ) );
        }
    }
}
```

---

## Registering a Base Tool

In `includes/tools-init.php`:

```php
// Inside the registration function:
require_once WP_MCP_AI_PLUGIN_DIR . 'includes/tools/class-wp-mcp-ai-tool-{name}.php';
$registry->register_tool( 'WP_MCP_AI_Tool_{Name}' );
```

## Registering a Pro Tool

In `addons/pro/mcp-ai-wpoos-pro.php`, in the class loader section and the tool group map:

```php
// Class loader (load_tool_classes method):
'WP_MCP_AI_Tool_{Name}' => 'includes/tools/class-wp-mcp-ai-tool-{name}.php',

// Tool group map:
'tool_slug' => array(
    'class' => 'WP_MCP_AI_Tool_{Name}',
    'group' => 'wordpress-core',  // or other group
),
```

---

## Base Version Guard

Pro-only tools must be wrapped:

```php
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION ) {
    // Register pro tool
}
```

Or check the toolkit enable flag:

```php
if ( $this->is_toolkit_enabled( 'enable_crm_toolkit' ) ) {
    // Register CRM tools
}
```

---

## Tool Return Format

Tools return **exactly one of two shapes** — the canonical envelope (see [`CLAUDE.md`](../CLAUDE.md#tool-return-format--canonical-envelope) and [`.context/conventions.md`](conventions.md#tool-return-envelope-canonical)):

```php
// Success:
return array(
    'success' => true,
    'message' => __( 'Done.', 'mcp-ai-wpoos' ),
    'data'    => $results,
);

// Error (use WP_Error — never `success => false`):
return new WP_Error( 'not_found', __( 'Resource not found.', 'mcp-ai-wpoos' ) );
```

For success responses, prefer the helper `format_success_response( $message, $data )` from [`trait-wp-mcp-ai-tool-chat-response.php`](../includes/tools/trait-wp-mcp-ai-tool-chat-response.php) — `use WP_MCP_AI_Tool_Chat_Response;` in the tool class.

Returning `array( 'success' => false, ... )` for errors is forbidden in new code; observability subscribers (`wp_mcp_ai_after_tool_execution`, OTel, audit log, token tracking) rely on `is_wp_error( $result )` to classify outcomes.

---

## Parameter Types Reference

```php
// String parameter:
array(
    'type'        => 'string',
    'description' => 'Description.',
)

// Integer parameter:
array(
    'type'        => 'integer',
    'description' => 'Numeric ID.',
    'minimum'     => 1,
)

// Boolean parameter:
array(
    'type'        => 'boolean',
    'description' => 'Whether to include X.',
    'default'     => false,
)

// Enum parameter:
array(
    'type'        => 'string',
    'description' => 'Action to perform.',
    'enum'        => array( 'create', 'list', 'delete' ),
)

// Array parameter:
array(
    'type'        => 'array',
    'description' => 'List of IDs.',
    'items'       => array( 'type' => 'integer' ),
)
```

---

## Capability Reference for Tools

| Tool Type | Recommended Capability |
|-----------|----------------------|
| Read-only public | `read` |
| Create/edit content | `edit_posts` |
| Delete content | `delete_posts` |
| Manage settings | `manage_options` |
| User management | `manage_options` |
| Medical/healthcare | Custom cap via plugin |

---

## Common Sanitization in Tool execute()

```php
$name     = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
$post_id  = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
$content  = isset( $arguments['content'] ) ? wp_kses_post( $arguments['content'] ) : '';
$url      = isset( $arguments['url'] ) ? esc_url_raw( $arguments['url'] ) : '';
$action   = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';
```
