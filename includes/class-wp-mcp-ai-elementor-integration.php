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

        $widget_files = array(
            'class-wp-mcp-ai-elementor-widget.php',
            'class-wp-mcp-ai-elementor-chat-intro-widget.php',
            'class-wp-mcp-ai-elementor-chat-faq-widget.php',
        );

        foreach ( $widget_files as $file ) {
            $path = WP_MCP_AI_PATH . 'includes/elementor/' . $file;

            if ( file_exists( $path ) ) {
                require_once $path;
            }
        }

        $widget_classes = array(
            'WP_MCP_AI_Elementor_Widget',
            'WP_MCP_AI_Elementor_Chat_Intro_Widget',
            'WP_MCP_AI_Elementor_Chat_FAQ_Widget',
        );

        foreach ( $widget_classes as $widget_class ) {
            if ( class_exists( $widget_class ) ) {
                $widgets_manager->register( new $widget_class() );
            }
        }
    }
}
