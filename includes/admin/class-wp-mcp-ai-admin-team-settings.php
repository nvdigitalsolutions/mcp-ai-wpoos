<?php
/**
 * Team Settings Admin Page
 *
 * Provides a dedicated settings page for AI Teams configuration.
 * Allows administrators to set global defaults for provider, model, and temperature
 * that cascade to all teams (overridable at individual team level).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Team Settings admin page handler.
 */
class WP_MCP_AI_Admin_Team_Settings {

	/**
	 * Page hook suffix.
	 *
	 * @var string|false
	 */
	protected $page_hook;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_submenu_page' ), 25 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register the submenu page under Teams CPT.
	 */
	public function register_submenu_page() {
		$post_type = class_exists( 'WP_MCP_AI_Team_CPT' ) ? WP_MCP_AI_Team_CPT::POST_TYPE : 'mcp_ai_team';

		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . $post_type,
			__( 'Team Settings', 'mcp-ai-wpoos' ),
			__( 'Settings', 'mcp-ai-wpoos' ),
			'manage_options',
			'wp-mcp-ai-team-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			'wp_mcp_ai_team_settings_group',
			'wp_mcp_ai_team_default_provider',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
		register_setting(
			'wp_mcp_ai_team_settings_group',
			'wp_mcp_ai_team_default_model',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
		register_setting(
			'wp_mcp_ai_team_settings_group',
			'wp_mcp_ai_team_default_temperature',
			array( 'sanitize_callback' => 'floatval' )
		);
		register_setting(
			'wp_mcp_ai_team_settings_group',
			'wp_mcp_ai_team_default_driver_assistant',
			array( 'sanitize_callback' => 'absint' )
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
		}

		// Handle form submission.
		if ( isset( $_POST['wp_mcp_ai_team_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_team_settings_nonce'] ) ), 'wp_mcp_ai_team_settings' ) ) {
			$this->save_settings();
		}

		// Get active tab.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Get post type for links.
		$post_type = class_exists( 'WP_MCP_AI_Team_CPT' ) ? WP_MCP_AI_Team_CPT::POST_TYPE : 'mcp_ai_team';

		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-groups" style="font-size: 32px;"></span>
				<?php esc_html_e( 'Team Settings', 'mcp-ai-wpoos' ); ?>
			</h1>

			<?php $this->render_tabs( $active_tab, $post_type ); ?>

			<div class="team-settings-content">
				<?php
				switch ( $active_tab ) {
					case 'overview':
						$this->render_overview_tab();
						break;
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
						$this->render_overview_tab();
				}
				?>
			</div>
		</div>

