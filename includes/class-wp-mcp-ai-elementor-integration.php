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

		// Determine if we should use output buffering.
		// Skip buffering during Elementor editor page loads to prevent interference with
		// WordPress authentication checks and other admin scripts that expect normal HTML output.
		// Only use buffering during AJAX requests where JSON responses are expected.
		$is_elementor_editor = $this->is_elementor_editor_page_load();
		$should_buffer       = ! $is_elementor_editor;

		// Start output buffering to catch any stray output that could break JSON responses.
		// This is critical because Elementor uses AJAX with JSON responses during widget loading.
		if ( $should_buffer ) {
			ob_start();
		}

		try {
			// Verify the shortcode class is loaded before loading widgets that depend on it.
			// This prevents fatal errors when widgets reference WP_MCP_AI_Shortcode constants.
			if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
				// Clean the output buffer and return early if dependency is missing.
				if ( $should_buffer ) {
					ob_end_clean();
				}
				return;
			}

			// Load the shared trait first, as all widgets depend on it.
			$trait_path = WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';
			if ( ! file_exists( $trait_path ) ) {
				if ( $should_buffer ) {
					ob_end_clean();
				}
				return;
			}

			require_once $trait_path;

			// Verify the trait was successfully loaded before proceeding.
			if ( ! trait_exists( 'WP_MCP_AI_Elementor_Text_Formatting' ) ) {
				if ( $should_buffer ) {
					ob_end_clean();
				}
				return;
			}

			$widget_files = array(
				'class-wp-mcp-ai-elementor-widget.php',
				'class-wp-mcp-ai-elementor-professional-selector-widget.php',
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
				'class-wp-mcp-ai-elementor-quick-actions-widget.php',
				'class-wp-mcp-ai-elementor-telegram-login-widget.php',
			);

			foreach ( $widget_files as $file ) {
				$path = WP_MCP_AI_PATH . 'includes/elementor/' . $file;

				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}

			$widget_classes = array(
				'WP_MCP_AI_Elementor_Widget',
				'WP_MCP_AI_Elementor_Professional_Selector_Widget',
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
				'WP_MCP_AI_Elementor_Quick_Actions_Widget',
				'WP_MCP_AI_Elementor_Telegram_Login_Widget',
			);

			foreach ( $widget_classes as $widget_class ) {
				if ( class_exists( $widget_class ) ) {
					$widgets_manager->register( new $widget_class() );
				}
			}

			// Discard any output that was captured to prevent breaking JSON responses.
			if ( $should_buffer ) {
				ob_end_clean();
			}
		} catch ( \Exception $e ) {
			// If an exception occurs, clean the buffer and log the error.
			// This prevents the exception message from being output as HTML.
			if ( $should_buffer ) {
				ob_end_clean();
			}

			// Log the error if WP_DEBUG is enabled and the logger is available.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'error',
					'Elementor widget registration failed: ' . $e->getMessage(),
					array()
				);
			}
		}
	}

	/**
	 * Check if currently in Elementor editor page load context.
	 *
	 * Detects if we're loading the Elementor editor page (not AJAX requests).
	 * This is used to skip output buffering during editor page loads, which would
	 * interfere with WordPress admin scripts like wp-auth-check.
	 *
	 * @return bool True if in Elementor editor page load context, false otherwise.
	 */
	protected function is_elementor_editor_page_load() {
		// Check if this is an AJAX request.
		$is_ajax_request = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );

		// If it's an AJAX request, it's not an editor page load.
		if ( $is_ajax_request ) {
			return false;
		}

		// Check if Elementor editor is being loaded via GET parameter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
		if ( isset( $_GET['action'] ) && 'elementor' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
			return true;
		}

		// Check if Elementor Plugin is loaded and editor is active.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor = \Elementor\Plugin::instance();
			if ( $elementor && isset( $elementor->editor ) && $elementor->editor && method_exists( $elementor->editor, 'is_edit_mode' ) ) {
				return $elementor->editor->is_edit_mode();
			}
		}

		return false;
	}
}
