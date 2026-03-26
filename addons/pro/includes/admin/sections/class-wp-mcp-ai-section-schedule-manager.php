<?php
/**
 * Pro Schedule Manager Admin Section.
 *
 * Provides a full management UI for pro-level scheduled tasks, workflows,
 * and assistant runs within the NV oOS settings dashboard.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedule Manager admin settings section.
 */
class WP_MCP_AI_Section_Schedule_Manager extends WP_MCP_AI_Settings_Section {

	/**
	 * Admin nonce action for schedule manager.
	 */
	const NONCE_ACTION = 'wp_mcp_ai_pro_schedule_manager';

	/**
	 * Initialize hooks.
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		// AJAX handlers.
		add_action( 'wp_ajax_wp_mcp_ai_sm_get_schedules',      array( $this, 'ajax_get_schedules' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_create_schedule',    array( $this, 'ajax_create_schedule' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_update_schedule',    array( $this, 'ajax_update_schedule' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_delete_schedule',    array( $this, 'ajax_delete_schedule' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_toggle_schedule',    array( $this, 'ajax_toggle_schedule' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_trigger_schedule',   array( $this, 'ajax_trigger_schedule' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_get_history',        array( $this, 'ajax_get_history' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_clear_history',      array( $this, 'ajax_clear_history' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_export_history_csv', array( $this, 'ajax_export_history_csv' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_export_ical',        array( $this, 'ajax_export_ical' ) );
	}

	// -------------------------------------------------------------------------
	// Section identity
	// -------------------------------------------------------------------------

	/**
	 * Get section ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'schedule_manager';
	}

	/**
	 * Get section title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Pro Schedule Manager', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get parent tab.
	 *
	 * @return string
	 */
	public function get_tab() {
		return 'orchestration';
	}

	/**
	 * Get section description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create and manage scheduled tasks, multi-step workflows, and AI assistant runs with retry logic, failure notifications, and full execution history.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get section priority (after core orchestration).
	 *
	 * @return int
	 */
	public function get_priority() {
		return 30;
	}

	/**
	 * Get field definitions (no static settings; all managed via AJAX).
	 *
	 * @return array
	 */
	public function get_fields() {
		return array();
	}

	// -------------------------------------------------------------------------
	// Asset loading
	// -------------------------------------------------------------------------

