<?php
/**
 * Profession Settings Admin Page
 *
 * Provides a dedicated settings page for AI Professions configuration.
 * Allows administrators to set global defaults for provider, model, and temperature
 * that cascade to all professions (overridable at individual profession level).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profession Settings admin page handler.
 */
class WP_MCP_AI_Admin_Profession_Settings {

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
	 * Register the submenu page under Professions CPT.
	 */
	public function register_submenu_page() {
		$post_type = class_exists( 'WP_MCP_AI_Profession_CPT' ) ? WP_MCP_AI_Profession_CPT::POST_TYPE : 'mcp_ai_profession';

		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . $post_type,
			__( 'Profession Settings', 'mcp-ai-wpoos' ),
			__( 'Settings', 'mcp-ai-wpoos' ),
			'manage_options',
			'wp-mcp-ai-profession-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			'wp_mcp_ai_profession_settings_group',
			'wp_mcp_ai_profession_default_provider',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
		register_setting(
			'wp_mcp_ai_profession_settings_group',
			'wp_mcp_ai_profession_default_model',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
		register_setting(
			'wp_mcp_ai_profession_settings_group',
			'wp_mcp_ai_profession_default_temperature',
			array( 'sanitize_callback' => 'floatval' )
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
		if ( isset( $_POST['wp_mcp_ai_profession_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_profession_settings_nonce'] ) ), 'wp_mcp_ai_profession_settings' ) ) {
			$this->save_settings();
		}

		// Get active tab.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Get post type for links.
		$post_type = class_exists( 'WP_MCP_AI_Profession_CPT' ) ? WP_MCP_AI_Profession_CPT::POST_TYPE : 'mcp_ai_profession';

		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-groups" style="font-size: 32px;"></span>
				<?php esc_html_e( 'Profession Settings', 'mcp-ai-wpoos' ); ?>
			</h1>

			<?php $this->render_tabs( $active_tab, $post_type ); ?>

			<div class="profession-settings-content">
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
			.profession-settings-nav {
				border-bottom: 1px solid #ccd0d4;
				margin: 20px 0;
			}
			.profession-settings-nav a {
				display: inline-block;
				padding: 10px 15px;
				text-decoration: none;
				border-bottom: 2px solid transparent;
				margin-bottom: -1px;
			}
			.profession-settings-nav a.nav-tab-active {
				border-bottom-color: #2271b1;
				font-weight: 600;
			}
			.profession-settings-content {
				margin-top: 20px;
			}
			.profession-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin-bottom: 20px;
			}
			.profession-card h2 {
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
		<nav class="profession-settings-nav nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab_title ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, admin_url( 'edit.php?post_type=' . $post_type . '&page=wp-mcp-ai-profession-settings' ) ) ); ?>"
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
		<div class="profession-card">
			<h2><?php esc_html_e( 'AI Professions Overview', 'mcp-ai-wpoos' ); ?></h2>
			
			<div class="profession-description">
				<p><?php esc_html_e( 'AI Professions are specialized AI agents designed for specific professional roles. Each profession comes with tailored instructions, recommended tools, and industry-specific knowledge to provide expert assistance.', 'mcp-ai-wpoos' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Role-Based AI: Pre-configured AI agents for specific professions and industries', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Tool Recommendations: Automatically suggest relevant tools based on profession', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Custom Instructions: Profession-specific prompts and behavioral guidelines', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Knowledge Base: Industry-specific documents and playbooks', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Configuration Cascade: Global defaults with profession-level overrides', 'mcp-ai-wpoos' ); ?></li>
				<li><?php esc_html_e( 'Provider Flexibility: Support for OpenAI, Gemini, Ollama, and more', 'mcp-ai-wpoos' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Use Cases', 'mcp-ai-wpoos' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Content Creation:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Writers, bloggers, marketers', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Development:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'WordPress developers, plugin developers', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Business:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Consultants, entrepreneurs, project managers', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Creative:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Designers, architects, DJs, event planners', 'mcp-ai-wpoos' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render configuration tab.
	 */
	protected function render_configuration_tab() {
		// Get current settings.
		$default_provider    = get_option( 'wp_mcp_ai_profession_default_provider', '' );
		$default_model       = get_option( 'wp_mcp_ai_profession_default_model', '' );
		$default_temperature = get_option( 'wp_mcp_ai_profession_default_temperature', 0.7 );

		// Get available providers.
		$available_providers = array();
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$available_providers = WP_MCP_AI_Admin_Settings::get_available_providers();
		}

		?>
		<div class="profession-card">
			<h2><?php esc_html_e( 'Configuration', 'mcp-ai-wpoos' ); ?></h2>
			
			<p class="description">
				<?php esc_html_e( 'Configure global default settings for all AI Professions. These settings provide baseline values that cascade to all professions, but can be overridden at the individual profession level via metaboxes.', 'mcp-ai-wpoos' ); ?>
			</p>

			<?php if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success inline">
					<p><?php esc_html_e( 'Settings saved successfully.', 'mcp-ai-wpoos' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'wp_mcp_ai_profession_settings', 'wp_mcp_ai_profession_settings_nonce' ); ?>
				
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_profession_default_provider">
									<?php esc_html_e( 'Default AI Provider', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<select name="wp_mcp_ai_profession_default_provider" id="wp_mcp_ai_profession_default_provider" class="regular-text">
									<option value=""><?php esc_html_e( '-- Use Global Default --', 'mcp-ai-wpoos' ); ?></option>
									<?php foreach ( $available_providers as $provider_slug => $provider_label ) : ?>
										<option value="<?php echo esc_attr( $provider_slug ); ?>" <?php selected( $default_provider, $provider_slug ); ?>>
											<?php echo esc_html( $provider_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Default AI provider for all professions. Individual professions can override this in their metabox settings.', 'mcp-ai-wpoos' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_profession_default_model">
									<?php esc_html_e( 'Default Model', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<input type="text" name="wp_mcp_ai_profession_default_model" id="wp_mcp_ai_profession_default_model" value="<?php echo esc_attr( $default_model ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., gpt-4o, claude-3-5-sonnet-20241022', 'mcp-ai-wpoos' ); ?>">
								<p class="description">
									<?php esc_html_e( 'Default AI model for all professions. Leave empty to use the provider\'s default model. Individual professions can override this setting.', 'mcp-ai-wpoos' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_profession_default_temperature">
									<?php esc_html_e( 'Default Temperature', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<input type="number" name="wp_mcp_ai_profession_default_temperature" id="wp_mcp_ai_profession_default_temperature" value="<?php echo esc_attr( $default_temperature ); ?>" class="small-text" min="0" max="1" step="0.1">
								<p class="description">
									<?php esc_html_e( 'Default creativity/randomness setting (0.0 = deterministic, 1.0 = creative). Individual professions can override this setting.', 'mcp-ai-wpoos' ); ?>
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

		<div class="profession-card">
			<h2><?php esc_html_e( 'Settings Hierarchy', 'mcp-ai-wpoos' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Settings cascade in the following order (higher priority overrides lower):', 'mcp-ai-wpoos' ); ?>
			</p>
			<ol>
				<li><strong><?php esc_html_e( 'Individual Profession Settings', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'Configured in each profession\'s metabox (highest priority)', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Profession Global Defaults', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'This page (medium priority)', 'mcp-ai-wpoos' ); ?></li>
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
		<div class="profession-card">
			<h2><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %d: Number of tools */
					esc_html__( 'Professions can access all %d core tools plus profession-specific tool recommendations.', 'mcp-ai-wpoos' ),
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

		<div class="profession-card">
			<h2><?php esc_html_e( 'Tool Recommendations', 'mcp-ai-wpoos' ); ?></h2>
			<p><?php esc_html_e( 'Each profession has a tool recommendation system that suggests relevant tools based on the profession type.', 'mcp-ai-wpoos' ); ?></p>
			<p><?php esc_html_e( 'Tool recommendations are automatically configured when you create or edit professions.', 'mcp-ai-wpoos' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Get tools list for professions.
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		// Get core WordPress tools available to professions.
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
		<div class="profession-card">
			<h2><?php esc_html_e( 'Quick Start Guide', 'mcp-ai-wpoos' ); ?></h2>
			<ol>
				<li><strong><?php esc_html_e( 'Create a Profession:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Use the "Create New Profession" button to add a new AI profession', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Configure Settings:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Set the AI provider, model, and temperature in the profession metabox', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Add Instructions:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Provide role-specific instructions and guidelines', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Select Tools:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Enable recommended tools or manually select tools', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Test:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Use the Test Profession page to verify functionality', 'mcp-ai-wpoos' ); ?></li>
			</ol>
		</div>

		<div class="profession-card">
			<h2><?php esc_html_e( 'Support & Documentation', 'mcp-ai-wpoos' ); ?></h2>
			<p><?php esc_html_e( 'For more information and detailed documentation:', 'mcp-ai-wpoos' ); ?></p>
			<ul>
				<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/tool-reference.md" target="_blank"><?php esc_html_e( 'Tool Reference Documentation', 'mcp-ai-wpoos' ); ?></a></li>
				<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues" target="_blank"><?php esc_html_e( 'Report Issues or Request Features', 'mcp-ai-wpoos' ); ?></a></li>
			</ul>
		</div>

		<div class="profession-card">
			<h2><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos' ); ?></h2>
			<p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>" class="button">
					<?php esc_html_e( 'View All Professions', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Create New Profession', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type . '&page=wp-mcp-ai-test-profession' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Test Profession', 'mcp-ai-wpoos' ); ?>
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
		if ( isset( $_POST['wp_mcp_ai_profession_default_provider'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
			update_option( 'wp_mcp_ai_profession_default_provider', sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_profession_default_provider'] ) ) );
		}

		// Sanitize and save model.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
		if ( isset( $_POST['wp_mcp_ai_profession_default_model'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
			update_option( 'wp_mcp_ai_profession_default_model', sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_profession_default_model'] ) ) );
		}

		// Sanitize and save temperature.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
		if ( isset( $_POST['wp_mcp_ai_profession_default_temperature'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
			$temperature = floatval( wp_unslash( $_POST['wp_mcp_ai_profession_default_temperature'] ) );
			$temperature = max( 0, min( 1, $temperature ) ); // Clamp between 0 and 1.
			update_option( 'wp_mcp_ai_profession_default_temperature', $temperature );
		}

		// Redirect with success message.
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', admin_url( 'edit.php?post_type=mcp_ai_profession&page=wp-mcp-ai-profession-settings' ) ) );
		exit;
	}
}