		<style>
			.team-settings-nav {
				border-bottom: 1px solid #ccd0d4;
				margin: 20px 0;
			}
			.team-settings-nav a {
				display: inline-block;
				padding: 10px 15px;
				text-decoration: none;
				border-bottom: 2px solid transparent;
				margin-bottom: -1px;
			}
			.team-settings-nav a.nav-tab-active {
				border-bottom-color: #2271b1;
				font-weight: 600;
			}
			.team-settings-content {
				margin-top: 20px;
			}
			.team-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin-bottom: 20px;
			}
			.team-card h2 {
				margin-top: 0;
			}
			.tool-item {
				padding: 10px;
				border-bottom: 1px solid #f0f0f1;
			}
			.tool-item:last-child {
				border-bottom: none;
			}
			.tool-item strong {
				display: inline-block;
				min-width: 200px;
			}
		</style>
		<?php
	}

	/**
	 * Render tab navigation.
	 *
	 * @param string $active_tab Active tab slug.
	 * @param string $post_type  Post type slug.
	 */
	protected function render_tabs( $active_tab, $post_type ) {
		$tabs = array(
			'overview'      => __( 'Overview', 'mcp-ai-wpoos' ),
			'configuration' => __( 'Configuration', 'mcp-ai-wpoos' ),
			'tools'         => __( 'Available Tools', 'mcp-ai-wpoos' ),
			'help'          => __( 'Help & Documentation', 'mcp-ai-wpoos' ),
		);

		?>
		<nav class="team-settings-nav nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab_title ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, admin_url( 'edit.php?post_type=' . $post_type . '&page=wp-mcp-ai-team-settings' ) ) ); ?>"
					class="nav-tab <?php echo esc_attr( $active_tab === $tab_slug ? 'nav-tab-active' : '' ); ?>"
				>
					<?php echo esc_html( $tab_title ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="team-card">
			<h2><?php esc_html_e( 'AI Teams Overview', 'mcp-ai-wpoos' ); ?></h2>
			
			<div class="team-description">
				<p><?php esc_html_e( 'AI Teams allow you to orchestrate multiple AI professions to work together on complex tasks. Teams can coordinate workflows, delegate tasks, and combine expertise from multiple specialized AI agents.', 'mcp-ai-wpoos' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Multi-Agent Coordination: Orchestrate multiple AI professions in a single workflow', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Driver Agent: Coordinate team activities with a designated driver assistant', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Task Delegation: Automatically route tasks to the best-suited team members', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Shared Context: Team members share information and collaborate on solutions', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Configuration Cascade: Team-level settings cascade to all members', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Flexible Composition: Mix and match professions to create custom teams', 'mcp-ai-wpoos' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Use Cases', 'mcp-ai-wpoos' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Content Production:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Writer + Editor + SEO Specialist + Designer', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Web Development:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Developer + Designer + QA Tester + Project Manager', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'E-commerce:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Product Manager + Copywriter + Photographer + SEO', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Marketing:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Marketer + Content Creator + Social Media Manager + Analyst', 'mcp-ai-wpoos' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render configuration tab.
	 */
	protected function render_configuration_tab() {
		// Get current settings.
		$default_provider    = get_option( 'wp_mcp_ai_team_default_provider', '' );
		$default_model       = get_option( 'wp_mcp_ai_team_default_model', '' );
		$default_temperature = get_option( 'wp_mcp_ai_team_default_temperature', 0.7 );

		// Get available providers.
		$available_providers = array();
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$available_providers = WP_MCP_AI_Admin_Settings::get_available_providers();
		}

		?>
		<div class="team-card">
			<h2><?php esc_html_e( 'Configuration', 'mcp-ai-wpoos' ); ?></h2>
			
			<p class="description">
				<?php esc_html_e( 'Configure global default settings for all AI Teams. These settings provide baseline values that cascade to all team members, but can be overridden at the individual team level via metaboxes.', 'mcp-ai-wpoos' ); ?>
			</p>

			<?php if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success inline">
					<p><?php esc_html_e( 'Settings saved successfully.', 'mcp-ai-wpoos' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'wp_mcp_ai_team_settings', 'wp_mcp_ai_team_settings_nonce' ); ?>
				
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_team_default_provider">
									<?php esc_html_e( 'Default AI Provider', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<select name="wp_mcp_ai_team_default_provider" id="wp_mcp_ai_team_default_provider" class="regular-text">
									<option value=""><?php esc_html_e( '-- Use Global Default --', 'mcp-ai-wpoos' ); ?></option>
									<?php foreach ( $available_providers as $provider_slug => $provider_label ) : ?>
										<option value="<?php echo esc_attr( $provider_slug ); ?>" <?php selected( $default_provider, $provider_slug ); ?>>
											<?php echo esc_html( $provider_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Default AI provider for all team members. Individual teams can override this in their metabox settings.', 'mcp-ai-wpoos' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_team_default_model">
									<?php esc_html_e( 'Default Model', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<input type="text" name="wp_mcp_ai_team_default_model" id="wp_mcp_ai_team_default_model" value="<?php echo esc_attr( $default_model ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., gpt-4o, claude-3-5-sonnet-20241022', 'mcp-ai-wpoos' ); ?>">
								<p class="description">
									<?php esc_html_e( 'Default AI model for all team members. Leave empty to use the provider\'s default model. Individual teams can override this setting.', 'mcp-ai-wpoos' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_team_default_temperature">
									<?php esc_html_e( 'Default Temperature', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<input type="number" name="wp_mcp_ai_team_default_temperature" id="wp_mcp_ai_team_default_temperature" value="<?php echo esc_attr( $default_temperature ); ?>" class="small-text" min="0" max="1" step="0.1">
								<p class="description">
									<?php esc_html_e( 'Default creativity/randomness setting (0.0 = deterministic, 1.0 = creative). Individual teams can override this setting.', 'mcp-ai-wpoos' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'mcp-ai-wpoos' ); ?>">
				</p>
			</form>
		</div>

		<div class="team-card">
			<h2><?php esc_html_e( 'Settings Hierarchy', 'mcp-ai-wpoos' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Settings cascade in the following order (higher priority overrides lower):', 'mcp-ai-wpoos' ); ?>
			</p>
			<ol>
				<li><strong><?php esc_html_e( 'Individual Team Settings', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'Configured in each team\'s metabox (highest priority)', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Team Global Defaults', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'This page (medium priority)', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Global Plugin Settings', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'Site-wide defaults (lowest priority)', 'mcp-ai-wpoos' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render tools tab.
	 */
	protected function render_tools_tab() {
		$tools = $this->get_tools_list();
		?>
		<div class="team-card">
			<h2><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %d: Number of tools */
					esc_html__( 'Teams can access all %d core tools, and each team member (profession) may have additional specialized tools.', 'mcp-ai-wpoos' ),
					count( $tools )
				);
				?>
			</p>

			<div class="tools-list" style="margin-top: 20px;">
				<?php foreach ( $tools as $tool_slug => $tool_name ) : ?>
					<div class="tool-item">
						<strong><?php echo esc_html( $tool_name ); ?></strong>
						<code style="margin-left: 10px;"><?php echo esc_html( $tool_slug ); ?></code>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="team-card">
			<h2><?php esc_html_e( 'Team Orchestration', 'mcp-ai-wpoos' ); ?></h2>
			<p><?php esc_html_e( 'Teams use a driver agent to coordinate activities and delegate tasks to the appropriate team members.', 'mcp-ai-wpoos' ); ?></p>
			<p><?php esc_html_e( 'Each team member can use their profession-specific tools in addition to the core tools listed above.', 'mcp-ai-wpoos' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Get tools list for teams.
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		// Get core WordPress tools available to teams.
		return array(
			'wp_create_post'        => __( 'Create Post', 'mcp-ai-wpoos' ),
			'wp_update_post'        => __( 'Update Post', 'mcp-ai-wpoos' ),
			'wp_create_page'        => __( 'Create Page', 'mcp-ai-wpoos' ),
			'wp_update_page'        => __( 'Update Page', 'mcp-ai-wpoos' ),
			'wp_get_posts'          => __( 'Get Posts', 'mcp-ai-wpoos' ),
			'wp_search_content'     => __( 'Search Content', 'mcp-ai-wpoos' ),
			'wp_get_post_meta'      => __( 'Get Post Metadata', 'mcp-ai-wpoos' ),
			'wp_update_post_meta'   => __( 'Update Post Metadata', 'mcp-ai-wpoos' ),
			'generate_openai_image' => __( 'Generate Image (OpenAI)', 'mcp-ai-wpoos' ),
			'generate_gemini_image' => __( 'Generate Image (Gemini)', 'mcp-ai-wpoos' ),
			'resize_image'          => __( 'Resize Image', 'mcp-ai-wpoos' ),
			'crop_image'            => __( 'Crop Image', 'mcp-ai-wpoos' ),
			'rotate_image'          => __( 'Rotate Image', 'mcp-ai-wpoos' ),
			'web_scrape'            => __( 'Web Scrape', 'mcp-ai-wpoos' ),
			'web_search'            => __( 'Web Search', 'mcp-ai-wpoos' ),
			'create_assistant'      => __( 'Create Assistant', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Render help & documentation tab.
	 *
	 * @param string $post_type Post type slug.
	 */
	protected function render_help_tab( $post_type ) {
		?>
		<div class="team-card">
			<h2><?php esc_html_e( 'Quick Start Guide', 'mcp-ai-wpoos' ); ?></h2>
			<ol>
				<li><strong><?php esc_html_e( 'Create a Team:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Use the "Create New Team" button to add a new AI team', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Add Members:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Select professions to add as team members', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Configure Driver:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Select a driver assistant to coordinate the team', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Set Team Goals:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Define the team\'s purpose and workflow', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Test:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Use the Test Team page to verify team coordination', 'mcp-ai-wpoos' ); ?></li>
			</ol>
		</div>

		<div class="team-card">
			<h2><?php esc_html_e( 'Support & Documentation', 'mcp-ai-wpoos' ); ?></h2>
			<p><?php esc_html_e( 'For more information and detailed documentation:', 'mcp-ai-wpoos' ); ?></p>
			<ul>
				<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/tool-reference.md" target="_blank"><?php esc_html_e( 'Tool Reference Documentation', 'mcp-ai-wpoos' ); ?></a></li>
				<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues" target="_blank"><?php esc_html_e( 'Report Issues or Request Features', 'mcp-ai-wpoos' ); ?></a></li>
			</ul>
		</div>

		<div class="team-card">
			<h2><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos' ); ?></h2>
			<p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>" class="button">
					<?php esc_html_e( 'View All Teams', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Create New Team', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type . '&page=wp-mcp-ai-test-team' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Test Team', 'mcp-ai-wpoos' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 *
	 * Note: Nonce verification is done in render_page() before calling this method.
	 */
	private function save_settings() {
		// Sanitize and save provider.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
		if ( isset( $_POST['wp_mcp_ai_team_default_provider'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
			update_option( 'wp_mcp_ai_team_default_provider', sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_team_default_provider'] ) ) );
		}

		// Sanitize and save model.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
		if ( isset( $_POST['wp_mcp_ai_team_default_model'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
			update_option( 'wp_mcp_ai_team_default_model', sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_team_default_model'] ) ) );
		}

		// Sanitize and save temperature.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
		if ( isset( $_POST['wp_mcp_ai_team_default_temperature'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
			$temperature = floatval( wp_unslash( $_POST['wp_mcp_ai_team_default_temperature'] ) );
			$temperature = max( 0, min( 1, $temperature ) ); // Clamp between 0 and 1.
			update_option( 'wp_mcp_ai_team_default_temperature', $temperature );
		}

		// Redirect with success message.
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', admin_url( 'edit.php?post_type=mcp_ai_team&page=wp-mcp-ai-team-settings' ) ) );
		exit;
	}
}
