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
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Site Creator Toolkit', 'mcp-ai-wpoos-pro' ),
			__( 'Site Creator', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'nvoos-site-creator-toolkit',
			array( $this, 'render_settings_page' )
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
						'To enable or disable this toolkit, go to Settings → NV oOS → Tools and toggle the "Enable Site Creator Toolkit" option.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-settings&tab=tools' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Go to Tools Settings', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>

				<?php
				// Show current status.
				$settings   = get_option( 'wp_mcp_ai_settings', array() );
				$is_enabled = ! empty( $settings['enable_site_creator_toolkit'] );
				?>
				<p>
					<strong><?php esc_html_e( 'Current Status:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php if ( $is_enabled ) : ?>
						<span style="color: #46b450;">✓ <?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></span>
					<?php else : ?>
						<span style="color: #dc3232;">✗ <?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></span>
					<?php endif; ?>
				</p>
			</div>

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
}

// Initialize the settings page.
new WP_MCP_AI_Site_Creator_Toolkit_Settings_Page();
