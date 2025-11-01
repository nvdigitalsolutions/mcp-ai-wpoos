<?php
/**
 * Assistant custom post type.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the assistant custom post type and associated meta boxes.
 */
class WP_MCP_AI_Assistant_CPT {
    const POST_TYPE = 'mcp_ai_assistant';
    const META_TOOLS = '_wp_mcp_ai_tools';
    const META_PROVIDER = '_wp_mcp_ai_provider';
    const META_MODEL = '_wp_mcp_ai_model';
    const META_TEMPERATURE = '_wp_mcp_ai_temperature';
    const META_SYSTEM_PROMPT         = '_wp_mcp_ai_system_prompt';
    const META_MEMORY_FILES          = '_wp_mcp_ai_memory_files';
    const META_VECTOR_STORE_ID       = '_wp_mcp_ai_vector_store_id';
    const META_TOOL_SHORTCUTS        = '_wp_mcp_ai_tool_shortcuts';
    const META_TOOL_PREBUILT_SHORTCUTS = '_wp_mcp_ai_tool_prebuilt_shortcuts';
    const META_DISABLE_TOOL_SHORTCUTS = '_wp_mcp_ai_disable_tool_shortcuts';
    const META_TOOL_ROLE_RULES       = '_wp_mcp_ai_tool_role_rules';
    const META_CREDENTIALS           = WP_MCP_AI_Credentials::META_KEY;
    const META_EXTERNAL_ACTION_ID    = '_wp_mcp_ai_external_action_id';
    const META_EXTERNAL_ACTION_TYPE  = '_wp_mcp_ai_external_action_type';

    /**
     * Tool registry instance.
     *
     * @var WP_MCP_AI_Tool_Registry
     */
    protected $registry;

    /**
     * Track whether the credential action script has been printed.
     *
     * @var bool
     */
    protected static $credential_action_script_printed = false;

