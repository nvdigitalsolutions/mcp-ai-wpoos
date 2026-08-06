<?php
/**
 * Pro Status Dashboard Page
 *
 * Admin dashboard page for monitoring service component health, triggering
 * manual health checks, and managing component visibility. Registered under
 * the NV oOS Pro Dashboard menu.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Admin
 * @since     1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Status_Dashboard_Page' ) ) {
	/**
	 * Pro Status Dashboard Page class.
	 *
	 * @since 1.2.0
	 */
	class WP_MCP_AI_Pro_Status_Dashboard_Page {

		/**
		 * Page slug.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const PAGE_SLUG = 'nvoos-pro-status';

		/**
		 * AJAX nonce action.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const NONCE_ACTION = 'wp_mcp_ai_status_dashboard';

		/**
		 * Actual WordPress hook name returned by add_submenu_page().
		 *
		 * @since 1.2.0
		 * @var string
		 */
		private string $page_hook = '';

		/**
		 * Constructor.
		 *
		 * @since 1.2.0
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_page' ), 28 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Register the admin submenu page under the Pro Dashboard.
		 *
		 * Priority 28 places it after the Command Center (27).
		 *
		 * @since 1.2.0
		 *
		 * @return void
		 */
		public function register_page(): void {
			$this->page_hook = add_submenu_page(
				'nvoos-pro-dashboard',
				__( 'Status Page', 'mcp-ai-wpoos-pro' ),
				__( 'Status Page', 'mcp-ai-wpoos-pro' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Enqueue styles and scripts for the status dashboard page.
		 *
		 * @since 1.2.0
		 *
		 * @param string $hook The current admin page hook.
		 * @return void
		 */
		public function enqueue_assets( string $hook ): void {
			$is_status_page = false;

			if ( ! empty( $this->page_hook ) && $hook === $this->page_hook ) {
				$is_status_page = true;
			}

			// Fallback: check GET parameter.
			if ( ! $is_status_page ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page slug check for asset enqueue.
				$is_status_page = isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'];
			}

			if ( ! $is_status_page ) {
				return;
			}

			// Chart.js for uptime history graph.
			wp_enqueue_script(
				'chart-js',
				'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js',
				array(),
				'4.4.7',
				true
			);

			// Status dashboard styles.
			wp_enqueue_style(
				'wp-mcp-ai-pro-status-page',
				WP_MCP_AI_PRO_URL . 'assets/css/pro-status-page.css',
				array(),
				WP_MCP_AI_PRO_VERSION
			);

			// Status dashboard scripts.
			wp_enqueue_script(
				'wp-mcp-ai-pro-status-page',
				WP_MCP_AI_PRO_URL . 'assets/js/pro-status-page.js',
				array( 'jquery', 'chart-js' ),
				WP_MCP_AI_PRO_VERSION,
				true
			);

			// Localize script data.
			wp_localize_script(
				'wp-mcp-ai-pro-status-page',
				'wpMcpAiStatusDashboard',
				array(
					'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
					'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
					'refreshInterval' => 60000, // 60 seconds.
					'strings'         => array(
						'loading'            => __( 'Loading...', 'mcp-ai-wpoos-pro' ),
						'error'              => __( 'Error loading status data.', 'mcp-ai-wpoos-pro' ),
						'refreshing'         => __( 'Refreshing...', 'mcp-ai-wpoos-pro' ),
						'healthCheckRunning' => __( 'Health check in progress...', 'mcp-ai-wpoos-pro' ),
						'healthCheckDone'    => __( 'Health check complete.', 'mcp-ai-wpoos-pro' ),
						'operational'        => __( 'Operational', 'mcp-ai-wpoos-pro' ),
						'degraded'           => __( 'Degraded', 'mcp-ai-wpoos-pro' ),
						'partialOutage'      => __( 'Partial Outage', 'mcp-ai-wpoos-pro' ),
						'majorOutage'        => __( 'Major Outage', 'mcp-ai-wpoos-pro' ),
						'maintenance'        => __( 'Maintenance', 'mcp-ai-wpoos-pro' ),
						'public'             => __( 'Public', 'mcp-ai-wpoos-pro' ),
						'private'            => __( 'Private', 'mcp-ai-wpoos-pro' ),
						'confirmToggle'      => __( 'Toggle this component\'s public visibility?', 'mcp-ai-wpoos-pro' ),
						'lastChecked'        => __( 'Last checked', 'mcp-ai-wpoos-pro' ),
						'justNow'            => __( 'Just now', 'mcp-ai-wpoos-pro' ),
						'never'              => __( 'Never', 'mcp-ai-wpoos-pro' ),
						'noComponents'       => __( 'No service components are registered. Add status sources via the wp_mcp_ai_service_status_sources filter.', 'mcp-ai-wpoos-pro' ),
						'publicStatusUrl'    => rest_url( 'mcp-ai/v1/status' ),
						'viewPublicPage'     => __( 'View Public Status Page', 'mcp-ai-wpoos-pro' ),
						'runHealthCheck'     => __( 'Run Health Check Now', 'mcp-ai-wpoos-pro' ),
						/* translators: Used as suffix for relative time, e.g. "5 min ago" */
						'ago'                => __( 'ago', 'mcp-ai-wpoos-pro' ),
						/* translators: Abbreviation for minutes */
						'minAbbr'            => _x( 'min', 'abbreviation for minutes', 'mcp-ai-wpoos-pro' ),
						/* translators: Abbreviation for hours */
						'hrAbbr'             => _x( 'hr', 'abbreviation for hours', 'mcp-ai-wpoos-pro' ),
						/* translators: Singular: 1 day */
						'daySingular'        => __( 'day', 'mcp-ai-wpoos-pro' ),
						/* translators: Plural: 2+ days */
						'dayPlural'          => __( 'days', 'mcp-ai-wpoos-pro' ),
					),
				)
			);
		}

		/**
		 * Render the status dashboard page.
		 *
		 * @since 1.2.0
		 *
		 * @return void
		 */
		public function render_page(): void {
			?>
			<div class="wrap wp-mcp-ai-pro-status-page">
				<h1>
					<span class="dashicons dashicons-performance" style="font-size:28px;width:28px;height:28px;vertical-align:middle;margin-right:8px;color:#2271b1;"></span>
					<?php esc_html_e( 'Status Page', 'mcp-ai-wpoos-pro' ); ?>
					<span class="pro-badge" style="display:inline-block;background:#2271b1;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;vertical-align:middle;margin-left:8px;">PRO</span>
				</h1>

				<div class="wp-mcp-ai-pro-status-toolbar">
					<button type="button" class="button button-primary wp-mcp-ai-status-refresh-btn">
						<span class="dashicons dashicons-update" style="vertical-align:middle;margin-right:4px;"></span>
						<?php esc_html_e( 'Refresh', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<button type="button" class="button wp-mcp-ai-status-health-check-btn">
						<span class="dashicons dashicons-saved" style="vertical-align:middle;margin-right:4px;"></span>
						<?php esc_html_e( 'Run Health Check', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<span class="wp-mcp-ai-status-last-refreshed" style="margin-left:12px;color:#50575e;font-size:13px;"></span>
					<a href="<?php echo esc_url( rest_url( 'mcp-ai/v1/status' ) ); ?>" class="button" target="_blank" style="float:right;">
						<span class="dashicons dashicons-external" style="vertical-align:middle;margin-right:4px;"></span>
						<?php esc_html_e( 'Public API', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</div>

				<!-- Overall status banner -->
				<div class="wp-mcp-ai-pro-status-overall" id="wp-mcp-ai-status-overall">
					<span class="spinner is-active" style="float:none;margin:0;visibility:visible;"></span>
					<?php esc_html_e( 'Loading status data...', 'mcp-ai-wpoos-pro' ); ?>
				</div>

				<!-- Component grid -->
				<div class="wp-mcp-ai-pro-status-grid" id="wp-mcp-ai-status-grid">
					<!-- Populated by JS -->
				</div>

				<!-- Uptime history chart -->
				<div class="wp-mcp-ai-pro-status-history" style="margin-top:30px;">
					<h2><?php esc_html_e( 'Uptime History (30 days)', 'mcp-ai-wpoos-pro' ); ?></h2>
					<div class="wp-mcp-ai-pro-status-chart-container" style="background:#fff;border:1px solid #ccd0d4;padding:20px;border-radius:6px;">
						<canvas id="wp-mcp-ai-uptime-chart" width="800" height="300"></canvas>
					</div>
				</div>

				<!-- Shortcode usage help -->
				<div class="wp-mcp-ai-pro-status-help" style="margin-top:30px;background:#f0f6fc;border:1px solid #c5d9ed;padding:16px;border-radius:6px;">
					<h3><?php esc_html_e( 'Public Status Page', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p><?php esc_html_e( 'Use the shortcode below on any page or post to display a public-facing status page:', 'mcp-ai-wpoos-pro' ); ?></p>
					<code style="display:block;padding:10px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;font-size:14px;">
						[nvoos_status]
					</code>
					<p style="margin-top:8px;color:#50575e;"><?php esc_html_e( 'Options: show_history="true" compact="true"', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			</div>
			<?php
		}
	}

	// Bootstrap.
	if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
		new WP_MCP_AI_Pro_Status_Dashboard_Page();
	}
}
