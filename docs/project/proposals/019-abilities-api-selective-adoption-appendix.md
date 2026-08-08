# Appendix: Code Examples & Annotation Reference

**Companion to:** [`019-abilities-api-selective-adoption-implementation-plan.md`](019-abilities-api-selective-adoption-implementation-plan.md)

---

## A. Bridge Class — Reference Implementation

```php
<?php
/**
 * Ability Bridge — wraps one NV oOS tool as a WordPress Ability.
 *
 * @package NV_oOS
 * @since   2.0.0
 */

class WP_MCP_AI_Ability_Bridge {

    /**
     * Register a single tool as a WordPress Ability.
     *
     * @param WP_MCP_AI_Tool_Interface $tool      The tool instance.
     * @param string                   $category  Ability category slug.
     * @return WP_Ability|false Registration result or false on failure.
     */
    public static function register( WP_MCP_AI_Tool_Interface $tool, string $category ) {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return false;
        }

        $slug     = $tool->get_slug();
        $hyphen   = str_replace( '_', '-', $slug );
        $id       = "nvoos/{$hyphen}";
        $flags    = self::get_flags( $tool );
        $annotations = self::map_annotations( $flags );

        return wp_register_ability(
            $id,
            array(
                'label'             => $tool->get_name(),
                'description'       => $tool->get_description(),
                'category'          => $category,
                'input_schema'      => $tool->get_parameters_schema(),
                'output_schema'     => self::get_output_schema( $tool ),
                'execute_callback'  => self::build_execute_callback( $slug ),
                'permission_callback' => self::build_permission_callback( $tool->get_required_capability() ),
                'meta'              => array(
                    'show_in_rest' => true,
                    'annotations'  => $annotations,
                    'mcp'          => array( 'public' => true ),
                ),
            )
        );
    }

    /**
     * Build the execute callback closure.
     *
     * Uses lazy instantiation: looks up the tool in the registry on first call,
     * avoiding loading the tool class at registration time.
     */
    private static function build_execute_callback( string $slug ): callable {
        return static function ( array $input = array() ) use ( $slug ) {
            $registry = WP_MCP_AI_Tool_Registry::get_instance();
            $tool     = $registry->get_tool( $slug );

            if ( ! $tool ) {
                return array(
                    'success' => false,
                    'message' => 'Tool not available.',
                    'code'    => 'tool_not_found',
                );
            }

            $context = array(
                'user_id'         => get_current_user_id(),
                'ability_context' => true,
                'is_ability_call' => true,
            );

            $result = $tool->execute( $input, $context );

            if ( is_wp_error( $result ) ) {
                return array(
                    'success' => false,
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                );
            }

            return $result;
        };
    }

    /**
     * Build the permission callback closure.
     *
     * @param string $capability WordPress capability string.
     */
    private static function build_permission_callback( string $capability ): callable {
        return static function () use ( $capability ): bool {
            return current_user_can( $capability );
        };
    }

    /**
     * Extract capability flags from a tool, with safe fallback.
     */
    private static function get_flags( WP_MCP_AI_Tool_Interface $tool ): array {
        if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
            return $tool->get_capability_flags();
        }
        return array();
    }

    /**
     * Map NV oOS capability flags to MCP annotations.
     *
     * Industry best practice: set all four hints explicitly.
     * Unset annotations default to pessimistic (potentially destructive,
     * non-idempotent, closed-world) per the MCP spec.
     */
    private static function map_annotations( array $flags ): array {
        return array(
            'readOnlyHint'    => in_array( 'read-only', $flags, true ),
            'destructiveHint' => in_array( 'irreversible', $flags, true )
                              || in_array( 'data-destruction', $flags, true ),
            'idempotentHint'  => in_array( 'idempotent', $flags, true )
                              || in_array( 'read-only', $flags, true ),
            'openWorldHint'   => in_array( 'external-api', $flags, true )
                              || in_array( 'network-dependent', $flags, true )
                              || in_array( 'long-running', $flags, true ),
        );
    }

    /**
     * Get output schema, preferring tool-declared schema over generic envelope.
     */
    private static function get_output_schema( WP_MCP_AI_Tool_Interface $tool ): array {
        if ( $tool instanceof WP_MCP_AI_Tool_Ability_Interface
            && method_exists( $tool, 'get_output_schema' )
        ) {
            $schema = $tool->get_output_schema();
            if ( ! empty( $schema ) ) {
                return $schema;
            }
        }

        // Generic canonical envelope (Phase 1 default).
        return array(
            'type'       => 'object',
            'properties' => array(
                'success' => array(
                    'type'        => 'boolean',
                    'description' => 'Whether the operation succeeded.',
                ),
                'message' => array(
                    'type'        => 'string',
                    'description' => 'Human-readable summary.',
                ),
                'data'    => array(
                    'description' => 'Operation-specific result payload.',
                ),
            ),
        );
    }
}
```

