<?php
/**
 * Pro Dashboard Helper
 *
 * Provides helper methods for Pro Dashboard asset management and script registration.
 * This helper ensures Chart.js and Pro Dashboard scripts are consistently loaded
 * across all Pro Dashboard pages (main dashboard, diagnostic page, etc.).
 *
 * @package WP_MCP_AI
 * @since 1.5.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro Dashboard helper class for asset management.
 */
class WP_MCP_AI_Pro_Dashboard_Helper {

	/**
	 * Initialize the helper.
	 *
	 * NOTE: Auto-enqueuing is disabled to prevent conflicts with Pro Dashboard's
	 * own enqueue_assets() method. The helper provides utility methods for other
	 * contexts but should not automatically enqueue on Pro Dashboard pages.
	 */
	public static function init() {
		// Auto-enqueuing disabled - Pro Dashboard class handles its own asset loading.
		// add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_pro_dashboard_assets' ) );.
	}

	/**
	 * Conditionally enqueue Pro Dashboard assets on relevant pages.
	 *
	 * This method automatically detects Pro Dashboard pages and enqueues
	 * the necessary scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function maybe_enqueue_pro_dashboard_assets( $hook ) {
		// Check if we're on a Pro Dashboard page.
		if ( ! self::is_pro_dashboard_page( $hook ) ) {
			return;
		}

		// Enqueue all Pro Dashboard assets.
		self::enqueue_pro_dashboard_assets();
	}

	/**
	 * Check if the current page is a Pro Dashboard page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool True if on a Pro Dashboard page, false otherwise.
	 */
	public static function is_pro_dashboard_page( $hook ) {
		// Main Pro Dashboard page.
		if ( 'toplevel_page_nvoos-pro-dashboard' === $hook ) {
			return true;
		}

		// Diagnostic page.
		if ( 'nv-oos-pro_page_nvoos-pro-dashboard-diagnostic' === $hook ) {
			return true;
		}

		// Allow other pages to be registered as Pro Dashboard pages via filter.
		return apply_filters( 'wp_mcp_ai_is_pro_dashboard_page', false, $hook );
	}

	/**
	 * Register Chart.js library for Pro Dashboard.
	 *
	 * This method registers Chart.js using the centralized Chart.js Helper
	 * for consistency across the plugin.
	 */
	public static function register_chart_js() {
		if ( class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
			WP_MCP_AI_Chart_JS_Helper::register_chart_js();
		} else {
			// Fallback: Register Chart.js directly if helper class not available.
			$chart_js_path = WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js';
			$chart_js_url  = WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js';

			wp_register_script(
				'chartjs',
				$chart_js_url,
				array(),
				file_exists( $chart_js_path ) ? filemtime( $chart_js_path ) : '4.4.1',
				true
			);
		}
	}

	/**
	 * Register Pro Dashboard scripts and styles.
	 *
	 * This method only registers the assets without enqueuing them.
	 * Useful when you want assets available as dependencies but not loaded yet.
	 */
	public static function register_pro_dashboard_assets() {
		// Register Chart.js.
		self::register_chart_js();

		// Register responsive utilities CSS.
		$responsive_css_path = WP_MCP_AI_PATH . 'assets/css/admin-responsive-utilities.css';
		if ( ! wp_style_is( 'wp-mcp-ai-responsive-utilities', 'registered' ) ) {
			wp_register_style(
				'wp-mcp-ai-responsive-utilities',
				WP_MCP_AI_URL . 'assets/css/admin-responsive-utilities.css',
				array(),
				file_exists( $responsive_css_path ) ? filemtime( $responsive_css_path ) : WP_MCP_AI_VERSION
			);
		}

		// Register Pro Dashboard CSS.
		$dashboard_css_path = WP_MCP_AI_PATH . 'assets/css/pro-dashboard.css';
		if ( ! wp_style_is( 'wp-mcp-ai-pro-dashboard', 'registered' ) ) {
			wp_register_style(
				'wp-mcp-ai-pro-dashboard',
				WP_MCP_AI_URL . 'assets/css/pro-dashboard.css',
				array( 'wp-mcp-ai-responsive-utilities' ),
				file_exists( $dashboard_css_path ) ? filemtime( $dashboard_css_path ) : WP_MCP_AI_VERSION
			);
		}

		// Register Pro Dashboard JS.
		if ( ! wp_script_is( 'wp-mcp-ai-pro-dashboard', 'registered' ) ) {
			wp_register_script(
				'wp-mcp-ai-pro-dashboard',
				WP_MCP_AI_URL . 'assets/js/pro-dashboard.js',
				array( 'jquery', 'chartjs' ),
				WP_MCP_AI_VERSION,
				true
			);
		}
	}

	/**
	 * Enqueue Pro Dashboard scripts and styles.
	 *
	 * This method registers and enqueues all Pro Dashboard assets including
	 * Chart.js, responsive utilities, and Pro Dashboard specific scripts/styles.
	 *
	 * @param array $chart_data Optional. Chart data to pass to JavaScript. Default null.
	 */
	public static function enqueue_pro_dashboard_assets( $chart_data = null ) {
		// First, register all assets.
		self::register_pro_dashboard_assets();

		// Enqueue Chart.js.
		wp_enqueue_script( 'chartjs' );

		// Enqueue responsive utilities CSS.
		wp_enqueue_style( 'wp-mcp-ai-responsive-utilities' );

		// Enqueue Pro Dashboard CSS.
		wp_enqueue_style( 'wp-mcp-ai-pro-dashboard' );

		// Enqueue Pro Dashboard JS.
		wp_enqueue_script( 'wp-mcp-ai-pro-dashboard' );

		// If chart data provided or can be generated, localize the script.
		if ( null === $chart_data && class_exists( 'WP_MCP_AI_Pro_Dashboard' ) ) {
			$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();

			// Try to get chart data via reflection (private method).
			try {
				$reflection = new ReflectionClass( $dashboard );
				$method     = $reflection->getMethod( 'get_chart_data' );
				$method->setAccessible( true );
				$chart_data = $method->invoke( $dashboard );
			} catch ( Exception $e ) {
				// If reflection fails, set empty chart data.
				$chart_data = array();
			}
		}

		// Localize script with configuration data.
		wp_localize_script(
			'wp-mcp-ai-pro-dashboard',
			'wpMcpAiProDashboard',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'restUrl'     => esc_url_raw( rest_url() ),
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_pro_dashboard' ),
				'isProActive' => self::is_pro_active(),
				'chartData'   => $chart_data ? $chart_data : array(),
				'debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			)
		);
	}

	/**
	 * Check if Pro features are active.
	 *
	 * @return bool True if Pro features are available.
	 */
	private static function is_pro_active() {
		// Check for wp-config.php constant first (recommended method).
		if ( defined( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED' ) && WP_MCP_AI_PRO_DASHBOARD_ENABLED ) {
			return true;
		}

		// Check via filter for backward compatibility.
		return apply_filters( 'wp_mcp_ai_pro_dashboard_available', false );
	}

	/**
	 * Get Chart.js configuration for Pro Dashboard charts.
	 *
	 * Provides default Chart.js configuration optimized for Pro Dashboard.
	 *
	 * @return array Chart.js configuration array.
	 */
	public static function get_chart_config() {
		return array(
			'responsive'          => true,
			'maintainAspectRatio' => true,
			'plugins'             => array(
				'legend'  => array(
					'display'  => true,
					'position' => 'bottom',
				),
				'tooltip' => array(
					'enabled' => true,
				),
			),
		);
	}
}

// Initialize the helper.
WP_MCP_AI_Pro_Dashboard_Helper::init();
