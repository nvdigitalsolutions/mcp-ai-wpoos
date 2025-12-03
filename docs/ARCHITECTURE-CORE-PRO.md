# WP oOS - Core/Pro Architecture

This document describes the architecture split between WP oOS Core and WP oOS Pro.

## Repository Structure

```
wp-mcp-ai/
├── core/                           # Core plugin (GPL-3.0+)
│   ├── wp-mcp-ai-core.php         # Main plugin file
│   ├── includes/
│   │   └── src/
│   │       ├── Interfaces/        # Core interfaces
│   │       ├── Server/            # MCP server engine
│   │       ├── Tools/             # Baseline tools
│   │       └── API/               # Public API classes
│   ├── assets/
│   │   ├── js/                    # Frontend JavaScript
│   │   └── css/                   # Stylesheets
│   ├── LICENSE                    # GPL-3.0-or-later
│   ├── README.md                  # Developer documentation
│   └── readme.txt                 # WordPress.org readme
│
├── addons/
│   └── pro/                       # Pro add-on (Proprietary)
│       ├── wp-mcp-ai-pro.php     # Main plugin file
│       ├── includes/
│       │   ├── src/
│       │   │   └── Tools/        # Pro tools
│       │   └── admin/            # Admin UI
│       └── assets/
│           ├── js/
│           └── css/
│
├── shared/                        # Shared utilities (GPL-3.0+)
│   └── src/
│       └── shared-utilities.php   # Common helper functions
│
└── docs/                          # Documentation
    ├── FEATURE-MATRIX-CORE-PRO.md
    └── ARCHITECTURE-CORE-PRO.md
```

## Plugin Dependencies

```
WP oOS Pro
    └── requires: WP oOS Core
```

Pro checks for Core on activation and initialization:

```php
// In wp-mcp-ai-pro.php
function wp_mcp_ai_pro_init() {
    if ( ! function_exists( 'wp_mcp_ai_core_loaded' ) ) {
        add_action( 'admin_notices', 'wp_mcp_ai_pro_missing_core_notice' );
        return;
    }
    
    // Register Pro tools when Core fires its action
    add_action( 'wp_mcp_ai_register_tools', 'wp_mcp_ai_pro_register_tools', 20 );
}
add_action( 'plugins_loaded', 'wp_mcp_ai_pro_init', 15 );
```

## Public API

### Core API Functions

```php
// Check if Core is loaded
wp_mcp_ai_core_loaded() : bool

// Register a tool
wp_mcp_ai_register_tool( WP_MCP_AI_Core_Tool_Interface $tool ) : bool

// Get a tool by slug
wp_mcp_ai_get_tool( string $slug ) : ?WP_MCP_AI_Core_Tool_Interface

// Get all registered tools
wp_mcp_ai_get_registered_tools() : array

// Execute a tool
wp_mcp_ai_execute_tool( string $slug, array $arguments, array $context ) : mixed
```

### Core Hooks

```php
// Tool registration
do_action( 'wp_mcp_ai_register_tools', $server );

// After initialization
do_action( 'wp_mcp_ai_core_init' );

// Permission check
$can = apply_filters( 'wp_mcp_ai_can_run_tool', $can, $tool, $args, $user );

// Rate limiting
$allow = apply_filters( 'wp_mcp_ai_rate_limit_allow', $allow, $slug, $user, $context );
```

## Tool Interface

All tools implement `WP_MCP_AI_Core_Tool_Interface`:

```php
interface WP_MCP_AI_Core_Tool_Interface {
    public function get_slug();
    public function get_name();
    public function get_description();
    public function get_parameters_schema();
    public function execute( array $arguments = array(), array $context = array() );
}
```

Optional interfaces for additional features:

```php
// Capability flags for orchestration
interface WP_MCP_AI_Core_Tool_Capability_Flags_Interface {
    public function get_capability_flags();
}

// Tool-specific rules
interface WP_MCP_AI_Core_Tool_Rules_Interface {
    public function get_tool_rules();
}
```

## Tool Registration Flow

```
1. WordPress loads → plugins_loaded action

2. Core initializes (priority 10):
   - Create WP_MCP_AI_Core_Server instance
   - Register baseline tools (posts, media, users, taxonomies)
   - Fire 'wp_mcp_ai_register_tools' action

3. Pro initializes (priority 15):
   - Check if Core is loaded
   - Hook into 'wp_mcp_ai_register_tools' at priority 20
   - Register Pro tools (woo_products, woo_orders, etc.)

4. Third-party plugins can also hook in:
   add_action( 'wp_mcp_ai_register_tools', 'my_register_tools', 30 );
```

## REST API Endpoints

### Core Endpoints

```
GET  /wp-json/mcp-ai-core/v1/tools        # List all tools
POST /wp-json/mcp-ai-core/v1/tools/{slug} # Execute a tool
```

### Authentication

Core uses WordPress capability checks by default:

```php
public function check_permissions( $request ) {
    $can = apply_filters( 
        'wp_mcp_ai_can_access_tools', 
        current_user_can( 'edit_posts' ), 
        $request 
    );
    
    return $can ? true : new WP_Error( 'rest_forbidden', ... );
}
```

Pro can enhance authentication with additional methods.

## Pro Features

### Advanced Permissions

```php
// Pro adds filter for fine-grained control
add_filter( 'wp_mcp_ai_can_run_tool', function( $can, $tool, $args, $user ) {
    // Check role-based permissions
    // Check field-level access
    // Check record-level policies
    return $can;
}, 20, 4 );
```

### Rate Limiting

```php
// Pro adds sophisticated rate limiting
add_filter( 'wp_mcp_ai_rate_limit_allow', function( $allow, $slug, $user, $context ) {
    // Per-user limits
    // Per-tool limits
    // Burst control
    // Quota management
    return $allow;
}, 20, 4 );
```

## Development Workflow

### Building Core

```bash
cd core
# Create WordPress.org release zip
zip -r wp-mcp-ai-core.zip . -x ".*"
```

### Building Pro

```bash
cd addons/pro
# Create Pro release zip
zip -r wp-mcp-ai-pro.zip . -x ".*"
```

### Testing

Both plugins can be tested independently:

```bash
# Install Core
wp plugin install ./core --activate

# Install Pro (after Core)
wp plugin install ./addons/pro --activate
```

## Migration from Single Plugin

For existing installations of the monolithic wp-mcp-ai plugin:

1. **Backup**: Create a full site backup
2. **Install Core**: Install wp-mcp-ai-core from WordPress.org
3. **Install Pro**: Install wp-mcp-ai-pro from download
4. **Deactivate Old**: Deactivate the old wp-mcp-ai plugin
5. **Migrate Settings**: Settings should auto-migrate (same option names)

## Version Compatibility

| Core Version | Pro Version | Notes |
|--------------|-------------|-------|
| 1.0.x | 1.0.x | Initial release |
| 1.1.x | 1.1.x | API additions |

Pro versions should match or exceed Core minor version.