---

## B. Category Registrar — Reference Implementation

```php
<?php
/**
 * Ability Category Registrar — registers NV oOS discovery categories.
 *
 * @package NV_oOS
 * @since   2.0.0
 */

class WP_MCP_AI_Ability_Category_Registrar {

    /**
     * Category definitions.
     *
     * @var array<string, array{label: string, description: string}>
     */
    const CATEGORIES = array(
        'nvoos-site'      => array(
            'label'       => 'Site Information',
            'description' => 'Abilities that report on WordPress site state and configuration.',
        ),
        'nvoos-content'   => array(
            'label'       => 'Content & Publishing',
            'description' => 'Abilities for reading and managing WordPress content.',
        ),
        'nvoos-media'     => array(
            'label'       => 'Media & Images',
            'description' => 'Abilities for searching, retrieving, and optimizing media.',
        ),
        'nvoos-system'    => array(
            'label'       => 'System & Diagnostics',
            'description' => 'Abilities for inspecting server state, cron jobs, and plugin status.',
        ),
        'nvoos-discovery' => array(
            'label'       => 'AI Model Discovery',
            'description' => 'Abilities that describe available AI models, providers, and tools.',
        ),
    );

    /**
     * Register all categories on wp_abilities_api_categories_init.
     */
    public static function init(): void {
        add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register' ) );
    }

    /**
     * Register categories, skipping already-registered ones.
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability_category' ) ) {
            return;
        }

        foreach ( self::CATEGORIES as $slug => $args ) {
            if ( wp_has_ability_category( $slug ) ) {
                continue; // Another plugin already registered this category.
            }

            wp_register_ability_category(
                $slug,
                array(
                    'label'       => $args['label'],
                    'description' => $args['description'],
                )
            );
        }
    }
}
```

---

## C. Ability Registrar — Reference Implementation

```php
<?php
/**
 * Ability Registrar — bulk-registers eligible NV oOS tools as WordPress Abilities.
 *
 * @package NV_oOS
 * @since   2.0.0
 */

class WP_MCP_AI_Ability_Registrar {

    /**
     * Mapping of tool slugs to their category.
     *
     * In production this would be generated from tool metadata. For the
     * selective adoption plan, it's a curated allowlist.
     *
     * @var array<string, string>
     */
    const TOOL_CATEGORY_MAP = array(
        // nvoos-site
        'get_site_summary'    => 'nvoos-site',
        'get_post_types'      => 'nvoos-site',
        'get_taxonomies'      => 'nvoos-site',
        'get_themes'          => 'nvoos-site',
        'get_plugins'         => 'nvoos-site',
        'get_users'           => 'nvoos-site',

        // nvoos-content
        'get_post'            => 'nvoos-content',
        'search_content'      => 'nvoos-content',
        'get_comments'        => 'nvoos-content',
        'get_menus'           => 'nvoos-content',

        // nvoos-media
        'search_media'        => 'nvoos-media',
        'get_attachment'      => 'nvoos-media',
        'optimize_image'      => 'nvoos-media',

        // nvoos-system
        'list_cron_jobs'      => 'nvoos-system',
        'get_server_info'     => 'nvoos-system',
        'check_plugin_status' => 'nvoos-system',

        // nvoos-discovery
        'list_tools'          => 'nvoos-discovery',
        'get_tool_schema'     => 'nvoos-discovery',
        'get_providers'       => 'nvoos-discovery',
        'get_models'          => 'nvoos-discovery',
    );

    /**
     * Hook into wp_abilities_api_init.
     */
    public static function init(): void {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_all' ) );
    }

    /**
     * Register all eligible tools as Abilities.
     */
    public static function register_all(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        $registry = WP_MCP_AI_Tool_Registry::get_instance();
        $tools    = $registry->get_all_tools();

        foreach ( $tools as $slug => $tool ) {
            if ( ! isset( self::TOOL_CATEGORY_MAP[ $slug ] ) ) {
                continue; // Not in the selective allowlist.
            }

            $category = self::TOOL_CATEGORY_MAP[ $slug ];
            WP_MCP_AI_Ability_Bridge::register( $tool, $category );
        }

        /**
         * Fires after all NV oOS tool abilities are registered.
         *
         * @since 2.0.0
         */
        do_action( 'wp_mcp_ai_abilities_registered' );
    }
}
```

