<?php
/**
 * Profession settings page (Wave E-UI-3, sub-cluster 1).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Admin_Profession_Settings`
 * (`includes/admin/class-wp-mcp-ai-admin-profession-settings.php`):
 * byte-identical page surface — the `wp-mcp-ai-profession-settings`
 * page slug (position 25 under the profession CPT menu,
 * `manage_options`), the three registered settings
 * (`wp_mcp_ai_profession_default_provider|_model|_temperature` with
 * their sanitize callbacks), the tabbed render (overview /
 * configuration / available-tools / help), the configuration form
 * (provider select, model + temperature inputs, settings-hierarchy
 * card), the tools list + recommendation cards, the help/quick-start
 * + support cards, the inline stylesheet, and the nonce-gated save
 * flow with the temperature clamp (0–1) and settings-updated
 * redirect.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform addon's PSR-4 tree (decision
 *    D-UI/E-UI: operator admin UI ports land in
 *    `nvoos-content-graph-ai-platform` under `Admin\Integrations\`
 *    (the E-UI-3 wave folder).
 *  - The base's constructor-driven hook wiring becomes a static
 *    `register()` — wired standalone-only via
 *    `Plugin::registerIntegrationsScreens()`; the base loader owns the
 *    same page monolith (eager container instantiation).
 *  - The provider list resolves per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator — the base
 *    `WP_MCP_AI_Admin_Settings::get_available_providers()` monolith /
 *    empty list standalone, documented).
 *  - A `PAGE_SLUG` constant is added (the base hardcodes the slug —
 *    additive, documented).
 *  - The base's `private` helpers become `protected` — widening
 *    visibility is additive and lets the characterization suite expose
 *    them without reflection (documented deviation).
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Integrations
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Integrations;

/**
 * Profession Settings admin page handler.
 *
 * @since 2.0.0
 */
class ProfessionSettings {

	/**
	 * Page slug (the base hardcodes it — promoted to a constant,
	 * additive).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-profession-settings';

	/**
	 * Page hook suffix.
	 *
	 * @var string|false
	 */
	protected $page_hook;

