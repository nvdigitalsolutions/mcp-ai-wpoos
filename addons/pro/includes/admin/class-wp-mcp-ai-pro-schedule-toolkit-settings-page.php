<?php
/**
 * Pro Schedule Toolkit Settings Page.
 *
 * Adds a six-tab toolkit-style settings surface for the Pro Scheduler under
 * NV oOS Pro Dashboard → Schedule Settings, mirroring the canonical toolkit
 * settings template (Overview · Configuration · Tools · Research · Help ·
 * MCP Server).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Pro Schedule Toolkit Settings Page Class.
 */
class WP_MCP_AI_Pro_Schedule_Toolkit_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->toolkit_slug = 'pro_schedule';
		$this->toolkit_name = __( 'Pro Scheduler', 'mcp-ai-wpoos-pro' );
		$this->option_name  = 'wp_mcp_ai_pro_schedule_toolkit_settings';
		$this->page_slug    = 'wp-mcp-ai-pro-schedule-toolkit-settings';
		$this->has_research = true;
		$this->icon         = 'dashicons-clock';

		parent::__construct();
	}

	/**
	 * Register the submenu page with a filterable capability.
	 *
	 * Overrides the base method so site administrators can delegate schedule
	 * management to non-`manage_options` roles via the
	 * `wp_mcp_ai_pro_schedule_capability` filter.
	 */
	public function add_settings_page() {
		/**
		 * Filter the capability required to view the Pro Scheduler settings page.
		 *
		 * @since 1.x
		 *
		 * @param string $capability Capability slug. Default 'manage_options'.
		 */
		$capability = (string) apply_filters( 'wp_mcp_ai_pro_schedule_capability', 'manage_options' );
		if ( '' === $capability ) {
			$capability = 'manage_options';
		}

		add_submenu_page(
			$this->parent_slug,
			$this->toolkit_name . ' ' . __( 'Settings', 'mcp-ai-wpoos-pro' ),
			$this->toolkit_name,
			$capability,
			$this->page_slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Toolkit slug accessor.
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Toolkit name accessor.
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Get all schedules from the Pro Schedule Manager.
	 *
	 * @return array Schedules indexed by schedule_id.
	 */
	protected function get_all_schedules() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return array();
		}
		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
		return is_array( $schedules ) ? $schedules : array();
	}

	/**
	 * Render the Overview tab — at-a-glance scheduler dashboard.
	 */
	protected function render_overview_tab() {
		$schedules = $this->get_all_schedules();

		$total    = count( $schedules );
		$enabled  = 0;
		$disabled = 0;
		$upcoming = array();

		$now = time();
		foreach ( $schedules as $sch ) {
			if ( ! empty( $sch['enabled'] ) ) {
				++$enabled;
			} else {
				++$disabled;
			}

			$schedule_id = isset( $sch['id'] ) ? (string) $sch['id'] : '';
			$next        = 0;
			if ( $schedule_id && method_exists( 'WP_MCP_AI_Pro_Schedule_Manager', 'get_next_run_time' ) ) {
				$next = (int) WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $schedule_id );
			}
			if ( $next > 0 && $next >= $now && ! empty( $sch['enabled'] ) ) {
				$upcoming[] = array(
					'id'   => $schedule_id,
					'name' => isset( $sch['name'] ) ? (string) $sch['name'] : '',
					'when' => $next,
				);
			}
		}

		usort(
			$upcoming,
			function ( $a, $b ) {
				return $a['when'] <=> $b['when'];
			}
		);
		$upcoming = array_slice( $upcoming, 0, 5 );

		$manager_url  = admin_url( 'admin.php?page=nvoos-pro-schedule-manager' );
		$research_url = admin_url( 'admin.php?page=research-pro-schedule' );
		$date_fmt     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		?>
		<div class="toolkit-overview">
			<div class="toolkit-card">
				<h2><?php esc_html_e( 'Pro Scheduler Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php esc_html_e( 'Manage recurring tasks, reminders, and AI assistant runs from a single dashboard. Each schedule can fire a plain reminder hook or invoke a configured assistant.', 'mcp-ai-wpoos-pro' ); ?>
				</p>

				<div class="schedule-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px;margin:20px 0;">
					<div class="stat-card" style="background:#f0f6fc;border:1px solid #c5d9ed;padding:15px;border-radius:4px;text-align:center;">
						<div style="font-size:32px;font-weight:600;color:#2271b1;"><?php echo esc_html( (string) $total ); ?></div>
						<div><?php esc_html_e( 'Total Schedules', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
					<div class="stat-card" style="background:#edfaef;border:1px solid #b3d9bc;padding:15px;border-radius:4px;text-align:center;">
						<div style="font-size:32px;font-weight:600;color:#00a32a;"><?php echo esc_html( (string) $enabled ); ?></div>
						<div><?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
					<div class="stat-card" style="background:#fcf0f1;border:1px solid #ddc6c8;padding:15px;border-radius:4px;text-align:center;">
						<div style="font-size:32px;font-weight:600;color:#b32d2e;"><?php echo esc_html( (string) $disabled ); ?></div>
						<div><?php esc_html_e( 'Paused', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<h3><?php esc_html_e( 'Next 5 Upcoming Runs', 'mcp-ai-wpoos-pro' ); ?></h3>
				<?php if ( empty( $upcoming ) ) : ?>
					<p><em><?php esc_html_e( 'No upcoming runs are scheduled.', 'mcp-ai-wpoos-pro' ); ?></em></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Schedule', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Next Run', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $upcoming as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['name'] ); ?></td>
									<td><?php echo esc_html( wp_date( $date_fmt, $row['when'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<h3 style="margin-top:25px;"><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( $manager_url ); ?>" class="button button-primary">
						<span class="dashicons dashicons-admin-generic" style="vertical-align:middle;"></span>
						<?php esc_html_e( 'Open Schedule Manager', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( $research_url ); ?>" class="button">
						<span class="dashicons dashicons-search" style="vertical-align:middle;"></span>
						<?php esc_html_e( 'Research & Add Schedule', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
			<?php $this->render_recent_run_history( $schedules, $date_fmt ); ?>
		</div>
		<?php
	}

	/**
	 * Render the recent-run-history card (last 20 runs across all schedules).
	 *
	 * Reads from `WP_MCP_AI_Pro_Schedule_Manager::get_run_history()` per schedule,
	 * merges, sorts newest first, and surfaces status + duration + last error so
	 * site admins can spot failing schedules from one glance.
	 *
	 * @param array  $schedules Schedules keyed by id.
	 * @param string $date_fmt  Combined site date+time format.
	 */
	protected function render_recent_run_history( array $schedules, $date_fmt ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return;
		}
		if ( ! method_exists( 'WP_MCP_AI_Pro_Schedule_Manager', 'get_run_history' ) ) {
			return;
		}

		$rows = array();
		foreach ( $schedules as $sch ) {
			$schedule_id = isset( $sch['id'] ) ? (string) $sch['id'] : '';
			if ( '' === $schedule_id ) {
				continue;
			}
			$history = WP_MCP_AI_Pro_Schedule_Manager::get_run_history( $schedule_id, 10 );
			if ( ! is_array( $history ) ) {
				continue;
			}
			foreach ( $history as $run ) {
				$rows[] = array(
					'schedule_name' => isset( $sch['name'] ) ? (string) $sch['name'] : $schedule_id,
					'when'          => isset( $run['start_time'] ) ? (int) $run['start_time'] : 0,
					'success'       => isset( $run['status'] ) && 'success' === $run['status'],
					'duration'      => isset( $run['duration'] ) ? (float) $run['duration'] : 0.0,
					'error'         => isset( $run['error'] ) ? (string) $run['error'] : '',
				);
			}
		}

		if ( empty( $rows ) ) {
			return;
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['when'] <=> $a['when'];
			}
		);
		$rows = array_slice( $rows, 0, 20 );
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Recent Run History', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Last 20 runs across all schedules. Failed runs show their last error message.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Schedule', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Last Error', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['when'] > 0 ? wp_date( $date_fmt, $row['when'] ) : '—' ); ?></td>
							<td><?php echo esc_html( $row['schedule_name'] ); ?></td>
							<td>
								<?php if ( $row['success'] ) : ?>
									<span style="color:#00a32a;">●</span> <?php esc_html_e( 'Success', 'mcp-ai-wpoos-pro' ); ?>
								<?php else : ?>
									<span style="color:#b32d2e;">●</span> <?php esc_html_e( 'Failed', 'mcp-ai-wpoos-pro' ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( sprintf( '%.2fs', $row['duration'] ) ); ?></td>
							<td><?php echo esc_html( '' !== $row['error'] ? wp_trim_words( $row['error'], 12, '…' ) : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the Configuration tab — site-wide scheduler defaults.
	 */
	protected function render_configuration_tab() {
		$options      = get_option( $this->option_name, array() );
		$timezone     = wp_timezone_string();
		$cadence_opts = array_merge( array( 'single' ), array_keys( wp_get_schedules() ) );
		$cadence_opts = array_unique( $cadence_opts );
		sort( $cadence_opts );

		$default_cadence = isset( $options['default_cadence'] ) ? (string) $options['default_cadence'] : 'daily';
		$default_time    = isset( $options['default_time'] ) ? (string) $options['default_time'] : '09:00';
		$max_concurrent  = isset( $options['max_concurrent_runs'] ) ? (int) $options['max_concurrent_runs'] : 3;
		$retry_count     = isset( $options['retry_count'] ) ? (int) $options['retry_count'] : 1;
		$retry_backoff   = isset( $options['retry_backoff'] ) ? (string) $options['retry_backoff'] : 'linear';
		$notify_email    = isset( $options['notification_email'] ) ? (string) $options['notification_email'] : get_option( 'admin_email' );
		$kill_switch     = ! empty( $options['kill_switch'] );

		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Scheduler Defaults', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'These defaults apply to schedules created via the Research & Add workflow when an explicit value is not supplied.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Site Time Zone', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<code><?php echo esc_html( $timezone ); ?></code>
						<p class="description">
							<?php
							echo wp_kses_post(
								sprintf(
									/* translators: %s: General Settings URL */
									__( 'Configured in <a href="%s">Settings → General → Timezone</a>.', 'mcp-ai-wpoos-pro' ),
									esc_url( admin_url( 'options-general.php' ) )
								)
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pro_schedule_default_cadence"><?php esc_html_e( 'Default Cadence', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<select id="pro_schedule_default_cadence" name="<?php echo esc_attr( $this->option_name ); ?>[default_cadence]">
							<?php foreach ( $cadence_opts as $cad ) : ?>
								<option value="<?php echo esc_attr( $cad ); ?>" <?php selected( $cad, $default_cadence ); ?>>
									<?php echo esc_html( $cad ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pro_schedule_default_time"><?php esc_html_e( 'Default Time of Day (24h)', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<input type="time" id="pro_schedule_default_time" name="<?php echo esc_attr( $this->option_name ); ?>[default_time]" value="<?php echo esc_attr( $default_time ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pro_schedule_max_concurrent_runs"><?php esc_html_e( 'Max Concurrent Runs', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<input type="number" id="pro_schedule_max_concurrent_runs" name="<?php echo esc_attr( $this->option_name ); ?>[max_concurrent_runs]" value="<?php echo esc_attr( (string) $max_concurrent ); ?>" min="1" max="20" class="small-text" />
						<p class="description"><?php esc_html_e( 'Soft cap on the number of schedules that may run simultaneously. Surfaced as a hint to consumers via the wp_mcp_ai_pro_schedule_max_concurrent_runs filter.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Retry Policy', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<?php esc_html_e( 'Retries:', 'mcp-ai-wpoos-pro' ); ?>
							<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[retry_count]" value="<?php echo esc_attr( (string) $retry_count ); ?>" min="0" max="10" class="small-text" />
						</label>
						<label style="margin-left:15px;">
							<?php esc_html_e( 'Backoff:', 'mcp-ai-wpoos-pro' ); ?>
							<select name="<?php echo esc_attr( $this->option_name ); ?>[retry_backoff]">
								<option value="linear" <?php selected( 'linear', $retry_backoff ); ?>><?php esc_html_e( 'Linear', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="exponential" <?php selected( 'exponential', $retry_backoff ); ?>><?php esc_html_e( 'Exponential', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="constant" <?php selected( 'constant', $retry_backoff ); ?>><?php esc_html_e( 'Constant', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pro_schedule_notification_email"><?php esc_html_e( 'Error Notification Email', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<input type="email" id="pro_schedule_notification_email" name="<?php echo esc_attr( $this->option_name ); ?>[notification_email]" value="<?php echo esc_attr( $notify_email ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Address used when a schedule has no per-schedule notification override.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Kill Switch', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[kill_switch]" value="1" <?php checked( $kill_switch ); ?> />
							<?php esc_html_e( 'Disable all Pro schedule dispatch (no schedules will fire)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Toggle this to pause every Pro schedule at once without editing each one. Re-enable to resume normal operation.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Capability Filter', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<p class="description">
							<?php
							echo wp_kses_post(
								__( 'Pages and tools require the <code>manage_options</code> capability by default. Customize via the <code>wp_mcp_ai_pro_schedule_capability</code> filter.', 'mcp-ai-wpoos-pro' )
							);
							?>
						</p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Get the list of tools that ship with this toolkit.
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'create_pro_schedule'          => __( 'Create Pro Schedule', 'mcp-ai-wpoos-pro' ),
			'update_pro_schedule'          => __( 'Update Pro Schedule', 'mcp-ai-wpoos-pro' ),
			'delete_pro_schedule'          => __( 'Delete Pro Schedule', 'mcp-ai-wpoos-pro' ),
			'list_pro_schedules'           => __( 'List Pro Schedules', 'mcp-ai-wpoos-pro' ),
			'get_schedule_run_history'     => __( 'Get Schedule Run History', 'mcp-ai-wpoos-pro' ),
			'dry_run_pro_schedule'         => __( 'Dry-run Pro Schedule', 'mcp-ai-wpoos-pro' ),
			'plan_schedules_from_workflow' => __( 'Plan Schedules From Workflow', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$sanitized = is_array( $input ) ? $input : array();

		if ( isset( $sanitized['default_cadence'] ) ) {
			$sanitized['default_cadence'] = sanitize_key( $sanitized['default_cadence'] );
		}
		if ( isset( $sanitized['default_time'] ) ) {
			$time = (string) $sanitized['default_time'];
			if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m ) ) {
				$sanitized['default_time'] = sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
			} else {
				$sanitized['default_time'] = '09:00';
			}
		}
		if ( isset( $sanitized['max_concurrent_runs'] ) ) {
			$sanitized['max_concurrent_runs'] = max( 1, min( 20, absint( $sanitized['max_concurrent_runs'] ) ) );
		}
		if ( isset( $sanitized['retry_count'] ) ) {
			$sanitized['retry_count'] = max( 0, min( 10, absint( $sanitized['retry_count'] ) ) );
		}
		if ( isset( $sanitized['retry_backoff'] ) ) {
			$allowed                    = array( 'linear', 'exponential', 'constant' );
			$sanitized['retry_backoff'] = in_array( $sanitized['retry_backoff'], $allowed, true ) ? $sanitized['retry_backoff'] : 'linear';
		}
		if ( isset( $sanitized['notification_email'] ) ) {
			$email                           = sanitize_email( $sanitized['notification_email'] );
			$sanitized['notification_email'] = is_email( $email ) ? $email : '';
		}
		$sanitized['kill_switch']     = ! empty( $sanitized['kill_switch'] );
		$sanitized['enable_research'] = ! empty( $sanitized['enable_research'] );

		if ( isset( $sanitized['research_assistant_id'] ) ) {
			$sanitized['research_assistant_id'] = absint( $sanitized['research_assistant_id'] );
		}

		return $sanitized;
	}

	/**
	 * Override the Research & Add UI to link to the dedicated page.
	 */
	protected function render_research_add_ui() {
		$research_url = admin_url( 'admin.php?page=research-pro-schedule' );
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Research & Add Schedule', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p><?php esc_html_e( 'The Research & Add Schedule page lets you turn a free-form list of recurring responsibilities into managed Pro Schedules. Choose AI Research, Bulk Import, or Review & Run History from the workflow selector.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $research_url ); ?>" class="button button-primary">
					<span class="dashicons dashicons-search" style="vertical-align:middle;"></span>
					<?php esc_html_e( 'Open Research & Add Schedule', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Help tab — cadence cheat-sheet & common patterns.
	 */
	protected function render_help_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Cadence Cheat-Sheet', 'mcp-ai-wpoos-pro' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Cadence', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Approx. Interval', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Typical Use', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><code>hourly</code></td><td><?php esc_html_e( 'Every 60 minutes', 'mcp-ai-wpoos-pro' ); ?></td><td><?php esc_html_e( 'Quick polling, queue health checks', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					<tr><td><code>twicedaily</code></td><td><?php esc_html_e( 'Every 12 hours', 'mcp-ai-wpoos-pro' ); ?></td><td><?php esc_html_e( 'Morning + evening digests', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					<tr><td><code>daily</code></td><td><?php esc_html_e( 'Every 24 hours', 'mcp-ai-wpoos-pro' ); ?></td><td><?php esc_html_e( 'Standup digest, sales summary', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					<tr><td><code>weekly</code></td><td><?php esc_html_e( 'Every 7 days', 'mcp-ai-wpoos-pro' ); ?></td><td><?php esc_html_e( 'Weekly report, team review', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					<tr><td><code>single</code></td><td><?php esc_html_e( 'One-time run', 'mcp-ai-wpoos-pro' ); ?></td><td><?php esc_html_e( 'Reminders for a specific event', 'mcp-ai-wpoos-pro' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Common Patterns', 'mcp-ai-wpoos-pro' ); ?></h2>
			<ul>
				<li><strong><?php esc_html_e( 'Daily Standup Digest:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'cadence = daily, time = 09:00, assistant = team-summary-bot', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Weekly Report:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'cadence = weekly, time = Monday 08:00, tags = report,weekly', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Email Triage:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'cadence = hourly during business hours, plain reminder hook', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Troubleshooting', 'mcp-ai-wpoos-pro' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'If a schedule never runs, check that WP-Cron is enabled and that the kill-switch is off (Configuration tab).', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'If an assistant-driven schedule completes without output, review the run history for the most recent error message.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'For "next-run" drift on long-running sites, configure a real cron job that hits wp-cron.php every 5 minutes.', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p>
				<a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/addons/pro/docs/toolkits/pro-scheduler.md" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Pro Scheduler Toolkit documentation', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}

// Initialize the settings page in admin context.
if ( is_admin() && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	new WP_MCP_AI_Pro_Schedule_Toolkit_Settings_Page();
}
