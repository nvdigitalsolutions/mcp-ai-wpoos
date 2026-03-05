<?php
/**
 * Admin cron manager for NV oOS.
 *
 * This class provides a server-side UI for managing cron jobs. It does not use
 * AJAX for delete operations or data refresh - instead it follows WordPress
 * conventions using admin_post_ actions and page redirects.
 *
 * If AJAX functionality is needed in the future, it should integrate with
 * WP_MCP_AI_Admin_AJAX_Handlers following the existing pattern in the codebase.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
}

/**
 * Renders the management UI for cron events scheduled via NV oOS.
 */
class WP_MCP_AI_Admin_Cron_Manager {
	const PAGE_SLUG = 'wp-mcp-ai-cron-manager';

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
		add_action( 'admin_post_wp_mcp_ai_delete_cron', array( $this, 'handle_delete_cron' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_cron_manager_stats', array( $this, 'ajax_get_stats' ) );
	}

	/**
	 * Register the cron manager page under the NV oOS menu.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'NV oOS Cron Manager', 'mcp-ai-wpoos' ),
			__( 'Cron Manager', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue lightweight styles for the cron table.
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

		$inline_css = '.wp-mcp-ai-cron-manager__intro{margin:1.5rem 0;padding:1rem;background:#f0f6fc;border-left:4px solid #2271b1;}'
			. '.wp-mcp-ai-cron-manager__intro p{margin:0.5rem 0;}'
			. '.wp-mcp-ai-cron-manager__intro p:first-child{margin-top:0;}'
			. '.wp-mcp-ai-cron-manager__intro p:last-child{margin-bottom:0;}'
			. '.wp-mcp-ai-cron-manager__stats{display:flex;gap:1.5rem;margin:1.5rem 0;}'
			. '.wp-mcp-ai-cron-manager__stat{padding:1rem;background:#fff;border:1px solid #dcdcde;border-radius:4px;flex:1;}'
			. '.wp-mcp-ai-cron-manager__stat-label{font-size:0.875rem;color:#646970;margin-bottom:0.25rem;}'
			. '.wp-mcp-ai-cron-manager__stat-value{font-size:1.75rem;font-weight:600;color:#1d2327;}'
			. '.wp-mcp-ai-cron-manager__table{margin-top:1.5rem;border-collapse:collapse;width:100%;}'
			. '.wp-mcp-ai-cron-manager__table th,.wp-mcp-ai-cron-manager__table td{border:1px solid #dcdcde;padding:0.75rem;text-align:left;vertical-align:top;}'
			. '.wp-mcp-ai-cron-manager__table th{background:#f8f9ff;font-weight:600;}'
			. '.wp-mcp-ai-cron-manager__empty{margin-top:1.5rem;padding:1.5rem;border:1px solid #dcdcde;background:#fff;border-radius:4px;}'
			. '.wp-mcp-ai-cron-manager__empty h3{margin-top:0;}'
			. '.wp-mcp-ai-cron-manager__empty ul{margin-left:1.5rem;}'
			. '.wp-mcp-ai-cron-manager__actions form{display:inline-block;margin-right:0.5rem;}'
			. '.wp-mcp-ai-cron-manager__args{font-family:monospace;font-size:13px;white-space:pre-wrap;word-break:break-word;}'
			. '.wp-mcp-ai-cron-manager__status{display:inline-block;padding:0.25rem 0.5rem;border-radius:3px;font-size:0.75rem;font-weight:600;}'
			. '.wp-mcp-ai-cron-manager__status--active{background:#d5f0db;color:#0a5f1a;}'
			. '.wp-mcp-ai-cron-manager__status--executed{background:#e0f2ff;color:#0056a0;}'
			. '.wp-mcp-ai-cron-manager__status--inactive{background:#f0f0f1;color:#50575e;}'
			. '.wp-mcp-ai-cron-manager__status--recurring{background:#e5f2ff;color:#0c5ba0;}'
			. '.wp-mcp-ai-cron-manager__status--oneoff{background:#fef7e0;color:#8b6c00;}';

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline style registered with no URL; version not applicable.
		wp_register_style( 'wp-mcp-ai-cron-manager-inline', false );
		wp_enqueue_style( 'wp-mcp-ai-cron-manager-inline' );
		wp_add_inline_style( 'wp-mcp-ai-cron-manager-inline', $inline_css );

		// Enqueue JavaScript for auto-refresh functionality.
		wp_enqueue_script(
			'wp-mcp-ai-admin-cron-manager',
			WP_MCP_AI_URL . 'assets/js/admin-cron-manager.js',
			array( 'jquery' ),
			filemtime( WP_MCP_AI_PATH . 'assets/js/admin-cron-manager.js' ),
			true
		);

		wp_localize_script(
			'wp-mcp-ai-admin-cron-manager',
			'wpMcpAiCronManager',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_cron_manager' ),
				'strings' => array(
					'noJobs' => __( 'No cron events scheduled.', 'mcp-ai-wpoos' ),
				),
			)
		);
	}

	/**
	 * Handle deletion of a cron event from the manager.
	 */
	public function handle_delete_cron() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage cron events.', 'mcp-ai-wpoos' ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		if ( '' === $job_id ) {
			wp_die( esc_html__( 'Missing cron identifier.', 'mcp-ai-wpoos' ) );
		}