---

## D. Optional Tool Interface

```php
<?php
/**
 * Optional interface for NV oOS tools that want to self-declare as Abilities.
 *
 * Tools implementing this interface are automatically registered as Abilities
 * by the Ability Registrar during Phase 2+.
 *
 * @package NV_oOS
 * @since   2.0.0
 */
interface WP_MCP_AI_Tool_Ability_Interface {

    /**
     * Get the ability identifier (without namespace prefix).
     *
     * Example: 'get-post' → registered as 'nvoos/get-post'.
     *
     * @return string Ability name in kebab-case.
     */
    public function get_ability_identifier(): string;

    /**
     * Get the ability category slug.
     *
     * Must match a category registered via wp_register_ability_category().
     *
     * @return string Category slug.
     */
    public function get_ability_category(): string;

    /**
     * Get the JSON Schema for the ability's output.
     *
     * Return an empty array to fall back to the generic canonical envelope.
     *
     * @return array JSON Schema array.
     */
    public function get_output_schema(): array;

    /**
     * Whether this ability should be publicly exposed via MCP adapter.
     *
     * Sets meta.mcp.public on the ability registration.
     *
     * @return bool True if the ability should be MCP-public.
     */
    public function is_public_ability(): bool;
}
```

---

## E. Complete Capability Flag → MCP Annotation Mapping Table

See main plan §3 for the logic. This table shows every flag and its effect:

| NV oOS Capability Flag | `readOnlyHint` | `destructiveHint` | `idempotentHint` | `openWorldHint` | Notes |
|---|---|---|---|---|---|
| `read-only` | **true** | false | **true** | — | Reading is idempotent by nature |
| `write` | false | — | — | — | Mutating |
| `state-changing` | false | — | — | — | Mutating |
| `reversible` | — | false | — | — | Safe to undo |
| `irreversible` | — | **true** | false | — | Cannot undo |
| `idempotent` | — | — | **true** | — | Safe to retry |
| `non-deterministic` | — | — | false | — | Results vary; not strictly idempotent |
| `local-only` | — | — | — | false | No external calls |
| `external-api` | — | — | — | **true** | Calls external services |
| `network-dependent` | — | — | — | **true** | Requires internet |
| `async` | — | — | — | — | Informational only (UX hint) |
| `long-running` | — | — | — | **true** | Results not immediate |
| `data-destruction` | false | **true** | false | — | Permanently removes data |
| `financial-impact` | — | — | — | — | No MCP equivalent; used by Layer J |
| `external-communication` | — | — | — | **true** | Sends messages externally |
| `access-control-change` | — | — | — | — | No MCP equivalent; always requires human approval |
| `pii-data` | — | — | — | — | No MCP equivalent; session-level policy |
| `cacheable` | — | — | — | — | No MCP equivalent; performance hint |
| `requires-credentials` | — | — | — | — | Registration gate only |
| `requires-plugin` | — | — | — | — | Registration gate only |
| `streaming-capable` | — | — | — | — | No MCP equivalent; transport concern |
| `rate-limited` | — | — | — | — | No MCP equivalent; operational |
| `consumes-tokens` | — | — | — | — | No MCP equivalent; cost tracking |

**Key:** `true`/`false` = sets the annotation explicitly. `—` = leaves at default (pessimistic).

---

## F. Context Population — Detailed Example

```php
/**
 * Full context builder for Ability execution path.
 *
 * Tools receive different levels of context depending on the execution path:
 *
 *   Path              | user_id | assistant_id | channel_id | ability_context
 *   ------------------|---------|--------------|------------|----------------
 *   NV oOS MCP        |    ✓    |      ✓       |     ✓      |      false
 *   NV oOS REST       |    ✓    |      ✓       |     ✓      |      false
 *   Ability (MCP Adap)|    ✓    |      ✗       |     ✗       |      true
 *   Ability (REST run)|    ✓    |      ✗       |     ✗       |      true
 *   WP-CLI            |    ✓    |      ✗       |     ✗       |      false
 *
 * Tools that REQUIRE assistant_id/channel_id should check ability_context
 * and return WP_Error('ability_context_limited', ...) if unavailable.
 */
function wp_mcp_ai_build_ability_context(): array {
    return array(
        'user_id'          => get_current_user_id(),
        'ability_context'  => true,
        'is_ability_call'  => true,
        'assistant_id'     => null,  // Not available in Ability context
        'channel_id'       => null,
        'conversation_id'  => null,
        'request_source'   => 'ability',
    );
}
```

