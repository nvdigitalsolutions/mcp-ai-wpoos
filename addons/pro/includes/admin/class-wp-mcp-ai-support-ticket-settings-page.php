<?php
/**
 * Support Ticket Settings Admin Page
 *
 * Provides a dedicated settings page under the Support Ticket CPT menu for
 * configuring AI assistant, default priority, and category settings.
 * Tabbed: Overview / AI Configuration / Tools / Help.
 *
 * Mirrors WP_MCP_AI_Deal_Settings_Page and WP_MCP_AI_Lead_Settings_Page patterns.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Support Ticket Settings admin page handler.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Support_Ticket_Settings_Page {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_ai_support_ticket_settings';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-support-ticket-settings';

	/**
	 * Initialize the page.
	 *
	 * @since 2.6.0
	 */
	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'register_submenu_page' ), 25 );
		add_action( 'admin_init', array( $instance, 'register_settings' ) );
	}

	/**
	 * Register the submenu page under Support Tickets CPT.
	 *
	 * @since 2.6.0
	 */
	public function register_submenu_page() {
		$post_type = class_exists( 'WP_MCP_AI_Support_Ticket_CPT' ) ? WP_MCP_AI_Support_Ticket_CPT::POST_TYPE : 'mcp_ai_support_ticket';

		add_submenu_page(
			'edit.php?post_type=' . $post_type,
			__( 'Support Ticket Settings', 'mcp-ai-wpoos-pro' ),
			__( 'Settings', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @since 2.6.0
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_NAME . '_group',
			self::OPTION_NAME,
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 2.6.0
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$existing = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$sanitized = $existing;

		if ( isset( $input['assistant_id'] ) ) {
			if ( 'default' === $input['assistant_id'] ) {
				$sanitized['assistant_id'] = 'default';
			} else {
				$sanitized['assistant_id'] = absint( $input['assistant_id'] );
			}
		}

		if ( isset( $input['default_priority'] ) ) {
			$valid_priorities = array( 'p1_critical', 'p2_high', 'p3_medium', 'p4_low' );
			if ( in_array( $input['default_priority'], $valid_priorities, true ) ) {
				$sanitized['default_priority'] = $input['default_priority'];
			}
		}

		if ( isset( $input['default_assignee_id'] ) ) {
			$sanitized['default_assignee_id'] = absint( $input['default_assignee_id'] );
		}

		if ( isset( $input['auto_close_resolved_days'] ) ) {
			$sanitized['auto_close_resolved_days'] = absint( $input['auto_close_resolved_days'] );
		}

		return $sanitized;
	}

	/**
	 * Render the settings page.
	 *
	 * @since 2.6.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type  = class_exists( 'WP_MCP_AI_Support_Ticket_CPT' ) ? WP_MCP_AI_Support_Ticket_CPT::POST_TYPE : 'mcp_ai_support_ticket';
		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-sos" style="font-size: 32px; width: 32px; height: 32px;"></span>
				<?php echo esc_html( __( 'Support Ticket Settings', 'mcp-ai-wpoos-pro' ) ); ?>
			</h1>

			<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_tabs( $active_tab ); ?>

			<div class="toolkit-settings-content">
				<?php
				switch ( $active_tab ) {
					case 'configuration':
						$this->render_configuration_tab();
						break;
					case 'tools':
						$this->render_tools_tab();
						break;
					case 'help':
						$this->render_help_tab( $post_type );
						break;
					default:
						$this->render_overview_tab( $post_type );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render tab navigation.
	 *
	 * @since 2.6.0
	 * @param string $active_tab Active tab slug.
	 */
	protected function render_tabs( $active_tab ) {
		$tabs = array(
			'overview'      => __( 'Overview', 'mcp-ai-wpoos-pro' ),
			'configuration' => __( 'AI Configuration', 'mcp-ai-wpoos-pro' ),
			'tools'         => __( 'Tools', 'mcp-ai-wpoos-pro' ),
			'help'          => __( 'Help', 'mcp-ai-wpoos-pro' ),
		);

		?>
		<nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
			<?php foreach ( $tabs as $tab_slug => $tab_title ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>"
					class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded CSS class. ?>"
				>
					<?php echo esc_html( $tab_title ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render overview tab.
	 *
	 * @since 2.6.0
	 * @param string $post_type The ticket CPT slug.
	 */
	protected function render_overview_tab( $post_type ) {
		$count = 0;
		if ( post_type_exists( $post_type ) ) {
			$counts = wp_count_posts( $post_type );
			$count  = isset( $counts->publish ) ? $counts->publish : 0;
		}

		$settings = get_option( self::OPTION_NAME, array() );
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Support Ticket Management Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
				<div class="toolkit-stat-card" style="background: #f0f6fc; padding: 20px; border-left: 4px solid #2271b1;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Total Tickets', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p style="font-size: 32px; margin: 0; font-weight: bold;"><?php echo absint( $count ); ?></p>
				</div>
			</div>

			<h3><?php esc_html_e( 'Quick Links', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>"><?php esc_html_e( 'View All Tickets', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>"><?php esc_html_e( 'Add New Ticket', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG ) ); ?>"><?php esc_html_e( 'CRM Command Center', 'mcp-ai-wpoos-pro' ); ?></a></li>
			</ul>

			<h3><?php esc_html_e( 'Current Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="widefat striped" style="max-width: 600px;">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'AI Assistant', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php
							$assistant_id = isset( $settings['assistant_id'] ) ? $settings['assistant_id'] : 'default';
							if ( 'default' === $assistant_id ) {
								esc_html_e( 'CRM Toolkit Default', 'mcp-ai-wpoos-pro' );
							} elseif ( $assistant_id > 0 && get_post( $assistant_id ) ) {
								echo esc_html( get_the_title( $assistant_id ) );
							} else {
								esc_html_e( 'Not set', 'mcp-ai-wpoos-pro' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Default Priority', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php
							$default_priority = isset( $settings['default_priority'] ) ? $settings['default_priority'] : 'p2_high';
							$priorities       = array(
								'p1_critical' => __( 'P1 — Critical', 'mcp-ai-wpoos-pro' ),
								'p2_high'     => __( 'P2 — High', 'mcp-ai-wpoos-pro' ),
								'p3_medium'   => __( 'P3 — Medium', 'mcp-ai-wpoos-pro' ),
								'p4_low'      => __( 'P4 — Low', 'mcp-ai-wpoos-pro' ),
							);
							echo esc_html( $priorities[ $default_priority ] ?? $default_priority );
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Auto-Close Resolved', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( sprintf( /* translators: %d: number of days */ __( '%d days', 'mcp-ai-wpoos-pro' ), $settings['auto_close_resolved_days'] ?? 3 ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Pipeline Stages', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="widefat striped" style="max-width: 600px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Stage', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'SLA Paused', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( class_exists( 'WP_MCP_AI_Support_Ticket_CPT' ) ) : ?>
						<?php foreach ( WP_MCP_AI_Support_Ticket_CPT::PIPELINE_STAGES as $slug => $def ) : ?>
							<tr>
								<td>
									<span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:<?php echo esc_attr( WP_MCP_AI_Support_Ticket_CPT::get_stage_color( $slug ) ); ?>15;color:<?php echo esc_attr( WP_MCP_AI_Support_Ticket_CPT::get_stage_color( $slug ) ); ?>;">
										<?php echo esc_html( $def['label'] ); ?>
									</span>
								</td>
								<td><?php echo $def['sla_paused'] ? '⏸️ ' . esc_html__( 'Yes', 'mcp-ai-wpoos-pro' ) : '▶️ ' . esc_html__( 'No', 'mcp-ai-wpoos-pro' ); ?></td>
								<td>
									<?php if ( ! empty( $def['is_closed'] ) ) : ?>
										<?php esc_html_e( 'Terminal state', 'mcp-ai-wpoos-pro' ); ?>
									<?php elseif ( ! empty( $def['is_resolved'] ) ) : ?>
										<?php esc_html_e( 'Resolution confirmed, auto-closes', 'mcp-ai-wpoos-pro' ); ?>
									<?php elseif ( ! empty( $def['sla_paused'] ) ) : ?>
										<?php esc_html_e( 'Waiting for external input', 'mcp-ai-wpoos-pro' ); ?>
									<?php else : ?>
										<?php esc_html_e( 'Active work state', 'mcp-ai-wpoos-pro' ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render AI Configuration tab.
	 *
	 * @since 2.6.0
	 */
	protected function render_configuration_tab() {
		$settings = get_option( self::OPTION_NAME, array() );

		$current_assistant = isset( $settings['assistant_id'] ) ? $settings['assistant_id'] : 'default';
		$default_priority  = isset( $settings['default_priority'] ) ? $settings['default_priority'] : 'p2_high';
		$default_assignee  = isset( $settings['default_assignee_id'] ) ? $settings['default_assignee_id'] : 0;
		$auto_close_days   = isset( $settings['auto_close_resolved_days'] ) ? $settings['auto_close_resolved_days'] : 3;

		$available_assistants = $this->get_available_assistants();
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'AI Configuration for Support Tickets', 'mcp-ai-wpoos-pro' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'These settings control how AI assists with support ticket triage, classification, and resolution. They override the CRM Toolkit defaults for this CPT.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_NAME . '_group' );
				?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="ticket_assistant_id"><?php esc_html_e( 'Support Assistant', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[assistant_id]" id="ticket_assistant_id">
								<?php foreach ( $available_assistants as $a_id => $a_name ) : ?>
									<option value="<?php echo esc_attr( $a_id ); ?>" <?php selected( $current_assistant, $a_id ); ?>>
										<?php echo esc_html( $a_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Select which AI assistant to use for support ticket operations. Leave as "CRM Toolkit Default" to use the global setting.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ticket_default_priority"><?php esc_html_e( 'Default Priority', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_priority]" id="ticket_default_priority">
								<option value="p1_critical" <?php selected( $default_priority, 'p1_critical' ); ?>><?php esc_html_e( 'P1 — Critical (15min / 4hr)', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="p2_high" <?php selected( $default_priority, 'p2_high' ); ?>><?php esc_html_e( 'P2 — High (1hr / 8hr)', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="p3_medium" <?php selected( $default_priority, 'p3_medium' ); ?>><?php esc_html_e( 'P3 — Medium (4hr / 24hr)', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="p4_low" <?php selected( $default_priority, 'p4_low' ); ?>><?php esc_html_e( 'P4 — Low (8hr / 72hr)', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Default priority for newly created support tickets. Priority determines SLA response and resolution targets.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ticket_default_assignee"><?php esc_html_e( 'Default Assignee', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'name'             => esc_attr( self::OPTION_NAME ) . '[default_assignee_id]',
									'id'               => 'ticket_default_assignee',
									'selected'         => $default_assignee,
									'show_option_none' => __( '— Unassigned —', 'mcp-ai-wpoos-pro' ),
									'role__in'         => array( 'administrator', 'editor', 'author', 'contributor' ),
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Default assignee for auto-created tickets. Leave unassigned for manual triage.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ticket_auto_close_days"><?php esc_html_e( 'Auto-Close Resolved Tickets', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<input type="number" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auto_close_resolved_days]" id="ticket_auto_close_days" value="<?php echo esc_attr( $auto_close_days ); ?>" class="small-text" min="1" max="30" />
							<p class="description"><?php esc_html_e( 'Number of days after which a resolved ticket is automatically closed. Set to 0 to disable.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Settings Hierarchy', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Support ticket assistant resolution order:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ol>
				<li><strong><?php esc_html_e( 'Support Ticket Settings', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'This page (highest priority for ticket operations)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'CRM Toolkit Settings', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Global CRM Research & Add Assistant (medium priority)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'First Available Assistant', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Auto-fallback to any published assistant (lowest priority)', 'mcp-ai-wpoos-pro' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render tools tab.
	 *
	 * @since 2.6.0
	 */
	protected function render_tools_tab() {
		$tools = $this->get_ticket_tools();
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Support Ticket Tools', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The following AI tools are planned for support ticket operations. Enable or disable them in the CRM Toolkit settings.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<?php if ( empty( $tools ) ) : ?>
				<p><?php esc_html_e( 'No ticket-specific tools found. Ensure the CRM toolkit is enabled and tools are registered.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<?php foreach ( $tools as $slug => $label ) : ?>
					<div class="tool-item">
						<strong><?php echo esc_html( $label ); ?></strong>
						<code><?php echo esc_html( $slug ); ?></code>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render help tab.
	 *
	 * @since 2.6.0
	 * @param string $post_type The ticket CPT slug.
	 */
	protected function render_help_tab( $post_type ) {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Getting Started with Support Tickets', 'mcp-ai-wpoos-pro' ); ?></h2>
			<ol>
				<li><strong><?php esc_html_e( 'Configure your AI assistant', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Choose which assistant powers ticket triage and classification in the AI Configuration tab.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Create support tickets', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Tickets can be created manually from the admin, via AI tools, or auto-created from inbound messages.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Track SLA compliance', 'mcp-ai-wpoos-pro' ); ?></strong> &mdash; <?php esc_html_e( 'Monitor first response and resolution timers from the SLA & Timing metabox and CRM Command Center.', 'mcp-ai-wpoos-pro' ); ?></li>
			</ol>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Resources', 'mcp-ai-wpoos-pro' ); ?></h2>
			<ul>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>"><?php esc_html_e( 'Ticket List', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG ) ); ?>"><?php esc_html_e( 'CRM Command Center', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-crm-toolkit-settings' ) ); ?>"><?php esc_html_e( 'CRM Toolkit Settings', 'mcp-ai-wpoos-pro' ); ?></a></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get available assistants for the dropdown.
	 *
	 * @since 2.6.0
	 * @return array Map of id|default => name.
	 */
	private function get_available_assistants() {
		$assistants = array(
			'default' => __( 'CRM Toolkit Default', 'mcp-ai-wpoos-pro' ),
		);

		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'id=>parent',
			)
		);

		foreach ( $posts as $post_id => $parent_id ) {
			$assistants[ $post_id ] = get_the_title( $post_id );
		}

		return $assistants;
	}

	/**
	 * Get registered support ticket tools.
	 *
	 * @since 2.6.0
	 * @return array Map of slug => label.
	 */
	private function get_ticket_tools() {
		// These tools will be registered in Phase 3 (see proposal).
		$tools = array(
			'create_support_ticket'   => __( 'Create Support Ticket', 'mcp-ai-wpoos-pro' ),
			'get_support_ticket'      => __( 'Get Support Ticket', 'mcp-ai-wpoos-pro' ),
			'list_support_tickets'    => __( 'List Support Tickets', 'mcp-ai-wpoos-pro' ),
			'update_support_ticket'   => __( 'Update Support Ticket', 'mcp-ai-wpoos-pro' ),
			'resolve_support_ticket'  => __( 'Resolve Support Ticket', 'mcp-ai-wpoos-pro' ),
			'reopen_support_ticket'   => __( 'Reopen Support Ticket', 'mcp-ai-wpoos-pro' ),
			'escalate_support_ticket' => __( 'Escalate Support Ticket', 'mcp-ai-wpoos-pro' ),
			'merge_support_tickets'   => __( 'Merge Support Tickets', 'mcp-ai-wpoos-pro' ),
			'classify_support_ticket' => __( 'Classify Support Ticket', 'mcp-ai-wpoos-pro' ),
			'get_ticket_sla_report'   => __( 'Get Ticket SLA Report', 'mcp-ai-wpoos-pro' ),
		);

		/**
		 * Filter the list of registered support ticket tools.
		 *
		 * @since 2.6.0
		 * @param array $tools Map of tool slug => label.
		 */
		return apply_filters( 'wp_mcp_ai_support_ticket_tools', $tools );
	}
}
