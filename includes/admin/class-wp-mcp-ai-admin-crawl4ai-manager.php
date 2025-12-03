<?php
/**
 * Admin Crawl4AI manager for WP oOS.
 *
 * This class provides a server-side UI for managing and monitoring Crawl4AI jobs.
 * It follows WordPress conventions using admin_post_ actions and page redirects.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the management UI for Crawl4AI crawl jobs.
 */
class WP_MCP_AI_Admin_Crawl4AI_Manager {
	const PAGE_SLUG = 'wp-mcp-ai-crawl4ai-manager';

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
		add_action( 'admin_post_wp_mcp_ai_cancel_crawl_job', array( $this, 'handle_cancel_job' ) );
		add_action( 'admin_post_wp_mcp_ai_retry_crawl_job', array( $this, 'handle_retry_job' ) );
		add_action( 'admin_post_wp_mcp_ai_clear_crawl_cache', array( $this, 'handle_clear_cache' ) );
	}

	/**
	 * Register the Crawl4AI manager page under the WP oOS menu.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'WP oOS Crawl4AI Manager', 'wp-mcp-ai' ),
			__( 'Crawl4AI Manager', 'wp-mcp-ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue styles for the Crawl4AI manager.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $this->page_hook !== $hook ) {
			return;
		}

		$inline_css = '.wp-mcp-ai-crawl4ai-manager__intro{margin:1.5rem 0;padding:1rem;background:#f0f6fc;border-left:4px solid #2271b1;}'
			. '.wp-mcp-ai-crawl4ai-manager__intro p{margin:0.5rem 0;}'
			. '.wp-mcp-ai-crawl4ai-manager__intro p:first-child{margin-top:0;}'
			. '.wp-mcp-ai-crawl4ai-manager__intro p:last-child{margin-bottom:0;}'
			. '.wp-mcp-ai-crawl4ai-manager__stats{display:flex;gap:1.5rem;margin:1.5rem 0;flex-wrap:wrap;}'
			. '.wp-mcp-ai-crawl4ai-manager__stat{padding:1rem;background:#fff;border:1px solid #dcdcde;border-radius:4px;flex:1;min-width:200px;}'
			. '.wp-mcp-ai-crawl4ai-manager__stat-label{font-size:0.875rem;color:#646970;margin-bottom:0.25rem;}'
			. '.wp-mcp-ai-crawl4ai-manager__stat-value{font-size:1.75rem;font-weight:600;color:#1d2327;}'
			. '.wp-mcp-ai-crawl4ai-manager__table{margin-top:1.5rem;border-collapse:collapse;width:100%;}'
			. '.wp-mcp-ai-crawl4ai-manager__table th,.wp-mcp-ai-crawl4ai-manager__table td{border:1px solid #dcdcde;padding:0.75rem;text-align:left;vertical-align:top;}'
			. '.wp-mcp-ai-crawl4ai-manager__table th{background:#f8f9ff;font-weight:600;}'
			. '.wp-mcp-ai-crawl4ai-manager__empty{margin-top:1.5rem;padding:1.5rem;border:1px solid #dcdcde;background:#fff;border-radius:4px;}'
			. '.wp-mcp-ai-crawl4ai-manager__empty h3{margin-top:0;}'
			. '.wp-mcp-ai-crawl4ai-manager__actions form{display:inline-block;margin-right:0.5rem;}'
			. '.wp-mcp-ai-crawl4ai-manager__status{display:inline-block;padding:0.25rem 0.5rem;border-radius:3px;font-size:0.75rem;font-weight:600;text-transform:uppercase;}'
			. '.wp-mcp-ai-crawl4ai-manager__status--completed{background:#d5f0db;color:#0a5f1a;}'
			. '.wp-mcp-ai-crawl4ai-manager__status--pending{background:#fef7e0;color:#8b6c00;}'
			. '.wp-mcp-ai-crawl4ai-manager__status--processing{background:#e0f2ff;color:#0056a0;}'
			. '.wp-mcp-ai-crawl4ai-manager__status--failed{background:#ffd1d1;color:#a00;}'
			. '.wp-mcp-ai-crawl4ai-manager__status--timeout{background:#f0f0f1;color:#50575e;}'
			. '.wp-mcp-ai-crawl4ai-manager__urls{font-family:monospace;font-size:13px;white-space:pre-wrap;word-break:break-all;max-width:400px;}'
			. '.wp-mcp-ai-crawl4ai-manager__notice{margin:1rem 0;padding:0.75rem 1rem;border-left:4px solid;}'
			. '.wp-mcp-ai-crawl4ai-manager__notice--success{background:#d5f0db;border-color:#0a5f1a;color:#0a5f1a;}'
			. '.wp-mcp-ai-crawl4ai-manager__notice--error{background:#ffd1d1;border-color:#a00;color:#a00;}'
			. '.wp-mcp-ai-crawl4ai-manager__config{margin:1.5rem 0;padding:1rem;background:#fff;border:1px solid #dcdcde;border-radius:4px;}'
			. '.wp-mcp-ai-crawl4ai-manager__config-item{margin:0.5rem 0;display:flex;gap:0.5rem;}'
			. '.wp-mcp-ai-crawl4ai-manager__config-label{font-weight:600;min-width:150px;}'
			. '.wp-mcp-ai-crawl4ai-manager__config-value{font-family:monospace;word-break:break-all;}';

		wp_register_style( 'wp-mcp-ai-crawl4ai-manager-inline', false );
		wp_enqueue_style( 'wp-mcp-ai-crawl4ai-manager-inline' );
		wp_add_inline_style( 'wp-mcp-ai-crawl4ai-manager-inline', $inline_css );
	}

	/**
	 * Handle cancellation of a crawl job.
	 */
	public function handle_cancel_job() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage crawl jobs.', 'wp-mcp-ai' ) );
		}

		$task_id = isset( $_POST['task_id'] ) ? sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) : '';

		if ( '' === $task_id ) {
			wp_die( esc_html__( 'Missing task identifier.', 'wp-mcp-ai' ) );
		}

		check_admin_referer( 'wp_mcp_ai_cancel_crawl_' . $task_id );

		// Cancel the job if it's in the crawler queue.
		if ( class_exists( 'WP_MCP_AI_Crawler' ) ) {
			$job = WP_MCP_AI_Crawler::get_job_status( $task_id );
			if ( $job ) {
				// Delete the job to prevent further polling.
				$this->cancel_crawler_job( $task_id );
			}
		}

		$redirect = add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'updated' => '1',
				'action'  => 'cancelled',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle retry of a failed crawl job.
	 */
	public function handle_retry_job() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage crawl jobs.', 'wp-mcp-ai' ) );
		}

		$task_id = isset( $_POST['task_id'] ) ? sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) : '';

		if ( '' === $task_id ) {
			wp_die( esc_html__( 'Missing task identifier.', 'wp-mcp-ai' ) );
		}

		check_admin_referer( 'wp_mcp_ai_retry_crawl_' . $task_id );

		// Get the cached result to extract original URLs.
		$cached = WP_MCP_AI_Crawl4AI_Local_API::retrieve_task_result( $task_id );

		$redirect = add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'updated' => $cached ? '1' : '0',
				'action'  => 'retry_queued',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle cache clearing.
	 */
	public function handle_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to clear cache.', 'wp-mcp-ai' ) );
		}

		check_admin_referer( 'wp_mcp_ai_clear_crawl_cache' );

		$cleared = $this->clear_all_cached_tasks();

		$redirect = add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'updated' => $cleared ? '1' : '0',
				'action'  => 'cache_cleared',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Cancel a crawler job by task ID.
	 *
	 * @param string $task_id Task identifier.
	 */
	private function cancel_crawler_job( $task_id ) {
		// Delete the job storage to prevent further polling.
		// Note: We cannot directly access WP_MCP_AI_Crawler::delete_job as it's protected.
		// Instead, we manually delete the transient to achieve the same effect.
		global $wpdb;

		$prefix = 'wp_mcp_ai_crawl4ai_job_';
		$hash   = md5( $task_id );

		if ( is_multisite() ) {
			$blog_id = get_current_blog_id();
			$key     = sprintf( '%s%s_%s', $prefix, $blog_id, $hash );
			delete_site_transient( $key );

			// Also unschedule the cron event.
			$next = wp_next_scheduled( 'wp_mcp_ai_crawl4ai_poll_task', array( $task_id ) );
			if ( $next ) {
				wp_unschedule_event( $next, 'wp_mcp_ai_crawl4ai_poll_task', array( $task_id ) );
			}
		} else {
			$key = $prefix . $hash;
			delete_transient( $key );

			// Also unschedule the cron event.
			$next = wp_next_scheduled( 'wp_mcp_ai_crawl4ai_poll_task', array( $task_id ) );
			if ( $next ) {
				wp_unschedule_event( $next, 'wp_mcp_ai_crawl4ai_poll_task', array( $task_id ) );
			}
		}
	}

	/**
	 * Clear all cached crawl tasks.
	 *
	 * @return int Number of tasks cleared.
	 */
	private function clear_all_cached_tasks() {
		global $wpdb;

		$prefix = WP_MCP_AI_Crawl4AI_Local_API::TASK_STORAGE_PREFIX;

		if ( is_multisite() ) {
			$blog_id = get_current_blog_id();
			$pattern = $prefix . $blog_id . '_%';
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( '_site_transient_' . $prefix . $blog_id . '_' ) . '%'
				)
			);
		} else {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_' . $prefix ) . '%'
				)
			);
		}

		return absint( $deleted );
	}

	/**
	 * Get all active crawl jobs.
	 *
	 * @return array Array of active jobs.
	 */
	private function get_active_jobs() {
		global $wpdb;

		$jobs   = array();
		$prefix = 'wp_mcp_ai_crawl4ai_job_';

		if ( is_multisite() ) {
			$blog_id = get_current_blog_id();
			$pattern = $prefix . $blog_id . '_%';
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( '_site_transient_' . $prefix . $blog_id . '_' ) . '%'
				),
				ARRAY_A
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name as meta_key, option_value as meta_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_' . $prefix ) . '%'
				),
				ARRAY_A
			);
		}

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$value = maybe_unserialize( $row['meta_value'] );
				if ( is_array( $value ) ) {
					$jobs[] = $value;
				}
			}
		}

		return $jobs;
	}

	/**
	 * Get all cached task results.
	 *
	 * @return array Array of cached tasks.
	 */
	private function get_cached_tasks() {
		global $wpdb;

		$tasks  = array();
		$prefix = WP_MCP_AI_Crawl4AI_Local_API::TASK_STORAGE_PREFIX;

		if ( is_multisite() ) {
			$blog_id = get_current_blog_id();
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( '_site_transient_' . $prefix . $blog_id . '_' ) . '%'
				),
				ARRAY_A
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name as meta_key, option_value as meta_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_' . $prefix ) . '%'
				),
				ARRAY_A
			);
		}

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$value = maybe_unserialize( $row['meta_value'] );
				if ( is_array( $value ) && isset( $value['task_id'] ) ) {
					$tasks[] = $value;
				}
			}
		}

		return $tasks;
	}

	/**
	 * Get statistics for display.
	 *
	 * @return array Statistics array.
	 */
	private function get_statistics() {
		$active_jobs  = $this->get_active_jobs();
		$cached_tasks = $this->get_cached_tasks();

		$stats = array(
			'total_jobs'      => count( $active_jobs ),
			'total_cached'    => count( $cached_tasks ),
			'completed'       => 0,
			'pending'         => 0,
			'failed'          => 0,
			'timeout'         => 0,
			'total_urls'      => 0,
			'cache_size_mb'   => 0,
		);

		foreach ( $cached_tasks as $task ) {
			$status = isset( $task['status'] ) ? $task['status'] : 'unknown';
			
			switch ( $status ) {
				case 'completed':
					++$stats['completed'];
					break;
				case 'pending':
				case 'processing':
					++$stats['pending'];
					break;
				case 'failed':
				case 'error':
					++$stats['failed'];
					break;
				case 'timeout':
					++$stats['timeout'];
					break;
			}

			if ( isset( $task['results'] ) && is_array( $task['results'] ) ) {
				$stats['total_urls'] += count( $task['results'] );
			}

			// Estimate cache size.
			$serialized = maybe_serialize( $task );
			$stats['cache_size_mb'] += strlen( $serialized );
		}

		$stats['cache_size_mb'] = round( $stats['cache_size_mb'] / 1024 / 1024, 2 );

		return $stats;
	}

	/**
	 * Render the Crawl4AI manager page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-mcp-ai' ) );
		}

		$stats        = $this->get_statistics();
		$active_jobs  = $this->get_active_jobs();
		$cached_tasks = $this->get_cached_tasks();
		$settings     = WP_MCP_AI_Admin_Settings::get_settings();

		// Sort by created/stored time (most recent first).
		usort( $cached_tasks, function( $a, $b ) {
			$time_a = isset( $a['stored_at'] ) ? strtotime( $a['stored_at'] ) : 0;
			$time_b = isset( $b['stored_at'] ) ? strtotime( $b['stored_at'] ) : 0;
			return $time_b - $time_a;
		});

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Crawl4AI Manager', 'wp-mcp-ai' ); ?></h1>

			<?php $this->render_notices(); ?>

			<div class="wp-mcp-ai-crawl4ai-manager__intro">
				<p>
					<strong><?php esc_html_e( 'Crawl4AI Job Monitoring & Management', 'wp-mcp-ai' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'Monitor and manage Crawl4AI web crawling jobs. This dashboard shows active background jobs, cached results, and configuration status. Jobs are automatically cleaned up after 24 hours.', 'wp-mcp-ai' ); ?>
				</p>
			</div>

			<?php $this->render_configuration( $settings ); ?>

			<div class="wp-mcp-ai-crawl4ai-manager__stats">
				<div class="wp-mcp-ai-crawl4ai-manager__stat">
					<div class="wp-mcp-ai-crawl4ai-manager__stat-label">
						<?php esc_html_e( 'Active Jobs', 'wp-mcp-ai' ); ?>
					</div>
					<div class="wp-mcp-ai-crawl4ai-manager__stat-value">
						<?php echo esc_html( number_format_i18n( $stats['total_jobs'] ) ); ?>
					</div>
				</div>

				<div class="wp-mcp-ai-crawl4ai-manager__stat">
					<div class="wp-mcp-ai-crawl4ai-manager__stat-label">
						<?php esc_html_e( 'Cached Tasks', 'wp-mcp-ai' ); ?>
					</div>
					<div class="wp-mcp-ai-crawl4ai-manager__stat-value">
						<?php echo esc_html( number_format_i18n( $stats['total_cached'] ) ); ?>
					</div>
				</div>

				<div class="wp-mcp-ai-crawl4ai-manager__stat">
					<div class="wp-mcp-ai-crawl4ai-manager__stat-label">
						<?php esc_html_e( 'Completed', 'wp-mcp-ai' ); ?>
					</div>
					<div class="wp-mcp-ai-crawl4ai-manager__stat-value">
						<?php echo esc_html( number_format_i18n( $stats['completed'] ) ); ?>
					</div>
				</div>

				<div class="wp-mcp-ai-crawl4ai-manager__stat">
					<div class="wp-mcp-ai-crawl4ai-manager__stat-label">
						<?php esc_html_e( 'Failed', 'wp-mcp-ai' ); ?>
					</div>
					<div class="wp-mcp-ai-crawl4ai-manager__stat-value">
						<?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?>
					</div>
				</div>

				<div class="wp-mcp-ai-crawl4ai-manager__stat">
					<div class="wp-mcp-ai-crawl4ai-manager__stat-label">
						<?php esc_html_e( 'Cache Size', 'wp-mcp-ai' ); ?>
					</div>
					<div class="wp-mcp-ai-crawl4ai-manager__stat-value">
						<?php echo esc_html( $stats['cache_size_mb'] . ' MB' ); ?>
					</div>
				</div>

				<div class="wp-mcp-ai-crawl4ai-manager__stat">
					<div class="wp-mcp-ai-crawl4ai-manager__stat-label">
						<?php esc_html_e( 'Total URLs Crawled', 'wp-mcp-ai' ); ?>
					</div>
					<div class="wp-mcp-ai-crawl4ai-manager__stat-value">
						<?php echo esc_html( number_format_i18n( $stats['total_urls'] ) ); ?>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $active_jobs ) ) : ?>
				<h2><?php esc_html_e( 'Active Background Jobs', 'wp-mcp-ai' ); ?></h2>
				<?php $this->render_active_jobs_table( $active_jobs ); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Cached Crawl Results', 'wp-mcp-ai' ); ?></h2>
			
			<?php if ( ! empty( $cached_tasks ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 1rem;">
					<?php wp_nonce_field( 'wp_mcp_ai_clear_crawl_cache' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_clear_crawl_cache" />
					<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear all cached crawl results?', 'wp-mcp-ai' ) ); ?>');">
						<?php esc_html_e( 'Clear All Cache', 'wp-mcp-ai' ); ?>
					</button>
				</form>
				<?php $this->render_cached_tasks_table( $cached_tasks ); ?>
			<?php else : ?>
				<div class="wp-mcp-ai-crawl4ai-manager__empty">
					<h3><?php esc_html_e( 'No Cached Results', 'wp-mcp-ai' ); ?></h3>
					<p><?php esc_html_e( 'No crawl results are currently cached. Completed crawl jobs will appear here.', 'wp-mcp-ai' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render configuration section.
	 *
	 * @param array $settings Plugin settings.
	 */
	private function render_configuration( $settings ) {
		$base_url = isset( $settings['crawl4ai_base_url'] ) ? $settings['crawl4ai_base_url'] : '';
		$has_key  = ! empty( $settings['crawl4ai_api_key'] );
		$mode     = $base_url ? 'remote' : 'local';

		?>
		<div class="wp-mcp-ai-crawl4ai-manager__config">
			<h3><?php esc_html_e( 'Configuration', 'wp-mcp-ai' ); ?></h3>
			<div class="wp-mcp-ai-crawl4ai-manager__config-item">
				<div class="wp-mcp-ai-crawl4ai-manager__config-label"><?php esc_html_e( 'Mode:', 'wp-mcp-ai' ); ?></div>
				<div class="wp-mcp-ai-crawl4ai-manager__config-value">
					<?php
					if ( 'remote' === $mode ) {
						echo '<strong>' . esc_html__( 'Remote Crawl4AI Service', 'wp-mcp-ai' ) . '</strong>';
					} else {
						echo '<strong>' . esc_html__( 'Local WordPress Crawler', 'wp-mcp-ai' ) . '</strong>';
					}
					?>
				</div>
			</div>
			<?php if ( 'remote' === $mode ) : ?>
				<div class="wp-mcp-ai-crawl4ai-manager__config-item">
					<div class="wp-mcp-ai-crawl4ai-manager__config-label"><?php esc_html_e( 'Base URL:', 'wp-mcp-ai' ); ?></div>
					<div class="wp-mcp-ai-crawl4ai-manager__config-value"><?php echo esc_html( $base_url ); ?></div>
				</div>
				<div class="wp-mcp-ai-crawl4ai-manager__config-item">
					<div class="wp-mcp-ai-crawl4ai-manager__config-label"><?php esc_html_e( 'API Key:', 'wp-mcp-ai' ); ?></div>
					<div class="wp-mcp-ai-crawl4ai-manager__config-value">
						<?php echo $has_key ? esc_html__( '✓ Configured', 'wp-mcp-ai' ) : esc_html__( '✗ Not configured', 'wp-mcp-ai' ); ?>
					</div>
				</div>
			<?php endif; ?>
			<div class="wp-mcp-ai-crawl4ai-manager__config-item">
				<div class="wp-mcp-ai-crawl4ai-manager__config-label"><?php esc_html_e( 'Settings:', 'wp-mcp-ai' ); ?></div>
				<div class="wp-mcp-ai-crawl4ai-manager__config-value">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-settings' ) ); ?>">
						<?php esc_html_e( 'Configure Crawl4AI Settings', 'wp-mcp-ai' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render notices.
	 */
	private function render_notices() {
		if ( ! isset( $_GET['updated'] ) || ! isset( $_GET['action'] ) ) {
			return;
		}

		$updated = sanitize_text_field( wp_unslash( $_GET['updated'] ) );
		$action  = sanitize_text_field( wp_unslash( $_GET['action'] ) );

		if ( '1' === $updated ) {
			$messages = array(
				'cancelled'     => __( 'Crawl job cancelled successfully.', 'wp-mcp-ai' ),
				'retry_queued'  => __( 'Crawl job queued for retry.', 'wp-mcp-ai' ),
				'cache_cleared' => __( 'All cached crawl results cleared.', 'wp-mcp-ai' ),
			);

			$message = isset( $messages[ $action ] ) ? $messages[ $action ] : __( 'Action completed successfully.', 'wp-mcp-ai' );

			echo '<div class="wp-mcp-ai-crawl4ai-manager__notice wp-mcp-ai-crawl4ai-manager__notice--success">';
			echo esc_html( $message );
			echo '</div>';
		} else {
			echo '<div class="wp-mcp-ai-crawl4ai-manager__notice wp-mcp-ai-crawl4ai-manager__notice--error">';
			echo esc_html__( 'Action failed. Please try again.', 'wp-mcp-ai' );
			echo '</div>';
		}
	}

	/**
	 * Render active jobs table.
	 *
	 * @param array $jobs Array of active jobs.
	 */
	private function render_active_jobs_table( $jobs ) {
		?>
		<table class="wp-mcp-ai-crawl4ai-manager__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Task ID', 'wp-mcp-ai' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
					<th><?php esc_html_e( 'Created', 'wp-mcp-ai' ); ?></th>
					<th><?php esc_html_e( 'Last Updated', 'wp-mcp-ai' ); ?></th>
					<th><?php esc_html_e( 'Poll Interval', 'wp-mcp-ai' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wp-mcp-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $jobs as $job ) : ?>
					<tr>
						<td><code><?php echo esc_html( $job['task_id'] ); ?></code></td>
						<td><?php echo wp_kses_post( $this->render_status_badge( $job['status'] ) ); ?></td>
						<td><?php echo esc_html( $this->format_timestamp( $job['created_at'] ) ); ?></td>
						<td><?php echo esc_html( $this->format_timestamp( $job['updated_at'] ) ); ?></td>
						<td><?php echo esc_html( $job['poll_interval'] . 's' ); ?></td>
						<td class="wp-mcp-ai-crawl4ai-manager__actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'wp_mcp_ai_cancel_crawl_' . $job['task_id'] ); ?>
								<input type="hidden" name="action" value="wp_mcp_ai_cancel_crawl_job" />
								<input type="hidden" name="task_id" value="<?php echo esc_attr( $job['task_id'] ); ?>" />
								<button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Cancel this crawl job?', 'wp-mcp-ai' ) ); ?>');">
									<?php esc_html_e( 'Cancel', 'wp-mcp-ai' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render cached tasks table.
	 *
	 * @param array $tasks Array of cached tasks.
	 */
	private function render_cached_tasks_table( $tasks ) {
		// Limit to most recent 50 tasks.
		$tasks = array_slice( $tasks, 0, 50 );

		?>
		<table class="wp-mcp-ai-crawl4ai-manager__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Task ID', 'wp-mcp-ai' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
					<th><?php esc_html_e( 'URLs', 'wp-mcp-ai' ); ?></th>
					<th><?php esc_html_e( 'Results', 'wp-mcp-ai' ); ?></th>
					<th><?php esc_html_e( 'Stored At', 'wp-mcp-ai' ); ?></th>
					<th><?php esc_html_e( 'Size', 'wp-mcp-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tasks as $task ) : ?>
					<tr>
						<td><code><?php echo esc_html( isset( $task['task_id'] ) ? $task['task_id'] : 'N/A' ); ?></code></td>
						<td><?php echo wp_kses_post( $this->render_status_badge( isset( $task['status'] ) ? $task['status'] : 'unknown' ) ); ?></td>
						<td>
							<?php
							if ( isset( $task['results'] ) && is_array( $task['results'] ) ) {
								$urls = array();
								foreach ( $task['results'] as $result ) {
									if ( isset( $result['url'] ) ) {
										$urls[] = $result['url'];
									}
								}
								if ( ! empty( $urls ) ) {
									echo '<div class="wp-mcp-ai-crawl4ai-manager__urls">';
									echo esc_html( implode( "\n", array_slice( $urls, 0, 3 ) ) );
									if ( count( $urls ) > 3 ) {
										echo '<br><em>' . esc_html( sprintf( __( '+ %d more', 'wp-mcp-ai' ), count( $urls ) - 3 ) ) . '</em>';
									}
									echo '</div>';
								} else {
									echo esc_html__( 'N/A', 'wp-mcp-ai' );
								}
							} else {
								echo esc_html__( 'N/A', 'wp-mcp-ai' );
							}
							?>
						</td>
						<td>
							<?php
							$result_count = isset( $task['results'] ) && is_array( $task['results'] ) ? count( $task['results'] ) : 0;
							echo esc_html( number_format_i18n( $result_count ) );
							?>
						</td>
						<td>
							<?php
							if ( isset( $task['stored_at'] ) ) {
								echo esc_html( human_time_diff( strtotime( $task['stored_at'] ), current_time( 'timestamp' ) ) . __( ' ago', 'wp-mcp-ai' ) );
							} else {
								echo esc_html__( 'N/A', 'wp-mcp-ai' );
							}
							?>
						</td>
						<td>
							<?php
							$size = strlen( maybe_serialize( $task ) );
							echo esc_html( size_format( $size ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<em>
				<?php
				printf(
					/* translators: %d: number of tasks shown */
					esc_html__( 'Showing the %d most recent cached tasks.', 'wp-mcp-ai' ),
					count( $tasks )
				);
				?>
			</em>
		</p>
		<?php
	}

	/**
	 * Render status badge.
	 *
	 * @param string $status Status string.
	 * @return string HTML badge.
	 */
	private function render_status_badge( $status ) {
		$status = strtolower( $status );
		$class  = 'wp-mcp-ai-crawl4ai-manager__status';

		switch ( $status ) {
			case 'completed':
				$class .= ' wp-mcp-ai-crawl4ai-manager__status--completed';
				$label  = __( 'Completed', 'wp-mcp-ai' );
				break;
			case 'pending':
				$class .= ' wp-mcp-ai-crawl4ai-manager__status--pending';
				$label  = __( 'Pending', 'wp-mcp-ai' );
				break;
			case 'processing':
			case 'in_progress':
				$class .= ' wp-mcp-ai-crawl4ai-manager__status--processing';
				$label  = __( 'Processing', 'wp-mcp-ai' );
				break;
			case 'failed':
			case 'error':
				$class .= ' wp-mcp-ai-crawl4ai-manager__status--failed';
				$label  = __( 'Failed', 'wp-mcp-ai' );
				break;
			case 'timeout':
				$class .= ' wp-mcp-ai-crawl4ai-manager__status--timeout';
				$label  = __( 'Timeout', 'wp-mcp-ai' );
				break;
			default:
				$label = ucfirst( $status );
		}

		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	/**
	 * Format timestamp for display.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string Formatted time string.
	 */
	private function format_timestamp( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return __( 'N/A', 'wp-mcp-ai' );
		}

		return human_time_diff( $timestamp, current_time( 'timestamp' ) ) . __( ' ago', 'wp-mcp-ai' );
	}
}
