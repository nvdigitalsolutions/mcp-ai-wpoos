<?php
/**
 * Admin Crawl4AI Monitor for NV oOS.
 *
 * This class provides a server-side UI for monitoring Crawl4AI jobs,
 * browser pools, and job history. It follows WordPress conventions
 * using admin_post_ actions and page redirects.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the monitoring UI for Crawl4AI integration.
 */
class WP_MCP_AI_Admin_Crawl4AI_Monitor {
	const PAGE_SLUG = 'wp-mcp-ai-crawl4ai-monitor';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 15 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_crawl4ai_stats', array( $this, 'ajax_get_stats' ) );
	}

	/**
	 * Register the Crawl4AI monitor page under the NV oOS menu.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Crawl4AI Monitor', 'mcp-ai-wpoos' ),
			__( 'Crawl4AI Monitor', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue lightweight styles for the monitor page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $this->page_hook !== $hook ) {
			return;
		}

		// Enqueue shared admin monitor styles.
		wp_enqueue_style(
			'wp-mcp-ai-admin-monitor-shared',
			WP_MCP_AI_URL . 'assets/css/admin-monitor-shared.css',
			array(),
			filemtime( WP_MCP_AI_PATH . 'assets/css/admin-monitor-shared.css' )
		);

		$inline_css = '.wp-mcp-ai-crawl4ai-monitor__intro{margin:1.5rem 0;padding:1rem;background:#f0f6fc;border-left:4px solid #2271b1;}'
			. '.wp-mcp-ai-crawl4ai-monitor__intro p{margin:0.5rem 0;}'
			. '.wp-mcp-ai-crawl4ai-monitor__intro p:first-child{margin-top:0;}'
			. '.wp-mcp-ai-crawl4ai-monitor__intro p:last-child{margin-bottom:0;}'
			. '.wp-mcp-ai-crawl4ai-monitor__stats{display:flex;gap:1.5rem;margin:1.5rem 0;}'
			. '.wp-mcp-ai-crawl4ai-monitor__stat{padding:1rem;background:#fff;border:1px solid #dcdcde;border-radius:4px;flex:1;}'
			. '.wp-mcp-ai-crawl4ai-monitor__stat-label{font-size:0.875rem;color:#646970;margin-bottom:0.25rem;}'
			. '.wp-mcp-ai-crawl4ai-monitor__stat-value{font-size:1.75rem;font-weight:600;color:#1d2327;}'
			. '.wp-mcp-ai-crawl4ai-monitor__table{margin-top:1.5rem;border-collapse:collapse;width:100%;}'
			. '.wp-mcp-ai-crawl4ai-monitor__table th,.wp-mcp-ai-crawl4ai-monitor__table td{border:1px solid #dcdcde;padding:0.75rem;text-align:left;vertical-align:top;}'
			. '.wp-mcp-ai-crawl4ai-monitor__table th{background:#f8f9ff;font-weight:600;}'
			. '.wp-mcp-ai-crawl4ai-monitor__empty{margin-top:1.5rem;padding:1.5rem;border:1px solid #dcdcde;background:#fff;border-radius:4px;}'
			. '.wp-mcp-ai-crawl4ai-monitor__empty h3{margin-top:0;}'
			. '.wp-mcp-ai-crawl4ai-monitor__status{display:inline-block;padding:0.25rem 0.5rem;border-radius:3px;font-size:0.75rem;font-weight:600;}'
			. '.wp-mcp-ai-crawl4ai-monitor__status--completed{background:#d5f0db;color:#0a5f1a;}'
			. '.wp-mcp-ai-crawl4ai-monitor__status--running{background:#e0f2ff;color:#0056a0;}'
			. '.wp-mcp-ai-crawl4ai-monitor__status--failed{background:#fee;color:#a00;}'
			. '.wp-mcp-ai-crawl4ai-monitor__status--pending{background:#fef7e0;color:#8b6c00;}';

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline style registered with no URL; version not applicable.
		wp_register_style( 'wp-mcp-ai-crawl4ai-monitor-inline', false );
		wp_enqueue_style( 'wp-mcp-ai-crawl4ai-monitor-inline' );
		wp_add_inline_style( 'wp-mcp-ai-crawl4ai-monitor-inline', $inline_css );

		// Enqueue JavaScript for auto-refresh functionality.
		wp_enqueue_script(
			'wp-mcp-ai-admin-crawl4ai-monitor',
			WP_MCP_AI_URL . 'assets/js/admin-crawl4ai-monitor.js',
			array( 'jquery' ),
			filemtime( WP_MCP_AI_PATH . 'assets/js/admin-crawl4ai-monitor.js' ),
			true
		);

		wp_localize_script(
			'wp-mcp-ai-admin-crawl4ai-monitor',
			'wpMcpAiCrawl4AI',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_crawl4ai_monitor' ),
				'strings' => array(
					'noJobs' => __( 'No crawl jobs found.', 'mcp-ai-wpoos' ),
				),
			)
		);
	}

	/**
	 * Get Crawl4AI job statistics for display.
	 *
	 * @return array Statistics array.
	 */
	private function get_statistics() {
		$stats = array(
			'total_jobs'     => 0,
			'running_jobs'   => 0,
			'completed_jobs' => 0,
			'failed_jobs'    => 0,
			'browser_pools'  => 0,
		);

		// Get statistics from Crawl4AI API if available.
		if ( class_exists( 'WP_MCP_AI_Crawl4AI_Local_API' ) ) {
			try {
				$stats = WP_MCP_AI_Crawl4AI_Local_API::get_statistics();
			} catch ( Exception $e ) {
				// Log error but continue with empty stats.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'error',
						'Failed to get Crawl4AI statistics: ' . $e->getMessage()
					);
				}
			}
		}

		return $stats;
	}

	/**
	 * Get recent Crawl4AI jobs for display.
	 *
	 * @return array Array of job data.
	 */
	private function get_recent_jobs() {
		$jobs = array();

		// Get jobs from Crawl4AI API if available.
		if ( class_exists( 'WP_MCP_AI_Crawl4AI_Local_API' ) ) {
			try {
				$jobs = WP_MCP_AI_Crawl4AI_Local_API::get_recent_jobs( array( 'limit' => 50 ) );
			} catch ( Exception $e ) {
				// Log error but continue with empty jobs.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'error',
						'Failed to get Crawl4AI jobs: ' . $e->getMessage()
					);
				}
			}
		}

		return $jobs;
	}

	/**
	 * AJAX handler for getting Crawl4AI statistics.
	 *
	 * @return void
	 */
	public function ajax_get_stats() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_crawl4ai_monitor', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$stats = $this->get_statistics();
		$jobs  = $this->get_recent_jobs();

		wp_send_json_success(
			array(
				'stats' => $stats,
				'jobs'  => $jobs,
			)
		);
	}

	/**
	 * Render the Crawl4AI monitor page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stats = $this->get_statistics();
		$jobs  = $this->get_recent_jobs();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Crawl4AI Monitor', 'mcp-ai-wpoos' ); ?></h1>

			<div class="wp-mcp-ai-crawl4ai-monitor__notices"></div>

			<div class="auto-refresh-controls">
				<label for="toggle-auto-refresh">
					<input type="checkbox" id="toggle-auto-refresh" checked />
					<?php esc_html_e( 'Auto-refresh (every 10 seconds)', 'mcp-ai-wpoos' ); ?>
				</label>
				<button type="button" id="refresh-crawl4ai-stats" class="button button-secondary">
					<span class="dashicons dashicons-update-alt"></span>
					<?php esc_html_e( 'Refresh Now', 'mcp-ai-wpoos' ); ?>
				</button>
				<span class="last-refresh">
					<?php esc_html_e( 'Last updated:', 'mcp-ai-wpoos' ); ?>
					<strong id="last-refresh-time"><?php echo esc_html( wp_date( 'H:i:s' ) ); ?></strong>
				</span>
			</div>

			<div class="wp-mcp-ai-crawl4ai-monitor__intro">
				<p><strong><?php esc_html_e( 'About Crawl4AI Monitor', 'mcp-ai-wpoos' ); ?></strong></p>
				<p><?php esc_html_e( 'The Crawl4AI Monitor displays web crawling jobs, browser pool status, and job history for the Crawl4AI integration. Crawl4AI allows AI assistants to scrape and extract data from websites using browser automation.', 'mcp-ai-wpoos' ); ?></p>
				<p><?php esc_html_e( 'Configure Crawl4AI settings in NV oOS → Integrations → External Tools to connect to your Crawl4AI server.', 'mcp-ai-wpoos' ); ?></p>
			</div>

			<?php
			// Check if Crawl4AI is configured.
			$crawl4ai_configured = false;
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				$settings            = WP_MCP_AI_Admin_Settings::get_settings();
				$crawl4ai_configured = ! empty( $settings['crawl4ai_base_url'] );
			}

			if ( ! $crawl4ai_configured ) :
				?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: URL to settings page */
							wp_kses_post( __( 'Crawl4AI is not configured. Please configure it in <a href="%s">NV oOS → Integrations → External Tools</a>.', 'mcp-ai-wpoos' ) ),
							esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=integrations' ) )
						);
						?>
					</p>
				</div>
				<?php
			endif;
			?>

			<div class="wp-mcp-ai-crawl4ai-monitor__stats">
				<div class="wp-mcp-ai-crawl4ai-monitor__stat">
					<div class="wp-mcp-ai-crawl4ai-monitor__stat-label"><?php esc_html_e( 'Total Jobs', 'mcp-ai-wpoos' ); ?></div>
					<div class="wp-mcp-ai-crawl4ai-monitor__stat-value" data-stat="total_jobs"><?php echo esc_html( $stats['total_jobs'] ); ?></div>
				</div>
				<div class="wp-mcp-ai-crawl4ai-monitor__stat">
					<div class="wp-mcp-ai-crawl4ai-monitor__stat-label"><?php esc_html_e( 'Running', 'mcp-ai-wpoos' ); ?></div>
					<div class="wp-mcp-ai-crawl4ai-monitor__stat-value" data-stat="running_jobs"><?php echo esc_html( $stats['running_jobs'] ); ?></div>
				</div>
				<div class="wp-mcp-ai-crawl4ai-monitor__stat">
					<div class="wp-mcp-ai-crawl4ai-monitor__stat-label"><?php esc_html_e( 'Completed', 'mcp-ai-wpoos' ); ?></div>
					<div class="wp-mcp-ai-crawl4ai-monitor__stat-value" data-stat="completed_jobs"><?php echo esc_html( $stats['completed_jobs'] ); ?></div>
				</div>
				<div class="wp-mcp-ai-crawl4ai-monitor__stat">
					<div class="wp-mcp-ai-crawl4ai-monitor__stat-label"><?php esc_html_e( 'Failed', 'mcp-ai-wpoos' ); ?></div>
					<div class="wp-mcp-ai-crawl4ai-monitor__stat-value" data-stat="failed_jobs"><?php echo esc_html( $stats['failed_jobs'] ); ?></div>
				</div>
				<div class="wp-mcp-ai-crawl4ai-monitor__stat">
					<div class="wp-mcp-ai-crawl4ai-monitor__stat-label"><?php esc_html_e( 'Browser Pools', 'mcp-ai-wpoos' ); ?></div>
					<div class="wp-mcp-ai-crawl4ai-monitor__stat-value" data-stat="browser_pools"><?php echo esc_html( $stats['browser_pools'] ); ?></div>
				</div>
			</div>

			<?php if ( empty( $jobs ) ) : ?>
				<div class="wp-mcp-ai-crawl4ai-monitor__empty">
					<h3><?php esc_html_e( 'No Crawl Jobs Yet', 'mcp-ai-wpoos' ); ?></h3>
					<p><?php esc_html_e( 'No web crawling jobs have been executed yet. The AI Assistant can create crawl jobs using the run_crawl4ai_job tool.', 'mcp-ai-wpoos' ); ?></p>
					<?php if ( $crawl4ai_configured ) : ?>
						<p><?php esc_html_e( 'Once the assistant creates crawl jobs, they will appear here for monitoring and review.', 'mcp-ai-wpoos' ); ?></p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<table class="wp-mcp-ai-crawl4ai-monitor__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Job ID', 'mcp-ai-wpoos' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL', 'mcp-ai-wpoos' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Started', 'mcp-ai-wpoos' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Duration', 'mcp-ai-wpoos' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Browser Pool', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody id="crawl4ai-jobs-table">
						<?php foreach ( $jobs as $job ) : ?>
							<tr>
								<td><code><?php echo esc_html( $job['id'] ?? 'N/A' ); ?></code></td>
								<td><?php echo esc_html( $job['url'] ?? 'N/A' ); ?></td>
								<td>
									<?php
									$status       = $job['status'] ?? 'unknown';
									$status_class = 'wp-mcp-ai-crawl4ai-monitor__status--pending';
									if ( 'completed' === $status ) {
										$status_class = 'wp-mcp-ai-crawl4ai-monitor__status--completed';
									} elseif ( 'running' === $status ) {
										$status_class = 'wp-mcp-ai-crawl4ai-monitor__status--running';
									} elseif ( 'failed' === $status ) {
										$status_class = 'wp-mcp-ai-crawl4ai-monitor__status--failed';
									}
									?>
									<span class="wp-mcp-ai-crawl4ai-monitor__status <?php echo esc_attr( $status_class ); ?>">
										<?php echo esc_html( ucfirst( $status ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $job['started'] ?? 'N/A' ); ?></td>
								<td><?php echo esc_html( $job['duration'] ?? 'N/A' ); ?></td>
								<td><?php echo esc_html( $job['browser_pool'] ?? 'N/A' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
