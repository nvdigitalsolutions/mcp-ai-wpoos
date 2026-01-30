<?php
/**
 * Site Creator Toolkit Settings Page
 *
 * Admin page for configuring the Site Creator toolkit with page/section/widget builders,
 * template management, and Architect Agent integration.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Creator Toolkit Settings Page
 *
 * Provides configuration options for the Site Creator toolkit including:
 * - Research and discovery tools
 * - Page builder capabilities
 * - Section builder tools
 * - Widget builder features
 * - Template management
 * - Architect Agent integration
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Site_Creator_Toolkit_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ), 20 );
	}

	/**
	 * Add settings page to admin menu.
	 *
	 * @since 1.2.0
	 */
	public function add_settings_page() {
		// Add top-level menu page for Site Creator.
		add_menu_page(
			__( 'Site Creator', 'mcp-ai-wpoos-pro' ),
			__( 'Site Creator', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'nvoos-site-creator',
			array( $this, 'render_settings_page' ),
			'dashicons-admin-site-alt3',
			31
		);

		// Remove the auto-generated submenu item (has same title as top-level menu).
		remove_submenu_page( 'nvoos-site-creator', 'nvoos-site-creator' );

		// Add submenu items for Site Creator.
		add_submenu_page(
			'nvoos-site-creator',
			__( 'Overview', 'mcp-ai-wpoos-pro' ),
			__( 'Overview', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'nvoos-site-creator',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'nvoos-site-creator',
			__( 'Tools', 'mcp-ai-wpoos-pro' ),
			__( 'Tools', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'nvoos-site-creator-tools',
			array( $this, 'render_tools_page' )
		);

		add_submenu_page(
			'nvoos-site-creator',
			__( 'Templates', 'mcp-ai-wpoos-pro' ),
			__( 'Templates', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'nvoos-site-creator-templates',
			array( $this, 'render_templates_page' )
		);

		add_submenu_page(
			'nvoos-site-creator',
			__( 'Research & Add', 'mcp-ai-wpoos-pro' ),
			__( 'Research & Add', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'nvoos-site-creator-research',
			array( $this, 'render_research_page' )
		);

		add_submenu_page(
			'nvoos-site-creator',
			__( 'Consolidate & Add', 'mcp-ai-wpoos-pro' ),
			__( 'Consolidate & Add', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'nvoos-site-creator-consolidate',
			array( $this, 'render_consolidate_page' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @since 1.2.0
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Site Creator Toolkit', 'mcp-ai-wpoos-pro' ); ?></h1>

			<div class="card">
				<h2><?php esc_html_e( 'About Site Creator Toolkit', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'The Site Creator Toolkit provides advanced AI-powered tools for automated WordPress site creation, following industry best practices and modern standards. It integrates with the Architect Agent for self-editing capabilities and automated development workflows.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>

				<h3><?php esc_html_e( 'Available Tool Categories', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'Research & Discovery (4 tools)', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Web search for best practices, competitor analysis, site planning, template suggestions', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Page Building (5 tools)', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Landing pages, homepage layouts, about pages, service pages, blog layouts', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Section Building (6 tools)', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Hero sections, features, testimonials, CTAs, galleries, contact sections', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Widget Building (4 tools)', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Custom widgets, navigation menus, sidebar widgets, footer widgets', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Template Management (4 tools)', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Save/import/export templates, version control', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Integration Tools (3 tools)', 'mcp-ai-wpoos-pro' ); ?></strong> - <?php esc_html_e( 'Architect Agent integration, theme scaffolding, automated workflows', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Industry best practices from 2025 web standards', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Performance optimization (Core Web Vitals)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Accessibility compliance (WCAG 2.2)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Mobile-first responsive design', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'SEO optimization', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'AI-enhanced workflows', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Block-based design (Gutenberg compatible)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Elementor integration', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Integration with Architect Agent', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<?php
					esc_html_e(
						'When enabled, the Site Creator Toolkit integrates with the Architect Agent to provide:',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>
				<ul>
					<li><?php esc_html_e( 'Automated code generation (PHP, CSS, JavaScript)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Self-editing capabilities for generated code', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Version control integration (Git)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Quality assurance checks (linting, testing)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Automated development workflows', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<?php
					printf(
						/* translators: %s: documentation file path */
						esc_html__( 'For complete setup instructions and usage examples, see %s', 'mcp-ai-wpoos-pro' ),
						'<code>addons/pro/includes/tools/site-creator-toolkit/README.md</code>'
					);
					?>
				</p>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'To enable or disable this toolkit, go to Settings → NV oOS → Tools → Features and toggle the "Enable Site Creator Toolkit" option.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=features' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Go to Features Settings', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>

				<?php
				// Show current status.
				$settings   = get_option( 'wp_mcp_ai_settings', array() );
				$is_enabled = ! empty( $settings['enable_site_creator_toolkit'] );
				?>
				<p>
					<strong><?php esc_html_e( 'Toolkit Status:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php if ( $is_enabled ) : ?>
						<span style="color: #46b450;">✓ <?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></span>
					<?php else : ?>
						<span style="color: #dc3232;">✗ <?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></span>
					<?php endif; ?>
				</p>
			</div>

			<?php if ( $is_enabled ) : ?>
			<div class="card">
				<h2><?php esc_html_e( 'Permissions', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'Configure what actions AI assistants are allowed to perform when using Site Creator tools. All permissions require manage_options capability.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'wp_mcp_ai_settings' );
					?>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Site Creator', 'mcp-ai-wpoos-pro' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wp_mcp_ai_settings[enable_site_creator]" value="1" <?php checked( ! empty( $settings['enable_site_creator'] ) ); ?> />
										<?php esc_html_e( 'Allow AI to create and configure sites', 'mcp-ai-wpoos-pro' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'When enabled, AI assistants can use site creator tools to automatically install themes, plugins, update options, and create content.', 'mcp-ai-wpoos-pro' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Plugin Installation', 'mcp-ai-wpoos-pro' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wp_mcp_ai_settings[site_creator_allow_plugin_install]" value="1" <?php checked( ! empty( $settings['site_creator_allow_plugin_install'] ) ); ?> />
										<?php esc_html_e( 'Enable automatic plugin installation from WordPress.org', 'mcp-ai-wpoos-pro' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Allows AI to install and activate plugins from the WordPress.org repository. Plugins are only installed from trusted WordPress.org sources.', 'mcp-ai-wpoos-pro' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Theme Installation', 'mcp-ai-wpoos-pro' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wp_mcp_ai_settings[site_creator_allow_theme_install]" value="1" <?php checked( ! empty( $settings['site_creator_allow_theme_install'] ) ); ?> />
										<?php esc_html_e( 'Enable automatic theme installation from WordPress.org', 'mcp-ai-wpoos-pro' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Allows AI to install and activate themes from the WordPress.org repository. Themes are only installed from trusted WordPress.org sources.', 'mcp-ai-wpoos-pro' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Option Updates', 'mcp-ai-wpoos-pro' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wp_mcp_ai_settings[site_creator_allow_option_updates]" value="1" <?php checked( ! empty( $settings['site_creator_allow_option_updates'] ) ); ?> />
										<?php esc_html_e( 'Enable automatic WordPress option updates', 'mcp-ai-wpoos-pro' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Allows AI to update WordPress options (e.g., blogname, blogdescription) via the update_option tool.', 'mcp-ai-wpoos-pro' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'WP-CLI Tools', 'mcp-ai-wpoos-pro' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wp_mcp_ai_settings[site_creator_allow_wp_cli_tools]" value="1" <?php checked( ! empty( $settings['site_creator_allow_wp_cli_tools'] ) ); ?> />
										<?php esc_html_e( 'Enable WP-CLI inspection and execution tools', 'mcp-ai-wpoos-pro' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Allows AI to inspect and interact with the WP-CLI environment. This includes checking WP-CLI availability and version information.', 'mcp-ai-wpoos-pro' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Elementor Kit Import', 'mcp-ai-wpoos-pro' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wp_mcp_ai_settings[site_creator_allow_elementor_kit_import]" value="1" <?php checked( ! empty( $settings['site_creator_allow_elementor_kit_import'] ) ); ?> />
										<?php esc_html_e( 'Enable Elementor template kit import', 'mcp-ai-wpoos-pro' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Allows AI to import Elementor template kits from the Media Library. Requires Elementor to be active.', 'mcp-ai-wpoos-pro' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( 'Save Permissions', 'mcp-ai-wpoos-pro' ) ); ?>
				</form>
			</div>
			<?php endif; ?>

			<div class="card">
				<h2><?php esc_html_e( 'Template Management', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'Site templates, page templates, and reusable sections are stored as custom post types. You can manage them from the WordPress admin menu.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>
				<?php if ( $is_enabled ) : ?>
					<p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=wp_site_template' ) ); ?>" class="button">
							<?php esc_html_e( 'Manage Site Templates', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Security & Best Practices', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'The Site Creator Toolkit follows WordPress security best practices:',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>
				<ul>
					<li><?php esc_html_e( 'Requires manage_options capability for all operations', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Input sanitization and output escaping', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Nonce verification for state changes', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Comprehensive audit logging', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Generated code follows WordPress Coding Standards', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Requirements', 'mcp-ai-wpoos-pro' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'PHP 7.4 or higher', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'WordPress 6.0 or higher', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'NV oOS Pro addon', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'OpenAI API key (for AI-powered features)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Architect Agent Toolkit (for automated development)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Optional: Elementor (for enhanced page building)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Optional: JetEngine (for CCT storage)', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Warning', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p style="color: #d63638;">
					<strong><?php esc_html_e( 'Important:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php
					esc_html_e(
						'The Site Creator Toolkit provides powerful capabilities that enable AI agents to generate and modify site structure and code. Only grant access to trusted administrators with manage_options capability. Always use in development environments with version control and backups.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the tools page.
	 *
	 * @since 1.2.0
	 */
	public function render_tools_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Site Creator Tools', 'mcp-ai-wpoos-pro' ); ?></h1>

			<div class="card">
				<h2><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'The Site Creator Toolkit provides 26 specialized tools across 6 categories for automated WordPress site creation.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>

				<h3><?php esc_html_e( 'Tool Categories', 'mcp-ai-wpoos-pro' ); ?></h3>

				<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
					<div class="card" style="margin: 0;">
						<h4><span class="dashicons dashicons-search" style="color: #2271b1;"></span> <?php esc_html_e( 'Research & Discovery', 'mcp-ai-wpoos-pro' ); ?></h4>
						<p><?php esc_html_e( '4 tools for best practices, competitor analysis, site planning, and template suggestions', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<div class="card" style="margin: 0;">
						<h4><span class="dashicons dashicons-admin-page" style="color: #2271b1;"></span> <?php esc_html_e( 'Page Building', 'mcp-ai-wpoos-pro' ); ?></h4>
						<p><?php esc_html_e( '5 tools for landing pages, homepage layouts, about pages, service pages, and blog layouts', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<div class="card" style="margin: 0;">
						<h4><span class="dashicons dashicons-editor-table" style="color: #2271b1;"></span> <?php esc_html_e( 'Section Building', 'mcp-ai-wpoos-pro' ); ?></h4>
						<p><?php esc_html_e( '6 tools for hero sections, features, testimonials, CTAs, galleries, and contact sections', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<div class="card" style="margin: 0;">
						<h4><span class="dashicons dashicons-layout" style="color: #2271b1;"></span> <?php esc_html_e( 'Widget Building', 'mcp-ai-wpoos-pro' ); ?></h4>
						<p><?php esc_html_e( '4 tools for custom widgets, navigation menus, sidebar widgets, and footer widgets', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<div class="card" style="margin: 0;">
						<h4><span class="dashicons dashicons-portfolio" style="color: #2271b1;"></span> <?php esc_html_e( 'Template Management', 'mcp-ai-wpoos-pro' ); ?></h4>
						<p><?php esc_html_e( '4 tools for saving, importing, exporting templates, and version control', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<div class="card" style="margin: 0;">
						<h4><span class="dashicons dashicons-admin-tools" style="color: #2271b1;"></span> <?php esc_html_e( 'Integration Tools', 'mcp-ai-wpoos-pro' ); ?></h4>
						<p><?php esc_html_e( '3 tools for Architect Agent integration, theme scaffolding, and automated workflows', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				</div>

				<h3 style="margin-top: 30px;"><?php esc_html_e( 'Tool Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<?php
					esc_html_e(
						'To enable or disable individual tools, go to the main NV oOS Settings page.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=site_creator' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Configure Tools', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the templates page.
	 *
	 * @since 1.2.0
	 */
	public function render_templates_page() {
		$settings   = get_option( 'wp_mcp_ai_settings', array() );
		$is_enabled = ! empty( $settings['enable_site_creator_toolkit'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Site Creator Templates', 'mcp-ai-wpoos-pro' ); ?></h1>

			<?php if ( ! $is_enabled ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'Site Creator Toolkit is not enabled.', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php esc_html_e( 'Enable it in the main settings to use template features.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=site_creator' ) ); ?>" class="button">
							<?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<div class="card">
				<h2><?php esc_html_e( 'Template Management', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'Site templates, page templates, and reusable sections created by the Site Creator Toolkit are stored as custom post types.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>

				<?php if ( $is_enabled ) : ?>
					<h3><?php esc_html_e( 'Manage Templates', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=wp_site_template' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'View All Site Templates', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>

					<h3 style="margin-top: 30px;"><?php esc_html_e( 'Template Features', 'mcp-ai-wpoos-pro' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'Save templates from generated sites', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Import/export templates between sites', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Version control for template changes', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Reusable sections and components', 'mcp-ai-wpoos-pro' ); ?></li>
					</ul>
				<?php endif; ?>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Template Best Practices', 'mcp-ai-wpoos-pro' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'Use descriptive names for templates', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Test templates in staging before production', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Keep templates updated with latest best practices', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Document any custom modifications', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Use version control for tracking changes', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the research & add page.
	 *
	 * @since 1.2.0
	 */
	public function render_research_page() {
		$settings   = get_option( 'wp_mcp_ai_settings', array() );
		$is_enabled = ! empty( $settings['enable_site_creator_toolkit'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Research & Add Site Templates', 'mcp-ai-wpoos-pro' ); ?></h1>

			<?php if ( ! $is_enabled ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'Site Creator Toolkit is not enabled.', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php esc_html_e( 'Enable it in the main settings to use research features.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=site_creator' ) ); ?>" class="button">
							<?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<div class="card">
				<h2><?php esc_html_e( 'AI-Powered Site Research', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'Use AI assistance to research and plan site templates before creating them. The AI can help you discover best practices, analyze competitor sites, suggest layouts, and plan complete site structures.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>

				<h3><?php esc_html_e( 'Research Capabilities', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'Best Practices Research:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Discover industry standards and modern web design principles', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Competitor Analysis:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Analyze competitor websites for inspiration and insights', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Site Planning:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Generate comprehensive site plans and architecture', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Template Suggestions:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Get AI-powered template and pattern recommendations', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Content Strategy:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Plan content structure and information architecture', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<?php if ( $is_enabled ) : ?>
					<h3><?php esc_html_e( 'How to Use', 'mcp-ai-wpoos-pro' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Start a conversation with the AI assistant below', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Describe your site requirements or ask research questions', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Review AI suggestions and research findings', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Refine your requirements through conversation', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Create site templates directly from the research insights', 'mcp-ai-wpoos-pro' ); ?></li>
					</ol>

					<div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 4px;">
						<h3 style="margin-top: 0;"><?php esc_html_e( 'AI Assistant', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p><?php esc_html_e( 'Chat with the AI assistant to research and plan your site templates:', 'mcp-ai-wpoos-pro' ); ?></p>
						
						<?php
						// Render the chat interface.
						if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
							echo do_shortcode( '[nvoos-ai-chat assistant="site-creator-research" mode="inline"]' );
						} else {
							?>
							<div class="notice notice-info inline">
								<p><?php esc_html_e( 'Chat interface not available. Please ensure NV oOS is properly configured.', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<?php
						}
						?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $is_enabled ) : ?>
				<div class="card">
					<h2><?php esc_html_e( 'Example Research Questions', 'mcp-ai-wpoos-pro' ); ?></h2>
					<ul>
						<li>"What are the best practices for e-commerce homepage design in 2025?"</li>
						<li>"Analyze the top 3 SaaS landing pages and suggest a layout"</li>
						<li>"Create a site plan for a restaurant website with online ordering"</li>
						<li>"What sections should I include in a professional services website?"</li>
						<li>"Suggest template patterns for a portfolio website"</li>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the consolidate & add page.
	 *
	 * @since 1.2.0
	 */
	public function render_consolidate_page() {
		$settings   = get_option( 'wp_mcp_ai_settings', array() );
		$is_enabled = ! empty( $settings['enable_site_creator_toolkit'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Consolidate & Add Site Templates', 'mcp-ai-wpoos-pro' ); ?></h1>

			<?php if ( ! $is_enabled ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'Site Creator Toolkit is not enabled.', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php esc_html_e( 'Enable it in the main settings to use consolidation features.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=site_creator' ) ); ?>" class="button">
							<?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<div class="card">
				<h2><?php esc_html_e( 'Bulk Import & Consolidation', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'Import and consolidate site templates from various sources. Batch process multiple templates, validate data quality, and streamline your template library management.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>

				<h3><?php esc_html_e( 'Import Options', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'JSON Import:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Import site templates from JSON files', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'ZIP Archives:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Import complete template kits from ZIP files', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'XML Import:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Import from XML format (WordPress export compatible)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'URL Import:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Import templates directly from remote URLs', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Batch Processing:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Process multiple templates in a single operation', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<?php if ( $is_enabled ) : ?>
					<h3><?php esc_html_e( 'Consolidation Features', 'mcp-ai-wpoos-pro' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'Duplicate detection and merging', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Data quality validation', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Automatic categorization', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Preview before importing', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Rollback support for failed imports', 'mcp-ai-wpoos-pro' ); ?></li>
					</ul>

					<div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 4px;">
						<h3 style="margin-top: 0;"><?php esc_html_e( 'Import Templates', 'mcp-ai-wpoos-pro' ); ?></h3>
						
						<form method="post" enctype="multipart/form-data" style="max-width: 600px;">
							<?php wp_nonce_field( 'wp_mcp_ai_template_import', 'wp_mcp_ai_template_import_nonce' ); ?>
							
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">
										<label for="import_type"><?php esc_html_e( 'Import Type', 'mcp-ai-wpoos-pro' ); ?></label>
									</th>
									<td>
										<select name="import_type" id="import_type" class="regular-text">
											<option value="json"><?php esc_html_e( 'JSON File', 'mcp-ai-wpoos-pro' ); ?></option>
											<option value="zip"><?php esc_html_e( 'ZIP Archive', 'mcp-ai-wpoos-pro' ); ?></option>
											<option value="xml"><?php esc_html_e( 'XML File', 'mcp-ai-wpoos-pro' ); ?></option>
											<option value="url"><?php esc_html_e( 'Remote URL', 'mcp-ai-wpoos-pro' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="import_file"><?php esc_html_e( 'File', 'mcp-ai-wpoos-pro' ); ?></label>
									</th>
									<td>
										<input type="file" name="import_file" id="import_file" class="regular-text" accept=".json,.zip,.xml" />
										<p class="description"><?php esc_html_e( 'Select a file to import', 'mcp-ai-wpoos-pro' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="import_url"><?php esc_html_e( 'URL', 'mcp-ai-wpoos-pro' ); ?></label>
									</th>
									<td>
										<input type="url" name="import_url" id="import_url" class="regular-text" placeholder="https://example.com/template.json" />
										<p class="description"><?php esc_html_e( 'Or enter a URL to import from', 'mcp-ai-wpoos-pro' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Options', 'mcp-ai-wpoos-pro' ); ?></th>
									<td>
										<label style="display: block; margin-bottom: 8px;">
											<input type="checkbox" name="validate_data" value="1" checked />
											<?php esc_html_e( 'Validate data quality before importing', 'mcp-ai-wpoos-pro' ); ?>
										</label>
										<label style="display: block; margin-bottom: 8px;">
											<input type="checkbox" name="check_duplicates" value="1" checked />
											<?php esc_html_e( 'Check for duplicates', 'mcp-ai-wpoos-pro' ); ?>
										</label>
										<label style="display: block; margin-bottom: 8px;">
											<input type="checkbox" name="auto_categorize" value="1" checked />
											<?php esc_html_e( 'Automatically categorize templates', 'mcp-ai-wpoos-pro' ); ?>
										</label>
										<label style="display: block;">
											<input type="checkbox" name="preview_mode" value="1" />
											<?php esc_html_e( 'Preview only (do not import)', 'mcp-ai-wpoos-pro' ); ?>
										</label>
									</td>
								</tr>
							</table>

							<p class="submit">
								<button type="submit" name="action" value="import" class="button button-primary">
									<span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
									<?php esc_html_e( 'Import Templates', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</p>
						</form>

						<div style="margin-top: 20px; padding: 15px; background: #fff8e5; border-left: 4px solid #dba617; border-radius: 4px;">
							<p style="margin: 0;">
								<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php esc_html_e( 'Large imports may take several minutes. Please do not close this page during import.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $is_enabled ) : ?>
				<div class="card">
					<h2><?php esc_html_e( 'AI-Assisted Consolidation', 'mcp-ai-wpoos-pro' ); ?></h2>
					<p><?php esc_html_e( 'Use the AI assistant to help with template consolidation and data cleanup:', 'mcp-ai-wpoos-pro' ); ?></p>
					
					<?php
					// Render the chat interface.
					if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
						echo do_shortcode( '[nvoos-ai-chat assistant="site-creator-consolidate" mode="inline"]' );
					} else {
						?>
						<div class="notice notice-info inline">
							<p><?php esc_html_e( 'Chat interface not available. Please ensure NV oOS is properly configured.', 'mcp-ai-wpoos-pro' ); ?></p>
						</div>
						<?php
					}
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

// Initialize the settings page.
new WP_MCP_AI_Site_Creator_Toolkit_Settings_Page();
