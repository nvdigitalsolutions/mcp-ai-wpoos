<?php
/**
 * Elementor integration for the chat shortcode.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles Elementor widget registration.
 */
class WP_MCP_AI_Elementor_Integration {
    /**
     * Maybe bootstrap the Elementor integration when Elementor is available.
     */
    public static function maybe_init() {
        if ( ! did_action( 'elementor/loaded' ) ) {
            add_action( 'elementor/loaded', array( __CLASS__, 'init' ) );
            return;
        }

        self::init();
    }

    /**
     * Initialise the Elementor integration if Elementor is active.
     */
    public static function init() {
        if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
            return;
        }

        $integration = new self();
        $integration->register_hooks();
    }

    /**
     * Register hooks used to add the widget.
     */
    protected function register_hooks() {
        add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
    }

    /**
     * Register the chat widget with Elementor.
     *
     * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager instance.
     */
    public function register_widget( $widgets_manager ) {
        if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
            return;
        }

        require_once WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-widget.php';

        if ( class_exists( 'WP_MCP_AI_Elementor_Widget' ) ) {
            $widgets_manager->register( new WP_MCP_AI_Elementor_Widget() );
        }
    }
}
