<?php
/**
 * Research & Add Schedule admin page.
 *
 * Provides a chat-driven UI under the NV oOS Pro Dashboard where a user can
 * paste in a free-form list of recurring responsibilities and have the AI
 * turn each line into a managed Pro Schedule via the
 * `plan_schedules_from_workflow` tool.
 *
 * Mirrors the structure of WP_MCP_AI_Task_Research_Page.
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

if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Research_Page' ) ) {

	/**
	 * Standalone admin page: Research & Add Schedule.
	 */
	class WP_MCP_AI_Pro_Schedule_Research_Page {

		/**
		 * Page slug.
		 */
		const PAGE_SLUG = 'research-pro-schedule';

		/**
		 * Stored hook suffix returned by add_submenu_page().
		 *
		 * @var string
		 */
		protected static $page_hook = '';

		/**
		 * Initialize hooks.
		 */
		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 27 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_action( 'wp_ajax_wp_mcp_ai_preview_schedule_from_research', array( __CLASS__, 'ajax_preview' ) );
			add_action( 'wp_ajax_wp_mcp_ai_create_schedule_from_research', array( __CLASS__, 'ajax_create' ) );
			add_action( 'wp_ajax_wp_mcp_ai_dry_run_schedule_from_research', array( __CLASS__, 'ajax_dry_run' ) );
			add_action( 'wp_ajax_wp_mcp_ai_toggle_schedule_from_research', array( __CLASS__, 'ajax_toggle' ) );
			add_action( 'wp_ajax_wp_mcp_ai_run_now_schedule_from_research', array( __CLASS__, 'ajax_run_now' ) );
			add_action( 'wp_ajax_wp_mcp_ai_run_history_from_research', array( __CLASS__, 'ajax_run_history' ) );
		}

		/**
		 * Register the submenu page under NV oOS Pro Dashboard.
		 */
		public static function add_menu_page() {
			self::$page_hook = (string) add_submenu_page(
				'nvoos-pro-dashboard',
				__( 'Research & Add Schedule', 'mcp-ai-wpoos-pro' ),
				__( 'Research & Add Schedule', 'mcp-ai-wpoos-pro' ),
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * Enqueue assets only on this page.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public static function enqueue_assets( $hook ) {
			if ( empty( self::$page_hook ) || $hook !== self::$page_hook ) {
				return;
			}

			// Chat shortcode assets.
			if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
				$shortcode = new WP_MCP_AI_Shortcode();
				if ( method_exists( $shortcode, 'register_assets' ) ) {
					$shortcode->register_assets();
				}
				if ( defined( 'WP_MCP_AI_Shortcode::STYLE_HANDLE' ) ) {
					wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
				}
				if ( defined( 'WP_MCP_AI_Shortcode::SCRIPT_HANDLE' ) ) {
					wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
				}
			}

			// Reuse existing enhanced research page styles.
			$css_path = WP_MCP_AI_PATH . 'assets/css/enhanced-research-page.css';
			if ( file_exists( $css_path ) ) {
				wp_enqueue_style(
					'wp-mcp-ai-enhanced-research-page',
					WP_MCP_AI_URL . 'assets/css/enhanced-research-page.css',
					array(),
					defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : false
				);
			}

			// Reuse the shared workflow-card click handler from the base plugin.
			$js_path = WP_MCP_AI_PATH . 'assets/js/enhanced-research-page.js';
			if ( file_exists( $js_path ) ) {
				wp_enqueue_script(
					'wp-mcp-ai-enhanced-research-page',
					WP_MCP_AI_URL . 'assets/js/enhanced-research-page.js',
					array( 'jquery' ),
					defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : false,
					true
				);

				// Map legacy ?mode= query-arg → data-workflow card so old bookmarks land on the right card.
				$legacy_mode  = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$mode_to_card = array(
					'chat'     => 'research',
					'paste'    => 'import',
					'review'   => 'review',
					'calendar' => 'calendar',
				);
				$initial_card = isset( $mode_to_card[ $legacy_mode ] ) ? $mode_to_card[ $legacy_mode ] : '';
				wp_localize_script(
					'wp-mcp-ai-enhanced-research-page',
					'wpMcpAiResearchPage',
					array(
						'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
						'nonce'           => wp_create_nonce( 'wp_mcp_ai_research_pro_schedule' ),
						'entityType'      => 'pro_schedule',
						'initialWorkflow' => $initial_card,
					)
				);
			}

			// Inline JS handles preview/create AJAX for the bulk paste mode.
			wp_register_script(
				'wp-mcp-ai-pro-schedule-research-page',
				'',
				array( 'jquery' ),
				defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : false,
				true
			);
			wp_enqueue_script( 'wp-mcp-ai-pro-schedule-research-page' );
			wp_localize_script(
				'wp-mcp-ai-pro-schedule-research-page',
				'wpMcpAiScheduleResearch',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp_mcp_ai_research_pro_schedule' ),
					'i18n'    => array(
						'previewing'      => __( 'Generating preview…', 'mcp-ai-wpoos-pro' ),
						'creating'        => __( 'Creating schedules…', 'mcp-ai-wpoos-pro' ),
						'noItems'         => __( 'Please paste at least one workflow item.', 'mcp-ai-wpoos-pro' ),
						'errorPrefix'     => __( 'Error: ', 'mcp-ai-wpoos-pro' ),
						'dryRunning'      => __( 'Running dry-run…', 'mcp-ai-wpoos-pro' ),
						'noNextRuns'      => __( 'No upcoming runs projected.', 'mcp-ai-wpoos-pro' ),
						'warnings'        => __( 'Warnings:', 'mcp-ai-wpoos-pro' ),
						'nextRuns'        => __( 'Next runs:', 'mcp-ai-wpoos-pro' ),
						'pausing'         => __( 'Pausing…', 'mcp-ai-wpoos-pro' ),
						'resuming'        => __( 'Resuming…', 'mcp-ai-wpoos-pro' ),
						'running'         => __( 'Running…', 'mcp-ai-wpoos-pro' ),
						'paused'          => __( 'Paused.', 'mcp-ai-wpoos-pro' ),
						'resumed'         => __( 'Resumed.', 'mcp-ai-wpoos-pro' ),
						'ranOk'           => __( 'Run completed successfully.', 'mcp-ai-wpoos-pro' ),
						'pauseLabel'      => __( 'Pause', 'mcp-ai-wpoos-pro' ),
						'resumeLabel'     => __( 'Resume', 'mcp-ai-wpoos-pro' ),
						'confirmRun'      => __( 'Trigger this schedule to run immediately?', 'mcp-ai-wpoos-pro' ),
						'loadingHistory'  => __( 'Loading run history…', 'mcp-ai-wpoos-pro' ),
						'noHistory'       => __( 'No run history recorded yet.', 'mcp-ai-wpoos-pro' ),
						'runSuccess'      => __( '✓ Success', 'mcp-ai-wpoos-pro' ),
						'runFailed'       => __( '✗ Failed', 'mcp-ai-wpoos-pro' ),
						'historyDuration' => __( 'Duration', 'mcp-ai-wpoos-pro' ),
					),
				)
			);
			wp_add_inline_script(
				'wp-mcp-ai-pro-schedule-research-page',
				self::get_inline_script()
			);
		}

		/**
		 * Render the admin page.
		 */
		public static function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos-pro' ) );
			}

			// Get assistant from toolkit settings or first available.
			$settings     = get_option( 'wp_mcp_ai_pro_schedule_toolkit_settings', array() );
			$assistant_id = isset( $settings['research_assistant_id'] ) ? absint( $settings['research_assistant_id'] ) : 0;

			if ( ! $assistant_id || 'publish' !== get_post_status( $assistant_id ) ) {
				$assistants   = get_posts(
					array(
						'post_type'      => 'mcp_ai_assistant',
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'orderby'        => 'date',
						'order'          => 'DESC',
					)
				);
				$assistant_id = ! empty( $assistants ) ? (int) $assistants[0]->ID : 0;
			}

			$settings_url = admin_url( 'admin.php?page=wp-mcp-ai-pro-schedule-toolkit-settings' );
			$manager_url  = admin_url( 'admin.php?page=nvoos-pro-schedule-manager' );

			?>
			<div class="wrap wp-mcp-ai-research-page wp-mcp-ai-pro-schedule-research-page">
				<h1 class="wp-heading-inline">
					<span class="dashicons dashicons-clock" style="font-size:28px;width:28px;height:28px;vertical-align:middle;margin-right:8px;color:#2271b1;"></span>
					<?php esc_html_e( 'Research & Add Schedule', 'mcp-ai-wpoos-pro' ); ?>
					<span style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:4px 12px;border-radius:12px;font-size:11px;font-weight:600;margin-left:10px;text-transform:uppercase;letter-spacing:.5px;">PRO</span>
				</h1>
				<a href="<?php echo esc_url( $manager_url ); ?>" class="page-title-action">
					<?php esc_html_e( 'Open Schedule Manager', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="page-title-action">
					<?php esc_html_e( 'Schedule Settings', 'mcp-ai-wpoos-pro' ); ?>
				</a>

				<hr class="wp-header-end">

				<div class="wp-mcp-ai-research-container">
					<div class="wp-mcp-ai-research-sidebar">
						<div class="wp-mcp-ai-research-intro">
							<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
							<ol>
								<li><?php esc_html_e( 'Pick a workflow card — AI Research, Bulk Import, or Review & Run History.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'AI normalizes cadence and time-of-day for each item.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Preview the plan and adjust any cadences.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Create — each line becomes a managed Pro Schedule.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
						</div>

						<div class="wp-mcp-ai-research-tips">
							<h3><?php esc_html_e( 'Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
							<ul>
								<li><strong><?php esc_html_e( 'One per line:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Each non-empty line becomes one schedule.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><strong><?php esc_html_e( 'Frequency hints:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Include words like "daily", "weekly", "monthly" — the AI will detect them.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><strong><?php esc_html_e( 'Group by category:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Use "## Heading" lines to tag the items that follow.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><strong><?php esc_html_e( 'Pick an assistant:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Select an assistant to run each schedule, or leave blank for plain reminders.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ul>
						</div>

						<div class="wp-mcp-ai-research-actions">
							<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
							<p>
								<a href="<?php echo esc_url( $manager_url ); ?>" class="button">
									<?php esc_html_e( 'Schedule Manager', 'mcp-ai-wpoos-pro' ); ?>
								</a>
							</p>
							<p>
								<a href="<?php echo esc_url( $settings_url ); ?>" class="button">
									<?php esc_html_e( 'Schedule Settings', 'mcp-ai-wpoos-pro' ); ?>
								</a>
							</p>
							<p>
								<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>" class="button">
									<?php esc_html_e( 'Manage Assistants', 'mcp-ai-wpoos-pro' ); ?>
								</a>
							</p>
						</div>
					</div>

					<div class="wp-mcp-ai-research-main">
						<!-- Workflow Mode Selector -->
						<div class="wp-mcp-ai-workflow-selector">
							<h2><?php esc_html_e( 'Choose Your Workflow', 'mcp-ai-wpoos-pro' ); ?></h2>
							<div class="workflow-options">
								<button type="button" class="workflow-option active" data-workflow="research">
									<span class="dashicons dashicons-format-chat"></span>
									<strong><?php esc_html_e( 'AI Research', 'mcp-ai-wpoos-pro' ); ?></strong>
									<p><?php esc_html_e( 'Plan schedules with AI assistance.', 'mcp-ai-wpoos-pro' ); ?></p>
								</button>
								<button type="button" class="workflow-option" data-workflow="import">
									<span class="dashicons dashicons-upload"></span>
									<strong><?php esc_html_e( 'Bulk Import', 'mcp-ai-wpoos-pro' ); ?></strong>
									<p><?php esc_html_e( 'Paste a list of recurring responsibilities.', 'mcp-ai-wpoos-pro' ); ?></p>
								</button>
								<button type="button" class="workflow-option" data-workflow="review">
									<span class="dashicons dashicons-analytics"></span>
									<strong><?php esc_html_e( 'Review & Run History', 'mcp-ai-wpoos-pro' ); ?></strong>
									<p><?php esc_html_e( 'Inspect schedules created from this workflow.', 'mcp-ai-wpoos-pro' ); ?></p>
								</button>
								<button type="button" class="workflow-option" data-workflow="calendar">
									<span class="dashicons dashicons-calendar-alt"></span>
									<strong><?php esc_html_e( 'Calendar', 'mcp-ai-wpoos-pro' ); ?></strong>
									<p><?php esc_html_e( 'Preview the next upcoming runs at a glance.', 'mcp-ai-wpoos-pro' ); ?></p>
								</button>
							</div>
						</div>

						<!-- AI Research Workflow (Default) -->
						<div id="workflow-research" class="workflow-content active">
							<?php self::render_chat_mode( $assistant_id ); ?>
						</div>

						<!-- Bulk Import Workflow -->
						<div id="workflow-import" class="workflow-content">
							<?php self::render_paste_mode( $assistant_id ); ?>
						</div>

						<!-- Review Workflow -->
						<div id="workflow-review" class="workflow-content">
							<?php self::render_review_mode(); ?>
						</div>

						<!-- Calendar Workflow -->
						<div id="workflow-calendar" class="workflow-content">
							<?php self::render_calendar_mode(); ?>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render the AI chat mode.
		 *
		 * @param int $assistant_id Assistant post ID.
		 */
		protected static function render_chat_mode( $assistant_id ) {
			if ( $assistant_id <= 0 ) {
				?>
				<div class="notice notice-error">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: link to create assistant */
								__( 'No AI assistant found. Please <a href="%s">create an assistant</a> first.', 'mcp-ai-wpoos-pro' ),
								esc_url( admin_url( 'post-new.php?post_type=mcp_ai_assistant' ) )
							)
						);
						?>
					</p>
				</div>
				<?php
				return;
			}

			$schedule_tools = array(
				'plan_schedules_from_workflow',
				'create_pro_schedule',
				'list_pro_schedules',
				'update_pro_schedule',
				'delete_pro_schedule',
				'get_schedule_run_history',
				'list_assistants',
				// Research → Paper Store pipeline.
				'generate_research_report',
				'create_post_from_research',
				'web_search',
			);

			?>
			<div class="wp-mcp-ai-research-chat">
				<p class="description"><?php esc_html_e( 'Describe the recurring responsibilities you want to schedule. The assistant can use the plan_schedules_from_workflow tool to turn your list into managed Pro Schedules.', 'mcp-ai-wpoos-pro' ); ?></p>
				<?php
				echo do_shortcode(
					'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="' . esc_attr( implode( ',', $schedule_tools ) ) . '"]'
				);
				?>
			</div>
			<?php
		}

		/**
		 * Render the bulk paste mode.
		 *
		 * @param int $assistant_id Default assistant post ID.
		 */
		protected static function render_paste_mode( $assistant_id ) {
			$assistants = get_posts(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			$cadences = array_merge( array( 'single' ), array_keys( wp_get_schedules() ) );
			$cadences = array_unique( $cadences );
			sort( $cadences );

			$example = "Respond to emails\nCheck WhatsApp messages on internal groups\nFollow up with the team on pending tasks\nReview daily sales updates\nWeekly sales updates";

			?>
			<div class="wp-mcp-ai-bulk-paste-mode" style="background:#fff;padding:20px;border:1px solid #c3c4c7;border-radius:4px;">
				<h2><?php esc_html_e( 'Bulk Paste Workflow', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Paste recurring responsibilities below — one per line. Optionally insert "## Category" headings to tag groups.', 'mcp-ai-wpoos-pro' ); ?></p>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="schedule-research-text"><?php esc_html_e( 'Workflow Items', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td>
								<textarea id="schedule-research-text" rows="12" cols="60" class="large-text code" placeholder="<?php echo esc_attr( $example ); ?>"></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="schedule-research-category"><?php esc_html_e( 'Default Category', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td>
								<input type="text" id="schedule-research-category" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. marketing, mass-market, admin', 'mcp-ai-wpoos-pro' ); ?>">
								<p class="description"><?php esc_html_e( 'Used as a tag for every created schedule.', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="schedule-research-cadence"><?php esc_html_e( 'Default Cadence', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td>
								<select id="schedule-research-cadence">
									<?php foreach ( $cadences as $slug ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, 'daily' ); ?>><?php echo esc_html( $slug ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Used when an item gives no cadence hint.', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="schedule-research-time"><?php esc_html_e( 'Default Time (24h)', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td>
								<input type="time" id="schedule-research-time" value="09:00">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="schedule-research-assistant"><?php esc_html_e( 'Default Assistant', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td>
								<select id="schedule-research-assistant">
									<option value="0"><?php esc_html_e( '— None (use plain reminder hook) —', 'mcp-ai-wpoos-pro' ); ?></option>
									<?php foreach ( $assistants as $a ) : ?>
										<option value="<?php echo esc_attr( $a->ID ); ?>" <?php selected( $a->ID, $assistant_id ); ?>>
											<?php echo esc_html( $a->post_title ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'When set, each schedule fires this assistant with the item as its instruction.', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<button type="button" class="button" id="schedule-research-preview">
						<?php esc_html_e( 'Preview Plan', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<button type="button" class="button button-primary" id="schedule-research-create" disabled>
						<?php esc_html_e( 'Create Schedules', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<span class="spinner" id="schedule-research-spinner" style="float:none;"></span>
				</p>

				<div id="schedule-research-results" aria-live="polite"></div>
			</div>
			<?php
		}

		/**
		 * Render the review mode (recently created schedules).
		 */
		protected static function render_review_mode() {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
				echo '<p>' . esc_html__( 'Schedule Manager is not available.', 'mcp-ai-wpoos-pro' ) . '</p>';
				return;
			}

			$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules(
				array( 'tag' => 'planned-from-workflow' )
			);

			if ( empty( $schedules ) ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No schedules have been created from this page yet.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
				return;
			}

			?>
			<div class="wp-mcp-ai-review-mode" style="background:#fff;padding:20px;border:1px solid #c3c4c7;border-radius:4px;">
				<h2><?php esc_html_e( 'Schedules Created From Workflow', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Cadence', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Tags', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Last Run', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $schedules as $sch ) : ?>
							<?php $sch_id = isset( $sch['id'] ) ? (string) $sch['id'] : ''; ?>
							<tr>
								<td><?php echo esc_html( isset( $sch['name'] ) ? $sch['name'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $sch['schedule'] ) ? $sch['schedule'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $sch['schedule_type'] ) ? $sch['schedule_type'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $sch['tags'] ) && is_array( $sch['tags'] ) ? implode( ', ', $sch['tags'] ) : '' ); ?></td>
								<td><?php echo esc_html( ! empty( $sch['enabled'] ) ? __( 'Enabled', 'mcp-ai-wpoos-pro' ) : __( 'Disabled', 'mcp-ai-wpoos-pro' ) ); ?></td>
								<td>
									<?php
									$last = isset( $sch['last_run_time'] ) ? (int) $sch['last_run_time'] : 0;
									echo esc_html( $last > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) : __( 'Never', 'mcp-ai-wpoos-pro' ) );
									?>
								</td>
								<td>
									<button type="button" class="button button-small wp-mcp-ai-dry-run-button" data-schedule-id="<?php echo esc_attr( $sch_id ); ?>">
										<?php esc_html_e( 'Dry-run', 'mcp-ai-wpoos-pro' ); ?>
									</button>
									<button type="button" class="button button-small wp-mcp-ai-toggle-button" data-schedule-id="<?php echo esc_attr( $sch_id ); ?>" data-enabled="<?php echo ! empty( $sch['enabled'] ) ? '1' : '0'; ?>">
										<?php echo esc_html( ! empty( $sch['enabled'] ) ? __( 'Pause', 'mcp-ai-wpoos-pro' ) : __( 'Resume', 'mcp-ai-wpoos-pro' ) ); ?>
									</button>
									<button type="button" class="button button-small wp-mcp-ai-run-now-button" data-schedule-id="<?php echo esc_attr( $sch_id ); ?>">
										<?php esc_html_e( 'Run now', 'mcp-ai-wpoos-pro' ); ?>
									</button>
									<button type="button" class="button button-small wp-mcp-ai-history-button" data-schedule-id="<?php echo esc_attr( $sch_id ); ?>">
										<?php esc_html_e( 'History', 'mcp-ai-wpoos-pro' ); ?>
									</button>
								</td>
							</tr>
							<?php if ( '' !== $sch_id ) : ?>
								<tr class="wp-mcp-ai-dry-run-result" id="wp-mcp-ai-dry-run-result-<?php echo esc_attr( $sch_id ); ?>" style="display:none;">
									<td colspan="7"></td>
								</tr>
								<tr class="wp-mcp-ai-run-history-row" id="wp-mcp-ai-run-history-row-<?php echo esc_attr( $sch_id ); ?>" style="display:none;">
									<td colspan="7"></td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		}

		/**
		 * AJAX handler — preview (dry_run=true).
		 */
		public static function ajax_preview() {
			self::handle_ajax( true );
		}

		/**
		 * AJAX handler — create (dry_run=false).
		 */
		public static function ajax_create() {
			self::handle_ajax( false );
		}

		/**
		 * AJAX handler — dry-run an existing schedule. Returns the new
		 * dry_run_pro_schedule tool's payload so the review-mode UI can show
		 * upcoming runs + warnings inline.
		 */
		public static function ajax_dry_run() {
			check_ajax_referer( 'wp_mcp_ai_research_pro_schedule', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to inspect schedules.', 'mcp-ai-wpoos-pro' ) ),
					403
				);
			}

			$schedule_id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';
			if ( '' === $schedule_id ) {
				wp_send_json_error( array( 'message' => __( 'A schedule_id is required.', 'mcp-ai-wpoos-pro' ) ), 400 );
			}

			$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-dry-run-pro-schedule.php';
			if ( file_exists( $tool_file ) ) {
				require_once $tool_file;
			}
			if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule' ) ) {
				wp_send_json_error( array( 'message' => __( 'Dry-run tool is not available.', 'mcp-ai-wpoos-pro' ) ), 500 );
			}

			$tool   = new WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule();
			$result = $tool->execute(
				array(
					'schedule_id' => $schedule_id,
					'count'       => 5,
				),
				array( 'user_id' => get_current_user_id() )
			);

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}

			wp_send_json_success( $result );
		}

		/**
		 * AJAX handler — pause or resume a schedule. Posts `enabled=1|0`.
		 */
		public static function ajax_toggle() {
			check_ajax_referer( 'wp_mcp_ai_research_pro_schedule', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to modify schedules.', 'mcp-ai-wpoos-pro' ) ),
					403
				);
			}
			if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
				wp_send_json_error( array( 'message' => __( 'Schedule Manager is not available.', 'mcp-ai-wpoos-pro' ) ), 500 );
			}

			$schedule_id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';
			$enabled     = isset( $_POST['enabled'] ) ? (bool) absint( $_POST['enabled'] ) : false;
			if ( '' === $schedule_id ) {
				wp_send_json_error( array( 'message' => __( 'A schedule_id is required.', 'mcp-ai-wpoos-pro' ) ), 400 );
			}

			$result = WP_MCP_AI_Pro_Schedule_Manager::toggle_schedule( $schedule_id, $enabled, get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}

			wp_send_json_success(
				array(
					'schedule_id' => $schedule_id,
					'enabled'     => $enabled,
				)
			);
		}

		/**
		 * AJAX handler — manually trigger a schedule via Schedule_Manager::trigger_now().
		 */
		public static function ajax_run_now() {
			check_ajax_referer( 'wp_mcp_ai_research_pro_schedule', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to run schedules.', 'mcp-ai-wpoos-pro' ) ),
					403
				);
			}
			if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
				wp_send_json_error( array( 'message' => __( 'Schedule Manager is not available.', 'mcp-ai-wpoos-pro' ) ), 500 );
			}

			$schedule_id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';
			if ( '' === $schedule_id ) {
				wp_send_json_error( array( 'message' => __( 'A schedule_id is required.', 'mcp-ai-wpoos-pro' ) ), 400 );
			}

			$result = WP_MCP_AI_Pro_Schedule_Manager::trigger_now( $schedule_id, get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}

			wp_send_json_success( array( 'schedule_id' => $schedule_id ) );
		}

		/**
		 * AJAX handler — return the run-history ring buffer for a schedule.
		 *
		 * Calls the `get_schedule_run_history` tool (via registry or direct
		 * instantiation) and returns the result as JSON.
		 */
		public static function ajax_run_history() {
			check_ajax_referer( 'wp_mcp_ai_research_pro_schedule', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to view run history.', 'mcp-ai-wpoos-pro' ) ),
					403
				);
			}

			$schedule_id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';
			if ( '' === $schedule_id ) {
				wp_send_json_error( array( 'message' => __( 'A schedule_id is required.', 'mcp-ai-wpoos-pro' ) ), 400 );
			}

			$args    = array( 'schedule_id' => $schedule_id );
			$context = array( 'user_id' => get_current_user_id() );
			$result  = null;

			// Prefer the registry so lifecycle hooks fire.
			if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				$registry = WP_MCP_AI_Tool_Registry::get_instance();
				if ( method_exists( $registry, 'execute_tool' ) ) {
					$result = $registry->execute_tool( 'get_schedule_run_history', $args, $context );
				}
			}

			if ( null === $result ) {
				$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-get-schedule-run-history.php';
				if ( file_exists( $tool_file ) ) {
					require_once $tool_file;
				}
				if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Get_Schedule_Run_History' ) ) {
					wp_send_json_error( array( 'message' => __( 'Run history tool is not available.', 'mcp-ai-wpoos-pro' ) ), 500 );
				}
				$tool   = new WP_MCP_AI_Pro_Tool_Get_Schedule_Run_History();
				$result = $tool->execute( $args, $context );
			}

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}

			wp_send_json_success( $result );
		}

		/**
		 * Shared AJAX dispatcher.
		 *
		 * @param bool $dry_run Whether to call the tool in dry_run mode.
		 */
		protected static function handle_ajax( $dry_run ) {
			check_ajax_referer( 'wp_mcp_ai_research_pro_schedule', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to plan schedules.', 'mcp-ai-wpoos-pro' ) )
				);
			}

			$workflow_text = isset( $_POST['workflow_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['workflow_text'] ) ) : '';
			$category      = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
			$cadence       = isset( $_POST['default_cadence'] ) ? sanitize_key( wp_unslash( $_POST['default_cadence'] ) ) : 'daily';
			$time          = isset( $_POST['default_time'] ) ? sanitize_text_field( wp_unslash( $_POST['default_time'] ) ) : '09:00';
			$assistant_id  = isset( $_POST['default_assistant_id'] ) ? absint( $_POST['default_assistant_id'] ) : 0;

			if ( '' === trim( $workflow_text ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Please paste at least one workflow item.', 'mcp-ai-wpoos-pro' ) )
				);
			}

			$args = array(
				'workflow_text'        => $workflow_text,
				'category'             => $category,
				'default_cadence'      => $cadence,
				'default_time'         => $time,
				'default_assistant_id' => $assistant_id,
				'dry_run'              => (bool) $dry_run,
			);

			$context = array( 'user_id' => get_current_user_id() );

			$result = null;

			// Prefer the registry so before/after hooks fire.
			if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				$registry = WP_MCP_AI_Tool_Registry::get_instance();
				if ( method_exists( $registry, 'execute_tool' ) ) {
					$result = $registry->execute_tool( 'plan_schedules_from_workflow', $args, $context );
				}
			}

			if ( null === $result ) {
				if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Plan_Schedules_From_Workflow' ) ) {
					require_once WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-plan-schedules-from-workflow.php';
				}
				$tool   = new WP_MCP_AI_Pro_Tool_Plan_Schedules_From_Workflow();
				$result = $tool->execute( $args, $context );
			}

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success( $result );
		}

		/**
		 * Render the Calendar mode — a flat list of the next upcoming runs across
		 * every enabled schedule, sorted ascending. Provides the at-a-glance
		 * timeline view called for by the toolkit blueprint.
		 */
		protected static function render_calendar_mode() {
			$rows = array();

			if ( class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' )
				&& method_exists( 'WP_MCP_AI_Pro_Schedule_Manager', 'get_schedules' )
				&& method_exists( 'WP_MCP_AI_Pro_Schedule_Manager', 'get_next_run_times' )
			) {
				$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules( array( 'enabled' => true ) );
				if ( is_array( $schedules ) ) {
					foreach ( $schedules as $sch ) {
						$schedule_id = isset( $sch['id'] ) ? (string) $sch['id'] : '';
						if ( '' === $schedule_id ) {
							continue;
						}
						$times = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_times( $schedule_id, 5 );
						foreach ( $times as $when ) {
							$rows[] = array(
								'when'    => (int) $when,
								'name'    => isset( $sch['name'] ) ? (string) $sch['name'] : $schedule_id,
								'cadence' => isset( $sch['schedule'] ) ? (string) $sch['schedule'] : '',
							);
						}
					}
				}
			}

			usort(
				$rows,
				static function ( $a, $b ) {
					return $a['when'] <=> $b['when'];
				}
			);
			$rows     = array_slice( $rows, 0, 30 );
			$date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			$tz       = wp_timezone_string();
			?>
			<div class="wp-mcp-ai-research-section">
				<h2><?php esc_html_e( 'Upcoming Runs', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: site timezone string. */
						esc_html__( 'Next 30 upcoming runs across all enabled schedules. Times are shown in the site timezone (%s).', 'mcp-ai-wpoos-pro' ),
						esc_html( $tz )
					);
					?>
				</p>
				<?php if ( empty( $rows ) ) : ?>
					<p><em><?php esc_html_e( 'No upcoming runs are scheduled yet.', 'mcp-ai-wpoos-pro' ); ?></em></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'When', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Schedule', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Cadence', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( $date_fmt, $row['when'] ) ); ?></td>
									<td><?php echo esc_html( $row['name'] ); ?></td>
									<td><code><?php echo esc_html( $row['cadence'] ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Inline JavaScript for the bulk paste mode.
		 *
		 * @return string Script source.
		 */
		protected static function get_inline_script() {
			return '(function($){
	if (typeof window.wpMcpAiScheduleResearch === \'undefined\') { return; }
	var cfg = window.wpMcpAiScheduleResearch;

	function escapeHtml(text){
		return String(text == null ? \'\' : text).replace(/[&<>"\']/g, function(c){
			var map = {\'&\':\'&amp;\',\'<\':\'&lt;\',\'>\':\'&gt;\',\'"\':\'&quot;\',\'\\\'\':\'&#39;\'};
			return map[c];
		});
	}

	function collectArgs(){
		return {
			workflow_text: $(\'#schedule-research-text\').val() || \'\',
			category: $(\'#schedule-research-category\').val() || \'\',
			default_cadence: $(\'#schedule-research-cadence\').val() || \'daily\',
			default_time: $(\'#schedule-research-time\').val() || \'09:00\',
			default_assistant_id: parseInt($(\'#schedule-research-assistant\').val(), 10) || 0
		};
	}

	function renderResult(data){
		var $out = $(\'#schedule-research-results\');
		$out.empty();
		if (!data) return;
		var summary = data.summary || {};
		var rows = (data.plan && data.plan.length) ? data.plan : (data.created || []);
		var html = \'<h3>\' + escapeHtml(data.message || \'\') + \'</h3>\';
		html += \'<p>\' + escapeHtml(\'Total: \' + (summary.total || 0) + \' · Planned: \' + (summary.planned || 0) + \' · Created: \' + (summary.created || 0) + \' · Errors: \' + (summary.errors || 0)) + \'</p>\';
		if (rows.length){
			html += \'<table class="widefat striped"><thead><tr><th>Name</th><th>Cadence</th><th>Type</th><th>Tags</th></tr></thead><tbody>\';
			rows.forEach(function(r){
				html += \'<tr>\';
				html += \'<td>\' + escapeHtml(r.name || \'\') + \'</td>\';
				html += \'<td>\' + escapeHtml(r.schedule || r.cadence || \'\') + \'</td>\';
				html += \'<td>\' + escapeHtml(r.schedule_type || \'\') + \'</td>\';
				html += \'<td>\' + escapeHtml((r.tags || []).join(\', \')) + \'</td>\';
				html += \'</tr>\';
			});
			html += \'</tbody></table>\';
		}
		if (data.errors && data.errors.length){
			html += \'<h4>\' + escapeHtml(\'Errors\') + \'</h4><ul>\';
			data.errors.forEach(function(e){
				html += \'<li>\' + escapeHtml(e.message || \'\') + \'</li>\';
			});
			html += \'</ul>\';
		}
		$out.html(html);
	}

	$(document).on(\'click\', \'#schedule-research-preview\', function(e){
		e.preventDefault();
		var args = collectArgs();
		if (!args.workflow_text.trim()){
			alert(cfg.i18n.noItems);
			return;
		}
		var $sp = $(\'#schedule-research-spinner\').addClass(\'is-active\');
		$(\'#schedule-research-create\').prop(\'disabled\', true);
		$(\'#schedule-research-results\').html(\'<p>\' + escapeHtml(cfg.i18n.previewing) + \'</p>\');
		args.action = \'wp_mcp_ai_preview_schedule_from_research\';
		args.nonce = cfg.nonce;
		$.post(cfg.ajaxUrl, args).done(function(resp){
			$sp.removeClass(\'is-active\');
			if (resp && resp.success){
				renderResult(resp.data);
				$(\'#schedule-research-create\').prop(\'disabled\', false);
			} else {
				$(\'#schedule-research-results\').html(\'<div class="notice notice-error"><p>\' + escapeHtml(cfg.i18n.errorPrefix + ((resp && resp.data && resp.data.message) || \'Unknown\')) + \'</p></div>\');
			}
		}).fail(function(){
			$sp.removeClass(\'is-active\');
			$(\'#schedule-research-results\').html(\'<div class="notice notice-error"><p>\' + escapeHtml(cfg.i18n.errorPrefix + \'Request failed\') + \'</p></div>\');
		});
	});

	$(document).on(\'click\', \'#schedule-research-create\', function(e){
		e.preventDefault();
		var args = collectArgs();
		if (!args.workflow_text.trim()){
			alert(cfg.i18n.noItems);
			return;
		}
		var $sp = $(\'#schedule-research-spinner\').addClass(\'is-active\');
		$(\'#schedule-research-results\').append(\'<p>\' + escapeHtml(cfg.i18n.creating) + \'</p>\');
		args.action = \'wp_mcp_ai_create_schedule_from_research\';
		args.nonce = cfg.nonce;
		$.post(cfg.ajaxUrl, args).done(function(resp){
			$sp.removeClass(\'is-active\');
			if (resp && resp.success){
				renderResult(resp.data);
				$(\'#schedule-research-create\').prop(\'disabled\', true);
			} else {
				$(\'#schedule-research-results\').append(\'<div class="notice notice-error"><p>\' + escapeHtml(cfg.i18n.errorPrefix + ((resp && resp.data && resp.data.message) || \'Unknown\')) + \'</p></div>\');
			}
		}).fail(function(){
			$sp.removeClass(\'is-active\');
			$(\'#schedule-research-results\').append(\'<div class="notice notice-error"><p>\' + escapeHtml(cfg.i18n.errorPrefix + \'Request failed\') + \'</p></div>\');
		});
	});
	$(document).on(\'click\', \'.wp-mcp-ai-dry-run-button\', function(e){
		e.preventDefault();
		var $btn = $(this);
		var scheduleId = $btn.data(\'schedule-id\');
		if (!scheduleId){ return; }
		var $row = $(\'#wp-mcp-ai-dry-run-result-\' + scheduleId.replace(/[^a-zA-Z0-9_-]/g, \'\'));
		// Fall back to attribute selector if data-schedule-id contains non-id-safe characters.
		if ($row.length === 0){
			$row = $(\'tr.wp-mcp-ai-dry-run-result\').filter(function(){
				return $(this).attr(\'id\') === \'wp-mcp-ai-dry-run-result-\' + scheduleId;
			});
		}
		var $cell = $row.find(\'td\').first();
		$row.show();
		$cell.html(\'<em>\' + escapeHtml(cfg.i18n.dryRunning) + \'</em>\');
		$btn.prop(\'disabled\', true);
		$.post(cfg.ajaxUrl, {
			action: \'wp_mcp_ai_dry_run_schedule_from_research\',
			nonce: cfg.nonce,
			schedule_id: scheduleId
		}).done(function(resp){
			$btn.prop(\'disabled\', false);
			if (!resp || !resp.success){
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : \'Unknown\';
				$cell.html(\'<div class="notice notice-error inline"><p>\' + escapeHtml(cfg.i18n.errorPrefix + msg) + \'</p></div>\');
				return;
			}
			var data = resp.data || {};
			var html = \'\';
			var nextRuns = Array.isArray(data.next_runs) ? data.next_runs : [];
			html += \'<p><strong>\' + escapeHtml(cfg.i18n.nextRuns) + \'</strong></p>\';
			if (nextRuns.length === 0){
				html += \'<p><em>\' + escapeHtml(cfg.i18n.noNextRuns) + \'</em></p>\';
			} else {
				html += \'<ul style="margin:0 0 8px 18px;list-style:disc;">\';
				nextRuns.forEach(function(r){
					html += \'<li><code>\' + escapeHtml(r.iso8601 || \'\') + \'</code></li>\';
				});
				html += \'</ul>\';
			}
			var warnings = Array.isArray(data.warnings) ? data.warnings : [];
			if (warnings.length){
				html += \'<p><strong>\' + escapeHtml(cfg.i18n.warnings) + \'</strong></p><ul style="margin:0 0 8px 18px;list-style:disc;color:#996800;">\';
				warnings.forEach(function(w){ html += \'<li>\' + escapeHtml(w) + \'</li>\'; });
				html += \'</ul>\';
			}
			if (data.action){
				html += \'<details><summary>\' + escapeHtml(\'Action preview (\' + (data.action.type || \'\') + \')\') + \'</summary>\';
				html += \'<pre style="margin:8px 0;padding:8px;background:#f6f7f7;border:1px solid #dcdcde;overflow:auto;">\' + escapeHtml(JSON.stringify(data.action, null, 2)) + \'</pre></details>\';
			}
			$cell.html(html);
		}).fail(function(){
			$btn.prop(\'disabled\', false);
			$cell.html(\'<div class="notice notice-error inline"><p>\' + escapeHtml(cfg.i18n.errorPrefix + \'Request failed\') + \'</p></div>\');
		});
	});

	$(document).on(\'click\', \'.wp-mcp-ai-toggle-button\', function(e){
		e.preventDefault();
		var $btn = $(this);
		var scheduleId = $btn.data(\'schedule-id\');
		if (!scheduleId){ return; }
		var enabled = String($btn.attr(\'data-enabled\')) === \'1\';
		var newEnabled = !enabled;
		var originalText = $btn.text();
		$btn.prop(\'disabled\', true).text(enabled ? cfg.i18n.pausing : cfg.i18n.resuming);
		$.post(cfg.ajaxUrl, {
			action: \'wp_mcp_ai_toggle_schedule_from_research\',
			nonce: cfg.nonce,
			schedule_id: scheduleId,
			enabled: newEnabled ? 1 : 0
		}).done(function(resp){
			$btn.prop(\'disabled\', false);
			if (!resp || !resp.success){
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : \'Unknown\';
				$btn.text(originalText);
				alert(cfg.i18n.errorPrefix + msg);
				return;
			}
			$btn.attr(\'data-enabled\', newEnabled ? \'1\' : \'0\');
			$btn.text(newEnabled ? cfg.i18n.pauseLabel : cfg.i18n.resumeLabel);
			// Update the corresponding Status cell (5th column).
			var $statusCell = $btn.closest(\'tr\').find(\'td\').eq(4);
			$statusCell.text(newEnabled ? \'Enabled\' : \'Disabled\');
		}).fail(function(){
			$btn.prop(\'disabled\', false).text(originalText);
			alert(cfg.i18n.errorPrefix + \'Request failed\');
		});
	});

	$(document).on(\'click\', \'.wp-mcp-ai-run-now-button\', function(e){
		e.preventDefault();
		var $btn = $(this);
		var scheduleId = $btn.data(\'schedule-id\');
		if (!scheduleId){ return; }
		if (!window.confirm(cfg.i18n.confirmRun)){ return; }
		var originalText = $btn.text();
		$btn.prop(\'disabled\', true).text(cfg.i18n.running);
		$.post(cfg.ajaxUrl, {
			action: \'wp_mcp_ai_run_now_schedule_from_research\',
			nonce: cfg.nonce,
			schedule_id: scheduleId
		}).done(function(resp){
			$btn.prop(\'disabled\', false).text(originalText);
			if (!resp || !resp.success){
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : \'Unknown\';
				alert(cfg.i18n.errorPrefix + msg);
				return;
			}
			alert(cfg.i18n.ranOk);
		}).fail(function(){
			$btn.prop(\'disabled\', false).text(originalText);
			alert(cfg.i18n.errorPrefix + \'Request failed\');
		});
	});

	/* ------------------------------------------------------------------ *
	 * Run-history toggle button                                            *
	 * ------------------------------------------------------------------ */
	$(document).on(\'click\', \'.wp-mcp-ai-history-button\', function(e){
		e.preventDefault();
		var $btn = $(this);
		var scheduleId = $btn.data(\'schedule-id\');
		if (!scheduleId){ return; }
		var safeId = scheduleId.replace(/[^a-zA-Z0-9_-]/g, \'\');
		var $row = $(\'#wp-mcp-ai-run-history-row-\' + safeId);
		if ($row.length === 0){
			$row = $(\'tr.wp-mcp-ai-run-history-row\').filter(function(){
				return $(this).attr(\'id\') === \'wp-mcp-ai-run-history-row-\' + scheduleId;
			});
		}
		var $cell = $row.find(\'td\').first();

		// If already loaded and visible, toggle (collapse).
		if ($row.is(\':visible\') && $cell.data(\'loaded\')){
			$row.hide();
			return;
		}

		$row.show();
		$cell.html(\'<em>\' + escapeHtml(cfg.i18n.loadingHistory) + \'</em>\');
		$btn.prop(\'disabled\', true);

		$.post(cfg.ajaxUrl, {
			action: \'wp_mcp_ai_run_history_from_research\',
			nonce: cfg.nonce,
			schedule_id: scheduleId
		}).done(function(resp){
			$btn.prop(\'disabled\', false);
			if (!resp || !resp.success){
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : \'Unknown\';
				$cell.html(\'<div class="notice notice-error inline"><p>\' + escapeHtml(cfg.i18n.errorPrefix + msg) + \'</p></div>\');
				return;
			}
			var data = resp.data || {};
			var history = Array.isArray(data.history) ? data.history : (Array.isArray(data.runs) ? data.runs : []);
			if (history.length === 0){
				$cell.html(\'<p><em>\' + escapeHtml(cfg.i18n.noHistory) + \'</em></p>\');
				$cell.data(\'loaded\', true);
				return;
			}
			var html = \'<table class="widefat striped" style="margin:8px 0;">\';
			html += \'<thead><tr>\';
			html += \'<th>\' + escapeHtml(\'When\') + \'</th>\';
			html += \'<th>\' + escapeHtml(cfg.i18n.historyDuration) + \'</th>\';
			html += \'<th>\' + escapeHtml(\'Result\') + \'</th>\';
			html += \'<th>\' + escapeHtml(\'Error\') + \'</th>\';
			html += \'</tr></thead><tbody>\';
			history.forEach(function(run){
				var when = run.time || run.run_time || run.timestamp || \'\';
				var dur  = (run.duration != null) ? (parseFloat(run.duration) * 1000).toFixed(0) + \' ms\' : (run.duration_ms != null ? run.duration_ms + \' ms\' : \'—\');
				var ok   = (run.success === true || run.success === 1 || run.success === \'1\');
				var err  = run.error || \'\';
				var errTrunc = err.length > 120 ? err.substring(0, 120) + \'…\' : err;
				html += \'<tr>\';
				html += \'<td style="white-space:nowrap;">\' + escapeHtml(when) + \'</td>\';
				html += \'<td style="white-space:nowrap;">\' + escapeHtml(dur) + \'</td>\';
				html += \'<td style="white-space:nowrap;\' + (ok ? \'color:#00a32a;\' : \'color:#d63638;\') + \'">\' + escapeHtml(ok ? cfg.i18n.runSuccess : cfg.i18n.runFailed) + \'</td>\';
				html += \'<td>\' + escapeHtml(errTrunc) + \'</td>\';
				html += \'</tr>\';
			});
			html += \'</tbody></table>\';
			$cell.html(html);
			$cell.data(\'loaded\', true);
		}).fail(function(){
			$btn.prop(\'disabled\', false);
			$cell.html(\'<div class="notice notice-error inline"><p>\' + escapeHtml(cfg.i18n.errorPrefix + \'Request failed\') + \'</p></div>\');
		});
	});

})(jQuery);';
		}
	}
}

// Auto-init when bundled with the Pro plugin.
if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	WP_MCP_AI_Pro_Schedule_Research_Page::init();
}
