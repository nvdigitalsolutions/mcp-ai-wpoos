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
	}

	/**
	 * Register the cron manager page under the WP oOS menu.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'NV oOS Cron Manager', 'wp-mcp-ai' ),
			__( 'Cron Manager', 'wp-mcp-ai' ),
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

		wp_register_style( 'wp-mcp-ai-cron-manager-inline', false );
		wp_enqueue_style( 'wp-mcp-ai-cron-manager-inline' );
		wp_add_inline_style( 'wp-mcp-ai-cron-manager-inline', $inline_css );
	}

	/**
	 * Handle deletion of a cron event from the manager.
	 */
	public function handle_delete_cron() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage cron events.', 'wp-mcp-ai' ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		if ( '' === $job_id ) {
			wp_die( esc_html__( 'Missing cron identifier.', 'wp-mcp-ai' ) );
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
			<h1><?php esc_html_e( 'NV oOS Cron Manager', 'wp-mcp-ai' ); ?></h1>

			<div class="wp-mcp-ai-cron-manager__intro">
				<p><strong><?php esc_html_e( 'About Cron Manager', 'wp-mcp-ai' ); ?></strong></p>
				<p><?php esc_html_e( 'The Cron Manager displays and manages scheduled tasks created through NV oOS AI Assistant tools. Cron events allow the assistant to schedule automated tasks to run at specific times or on recurring schedules.', 'wp-mcp-ai' ); ?></p>
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
									'wp-mcp-ai'
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
									'wp-mcp-ai'
								),
								$retention_days
							)
						);
					} else {
						$retention_days = floor( $retention_hours / 24 );
						echo esc_html(
							sprintf(
								/* translators: %d: number of days */
								__( 'Test jobs and completed one-time events remain visible for %d days after execution, then are automatically removed. You can adjust this retention period in Settings → Orchestration Layer.', 'wp-mcp-ai' ),
								$retention_days
							)
						);
					}
				} else {
					esc_html_e( 'Completed one-time events are removed immediately after execution. You can enable job retention in Settings → Orchestration Layer to keep them visible for testing and verification.', 'wp-mcp-ai' );
				}
				?>
				</p>
			</div>

			<?php
			// Display update status message if present in query string.
			// Nonce verification not required as this is a read-only display of status after redirect.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
			if ( isset( $_GET['updated'] ) ) :
				$updated = sanitize_key( wp_unslash( $_GET['updated'] ) );
				if ( '1' === $updated ) :
					?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Cron event successfully removed and unscheduled from WordPress Cron.', 'wp-mcp-ai' ); ?></p>
					</div>
					<?php
				elseif ( '0' === $updated ) :
					?>
					<div class="notice notice-error is-dismissible">
						<p><?php esc_html_e( 'The cron event could not be removed. It may have already completed and been removed automatically, or it may not exist.', 'wp-mcp-ai' ); ?></p>
					</div>
					<?php
				endif;
			endif;
			?>

			<?php if ( ! empty( $jobs ) ) : ?>
				<div class="wp-mcp-ai-cron-manager__stats">
					<div class="wp-mcp-ai-cron-manager__stat">
						<div class="wp-mcp-ai-cron-manager__stat-label"><?php esc_html_e( 'Total Events', 'wp-mcp-ai' ); ?></div>
						<div class="wp-mcp-ai-cron-manager__stat-value"><?php echo esc_html( $stats['total'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-cron-manager__stat">
						<div class="wp-mcp-ai-cron-manager__stat-label"><?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></div>
						<div class="wp-mcp-ai-cron-manager__stat-value"><?php echo esc_html( $stats['active'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-cron-manager__stat">
						<div class="wp-mcp-ai-cron-manager__stat-label"><?php esc_html_e( 'Recurring', 'wp-mcp-ai' ); ?></div>
						<div class="wp-mcp-ai-cron-manager__stat-value"><?php echo esc_html( $stats['recurring'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-cron-manager__stat">
						<div class="wp-mcp-ai-cron-manager__stat-label"><?php esc_html_e( 'One-off', 'wp-mcp-ai' ); ?></div>
						<div class="wp-mcp-ai-cron-manager__stat-value"><?php echo esc_html( $stats['one_off'] ); ?></div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( empty( $jobs ) ) : ?>
				<div class="wp-mcp-ai-cron-manager__empty">
					<h3><?php esc_html_e( 'No Scheduled Events', 'wp-mcp-ai' ); ?></h3>
					<p><?php esc_html_e( 'No cron events have been scheduled through NV oOS yet. The AI Assistant can create scheduled tasks using the following tools:', 'wp-mcp-ai' ); ?></p>
					<ul>
						<li><strong>create_cron_job</strong> - <?php esc_html_e( 'Schedule a new one-time or recurring task', 'wp-mcp-ai' ); ?></li>
						<li><strong>list_cron_jobs</strong> - <?php esc_html_e( 'View all scheduled tasks', 'wp-mcp-ai' ); ?></li>
						<li><strong>get_cron_job</strong> - <?php esc_html_e( 'Get details about a specific scheduled task', 'wp-mcp-ai' ); ?></li>
						<li><strong>delete_cron_job</strong> - <?php esc_html_e( 'Remove a scheduled task', 'wp-mcp-ai' ); ?></li>
					</ul>
					<p><?php esc_html_e( 'Once the assistant creates scheduled events, they will appear here immediately for monitoring and management. Test jobs and completed one-time events will remain visible for the configured retention period, allowing you to verify successful execution.', 'wp-mcp-ai' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-mcp-ai-cron-manager__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Hook', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Next Run', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Schedule Type', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Arguments', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created By', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created At', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'wp-mcp-ai' ); ?></th>
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
								$creator = __( 'System', 'wp-mcp-ai' );
							}

							$created_at   = isset( $job['created_at'] ) && $job['created_at'] ? wp_date( 'Y-m-d H:i:s T', (int) $job['created_at'] ) : __( 'Unknown', 'wp-mcp-ai' );
							$args_display = wp_json_encode( $job['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
							?>
							<tr>
								<td><code><?php echo esc_html( $job['hook'] ); ?></code></td>
								<td>
									<?php if ( $is_active ) : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--active"><?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></span>
									<?php elseif ( $was_executed ) : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--executed"><?php esc_html_e( 'Executed', 'wp-mcp-ai' ); ?></span>
									<?php else : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--inactive"><?php esc_html_e( 'Inactive', 'wp-mcp-ai' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									if ( $next_run ) {
										$time_diff = human_time_diff( time(), $next_run );
										if ( $next_run > time() ) {
											/* translators: %s: human-readable time difference */
											echo esc_html( sprintf( __( 'In %s', 'wp-mcp-ai' ), $time_diff ) );
										} else {
											/* translators: %s: human-readable time difference */
											echo esc_html( sprintf( __( '%s ago', 'wp-mcp-ai' ), $time_diff ) );
										}
										echo '<br><small>' . esc_html( wp_date( 'Y-m-d H:i:s T', $next_run ) ) . '</small>';
									} elseif ( $was_executed && $first_timestamp > 0 ) {
										// Show when the job was scheduled to run for executed jobs.
										$time_diff = human_time_diff( $first_timestamp, time() );
										/* translators: %s: human-readable time difference */
										echo esc_html( sprintf( __( 'Ran %s ago', 'wp-mcp-ai' ), $time_diff ) );
										echo '<br><small>' . esc_html( wp_date( 'Y-m-d H:i:s T', $first_timestamp ) ) . '</small>';
									} else {
										esc_html_e( 'Not scheduled', 'wp-mcp-ai' );
									}
									?>
								</td>
								<td>
									<?php if ( $is_recurring ) : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--recurring"><?php esc_html_e( 'Recurring', 'wp-mcp-ai' ); ?></span>
										<br><small><?php echo esc_html( $schedule ); ?></small>
									<?php else : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--oneoff"><?php esc_html_e( 'One-off', 'wp-mcp-ai' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="wp-mcp-ai-cron-manager__args">
									<?php
									if ( empty( $job['args'] ) ) {
										echo '<em>' . esc_html__( 'None', 'wp-mcp-ai' ) . '</em>';
									} else {
										echo esc_html( $args_display );
									}
									?>
								</td>
								<td><?php echo esc_html( $creator ); ?></td>
								<td><?php echo esc_html( $created_at ); ?></td>
								<td class="wp-mcp-ai-cron-manager__actions">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this cron event? This action cannot be undone.', 'wp-mcp-ai' ) ); ?>');">
										<input type="hidden" name="action" value="wp_mcp_ai_delete_cron" />
										<input type="hidden" name="job_id" value="<?php echo esc_attr( $job['job_id'] ); ?>" />
										<?php wp_nonce_field( 'wp_mcp_ai_delete_cron_' . $job['job_id'] ); ?>
										<?php submit_button( __( 'Delete', 'wp-mcp-ai' ), 'delete', '', false ); ?>
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
}
