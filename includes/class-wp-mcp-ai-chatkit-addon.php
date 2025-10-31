<?php
/**
 * ChatKit add-on registration for WP MCP AI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the WP MCP AI add-on with ChatKit when available.
 */
class WP_MCP_AI_ChatKit_Addon {

    /**
     * Unique identifier used when registering the add-on with ChatKit.
     */
    const ADDON_ID = 'wp-mcp-ai';

    /**
     * Track whether the ChatKit integration has already been initialised.
     *
     * @var bool
     */
    protected static $bootstrapped = false;

    /**
     * Hook into WordPress to register the add-on when ChatKit is available.
     */
    public static function init() {
        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_bootstrap' ), 30 );
    }

    /**
     * Initialise the integration if ChatKit is active.
     */
    public static function maybe_bootstrap() {
        if ( self::$bootstrapped ) {
            return;
        }

        if ( ! self::is_chatkit_available() ) {
            return;
        }

        add_filter( 'chatkit_register_addons', array( __CLASS__, 'register_via_filter' ) );
        add_filter( 'chatkit_addons', array( __CLASS__, 'register_via_filter' ) );
        add_action( 'chatkit/register_addons', array( __CLASS__, 'register_via_action' ), 10, 1 );
        add_action( 'chatkit/register_addon', array( __CLASS__, 'register_single_addon' ), 10, 1 );

        self::$bootstrapped = true;
    }

    /**
     * Determine whether ChatKit is available in the current environment.
     *
     * @return bool
     */
    protected static function is_chatkit_available() {
        /**
         * Allow plugins/tests to force ChatKit availability.
         *
         * @since 1.0.0
         *
         * @param bool $available Whether ChatKit should be treated as active.
         */
        if ( apply_filters( 'wp_mcp_ai_chatkit_is_available', false ) ) {
            return true;
        }

        if ( defined( 'CHATKIT_VERSION' ) ) {
            return true;
        }

        if ( class_exists( '\\ChatKit\\Plugin', false ) ) {
            return true;
        }

        if ( function_exists( 'chatkit' ) ) {
            return true;
        }

        if ( did_action( 'chatkit/init' ) ) {
            return true;
        }

        return false;
    }

    /**
     * Register the add-on through a filter-based API.
     *
     * @param array $addons Previously registered add-ons.
     * @return array
     */
    public static function register_via_filter( $addons ) {
        if ( ! is_array( $addons ) ) {
            $addons = array();
        }

        $addons[ self::ADDON_ID ] = self::get_definition();

        return $addons;
    }

    /**
     * Register the add-on via an action-style API.
     *
     * @param mixed $manager Optional add-on manager instance provided by ChatKit.
     */
    public static function register_via_action( $manager = null ) {
        $definition = self::get_definition();

        if ( is_object( $manager ) && method_exists( $manager, 'register_addon' ) ) {
            $manager->register_addon( $definition );
            return;
        }

        /**
         * Fires when the WP MCP AI ChatKit add-on registers via an action.
         *
         * This mirrors the behaviour of ChatKit's manager objects so that tests or
         * other integrations can react consistently when a manager is not present.
         *
         * @since 1.0.0
         *
         * @param array $definition Registered add-on definition.
         * @param mixed $manager    Manager instance passed to the action, if any.
         */
        do_action( 'wp_mcp_ai_chatkit_addon_registered', $definition, $manager );
    }

    /**
     * Register a single add-on using callback style semantics.
     *
     * @param callable|null $callback Optional callback provided by ChatKit.
     */
    public static function register_single_addon( $callback = null ) {
        $definition = self::get_definition();

        if ( is_callable( $callback ) ) {
            call_user_func( $callback, $definition );
            return;
        }

        do_action( 'wp_mcp_ai_chatkit_addon_registered', $definition, null );
    }

    /**
     * Retrieve the add-on definition passed to ChatKit.
     *
     * @return array
     */
    public static function get_definition() {
        $rest_namespace = WP_MCP_AI_REST::REST_NAMESPACE;
        $capability     = wp_mcp_ai_get_required_chat_capability( 0, 'chatkit' );

        $definition = array(
            'id'             => self::ADDON_ID,
            'name'           => __( 'WP MCP AI', 'wp-mcp-ai' ),
            'description'    => __( 'Connect ChatKit workflows to WP MCP AI assistants.', 'wp-mcp-ai' ),
            'version'        => WP_MCP_AI_VERSION,
            'icon'           => WP_MCP_AI_URL . 'assets/images/ai-icon.svg',
            'rest_namespace' => $rest_namespace,
            'rest_routes'    => array(
                'chat'     => array(
                    'method' => 'POST',
                    'path'   => '/chat',
                ),
                'tools'    => array(
                    'method' => 'POST',
                    'path'   => '/tools',
                ),
                'download' => array(
                    'method' => 'GET',
                    'path'   => '/files/(?P<file_id>[^/]+)/download',
                ),
            ),
            'supports'       => array(
                'attachments'      => true,
                'guest_access'     => true,
                'tool_invocations' => true,
            ),
            'fields'         => array(
                'assistant_id' => array(
                    'type'        => 'integer',
                    'required'    => true,
                    'label'       => __( 'Assistant ID', 'wp-mcp-ai' ),
                    'description' => __( 'ID of the AI Assistant to engage for ChatKit requests.', 'wp-mcp-ai' ),
                ),
                'system_prompt' => array(
                    'type'        => 'string',
                    'required'    => false,
                    'label'       => __( 'System Prompt Override', 'wp-mcp-ai' ),
                    'description' => __( 'Optional system prompt override applied to ChatKit initiated chats.', 'wp-mcp-ai' ),
                ),
                'tool_shortcuts' => array(
                    'type'        => 'array',
                    'required'    => false,
                    'label'       => __( 'Shortcut Presets', 'wp-mcp-ai' ),
                    'description' => __( 'Tool shortcut presets exposed to ChatKit operators.', 'wp-mcp-ai' ),
                    'items'       => array(
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
                    'default'     => array(),
                ),
            ),
        );

        if ( $capability ) {
            $definition['capability'] = $capability;
        }

        /**
         * Filter the ChatKit add-on definition before it is exported.
         *
         * @since 1.0.0
         *
         * @param array $definition ChatKit add-on definition.
         */
        return apply_filters( 'wp_mcp_ai_chatkit_addon_definition', $definition );
    }

    /**
     * Reset the bootstrap state.
     *
     * Exposed primarily for automated tests to ensure a predictable bootstrap
     * cycle when multiple scenarios are executed in the same process.
     */
    public static function reset_state_for_testing() {
        self::$bootstrapped = false;

        remove_filter( 'chatkit_register_addons', array( __CLASS__, 'register_via_filter' ) );
        remove_filter( 'chatkit_addons', array( __CLASS__, 'register_via_filter' ) );
        remove_action( 'chatkit/register_addons', array( __CLASS__, 'register_via_action' ) );
        remove_action( 'chatkit/register_addon', array( __CLASS__, 'register_single_addon' ) );
    }
}