	/**
	 * Register the page hooks (standalone-only — see the class docblock).
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'register_submenu_page' ), 25 );
		\add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Profession post type slug (per-mode seam).
	 *
	 * @return string
	 */
	protected static function profession_post_type() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
			return \WP_MCP_AI_Profession_CPT::POST_TYPE;
		}

		if ( \class_exists( 'NvoosContentGraphAiPlatform\Professions\ProfessionCpt' ) ) {
			return \NvoosContentGraphAiPlatform\Professions\ProfessionCpt::POST_TYPE;
		}

		return 'mcp_ai_profession';
	}

	/**
	 * Available provider list (per-mode seam).
	 *
	 * @return array
	 */
	protected static function available_providers() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return \WP_MCP_AI_Admin_Settings::get_available_providers();
		}

		return array();
	}

	/**
	 * Register the submenu page under Professions CPT.
	 *
	 * @return void
	 */
	public function register_submenu_page(): void {
		$this->page_hook = \add_submenu_page(
			'edit.php?post_type=' . self::profession_post_type(),
			__( 'Profession Settings', 'nvoos-content-graph-ai-platform' ),
			__( 'Settings', 'nvoos-content-graph-ai-platform' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		\register_setting(
			'wp_mcp_ai_profession_settings_group',
			'wp_mcp_ai_profession_default_provider',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
		\register_setting(
			'wp_mcp_ai_profession_settings_group',
			'wp_mcp_ai_profession_default_model',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
		\register_setting(
			'wp_mcp_ai_profession_settings_group',
			'wp_mcp_ai_profession_default_temperature',
			array( 'sanitize_callback' => 'floatval' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-content-graph-ai-platform' ) );
		}

		// Handle form submission.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is explicitly verified on the next line before the save path runs.
		if ( isset( $_POST['wp_mcp_ai_profession_settings_nonce'] ) && \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['wp_mcp_ai_profession_settings_nonce'] ) ), 'wp_mcp_ai_profession_settings' ) ) {
			$this->save_settings();
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Get active tab.
		$active_tab = isset( $_GET['tab'] ) ? \sanitize_key( \wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation parameter; not a state-changing operation.

		// Get post type for links.
		$post_type = self::profession_post_type();

		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-groups" style="font-size: 32px;"></span>
				<?php \esc_html_e( 'Profession Settings', 'nvoos-content-graph-ai-platform' ); ?>
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

		<?php
		\wp_add_inline_style(
			'wp-mcp-ai-profession-settings',
			'.profession-settings-nav{border-bottom:1px solid #ccd0d4;margin:20px 0;}'
			. '.profession-settings-nav a{display:inline-block;padding:10px 15px;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;}'
			. '.profession-settings-nav a.nav-tab-active{border-bottom-color:#2271b1;font-weight:600;}'
			. '.profession-settings-content{margin-top:20px;}'
			. '.profession-card{background:#fff;border:1px solid #ccd0d4;box-shadow:0 1px 1px rgba(0,0,0,.04);padding:20px;margin-bottom:20px;}'
			. '.profession-card h2{margin-top:0;}'
			. '.tool-item{padding:10px;border-bottom:1px solid #f0f0f1;}'
			. '.tool-item:last-child{border-bottom:none;}'
			. '.tool-item strong{display:inline-block;min-width:200px;}'
		);
		?>
		<?php
	}

	/**
	 * Render tab navigation.
	 *
	 * @param string $active_tab Active tab slug.
	 * @param string $post_type  Post type slug.
	 * @return void
	 */
	protected function render_tabs( $active_tab, $post_type ): void {
		$tabs = array(
			'overview'      => __( 'Overview', 'nvoos-content-graph-ai-platform' ),
			'configuration' => __( 'Configuration', 'nvoos-content-graph-ai-platform' ),
			'tools'         => __( 'Available Tools', 'nvoos-content-graph-ai-platform' ),
			'help'          => __( 'Help & Documentation', 'nvoos-content-graph-ai-platform' ),
		);

		?>
		<nav class="profession-settings-nav nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab_title ) : ?>
				<a
					href="<?php echo \esc_url( \add_query_arg( 'tab', $tab_slug, \admin_url( 'edit.php?post_type=' . $post_type . '&page=' . self::PAGE_SLUG ) ) ); ?>"
					class="nav-tab <?php echo \esc_attr( $active_tab === $tab_slug ? 'nav-tab-active' : '' ); ?>"
				>
					<?php echo \esc_html( $tab_title ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render overview tab.
	 *
	 * @return void
	 */
	protected function render_overview_tab(): void {
		?>
		<div class="profession-card">
			<h2><?php \esc_html_e( 'AI Professions Overview', 'nvoos-content-graph-ai-platform' ); ?></h2>

			<div class="profession-description">
				<p><?php \esc_html_e( 'AI Professions are specialized AI agents designed for specific professional roles. Each profession comes with tailored instructions, recommended tools, and industry-specific knowledge to provide expert assistance.', 'nvoos-content-graph-ai-platform' ); ?></p>
			</div>

			<h3><?php \esc_html_e( 'Key Features', 'nvoos-content-graph-ai-platform' ); ?></h3>
			<ul>
				<li><?php \esc_html_e( 'Role-Based AI: Pre-configured AI agents for specific professions and industries', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><?php \esc_html_e( 'Tool Recommendations: Automatically suggest relevant tools based on profession', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><?php \esc_html_e( 'Custom Instructions: Profession-specific prompts and behavioral guidelines', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><?php \esc_html_e( 'Knowledge Base: Industry-specific documents and playbooks', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><?php \esc_html_e( 'Configuration Cascade: Global defaults with profession-level overrides', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><?php \esc_html_e( 'Provider Flexibility: Support for OpenAI, Gemini, Ollama, and more', 'nvoos-content-graph-ai-platform' ); ?></li>
			</ul>

			<h3><?php \esc_html_e( 'Use Cases', 'nvoos-content-graph-ai-platform' ); ?></h3>
			<ul>
				<li><strong><?php \esc_html_e( 'Content Creation:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Writers, bloggers, marketers', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><strong><?php \esc_html_e( 'Development:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'WordPress developers, plugin developers', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><strong><?php \esc_html_e( 'Business:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Consultants, entrepreneurs, project managers', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><strong><?php \esc_html_e( 'Creative:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Designers, architects, DJs, event planners', 'nvoos-content-graph-ai-platform' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render configuration tab.
	 *
	 * @return void
	 */
	protected function render_configuration_tab(): void {
		// Get current settings.
		$default_provider    = \get_option( 'wp_mcp_ai_profession_default_provider', '' );
		$default_model       = \get_option( 'wp_mcp_ai_profession_default_model', '' );
		$default_temperature = \get_option( 'wp_mcp_ai_profession_default_temperature', 0.7 );

		$available_providers = self::available_providers();

		?>
		<div class="profession-card">
			<h2><?php \esc_html_e( 'Configuration', 'nvoos-content-graph-ai-platform' ); ?></h2>

			<p class="description">
				<?php \esc_html_e( 'Configure global default settings for all AI Professions. These settings provide baseline values that cascade to all professions, but can be overridden at the individual profession level via metaboxes.', 'nvoos-content-graph-ai-platform' ); ?>
			</p>

			<?php if ( isset( $_GET['settings-updated'] ) && 'true' === \sanitize_key( \wp_unslash( $_GET['settings-updated'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query param set by WordPress after options save; no state change occurs here. ?>
				<div class="notice notice-success inline">
					<p><?php \esc_html_e( 'Settings saved successfully.', 'nvoos-content-graph-ai-platform' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php \wp_nonce_field( 'wp_mcp_ai_profession_settings', 'wp_mcp_ai_profession_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_profession_default_provider">
									<?php \esc_html_e( 'Default AI Provider', 'nvoos-content-graph-ai-platform' ); ?>
								</label>
							</th>
							<td>
								<select name="wp_mcp_ai_profession_default_provider" id="wp_mcp_ai_profession_default_provider" class="regular-text">
									<option value=""><?php \esc_html_e( '-- Use Global Default --', 'nvoos-content-graph-ai-platform' ); ?></option>
									<?php foreach ( $available_providers as $provider_slug => $provider_label ) : ?>
										<option value="<?php echo \esc_attr( $provider_slug ); ?>" <?php \selected( $default_provider, $provider_slug ); ?>>
											<?php echo \esc_html( $provider_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php \esc_html_e( 'Default AI provider for all professions. Individual professions can override this in their metabox settings.', 'nvoos-content-graph-ai-platform' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_profession_default_model">
									<?php \esc_html_e( 'Default Model', 'nvoos-content-graph-ai-platform' ); ?>
								</label>
							</th>
							<td>
								<input type="text" name="wp_mcp_ai_profession_default_model" id="wp_mcp_ai_profession_default_model" value="<?php echo \esc_attr( $default_model ); ?>" class="regular-text" placeholder="<?php \esc_attr_e( 'e.g., gpt-4.1, claude-sonnet-5', 'nvoos-content-graph-ai-platform' ); ?>">
								<p class="description">
									<?php \esc_html_e( 'Default AI model for all professions. Leave empty to use the provider\'s default model. Individual professions can override this setting.', 'nvoos-content-graph-ai-platform' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_profession_default_temperature">
									<?php \esc_html_e( 'Default Temperature', 'nvoos-content-graph-ai-platform' ); ?>
								</label>
							</th>
							<td>
								<input type="number" name="wp_mcp_ai_profession_default_temperature" id="wp_mcp_ai_profession_default_temperature" value="<?php echo \esc_attr( (string) $default_temperature ); ?>" class="small-text" min="0" max="1" step="0.1">
								<p class="description">
									<?php \esc_html_e( 'Default creativity/randomness setting (0.0 = deterministic, 1.0 = creative). Individual professions can override this setting.', 'nvoos-content-graph-ai-platform' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<input type="submit" name="submit" id="submit" class="button button-primary" value="<?php \esc_attr_e( 'Save Settings', 'nvoos-content-graph-ai-platform' ); ?>">
				</p>
			</form>
		</div>

		<div class="profession-card">
			<h2><?php \esc_html_e( 'Settings Hierarchy', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<p class="description">
				<?php \esc_html_e( 'Settings cascade in the following order (higher priority overrides lower):', 'nvoos-content-graph-ai-platform' ); ?>
			</p>
			<ol>
				<li><strong><?php \esc_html_e( 'Individual Profession Settings', 'nvoos-content-graph-ai-platform' ); ?></strong> - <?php \esc_html_e( 'Configured in each profession\'s metabox (highest priority)', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><strong><?php \esc_html_e( 'Profession Global Defaults', 'nvoos-content-graph-ai-platform' ); ?></strong> - <?php \esc_html_e( 'This page (medium priority)', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><strong><?php \esc_html_e( 'Global Plugin Settings', 'nvoos-content-graph-ai-platform' ); ?></strong> - <?php \esc_html_e( 'Site-wide defaults (lowest priority)', 'nvoos-content-graph-ai-platform' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render tools tab.
	 *
	 * @return void
	 */
	protected function render_tools_tab(): void {
		$tools = $this->get_tools_list();
		?>
		<div class="profession-card">
			<h2><?php \esc_html_e( 'Available Tools', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<p class="description">
				<?php
				\printf(
					/* translators: %d: Number of tools */
					\esc_html__( 'Professions can access all %d core tools plus profession-specific tool recommendations.', 'nvoos-content-graph-ai-platform' ),
					\count( $tools )
				);
				?>
			</p>

			<div class="tools-list" style="margin-top: 20px;">
				<?php foreach ( $tools as $tool_slug => $tool_name ) : ?>
					<div class="tool-item">
						<strong><?php echo \esc_html( $tool_name ); ?></strong>
						<code style="margin-left: 10px;"><?php echo \esc_html( $tool_slug ); ?></code>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="profession-card">
			<h2><?php \esc_html_e( 'Tool Recommendations', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<p><?php \esc_html_e( 'Each profession has a tool recommendation system that suggests relevant tools based on the profession type.', 'nvoos-content-graph-ai-platform' ); ?></p>
			<p><?php \esc_html_e( 'Tool recommendations are automatically configured when you create or edit professions.', 'nvoos-content-graph-ai-platform' ); ?></p>
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
			'wp_create_post'        => __( 'Create Post', 'nvoos-content-graph-ai-platform' ),
			'wp_update_post'        => __( 'Update Post', 'nvoos-content-graph-ai-platform' ),
			'wp_create_page'        => __( 'Create Page', 'nvoos-content-graph-ai-platform' ),
			'wp_update_page'        => __( 'Update Page', 'nvoos-content-graph-ai-platform' ),
			'wp_get_posts'          => __( 'Get Posts', 'nvoos-content-graph-ai-platform' ),
			'wp_search_content'     => __( 'Search Content', 'nvoos-content-graph-ai-platform' ),
			'wp_get_post_meta'      => __( 'Get Post Metadata', 'nvoos-content-graph-ai-platform' ),
			'wp_update_post_meta'   => __( 'Update Post Metadata', 'nvoos-content-graph-ai-platform' ),
			'generate_openai_image' => __( 'Generate Image (OpenAI)', 'nvoos-content-graph-ai-platform' ),
			'generate_gemini_image' => __( 'Generate Image (Gemini)', 'nvoos-content-graph-ai-platform' ),
			'resize_image'          => __( 'Resize Image', 'nvoos-content-graph-ai-platform' ),
			'crop_image'            => __( 'Crop Image', 'nvoos-content-graph-ai-platform' ),
			'rotate_image'          => __( 'Rotate Image', 'nvoos-content-graph-ai-platform' ),
			'web_scrape'            => __( 'Web Scrape', 'nvoos-content-graph-ai-platform' ),
			'web_search'            => __( 'Web Search', 'nvoos-content-graph-ai-platform' ),
			'create_assistant'      => __( 'Create Assistant', 'nvoos-content-graph-ai-platform' ),
		);
	}

	/**
	 * Render help & documentation tab.
	 *
	 * @param string $post_type Post type slug.
	 * @return void
	 */
	protected function render_help_tab( $post_type ): void {
		?>
		<div class="profession-card">
			<h2><?php \esc_html_e( 'Quick Start Guide', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<ol>
				<li><strong><?php \esc_html_e( 'Create a Profession:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Use the "Create New Profession" button to add a new AI profession', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><strong><?php \esc_html_e( 'Configure Settings:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Set the AI provider, model, and temperature in the profession metabox', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><strong><?php \esc_html_e( 'Add Instructions:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Provide role-specific instructions and guidelines', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><strong><?php \esc_html_e( 'Select Tools:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Enable recommended tools or manually select tools', 'nvoos-content-graph-ai-platform' ); ?></li>
				<li><strong><?php \esc_html_e( 'Test:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Use the Test Profession page to verify functionality', 'nvoos-content-graph-ai-platform' ); ?></li>
			</ol>
		</div>

		<div class="profession-card">
			<h2><?php \esc_html_e( 'Support & Documentation', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<p><?php \esc_html_e( 'For more information and detailed documentation:', 'nvoos-content-graph-ai-platform' ); ?></p>
			<ul>
				<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/tool-reference.md" target="_blank"><?php \esc_html_e( 'Tool Reference Documentation', 'nvoos-content-graph-ai-platform' ); ?></a></li>
				<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues" target="_blank"><?php \esc_html_e( 'Report Issues or Request Features', 'nvoos-content-graph-ai-platform' ); ?></a></li>
			</ul>
		</div>

		<div class="profession-card">
			<h2><?php \esc_html_e( 'Quick Actions', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<p>
				<a href="<?php echo \esc_url( \admin_url( 'edit.php?post_type=' . $post_type ) ); ?>" class="button">
					<?php \esc_html_e( 'View All Professions', 'nvoos-content-graph-ai-platform' ); ?>
				</a>
				<a href="<?php echo \esc_url( \admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>" class="button button-primary">
					<?php \esc_html_e( 'Create New Profession', 'nvoos-content-graph-ai-platform' ); ?>
				</a>
				<a href="<?php echo \esc_url( \admin_url( 'edit.php?post_type=' . $post_type . '&page=wp-mcp-ai-test-profession' ) ); ?>" class="button button-secondary">
					<?php \esc_html_e( 'Test Profession', 'nvoos-content-graph-ai-platform' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 *
	 * Note: Nonce verification is done in render_page() before calling this method.
	 *
	 * @return void
	 */
	protected function save_settings(): void {
		// Sanitize and save provider.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
		if ( isset( $_POST['wp_mcp_ai_profession_default_provider'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
			\update_option( 'wp_mcp_ai_profession_default_provider', \sanitize_text_field( \wp_unslash( $_POST['wp_mcp_ai_profession_default_provider'] ) ) );
		}

		// Sanitize and save model.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
		if ( isset( $_POST['wp_mcp_ai_profession_default_model'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
			\update_option( 'wp_mcp_ai_profession_default_model', \sanitize_text_field( \wp_unslash( $_POST['wp_mcp_ai_profession_default_model'] ) ) );
		}

		// Sanitize and save temperature.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
		if ( isset( $_POST['wp_mcp_ai_profession_default_temperature'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_page() before calling this method.
			$temperature = \floatval( \wp_unslash( $_POST['wp_mcp_ai_profession_default_temperature'] ) );
			$temperature = \max( 0, \min( 1, $temperature ) ); // Clamp between 0 and 1.
			\update_option( 'wp_mcp_ai_profession_default_temperature', $temperature );
		}

		// Redirect with success message.
		\wp_safe_redirect( \add_query_arg( 'settings-updated', 'true', \admin_url( 'edit.php?post_type=' . self::profession_post_type() . '&page=' . self::PAGE_SLUG ) ) );
		exit;
	}
}
