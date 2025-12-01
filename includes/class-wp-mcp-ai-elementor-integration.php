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

		// Load the shared trait first, as all widgets depend on it.
		$trait_path = WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';
		if ( ! file_exists( $trait_path ) ) {
			return;
		}

		require_once $trait_path;

		// Verify the trait was successfully loaded before proceeding.
		if ( ! trait_exists( 'WP_MCP_AI_Elementor_Text_Formatting' ) ) {
			return;
		}

		$widget_files = array(
			'class-wp-mcp-ai-elementor-widget.php',
			'class-wp-mcp-ai-elementor-assistant-defaults-widget.php',
			'class-wp-mcp-ai-elementor-assistant-base-knowledge-widget.php',
			'class-wp-mcp-ai-elementor-assistant-prompt-shortcuts-widget.php',
			'class-wp-mcp-ai-elementor-assistant-tools-widget.php',
			'class-wp-mcp-ai-elementor-chat-intro-widget.php',
			'class-wp-mcp-ai-elementor-chat-faq-widget.php',
			'class-wp-mcp-ai-elementor-chat-usage-timer-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-user-capability-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-user-files-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-user-chats-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-theme-preview-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-provider-links-widget.php',
			'class-wp-mcp-ai-elementor-dashboard-activity-feed-widget.php',
			'class-wp-mcp-ai-elementor-performance-test-runner-widget.php',
			'class-wp-mcp-ai-elementor-performance-metrics-widget.php',
			'class-wp-mcp-ai-elementor-performance-trends-widget.php',
			'class-wp-mcp-ai-elementor-test-results-table-widget.php',
			'class-wp-mcp-ai-elementor-performance-recommendations-widget.php',
			'class-wp-mcp-ai-elementor-system-health-status-widget.php',
		);

		foreach ( $widget_files as $file ) {
			$path = WP_MCP_AI_PATH . 'includes/elementor/' . $file;

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		$widget_classes = array(
			'WP_MCP_AI_Elementor_Widget',
			'WP_MCP_AI_Elementor_Assistant_Defaults_Widget',
			'WP_MCP_AI_Elementor_Assistant_Base_Knowledge_Widget',
			'WP_MCP_AI_Elementor_Assistant_Prompt_Shortcuts_Widget',
			'WP_MCP_AI_Elementor_Assistant_Tools_Widget',
			'WP_MCP_AI_Elementor_Chat_Intro_Widget',
			'WP_MCP_AI_Elementor_Chat_FAQ_Widget',
			'WP_MCP_AI_Elementor_Chat_Usage_Timer_Widget',
			'WP_MCP_AI_Elementor_Dashboard_Tool_Matrix_Widget',
			'WP_MCP_AI_Elementor_Dashboard_User_Capability_Widget',
			'WP_MCP_AI_Elementor_Dashboard_User_Files_Widget',
			'WP_MCP_AI_Elementor_Dashboard_User_Chats_Widget',
			'WP_MCP_AI_Elementor_Dashboard_Theme_Preview_Widget',
			'WP_MCP_AI_Elementor_Dashboard_Provider_Links_Widget',
			'WP_MCP_AI_Elementor_Dashboard_Activity_Feed_Widget',
			'WP_MCP_AI_Elementor_Performance_Test_Runner_Widget',
			'WP_MCP_AI_Elementor_Performance_Metrics_Widget',
			'WP_MCP_AI_Elementor_Performance_Trends_Widget',
			'WP_MCP_AI_Elementor_Test_Results_Table_Widget',
			'WP_MCP_AI_Elementor_Performance_Recommendations_Widget',
			'WP_MCP_AI_Elementor_System_Health_Status_Widget',
		);

		foreach ( $widget_classes as $widget_class ) {
			if ( class_exists( $widget_class ) ) {
				$widgets_manager->register( new $widget_class() );
			}
		}
	}
}
