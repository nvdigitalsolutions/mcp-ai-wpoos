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
						'previewing' => __( 'Generating preview…', 'mcp-ai-wpoos-pro' ),
						'creating'   => __( 'Creating schedules…', 'mcp-ai-wpoos-pro' ),
						'noItems'    => __( 'Please paste at least one workflow item.', 'mcp-ai-wpoos-pro' ),
						'errorPrefix'=> __( 'Error: ', 'mcp-ai-wpoos-pro' ),
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

			// Get assistant from settings or first available.
			$settings     = get_option( 'wp_mcp_ai_settings', array() );
			$assistant_id = isset( $settings['default_assistant_id'] ) ? absint( $settings['default_assistant_id'] ) : 0;

			if ( ! $assistant_id || 'publish' !== get_post_status( $assistant_id ) ) {
				$assistants = get_posts(
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

			$current_mode = isset( $_GET['mode'] ) ? sanitize_key( $_GET['mode'] ) : 'chat'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$valid_modes  = array( 'chat', 'paste', 'review' );
			if ( ! in_array( $current_mode, $valid_modes, true ) ) {
				$current_mode = 'chat';
			}

			?>
			<div class="wrap wp-mcp-ai-research-page wp-mcp-ai-pro-schedule-research-page">
				<h1 class="wp-heading-inline">
					<span class="dashicons dashicons-clock" style="font-size:28px;width:28px;height:28px;vertical-align:middle;margin-right:8px;color:#2271b1;"></span>
					<?php esc_html_e( 'Research & Add Schedule', 'mcp-ai-wpoos-pro' ); ?>
					<span style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:4px 12px;border-radius:12px;font-size:11px;font-weight:600;margin-left:10px;text-transform:uppercase;letter-spacing:.5px;">PRO</span>
				</h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-pro-schedule-manager' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Open Schedule Manager', 'mcp-ai-wpoos-pro' ); ?>
				</a>

				<hr class="wp-header-end">

				<div class="wp-mcp-ai-research-container">
					<div class="wp-mcp-ai-research-sidebar">
						<div class="wp-mcp-ai-research-intro">
							<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
							<ol>
								<li><?php esc_html_e( 'Paste a list of recurring responsibilities — one per line.', 'mcp-ai-wpoos-pro' ); ?></li>
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
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-pro-schedule-manager' ) ); ?>" class="button">
									<?php esc_html_e( 'Schedule Manager', 'mcp-ai-wpoos-pro' ); ?>
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
						<div class="wp-mcp-ai-mode-tabs">
							<?php
							$modes = array(
								'chat'   => array( 'icon' => '💬', 'label' => __( 'AI Plan', 'mcp-ai-wpoos-pro' ) ),
								'paste'  => array( 'icon' => '📋', 'label' => __( 'Bulk Paste', 'mcp-ai-wpoos-pro' ) ),
								'review' => array( 'icon' => '📊', 'label' => __( 'Review', 'mcp-ai-wpoos-pro' ) ),
							);
							foreach ( $modes as $mode => $data ) :
								$url = add_query_arg( 'mode', $mode );
								?>
								<a href="<?php echo esc_url( $url ); ?>" class="mode-tab <?php echo esc_attr( $mode === $current_mode ? 'active' : '' ); ?>">
									<span class="mode-icon"><?php echo esc_html( $data['icon'] ); ?></span>
									<span class="mode-label"><?php echo esc_html( $data['label'] ); ?></span>
								</a>
							<?php endforeach; ?>
						</div>

						<?php if ( 'chat' === $current_mode ) : ?>
							<?php self::render_chat_mode( $assistant_id ); ?>
						<?php elseif ( 'paste' === $current_mode ) : ?>
							<?php self::render_paste_mode( $assistant_id ); ?>
						<?php else : ?>
							<?php self::render_review_mode(); ?>
						<?php endif; ?>
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
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $schedules as $sch ) : ?>
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
							</tr>
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
					require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-plan-schedules-from-workflow.php';
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
		 * Inline JavaScript for the bulk paste mode.
		 *
		 * @return string Script source.
		 */
		protected static function get_inline_script() {
			return <<<'JS'
(function($){
	if (typeof window.wpMcpAiScheduleResearch === 'undefined') { return; }
	var cfg = window.wpMcpAiScheduleResearch;

	function escapeHtml(s){
		return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
			return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
		});
	}

	function collectArgs(){
		return {
			workflow_text: $('#schedule-research-text').val() || '',
			category: $('#schedule-research-category').val() || '',
			default_cadence: $('#schedule-research-cadence').val() || 'daily',
			default_time: $('#schedule-research-time').val() || '09:00',
			default_assistant_id: parseInt($('#schedule-research-assistant').val(), 10) || 0
		};
	}

	function renderResult(data){
		var $out = $('#schedule-research-results');
		$out.empty();
		if (!data) return;
		var summary = data.summary || {};
		var rows = (data.plan && data.plan.length) ? data.plan : (data.created || []);
		var html = '<h3>' + escapeHtml(data.message || '') + '</h3>';
		html += '<p>' + escapeHtml('Total: ' + (summary.total || 0) + ' · Planned: ' + (summary.planned || 0) + ' · Created: ' + (summary.created || 0) + ' · Errors: ' + (summary.errors || 0)) + '</p>';
		if (rows.length){
			html += '<table class="widefat striped"><thead><tr><th>Name</th><th>Cadence</th><th>Type</th><th>Tags</th></tr></thead><tbody>';
			rows.forEach(function(r){
				html += '<tr>';
				html += '<td>' + escapeHtml(r.name || '') + '</td>';
				html += '<td>' + escapeHtml(r.schedule || r.cadence || '') + '</td>';
				html += '<td>' + escapeHtml(r.schedule_type || '') + '</td>';
				html += '<td>' + escapeHtml((r.tags || []).join(', ')) + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
		}
		if (data.errors && data.errors.length){
			html += '<h4>' + escapeHtml('Errors') + '</h4><ul>';
			data.errors.forEach(function(e){
				html += '<li>' + escapeHtml(e.message || '') + '</li>';
			});
			html += '</ul>';
		}
		$out.html(html);
	}

	$(document).on('click', '#schedule-research-preview', function(e){
		e.preventDefault();
		var args = collectArgs();
		if (!args.workflow_text.trim()){
			alert(cfg.i18n.noItems);
			return;
		}
		var $sp = $('#schedule-research-spinner').addClass('is-active');
		$('#schedule-research-create').prop('disabled', true);
		$('#schedule-research-results').html('<p>' + escapeHtml(cfg.i18n.previewing) + '</p>');
		args.action = 'wp_mcp_ai_preview_schedule_from_research';
		args.nonce = cfg.nonce;
		$.post(cfg.ajaxUrl, args).done(function(resp){
			$sp.removeClass('is-active');
			if (resp && resp.success){
				renderResult(resp.data);
				$('#schedule-research-create').prop('disabled', false);
			} else {
				$('#schedule-research-results').html('<div class="notice notice-error"><p>' + escapeHtml(cfg.i18n.errorPrefix + ((resp && resp.data && resp.data.message) || 'Unknown')) + '</p></div>');
			}
		}).fail(function(){
			$sp.removeClass('is-active');
			$('#schedule-research-results').html('<div class="notice notice-error"><p>' + escapeHtml(cfg.i18n.errorPrefix + 'Request failed') + '</p></div>');
		});
	});

	$(document).on('click', '#schedule-research-create', function(e){
		e.preventDefault();
		var args = collectArgs();
		if (!args.workflow_text.trim()){
			alert(cfg.i18n.noItems);
			return;
		}
		var $sp = $('#schedule-research-spinner').addClass('is-active');
		$('#schedule-research-results').append('<p>' + escapeHtml(cfg.i18n.creating) + '</p>');
		args.action = 'wp_mcp_ai_create_schedule_from_research';
		args.nonce = cfg.nonce;
		$.post(cfg.ajaxUrl, args).done(function(resp){
			$sp.removeClass('is-active');
			if (resp && resp.success){
				renderResult(resp.data);
				$('#schedule-research-create').prop('disabled', true);
			} else {
				$('#schedule-research-results').append('<div class="notice notice-error"><p>' + escapeHtml(cfg.i18n.errorPrefix + ((resp && resp.data && resp.data.message) || 'Unknown')) + '</p></div>');
			}
		}).fail(function(){
			$sp.removeClass('is-active');
			$('#schedule-research-results').append('<div class="notice notice-error"><p>' + escapeHtml(cfg.i18n.errorPrefix + 'Request failed') + '</p></div>');
		});
	});
})(jQuery);
JS;
		}
	}
}

// Auto-init when bundled with the Pro plugin.
if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	WP_MCP_AI_Pro_Schedule_Research_Page::init();
}
