<?php
/**
 * Pro Schedule Manager Admin Section.
 *
 * Provides a full management UI for pro-level scheduled tasks, workflows,
 * and assistant runs within the NV oOS settings dashboard.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
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
		add_action( 'wp_ajax_wp_mcp_ai_sm_get_schedules', array( $this, 'ajax_get_schedules' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_create_schedule', array( $this, 'ajax_create_schedule' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_update_schedule', array( $this, 'ajax_update_schedule' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_delete_schedule', array( $this, 'ajax_delete_schedule' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_toggle_schedule', array( $this, 'ajax_toggle_schedule' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_trigger_schedule', array( $this, 'ajax_trigger_schedule' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_get_history', array( $this, 'ajax_get_history' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_clear_history', array( $this, 'ajax_clear_history' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_export_history_csv', array( $this, 'ajax_export_history_csv' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_export_ical', array( $this, 'ajax_export_ical' ) );

		// Schedule presets AJAX handlers.
		add_action( 'wp_ajax_wp_mcp_ai_sm_get_presets', array( $this, 'ajax_get_presets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_sm_install_preset', array( $this, 'ajax_install_preset' ) );
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
	 * Enqueue JavaScript and CSS on the settings dashboard or standalone page.
	 *
	 * Assets are loaded on:
	 * - The main NV oOS settings dashboard when the Orchestration tab is active.
	 * - The dedicated standalone Schedule Manager page (nvoos-pro-schedule-manager).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Build the standalone page hook from known constants rather than relying on a
		// global instance variable.  WordPress generates submenu hooks as:
		// {sanitized_parent_menu_title}_page_{page_slug}
		// WP_MCP_AI_Pro_Dashboard::SANITIZED_MENU_TITLE = 'nv-oos-pro'.
		$standalone_hook = '';
		if ( class_exists( 'WP_MCP_AI_Pro_Dashboard' ) && class_exists( 'WP_MCP_AI_Pro_Schedule_Manager_Page' ) ) {
			$standalone_hook = WP_MCP_AI_Pro_Dashboard::SANITIZED_MENU_TITLE . '_page_' . WP_MCP_AI_Pro_Schedule_Manager_Page::PAGE_SLUG;
		}

		$is_standalone = ( '' !== $standalone_hook && $hook === $standalone_hook );

		// Fallback: check $_GET['page'] for the standalone page slug.  This
		// covers edge cases where the hook suffix may differ from the computed
		// value (e.g. translated menu titles or timing differences in the
		// base + pro separate-plugin loading order).
		if ( ! $is_standalone && class_exists( 'WP_MCP_AI_Pro_Schedule_Manager_Page' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page slug for script enqueue only.
			$is_standalone = isset( $_GET['page'] ) && WP_MCP_AI_Pro_Schedule_Manager_Page::PAGE_SLUG === sanitize_text_field( wp_unslash( $_GET['page'] ) );
		}

		$is_dashboard = ( false !== strpos( $hook, 'wp-mcp-ai-dashboard' ) )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page slug for script enqueue only.
			|| ( isset( $_GET['page'] ) && 'wp-mcp-ai-dashboard' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) );

		if ( ! $is_dashboard && ! $is_standalone ) {
			return;
		}

		// On the main settings dashboard, only enqueue when the Orchestration tab is active.
		if ( $is_dashboard && ! $is_standalone ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking tab for script enqueue only.
			$is_tab = ! isset( $_GET['tab'] ) || 'orchestration' === sanitize_text_field( wp_unslash( $_GET['tab'] ) );
			if ( ! $is_tab ) {
				return;
			}
		}

		$css_path    = WP_MCP_AI_PRO_PATH . 'assets/css/schedule-manager.css';
		$css_version = file_exists( $css_path ) ? filemtime( $css_path ) : WP_MCP_AI_PRO_VERSION;

		wp_enqueue_style(
			'wp-mcp-ai-schedule-manager',
			WP_MCP_AI_PRO_URL . 'assets/css/schedule-manager.css',
			array(),
			$css_version
		);

		// chart.js — used for the run-history sparkline in the history modal.
		if ( class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
			WP_MCP_AI_Chart_JS_Helper::enqueue_chart_js();
		} elseif ( file_exists( WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js' ) ) {
			wp_enqueue_script(
				'wp-mcp-ai-chartjs',
				WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js',
				array(),
				filemtime( WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js' ),
				true
			);
		}

		$js_path    = WP_MCP_AI_PRO_PATH . 'assets/js/schedule-manager.js';
		$js_version = file_exists( $js_path ) ? filemtime( $js_path ) : WP_MCP_AI_PRO_VERSION;

		wp_enqueue_script(
			'wp-mcp-ai-schedule-manager',
			WP_MCP_AI_PRO_URL . 'assets/js/schedule-manager.js',
			array( 'jquery', 'wp-util', 'wp-mcp-ai-chartjs' ),
			$js_version,
			true
		);

		$cron_schedules   = wp_get_schedules();
		$schedule_options = array(
			'single' => __( 'Once (single)', 'mcp-ai-wpoos-pro' ),
		);
		foreach ( $cron_schedules as $key => $cron_schedule ) {
			$schedule_options[ $key ] = $cron_schedule['display'];
		}

		// Build a lightweight assistant list for preset install prompts.
		$preset_assistants = array();
		$ast_posts         = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		foreach ( $ast_posts as $ast_post ) {
			$preset_assistants[] = array(
				'id'    => $ast_post->ID,
				'title' => $ast_post->post_title,
			);
		}

		wp_localize_script(
			'wp-mcp-ai-schedule-manager',
			'wpMcpAiScheduleManager',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
				'scheduleOptions' => $schedule_options,
				'assistants'      => $preset_assistants,
				'strings'         => array(
					'confirmDelete'          => __( 'Are you sure you want to delete this schedule and all its history?', 'mcp-ai-wpoos-pro' ),
					'confirmClear'           => __( 'Are you sure you want to clear the run history for this schedule?', 'mcp-ai-wpoos-pro' ),
					'confirmTrigger'         => __( 'Run this schedule now?', 'mcp-ai-wpoos-pro' ),
					'loading'                => __( 'Loading…', 'mcp-ai-wpoos-pro' ),
					'saving'                 => __( 'Saving…', 'mcp-ai-wpoos-pro' ),
					'saved'                  => __( 'Saved.', 'mcp-ai-wpoos-pro' ),
					'deleted'                => __( 'Deleted.', 'mcp-ai-wpoos-pro' ),
					'triggered'              => __( 'Schedule triggered successfully.', 'mcp-ai-wpoos-pro' ),
					'error'                  => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'noSchedules'            => __( 'No schedules yet. Create one below.', 'mcp-ai-wpoos-pro' ),
					'noHistory'              => __( 'No run history for this schedule.', 'mcp-ai-wpoos-pro' ),
					'typeTask'               => __( 'Task', 'mcp-ai-wpoos-pro' ),
					'typeWorkflow'           => __( 'Workflow', 'mcp-ai-wpoos-pro' ),
					'typeAssistant'          => __( 'Assistant Run', 'mcp-ai-wpoos-pro' ),
					'typeBroadcast'          => __( 'Channel Broadcast', 'mcp-ai-wpoos-pro' ),
					'typeBuilder'            => __( 'Workflow Builder', 'mcp-ai-wpoos-pro' ),
					'selectWorkflow'         => __( 'Please select a saved workflow.', 'mcp-ai-wpoos-pro' ),
					'statusNever'            => __( 'Never run', 'mcp-ai-wpoos-pro' ),
					'statusSuccess'          => __( 'Success', 'mcp-ai-wpoos-pro' ),
					'statusFailure'          => __( 'Failed', 'mcp-ai-wpoos-pro' ),
					'statusPending'          => __( 'Pending', 'mcp-ai-wpoos-pro' ),
					'enabled'                => __( 'Enabled', 'mcp-ai-wpoos-pro' ),
					'disabled'               => __( 'Disabled', 'mcp-ai-wpoos-pro' ),
					'addStep'                => __( '+ Add Step', 'mcp-ai-wpoos-pro' ),
					'removeStep'             => __( 'Remove', 'mcp-ai-wpoos-pro' ),
					'exportCsv'              => __( 'Export CSV', 'mcp-ai-wpoos-pro' ),
					'exportIcal'             => __( 'Export to Calendar (.ics)', 'mcp-ai-wpoos-pro' ),
					'exportIcalTitle'        => __( 'Download all enabled schedules as an iCalendar file', 'mcp-ai-wpoos-pro' ),
					'chartSuccess'           => __( 'Success', 'mcp-ai-wpoos-pro' ),
					'chartFailure'           => __( 'Failure', 'mcp-ai-wpoos-pro' ),
					'viewLog'                => __( 'View Log', 'mcp-ai-wpoos-pro' ),
					'hideLog'                => __( 'Hide Log', 'mcp-ai-wpoos-pro' ),
					// Preset browser strings.
					'presetInstall'          => __( 'Install', 'mcp-ai-wpoos-pro' ),
					'presetInstalling'       => __( 'Installing…', 'mcp-ai-wpoos-pro' ),
					'presetInstalled'        => __( 'Preset installed successfully.', 'mcp-ai-wpoos-pro' ),
					'presetNoResults'        => __( 'No presets match your filters.', 'mcp-ai-wpoos-pro' ),
					'presetConfirmInstall'   => __( 'Install this schedule preset?', 'mcp-ai-wpoos-pro' ),
					'presetSelectAssistant'  => __( 'Select an assistant for this schedule:', 'mcp-ai-wpoos-pro' ),
					'presetNoAssistants'     => __( 'No assistants found. Please create an assistant first.', 'mcp-ai-wpoos-pro' ),
					'presetInvalidAssistant' => __( 'Please enter a valid assistant ID from the list above.', 'mcp-ai-wpoos-pro' ),
					'presetEnterCredentials' => __( 'Enter channel credentials JSON for this broadcast schedule:', 'mcp-ai-wpoos-pro' ),
					'presetInvalidJson'      => __( 'Invalid JSON. Please enter valid channel credentials.', 'mcp-ai-wpoos-pro' ),
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
			<?php $this->render_preset_browser(); ?>
			<?php $this->render_create_form(); ?>

		</div><!-- .wp-mcp-ai-schedule-manager -->

		<?php
		$this->render_logging_table();
		$this->render_activity_log();
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
						<option value="workflow_builder"><?php esc_html_e( 'Workflow Builder', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
					<select id="wp-mcp-ai-sm-filter-status" class="wp-mcp-ai-sm-filter">
						<option value=""><?php esc_html_e( 'All Statuses', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="enabled"><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="disabled"><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
					<button type="button" class="button" id="wp-mcp-ai-sm-refresh" title="<?php esc_attr_e( 'Refresh', 'mcp-ai-wpoos-pro' ); ?>" aria-label="<?php esc_attr_e( 'Refresh', 'mcp-ai-wpoos-pro' ); ?>">
						<span class="dashicons dashicons-update"></span>
					</button>
					<a href="#" class="button" id="wp-mcp-ai-sm-export-ical" title="<?php esc_attr_e( 'Download all enabled schedules as an iCalendar file', 'mcp-ai-wpoos-pro' ); ?>" aria-label="<?php esc_attr_e( 'Export to Calendar', 'mcp-ai-wpoos-pro' ); ?>">
						<span class="dashicons dashicons-calendar-alt"></span>
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
								<option value="workflow_builder"><?php esc_html_e( 'Workflow Builder', 'mcp-ai-wpoos-pro' ); ?></option>
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

					<!-- Workflow Builder panel -->
					<div class="wp-mcp-ai-sm-type-panel" id="sm-panel-workflow_builder" style="display:none;">
						<div class="wp-mcp-ai-sm-form-row">
							<div class="wp-mcp-ai-sm-form-group full-width">
								<label for="sm-workflow-builder-id"><?php esc_html_e( 'Saved Workflow', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
								<?php
								$saved_workflows = get_option( 'wp_mcp_ai_pro_workflows', array() );
								if ( ! is_array( $saved_workflows ) ) {
									$saved_workflows = array();
								}
								?>
								<select id="sm-workflow-builder-id">
									<option value=""><?php esc_html_e( '— Select a saved workflow —', 'mcp-ai-wpoos-pro' ); ?></option>
									<?php foreach ( $saved_workflows as $wf_id => $wf ) : ?>
										<option value="<?php echo esc_attr( $wf_id ); ?>">
											<?php echo esc_html( ! empty( $wf['name'] ) ? $wf['name'] : $wf_id ); ?>
											<?php
											$node_count = isset( $wf['nodes'] ) && is_array( $wf['nodes'] ) ? count( $wf['nodes'] ) : 0;
											$edge_count = isset( $wf['edges'] ) && is_array( $wf['edges'] ) ? count( $wf['edges'] ) : 0;
											/* translators: %1$d: number of nodes, %2$d: number of edges */
											printf( esc_html__( '(%1$d nodes, %2$d edges)', 'mcp-ai-wpoos-pro' ), (int) $node_count, (int) $edge_count );
											?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( empty( $saved_workflows ) ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %s: URL to Pro Workflow Builder page */
											esc_html__( 'No saved workflows found. %1$sCreate one in the Pro Workflow Builder%2$s first.', 'mcp-ai-wpoos-pro' ),
											'<a href="' . esc_url( admin_url( 'admin.php?page=nvoos-pro-workflow-builder' ) ) . '">',
											'</a>'
										);
										?>
									</p>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'Select a workflow created in the Pro Workflow Builder. It will be executed according to the schedule.', 'mcp-ai-wpoos-pro' ); ?></p>
								<?php endif; ?>
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

					<!-- Row: Timeout + Webhook -->
					<div class="wp-mcp-ai-sm-form-row">
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-timeout"><?php esc_html_e( 'Timeout (seconds)', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="number" id="sm-timeout" value="0" min="0" class="small-text">
							<p class="description"><?php esc_html_e( '0 = no limit. Runs exceeding this are marked as failed.', 'mcp-ai-wpoos-pro' ); ?></p>
						</div>
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-callback-url"><?php esc_html_e( 'Webhook Callback URL', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="url" id="sm-callback-url" class="regular-text" placeholder="https://example.com/webhook">
							<p class="description"><?php esc_html_e( 'Receives a POST with run results on completion or failure.', 'mcp-ai-wpoos-pro' ); ?></p>
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


					<!-- Row: Result Capture (display settings for Scheduled Result widget) -->
					<div class="wp-mcp-ai-sm-form-row">
						<div class="wp-mcp-ai-sm-form-group full-width">
							<strong><?php esc_html_e( 'Result Capture', 'mcp-ai-wpoos-pro' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Controls how run output is stored and surfaced in the Scheduled Result block/widget.', 'mcp-ai-wpoos-pro' ); ?></p>
						</div>
					</div>
					<div class="wp-mcp-ai-sm-form-row">
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-result-capture"><?php esc_html_e( 'Capture Mode', 'mcp-ai-wpoos-pro' ); ?></label>
							<select id="sm-result-capture">
								<option value="summary"><?php esc_html_e( 'Summary only', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="full"><?php esc_html_e( 'Full (summary + data)', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="disabled"><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
						</div>
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-result-retention"><?php esc_html_e( 'Retention (runs)', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="number" id="sm-result-retention" value="10" min="1" max="100" class="small-text">
							<p class="description"><?php esc_html_e( 'Number of recent result envelopes to keep (1–100).', 'mcp-ai-wpoos-pro' ); ?></p>
						</div>
					</div>
					<div class="wp-mcp-ai-sm-form-row">
						<div class="wp-mcp-ai-sm-form-group">
							<label class="wp-mcp-ai-sm-toggle-label">
								<input type="checkbox" id="sm-public-render">
								<?php esc_html_e( 'Allow public rendering', 'mcp-ai-wpoos-pro' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When enabled, unauthenticated visitors can fetch the latest result (only the allowed fields below).', 'mcp-ai-wpoos-pro' ); ?></p>
						</div>
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-public-fields"><?php esc_html_e( 'Public fields (allow-list)', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="text" id="sm-public-fields" class="regular-text" placeholder="<?php esc_attr_e( 'summary, data.items', 'mcp-ai-wpoos-pro' ); ?>">
							<p class="description"><?php esc_html_e( 'Comma-separated dotted JSON paths exposed when public rendering is on. Leave blank to expose summary only.', 'mcp-ai-wpoos-pro' ); ?></p>
						</div>
					</div>
					<div class="wp-mcp-ai-sm-form-row">
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-widget-render-mode"><?php esc_html_e( 'Widget default: render mode', 'mcp-ai-wpoos-pro' ); ?></label>
							<select id="sm-widget-render-mode">
								<option value="summary-card"><?php esc_html_e( 'Summary card', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="list"><?php esc_html_e( 'List', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="table"><?php esc_html_e( 'Table', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="metric"><?php esc_html_e( 'Metric', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="timeline"><?php esc_html_e( 'Timeline', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="raw"><?php esc_html_e( 'Raw', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
						</div>
						<div class="wp-mcp-ai-sm-form-group">
							<label for="sm-widget-refresh-interval"><?php esc_html_e( 'Widget default: auto-refresh (seconds)', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="number" id="sm-widget-refresh-interval" value="0" min="0" max="3600" class="small-text">
							<p class="description"><?php esc_html_e( '0 = no auto-refresh.', 'mcp-ai-wpoos-pro' ); ?></p>
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
				'id'                => $schedule['id'],
				'name'              => $schedule['name'],
				'description'       => $schedule['description'],
				'schedule_type'     => isset( $schedule['schedule_type'] ) ? $schedule['schedule_type'] : 'task',
				'hook'              => $schedule['hook'],
				'schedule'          => $schedule['schedule'],
				'enabled'           => (bool) $schedule['enabled'],
				'priority'          => (int) $schedule['priority'],
				'tags'              => (array) $schedule['tags'],
				'notify_on_failure' => (bool) $schedule['notify_on_failure'],
				'notify_email'      => $schedule['notify_email'],
				'max_retries'       => (int) $schedule['max_retries'],
				'retry_delay'       => (int) $schedule['retry_delay'],
				'timeout'           => isset( $schedule['timeout'] ) ? (int) $schedule['timeout'] : 0,
				'callback_url'      => isset( $schedule['callback_url'] ) ? $schedule['callback_url'] : '',
				'last_run_status'   => $schedule['last_run_status'],
				'last_run_time'     => $schedule['last_run_time'] ? wp_date( 'Y-m-d H:i:s', $schedule['last_run_time'] ) : null,
				'last_error'        => $schedule['last_error'],
				'run_count'         => (int) $schedule['run_count'],
				'next_run'          => $next_run ? wp_date( 'Y-m-d H:i:s', $next_run ) : null,
				'created_at'        => wp_date( 'Y-m-d H:i:s', $schedule['created_at'] ),
				'workflow_steps'    => isset( $schedule['workflow_steps'] ) ? $schedule['workflow_steps'] : array(),
				'assistant_config'  => isset( $schedule['assistant_config'] ) ? $schedule['assistant_config'] : array(),
				'broadcast_config'  => isset( $schedule['broadcast_config'] ) ? $schedule['broadcast_config'] : array(),
				'notify_channels'   => isset( $schedule['notify_channels'] ) ? $schedule['notify_channels'] : array(),
				'display'           => isset( $schedule['display'] ) ? $schedule['display'] : array(),
				'result_delivery'   => isset( $schedule['result_delivery'] ) ? $schedule['result_delivery'] : array(),
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above via verify_request(); value is JSON decoded below.
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; value is JSON decoded below.
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

		// Capture any unexpected output (PHP warnings/notices) that would
		// corrupt the JSON response and cause jQuery's .fail() to fire.
		ob_start();

		try {
			$result = WP_MCP_AI_Pro_Schedule_Manager::trigger_now( $schedule_id, get_current_user_id() );
		} catch ( \Throwable $e ) {
			ob_end_clean();

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Pro schedule trigger exception',
					array(
						'schedule_id' => $schedule_id,
						'error'       => $e->getMessage(),
						'file'        => str_replace( ABSPATH, '', $e->getFile() ),
						'line'        => $e->getLine(),
					)
				);
			}

			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Schedule trigger crashed: %s', 'mcp-ai-wpoos-pro' ),
						$e->getMessage()
					),
				)
			);
		}

		$unexpected_output = ob_get_clean();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$success = (bool) $result;
		$status  = $success ? 'success' : 'failure';

		$response = array(
			'run_status' => $status,
			'message'    => $success
				? __( 'Schedule triggered successfully.', 'mcp-ai-wpoos-pro' )
				: __( 'Schedule triggered but reported a failure. Check run history.', 'mcp-ai-wpoos-pro' ),
		);

		// Surface any unexpected output in the response for debugging.
		if ( '' !== $unexpected_output && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$response['debug_output'] = $unexpected_output;
		}

		wp_send_json_success( $response );
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
					'status'     => $entry['status'],
					'time'       => wp_date( 'Y-m-d H:i:s', $entry['start_time'] ),
					'duration'   => $entry['duration'],
					'error'      => $entry['error'],
					'action_log' => isset( $entry['action_log'] ) && is_array( $entry['action_log'] ) ? $entry['action_log'] : array(),
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

	// -------------------------------------------------------------------------
	// Logging
	// -------------------------------------------------------------------------

	/**
	 * Render the error & activity logging table if logging is enabled.
	 *
	 * Only shows schedule-related error entries so that operators can
	 * troubleshoot schedule issues without noise from unrelated events.
	 */
	private function render_logging_table() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// Only show the logging table if logging is enabled.
		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		$all_entries = WP_MCP_AI_Logger::get_recent_error_messages( 50 );

		// Filter to only schedule-related error entries by checking message
		// content and context for a schedule_id key.
		$entries = array();
		foreach ( $all_entries as $entry ) {
			$msg         = isset( $entry['message'] ) ? $entry['message'] : '';
			$has_context = isset( $entry['context']['schedule_id'] ) || isset( $entry['context']['event'] );
			if ( stripos( $msg, 'schedule' ) !== false || $has_context ) {
				$entries[] = $entry;
			}
			if ( count( $entries ) >= 20 ) {
				break;
			}
		}
		?>
		<div class="wp-mcp-ai-error-log-section" style="margin-top: 30px;">
			<h3><?php esc_html_e( 'Schedule Error Log', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Recent schedule-related error and warning messages (most recent first). Expand an entry to view additional context.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php if ( empty( $entries ) ) : ?>
				<p class="description"><?php esc_html_e( 'No schedule-related error or warning messages have been recorded yet.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<ul class="wp-mcp-ai-log-preview" style="list-style: none; padding: 0; margin: 15px 0;">
					<?php
					foreach ( $entries as $entry ) :
						$timestamp = '';

						if ( ! empty( $entry['timestamp'] ) ) {
							$timestamp = get_date_from_gmt(
								$entry['timestamp'],
								get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
							);
						}

						$type_label    = strtoupper( $entry['type'] );
						$message_label = $entry['message'];
						$context_label = '';

						if ( isset( $entry['context'] ) && ! empty( $entry['context'] ) ) {
							$options = 0;

							if ( defined( 'JSON_PRETTY_PRINT' ) ) {
								$options |= JSON_PRETTY_PRINT;
							}

							if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
								$options |= JSON_UNESCAPED_SLASHES;
							}

							$context_json = wp_json_encode( $entry['context'], $options );

							if ( false !== $context_json ) {
								$context_label = $context_json;
							}
						}
						?>
						<li style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border-left: 3px solid #dc3232; border-radius: 3px;">
							<?php if ( ! empty( $timestamp ) ) : ?>
								<span class="wp-mcp-ai-log-preview__time" style="color: #666; font-size: 0.9em;"><?php echo esc_html( $timestamp ); ?></span>
								&mdash;
							<?php endif; ?>
							<span class="wp-mcp-ai-log-preview__type" style="font-weight: bold; color: #dc3232;"><?php echo esc_html( $type_label ); ?></span>:
							<span class="wp-mcp-ai-log-preview__message"><?php echo esc_html( $message_label ); ?></span>
							<?php if ( '' !== $context_label ) : ?>
								<details class="wp-mcp-ai-log-preview__context" style="margin-top: 10px;">
									<summary style="cursor: pointer; color: #0073aa;"><?php esc_html_e( 'Context details', 'mcp-ai-wpoos' ); ?></summary>
									<pre style="background: #fff; padding: 10px; margin-top: 10px; overflow-x: auto; border: 1px solid #ddd; border-radius: 3px; font-size: 0.85em;"><?php echo esc_html( $context_label ); ?></pre>
								</details>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php
			$log_file_path    = WP_MCP_AI_Logger::get_log_file_path();
			$log_file_exists  = WP_MCP_AI_Logger::does_log_file_exist();
			$log_file_size    = WP_MCP_AI_Logger::get_log_file_size();
			$log_size_display = '';

			if ( null !== $log_file_size ) {
				$log_size_display = function_exists( 'size_format' )
				? size_format( $log_file_size, 2 )
				: $log_file_size . ' bytes';
			}
			?>
			<div class="wp-mcp-ai-log-meta" style="margin-top: 15px; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 3px;">
				<?php if ( '' !== $log_file_path ) : ?>
					<p class="description">
						<?php
						if ( $log_file_exists ) {
							if ( '' === $log_size_display ) {
								$log_size_display = __( 'Unknown size', 'mcp-ai-wpoos' );
							}

							printf(
								/* translators: 1: Path to the PHP error log. 2: Human readable size. */
								esc_html__( 'PHP error log: %1$s (%2$s).', 'mcp-ai-wpoos' ),
								'<code>' . esc_html( $log_file_path ) . '</code>',
								esc_html( $log_size_display )
							);
						} else {
							printf(
								/* translators: %s: Path to the PHP error log. */
								esc_html__( 'PHP error log: %s (not created yet).', 'mcp-ai-wpoos' ),
								'<code>' . esc_html( $log_file_path ) . '</code>'
							);
						}
						?>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Unable to determine the PHP error log location. Check your server configuration if you need to inspect or prune the log.', 'mcp-ai-wpoos' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a dedicated activity log scoped to schedule events.
	 *
	 * Only shows entries with the 'schedule_run' event type, which
	 * are recorded by WP_MCP_AI_Logger, giving schedule operators quick
	 * visibility into what happened during recent schedule runs.
	 */
	private function render_activity_log() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// Only show the activity log if logging is enabled.
		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		$entries = WP_MCP_AI_Logger::get_recent_activity_entries( 20, array( 'schedule_run' ) );
		?>
		<div class="wp-mcp-ai-activity-log-section" style="margin-top: 30px;">
			<h3><?php esc_html_e( 'Schedule Activity Log', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Recent schedule executions, triggers, and retries (most recent first).', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php if ( empty( $entries ) ) : ?>
				<p class="description"><?php esc_html_e( 'No schedule activity has been recorded yet.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<ul class="wp-mcp-ai-log-preview" style="list-style: none; padding: 0; margin: 15px 0;">
					<?php
					foreach ( $entries as $entry ) :
						$timestamp = '';

						if ( ! empty( $entry['timestamp'] ) ) {
							$timestamp = get_date_from_gmt(
								$entry['timestamp'],
								get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
							);
						}

						$type_label    = ! empty( $entry['type'] ) ? strtoupper( $entry['type'] ) : 'INFO';
						$message_label = ! empty( $entry['message'] ) ? $entry['message'] : '';
						$context_label = '';

						if ( isset( $entry['context'] ) && ! empty( $entry['context'] ) ) {
							$options = 0;

							if ( defined( 'JSON_PRETTY_PRINT' ) ) {
								$options |= JSON_PRETTY_PRINT;
							}

							if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
								$options |= JSON_UNESCAPED_SLASHES;
							}

							$context_json = wp_json_encode( $entry['context'], $options );

							if ( false !== $context_json ) {
								$context_label = $context_json;
							}
						}
						?>
						<li style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border-left: 3px solid #2271b1; border-radius: 3px;">
							<?php if ( ! empty( $timestamp ) ) : ?>
								<span class="wp-mcp-ai-log-preview__time" style="color: #666; font-size: 0.9em;"><?php echo esc_html( $timestamp ); ?></span>
								&mdash;
							<?php endif; ?>
							<span class="wp-mcp-ai-log-preview__type" style="font-weight: bold; color: #2271b1;"><?php echo esc_html( $type_label ); ?></span>:
							<span class="wp-mcp-ai-log-preview__message"><?php echo esc_html( $message_label ); ?></span>
							<?php if ( '' !== $context_label ) : ?>
								<details class="wp-mcp-ai-log-preview__context" style="margin-top: 10px;">
									<summary style="cursor: pointer; color: #0073aa;"><?php esc_html_e( 'Context details', 'mcp-ai-wpoos' ); ?></summary>
									<pre style="background: #fff; padding: 10px; margin-top: 10px; overflow-x: auto; border: 1px solid #ddd; border-radius: 3px; font-size: 0.85em;"><?php echo esc_html( $context_label ); ?></pre>
								</details>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Schedule Preset Browser
	// -------------------------------------------------------------------------

	/**
	 * Render the preset browser UI.
	 *
	 * Displays a collapsible panel with category and toolkit filters, a
	 * searchable grid of preset cards, and a one-click install button.
	 */
	protected function render_preset_browser() {
		$presets_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-presets.php';
		if ( ! file_exists( $presets_file ) ) {
			return;
		}
		require_once $presets_file;

		$categories = WP_MCP_AI_Pro_Schedule_Presets::get_categories();
		?>
		<div class="wp-mcp-ai-sm-presets-section">
			<h3 class="wp-mcp-ai-sm-presets-toggle" role="button" tabindex="0" aria-expanded="false" aria-controls="wp-mcp-ai-sm-presets-panel">
				<span class="dashicons dashicons-welcome-widgets-menus"></span>
				<?php esc_html_e( 'Schedule Presets', 'mcp-ai-wpoos-pro' ); ?>
			</h3>

			<div id="wp-mcp-ai-sm-presets-panel" class="wp-mcp-ai-sm-presets-panel" style="display:none;">
				<p class="description">
					<?php esc_html_e( 'Browse pre-configured schedule presets organised by toolkit and category. Click Install to create a ready-made schedule.', 'mcp-ai-wpoos-pro' ); ?>
				</p>

				<div class="wp-mcp-ai-sm-presets-filters">
					<select id="wp-mcp-ai-sm-preset-category" class="wp-mcp-ai-sm-filter">
						<option value=""><?php esc_html_e( 'All Categories', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $categories as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="wp-mcp-ai-sm-preset-toolkit" class="wp-mcp-ai-sm-filter">
						<option value=""><?php esc_html_e( 'All Toolkits', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
					<input type="search" id="wp-mcp-ai-sm-preset-search" class="wp-mcp-ai-sm-filter" placeholder="<?php esc_attr_e( 'Search presets…', 'mcp-ai-wpoos-pro' ); ?>">
				</div>

				<div id="wp-mcp-ai-sm-presets-grid" class="wp-mcp-ai-sm-presets-grid">
					<!-- Populated via JavaScript -->
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Return schedule presets (optionally filtered by category or toolkit).
	 */
	public function ajax_get_presets() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-presets.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_request().
		$category = isset( $_POST['category'] ) ? sanitize_key( $_POST['category'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$toolkit = isset( $_POST['toolkit'] ) ? sanitize_key( $_POST['toolkit'] ) : '';

		if ( '' !== $category ) {
			$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets_by_category( $category );
		} elseif ( '' !== $toolkit ) {
			$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets_by_toolkit( $toolkit );
		} else {
			$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets();
		}

		$output = array();
		foreach ( $presets as $id => $preset ) {
			$output[] = array(
				'id'            => $id,
				'name'          => $preset['name'],
				'description'   => $preset['description'],
				'toolkit'       => isset( $preset['toolkit'] ) ? $preset['toolkit'] : '',
				'category'      => isset( $preset['category'] ) ? $preset['category'] : '',
				'icon'          => isset( $preset['icon'] ) ? $preset['icon'] : 'dashicons-clock',
				'schedule_type' => isset( $preset['schedule_type'] ) ? $preset['schedule_type'] : 'task',
				'schedule'      => isset( $preset['schedule'] ) ? $preset['schedule'] : 'daily',
				'tags'          => isset( $preset['tags'] ) ? $preset['tags'] : array(),
			);
		}

		$categories = WP_MCP_AI_Pro_Schedule_Presets::get_categories();

		wp_send_json_success(
			array(
				'presets'    => $output,
				'categories' => $categories,
			)
		);
	}

	/**
	 * AJAX: Install a schedule preset.
	 */
	public function ajax_install_preset() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-presets.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_request().
		$preset_id = isset( $_POST['preset_id'] ) ? sanitize_key( $_POST['preset_id'] ) : '';

		if ( '' === $preset_id ) {
			wp_send_json_error( array( 'message' => __( 'No preset ID provided.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Collect optional overrides for types that require user-supplied values.
		$overrides = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_request().
		if ( ! empty( $_POST['assistant_id'] ) ) {
			$aid = absint( $_POST['assistant_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $aid && 'mcp_ai_assistant' === get_post_type( $aid ) && 'publish' === get_post_status( $aid ) ) {
				$overrides['assistant_id'] = $aid;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_request().
		if ( ! empty( $_POST['credentials'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified via verify_request(); raw JSON decoded and type-checked below.
			$raw_creds = wp_unslash( $_POST['credentials'] );
			if ( is_string( $raw_creds ) ) {
				$decoded = json_decode( $raw_creds, true );
				if ( is_array( $decoded ) ) {
					$overrides['credentials'] = $decoded;
				}
			} elseif ( is_array( $raw_creds ) ) {
				$overrides['credentials'] = $raw_creds;
			}
		}

		$result = WP_MCP_AI_Pro_Schedule_Presets::install_preset( $preset_id, get_current_user_id(), $overrides );

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
				'message'     => __( 'Preset installed successfully.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}
}