	/**
	 * Enqueue JavaScript and CSS on the settings dashboard.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$is_dashboard = ( false !== strpos( $hook, 'wp-mcp-ai-dashboard' ) )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page slug for script enqueue only.
			|| ( isset( $_GET['page'] ) && 'wp-mcp-ai-dashboard' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) );

		if ( ! $is_dashboard ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking tab for script enqueue only.
		$is_tab = ! isset( $_GET['tab'] ) || 'orchestration' === sanitize_text_field( wp_unslash( $_GET['tab'] ) );
		if ( ! $is_tab ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-schedule-manager',
			WP_MCP_AI_PRO_URL . 'assets/css/schedule-manager.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// chart.js — used for the run-history sparkline in the history modal.
		if ( class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
			WP_MCP_AI_Chart_JS_Helper::enqueue_chart_js();
		} elseif ( file_exists( WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js' ) ) {
			wp_enqueue_script(
				'chartjs',
				WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js',
				array(),
				filemtime( WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js' ),
				true
			);
		}

		wp_enqueue_script(
			'wp-mcp-ai-schedule-manager',
			WP_MCP_AI_PRO_URL . 'assets/js/schedule-manager.js',
			array( 'jquery', 'wp-util', 'chartjs' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		$cron_schedules     = wp_get_schedules();
		$schedule_options   = array(
			'single' => __( 'Once (single)', 'mcp-ai-wpoos-pro' ),
		);
		foreach ( $cron_schedules as $key => $cron_schedule ) {
			$schedule_options[ $key ] = $cron_schedule['display'];
		}

		wp_localize_script(
			'wp-mcp-ai-schedule-manager',
			'wpMcpAiScheduleManager',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
				'scheduleOptions' => $schedule_options,
				'strings'         => array(
					'confirmDelete'   => __( 'Are you sure you want to delete this schedule and all its history?', 'mcp-ai-wpoos-pro' ),
					'confirmClear'    => __( 'Are you sure you want to clear the run history for this schedule?', 'mcp-ai-wpoos-pro' ),
					'confirmTrigger'  => __( 'Run this schedule now?', 'mcp-ai-wpoos-pro' ),
					'loading'         => __( 'Loading…', 'mcp-ai-wpoos-pro' ),
					'saving'          => __( 'Saving…', 'mcp-ai-wpoos-pro' ),
					'saved'           => __( 'Saved.', 'mcp-ai-wpoos-pro' ),
					'deleted'         => __( 'Deleted.', 'mcp-ai-wpoos-pro' ),
					'triggered'       => __( 'Schedule triggered successfully.', 'mcp-ai-wpoos-pro' ),
					'error'           => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'noSchedules'     => __( 'No schedules yet. Create one below.', 'mcp-ai-wpoos-pro' ),
					'noHistory'       => __( 'No run history for this schedule.', 'mcp-ai-wpoos-pro' ),
					'typeTask'        => __( 'Task', 'mcp-ai-wpoos-pro' ),
					'typeWorkflow'    => __( 'Workflow', 'mcp-ai-wpoos-pro' ),
					'typeAssistant'   => __( 'Assistant Run', 'mcp-ai-wpoos-pro' ),
					'typeBroadcast'   => __( 'Channel Broadcast', 'mcp-ai-wpoos-pro' ),
					'statusNever'     => __( 'Never run', 'mcp-ai-wpoos-pro' ),
					'statusSuccess'   => __( 'Success', 'mcp-ai-wpoos-pro' ),
					'statusFailure'   => __( 'Failed', 'mcp-ai-wpoos-pro' ),
					'statusPending'   => __( 'Pending', 'mcp-ai-wpoos-pro' ),
					'enabled'         => __( 'Enabled', 'mcp-ai-wpoos-pro' ),
					'disabled'        => __( 'Disabled', 'mcp-ai-wpoos-pro' ),
					'addStep'         => __( '+ Add Step', 'mcp-ai-wpoos-pro' ),
					'removeStep'      => __( 'Remove', 'mcp-ai-wpoos-pro' ),
					'exportCsv'       => __( 'Export CSV', 'mcp-ai-wpoos-pro' ),
					'exportIcal'      => __( 'Export to Calendar (.ics)', 'mcp-ai-wpoos-pro' ),
					'exportIcalTitle' => __( 'Download all enabled schedules as an iCalendar file', 'mcp-ai-wpoos-pro' ),
					'chartSuccess'    => __( 'Success', 'mcp-ai-wpoos-pro' ),
					'chartFailure'    => __( 'Failure', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the section.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to manage schedules.', 'mcp-ai-wpoos-pro' ) . '</p>';
			return;
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';
		?>
		<div class="wp-mcp-ai-schedule-manager" id="wp-mcp-ai-schedule-manager">

			<?php $this->render_schedule_list(); ?>
			<?php $this->render_create_form(); ?>

		</div><!-- .wp-mcp-ai-schedule-manager -->
		<?php
	}

	/**
	 * Render the schedule list table.
	 */
	protected function render_schedule_list() {
		?>
		<div class="wp-mcp-ai-sm-list-section">
			<div class="wp-mcp-ai-sm-list-header">
				<h3><?php esc_html_e( 'Scheduled Jobs', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="wp-mcp-ai-sm-list-filters">
					<select id="wp-mcp-ai-sm-filter-type" class="wp-mcp-ai-sm-filter">
						<option value=""><?php esc_html_e( 'All Types', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="task"><?php esc_html_e( 'Task', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="workflow"><?php esc_html_e( 'Workflow', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="assistant_run"><?php esc_html_e( 'Assistant Run', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="channel_broadcast"><?php esc_html_e( 'Channel Broadcast', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
					<select id="wp-mcp-ai-sm-filter-status" class="wp-mcp-ai-sm-filter">
						<option value=""><?php esc_html_e( 'All Statuses', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="enabled"><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="disabled"><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
					<button type="button" class="button" id="wp-mcp-ai-sm-refresh">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<a href="#" class="button" id="wp-mcp-ai-sm-export-ical" title="<?php esc_attr_e( 'Download all enabled schedules as an iCalendar file', 'mcp-ai-wpoos-pro' ); ?>">
						<span class="dashicons dashicons-calendar-alt"></span>
						<?php esc_html_e( 'Export to Calendar (.ics)', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</div>
			</div>

			<div id="wp-mcp-ai-sm-table-wrap">
				<table class="wp-list-table widefat fixed striped wp-mcp-ai-sm-table" id="wp-mcp-ai-sm-table">
					<thead>
						<tr>
							<th scope="col" class="column-name"><?php esc_html_e( 'Name', 'mcp-ai-wpoos-pro' ); ?></th>
							<th scope="col" class="column-type"><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
							<th scope="col" class="column-schedule"><?php esc_html_e( 'Interval', 'mcp-ai-wpoos-pro' ); ?></th>
							<th scope="col" class="column-next-run"><?php esc_html_e( 'Next Run', 'mcp-ai-wpoos-pro' ); ?></th>
							<th scope="col" class="column-last-status"><?php esc_html_e( 'Last Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<th scope="col" class="column-runs"><?php esc_html_e( 'Runs', 'mcp-ai-wpoos-pro' ); ?></th>
							<th scope="col" class="column-enabled"><?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></th>
							<th scope="col" class="column-actions"><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody id="wp-mcp-ai-sm-tbody">
						<tr class="wp-mcp-ai-sm-loading-row">
							<td colspan="8">
								<span class="spinner is-active"></span>
								<?php esc_html_e( 'Loading schedules…', 'mcp-ai-wpoos-pro' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Run History Modal -->
			<div id="wp-mcp-ai-sm-history-modal" class="wp-mcp-ai-sm-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wp-mcp-ai-sm-history-modal-title">
				<div class="wp-mcp-ai-sm-modal-backdrop"></div>
				<div class="wp-mcp-ai-sm-modal-content">
					<div class="wp-mcp-ai-sm-modal-header">
						<h4 id="wp-mcp-ai-sm-history-modal-title"><?php esc_html_e( 'Run History', 'mcp-ai-wpoos-pro' ); ?></h4>
						<button type="button" class="wp-mcp-ai-sm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'mcp-ai-wpoos-pro' ); ?>">&times;</button>
					</div>
					<div class="wp-mcp-ai-sm-history-chart-wrap" style="padding:12px 16px 0;display:none;">
						<canvas id="wp-mcp-ai-sm-history-chart" height="60" aria-label="<?php esc_attr_e( 'Run history chart', 'mcp-ai-wpoos-pro' ); ?>" role="img"></canvas>
					</div>
					<div class="wp-mcp-ai-sm-modal-body" id="wp-mcp-ai-sm-history-body">
						<span class="spinner is-active"></span>
					</div>
					<div class="wp-mcp-ai-sm-modal-footer">
						<button type="button" class="button button-link-delete" id="wp-mcp-ai-sm-clear-history-btn">
							<?php esc_html_e( 'Clear History', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<button type="button" class="button" id="wp-mcp-ai-sm-export-csv-btn">
							<span class="dashicons dashicons-download" style="margin-top:3px;"></span>
							<?php esc_html_e( 'Export CSV', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<button type="button" class="button" id="wp-mcp-ai-sm-history-modal-close-btn">
							<?php esc_html_e( 'Close', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Edit Schedule Modal -->
			<div id="wp-mcp-ai-sm-edit-modal" class="wp-mcp-ai-sm-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wp-mcp-ai-sm-edit-modal-title">
				<div class="wp-mcp-ai-sm-modal-backdrop"></div>
				<div class="wp-mcp-ai-sm-modal-content wp-mcp-ai-sm-modal-wide">
					<div class="wp-mcp-ai-sm-modal-header">
						<h4 id="wp-mcp-ai-sm-edit-modal-title"><?php esc_html_e( 'Edit Schedule', 'mcp-ai-wpoos-pro' ); ?></h4>
						<button type="button" class="wp-mcp-ai-sm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'mcp-ai-wpoos-pro' ); ?>">&times;</button>
					</div>
					<div class="wp-mcp-ai-sm-modal-body" id="wp-mcp-ai-sm-edit-body">
						<!-- Populated via JS -->
					</div>
					<div class="wp-mcp-ai-sm-modal-footer">
						<button type="button" class="button button-primary" id="wp-mcp-ai-sm-edit-save-btn">
							<?php esc_html_e( 'Save Changes', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<button type="button" class="button" id="wp-mcp-ai-sm-edit-cancel-btn">
							<?php esc_html_e( 'Cancel', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div><!-- .wp-mcp-ai-sm-list-section -->
		<?php
	}

	/**
	 * Render the create schedule form.
	 */
	protected function render_create_form() {
		?>
		<div class="wp-mcp-ai-sm-create-section">
			<h3 class="wp-mcp-ai-sm-create-toggle" role="button" tabindex="0" aria-expanded="false" aria-controls="wp-mcp-ai-sm-create-form">
				<span class="dashicons dashicons-plus-alt2"></span>
				<?php esc_html_e( 'Create New Schedule', 'mcp-ai-wpoos-pro' ); ?>
			</h3>

			<div id="wp-mcp-ai-sm-create-form" class="wp-mcp-ai-sm-create-form" style="display:none;">
				<div class="wp-mcp-ai-sm-form-grid">

					<!-- Row: Name + Type -->
					<div class="wp-mcp-ai-sm-form-row">
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-name"><?php esc_html_e( 'Name', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
							<input type="text" id="sm-name" class="regular-text" placeholder="<?php esc_attr_e( 'My Daily Report', 'mcp-ai-wpoos-pro' ); ?>">
						</div>
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-type"><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></label>
							<select id="sm-type">
								<option value="task"><?php esc_html_e( 'Task (WP Hook)', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="workflow"><?php esc_html_e( 'Workflow (Tool Chain)', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="assistant_run"><?php esc_html_e( 'Assistant Run', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="channel_broadcast"><?php esc_html_e( 'Channel Broadcast', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
						</div>
					</div>

					<!-- Description -->
					<div class="wp-mcp-ai-sm-form-row">
						<div class="wp-mcp-ai-sm-form-group full-width">
							<label for="sm-description"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></label>
							<textarea id="sm-description" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Optional description…', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
						</div>
					</div>

					<!-- Row: Schedule + Timestamp -->
					<div class="wp-mcp-ai-sm-form-row">
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-schedule"><?php esc_html_e( 'Interval', 'mcp-ai-wpoos-pro' ); ?></label>
							<select id="sm-schedule">
								<!-- Populated by JS from wpMcpAiScheduleManager.scheduleOptions -->
							</select>
						</div>
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-timestamp"><?php esc_html_e( 'First Run (local time)', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="datetime-local" id="sm-timestamp" class="regular-text">
							<p class="description"><?php esc_html_e( 'Leave blank to run 60 s from now.', 'mcp-ai-wpoos-pro' ); ?></p>
						</div>
					</div>

					<!-- TYPE-SPECIFIC PANELS -->

					<!-- Task panel -->
					<div class="wp-mcp-ai-sm-type-panel" id="sm-panel-task">
						<div class="wp-mcp-ai-sm-form-row">
							<div class="wp-mcp-ai-sm-form-group full-width">
								<label for="sm-hook"><?php esc_html_e( 'Action Hook', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
								<input type="text" id="sm-hook" class="regular-text" placeholder="my_plugin_daily_cleanup">
								<p class="description"><?php esc_html_e( 'WordPress action hook name fired when this schedule runs.', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
						</div>
					</div>

					<!-- Workflow panel -->
					<div class="wp-mcp-ai-sm-type-panel" id="sm-panel-workflow" style="display:none;">
						<div class="wp-mcp-ai-sm-workflow-steps-wrap">
							<label><?php esc_html_e( 'Workflow Steps', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
							<p class="description"><?php esc_html_e( 'Add tool calls in order. Each step receives the previous step\'s result.', 'mcp-ai-wpoos-pro' ); ?></p>
							<div id="sm-workflow-steps" class="wp-mcp-ai-sm-steps-list"></div>
							<button type="button" class="button" id="sm-add-step">
								<span class="dashicons dashicons-plus-alt2"></span>
								<?php esc_html_e( 'Add Step', 'mcp-ai-wpoos-pro' ); ?>
							</button>
						</div>
					</div>

					<!-- Assistant Run panel -->
					<div class="wp-mcp-ai-sm-type-panel" id="sm-panel-assistant_run" style="display:none;">
						<div class="wp-mcp-ai-sm-form-row">
							<div class="wp-mcp-ai-sm-form-group">
								<label for="sm-assistant-id"><?php esc_html_e( 'Assistant', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
								<?php
								$assistants = get_posts(
									array(
										'post_type'      => 'mcp_ai_assistant',
										'post_status'    => 'publish',
										'posts_per_page' => -1,
										'orderby'        => 'title',
										'order'          => 'ASC',
									)
								);
								?>
								<select id="sm-assistant-id">
									<option value=""><?php esc_html_e( '— Select assistant —', 'mcp-ai-wpoos-pro' ); ?></option>
									<?php foreach ( $assistants as $ast ) : ?>
										<option value="<?php echo esc_attr( $ast->ID ); ?>"><?php echo esc_html( $ast->post_title ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="wp-mcp-ai-sm-form-row">
							<div class="wp-mcp-ai-sm-form-group full-width">
								<label for="sm-assistant-message"><?php esc_html_e( 'Message', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
								<textarea id="sm-assistant-message" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Generate the weekly performance report and email it to the team.', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
							</div>
						</div>
					</div>

					<!-- Channel Broadcast panel -->
					<div class="wp-mcp-ai-sm-type-panel" id="sm-panel-channel_broadcast" style="display:none;">
						<div class="wp-mcp-ai-sm-form-row">
							<div class="wp-mcp-ai-sm-form-group full-width">
								<label for="sm-broadcast-message"><?php esc_html_e( 'Message', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
								<textarea id="sm-broadcast-message" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Your scheduled broadcast messageâ¦', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
								<p class="description"><?php esc_html_e( 'Markdown is supported on Telegram, Slack, and Discord.', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
						</div>
						<div class="wp-mcp-ai-sm-form-row">
							<div class="wp-mcp-ai-sm-form-group full-width">
								<label><?php esc_html_e( 'Channels', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
								<div class="wp-mcp-ai-sm-channels-checkboxes">
									<?php foreach ( array( 'telegram', 'slack', 'discord', 'teams', 'messenger', 'whatsapp' ) as $ch ) { ?>
										<label class="wp-mcp-ai-sm-channel-label"><input type="checkbox" class="sm-broadcast-channel" value="<?php echo esc_attr( $ch ); ?>"> <?php echo esc_html( ucfirst( $ch ) ); ?></label>
									<?php } ?>
								</div>
								<p class="description"><?php esc_html_e( 'Credentials must be provided for each selected channel.', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
						</div>
						<div class="wp-mcp-ai-sm-form-row">
							<div class="wp-mcp-ai-sm-form-group full-width">
								<label for="sm-broadcast-credentials"><?php esc_html_e( 'Credentials (JSON)', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
								<textarea id="sm-broadcast-credentials" rows="4" class="large-text code" placeholder='<?php esc_attr_e( '{"telegram":{"token":"BOT_TOKEN","chat_id":"CHAT_ID"},"slack":{"token":"BOT_TOKEN","channel":"#general"}}', 'mcp-ai-wpoos-pro' ); ?>'></textarea>
								<p class="description"><?php esc_html_e( 'Credentials keyed by channel slug (same shape as unified_channel_broadcast tool).', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
						</div>
					</div>

					<!-- Row: Priority + Tags -->
					<div class="wp-mcp-ai-sm-form-row">
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-priority"><?php esc_html_e( 'Priority', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="number" id="sm-priority" value="5" min="1" max="10" class="small-text">
							<p class="description"><?php esc_html_e( '1 = highest, 10 = lowest', 'mcp-ai-wpoos-pro' ); ?></p>
						</div>
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-tags"><?php esc_html_e( 'Tags', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="text" id="sm-tags" class="regular-text" placeholder="<?php esc_attr_e( 'report, daily (comma-separated)', 'mcp-ai-wpoos-pro' ); ?>">
						</div>
					</div>

					<!-- Row: Retry config -->
					<div class="wp-mcp-ai-sm-form-row">
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-max-retries"><?php esc_html_e( 'Max Retries', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="number" id="sm-max-retries" value="0" min="0" max="5" class="small-text">
						</div>
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-retry-delay"><?php esc_html_e( 'Retry Delay (seconds)', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="number" id="sm-retry-delay" value="300" min="60" class="small-text">
						</div>
					</div>

					<!-- Row: Notifications -->
					<div class="wp-mcp-ai-sm-form-row">
						<div class="wp-mcp-ai-sm-form-group">
							<label class="wp-mcp-ai-sm-toggle-label">
								<input type="checkbox" id="sm-notify-on-failure">
								<?php esc_html_e( 'Notify on failure', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</div>
						<div class="wp-mcp-ai-sm-form-group" id="sm-notify-email-wrap" style="display:none;">
							<label for="sm-notify-email"><?php esc_html_e( 'Notification Email', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="email" id="sm-notify-email" class="regular-text" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						</div>
					</div>
					<!-- Row: Channel notifications -->
					<div class="wp-mcp-ai-sm-form-row" id="sm-notify-channels-row" style="display:none;">
						<div class="wp-mcp-ai-sm-form-group full-width">
							<label><?php esc_html_e( 'Notify Channels (optional)', 'mcp-ai-wpoos-pro' ); ?></label>
							<div class="wp-mcp-ai-sm-channels-checkboxes">
								<?php foreach ( array( 'telegram', 'slack', 'discord', 'teams', 'messenger', 'whatsapp' ) as $ch ) { ?>
									<label class="wp-mcp-ai-sm-channel-label"><input type="checkbox" class="sm-notify-channel" value="<?php echo esc_attr( $ch ); ?>"> <?php echo esc_html( ucfirst( $ch ) ); ?></label>
								<?php } ?>
							</div>
							<div class="wp-mcp-ai-sm-form-group full-width" style="margin-top:8px;">
								<label for="sm-notify-channel-credentials"><?php esc_html_e( 'Channel Credentials (JSON)', 'mcp-ai-wpoos-pro' ); ?></label>
								<textarea id="sm-notify-channel-credentials" rows="3" class="large-text code" placeholder='<?php esc_attr_e( '{"telegram":{"token":"…","chat_id":"…"}}', 'mcp-ai-wpoos-pro' ); ?>'></textarea>
							</div>
							<p class="description"><?php esc_html_e( 'When notify_on_failure is enabled, a failure alert will also be broadcast to the selected channels.', 'mcp-ai-wpoos-pro' ); ?></p>
						</div>
					</div>

					<!-- Row: Enabled toggle + Submit -->
					<div class="wp-mcp-ai-sm-form-row wp-mcp-ai-sm-form-actions">
						<label class="wp-mcp-ai-sm-toggle-label">
							<input type="checkbox" id="sm-enabled" checked>
							<?php esc_html_e( 'Enable immediately', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<button type="button" class="button button-primary" id="wp-mcp-ai-sm-create-btn">
							<?php esc_html_e( 'Create Schedule', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="wp-mcp-ai-sm-create-msg" id="wp-mcp-ai-sm-create-msg" aria-live="polite"></span>
					</div>

				</div><!-- .wp-mcp-ai-sm-form-grid -->
			</div><!-- #wp-mcp-ai-sm-create-form -->
		</div><!-- .wp-mcp-ai-sm-create-section -->
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * Verify the request nonce and capability.
	 *
	 * @return bool
	 */
	protected function verify_request() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			return false;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * AJAX: Return all schedules with next run times.
	 */
	public function ajax_get_schedules() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
		$output    = array();

		foreach ( $schedules as $schedule ) {
			$next_run = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $schedule['id'] );

			$output[] = array(
				'id'              => $schedule['id'],
				'name'            => $schedule['name'],
				'description'     => $schedule['description'],
				'schedule_type'   => isset( $schedule['schedule_type'] ) ? $schedule['schedule_type'] : 'task',
				'hook'            => $schedule['hook'],
				'schedule'        => $schedule['schedule'],
				'enabled'         => (bool) $schedule['enabled'],
				'priority'        => (int) $schedule['priority'],
				'tags'            => (array) $schedule['tags'],
				'notify_on_failure' => (bool) $schedule['notify_on_failure'],
				'notify_email'    => $schedule['notify_email'],
				'max_retries'     => (int) $schedule['max_retries'],
				'retry_delay'     => (int) $schedule['retry_delay'],
				'last_run_status' => $schedule['last_run_status'],
				'last_run_time'   => $schedule['last_run_time'] ? wp_date( 'Y-m-d H:i:s', $schedule['last_run_time'] ) : null,
				'last_error'      => $schedule['last_error'],
				'run_count'       => (int) $schedule['run_count'],
				'next_run'        => $next_run ? wp_date( 'Y-m-d H:i:s', $next_run ) : null,
				'created_at'      => wp_date( 'Y-m-d H:i:s', $schedule['created_at'] ),
				'workflow_steps'             => isset( $schedule['workflow_steps'] ) ? $schedule['workflow_steps'] : array(),
				'assistant_config'           => isset( $schedule['assistant_config'] ) ? $schedule['assistant_config'] : array(),
				'broadcast_config'           => isset( $schedule['broadcast_config'] ) ? $schedule['broadcast_config'] : array(),
				'notify_channels'            => isset( $schedule['notify_channels'] ) ? $schedule['notify_channels'] : array(),
			);
		}

		wp_send_json_success( array( 'schedules' => $output ) );
	}

	/**
	 * AJAX: Create a new schedule.
	 */
	public function ajax_create_schedule() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via verify_request().
		$raw = isset( $_POST['schedule'] ) ? wp_unslash( $_POST['schedule'] ) : '{}';

		if ( is_string( $raw ) ) {
			$data = json_decode( $raw, true );
		} else {
			$data = (array) $raw;
		}

		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid schedule data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( $data, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $result );
		$next_run = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $result );

		wp_send_json_success(
			array(
				'schedule_id' => $result,
				'name'        => $schedule['name'],
				'next_run'    => $next_run ? wp_date( 'Y-m-d H:i:s', $next_run ) : null,
				'message'     => __( 'Schedule created.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * AJAX: Update an existing schedule.
	 */
	public function ajax_update_schedule() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$schedule_id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$raw = isset( $_POST['schedule'] ) ? wp_unslash( $_POST['schedule'] ) : '{}';

		if ( is_string( $raw ) ) {
			$data = json_decode( $raw, true );
		} else {
			$data = (array) $raw;
		}

		if ( ! $schedule_id || ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$result = WP_MCP_AI_Pro_Schedule_Manager::update_schedule( $schedule_id, $data, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
		$next_run = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $schedule_id );

		wp_send_json_success(
			array(
				'schedule_id' => $schedule_id,
				'name'        => $schedule['name'],
				'next_run'    => $next_run ? wp_date( 'Y-m-d H:i:s', $next_run ) : null,
				'message'     => __( 'Schedule updated.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * AJAX: Delete a schedule.
	 */
	public function ajax_delete_schedule() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$schedule_id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';

		if ( ! $schedule_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing schedule_id.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$deleted = WP_MCP_AI_Pro_Schedule_Manager::delete_schedule( $schedule_id );

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Schedule not found.', 'mcp-ai-wpoos-pro' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Schedule deleted.', 'mcp-ai-wpoos-pro' ) ) );
	}

	/**
	 * AJAX: Enable or disable a schedule.
	 */
	public function ajax_toggle_schedule() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$schedule_id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );

		if ( ! $schedule_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing schedule_id.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$result = WP_MCP_AI_Pro_Schedule_Manager::toggle_schedule( $schedule_id, $enabled, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'enabled' => $enabled,
				'message' => $enabled
					? __( 'Schedule enabled.', 'mcp-ai-wpoos-pro' )
					: __( 'Schedule disabled.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * AJAX: Trigger a schedule immediately.
	 */
	public function ajax_trigger_schedule() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$schedule_id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';

		if ( ! $schedule_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing schedule_id.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$result = WP_MCP_AI_Pro_Schedule_Manager::trigger_now( $schedule_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$success = (bool) $result;
		$status  = $success ? 'success' : 'failure';

		wp_send_json_success(
			array(
				'run_status' => $status,
				'message'    => $success
					? __( 'Schedule triggered successfully.', 'mcp-ai-wpoos-pro' )
					: __( 'Schedule triggered but reported a failure. Check run history.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * AJAX: Get run history for a schedule.
	 */
	public function ajax_get_history() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$schedule_id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';

		if ( ! $schedule_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing schedule_id.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$history = WP_MCP_AI_Pro_Schedule_Manager::get_run_history( $schedule_id, 50 );

		$formatted = array_map(
			function ( $entry ) {
				return array(
					'status'   => $entry['status'],
					'time'     => wp_date( 'Y-m-d H:i:s', $entry['start_time'] ),
					'duration' => $entry['duration'],
					'error'    => $entry['error'],
				);
			},
			$history
		);

		wp_send_json_success( array( 'history' => $formatted ) );
	}

	/**
	 * AJAX: Clear run history for a schedule.
	 */
	public function ajax_clear_history() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$schedule_id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';

		if ( ! $schedule_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing schedule_id.', 'mcp-ai-wpoos-pro' ) ) );
		}

		WP_MCP_AI_Pro_Schedule_Manager::clear_run_history( $schedule_id );

		wp_send_json_success( array( 'message' => __( 'History cleared.', 'mcp-ai-wpoos-pro' ) ) );
	}

	/**
	 * AJAX: Export run history for a schedule as a CSV download.
	 *
	 * Uses WP_MCP_AI_Pro_Schedule_Manager::get_history_csv() which leverages the
	 * csv-stringify NPM package via WP_MCP_AI_Contact_Importer_Service when
	 * available, falling back to pure-PHP fputcsv.
	 */
	public function ajax_export_history_csv() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$schedule_id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';

		if ( ! $schedule_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing schedule_id.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$csv      = WP_MCP_AI_Pro_Schedule_Manager::get_history_csv( $schedule_id, 50 );
		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
		$filename = 'schedule-history-' . sanitize_file_name( $schedule_id ) . '-' . gmdate( 'Ymd-His' ) . '.csv';

		// Return as base64 data URI so JS can trigger a browser download without a full page load.
		wp_send_json_success(
			array(
				'csv'      => base64_encode( $csv ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- safe transport encoding
				'filename' => $filename,
				'name'     => $schedule ? $schedule['name'] : $schedule_id,
			)
		);
	}

	/**
	 * AJAX: Export all enabled schedules as an iCalendar (.ics) file download.
	 *
	 * Uses WP_MCP_AI_Pro_Schedule_Manager::get_schedules_ical() which invokes the
	 * ical-generator Node.js service via wp_mcp_ai_ics_generate_calendar filter
	 * when available, falling back to a pure-PHP RFC 5545 implementation.
	 */
	public function ajax_export_ical() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

		$ics      = WP_MCP_AI_Pro_Schedule_Manager::get_schedules_ical();
		$filename = 'nvoos-schedules-' . gmdate( 'Ymd-His' ) . '.ics';

		wp_send_json_success(
			array(
				'ics'      => base64_encode( $ics ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- safe transport encoding
				'filename' => $filename,
			)
		);
	}
}