		check_admin_referer( 'wp_mcp_ai_delete_cron_' . $job_id );

		$deleted = WP_MCP_AI_Cron_Manager::remove_job( $job_id );

		$redirect = add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'updated' => $deleted ? '1' : '0',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Get statistics for display.
	 *
	 * @param array $jobs Array of cron jobs.
	 * @return array Statistics array.
	 */
	private function get_statistics( $jobs ) {
		$total_jobs    = count( $jobs );
		$active_jobs   = 0;
		$inactive_jobs = 0;
		$recurring     = 0;
		$one_off       = 0;

		foreach ( $jobs as $job ) {
			$event = wp_get_scheduled_event( $job['hook'], $job['args'] );
			if ( $event ) {
				++$active_jobs;
			} else {
				++$inactive_jobs;
			}

			$schedule = isset( $job['schedule'] ) ? $job['schedule'] : 'single';
			if ( 'single' === $schedule || '' === $schedule ) {
				++$one_off;
			} else {
				++$recurring;
			}
		}

		return array(
			'total'     => $total_jobs,
			'active'    => $active_jobs,
			'inactive'  => $inactive_jobs,
			'recurring' => $recurring,
			'one_off'   => $one_off,
		);
	}

	/**
	 * AJAX handler for getting cron manager statistics.
	 *
	 * @return void
	 */
	public function ajax_get_stats() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_cron_manager', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		$jobs      = WP_MCP_AI_Cron_Manager::get_jobs();
		$stats     = $this->get_statistics( $jobs );
		$dlq_stats = null;