---

## G. Example: `get_site_summary` as a Complete Ability

```php
add_action( 'wp_abilities_api_init', function () {
    if ( ! function_exists( 'wp_register_ability' ) ) {
        return;
    }

    wp_register_ability(
        'nvoos/get-site-summary',
        array(
            'label'       => __( 'Get Site Summary', 'wp-mcp-ai' ),
            'description' => __(
                'Returns a summary of this WordPress site including name, URL, '
                . 'version, active theme, active plugins count, post counts, '
                . 'and PHP/MySQL versions. Useful for AI agents to understand '
                . 'the site environment before performing operations.',
                'wp-mcp-ai'
            ),
            'category'    => 'nvoos-site',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(),
            ),
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'site_name'        => array( 'type' => 'string' ),
                    'site_url'         => array( 'type' => 'string' ),
                    'wp_version'       => array( 'type' => 'string' ),
                    'php_version'      => array( 'type' => 'string' ),
                    'mysql_version'    => array( 'type' => 'string' ),
                    'active_theme'     => array( 'type' => 'string' ),
                    'active_plugins'   => array( 'type' => 'integer' ),
                    'total_posts'      => array( 'type' => 'integer' ),
                    'total_pages'      => array( 'type' => 'integer' ),
                    'total_comments'   => array( 'type' => 'integer' ),
                    'total_users'      => array( 'type' => 'integer' ),
                    'is_multisite'     => array( 'type' => 'boolean' ),
                    'timezone'         => array( 'type' => 'string' ),
                ),
            ),
            'execute_callback' => function () {
                $counts = wp_count_posts();
                $pages  = wp_count_posts( 'page' );

                return array(
                    'site_name'      => get_bloginfo( 'name' ),
                    'site_url'       => get_bloginfo( 'url' ),
                    'wp_version'     => get_bloginfo( 'version' ),
                    'php_version'    => PHP_VERSION,
                    'mysql_version'  => $GLOBALS['wpdb']->db_version(),
                    'active_theme'   => wp_get_theme()->get( 'Name' ),
                    'active_plugins' => count( get_option( 'active_plugins', array() ) ),
                    'total_posts'    => (int) ( $counts->publish ?? 0 ),
                    'total_pages'    => (int) ( $pages->publish ?? 0 ),
                    'total_comments' => (int) wp_count_comments()->total_comments,
                    'total_users'    => (int) count_users()['total_users'],
                    'is_multisite'   => is_multisite(),
                    'timezone'       => wp_timezone_string(),
                );
            },
            'permission_callback' => static fn () => current_user_can( 'read' ),
            'meta' => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readOnlyHint'    => true,
                    'destructiveHint' => false,
                    'idempotentHint'  => true,
                    'openWorldHint'   => false,
                ),
                'mcp' => array( 'public' => true ),
            ),
        )
    );
} );
```

---

## H. Bootstrap Integration

```php
// In the main plugin bootstrap or initialization class:

// Register ability categories (before abilities).
WP_MCP_AI_Ability_Category_Registrar::init();

// Register eligible tools as Abilities.
WP_MCP_AI_Ability_Registrar::init();
```

---

## I. WP-CLI Debugging Commands

```bash
# Check if a specific ability is registered
wp shell
wp> wp_has_ability( 'nvoos/get-site-summary' );
=> bool(true)

# Inspect an ability
wp> $a = wp_get_ability( 'nvoos/get-site-summary' );
wp> var_dump( $a->get_label(), $a->get_category() );

# Execute an ability directly
wp> $result = wp_get_ability( 'nvoos/get-site-summary' )->execute( array() );
wp> var_dump( $result );

# List all NV oOS abilities
wp> $all = wp_get_abilities();
wp> $ours = array_filter( $all, fn( $k ) => str_starts_with( $k, 'nvoos/' ), ARRAY_FILTER_USE_KEY );
wp> var_dump( count( $ours ) );

# Check MCP adapter discovery (requires MCP adapter installed)
wp eval 'do_action( "wp_mcp_server_ready" );'
```
