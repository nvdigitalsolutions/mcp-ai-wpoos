<?php
/**
 * Tool registry singleton.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

/**
 * Maintains a list of available tool providers.
 */
class WP_MCP_AI_Tool_Registry {
    /**
     * Singleton instance.
     *
     * @var WP_MCP_AI_Tool_Registry
     */
    protected static $instance = null;

    /**
     * Registered tools keyed by slug.
     *
     * @var WP_MCP_AI_Tool_Interface[]
     */
    protected $tools = array();

    /**
     * Whether the registry has been initialised.
     *
     * @var bool
     */
    protected $bootstrapped = false;

    /**
     * Retrieve the singleton instance.
     *
     * @return WP_MCP_AI_Tool_Registry
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Prevent direct construction.
     */
    protected function __construct() {}

    /**
     * Prevent cloning.
     */
    protected function __clone() {}

    /**
     * Prevent unserialisation.
     */
    public function __wakeup() {} // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore

    /**
     * Initialise the registry by loading default tools and triggering hooks.
     */
    public function init() {
        if ( $this->bootstrapped ) {
            return;
        }

        $this->bootstrapped = true;

        $this->load_default_tools();

        /**
         * Allow third parties to register additional tools.
         *
         * @param WP_MCP_AI_Tool_Registry $registry Registry instance.
         */
        do_action( 'wp_mcp_ai_register_tools', $this );
    }

    /**
     * Register a tool implementation.
     *
     * @param string|WP_MCP_AI_Tool_Interface $tool Tool class name or instance.
     * @return bool Whether the tool was registered.
     */
    public function register_tool( $tool ) {
        if ( is_string( $tool ) ) {
            if ( ! class_exists( $tool ) ) {
                return false;
            }

            $tool = new $tool();
        }

        if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
            return false;
        }

        $slug = sanitize_key( $tool->get_slug() );

        if ( empty( $slug ) ) {
            return false;
        }

        $this->tools[ $slug ] = $tool;

        return true;
    }

    /**
     * Unregister a tool by slug.
     *
     * @param string $slug Tool slug.
     */
    public function unregister_tool( $slug ) {
        $slug = sanitize_key( $slug );
        unset( $this->tools[ $slug ] );
    }

    /**
     * Retrieve a tool instance.
     *
     * @param string $slug Tool slug.
     * @return WP_MCP_AI_Tool_Interface|null
     */
    public function get_tool( $slug ) {
        $slug = sanitize_key( $slug );

        return isset( $this->tools[ $slug ] ) ? $this->tools[ $slug ] : null;
    }

    /**
     * Retrieve all registered tools.
     *
     * @return WP_MCP_AI_Tool_Interface[]
     */
    public function get_tools() {
        return array_values( $this->tools );
    }

    /**
     * Load the plugin's default tool providers.
     */
    protected function load_default_tools() {
        $default_tools = array(
            'WP_MCP_AI_Tool_Get_Recent_Posts'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-recent-posts.php',
            'WP_MCP_AI_Tool_Get_User_Info'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-user-info.php',
            'WP_MCP_AI_Tool_Get_Site_Summary'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-site-summary.php',
            'WP_MCP_AI_Tool_Get_Woo_Orders'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-woo-recent-orders.php',
            'WP_MCP_AI_Tool_Get_JetEngine_Items' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetengine-items.php',
        );

        foreach ( $default_tools as $class => $file ) {
            if ( file_exists( $file ) ) {
                require_once $file;
            }

            if ( class_exists( $class ) ) {
                $this->register_tool( new $class() );
            }
        }
    }
}