    /**
     * Constructor.
     *
     * @param WP_MCP_AI_Tool_Registry $registry Tool registry.
     */
    public function __construct( WP_MCP_AI_Tool_Registry $registry ) {
        $this->registry = $registry;

        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'init', array( __CLASS__, 'register_meta' ) );
        add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor_for_post_type' ), 10, 2 );
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
        add_action( 'admin_post_wp_mcp_ai_issue_credential', array( $this, 'handle_issue_credential' ) );
        add_action( 'admin_post_wp_mcp_ai_revoke_credential', array( $this, 'handle_revoke_credential' ) );
        add_action( 'admin_post_wp_mcp_ai_delete_credential', array( $this, 'handle_delete_credential' ) );
        add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
        add_action( 'before_delete_post', array( $this, 'cleanup_deleted_assistant_credentials' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_menu_icon_styles' ) );
    }

    /**
     * Render controls that allow editing the pre-built shortcuts contributed by tools.
     *
     * @param WP_Post $post               Post object.
     * @param array   $selected_tools     Selected tool slugs.
     * @param array   $prebuilt_shortcuts Sanitized custom pre-built shortcut configuration.
     */
    protected function render_prebuilt_shortcuts_editor( $post, array $selected_tools, array $prebuilt_shortcuts ) {
        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $selected_tools = array_values(
            array_unique(
                array_filter(
                    array_map( 'sanitize_key', $selected_tools )
                )
            )
        );

        echo '<div class="wp-mcp-ai-prebuilt-shortcuts">';
        echo '<h3>' . esc_html__( 'Pre-built prompt shortcuts', 'wp-mcp-ai' ) . '</h3>';
        echo '<p class="description">' . esc_html__( 'Adjust the shortcuts that selected tools contribute to the assistant chat interface.', 'wp-mcp-ai' ) . '</p>';

        if ( empty( $selected_tools ) ) {
            echo '<p class="description">' . esc_html__( 'Select at least one tool above to configure its pre-built shortcuts.', 'wp-mcp-ai' ) . '</p>';
        }

        $default_shortcuts_map = $this->get_default_prebuilt_shortcuts_map( $selected_tools, $post->ID );

        $tool_index = 0;

        foreach ( $selected_tools as $tool_slug ) {
            $tool = $this->registry->get_tool( $tool_slug );

            if ( ! $tool ) {
                continue;
            }

            $tool_name           = $tool->get_name();
            $defaults            = isset( $default_shortcuts_map[ $tool_slug ] ) ? $default_shortcuts_map[ $tool_slug ] : array();
            $settings            = isset( $prebuilt_shortcuts[ $tool_slug ] ) ? $prebuilt_shortcuts[ $tool_slug ] : array();
            $mode                = ( isset( $settings['mode'] ) && 'custom' === $settings['mode'] ) ? 'custom' : 'inherit';
            $custom_rows         = ( 'custom' === $mode && isset( $settings['shortcuts'] ) && is_array( $settings['shortcuts'] ) ) ? $settings['shortcuts'] : array();
            $next_index          = ( 'custom' === $mode ) ? count( $custom_rows ) : 0;
            $defaults_json       = wp_json_encode( $defaults );
            $has_existing_custom = ( 'custom' === $mode );
            $rows_aria_hidden    = ( 'custom' === $mode ) ? 'false' : 'true';
            $mode_label_inherit  = __( 'Using defaults', 'wp-mcp-ai' );
            $mode_label_custom   = __( 'Custom prompts', 'wp-mcp-ai' );
            $mode_label          = ( 'custom' === $mode ) ? $mode_label_custom : $mode_label_inherit;
            $open_attr           = ( 0 === $tool_index || 'custom' === $mode ) ? ' open' : '';

            if ( false === $defaults_json ) {
                $defaults_json = '[]';
            }

            echo '<details class="wp-mcp-ai-prebuilt-shortcuts__tool" data-tool="' . esc_attr( $tool_slug ) . '" data-defaults="' . esc_attr( $defaults_json ) . '" data-has-existing-custom="' . ( $has_existing_custom ? 'true' : 'false' ) . '" data-mode-label-inherit="' . esc_attr( $mode_label_inherit ) . '" data-mode-label-custom="' . esc_attr( $mode_label_custom ) . '"' . $open_attr . '>';
            echo '<summary class="wp-mcp-ai-prebuilt-shortcuts__summary">';
            echo '<span class="wp-mcp-ai-prebuilt-shortcuts__summary-title">' . esc_html( $tool_name ) . '</span>';
            echo '<span class="wp-mcp-ai-prebuilt-shortcuts__summary-mode" aria-live="polite">' . esc_html( $mode_label ) . '</span>';
            echo '</summary>';
            echo '<div class="wp-mcp-ai-prebuilt-shortcuts__content">';
            echo '<p class="wp-mcp-ai-prebuilt-shortcuts__mode">';
            printf(
                '<label><input type="radio" name="wp_mcp_ai_prebuilt_shortcuts[%1$s][mode]" value="inherit" %2$s /> %3$s</label>',
                esc_attr( $tool_slug ),
                checked( 'inherit', $mode, false ),
                esc_html__( 'Use defaults', 'wp-mcp-ai' )
            );
            printf(
                '<label><input type="radio" name="wp_mcp_ai_prebuilt_shortcuts[%1$s][mode]" value="custom" %2$s /> %3$s</label>',
                esc_attr( $tool_slug ),
                checked( 'custom', $mode, false ),
                esc_html__( 'Customize', 'wp-mcp-ai' )
            );
            echo '</p>';

            echo '<div class="wp-mcp-ai-prebuilt-shortcuts__defaults">';
            if ( ! empty( $defaults ) ) {
                echo '<p class="description">' . esc_html__( 'Default prompts provided by this tool:', 'wp-mcp-ai' ) . '</p>';
                echo '<ul class="wp-mcp-ai-prebuilt-shortcuts__defaults-list">';

                foreach ( $defaults as $default_shortcut ) {
                    $default_label   = isset( $default_shortcut['label'] ) ? (string) $default_shortcut['label'] : '';
                    $default_payload = isset( $default_shortcut['payload'] ) ? (string) $default_shortcut['payload'] : '';
                    $summary         = '';

                    if ( '' !== $default_payload ) {
                        $summary = wp_html_excerpt( $default_payload, 100, '&hellip;' );
                    }

                    echo '<li>';
                    if ( '' !== $default_label ) {
                        echo '<strong>' . esc_html( $default_label ) . '</strong>';
                    } else {
                        echo '<strong>' . esc_html__( 'Shortcut', 'wp-mcp-ai' ) . '</strong>';
                    }

                    if ( '' !== $summary ) {
                        echo '<span class="wp-mcp-ai-prebuilt-shortcuts__defaults-summary"> ' . esc_html( $summary ) . '</span>';
                    }

                    echo '</li>';
                }

                echo '</ul>';
            } else {
                echo '<p class="description">' . esc_html__( 'This tool does not provide any pre-built shortcuts.', 'wp-mcp-ai' ) . '</p>';
            }
            echo '</div>';

            echo '<div class="wp-mcp-ai-prebuilt-shortcuts__rows" data-tool="' . esc_attr( $tool_slug ) . '" data-next-index="' . esc_attr( $next_index ) . '" aria-hidden="' . esc_attr( $rows_aria_hidden ) . '"';
            if ( 'custom' !== $mode ) {
                echo ' hidden';
            }
            echo '>';

            if ( 'custom' === $mode ) {
                foreach ( $custom_rows as $index => $shortcut ) {
                    $index       = intval( $index );
                    $label       = isset( $shortcut['label'] ) ? (string) $shortcut['label'] : '';
                    $payload     = isset( $shortcut['payload'] ) ? (string) $shortcut['payload'] : '';
                    $description = isset( $shortcut['description'] ) ? (string) $shortcut['description'] : '';
                    /* translators: 1: Shortcut number. 2: Tool name. */
                    $legend_text = sprintf( __( 'Shortcut %1$d for %2$s', 'wp-mcp-ai' ), $index + 1, $tool_name );

                    echo '<fieldset class="wp-mcp-ai-prebuilt-shortcuts__row" data-index="' . esc_attr( $index ) . '">';
                    echo '<legend class="screen-reader-text">' . esc_html( $legend_text ) . '</legend>';
                    echo '<p>';
                    printf(
                        '<label><strong>%1$s</strong><input type="text" class="widefat" name="wp_mcp_ai_prebuilt_shortcuts[%2$s][shortcuts][%3$s][label]" value="%4$s"%5$s /></label>',
                        esc_html__( 'Shortcut label', 'wp-mcp-ai' ),
                        esc_attr( $tool_slug ),
                        esc_attr( $index ),
                        esc_attr( $label ),
                        disabled( 'custom' !== $mode, true, false )
                    );
                    echo '</p>';
                    echo '<p>';
                    printf(
                        '<label><strong>%1$s</strong><textarea class="widefat" rows="4" name="wp_mcp_ai_prebuilt_shortcuts[%2$s][shortcuts][%3$s][payload]"%4$s>%5$s</textarea></label>',
                        esc_html__( 'Prompt text', 'wp-mcp-ai' ),
                        esc_attr( $tool_slug ),
                        esc_attr( $index ),
                        disabled( 'custom' !== $mode, true, false ),
                        esc_textarea( $payload )
                    );
                    echo '</p>';
                    echo '<p>';
                    printf(
                        '<label><strong>%1$s</strong><textarea class="widefat" rows="3" name="wp_mcp_ai_prebuilt_shortcuts[%2$s][shortcuts][%3$s][description]"%4$s>%5$s</textarea></label>',
                        esc_html__( 'Optional description', 'wp-mcp-ai' ),
                        esc_attr( $tool_slug ),
                        esc_attr( $index ),
                        disabled( 'custom' !== $mode, true, false ),
                        esc_textarea( $description )
                    );
                    echo '</p>';
                    echo '<p>';
                    printf(
                        '<button type="button" class="button-link-delete wp-mcp-ai-prebuilt-shortcuts__remove"%1$s>%2$s</button>',
                        disabled( 'custom' !== $mode, true, false ),
                        esc_html__( 'Remove shortcut', 'wp-mcp-ai' )
                    );
                    echo '</p>';
                    echo '<hr />';
                    echo '</fieldset>';
                }
            }

            echo '</div>';
            echo '<p>';
            printf(
                '<button type="button" class="button wp-mcp-ai-prebuilt-shortcuts__add" data-tool="%1$s"%2$s>%3$s</button>',
                esc_attr( $tool_slug ),
                disabled( 'custom' !== $mode, true, false ),
                esc_html__( 'Add shortcut', 'wp-mcp-ai' )
            );
            echo '</p>';
            echo '</div>';
            echo '</details>';

            $tool_index++;
        }

        echo '</div>';

        static $prebuilt_shortcut_template_printed = false;

        if ( ! $prebuilt_shortcut_template_printed ) {
            $prebuilt_shortcut_template_printed = true;
            ?>
            <template id="wp-mcp-ai-prebuilt-shortcut-template">
                <fieldset class="wp-mcp-ai-prebuilt-shortcuts__row" data-index="__INDEX__">
                    <legend class="screen-reader-text"><?php esc_html_e( 'New pre-built shortcut', 'wp-mcp-ai' ); ?></legend>
                    <p>
                        <label>
                            <strong><?php esc_html_e( 'Shortcut label', 'wp-mcp-ai' ); ?></strong>
                            <input type="text" class="widefat" name="wp_mcp_ai_prebuilt_shortcuts[__TOOL__][shortcuts][__INDEX__][label]" />
                        </label>
                    </p>
                    <p>
                        <label>
                            <strong><?php esc_html_e( 'Prompt text', 'wp-mcp-ai' ); ?></strong>
                            <textarea class="widefat" rows="4" name="wp_mcp_ai_prebuilt_shortcuts[__TOOL__][shortcuts][__INDEX__][payload]"></textarea>
                        </label>
                    </p>
                    <p>
                        <label>
                            <strong><?php esc_html_e( 'Optional description', 'wp-mcp-ai' ); ?></strong>
                            <textarea class="widefat" rows="3" name="wp_mcp_ai_prebuilt_shortcuts[__TOOL__][shortcuts][__INDEX__][description]"></textarea>
                        </label>
                    </p>
                    <p>
                        <button type="button" class="button-link-delete wp-mcp-ai-prebuilt-shortcuts__remove"><?php esc_html_e( 'Remove shortcut', 'wp-mcp-ai' ); ?></button>
                    </p>
                    <hr />
                </fieldset>
            </template>
            <?php
        }
    }

    /**
     * Retrieve the default pre-built shortcuts for the supplied tools.
     *
     * @param array $tool_slugs   Tool slugs to inspect.
     * @param int   $assistant_id Assistant post ID.
     * @return array
     */
    protected function get_default_prebuilt_shortcuts_map( array $tool_slugs, $assistant_id ) {
        if ( empty( $tool_slugs ) ) {
            return array();
        }

        $assistant_id = absint( $assistant_id );
        $shortcuts    = array();

        foreach ( $tool_slugs as $tool_slug ) {
            $tool_slug = sanitize_key( $tool_slug );

            if ( '' === $tool_slug ) {
                continue;
            }

            $tool = $this->registry->get_tool( $tool_slug );

            if ( ! $tool ) {
                continue;
            }

            $tasks         = array();
            $skip_fallback = false;

            if ( $tool instanceof WP_MCP_AI_Tool_Shortcuts_Interface ) {
                $tasks = $tool->get_shortcut_tasks();
            } elseif ( method_exists( $tool, 'get_shortcut_tasks' ) ) {
                $tasks = $tool->get_shortcut_tasks();
            }

            if ( null === $tasks ) {
                $skip_fallback = true;
            }

            $tasks = apply_filters( 'wp_mcp_ai_tool_shortcut_tasks', $tasks, $tool, $assistant_id );
            $tasks = apply_filters( 'wp_mcp_ai_tool_shortcut_tasks_' . $tool_slug, $tasks, $tool, $assistant_id );

            if ( null === $tasks ) {
                $shortcuts[ $tool_slug ] = array();
                continue;
            }

            $entries = array();

            if ( empty( $tasks ) || ! is_array( $tasks ) ) {
                $should_register_fallback = ! $skip_fallback;

                if ( $tool instanceof WP_MCP_AI_Tool_Fallback_Shortcut_Interface ) {
                    $should_register_fallback = (bool) $tool->should_register_fallback_shortcut( $assistant_id );
                } elseif ( method_exists( $tool, 'should_register_fallback_shortcut' ) ) {
                    $should_register_fallback = (bool) $tool->should_register_fallback_shortcut( $assistant_id );
                }

                $should_register_fallback = apply_filters(
                    'wp_mcp_ai_tool_should_register_fallback_shortcut',
                    $should_register_fallback,
                    $tool,
                    $assistant_id,
                    $tasks
                );

                if ( $should_register_fallback ) {
                    $entries[] = array(
                        'label'   => sanitize_text_field( $tool->get_slug() ),
                        'payload' => sanitize_textarea_field( $tool->get_slug() ),
                    );
                }

                $shortcuts[ $tool_slug ] = $entries;
                continue;
            }

            foreach ( $tasks as $task ) {
                if ( ! is_array( $task ) ) {
                    continue;
                }

                $label   = isset( $task['label'] ) && is_string( $task['label'] ) ? sanitize_text_field( $task['label'] ) : '';
                $payload = isset( $task['payload'] ) && is_string( $task['payload'] ) ? sanitize_textarea_field( $task['payload'] ) : '';

                if ( '' === $label && '' === $payload ) {
                    continue;
                }

                if ( '' === $label ) {
                    $label = $tool->get_slug();
                }

                if ( '' === $payload ) {
                    $payload = $tool->get_slug();
                }

                $entry = array(
                    'label'   => $label,
                    'payload' => $payload,
                );

                if ( isset( $task['description'] ) && is_string( $task['description'] ) ) {
                    $entry['description'] = sanitize_textarea_field( $task['description'] );
                }

                $entries[] = $entry;
            }

            if ( empty( $entries ) ) {
                $should_register_fallback = ! $skip_fallback;

                if ( $tool instanceof WP_MCP_AI_Tool_Fallback_Shortcut_Interface ) {
                    $should_register_fallback = (bool) $tool->should_register_fallback_shortcut( $assistant_id );
                } elseif ( method_exists( $tool, 'should_register_fallback_shortcut' ) ) {
                    $should_register_fallback = (bool) $tool->should_register_fallback_shortcut( $assistant_id );
                }

                $should_register_fallback = apply_filters(
                    'wp_mcp_ai_tool_should_register_fallback_shortcut',
                    $should_register_fallback,
                    $tool,
                    $assistant_id,
                    $tasks
                );

                if ( $should_register_fallback ) {
                    $entries[] = array(
                        'label'   => sanitize_text_field( $tool->get_slug() ),
                        'payload' => sanitize_textarea_field( $tool->get_slug() ),
                    );
                }
            }

            $shortcuts[ $tool_slug ] = $entries;
        }

        return $shortcuts;
    }

    /**
     * Ensure admin menu icon styles load so the CPT icon matches JetEngine's sizing.
     */
    public static function enqueue_admin_menu_icon_styles() {
        static $enqueued = false;

        if ( $enqueued ) {
            return;
        }

        $enqueued = true;

        $post_type_class = sanitize_html_class( self::POST_TYPE );
        $styles          = sprintf(
            '#adminmenu .menu-icon-%1$s a.menu-top{display:flex;align-items:center;gap:8px;padding:0 12px 0 6px;}#adminmenu .menu-icon-%1$s .wp-menu-image{display:flex;align-items:center;justify-content:center;width:20px;height:20px;margin:0;}#adminmenu .menu-icon-%1$s .wp-menu-name{display:flex;align-items:center;}#adminmenu .menu-icon-%1$s .wp-menu-image img{width:20px;height:20px;max-width:none;display:block;object-fit:contain;}',
            $post_type_class
        );

        wp_register_style( 'wp-mcp-ai-admin-menu-icon', false, array(), null );
        wp_enqueue_style( 'wp-mcp-ai-admin-menu-icon' );
        wp_add_inline_style( 'wp-mcp-ai-admin-menu-icon', $styles );
    }

    /**
     * Register the assistant custom post type.
     */
    public static function register_post_type() {
        $labels = array(
            'name'               => __( 'AI Assistants', 'wp-mcp-ai' ),
            'singular_name'      => __( 'AI Assistant', 'wp-mcp-ai' ),
            'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
            'add_new_item'       => __( 'Add New Assistant', 'wp-mcp-ai' ),
            'edit_item'          => __( 'Edit Assistant', 'wp-mcp-ai' ),
            'new_item'           => __( 'New Assistant', 'wp-mcp-ai' ),
            'view_item'          => __( 'View Assistant', 'wp-mcp-ai' ),
            'search_items'       => __( 'Search Assistants', 'wp-mcp-ai' ),
            'not_found'          => __( 'No assistants found', 'wp-mcp-ai' ),
            'not_found_in_trash' => __( 'No assistants found in Trash', 'wp-mcp-ai' ),
            'all_items'          => __( 'Assistants', 'wp-mcp-ai' ),
        );

        $args = array(
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'rest_base'           => 'mcp-ai-assistants',
            'capability_type'     => 'post',
            'supports'            => array( 'title', 'editor' ),
            'menu_icon'           => WP_MCP_AI_URL . 'assets/images/ai-icon.svg',
            'has_archive'         => false,
            'rewrite'             => false,
            'show_in_nav_menus'   => false,
            'map_meta_cap'        => true,
        );

        register_post_type( self::POST_TYPE, $args );
    }

    /**
     * Disable the block editor for the assistant post type so meta boxes save correctly.
     *
     * @param bool   $use_block_editor Whether the block editor should be used.
     * @param string $post_type        Current post type being edited.
     * @return bool
     */
    public static function disable_block_editor_for_post_type( $use_block_editor, $post_type ) {
        if ( self::POST_TYPE === $post_type ) {
            return false;
        }

        return $use_block_editor;
    }

    /**
     * Register assistant post meta for REST access and sanitization.
     */
    public static function register_meta() {
        $auth_callback = array( __CLASS__, 'meta_auth_callback' );

        register_post_meta(
            self::POST_TYPE,
            self::META_TOOLS,
            array(
                'type'              => 'array',
                'single'            => true,
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'string',
                        ),
                    ),
                ),
                'sanitize_callback' => array( __CLASS__, 'sanitize_tools_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_PROVIDER,
            array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_provider_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_MODEL,
            array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_model_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_TEMPERATURE,
            array(
                'type'              => 'number',
                'single'            => true,
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'    => 'number',
                        'minimum' => 0,
                        'maximum' => 2,
                    ),
                ),
                'sanitize_callback' => array( __CLASS__, 'sanitize_temperature_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_SYSTEM_PROMPT,
            array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_system_prompt_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_MEMORY_FILES,
            array(
                'type'              => 'array',
                'single'            => true,
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'integer',
                        ),
                    ),
                ),
                'sanitize_callback' => array( __CLASS__, 'sanitize_memory_files_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_VECTOR_STORE_ID,
            array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_vector_store_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_TOOL_SHORTCUTS,
            array(
                'type'              => 'array',
                'single'            => true,
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'label'       => array(
                                    'type' => 'string',
                                ),
                                'payload'     => array(
                                    'type' => 'string',
                                ),
                                'tool'        => array(
                                    'type' => 'string',
                                ),
                                'description' => array(
                                    'type' => 'string',
                                ),
                            ),
                            'additionalProperties' => false,
                        ),
                    ),
                ),
                'sanitize_callback' => array( __CLASS__, 'sanitize_tool_shortcuts_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_TOOL_PREBUILT_SHORTCUTS,
            array(
                'type'              => 'object',
                'single'            => true,
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'                 => 'object',
                        'additionalProperties' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'mode'      => array(
                                    'type' => 'string',
                                    'enum' => array( 'custom' ),
                                ),
                                'shortcuts' => array(
                                    'type'  => 'array',
                                    'items' => array(
                                        'type'                 => 'object',
                                        'properties'           => array(
                                            'label'       => array(
                                                'type' => 'string',
                                            ),
                                            'payload'     => array(
                                                'type' => 'string',
                                            ),
                                            'description' => array(
                                                'type' => 'string',
                                            ),
                                        ),
                                        'additionalProperties' => false,
                                    ),
                                ),
                            ),
                            'additionalProperties' => false,
                        ),
                    ),
                ),
                'sanitize_callback' => array( __CLASS__, 'sanitize_prebuilt_tool_shortcuts_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        $flag_schema = array(
            'type'  => 'array',
            'items' => array(
                'type' => 'string',
            ),
        );

        $allowed_flags = self::get_allowed_tool_role_flags();

        if ( ! empty( $allowed_flags ) ) {
            $flag_schema['items']['enum'] = $allowed_flags;
        }

        register_post_meta(
            self::POST_TYPE,
            self::META_TOOL_ROLE_RULES,
            array(
                'type'              => 'array',
                'single'            => true,
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'properties'           => array(
                                'tool'   => array(
                                    'type' => 'string',
                                ),
                                'roles'  => array(
                                    'type'  => 'array',
                                    'items' => array(
                                        'type' => 'string',
                                    ),
                                ),
                                'groups' => array(
                                    'type'  => 'array',
                                    'items' => array(
                                        'type'    => 'integer',
                                        'minimum' => 1,
                                    ),
                                ),
                                'flags'  => $flag_schema,
                            ),
                            'additionalProperties' => false,
                            'required'             => array( 'tool' ),
                        ),
                    ),
                ),
                'sanitize_callback' => array( __CLASS__, 'sanitize_tool_role_rules_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_DISABLE_TOOL_SHORTCUTS,
            array(
                'type'              => 'boolean',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_disable_tool_shortcuts_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_EXTERNAL_ACTION_ID,
            array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_external_action_id_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_EXTERNAL_ACTION_TYPE,
            array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_external_action_type_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );
    }

    /**
     * Meta capability check for assistant meta values.
     *
     * @param bool       $allowed Existing permission.
     * @param string     $meta_key Meta key being modified.
     * @param int        $post_id Post ID.
     * @param int        $user_id User ID.
     * @param string|array $cap Capability name(s).
     * @param array      $caps Primitive caps.
     * @return bool
     */
    public static function meta_auth_callback( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ) {
        unset( $allowed, $meta_key, $user_id, $cap, $caps );

        return current_user_can( 'edit_post', $post_id );
    }

    /**
     * Sanitize tools meta value.
     *
     * @param mixed $tools Raw tools value.
     * @return array
     */
    public static function sanitize_tools_meta( $tools ) {
        if ( ! is_array( $tools ) ) {
            return array();
        }

        $registry = WP_MCP_AI_Tool_Registry::get_instance();
        $registry->init();

        $sanitized = array();

        foreach ( $tools as $tool_slug ) {
            $tool_slug = sanitize_key( $tool_slug );

            if ( '' === $tool_slug ) {
                continue;
            }

            if ( null === $registry->get_tool( $tool_slug ) ) {
                continue;
            }

            $sanitized[] = $tool_slug;
        }

        return array_values( array_unique( $sanitized ) );
    }

    /**
     * Sanitize provider meta value.
     *
     * @param mixed $provider Raw provider value.
     * @return string
     */
    public static function sanitize_provider_meta( $provider ) {
        $provider = is_string( $provider ) ? sanitize_key( $provider ) : '';

        $allowed_providers = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini' ) );
        if ( ! is_array( $allowed_providers ) ) {
            $allowed_providers = array( 'openai', 'gemini' );
        }

        if ( ! in_array( $provider, $allowed_providers, true ) ) {
            return '';
        }

        return $provider;
    }

    /**
     * Sanitize model meta value.
     *
     * @param mixed $model Raw model value.
     * @return string
     */
    public static function sanitize_model_meta( $model ) {
        if ( ! is_string( $model ) ) {
            return '';
        }

        return sanitize_text_field( $model );
    }

    /**
     * Sanitize temperature meta value.
     *
     * @param mixed $temperature Raw temperature value.
     * @return float|null
     */
    public static function sanitize_temperature_meta( $temperature ) {
        if ( is_string( $temperature ) ) {
            $temperature = trim( $temperature );
        }

        if ( '' === $temperature || null === $temperature ) {
            return null;
        }

        if ( is_numeric( $temperature ) ) {
            $temperature = floatval( $temperature );
            if ( $temperature < 0 || $temperature > 2 ) {
                return null;
            }

            return $temperature;
        }

        return null;
    }

    /**
     * Sanitize system prompt meta value.
     *
     * @param mixed $prompt Raw prompt value.
     * @return string
     */
    public static function sanitize_system_prompt_meta( $prompt ) {
        if ( ! is_string( $prompt ) ) {
            return '';
        }

        return wp_kses_post( $prompt );
    }

    /**
     * Sanitize memory files meta value.
     *
     * @param mixed $memory_files Raw memory file IDs.
     * @return array
     */
    public static function sanitize_memory_files_meta( $memory_files ) {
        if ( ! is_array( $memory_files ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $memory_files as $file_id ) {
            $file_id = absint( $file_id );
            if ( $file_id && 'attachment' === get_post_type( $file_id ) ) {
                $sanitized[] = $file_id;
            }
        }

        return array_values( array_unique( $sanitized ) );
    }

    /**
     * Sanitize vector store ID meta value.
     *
     * @param mixed $vector_store_id Raw vector store ID.
     * @return string
     */
    public static function sanitize_vector_store_meta( $vector_store_id ) {
        if ( ! is_string( $vector_store_id ) ) {
            return '';
        }

        return sanitize_text_field( $vector_store_id );
    }

    /**
     * Sanitize the default external action identifier meta value.
     *
     * @param mixed $identifier Raw identifier value.
     * @return string
     */
    public static function sanitize_external_action_id_meta( $identifier ) {
        if ( ! is_string( $identifier ) ) {
            return '';
        }

        return sanitize_text_field( $identifier );
    }

    /**
     * Sanitize the default external action type meta value.
     *
     * @param mixed $action_type Raw action type value.
     * @return string
     */
    public static function sanitize_external_action_type_meta( $action_type ) {
        $action_type = is_string( $action_type ) ? sanitize_key( $action_type ) : '';

        if ( ! in_array( $action_type, array( 'workflow', 'assistant' ), true ) ) {
            return '';
        }

        return $action_type;
    }

    /**
     * Register meta boxes for the assistant CPT.
     */
    public function register_meta_boxes() {
        add_meta_box(
            'wp-mcp-ai-tools',
            __( 'Available Tools', 'wp-mcp-ai' ),
            array( $this, 'render_tools_meta_box' ),
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'wp-mcp-ai-tool-shortcuts',
            __( 'Prompt Shortcuts', 'wp-mcp-ai' ),
            array( $this, 'render_tool_shortcuts_meta_box' ),
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'wp-mcp-ai-defaults',
            __( 'Model Defaults', 'wp-mcp-ai' ),
            array( $this, 'render_defaults_meta_box' ),
            self::POST_TYPE,
            'side',
            'default'
        );

        add_meta_box(
            'wp-mcp-ai-base-knowledge',
            __( 'Base Knowledge', 'wp-mcp-ai' ),
            array( $this, 'render_base_knowledge_meta_box' ),
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'wp-mcp-ai-credentials',
            __( 'API Credentials', 'wp-mcp-ai' ),
            array( $this, 'render_credentials_meta_box' ),
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    /**
     * Render the credentials meta box content.
     *
     * @param WP_Post $post Post object.
     */
    public function render_credentials_meta_box( $post ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            echo '<p>' . esc_html__( 'You do not have permission to manage credentials.', 'wp-mcp-ai' ) . '</p>';
            return;
        }

        $credentials = WP_MCP_AI_Credentials::get_credentials( $post->ID );

        echo '<p>' . esc_html__( 'Issue tokens for remote integrations. Store the generated token securely; it will not be shown again.', 'wp-mcp-ai' ) . '</p>';

        if ( empty( $credentials ) ) {
            echo '<p>' . esc_html__( 'No credentials have been issued for this assistant.', 'wp-mcp-ai' ) . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Credential ID', 'wp-mcp-ai' ) . '</th>';
            echo '<th>' . esc_html__( 'Created', 'wp-mcp-ai' ) . '</th>';
            echo '<th>' . esc_html__( 'Status', 'wp-mcp-ai' ) . '</th>';
            echo '<th>' . esc_html__( 'Actions', 'wp-mcp-ai' ) . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ( $credentials as $credential ) {
                $created_at = ! empty( $credential['created_at'] ) ? get_date_from_gmt( $credential['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : __( 'Unknown', 'wp-mcp-ai' );
                $status      = __( 'Active', 'wp-mcp-ai' );
                $action_links = array();

                if ( ! empty( $credential['revoked_at'] ) ) {
                    $status = sprintf(
                        /* translators: %s: revocation timestamp */
                        __( 'Revoked %s', 'wp-mcp-ai' ),
                        get_date_from_gmt( $credential['revoked_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
                    );
                } else {
                    $action_links[] = $this->build_credential_action_button(
                        $post->ID,
                        $credential['id'],
                        'wp_mcp_ai_revoke_credential',
                        'wp_mcp_ai_revoke_credential_' . $post->ID . '_' . $credential['id'],
                        'wp_mcp_ai_revoke_nonce',
                        __( 'Revoke', 'wp-mcp-ai' ),
                        __( 'Revoke this credential? This action cannot be undone.', 'wp-mcp-ai' )
                    );
                }

                $action_links[] = $this->build_credential_action_button(
                    $post->ID,
                    $credential['id'],
                    'wp_mcp_ai_delete_credential',
                    'wp_mcp_ai_delete_credential_' . $post->ID . '_' . $credential['id'],
                    'wp_mcp_ai_delete_nonce',
                    __( 'Delete', 'wp-mcp-ai' ),
                    __( 'Delete this credential? This action cannot be undone.', 'wp-mcp-ai' ),
                    'button button-secondary delete'
                );

                $actions = empty( $action_links ) ? '&#8212;' : implode( ' ', $action_links );

                echo '<tr>';
                echo '<td><code>' . esc_html( $credential['id'] ) . '</code></td>';
                echo '<td>' . esc_html( $created_at ) . '</td>';
                echo '<td>' . esc_html( $status ) . '</td>';
                echo '<td>' . $actions . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        }

        $issue_url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'  => 'wp_mcp_ai_issue_credential',
                    'post_id' => $post->ID,
                ),
                admin_url( 'admin-post.php' )
            ),
            'wp_mcp_ai_issue_credential_' . $post->ID,
            'wp_mcp_ai_issue_nonce'
        );

        printf(
            '<p><a class="button button-secondary" href="%1$s">%2$s</a></p>',
            esc_url( $issue_url ),
            esc_html__( 'Generate Credential', 'wp-mcp-ai' )
        );

        $this->print_credential_action_script();
    }

    /**
     * Build the markup for a credential action button.
     *
     * @param int    $post_id        Assistant post ID.
     * @param string $credential_id  Credential identifier.
     * @param string $action         Admin-post action hook name.
     * @param string $nonce_action   Action name for nonce verification.
     * @param string $nonce_name     Nonce field name.
     * @param string $button_label   Button label.
     * @param string $confirm_prompt Confirmation prompt shown before submit.
     * @param string $button_class   CSS classes to apply to the button element.
     *
     * @return string
     */
    protected function build_credential_action_button( $post_id, $credential_id, $action, $nonce_action, $nonce_name, $button_label, $confirm_prompt, $button_class = 'button button-secondary' ) {
        $classes = trim( $button_class . ' wp-mcp-ai-credential-action' );
        $attributes = array(
            'type'              => 'button',
            'class'             => $classes,
            'data-action'       => $action,
            'data-post-id'      => $post_id,
            'data-credential-id'=> $credential_id,
            'data-nonce-name'   => $nonce_name,
            'data-nonce-value'  => wp_create_nonce( $nonce_action ),
            'data-endpoint'     => admin_url( 'admin-post.php' ),
        );

        if ( $confirm_prompt ) {
            $attributes['data-confirm'] = $confirm_prompt;
        }

        $attribute_string = '';
        foreach ( $attributes as $name => $value ) {
            if ( '' === $value || null === $value ) {
                continue;
            }

            $escaped_value = ( 'data-endpoint' === $name ) ? esc_url( $value ) : esc_attr( $value );
            $attribute_string .= sprintf( ' %s="%s"', esc_attr( $name ), $escaped_value );
        }

        return sprintf( '<button%1$s>%2$s</button>', $attribute_string, esc_html( $button_label ) );
    }

    /**
     * Print the JavaScript required to submit credential action buttons as POST requests.
     */
    protected function print_credential_action_script() {
        if ( self::$credential_action_script_printed ) {
            return;
        }

        self::$credential_action_script_printed = true;
        ?>
        <script type="text/javascript">
        ( function() {
            function submitCredentialAction( button ) {
                if ( ! button ) {
                    return;
                }

                var confirmMessage = button.getAttribute( 'data-confirm' );
                if ( confirmMessage && ! window.confirm( confirmMessage ) ) {
                    return;
                }

                var endpoint = button.getAttribute( 'data-endpoint' );
                if ( ! endpoint ) {
                    return;
                }

                var form = document.createElement( 'form' );
                form.method = 'post';
                form.action = endpoint;
                form.style.display = 'none';

                var fields = {
                    action: button.getAttribute( 'data-action' ),
                    post_id: button.getAttribute( 'data-post-id' ),
                    credential_id: button.getAttribute( 'data-credential-id' )
                };

                var nonceName = button.getAttribute( 'data-nonce-name' );
                var nonceValue = button.getAttribute( 'data-nonce-value' );

                if ( nonceName && nonceValue ) {
                    fields[ nonceName ] = nonceValue;
                }

                for ( var key in fields ) {
                    if ( Object.prototype.hasOwnProperty.call( fields, key ) && fields[ key ] ) {
                        var input = document.createElement( 'input' );
                        input.type = 'hidden';
                        input.name = key;
                        input.value = fields[ key ];
                        form.appendChild( input );
                    }
                }

                document.body.appendChild( form );
                form.submit();
            }

            document.addEventListener( 'click', function( event ) {
                var target = event.target;
                if ( target && target.classList && target.classList.contains( 'wp-mcp-ai-credential-action' ) ) {
                    event.preventDefault();
                    submitCredentialAction( target );
                }
            } );
        } )();
        </script>
        <?php
    }

    /**
     * Generate a nonce field name unique to a credential.
     *
     * @param string $base_name     Base nonce field name.
     * @param string $credential_id Credential identifier.
     * @return string
     */
    protected function get_credential_nonce_field_name( $base_name, $credential_id ) {
        $suffix = sanitize_key( $credential_id );

        if ( '' === $suffix ) {
            return $base_name;
        }

        return $base_name . '_' . $suffix;
    }

    /**
     * Render the tools meta box content.
     *
     * @param WP_Post $post Post object.
     */
    public function render_tools_meta_box( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
        }

        wp_nonce_field( 'wp_mcp_ai_tools_meta', 'wp_mcp_ai_tools_meta_nonce' );

        $selected_tools = get_post_meta( $post->ID, self::META_TOOLS, true );
        if ( ! is_array( $selected_tools ) ) {
            $selected_tools = array();
        }

        $tools = $this->registry->get_tools();

        $disable_tool_shortcuts = get_post_meta( $post->ID, self::META_DISABLE_TOOL_SHORTCUTS, true );
        $disable_tool_shortcuts = self::sanitize_disable_tool_shortcuts_meta( $disable_tool_shortcuts );

        $prebuilt_shortcuts = get_post_meta( $post->ID, self::META_TOOL_PREBUILT_SHORTCUTS, true );
        if ( ! is_array( $prebuilt_shortcuts ) ) {
            $prebuilt_shortcuts = array();
        }

        $prebuilt_shortcuts = self::sanitize_prebuilt_tool_shortcuts_meta( $prebuilt_shortcuts );

        $tool_role_rules = get_post_meta( $post->ID, self::META_TOOL_ROLE_RULES, true );
        if ( ! is_array( $tool_role_rules ) ) {
            $tool_role_rules = array();
        }

        $tool_role_rules = self::sanitize_tool_role_rules_meta( $tool_role_rules );

        $tool_role_rules_by_slug = array();

        foreach ( $tool_role_rules as $rule ) {
            if ( isset( $rule['tool'] ) ) {
                $tool_role_rules_by_slug[ $rule['tool'] ] = $rule;
            }
        }

        $external_action_id   = get_post_meta( $post->ID, self::META_EXTERNAL_ACTION_ID, true );
        $external_action_id   = self::sanitize_external_action_id_meta( $external_action_id );
        $external_action_type = get_post_meta( $post->ID, self::META_EXTERNAL_ACTION_TYPE, true );
        $external_action_type = self::sanitize_external_action_type_meta( $external_action_type );

        if ( empty( $tools ) ) {
            echo '<p>' . esc_html__( 'No tools are currently registered.', 'wp-mcp-ai' ) . '</p>';
            return;
        }

        echo '<p>' . esc_html__( 'Select the tools this assistant is permitted to invoke.', 'wp-mcp-ai' ) . '</p>';
        echo '<p class="description">' . esc_html__( 'Expand a group to review related capabilities. You can optionally limit who can call each tool by assigning WordPress roles.', 'wp-mcp-ai' ) . '</p>';

        echo '<fieldset class="wp-mcp-ai-tools__shortcuts-toggle">';
        echo '<legend class="screen-reader-text">' . esc_html__( 'Tool shortcut options', 'wp-mcp-ai' ) . '</legend>';
        echo '<label for="wp-mcp-ai-disable-tool-shortcuts" class="wp-mcp-ai-tools__shortcuts-toggle-label">';
        printf(
            '<input type="checkbox" id="wp-mcp-ai-disable-tool-shortcuts" name="wp_mcp_ai_disable_prebuilt_shortcuts" value="1" %s />',
            checked( $disable_tool_shortcuts, true, false )
        );
        echo '<span>' . esc_html__( 'Disable pre-built prompt shortcuts from selected tools', 'wp-mcp-ai' ) . '</span>';
        echo '</label>';
        echo '<p class="description">' . esc_html__( 'When enabled, only the custom shortcuts you define below will appear in the chat interface.', 'wp-mcp-ai' ) . '</p>';
        echo '</fieldset>';

        $group_map = array();
        if ( method_exists( $this->registry, 'get_tool_group_map' ) ) {
            $group_map = $this->registry->get_tool_group_map();
        }
        if ( ! is_array( $group_map ) ) {
            $group_map = array();
        }

        $group_labels = array();
        if ( method_exists( $this->registry, 'get_tool_group_labels' ) ) {
            $group_labels = $this->registry->get_tool_group_labels();
        }
        if ( ! is_array( $group_labels ) ) {
            $group_labels = array();
        }
        if ( ! isset( $group_labels['other'] ) ) {
            $group_labels['other'] = __( 'Other tools', 'wp-mcp-ai' );
        }

        $grouped_tools = array();

        foreach ( $tools as $tool ) {
            if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
                continue;
            }

            $slug = $tool->get_slug();

            if ( '' === $slug ) {
                continue;
            }

            $group_id = isset( $group_map[ $slug ] ) ? (string) $group_map[ $slug ] : 'other';

            if ( '' === $group_id ) {
                $group_id = 'other';
            }

            if ( ! isset( $grouped_tools[ $group_id ] ) ) {
                $grouped_tools[ $group_id ] = array();
            }

            $grouped_tools[ $group_id ][] = $tool;
        }

        if ( empty( $grouped_tools ) ) {
            echo '<p>' . esc_html__( 'No tools are currently registered.', 'wp-mcp-ai' ) . '</p>';
            return;
        }

        $ordered_group_ids = array();

        foreach ( $group_labels as $group_id => $label ) {
            if ( isset( $grouped_tools[ $group_id ] ) ) {
                $ordered_group_ids[] = (string) $group_id;
            }
        }

        foreach ( $grouped_tools as $group_id => $unused ) {
            if ( ! in_array( $group_id, $ordered_group_ids, true ) ) {
                $ordered_group_ids[] = (string) $group_id;
            }
        }

        $role_options = array();

        if ( function_exists( 'get_editable_roles' ) ) {
            $editable_roles = get_editable_roles();

            if ( is_array( $editable_roles ) ) {
                foreach ( $editable_roles as $role_slug => $role_details ) {
                    $role_slug = sanitize_key( $role_slug );

                    if ( '' === $role_slug ) {
                        continue;
                    }

                    $role_name = isset( $role_details['name'] ) ? (string) $role_details['name'] : $role_slug;

                    $role_options[ $role_slug ] = translate_user_role( $role_name );
                }
            }
        }

        if ( empty( $role_options ) ) {
            $registered_roles = self::get_registered_role_slugs();

            foreach ( $registered_roles as $role_slug ) {
                if ( '' === $role_slug ) {
                    continue;
                }

                $role_options[ $role_slug ] = ucwords( str_replace( '_', ' ', $role_slug ) );
            }
        }

        if ( ! empty( $role_options ) ) {
            uasort( $role_options, 'strnatcasecmp' );
        }

        static $tools_styles_printed = false;

        if ( ! $tools_styles_printed ) {
            $tools_styles_printed = true;
            ?>
            <style>
            .wp-mcp-ai-tools{display:flex;flex-direction:column;gap:1rem;margin-top:1rem}
            .wp-mcp-ai-tools__group{border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7}
            .wp-mcp-ai-tools__group summary{list-style:none;cursor:pointer;padding:0.75rem 1rem;display:flex;align-items:center;gap:0.75rem;font-weight:600;outline:none}
            .wp-mcp-ai-tools__group summary::-webkit-details-marker{display:none}
            .wp-mcp-ai-tools__summary-title{flex:1 1 auto}
            .wp-mcp-ai-tools__summary-count{font-size:0.875rem;color:#50575e;background:#fff;border:1px solid #dcdcde;border-radius:999px;padding:0 0.5rem;line-height:1.6}
            .wp-mcp-ai-tools__group[open]{background:#fff}
            .wp-mcp-ai-tools__group[open] summary{border-bottom:1px solid #dcdcde}
            .wp-mcp-ai-tools__list{margin:0;padding:1rem;list-style:none;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem}
            .wp-mcp-ai-tools__item{border:1px solid #dcdcde;border-radius:4px;background:#fff;padding:1rem;display:flex;flex-direction:column;gap:0.5rem;transition:box-shadow 0.2s ease}
            .wp-mcp-ai-tools__item:focus-within{box-shadow:0 0 0 1px #2271b1}
            .wp-mcp-ai-tools__header{display:flex;align-items:flex-start;gap:0.75rem}
            .wp-mcp-ai-tools__checkbox{margin-top:0.2rem}
            .wp-mcp-ai-tools__name{display:block;font-weight:600;font-size:14px}
            .wp-mcp-ai-tools__description{margin:0;color:#50575e;font-size:13px}
            .wp-mcp-ai-tools__controls label{font-weight:600;font-size:13px;margin-bottom:0.25rem;display:block}
            .wp-mcp-ai-tools__role-select{width:100%}
            .wp-mcp-ai-tools__helper{margin:0;color:#646970;font-size:12px}
            .wp-mcp-ai-tools__extra{margin-top:0.5rem;padding-top:0.5rem;border-top:1px solid #dcdcde}
            .wp-mcp-ai-tools__item[data-tool-selected="false"]{opacity:0.75}
            .wp-mcp-ai-tools__item[data-tool-selected="false"] .wp-mcp-ai-tools__extra{display:none}
            .wp-mcp-ai-tools__shortcuts-toggle{margin:1rem 0 0;padding:1rem;border:1px solid #dcdcde;border-radius:4px;background:#fff;display:flex;flex-direction:column;gap:0.5rem}
            .wp-mcp-ai-tools__shortcuts-toggle-label{font-weight:600;display:flex;align-items:center;gap:0.5rem;font-size:14px}
            .wp-mcp-ai-tools__shortcuts-toggle .description{margin:0;font-size:13px;color:#50575e}
            .wp-mcp-ai-prebuilt-shortcuts{margin-top:1.5rem;padding:1.5rem;border:1px solid #dcdcde;border-radius:4px;background:#fff;display:flex;flex-direction:column;gap:1rem}
            .wp-mcp-ai-prebuilt-shortcuts h3{margin:0;font-size:16px}
            .wp-mcp-ai-prebuilt-shortcuts__tool{border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7}
            .wp-mcp-ai-prebuilt-shortcuts__summary{list-style:none;cursor:pointer;padding:0.75rem 1rem;display:flex;align-items:center;gap:0.75rem;font-weight:600;outline:none}
            .wp-mcp-ai-prebuilt-shortcuts__summary::-webkit-details-marker{display:none}
            .wp-mcp-ai-prebuilt-shortcuts__summary-title{flex:1 1 auto}
            .wp-mcp-ai-prebuilt-shortcuts__summary-mode{font-size:0.875rem;color:#50575e;background:#fff;border:1px solid #dcdcde;border-radius:999px;padding:0 0.5rem;line-height:1.6}
            .wp-mcp-ai-prebuilt-shortcuts__tool[open]{background:#fff}
            .wp-mcp-ai-prebuilt-shortcuts__tool[open] .wp-mcp-ai-prebuilt-shortcuts__summary{border-bottom:1px solid #dcdcde}
            .wp-mcp-ai-prebuilt-shortcuts__content{padding:1rem;display:flex;flex-direction:column;gap:1rem;border-top:1px solid #dcdcde}
            .wp-mcp-ai-prebuilt-shortcuts__mode{display:flex;flex-wrap:wrap;gap:1rem;margin:0}
            .wp-mcp-ai-prebuilt-shortcuts__mode label{display:flex;align-items:center;gap:0.5rem;font-weight:600}
            .wp-mcp-ai-prebuilt-shortcuts__defaults{margin:0}
            .wp-mcp-ai-prebuilt-shortcuts__defaults p{margin:0;color:#50575e;font-size:13px}
            .wp-mcp-ai-prebuilt-shortcuts__defaults-list{margin:0.5rem 0 0;padding-left:1.25rem}
            .wp-mcp-ai-prebuilt-shortcuts__defaults-list li{margin-bottom:0.5rem;font-size:13px}
            .wp-mcp-ai-prebuilt-shortcuts__defaults-summary{display:block;color:#50575e;font-size:12px;margin-top:0.25rem}
            .wp-mcp-ai-prebuilt-shortcuts__rows{display:flex;flex-direction:column;gap:1rem}
            .wp-mcp-ai-prebuilt-shortcuts__row{border:1px solid #dcdcde;border-radius:4px;padding:1rem;background:#fff}
            .wp-mcp-ai-prebuilt-shortcuts__row hr{margin:1rem -1rem 0}
            @media (max-width:782px){.wp-mcp-ai-tools__list{grid-template-columns:1fr}}
            </style>
            <?php
        }

        static $tools_script_printed = false;

        if ( ! $tools_script_printed ) {
            $tools_script_printed = true;
            ?>
            <script>
            ( function() {
                function syncToolControls( container ) {
                    if ( ! container ) {
                        return;
                    }

                    var checkbox = container.querySelector( '.wp-mcp-ai-tools__checkbox' );
                    if ( ! checkbox ) {
                        return;
                    }

                    var controls = container.querySelectorAll( '[data-tool-control]' );
                    var selected = checkbox.checked;

                    container.setAttribute( 'data-tool-selected', selected ? 'true' : 'false' );

                    controls.forEach( function( control ) {
                        if ( selected ) {
                            control.removeAttribute( 'disabled' );
                            control.setAttribute( 'aria-disabled', 'false' );
                        } else {
                            control.setAttribute( 'disabled', 'disabled' );
                            control.setAttribute( 'aria-disabled', 'true' );
                        }
                    } );
                }

                document.addEventListener( 'DOMContentLoaded', function() {
                    var toolItems = document.querySelectorAll( '.wp-mcp-ai-tools__item' );
                    var prebuiltTemplate = document.getElementById( 'wp-mcp-ai-prebuilt-shortcut-template' );

                    toolItems.forEach( function( item ) {
                        var checkbox = item.querySelector( '.wp-mcp-ai-tools__checkbox' );

                        if ( ! checkbox ) {
                            return;
                        }

                        syncToolControls( item );

                        checkbox.addEventListener( 'change', function() {
                            syncToolControls( item );
                        } );
                    } );

                    if ( ! prebuiltTemplate ) {
                        return;
                    }

                    var prebuiltFieldsets = document.querySelectorAll( '.wp-mcp-ai-prebuilt-shortcuts__tool' );

                    prebuiltFieldsets.forEach( function( fieldset ) {
                        var rowsContainer = fieldset.querySelector( '.wp-mcp-ai-prebuilt-shortcuts__rows' );
                        var addButton = fieldset.querySelector( '.wp-mcp-ai-prebuilt-shortcuts__add' );
                        var modeInputs = fieldset.querySelectorAll( '.wp-mcp-ai-prebuilt-shortcuts__mode input[type="radio"]' );
                        var defaults = [];
                        var datasetDefaults = fieldset.getAttribute( 'data-defaults' );
                        var hasExistingCustom = fieldset.getAttribute( 'data-has-existing-custom' ) === 'true';
                        var summaryModeElement = fieldset.querySelector( '.wp-mcp-ai-prebuilt-shortcuts__summary-mode' );
                        var modeLabelInherit = fieldset.getAttribute( 'data-mode-label-inherit' ) || '';
                        var modeLabelCustom = fieldset.getAttribute( 'data-mode-label-custom' ) || '';

                        if ( datasetDefaults ) {
                            try {
                                defaults = JSON.parse( datasetDefaults ) || [];
                            } catch ( error ) {
                                defaults = [];
                            }
                        }

                        function getNextIndex() {
                            if ( ! rowsContainer ) {
                                return 0;
                            }

                            var next = parseInt( rowsContainer.getAttribute( 'data-next-index' ), 10 );

                            if ( isNaN( next ) ) {
                                next = rowsContainer.querySelectorAll( '.wp-mcp-ai-prebuilt-shortcuts__row' ).length;
                            }

                            rowsContainer.setAttribute( 'data-next-index', next + 1 );

                            return next;
                        }

                        function addRow( index, values ) {
                            if ( ! rowsContainer ) {
                                return null;
                            }

                            var fragment = document.importNode( prebuiltTemplate.content, true );
                            var html = '';

                            if ( fragment.firstElementChild ) {
                                html = fragment.firstElementChild.outerHTML;
                            } else if ( fragment.children.length ) {
                                html = fragment.children[0].outerHTML;
                            }

                            if ( ! html ) {
                                return null;
                            }

                            var tool = fieldset.getAttribute( 'data-tool' ) || '';
                            html = html.replace( /__INDEX__/g, index );
                            html = html.replace( /__TOOL__/g, tool );

                            var wrapper = document.createElement( 'div' );
                            wrapper.innerHTML = html;
                            var row = wrapper.firstElementChild;

                            if ( ! row ) {
                                return null;
                            }

                            rowsContainer.appendChild( row );

                            if ( values && typeof values === 'object' ) {
                                var labelField = row.querySelector( 'input[name*="[label]"]' );
                                var payloadField = row.querySelector( 'textarea[name*="[payload]"]' );
                                var descriptionField = row.querySelector( 'textarea[name*="[description]"]' );

                                if ( labelField && typeof values.label === 'string' ) {
                                    labelField.value = values.label;
                                }

                                if ( payloadField && typeof values.payload === 'string' ) {
                                    payloadField.value = values.payload;
                                }

                                if ( descriptionField && typeof values.description === 'string' ) {
                                    descriptionField.value = values.description;
                                }
                            }

                            return row;
                        }

                        function setFieldsDisabled( disabledState ) {
                            if ( rowsContainer ) {
                                var fields = rowsContainer.querySelectorAll( 'input, textarea' );
                                fields.forEach( function( field ) {
                                    if ( disabledState ) {
                                        field.setAttribute( 'disabled', 'disabled' );
                                    } else {
                                        field.removeAttribute( 'disabled' );
                                    }
                                } );

                                var removeButtons = rowsContainer.querySelectorAll( '.wp-mcp-ai-prebuilt-shortcuts__remove' );
                                removeButtons.forEach( function( button ) {
                                    if ( disabledState ) {
                                        button.setAttribute( 'disabled', 'disabled' );
                                    } else {
                                        button.removeAttribute( 'disabled' );
                                    }
                                } );
                            }

                            if ( addButton ) {
                                if ( disabledState ) {
                                    addButton.setAttribute( 'disabled', 'disabled' );
                                } else {
                                    addButton.removeAttribute( 'disabled' );
                                }
                            }
                        }

                        function ensureDefaultRows() {
                            if ( ! rowsContainer || rowsContainer.querySelector( '.wp-mcp-ai-prebuilt-shortcuts__row' ) ) {
                                return;
                            }

                            if ( ! defaults.length ) {
                                return;
                            }

                            defaults.forEach( function( shortcut ) {
                                var index = getNextIndex();
                                addRow( index, shortcut );
                            } );
                        }

                        function toggleMode( mode ) {
                            var isCustom = mode === 'custom';

                            if ( rowsContainer ) {
                                if ( isCustom ) {
                                    rowsContainer.removeAttribute( 'hidden' );
                                    rowsContainer.setAttribute( 'aria-hidden', 'false' );
                                } else {
                                    rowsContainer.setAttribute( 'hidden', 'hidden' );
                                    rowsContainer.setAttribute( 'aria-hidden', 'true' );
                                }
                            }

                            if ( summaryModeElement ) {
                                summaryModeElement.textContent = isCustom
                                    ? ( modeLabelCustom || summaryModeElement.textContent )
                                    : ( modeLabelInherit || summaryModeElement.textContent );
                            }

                            if ( isCustom && ! hasExistingCustom ) {
                                ensureDefaultRows();
                                hasExistingCustom = true;
                                fieldset.setAttribute( 'data-has-existing-custom', 'true' );
                            }

                            if ( isCustom ) {
                                fieldset.setAttribute( 'open', 'open' );
                            }

                            setFieldsDisabled( ! isCustom );
                        }

                        if ( addButton ) {
                            addButton.addEventListener( 'click', function() {
                                var index = getNextIndex();
                                addRow( index );
                            } );
                        }

                        if ( rowsContainer ) {
                            rowsContainer.addEventListener( 'click', function( event ) {
                                var target = event.target;

                                if ( target && target.classList && target.classList.contains( 'wp-mcp-ai-prebuilt-shortcuts__remove' ) ) {
                                    event.preventDefault();

                                    var row = target.closest( '.wp-mcp-ai-prebuilt-shortcuts__row' );

                                    if ( row && rowsContainer.contains( row ) ) {
                                        rowsContainer.removeChild( row );
                                    }
                                }
                            } );
                        }

                        modeInputs.forEach( function( input ) {
                            input.addEventListener( 'change', function() {
                                if ( input.checked ) {
                                    toggleMode( input.value );
                                }
                            } );
                        } );

                        var initialMode = 'inherit';

                        modeInputs.forEach( function( input ) {
                            if ( input.checked ) {
                                initialMode = input.value;
                            }
                        } );

                        toggleMode( initialMode );
                    } );
                } );
            } )();
            </script>
            <?php
        }

        echo '<div class="wp-mcp-ai-tools" role="group" aria-label="' . esc_attr__( 'Assistant tool permissions', 'wp-mcp-ai' ) . '">';

        foreach ( $ordered_group_ids as $group_index => $group_id ) {
            if ( ! isset( $grouped_tools[ $group_id ] ) || empty( $grouped_tools[ $group_id ] ) ) {
                continue;
            }

            $group_label = isset( $group_labels[ $group_id ] ) ? $group_labels[ $group_id ] : ucwords( str_replace( '-', ' ', (string) $group_id ) );
            $group_label = (string) $group_label;
            $group_count = count( $grouped_tools[ $group_id ] );
            $group_suffix = sanitize_html_class( $group_id );
            $summary_id  = 'wp-mcp-ai-tools-summary-' . $group_suffix;
            $list_id     = 'wp-mcp-ai-tools-list-' . $group_suffix;
            $open_attr   = 0 === $group_index ? ' open' : '';

            echo '<details class="wp-mcp-ai-tools__group" role="group" aria-labelledby="' . esc_attr( $summary_id ) . '"' . $open_attr . '>';
            echo '<summary id="' . esc_attr( $summary_id ) . '" class="wp-mcp-ai-tools__summary">';
            echo '<span class="wp-mcp-ai-tools__summary-title">' . esc_html( $group_label ) . '</span>';
            echo '<span class="wp-mcp-ai-tools__summary-count" aria-hidden="true">' . esc_html( number_format_i18n( $group_count ) ) . '</span>';
            echo '<span class="screen-reader-text">' . esc_html( sprintf( _n( '%d tool in this group', '%d tools in this group', $group_count, 'wp-mcp-ai' ), $group_count ) ) . '</span>';
            echo '</summary>';
            echo '<ul class="wp-mcp-ai-tools__list" id="' . esc_attr( $list_id ) . '" role="group" aria-label="' . esc_attr( $group_label ) . '">';

            foreach ( $grouped_tools[ $group_id ] as $tool ) {
                $slug = $tool->get_slug();

                if ( '' === $slug ) {
                    continue;
                }

                $is_selected     = in_array( $slug, $selected_tools, true );
                $checkbox_id     = 'wp-mcp-ai-tool-' . sanitize_html_class( $slug );
                $description_id  = 'wp-mcp-ai-tool-description-' . sanitize_html_class( $slug );
                $role_select_id  = 'wp-mcp-ai-tool-roles-' . sanitize_html_class( $slug );
                $role_helper_id  = $role_select_id . '-help';
                $control_disabled = $is_selected ? '' : ' disabled="disabled"';
                $aria_disabled    = $is_selected ? 'false' : 'true';
                $selected_roles   = isset( $tool_role_rules_by_slug[ $slug ]['roles'] ) ? (array) $tool_role_rules_by_slug[ $slug ]['roles'] : array();
                $persisted_groups = isset( $tool_role_rules_by_slug[ $slug ]['groups'] ) ? (array) $tool_role_rules_by_slug[ $slug ]['groups'] : array();
                $persisted_flags  = isset( $tool_role_rules_by_slug[ $slug ]['flags'] ) ? (array) $tool_role_rules_by_slug[ $slug ]['flags'] : array();
                $select_size      = ! empty( $role_options ) ? min( max( count( $role_options ), 4 ), 8 ) : 4;

                echo '<li class="wp-mcp-ai-tools__item" data-tool-selected="' . ( $is_selected ? 'true' : 'false' ) . '">';
                echo '<div class="wp-mcp-ai-tools__header">';
                printf(
                    '<input type="checkbox" class="wp-mcp-ai-tools__checkbox" id="%1$s" name="wp_mcp_ai_tools[]" value="%2$s" %3$s aria-describedby="%4$s" />',
                    esc_attr( $checkbox_id ),
                    esc_attr( $slug ),
                    checked( $is_selected, true, false ),
                    esc_attr( $description_id )
                );
                echo '<label for="' . esc_attr( $checkbox_id ) . '">';
                echo '<span class="wp-mcp-ai-tools__name">' . esc_html( $tool->get_name() ) . '</span>';
                echo '<p class="wp-mcp-ai-tools__description" id="' . esc_attr( $description_id ) . '">' . esc_html( $tool->get_description() ) . '</p>';
                echo '</label>';
                echo '</div>';

                echo '<input type="hidden" name="wp_mcp_ai_tool_role_rules[' . esc_attr( $slug ) . '][tool]" value="' . esc_attr( $slug ) . '" data-tool-control="1"' . $control_disabled . ' />';

                foreach ( $persisted_groups as $group_value ) {
                    echo '<input type="hidden" name="wp_mcp_ai_tool_role_rules[' . esc_attr( $slug ) . '][groups][]" value="' . esc_attr( (string) absint( $group_value ) ) . '" data-tool-control="1"' . $control_disabled . ' />';
                }

                foreach ( $persisted_flags as $flag_value ) {
                    $flag_value = sanitize_key( $flag_value );

                    if ( '' === $flag_value ) {
                        continue;
                    }

                    echo '<input type="hidden" name="wp_mcp_ai_tool_role_rules[' . esc_attr( $slug ) . '][flags][]" value="' . esc_attr( $flag_value ) . '" data-tool-control="1"' . $control_disabled . ' />';
                }

                echo '<div class="wp-mcp-ai-tools__controls">';

                if ( ! empty( $role_options ) ) {
                    echo '<label for="' . esc_attr( $role_select_id ) . '">' . esc_html__( 'Limit to selected roles', 'wp-mcp-ai' ) . '</label>';
                    echo '<select id="' . esc_attr( $role_select_id ) . '" name="wp_mcp_ai_tool_role_rules[' . esc_attr( $slug ) . '][roles][]" class="wp-mcp-ai-tools__role-select" multiple size="' . esc_attr( $select_size ) . '" data-tool-control="1"' . $control_disabled . ' aria-describedby="' . esc_attr( $role_helper_id ) . '" aria-disabled="' . esc_attr( $aria_disabled ) . '">';

                    foreach ( $role_options as $role_slug => $role_label ) {
                        printf(
                            '<option value="%1$s" %2$s>%3$s</option>',
                            esc_attr( $role_slug ),
                            selected( in_array( $role_slug, $selected_roles, true ), true, false ),
                            esc_html( $role_label )
                        );
                    }

                    echo '</select>';
                    echo '<p class="description wp-mcp-ai-tools__helper" id="' . esc_attr( $role_helper_id ) . '">' . esc_html__( 'Hold Ctrl (Windows) or Command (macOS) to toggle multiple roles. Leave blank to allow any role with access to the assistant interface.', 'wp-mcp-ai' ) . '</p>';
                } else {
                    echo '<p class="description wp-mcp-ai-tools__helper" id="' . esc_attr( $role_helper_id ) . '">' . esc_html__( 'No editable roles are available. All authenticated operators will be able to request this tool.', 'wp-mcp-ai' ) . '</p>';
                }

                if ( 'run_openai_external_action' === $slug ) {
                    $identifier_field_id = 'wp-mcp-ai-external-action-id';
                    $type_field_id       = 'wp-mcp-ai-external-action-type';
                    ?>
                    <div class="wp-mcp-ai-tools__extra">
                        <p>
                            <label for="<?php echo esc_attr( $identifier_field_id ); ?>">
                                <strong><?php esc_html_e( 'Default workflow or assistant ID', 'wp-mcp-ai' ); ?></strong>
                            </label>
                            <input
                                type="text"
                                id="<?php echo esc_attr( $identifier_field_id ); ?>"
                                name="wp_mcp_ai_external_action_identifier"
                                value="<?php echo esc_attr( $external_action_id ); ?>"
                                class="widefat"
                                data-tool-control="1"<?php echo $control_disabled; ?>
                            />
                        </p>
                        <p>
                            <label for="<?php echo esc_attr( $type_field_id ); ?>">
                                <strong><?php esc_html_e( 'Default action type', 'wp-mcp-ai' ); ?></strong>
                            </label>
                            <select id="<?php echo esc_attr( $type_field_id ); ?>" name="wp_mcp_ai_external_action_type" class="widefat" data-tool-control="1"<?php echo $control_disabled; ?>>
                                <option value="">
                                    <?php esc_html_e( 'Use runtime choice', 'wp-mcp-ai' ); ?>
                                </option>
                                <option value="workflow" <?php selected( $external_action_type, 'workflow' ); ?>>
                                    <?php esc_html_e( 'Workflow', 'wp-mcp-ai' ); ?>
                                </option>
                                <option value="assistant" <?php selected( $external_action_type, 'assistant' ); ?>>
                                    <?php esc_html_e( 'Assistant', 'wp-mcp-ai' ); ?>
                                </option>
                            </select>
                        </p>
                    </div>
                    <?php
                }

                echo '</div>';
                echo '</li>';
            }

            echo '</ul>';
            echo '</details>';
        }

        echo '</div>';

        $this->render_prebuilt_shortcuts_editor( $post, $selected_tools, $prebuilt_shortcuts );
    }

    /**
     * Render the tool shortcuts meta box content.
     *
     * @param WP_Post $post Post object.
     */
    public function render_tool_shortcuts_meta_box( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
        }

        wp_nonce_field( 'wp_mcp_ai_tool_shortcuts_meta', 'wp_mcp_ai_tool_shortcuts_meta_nonce' );

        $shortcuts = get_post_meta( $post->ID, self::META_TOOL_SHORTCUTS, true );
        if ( ! is_array( $shortcuts ) ) {
            $shortcuts = array();
        }

        $shortcuts = self::sanitize_tool_shortcuts_meta( $shortcuts );

        if ( empty( $shortcuts ) ) {
            $shortcuts = array(
                array(
                    'label'       => '',
                    'payload'     => '',
                    'tool'        => '',
                    'description' => '',
                ),
            );
        }

        $tools          = $this->registry->get_tools();
        $tool_options   = array( '' => __( 'No specific tool', 'wp-mcp-ai' ) );
        $selected_tools = get_post_meta( $post->ID, self::META_TOOLS, true );
        if ( ! is_array( $selected_tools ) ) {
            $selected_tools = array();
        }

        foreach ( $tools as $tool ) {
            $slug = $tool->get_slug();

            if ( ! empty( $selected_tools ) && ! in_array( $slug, $selected_tools, true ) ) {
                continue;
            }

            $tool_options[ $slug ] = $tool->get_name();
        }

        ?>
        <p><?php esc_html_e( 'Create ready-to-use prompts that will show as shortcuts in the chat interface for this assistant.', 'wp-mcp-ai' ); ?></p>
        <div
            id="wp-mcp-ai-tool-shortcuts-rows"
            class="wp-mcp-ai-tool-shortcuts"
            data-next-index="<?php echo esc_attr( count( $shortcuts ) ); ?>"
        >
            <?php foreach ( $shortcuts as $index => $shortcut ) :
                $label       = isset( $shortcut['label'] ) ? $shortcut['label'] : '';
                $payload     = isset( $shortcut['payload'] ) ? $shortcut['payload'] : '';
                $tool        = isset( $shortcut['tool'] ) ? $shortcut['tool'] : '';
                $description = isset( $shortcut['description'] ) ? $shortcut['description'] : '';
                ?>
                <fieldset class="wp-mcp-ai-tool-shortcuts__row" data-index="<?php echo esc_attr( $index ); ?>">
                    <legend class="screen-reader-text">
                        <?php printf( esc_html__( 'Shortcut %d', 'wp-mcp-ai' ), intval( $index ) + 1 ); ?>
                    </legend>
                    <p>
                        <label>
                            <strong><?php esc_html_e( 'Shortcut label', 'wp-mcp-ai' ); ?></strong>
                            <input
                                type="text"
                                class="widefat"
                                name="wp_mcp_ai_tool_shortcuts[<?php echo esc_attr( $index ); ?>][label]"
                                value="<?php echo esc_attr( $label ); ?>"
                            />
                        </label>
                    </p>
                    <p>
                        <label>
                            <strong><?php esc_html_e( 'Prompt text', 'wp-mcp-ai' ); ?></strong>
                            <textarea
                                class="widefat"
                                rows="4"
                                name="wp_mcp_ai_tool_shortcuts[<?php echo esc_attr( $index ); ?>][payload]"
                            ><?php echo esc_textarea( $payload ); ?></textarea>
                        </label>
                    </p>
                    <p>
                        <label>
                            <strong><?php esc_html_e( 'Optional description', 'wp-mcp-ai' ); ?></strong>
                            <textarea
                                class="widefat"
                                rows="3"
                                name="wp_mcp_ai_tool_shortcuts[<?php echo esc_attr( $index ); ?>][description]"
                            ><?php echo esc_textarea( $description ); ?></textarea>
                        </label>
                    </p>
                    <p>
                        <label>
                            <strong><?php esc_html_e( 'Associated tool', 'wp-mcp-ai' ); ?></strong>
                            <select
                                class="widefat"
                                name="wp_mcp_ai_tool_shortcuts[<?php echo esc_attr( $index ); ?>][tool]"
                            >
                                <?php foreach ( $tool_options as $slug => $name ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $tool, $slug ); ?>>
                                        <?php echo esc_html( $name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </p>
                    <p>
                        <button type="button" class="button-link-delete wp-mcp-ai-remove-tool-shortcut">
                            <?php esc_html_e( 'Remove shortcut', 'wp-mcp-ai' ); ?>
                        </button>
                    </p>
                    <hr />
                </fieldset>
            <?php endforeach; ?>
        </div>
        <p>
            <button type="button" class="button" id="wp-mcp-ai-add-tool-shortcut">
                <?php esc_html_e( 'Add shortcut', 'wp-mcp-ai' ); ?>
            </button>
        </p>

        <template id="wp-mcp-ai-tool-shortcut-template">
            <fieldset class="wp-mcp-ai-tool-shortcuts__row" data-index="__INDEX__">
                <legend class="screen-reader-text"><?php esc_html_e( 'New shortcut', 'wp-mcp-ai' ); ?></legend>
                <p>
                    <label>
                        <strong><?php esc_html_e( 'Shortcut label', 'wp-mcp-ai' ); ?></strong>
                        <input type="text" class="widefat" name="wp_mcp_ai_tool_shortcuts[__INDEX__][label]" />
                    </label>
                </p>
                <p>
                    <label>
                        <strong><?php esc_html_e( 'Prompt text', 'wp-mcp-ai' ); ?></strong>
                        <textarea class="widefat" rows="4" name="wp_mcp_ai_tool_shortcuts[__INDEX__][payload]"></textarea>
                    </label>
                </p>
                <p>
                    <label>
                        <strong><?php esc_html_e( 'Optional description', 'wp-mcp-ai' ); ?></strong>
                        <textarea class="widefat" rows="3" name="wp_mcp_ai_tool_shortcuts[__INDEX__][description]"></textarea>
                    </label>
                </p>
                <p>
                    <label>
                        <strong><?php esc_html_e( 'Associated tool', 'wp-mcp-ai' ); ?></strong>
                        <select class="widefat" name="wp_mcp_ai_tool_shortcuts[__INDEX__][tool]">
                            <?php foreach ( $tool_options as $slug => $name ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p>
                    <button type="button" class="button-link-delete wp-mcp-ai-remove-tool-shortcut"><?php esc_html_e( 'Remove shortcut', 'wp-mcp-ai' ); ?></button>
                </p>
                <hr />
            </fieldset>
        </template>

        <script>
        ( function() {
            var container = document.getElementById( 'wp-mcp-ai-tool-shortcuts-rows' );
            var addButton = document.getElementById( 'wp-mcp-ai-add-tool-shortcut' );
            var template = document.getElementById( 'wp-mcp-ai-tool-shortcut-template' );

            if ( ! container || ! addButton || ! template ) {
                return;
            }

            function getNextIndex() {
                var next = parseInt( container.getAttribute( 'data-next-index' ), 10 );

                if ( isNaN( next ) ) {
                    next = container.querySelectorAll( '.wp-mcp-ai-tool-shortcuts__row' ).length;
                }

                container.setAttribute( 'data-next-index', next + 1 );

                return next;
            }

            function removeRow( button ) {
                var fieldset = button.closest( '.wp-mcp-ai-tool-shortcuts__row' );

                if ( fieldset && container.contains( fieldset ) ) {
                    container.removeChild( fieldset );
                }
            }

            addButton.addEventListener( 'click', function() {
                var index = getNextIndex();
                var fragment = document.importNode( template.content, true );
                var html = '';

                if ( fragment.firstElementChild ) {
                    html = fragment.firstElementChild.outerHTML;
                } else if ( fragment.children.length ) {
                    html = fragment.children[0].outerHTML;
                }

                if ( ! html ) {
                    return;
                }

                html = html.replace( /__INDEX__/g, index );

                var wrapper = document.createElement( 'div' );
                wrapper.innerHTML = html;
                var newRow = wrapper.firstElementChild;

                if ( newRow ) {
                    container.appendChild( newRow );
                }
            } );

            container.addEventListener( 'click', function( event ) {
                var target = event.target;

                if ( target && target.classList && target.classList.contains( 'wp-mcp-ai-remove-tool-shortcut' ) ) {
                    event.preventDefault();
                    removeRow( target );
                }
            } );
        } )();
        </script>
        <?php
    }

    /**
     * Render the defaults meta box content.
     *
     * @param WP_Post $post Post object.
     */
    public function render_defaults_meta_box( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
        }

        wp_nonce_field( 'wp_mcp_ai_defaults_meta', 'wp_mcp_ai_defaults_meta_nonce' );

        $provider      = get_post_meta( $post->ID, self::META_PROVIDER, true );
        $provider      = self::sanitize_provider_meta( $provider );
        $model         = get_post_meta( $post->ID, self::META_MODEL, true );
        $temperature   = get_post_meta( $post->ID, self::META_TEMPERATURE, true );
        $system_prompt = get_post_meta( $post->ID, self::META_SYSTEM_PROMPT, true );

        $settings         = WP_MCP_AI_Admin_Settings::get_settings();
        $default_provider = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';

        if ( '' === $provider ) {
            $provider = $default_provider;
        }

        $provider_choices = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini' ) );
        if ( ! is_array( $provider_choices ) ) {
            $provider_choices = array( 'openai', 'gemini' );
        }

        if ( '' === $temperature ) {
            $temperature = '';
        }

        ?>
        <p>
            <label for="wp-mcp-ai-provider"><strong><?php esc_html_e( 'Provider', 'wp-mcp-ai' ); ?></strong></label>
            <select id="wp-mcp-ai-provider" name="wp_mcp_ai_provider" class="widefat">
                <?php
                foreach ( $provider_choices as $choice ) {
                    $choice = sanitize_key( $choice );
                    if ( '' === $choice ) {
                        continue;
                    }

                    $label = 'openai' === $choice ? __( 'OpenAI', 'wp-mcp-ai' ) : __( 'Gemini', 'wp-mcp-ai' );
                    ?>
                    <option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $provider, $choice ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php
                }
                ?>
            </select>
        </p>
        <p>
            <label for="wp-mcp-ai-model"><strong><?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?></strong></label>
            <input type="text" id="wp-mcp-ai-model" name="wp_mcp_ai_model" value="<?php echo esc_attr( $model ); ?>" class="widefat" />
        </p>
        <p>
            <label for="wp-mcp-ai-temperature"><strong><?php esc_html_e( 'Temperature', 'wp-mcp-ai' ); ?></strong></label>
            <input type="number" step="0.1" min="0" max="2" id="wp-mcp-ai-temperature" name="wp_mcp_ai_temperature" value="<?php echo esc_attr( $temperature ); ?>" class="widefat" />
        </p>
        <p>
            <label for="wp-mcp-ai-system-prompt"><strong><?php esc_html_e( 'System Prompt', 'wp-mcp-ai' ); ?></strong></label>
            <textarea id="wp-mcp-ai-system-prompt" name="wp_mcp_ai_system_prompt" class="widefat" rows="5"><?php echo esc_textarea( $system_prompt ); ?></textarea>
        </p>
        <?php
    }

    /**
     * Render the base knowledge meta box content.
     *
     * @param WP_Post $post Post object.
     */
    public function render_base_knowledge_meta_box( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
        }

        wp_nonce_field( 'wp_mcp_ai_base_knowledge_meta', 'wp_mcp_ai_base_knowledge_meta_nonce' );

        wp_enqueue_media();
        wp_enqueue_script( 'jquery' );

        $memory_files    = get_post_meta( $post->ID, self::META_MEMORY_FILES, true );
        $vector_store_id = get_post_meta( $post->ID, self::META_VECTOR_STORE_ID, true );

        if ( ! is_array( $memory_files ) ) {
            $memory_files = array();
        }

        if ( ! is_string( $vector_store_id ) ) {
            $vector_store_id = '';
        }

        $memory_entries     = array();
        $memory_size_bytes  = 0;

        foreach ( $memory_files as $file_id ) {
            $file_id    = absint( $file_id );
            $attachment = get_post( $file_id );

            if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
                continue;
            }

            $memory_entries[] = array(
                'id'    => $file_id,
                'title' => get_the_title( $attachment ),
            );

            $file_path = get_attached_file( $file_id );
            if ( $file_path && file_exists( $file_path ) ) {
                $file_size = filesize( $file_path );
                if ( false !== $file_size ) {
                    $memory_size_bytes += (int) $file_size;
                }
            }
        }

        $memory_size_label = size_format( $memory_size_bytes );

        ?>
        <p><?php esc_html_e( 'Select Media Library items that should be preloaded as reference material for this assistant.', 'wp-mcp-ai' ); ?></p>
        <ul id="wp-mcp-ai-memory-files-list" class="wp-mcp-ai-memory-files">
            <?php foreach ( $memory_entries as $entry ) :
                $file_id = $entry['id'];
                $title   = $entry['title'];
                ?>
                <li data-id="<?php echo esc_attr( $file_id ); ?>">
                    <span class="wp-mcp-ai-memory-file-title"><?php echo esc_html( $title ? $title : sprintf( __( 'Attachment #%d', 'wp-mcp-ai' ), $file_id ) ); ?></span>
                    <button type="button" class="button-link wp-mcp-ai-remove-memory"><?php esc_html_e( 'Remove', 'wp-mcp-ai' ); ?></button>
                    <input type="hidden" name="wp_mcp_ai_memory_files[]" value="<?php echo esc_attr( $file_id ); ?>" />
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="description">
            <?php
            printf(
                /* translators: %s: Human-readable size of the memory payload. */
                esc_html__( 'Total memory size sent with each request: %s.', 'wp-mcp-ai' ),
                esc_html( $memory_size_label )
            );
            ?>
        </p>
        <p>
            <button type="button" class="button" id="wp-mcp-ai-memory-select">
                <?php esc_html_e( 'Add Knowledge Files', 'wp-mcp-ai' ); ?>
            </button>
        </p>
        <p>
            <label for="wp-mcp-ai-vector-store-id"><strong><?php esc_html_e( 'Vector Store ID', 'wp-mcp-ai' ); ?></strong></label>
            <input type="text" id="wp-mcp-ai-vector-store-id" name="wp_mcp_ai_vector_store_id" value="<?php echo esc_attr( $vector_store_id ); ?>" class="widefat" />
            <span class="description"><?php esc_html_e( 'Optional identifier for an external vector store that should be associated with this assistant.', 'wp-mcp-ai' ); ?></span>
        </p>
        <script type="text/javascript">
        jQuery( function( $ ) {
            var frame;
            var list = $( '#wp-mcp-ai-memory-files-list' );

            function addAttachment( attachment ) {
                var id = attachment.id || attachment.ID;
                if ( ! id ) {
                    return;
                }

                if ( list.find( 'li[data-id="' + id + '"]' ).length ) {
                    return;
                }

                var title = attachment.title || attachment.filename || attachment.name || '<?php echo esc_js( __( 'Attachment', 'wp-mcp-ai' ) ); ?>';
                var label = title + ' (ID: ' + id + ')';

                var item = $( '<li />', { 'data-id': id } );
                item.append( $( '<span />', { 'class': 'wp-mcp-ai-memory-file-title', 'text': label } ) );
                item.append( $( '<button />', { 'type': 'button', 'class': 'button-link wp-mcp-ai-remove-memory', 'text': '<?php echo esc_js( __( 'Remove', 'wp-mcp-ai' ) ); ?>' } ) );
                item.append( $( '<input />', { 'type': 'hidden', 'name': 'wp_mcp_ai_memory_files[]', 'value': id } ) );

                list.append( item );
            }

            $( '#wp-mcp-ai-memory-select' ).on( 'click', function( event ) {
                event.preventDefault();

                if ( frame ) {
                    frame.open();
                    return;
                }

                frame = wp.media({
                    title: '<?php echo esc_js( __( 'Select knowledge files', 'wp-mcp-ai' ) ); ?>',
                    button: {
                        text: '<?php echo esc_js( __( 'Use files', 'wp-mcp-ai' ) ); ?>'
                    },
                    multiple: true
                });

                frame.on( 'select', function() {
                    var selection = frame.state().get( 'selection' );
                    if ( ! selection ) {
                        return;
                    }

                    selection.each( function( attachment ) {
                        addAttachment( attachment.toJSON() );
                    } );
                });

                frame.open();
            } );

            list.on( 'click', '.wp-mcp-ai-remove-memory', function( event ) {
                event.preventDefault();
                $( this ).closest( 'li' ).remove();
            } );
        } );
        </script>
        <?php
    }

    /**
     * Handle credential issuance requests from the admin UI.
     */
    public function handle_issue_credential() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage assistant credentials.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
        }

        $post_id = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! $post_id ) {
            wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 400 ) );
        }

        check_admin_referer( 'wp_mcp_ai_issue_credential_' . $post_id, 'wp_mcp_ai_issue_nonce' );

        $post = get_post( $post_id );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 404 ) );
        }

        $user_id = get_current_user_id();
        $issued  = WP_MCP_AI_Credentials::issue_credential( $post_id, $user_id );

        if ( is_wp_error( $issued ) ) {
            $error_code = sanitize_key( $issued->get_error_code() );
            $this->redirect_with_notice( $post_id, 'credential_error', array( 'error' => $error_code ) );
        }

        set_transient(
            $this->get_token_transient_key( $user_id ),
            array(
                'assistant_id' => $post_id,
                'token'        => $issued['token'],
            ),
            10 * MINUTE_IN_SECONDS
        );

        $this->redirect_with_notice( $post_id, 'credential_created' );
    }

    /**
     * Handle credential revocation requests from the admin UI.
     */
    public function handle_revoke_credential() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage assistant credentials.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
        }

        $post_id       = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $credential_id = isset( $_REQUEST['credential_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['credential_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( ! $post_id || '' === $credential_id ) {
            wp_die( esc_html__( 'Invalid credential request.', 'wp-mcp-ai' ), '', array( 'response' => 400 ) );
        }

        $nonce_field = $this->get_credential_nonce_field_name( 'wp_mcp_ai_revoke_nonce', $credential_id );

        check_admin_referer( 'wp_mcp_ai_revoke_credential_' . $post_id . '_' . $credential_id, $nonce_field );

        $post = get_post( $post_id );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 404 ) );
        }

        $result = WP_MCP_AI_Credentials::revoke_credential( $post_id, $credential_id, get_current_user_id() );

        if ( is_wp_error( $result ) ) {
            $error_code = sanitize_key( $result->get_error_code() );
            $this->redirect_with_notice( $post_id, 'credential_error', array( 'error' => $error_code ) );
        }

        $this->redirect_with_notice( $post_id, 'credential_revoked' );
    }

    /**
     * Handle credential deletion requests from the admin UI.
     */
    public function handle_delete_credential() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage assistant credentials.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
        }

        $post_id       = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $credential_id = isset( $_REQUEST['credential_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['credential_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( ! $post_id || '' === $credential_id ) {
            wp_die( esc_html__( 'Invalid credential request.', 'wp-mcp-ai' ), '', array( 'response' => 400 ) );
        }

        $nonce_field = $this->get_credential_nonce_field_name( 'wp_mcp_ai_delete_nonce', $credential_id );

        check_admin_referer( 'wp_mcp_ai_delete_credential_' . $post_id . '_' . $credential_id, $nonce_field );

        $post = get_post( $post_id );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 404 ) );
        }

        $result = WP_MCP_AI_Credentials::delete_credential( $post_id, $credential_id, get_current_user_id() );

        if ( is_wp_error( $result ) ) {
            $error_code = sanitize_key( $result->get_error_code() );
            $this->redirect_with_notice( $post_id, 'credential_error', array( 'error' => $error_code ) );
        }

        $this->redirect_with_notice( $post_id, 'credential_deleted' );
    }

    /**
     * Display notices related to credential management.
     */
    public function render_admin_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();

        if ( ! $screen || 'post' !== $screen->base || self::POST_TYPE !== $screen->post_type ) {
            return;
        }

        $post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $post_id ) {
            return;
        }

        $user_id      = get_current_user_id();
        $transient_key = $this->get_token_transient_key( $user_id );
        $token_notice  = get_transient( $transient_key );

        if ( is_array( $token_notice ) && isset( $token_notice['assistant_id'], $token_notice['token'] ) && absint( $token_notice['assistant_id'] ) === $post_id ) {
            delete_transient( $transient_key );

            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                sprintf(
                    /* translators: %s: credential token */
                    esc_html__( 'New credential issued. Copy this token now: %s', 'wp-mcp-ai' ),
                    '<code>' . esc_html( $token_notice['token'] ) . '</code>'
                )
            );
        }

        $notice = isset( $_GET['wp_mcp_ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['wp_mcp_ai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( '' === $notice ) {
            return;
        }

        $error_code = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $message    = $this->get_notice_message( $notice, $error_code );

        if ( '' === $message ) {
            return;
        }

        $class = in_array( $notice, array( 'credential_created', 'credential_revoked', 'credential_deleted' ), true ) ? 'notice-success' : 'notice-error';

        printf( '<div class="notice %1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }

    /**
     * Build the transient key used for temporary token storage.
     *
     * @param int $user_id User ID.
     * @return string
     */
    protected function get_token_transient_key( $user_id ) {
        return 'wp_mcp_ai_new_token_' . absint( $user_id );
    }

    /**
     * Redirect back to the assistant edit screen with a notice.
     *
     * @param int    $post_id Assistant post ID.
     * @param string $notice  Notice identifier.
     * @param array  $extra   Additional query arguments.
     */
    protected function redirect_with_notice( $post_id, $notice, $extra = array() ) {
        $args = array_merge(
            array(
                'post'             => absint( $post_id ),
                'action'           => 'edit',
                'wp_mcp_ai_notice' => sanitize_key( $notice ),
            ),
            array_change_key_case( $extra, CASE_LOWER )
        );

        wp_safe_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
        exit;
    }

    /**
     * Map notice identifiers to user-facing messages.
     *
     * @param string $notice     Notice identifier.
     * @param string $error_code Optional error code.
     * @return string
     */
    protected function get_notice_message( $notice, $error_code = '' ) {
        switch ( $notice ) {
            case 'credential_created':
                return __( 'Credential issued successfully.', 'wp-mcp-ai' );
            case 'credential_revoked':
                return __( 'Credential revoked successfully.', 'wp-mcp-ai' );
            case 'credential_deleted':
                return __( 'Credential deleted successfully.', 'wp-mcp-ai' );
            case 'credential_error':
                switch ( $error_code ) {
                    case 'wp_mcp_ai_unknown_credential':
                        return __( 'The requested credential could not be found.', 'wp-mcp-ai' );
                    case 'wp_mcp_ai_credential_already_revoked':
                        return __( 'The credential has already been revoked.', 'wp-mcp-ai' );
                    case 'wp_mcp_ai_invalid_assistant':
                        return __( 'Unable to manage credentials for this assistant.', 'wp-mcp-ai' );
                    default:
                        return __( 'An error occurred while managing the credential.', 'wp-mcp-ai' );
                }
        }

        return '';
    }

    /**
     * Remove credential index entries when an assistant is deleted.
     *
     * @param int $post_id Post ID being deleted.
     */
    public function cleanup_deleted_assistant_credentials( $post_id ) {
        if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
            return;
        }

        WP_MCP_AI_Credentials::purge_assistant_credentials( $post_id );
    }

    /**
     * Persist assistant meta fields.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function save_post( $post_id, $post ) {
        if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
            return;
        }

        $tools_nonce_verified          = false;
        $defaults_nonce_verified       = false;
        $base_knowledge_nonce_verified = false;
        $shortcuts_nonce_verified      = false;

        if ( isset( $_POST['wp_mcp_ai_tools_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $tools_nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_tools_meta_nonce'] ) ), 'wp_mcp_ai_tools_meta' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        if ( isset( $_POST['wp_mcp_ai_defaults_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $defaults_nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_defaults_meta_nonce'] ) ), 'wp_mcp_ai_defaults_meta' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        if ( isset( $_POST['wp_mcp_ai_base_knowledge_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $base_knowledge_nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_base_knowledge_meta_nonce'] ) ), 'wp_mcp_ai_base_knowledge_meta' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        if ( isset( $_POST['wp_mcp_ai_tool_shortcuts_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $shortcuts_nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_tool_shortcuts_meta_nonce'] ) ), 'wp_mcp_ai_tool_shortcuts_meta' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        if ( ! $tools_nonce_verified && ! $defaults_nonce_verified && ! $base_knowledge_nonce_verified && ! $shortcuts_nonce_verified ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( $tools_nonce_verified ) {
            $tool_slugs = array();
            if ( isset( $_POST['wp_mcp_ai_tools'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $tool_slugs = self::sanitize_tools_meta( wp_unslash( $_POST['wp_mcp_ai_tools'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            update_post_meta( $post_id, self::META_TOOLS, $tool_slugs );

            $external_action_id = isset( $_POST['wp_mcp_ai_external_action_identifier'] )
                ? self::sanitize_external_action_id_meta( wp_unslash( $_POST['wp_mcp_ai_external_action_identifier'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
                : '';

            if ( '' === $external_action_id ) {
                delete_post_meta( $post_id, self::META_EXTERNAL_ACTION_ID );
            } else {
                update_post_meta( $post_id, self::META_EXTERNAL_ACTION_ID, $external_action_id );
            }

            $external_action_type = isset( $_POST['wp_mcp_ai_external_action_type'] )
                ? self::sanitize_external_action_type_meta( wp_unslash( $_POST['wp_mcp_ai_external_action_type'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
                : '';

            if ( '' === $external_action_type ) {
                delete_post_meta( $post_id, self::META_EXTERNAL_ACTION_TYPE );
            } else {
                update_post_meta( $post_id, self::META_EXTERNAL_ACTION_TYPE, $external_action_type );
            }

            $tool_role_rules = array();

            if ( isset( $_POST['wp_mcp_ai_tool_role_rules'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $tool_role_rules = self::sanitize_tool_role_rules_meta( wp_unslash( $_POST['wp_mcp_ai_tool_role_rules'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            if ( empty( $tool_role_rules ) ) {
                delete_post_meta( $post_id, self::META_TOOL_ROLE_RULES );
            } else {
                update_post_meta( $post_id, self::META_TOOL_ROLE_RULES, $tool_role_rules );
            }

            $disable_tool_shortcuts = isset( $_POST['wp_mcp_ai_disable_prebuilt_shortcuts'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
                ? self::sanitize_disable_tool_shortcuts_meta( wp_unslash( $_POST['wp_mcp_ai_disable_prebuilt_shortcuts'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
                : false;

            if ( $disable_tool_shortcuts ) {
                update_post_meta( $post_id, self::META_DISABLE_TOOL_SHORTCUTS, true );
            } else {
                delete_post_meta( $post_id, self::META_DISABLE_TOOL_SHORTCUTS );
            }

            $prebuilt_tool_shortcuts = array();

            if ( isset( $_POST['wp_mcp_ai_prebuilt_shortcuts'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $prebuilt_tool_shortcuts = self::sanitize_prebuilt_tool_shortcuts_meta( wp_unslash( $_POST['wp_mcp_ai_prebuilt_shortcuts'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            if ( empty( $prebuilt_tool_shortcuts ) ) {
                delete_post_meta( $post_id, self::META_TOOL_PREBUILT_SHORTCUTS );
            } else {
                update_post_meta( $post_id, self::META_TOOL_PREBUILT_SHORTCUTS, $prebuilt_tool_shortcuts );
            }
        }

        if ( $shortcuts_nonce_verified ) {
            $tool_shortcuts = array();

            if ( isset( $_POST['wp_mcp_ai_tool_shortcuts'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $tool_shortcuts = self::sanitize_tool_shortcuts_meta( wp_unslash( $_POST['wp_mcp_ai_tool_shortcuts'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            if ( empty( $tool_shortcuts ) ) {
                delete_post_meta( $post_id, self::META_TOOL_SHORTCUTS );
            } else {
                update_post_meta( $post_id, self::META_TOOL_SHORTCUTS, $tool_shortcuts );
            }
        }

        if ( $defaults_nonce_verified ) {
            $provider = isset( $_POST['wp_mcp_ai_provider'] )
                ? self::sanitize_provider_meta( wp_unslash( $_POST['wp_mcp_ai_provider'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
                : '';

            if ( '' === $provider ) {
                delete_post_meta( $post_id, self::META_PROVIDER );
            } else {
                update_post_meta( $post_id, self::META_PROVIDER, $provider );
            }

            $model = isset( $_POST['wp_mcp_ai_model'] ) ? self::sanitize_model_meta( wp_unslash( $_POST['wp_mcp_ai_model'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta( $post_id, self::META_MODEL, $model );

            $temperature = null;
            if ( isset( $_POST['wp_mcp_ai_temperature'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $temperature = self::sanitize_temperature_meta( wp_unslash( $_POST['wp_mcp_ai_temperature'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            if ( null === $temperature ) {
                delete_post_meta( $post_id, self::META_TEMPERATURE );
            } else {
                update_post_meta( $post_id, self::META_TEMPERATURE, $temperature );
            }

            $system_prompt = isset( $_POST['wp_mcp_ai_system_prompt'] ) ? self::sanitize_system_prompt_meta( wp_unslash( $_POST['wp_mcp_ai_system_prompt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta( $post_id, self::META_SYSTEM_PROMPT, $system_prompt );
        }

        if ( $base_knowledge_nonce_verified ) {
            $memory_files = array();
            if ( isset( $_POST['wp_mcp_ai_memory_files'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $memory_files = self::sanitize_memory_files_meta( wp_unslash( $_POST['wp_mcp_ai_memory_files'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            update_post_meta( $post_id, self::META_MEMORY_FILES, $memory_files );

            $vector_store_id = isset( $_POST['wp_mcp_ai_vector_store_id'] ) ? self::sanitize_vector_store_meta( wp_unslash( $_POST['wp_mcp_ai_vector_store_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta( $post_id, self::META_VECTOR_STORE_ID, $vector_store_id );
        }
    }

    /**
     * Retrieve the configuration for a specific assistant.
     *
     * @param int $assistant_id Assistant post ID.
     * @return array
     */
    public static function get_assistant_configuration( $assistant_id ) {
        $assistant_id = absint( $assistant_id );
        if ( ! $assistant_id ) {
            return array();
        }

        $config = array(
            'tools'                       => get_post_meta( $assistant_id, self::META_TOOLS, true ),
            'provider'                    => get_post_meta( $assistant_id, self::META_PROVIDER, true ),
            'model'                       => get_post_meta( $assistant_id, self::META_MODEL, true ),
            'temperature'                 => get_post_meta( $assistant_id, self::META_TEMPERATURE, true ),
            'system_prompt'               => get_post_meta( $assistant_id, self::META_SYSTEM_PROMPT, true ),
            'memory_files'                => get_post_meta( $assistant_id, self::META_MEMORY_FILES, true ),
            'vector_store_id'             => get_post_meta( $assistant_id, self::META_VECTOR_STORE_ID, true ),
            'tool_shortcuts'              => get_post_meta( $assistant_id, self::META_TOOL_SHORTCUTS, true ),
            'tool_prebuilt_shortcuts'     => get_post_meta( $assistant_id, self::META_TOOL_PREBUILT_SHORTCUTS, true ),
            'tool_role_rules'             => get_post_meta( $assistant_id, self::META_TOOL_ROLE_RULES, true ),
            'disable_prebuilt_shortcuts'  => get_post_meta( $assistant_id, self::META_DISABLE_TOOL_SHORTCUTS, true ),
            'external_action_identifier'  => get_post_meta( $assistant_id, self::META_EXTERNAL_ACTION_ID, true ),
            'external_action_type'        => get_post_meta( $assistant_id, self::META_EXTERNAL_ACTION_TYPE, true ),
        );

        if ( ! is_array( $config['tools'] ) ) {
            $config['tools'] = array();
        }

        if ( ! is_string( $config['provider'] ) ) {
            $config['provider'] = '';
        } else {
            $config['provider'] = self::sanitize_provider_meta( $config['provider'] );
        }

        if ( '' === $config['model'] ) {
            $config['model'] = '';
        }

        if ( '' === $config['temperature'] ) {
            $config['temperature'] = null;
        } else {
            $config['temperature'] = floatval( $config['temperature'] );
        }

        if ( '' === $config['system_prompt'] ) {
            $config['system_prompt'] = '';
        }

        if ( ! is_array( $config['memory_files'] ) ) {
            $config['memory_files'] = array();
        }

        $config['memory_files'] = array_values( array_filter( array_map( 'absint', $config['memory_files'] ) ) );

        if ( ! is_string( $config['vector_store_id'] ) ) {
            $config['vector_store_id'] = '';
        } else {
            $config['vector_store_id'] = sanitize_text_field( $config['vector_store_id'] );
        }

        if ( ! is_array( $config['tool_shortcuts'] ) ) {
            $config['tool_shortcuts'] = array();
        } else {
            $config['tool_shortcuts'] = self::sanitize_tool_shortcuts_meta( $config['tool_shortcuts'] );
        }

        if ( ! is_array( $config['tool_prebuilt_shortcuts'] ) ) {
            $config['tool_prebuilt_shortcuts'] = array();
        } else {
            $config['tool_prebuilt_shortcuts'] = self::sanitize_prebuilt_tool_shortcuts_meta( $config['tool_prebuilt_shortcuts'] );
        }

        if ( ! is_array( $config['tool_role_rules'] ) ) {
            $config['tool_role_rules'] = array();
        } else {
            $config['tool_role_rules'] = self::sanitize_tool_role_rules_meta( $config['tool_role_rules'] );
        }

        $config['disable_prebuilt_shortcuts'] = self::sanitize_disable_tool_shortcuts_meta( $config['disable_prebuilt_shortcuts'] );

        if ( ! is_string( $config['external_action_identifier'] ) ) {
            $config['external_action_identifier'] = '';
        } else {
            $config['external_action_identifier'] = self::sanitize_external_action_id_meta( $config['external_action_identifier'] );
        }

        if ( ! is_string( $config['external_action_type'] ) ) {
            $config['external_action_type'] = '';
        } else {
            $config['external_action_type'] = self::sanitize_external_action_type_meta( $config['external_action_type'] );
        }

        return $config;
    }

    /**
     * Sanitize tool shortcut metadata value.
     *
     * @param mixed $shortcuts Raw shortcuts value.
     * @return array
     */
    public static function sanitize_tool_shortcuts_meta( $shortcuts ) {
        if ( ! is_array( $shortcuts ) ) {
            return array();
        }

        $registry = null;

        if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
            $registry = WP_MCP_AI_Tool_Registry::get_instance();

            if ( method_exists( $registry, 'init' ) ) {
                $registry->init();
            }
        }

        $sanitized = array();

        foreach ( $shortcuts as $shortcut ) {
            if ( ! is_array( $shortcut ) ) {
                continue;
            }

            $label = isset( $shortcut['label'] ) && is_string( $shortcut['label'] )
                ? sanitize_text_field( $shortcut['label'] )
                : '';

            $payload = isset( $shortcut['payload'] ) && is_string( $shortcut['payload'] )
                ? sanitize_textarea_field( $shortcut['payload'] )
                : '';

            if ( '' === $label && '' === $payload ) {
                continue;
            }

            $entry = array(
                'label'   => $label,
                'payload' => $payload,
            );

            if ( isset( $shortcut['description'] ) && is_string( $shortcut['description'] ) ) {
                $description = sanitize_textarea_field( $shortcut['description'] );
                if ( '' !== $description ) {
                    $entry['description'] = $description;
                }
            }

            if ( isset( $shortcut['tool'] ) && is_string( $shortcut['tool'] ) ) {
                $tool = sanitize_key( $shortcut['tool'] );

                if ( '' !== $tool ) {
                    $is_known_tool = true;

                    if ( null !== $registry && method_exists( $registry, 'get_tool' ) ) {
                        $is_known_tool = ( null !== $registry->get_tool( $tool ) );
                    }

                    if ( $is_known_tool ) {
                        $entry['tool'] = $tool;
                    }
                }
            }

            $sanitized[] = $entry;
        }

        return array_values( $sanitized );
    }

    /**
     * Sanitize customized pre-built tool shortcut metadata.
     *
     * @param mixed $value Raw pre-built shortcut configuration.
     * @return array
     */
    public static function sanitize_prebuilt_tool_shortcuts_meta( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $value as $key => $settings ) {
            $tool = '';

            if ( is_string( $key ) ) {
                $tool = sanitize_key( $key );
            }

            if ( '' === $tool && is_array( $settings ) && isset( $settings['tool'] ) && is_string( $settings['tool'] ) ) {
                $tool = sanitize_key( $settings['tool'] );
            }

            if ( '' === $tool || ! is_array( $settings ) ) {
                continue;
            }

            if ( isset( $settings['mode'] ) && is_string( $settings['mode'] ) ) {
                $mode = strtolower( sanitize_text_field( $settings['mode'] ) );

                if ( 'inherit' === $mode || 'default' === $mode ) {
                    continue;
                }
            }

            $entry = array(
                'mode'      => 'custom',
                'shortcuts' => array(),
            );

            if ( isset( $settings['shortcuts'] ) && is_array( $settings['shortcuts'] ) ) {
                foreach ( $settings['shortcuts'] as $shortcut ) {
                    if ( ! is_array( $shortcut ) ) {
                        continue;
                    }

                    $label = isset( $shortcut['label'] ) && is_string( $shortcut['label'] )
                        ? sanitize_text_field( $shortcut['label'] )
                        : '';
                    $payload = isset( $shortcut['payload'] ) && is_string( $shortcut['payload'] )
                        ? sanitize_textarea_field( $shortcut['payload'] )
                        : '';

                    if ( '' === $label && '' === $payload ) {
                        continue;
                    }

                    $item = array(
                        'label'   => $label,
                        'payload' => $payload,
                    );

                    if ( isset( $shortcut['description'] ) && is_string( $shortcut['description'] ) ) {
                        $description = sanitize_textarea_field( $shortcut['description'] );

                        if ( '' !== $description ) {
                            $item['description'] = $description;
                        }
                    }

                    $entry['shortcuts'][] = $item;
                }
            }

            if ( empty( $entry['shortcuts'] ) ) {
                continue;
            }

            $entry['shortcuts'] = array_values( $entry['shortcuts'] );

            $sanitized[ $tool ] = $entry;
        }

        return $sanitized;
    }

    /**
     * Sanitize tool role rule metadata values.
     *
     * @param mixed $rules Raw rules value.
     * @return array
     */
    public static function sanitize_tool_role_rules_meta( $rules ) {
        if ( is_string( $rules ) ) {
            $decoded_rules = json_decode( $rules, true );
            if ( is_array( $decoded_rules ) ) {
                $rules = $decoded_rules;
            }
        }

        if ( ! is_array( $rules ) ) {
            return array();
        }

        $registry = null;

        if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
            $registry = WP_MCP_AI_Tool_Registry::get_instance();

            if ( method_exists( $registry, 'init' ) ) {
                $registry->init();
            }
        }

        $allowed_roles = self::get_registered_role_slugs();
        $allowed_flags = self::get_allowed_tool_role_flags();

        $sanitized = array();

        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }

            $tool_slug = isset( $rule['tool'] ) ? sanitize_key( $rule['tool'] ) : '';

            if ( '' === $tool_slug ) {
                continue;
            }

            $is_known_tool = true;

            if ( null !== $registry && method_exists( $registry, 'get_tool' ) ) {
                $is_known_tool = ( null !== $registry->get_tool( $tool_slug ) );
            }

            if ( ! $is_known_tool ) {
                continue;
            }

            $entry = array(
                'tool' => $tool_slug,
            );

            if ( isset( $rule['roles'] ) && is_array( $rule['roles'] ) ) {
                $valid_roles = array();

                foreach ( $rule['roles'] as $role ) {
                    $role_slug = sanitize_key( $role );

                    if ( '' === $role_slug ) {
                        continue;
                    }

                    if ( empty( $allowed_roles ) || in_array( $role_slug, $allowed_roles, true ) ) {
                        $valid_roles[] = $role_slug;
                    }
                }

                if ( ! empty( $valid_roles ) ) {
                    $entry['roles'] = array_values( array_unique( $valid_roles ) );
                }
            }

            if ( isset( $rule['groups'] ) ) {
                $raw_groups = $rule['groups'];

                if ( is_string( $raw_groups ) || is_numeric( $raw_groups ) ) {
                    $raw_groups = array( $raw_groups );
                }

                if ( is_array( $raw_groups ) ) {
                    $valid_groups = array();

                    foreach ( $raw_groups as $group_id ) {
                        $group_id = absint( $group_id );

                        if ( $group_id > 0 ) {
                            $valid_groups[] = $group_id;
                        }
                    }

                    if ( ! empty( $valid_groups ) ) {
                        $entry['groups'] = array_values( array_unique( $valid_groups ) );
                    }
                }
            }

            $flags = array();

            if ( isset( $rule['flags'] ) ) {
                $raw_flags = $rule['flags'];

                if ( is_string( $raw_flags ) ) {
                    $raw_flags = array( $raw_flags );
                }

                if ( is_array( $raw_flags ) ) {
                    foreach ( $raw_flags as $flag ) {
                        $flag_slug = sanitize_key( $flag );

                        if ( '' !== $flag_slug && in_array( $flag_slug, $allowed_flags, true ) ) {
                            $flags[] = $flag_slug;
                        }
                    }
                }
            }

            foreach ( $allowed_flags as $flag_slug ) {
                if ( isset( $rule[ $flag_slug ] ) && wp_validate_boolean( $rule[ $flag_slug ] ) ) {
                    $flags[] = $flag_slug;
                }
            }

            if ( ! empty( $flags ) ) {
                $entry['flags'] = array_values( array_unique( $flags ) );
            }

            if ( empty( $entry['roles'] ) && empty( $entry['groups'] ) && empty( $entry['flags'] ) ) {
                continue;
            }

            $sanitized[] = $entry;
        }

        return array_values( $sanitized );
    }

    /**
     * Sanitize disable tool shortcut metadata value.
     *
     * @param mixed $value Raw value.
     * @return bool
     */
    public static function sanitize_disable_tool_shortcuts_meta( $value ) {
        if ( function_exists( 'rest_sanitize_boolean' ) ) {
            return rest_sanitize_boolean( $value );
        }

        if ( is_string( $value ) ) {
            $value = strtolower( $value );

            if ( in_array( $value, array( 'false', '0', '' ), true ) ) {
                return false;
            }
        }

        return (bool) $value;
    }

    /**
     * Retrieve the allowlist of tool role rule flags.
     *
     * @return array
     */
    protected static function get_allowed_tool_role_flags() {
        $default_flags = array(
            'allow_authenticated',
            'allow_guests',
            'allow_all_roles',
        );

        $flags = apply_filters( 'wp_mcp_ai_tool_role_rule_flags', $default_flags );

        if ( ! is_array( $flags ) ) {
            return array();
        }

        $flags = array_map( 'sanitize_key', $flags );
        $flags = array_filter( $flags, static function( $flag ) {
            return '' !== $flag;
        } );

        return array_values( array_unique( $flags ) );
    }

    /**
     * Retrieve the registered WordPress role slugs.
     *
     * @return array
     */
    protected static function get_registered_role_slugs() {
        static $role_slugs = null;

        if ( null !== $role_slugs ) {
            return $role_slugs;
        }

        $role_slugs = array();

        if ( function_exists( 'wp_roles' ) ) {
            $wp_roles = wp_roles();

            if ( $wp_roles && is_a( $wp_roles, 'WP_Roles' ) && isset( $wp_roles->roles ) && is_array( $wp_roles->roles ) ) {
                $role_slugs = array_keys( $wp_roles->roles );
            }
        }

        if ( ! empty( $role_slugs ) ) {
            $role_slugs = array_map( 'sanitize_key', $role_slugs );
            $role_slugs = array_filter( $role_slugs, static function( $role ) {
                return '' !== $role;
            } );
            $role_slugs = array_values( array_unique( $role_slugs ) );
        }

        return $role_slugs;
    }
}