		// Get DLQ stats if available.
		if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			$dlq_stats = WP_MCP_AI_Dead_Letter_Queue::get_stats();
		}

		// Format jobs for AJAX response.
		$formatted_jobs = array();
		foreach ( $jobs as $job ) {
			$event           = wp_get_scheduled_event( $job['hook'], $job['args'] );
			$next_run        = $event ? $event->timestamp : false;
			$schedule        = isset( $job['schedule'] ) ? $job['schedule'] : 'single';
			$is_active       = (bool) $event;
			$is_recurring    = ! ( 'single' === $schedule || '' === $schedule );
			$first_timestamp = isset( $job['first_timestamp'] ) ? (int) $job['first_timestamp'] : 0;
			$was_executed    = ! $is_active && $first_timestamp > 0 && $first_timestamp < time();

			$creator    = '';
			$created_by = isset( $job['created_by'] ) ? (int) $job['created_by'] : 0;

			if ( $created_by > 0 ) {
				$user = get_userdata( $created_by );
				if ( $user ) {
					$creator = $user->display_name;
				}
			}

			if ( '' === $creator ) {
				$creator = __( 'System', 'mcp-ai-wpoos' );
			}

			$formatted_jobs[] = array(
				'hook'                 => $job['hook'],
				'args'                 => $job['args'],
				'schedule'             => $schedule,
				'is_active'            => $is_active,
				'is_recurring'         => $is_recurring,
				'was_executed'         => $was_executed,
				'next_run'             => $next_run,
				'next_run_formatted'   => $next_run ? wp_date( 'Y-m-d H:i:s T', $next_run ) : null,
				'creator'              => $creator,
				'created_at_formatted' => isset( $job['created_at'] ) && $job['created_at'] ? wp_date( 'Y-m-d H:i:s T', (int) $job['created_at'] ) : __( 'Unknown', 'mcp-ai-wpoos' ),
				'job_id'               => $job['job_id'],
				'delete_nonce'         => wp_create_nonce( 'wp_mcp_ai_delete_cron_' . $job['job_id'] ),
				'first_timestamp'      => $first_timestamp,
			);
		}

		wp_send_json_success(
			array(
				'stats'     => $stats,
				'jobs'      => $formatted_jobs,
				'dlq_stats' => $dlq_stats,
			)
		);
	}

	/**
	 * Render the cron manager page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		$jobs  = WP_MCP_AI_Cron_Manager::get_jobs();
		$stats = $this->get_statistics( $jobs );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'NV oOS Cron Manager', 'mcp-ai-wpoos' ); ?></h1>

			<div class="wp-mcp-ai-cron-manager__notices"></div>

			<div class="auto-refresh-controls">
				<label for="toggle-auto-refresh">
					<input type="checkbox" id="toggle-auto-refresh" checked />
					<?php esc_html_e( 'Auto-refresh (every 15 seconds)', 'mcp-ai-wpoos' ); ?>
				</label>
				<button type="button" id="refresh-cron-manager" class="button button-secondary">
					<span class="dashicons dashicons-update-alt"></span>
					<?php esc_html_e( 'Refresh Now', 'mcp-ai-wpoos' ); ?>
				</button>
				<span class="last-refresh">
					<?php esc_html_e( 'Last updated:', 'mcp-ai-wpoos' ); ?>
					<strong id="last-refresh-time"><?php echo esc_html( wp_date( 'H:i:s' ) ); ?></strong>
				</span>
			</div>

			<div class="wp-mcp-ai-cron-manager__intro">
				<p><strong><?php esc_html_e( 'About Cron Manager', 'mcp-ai-wpoos' ); ?></strong></p>
				<p><?php esc_html_e( 'The Cron Manager displays and manages scheduled tasks created through NV oOS AI Assistant tools. Cron events allow the assistant to schedule automated tasks to run at specific times or on recurring schedules.', 'mcp-ai-wpoos' ); ?></p>
				<p>
				<?php
					/* translators: %s: retention period in hours */
					$retention_hours = 24; // Default value.
				if ( class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
					$retention_hours = WP_MCP_AI_Settings_Registry::get_setting( 'cron_job_retention_period', 24 );
				}
					$retention_hours = absint( $retention_hours );

				if ( $retention_hours > 0 ) {
					if ( $retention_hours < 24 ) {
						echo esc_html(
							sprintf(
								/* translators: %d: number of hours */
								_n(
									'Test jobs and completed one-time events remain visible for %d hour after execution, then are automatically removed. You can adjust this retention period in Settings → Orchestration Layer.',
									'Test jobs and completed one-time events remain visible for %d hours after execution, then are automatically removed. You can adjust this retention period in Settings → Orchestration Layer.',
									$retention_hours,
									'mcp-ai-wpoos'
								),
								$retention_hours
							)
						);
					} elseif ( $retention_hours >= 24 && $retention_hours < 168 ) {
						$retention_days = floor( $retention_hours / 24 );
						echo esc_html(
							sprintf(
								/* translators: %d: number of days */
								_n(
									'Test jobs and completed one-time events remain visible for %d day after execution, then are automatically removed. You can adjust this retention period in Settings → Orchestration Layer.',
									'Test jobs and completed one-time events remain visible for %d days after execution, then are automatically removed. You can adjust this retention period in Settings → Orchestration Layer.',
									$retention_days,
									'mcp-ai-wpoos'
								),
								$retention_days
							)
						);
					} else {
						$retention_days = floor( $retention_hours / 24 );
						echo esc_html(
							sprintf(
								/* translators: %d: number of days */
								__( 'Test jobs and completed one-time events remain visible for %d days after execution, then are automatically removed. You can adjust this retention period in Settings → Orchestration Layer.', 'mcp-ai-wpoos' ),
								$retention_days
							)
						);
					}
				} else {
					esc_html_e( 'Completed one-time events are removed immediately after execution. You can enable job retention in Settings → Orchestration Layer to keep them visible for testing and verification.', 'mcp-ai-wpoos' );
				}
				?>
				</p>
			</div>

			<?php
			// Display update status message if present in query string.
			// Nonce verification not required as this is a read-only display of status after redirect.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
			if ( isset( $_GET['updated'] ) ) :
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
				$updated = sanitize_key( wp_unslash( $_GET['updated'] ) );
				if ( '1' === $updated ) :
					?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Cron event successfully removed and unscheduled from WordPress Cron.', 'mcp-ai-wpoos' ); ?></p>
					</div>
					<?php
				elseif ( '0' === $updated ) :
					?>
					<div class="notice notice-error is-dismissible">
						<p><?php esc_html_e( 'The cron event could not be removed. It may have already completed and been removed automatically, or it may not exist.', 'mcp-ai-wpoos' ); ?></p>
					</div>
					<?php
				endif;
			endif;
			?>

			<?php if ( ! empty( $jobs ) ) : ?>
				<div class="wp-mcp-ai-cron-manager__stats">
					<div class="wp-mcp-ai-cron-manager__stat">
						<div class="wp-mcp-ai-cron-manager__stat-label"><?php esc_html_e( 'Total Events', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-cron-manager__stat-value" data-stat="total"><?php echo esc_html( $stats['total'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-cron-manager__stat">
						<div class="wp-mcp-ai-cron-manager__stat-label"><?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-cron-manager__stat-value" data-stat="active"><?php echo esc_html( $stats['active'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-cron-manager__stat">
						<div class="wp-mcp-ai-cron-manager__stat-label"><?php esc_html_e( 'Recurring', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-cron-manager__stat-value" data-stat="recurring"><?php echo esc_html( $stats['recurring'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-cron-manager__stat">
						<div class="wp-mcp-ai-cron-manager__stat-label"><?php esc_html_e( 'One-off', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-cron-manager__stat-value" data-stat="one_off"><?php echo esc_html( $stats['one_off'] ); ?></div>
					</div>
				</div>
			<?php endif; ?>

			<?php
			// Show DLQ and SLA statistics if classes are available.
			if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) || class_exists( 'WP_MCP_AI_SLA_Manager' ) ) :
				$this->render_dlq_sla_stats();
			endif;
			?>

			<?php if ( empty( $jobs ) ) : ?>
				<div class="wp-mcp-ai-cron-manager__empty">
					<h3><?php esc_html_e( 'No Scheduled Events', 'mcp-ai-wpoos' ); ?></h3>
					<p><?php esc_html_e( 'No cron events have been scheduled through NV oOS yet. The AI Assistant can create scheduled tasks using the following tools:', 'mcp-ai-wpoos' ); ?></p>
					<ul>
						<li><strong>create_cron_job</strong> - <?php esc_html_e( 'Schedule a new one-time or recurring task', 'mcp-ai-wpoos' ); ?></li>
						<li><strong>list_cron_jobs</strong> - <?php esc_html_e( 'View all scheduled tasks', 'mcp-ai-wpoos' ); ?></li>
						<li><strong>get_cron_job</strong> - <?php esc_html_e( 'Get details about a specific scheduled task', 'mcp-ai-wpoos' ); ?></li>
						<li><strong>delete_cron_job</strong> - <?php esc_html_e( 'Remove a scheduled task', 'mcp-ai-wpoos' ); ?></li>
					</ul>
					<p><?php esc_html_e( 'Once the assistant creates scheduled events, they will appear here immediately for monitoring and management. Test jobs and completed one-time events will remain visible for the configured retention period, allowing you to verify successful execution.', 'mcp-ai-wpoos' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-mcp-ai-cron-manager__table" id="cron-jobs-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Hook', 'mcp-ai-wpoos' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Next Run', 'mcp-ai-wpoos' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Schedule Type', 'mcp-ai-wpoos' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Arguments', 'mcp-ai-wpoos' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created By', 'mcp-ai-wpoos' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created At', 'mcp-ai-wpoos' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $jobs as $job ) : ?>
							<?php
							$event           = wp_get_scheduled_event( $job['hook'], $job['args'] );
							$next_run        = $event ? $event->timestamp : false;
							$schedule        = isset( $job['schedule'] ) ? $job['schedule'] : 'single';
							$is_active       = (bool) $event;
							$is_recurring    = ! ( 'single' === $schedule || '' === $schedule );
							$first_timestamp = isset( $job['first_timestamp'] ) ? (int) $job['first_timestamp'] : 0;

							// Determine if job was executed (not active but timestamp is in the past).
							$was_executed = ! $is_active && $first_timestamp > 0 && $first_timestamp < time();

							$creator    = '';
							$created_by = isset( $job['created_by'] ) ? (int) $job['created_by'] : 0;

							if ( $created_by > 0 ) {
								$user = get_userdata( $created_by );
								if ( $user ) {
									$creator = $user->display_name;
								}
							}

							if ( '' === $creator ) {
								$creator = __( 'System', 'mcp-ai-wpoos' );
							}

							$created_at   = isset( $job['created_at'] ) && $job['created_at'] ? wp_date( 'Y-m-d H:i:s T', (int) $job['created_at'] ) : __( 'Unknown', 'mcp-ai-wpoos' );
							$args_display = wp_json_encode( $job['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
							?>
							<tr>
								<td><code><?php echo esc_html( $job['hook'] ); ?></code></td>
								<td>
									<?php if ( $is_active ) : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--active"><?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?></span>
									<?php elseif ( $was_executed ) : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--executed"><?php esc_html_e( 'Executed', 'mcp-ai-wpoos' ); ?></span>
									<?php else : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--inactive"><?php esc_html_e( 'Inactive', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									if ( $next_run ) {
										$time_diff = human_time_diff( time(), $next_run );
										if ( $next_run > time() ) {
											/* translators: %s: human-readable time difference */
											echo esc_html( sprintf( __( 'In %s', 'mcp-ai-wpoos' ), $time_diff ) );
										} else {
											/* translators: %s: human-readable time difference */
											echo esc_html( sprintf( __( '%s ago', 'mcp-ai-wpoos' ), $time_diff ) );
										}
										echo '<br><small>' . esc_html( wp_date( 'Y-m-d H:i:s T', $next_run ) ) . '</small>';
									} elseif ( $was_executed && $first_timestamp > 0 ) {
										// Show when the job was scheduled to run for executed jobs.
										$time_diff = human_time_diff( $first_timestamp, time() );
										/* translators: %s: human-readable time difference */
										echo esc_html( sprintf( __( 'Ran %s ago', 'mcp-ai-wpoos' ), $time_diff ) );
										echo '<br><small>' . esc_html( wp_date( 'Y-m-d H:i:s T', $first_timestamp ) ) . '</small>';
									} else {
										esc_html_e( 'Not scheduled', 'mcp-ai-wpoos' );
									}
									?>
								</td>
								<td>
									<?php if ( $is_recurring ) : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--recurring"><?php esc_html_e( 'Recurring', 'mcp-ai-wpoos' ); ?></span>
										<br><small><?php echo esc_html( $schedule ); ?></small>
									<?php else : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--oneoff"><?php esc_html_e( 'One-off', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="wp-mcp-ai-cron-manager__args">
									<?php
									if ( empty( $job['args'] ) ) {
										echo '<em>' . esc_html__( 'None', 'mcp-ai-wpoos' ) . '</em>';
									} else {
										echo esc_html( $args_display );
									}
									?>
								</td>
								<td><?php echo esc_html( $creator ); ?></td>
								<td><?php echo esc_html( $created_at ); ?></td>
								<td class="wp-mcp-ai-cron-manager__actions">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this cron event? This action cannot be undone.', 'mcp-ai-wpoos' ) ); ?>');">
										<input type="hidden" name="action" value="wp_mcp_ai_delete_cron" />
										<input type="hidden" name="job_id" value="<?php echo esc_attr( $job['job_id'] ); ?>" />
										<?php wp_nonce_field( 'wp_mcp_ai_delete_cron_' . $job['job_id'] ); ?>
										<?php submit_button( __( 'Delete', 'mcp-ai-wpoos' ), 'delete', '', false ); ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render DLQ and SLA statistics section.
	 */
	private function render_dlq_sla_stats() {
		?>
		<div class="wp-mcp-ai-cron-manager__intro" style="margin-top:2rem;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Job Queue Health', 'mcp-ai-wpoos' ); ?></h2>

			<?php if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) : ?>
				<?php
				$dlq_stats = WP_MCP_AI_Dead_Letter_Queue::get_stats();
				if ( $dlq_stats['total'] > 0 ) :
					?>
					<div style="padding:1rem;background:#fff3cd;border-left:4px solid #ffc107;margin-bottom:1rem;">
						<strong><?php esc_html_e( 'Dead Letter Queue', 'mcp-ai-wpoos' ); ?></strong>
						<p style="margin:0.5rem 0 0 0;">
							<?php
							printf(
								/* translators: 1: total items, 2: active items */
								esc_html__( '%1$d failed items in queue (%2$d active, %3$d dismissed)', 'mcp-ai-wpoos' ),
								(int) $dlq_stats['total'],
								(int) $dlq_stats['active'],
								(int) $dlq_stats['dismissed']
							);
							?>
							<?php if ( ! empty( $dlq_stats['by_type'] ) ) : ?>
								<br>
								<?php
								$type_labels = array(
									'webhook'    => __( 'Webhooks', 'mcp-ai-wpoos' ),
									'cron_job'   => __( 'Cron Jobs', 'mcp-ai-wpoos' ),
									'async_tool' => __( 'Async Tools', 'mcp-ai-wpoos' ),
									'job_queue'  => __( 'Queue Jobs', 'mcp-ai-wpoos' ),
								);
								$type_parts  = array();
								foreach ( $dlq_stats['by_type'] as $type => $count ) {
									$label        = isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : $type;
									$type_parts[] = sprintf( '%s: %d', esc_html( $label ), $count );
								}
								echo esc_html( implode( ', ', $type_parts ) );
								?>
							<?php endif; ?>
						</p>
						<p style="margin:0.5rem 0 0 0;">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dlq-manager' ) ); ?>" class="button button-secondary">
								<?php esc_html_e( 'View Dead Letter Queue →', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					</div>
				<?php else : ?>
					<div style="padding:1rem;background:#d4edda;border-left:4px solid #28a745;margin-bottom:1rem;">
						<strong><?php esc_html_e( 'Dead Letter Queue', 'mcp-ai-wpoos' ); ?></strong>
						<p style="margin:0.5rem 0 0 0;">
							✓ <?php esc_html_e( 'No failed items - all jobs completing successfully', 'mcp-ai-wpoos' ); ?>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( class_exists( 'WP_MCP_AI_SLA_Manager' ) && WP_MCP_AI_SLA_Manager::is_enabled() ) : ?>
				<div style="padding:1rem;background:#e7f3ff;border-left:4px solid #2271b1;margin-bottom:1rem;">
					<strong><?php esc_html_e( 'SLA Prioritization', 'mcp-ai-wpoos' ); ?></strong>
					<p style="margin:0.5rem 0;">
						<?php esc_html_e( 'Jobs are automatically prioritized into tiers based on latency requirements:', 'mcp-ai-wpoos' ); ?>
					</p>
					<?php
					$tiers_info = WP_MCP_AI_SLA_Manager::get_all_tiers_info();
					?>
					<table style="width:100%;margin-top:0.5rem;border-collapse:collapse;">
						<tr style="background:#f0f6fc;">
							<th style="padding:0.5rem;text-align:left;border:1px solid #ddd;"><?php esc_html_e( 'Tier', 'mcp-ai-wpoos' ); ?></th>
							<th style="padding:0.5rem;text-align:left;border:1px solid #ddd;"><?php esc_html_e( 'Priority', 'mcp-ai-wpoos' ); ?></th>
							<th style="padding:0.5rem;text-align:left;border:1px solid #ddd;"><?php esc_html_e( 'SLA Target', 'mcp-ai-wpoos' ); ?></th>
							<th style="padding:0.5rem;text-align:left;border:1px solid #ddd;"><?php esc_html_e( 'Max Concurrent', 'mcp-ai-wpoos' ); ?></th>
						</tr>
						<?php foreach ( $tiers_info as $tier => $info ) : ?>
							<tr>
								<td style="padding:0.5rem;border:1px solid #ddd;">
									<strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $tier ) ) ); ?></strong>
								</td>
								<td style="padding:0.5rem;border:1px solid #ddd;"><?php echo esc_html( $info['priority'] ); ?></td>
								<td style="padding:0.5rem;border:1px solid #ddd;"><?php echo esc_html( $info['sla_target'] ); ?>s</td>
								<td style="padding:0.5rem;border:1px solid #ddd;"><?php echo esc_html( $info['concurrent'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>

					<?php
					// Show tuning recommendations if there are issues.
					$recommendations = WP_MCP_AI_SLA_Manager::get_tuning_recommendations();
					$has_issues      = false;
					foreach ( $recommendations as $rec ) {
						if ( 'ok' !== $rec['status'] ) {
							$has_issues = true;
							break;
						}
					}
					?>

					<?php if ( $has_issues ) : ?>
						<div style="margin-top:1rem;padding:0.75rem;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;">
							<strong><?php esc_html_e( '⚠️ Tuning Recommendations:', 'mcp-ai-wpoos' ); ?></strong>
							<ul style="margin:0.5rem 0 0 1.5rem;padding:0;">
								<?php foreach ( $recommendations as $rec ) : ?>
									<?php if ( 'ok' !== $rec['status'] ) : ?>
										<li>
											<strong><?php echo esc_html( ucfirst( $rec['tier'] ) ); ?>:</strong>
											<?php echo esc_html( $rec['message'] ); ?>
										</li>
									<?php endif; ?>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
