<?php
/**
 * F&B Management Admin Menu Registry
 *
 * Registers the top-level "F&B Management" admin menu section and provides
 * the parent slug for the F&B native-storage CPT screens.
 *
 * This mirrors the "NV CRM" / "NV Projects" pattern: a dedicated top-level
 * menu for a CPT-heavy Pro toolkit, landing on a dashboard page and linking
 * to each CPT listing plus the toolkit settings page (which itself lives
 * under the NV oOS Pro menu).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * F&B Management Admin Menu Registration Class
 */
class WP_MCP_AI_Fnb_Admin_Menu {

	/**
	 * Parent menu slug for the F&B Management top-level admin menu.
	 *
	 * @var string
	 */
	const PARENT_SLUG = 'nvoos-fnb-dashboard';

	/**
	 * Page hook for the dashboard landing page.
	 *
	 * @var string|null
	 */
	private static $dashboard_hook = null;

	/**
	 * Whether the menu has been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Initialize the F&B admin menu.
	 *
	 * Registers the top-level "F&B Management" menu at priority 25 so that
	 * submenu pages registered at priority 26+ can attach to it.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$registered ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'register_parent_menu' ), 25 );
		add_action( 'admin_menu', array( __CLASS__, 'register_submenus' ), 28 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_dashboard_assets' ) );
		self::$registered = true;
	}

	/**
	 * Register the top-level "F&B Management" admin menu.
	 *
	 * This serves as the landing page for the F&B section and renders the
	 * toolkit dashboard by default.
	 *
	 * @return void
	 */
	public static function register_parent_menu() {
		self::$dashboard_hook = add_menu_page(
			__( 'F&B Management', 'mcp-ai-wpoos-pro' ),
			__( 'F&B Management', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PARENT_SLUG,
			array( 'WP_MCP_AI_Fnb_Dashboard_Page', 'render_page' ),
			'dashicons-food',
			32
		);
	}

	/**
	 * Register the native-storage CPT listing screens under F&B Management.
	 *
	 * @return void
	 */
	public static function register_submenus() {
		$positions = 10;

		foreach ( self::submenu_cpts() as $post_type => $label ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}
			add_submenu_page(
				self::PARENT_SLUG,
				$label,
				$label,
				'edit_posts',
				'edit.php?post_type=' . $post_type,
				'',
				$positions
			);
			$positions += 10;
		}

		// Toolkit settings live under the NV oOS Pro menu; link to the page.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'F&B Settings', 'mcp-ai-wpoos-pro' ),
			__( 'Settings', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'admin.php?page=wp-mcp-ai-fnb',
			'',
			$positions + 10
		);
	}

	/**
	 * Native-storage CPT slug → submenu label map.
	 *
	 * @return array<string,string>
	 */
	private static function submenu_cpts() {
		return array(
			'mcp_ai_fnb_sale'     => __( 'Sales', 'mcp-ai-wpoos-pro' ),
			'mcp_ai_fnb_purchase' => __( 'Purchases', 'mcp-ai-wpoos-pro' ),
			'mcp_ai_fnb_stock'    => __( 'Stock Counts', 'mcp-ai-wpoos-pro' ),
			'mcp_ai_fnb_waste'    => __( 'Waste', 'mcp-ai-wpoos-pro' ),
			'mcp_ai_fnb_booking'  => __( 'Bookings', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get the parent menu slug for F&B submenu pages.
	 *
	 * @return string
	 */
	public static function get_parent_slug() {
		return self::PARENT_SLUG;
	}

	/**
	 * Get the dashboard page hook.
	 *
	 * @return string|null
	 */
	public static function get_dashboard_hook() {
		return self::$dashboard_hook;
	}

	/**
	 * Enqueue Chart.js and the F&B trend-chart payload + initialiser on the
	 * dashboard page only. Mirrors the Chat Channels dashboard enqueue pattern:
	 * register the shared `wp-mcp-ai-chartjs` handle, emit the data as an inline
	 * "before" block, then the chart initialiser as a default (after) block so
	 * it always runs once Chart.js has loaded in the footer.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_dashboard_assets( $hook ) {
		if ( null === self::$dashboard_hook || self::$dashboard_hook !== $hook ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Dashboard_Page' ) || ! class_exists( 'WP_MCP_AI_Fnb_Metrics' ) ) {
			return;
		}

		if ( class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
			WP_MCP_AI_Chart_JS_Helper::register_chart_js();
		} else {
			// Fallback: register from the base-plugin vendor path directly.
			$chart_js_path = WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js';
			$chart_js_url  = WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js';
			if ( file_exists( $chart_js_path ) ) {
				wp_register_script( 'wp-mcp-ai-chartjs', $chart_js_url, array(), (string) filemtime( $chart_js_path ), true );
			}
		}
		wp_enqueue_script( 'wp-mcp-ai-chartjs' );

		$payload = WP_MCP_AI_Fnb_Dashboard_Page::trend_payload();
		if ( null === $payload ) {
			return;
		}

		wp_add_inline_script(
			'wp-mcp-ai-chartjs',
			'var wpMcpAiFnbTrends = ' . wp_json_encode( $payload ) . ';',
			'before'
		);
		wp_add_inline_script( 'wp-mcp-ai-chartjs', WP_MCP_AI_Fnb_Dashboard_Page::trend_init_script() );
	}
}
