<?php
/**
 * Toolkit Settings Page Base Class
 *
 * Provides common functionality for all pro toolkit settings pages.
 * Includes tabs for Overview, Configuration, Tools Management, Research & Add, and Help.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for Pro Toolkit Settings Pages
 */
abstract class WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Toolkit slug (e.g., 'ecommerce', 'social_media')
	 *
	 * @var string
	 */
	protected $toolkit_slug;

	/**
	 * Toolkit name (e.g., 'E-commerce Toolkit', 'Social Media Toolkit')
	 *
	 * @var string
	 */
	protected $toolkit_name;

	/**
	 * Settings option name (e.g., 'wp_mcp_ai_ecommerce_toolkit_settings')
	 *
	 * @var string
	 */
	protected $option_name;

	/**
	 * Page slug (e.g., 'wp-mcp-ai-ecommerce-toolkit-settings')
	 *
	 * @var string
	 */
	protected $page_slug;

	/**
	 * Parent menu slug
	 *
	 * @var string
	 */
	protected $parent_slug = 'nvoos-pro-dashboard';

	/**
	 * Whether this toolkit has Research & Add functionality
	 *
	 * @var bool
	 */
	protected $has_research = false;

	/**
	 * Whether this toolkit supports remote sites
	 *
	 * @var bool
	 */
	protected $has_remote_sites = false;

	/**
	 * Toolkit icon dashicon class
	 *
	 * @var string
	 */
	protected $icon = 'dashicons-admin-tools';

	/**
	 * Constructor - sets up hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ), 30 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Get toolkit slug.
	 *
	 * @return string
	 */
	abstract protected function get_toolkit_slug();

	/**
	 * Get toolkit name.
	 *
	 * @return string
	 */
	abstract protected function get_toolkit_name();

	/**
	 * Render overview tab content.
	 */
	abstract protected function render_overview_tab();

	/**
	 * Render configuration tab content.
	 */
	abstract protected function render_configuration_tab();

	/**
	 * Get list of tools for this toolkit.
	 *
	 * @return array Array of tool slugs and names.
	 */
	abstract protected function get_tools_list();

	/**
	 * Add settings submenu page under NV oOS Pro Dashboard.
	 */
	public function add_settings_page() {
		static $registered = array();
		if ( isset( $registered[ $this->page_slug ] ) ) {
			return;
		}
		$registered[ $this->page_slug ] = true;
		add_submenu_page(
			$this->parent_slug,
			$this->toolkit_name . ' ' . __( 'Settings', 'mcp-ai-wpoos-pro' ),
			$this->toolkit_name,
			'manage_options',
			$this->page_slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			$this->option_name . '_group',
			$this->option_name,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		// Configuration section.
		add_settings_section(
			$this->option_name . '_config_section',
			__( 'Configuration', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_config_section_description' ),
			$this->option_name
		);

		// Remote sites section (if supported).
		if ( $this->has_remote_sites ) {
			add_settings_field(
				'enable_remote_sites',
				__( 'Enable Remote Sites', 'mcp-ai-wpoos-pro' ),
				array( $this, 'render_enable_remote_sites_field' ),
				$this->option_name,
				$this->option_name . '_config_section'
			);
		}

		// Research & Add section (if supported).
		if ( $this->has_research ) {
			add_settings_field(
				'enable_research',
				__( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
				array( $this, 'render_enable_research_field' ),
				$this->option_name,
				$this->option_name . '_config_section'
			);

			add_settings_field(
				'research_assistant_id',
				__( 'Research Assistant', 'mcp-ai-wpoos-pro' ),
				array( $this, 'render_research_assistant_field' ),
				$this->option_name,
				$this->option_name . '_config_section'
			);
		}
	}

	/**
	 * Render settings page with tabs.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get active tab.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Check for settings update.
		if ( isset( $_GET['settings-updated'] ) && 'configuration' === $active_tab ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error(
				$this->option_name . '_messages',
				$this->option_name . '_message',
				__( 'Settings saved successfully.', 'mcp-ai-wpoos-pro' ),
				'success'
			);
		}

		settings_errors( $this->option_name . '_messages' );
		?>
		<div class="wrap">
			<h1>
				<span class="dashicons <?php echo esc_attr( $this->icon ); ?>" style="font-size: 32px;"></span>
				<?php echo esc_html( $this->toolkit_name . ' ' . __( 'Settings', 'mcp-ai-wpoos-pro' ) ); ?>
			</h1>

			<?php $this->render_tabs( $active_tab ); ?>

			<div class="toolkit-settings-content">
				<?php
				switch ( $active_tab ) {
					case 'overview':
						$this->render_overview_tab();
						break;
					case 'configuration':
						$this->render_configuration_form();
						break;
					case 'tools':
						$this->render_tools_tab();
						break;
					case 'research':
						if ( $this->has_research ) {
							$this->render_research_tab();
						}
						break;
					case 'remote_sites':
						if ( $this->has_remote_sites ) {
							$this->render_remote_sites_tab();
						}
						break;
					case 'help':
						$this->render_help_tab();
						break;
					case 'mcp_server':
						$this->render_mcp_server_tab();
						break;
					default:
						$this->render_overview_tab();
				}
				?>
			</div><!-- .toolkit-settings-content -->
		</div><!-- .wrap -->

		<style>
			.toolkit-settings-nav {
				border-bottom: 1px solid #ccd0d4;
				margin: 20px 0;
			}
			.toolkit-settings-nav a {
				display: inline-block;
				padding: 10px 15px;
				text-decoration: none;
				border-bottom: 2px solid transparent;
				margin-bottom: -1px;
			}
			.toolkit-settings-nav a.nav-tab-active {
				border-bottom-color: #2271b1;
				font-weight: 600;
			}
			.toolkit-settings-content {
				margin-top: 20px;
			}
			.toolkit-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin-bottom: 20px;
			}
			.toolkit-card h2 {
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

			/* ── MCP Server tab enhancements (Phase B) ── */
			.wp-mcp-ai-status-pill {
				display: inline-block;
				width: 12px;
				height: 12px;
				border-radius: 50%;
				margin-right: 6px;
				vertical-align: middle;
			}
			.wp-mcp-ai-status-pill.enabled {
				background: #46b450;
				box-shadow: 0 0 4px rgba(70,180,80,0.5);
			}
			.wp-mcp-ai-status-pill.disabled {
				background: #dc3232;
				box-shadow: 0 0 4px rgba(220,50,50,0.3);
			}
			.wp-mcp-ai-server-status-table td {
				vertical-align: middle;
				padding: 4px 20px 4px 0;
				font-size: 13px;
			}
			.wp-mcp-ai-tools-badge {
				background: #f0f0f1;
				padding: 2px 10px;
				border-radius: 10px;
				font-size: 13px;
				display: inline-block;
			}
			.wp-mcp-ai-tool-item.filtered-hidden {
				display: none;
			}
			.wp-mcp-ai-copy-endpoint.copied {
				background: #46b450;
				color: #fff;
				border-color: #46b450;
			}
		</style>
		<?php
	}

	/**
	 * Render tab navigation.
	 *
	 * @param string $active_tab Active tab slug.
	 */
	protected function render_tabs( $active_tab ) {
		$tabs = array(
			'overview'      => __( 'Overview', 'mcp-ai-wpoos-pro' ),
			'configuration' => __( 'Configuration', 'mcp-ai-wpoos-pro' ),
			'tools'         => __( 'Tools Management', 'mcp-ai-wpoos-pro' ),
			'help'          => __( 'Help & Documentation', 'mcp-ai-wpoos-pro' ),
		);

		if ( $this->has_research ) {
			$tabs['research'] = __( 'Research & Add', 'mcp-ai-wpoos-pro' );
		}

		if ( $this->has_remote_sites ) {
			$tabs['remote_sites'] = __( 'Remote Sites', 'mcp-ai-wpoos-pro' );
		}

		// Add the MCP Server tab when this toolkit has registered a per-toolkit MCP server.
		if ( $this->get_mcp_server() ) {
			$tabs['mcp_server'] = __( 'MCP Server', 'mcp-ai-wpoos-pro' );
		}

		?>
		<nav class="toolkit-settings-nav nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab_title ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, admin_url( 'admin.php?page=' . $this->page_slug ) ) ); ?>"
					class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded CSS class. ?>"
				>
					<?php echo esc_html( $tab_title ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render configuration form.
	 */
	protected function render_configuration_form() {
		?>
		<div class="toolkit-card">
			<form method="post" action="options.php">
				<?php
				settings_fields( $this->option_name . '_group' );
				do_settings_sections( $this->option_name );
				$this->render_configuration_tab();
				submit_button( __( 'Save Settings', 'mcp-ai-wpoos-pro' ) );
				?>
			</form>
		</div><!-- .toolkit-card -->
		<?php
	}

	/**
	 * Render tools management tab.
	 */
	protected function render_tools_tab() {
		$tools = $this->get_tools_list();
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %d: Number of tools */
					esc_html__( 'This toolkit provides %d AI-powered tools for your assistants.', 'mcp-ai-wpoos-pro' ),
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

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'How to Use These Tools', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p><?php esc_html_e( 'All tools from this toolkit are automatically available to your AI assistants once the toolkit is enabled.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p><?php esc_html_e( 'To enable this toolkit:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'Go to Settings → NV oOS → Tools & Features', 'mcp-ai-wpoos-pro' ); ?></li>
				<li>
					<?php
					/* translators: %s: Toolkit name */
					printf( esc_html__( 'Check the "%s" option', 'mcp-ai-wpoos-pro' ), esc_html( $this->toolkit_name ) );
					?>
				</li>
				<li><?php esc_html_e( 'Save the settings', 'mcp-ai-wpoos-pro' ); ?></li>
			</ol>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=features' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Go to Toolkit Settings', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render research & add tab.
	 */
	protected function render_research_tab() {
		// Check if Research & Add is enabled.
		$settings = get_option( $this->option_name, array() );
		if ( empty( $settings['enable_research'] ) ) {
			?>
			<div class="toolkit-card">
				<h2><?php esc_html_e( 'Research & Add', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p><?php esc_html_e( 'Research & Add functionality allows you to use AI to create and manage data for this toolkit.', 'mcp-ai-wpoos-pro' ); ?></p>
				<p><?php esc_html_e( 'Enable Research & Add in the Configuration tab to access this feature.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			<?php
			return;
		}

		// Load and render toolkit-specific Research & Add implementation.
		$this->render_research_add_ui();
	}

	/**
	 * Render Research & Add UI.
	 * Child classes can override this to provide custom implementation.
	 */
	protected function render_research_add_ui() {
		// Try to load toolkit-specific Research & Add class.
		$class_name = 'WP_MCP_AI_' . ucwords( $this->toolkit_slug, '_' ) . '_Research_Add';
		$class_file = WP_MCP_AI_PRO_PATH . 'includes/research-add/class-wp-mcp-ai-' . str_replace( '_', '-', $this->toolkit_slug ) . '-research-add.php';

		if ( file_exists( $class_file ) ) {
			require_once $class_file;
			if ( class_exists( $class_name ) ) {
				$research_add = new $class_name();
				$research_add->render();
				return;
			}
		}

		// Fallback message if no implementation found.
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Research & Add', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p><?php esc_html_e( 'Research & Add implementation for this toolkit is coming soon.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render remote sites tab.
	 */
	protected function render_remote_sites_tab() {
		// Check if Remote Sites is enabled.
		$settings = get_option( $this->option_name, array() );
		if ( empty( $settings['enable_remote_sites'] ) ) {
			?>
<div class="toolkit-card">
<h2><?php esc_html_e( 'Remote Sites Integration', 'mcp-ai-wpoos-pro' ); ?></h2>
<p><?php esc_html_e( 'Remote Sites functionality allows this toolkit to query and interact with remote WordPress/WooCommerce sites in your mesh network.', 'mcp-ai-wpoos-pro' ); ?></p>
<p><?php esc_html_e( 'Enable Remote Sites in the Configuration tab to access this feature.', 'mcp-ai-wpoos-pro' ); ?></p>
</div>
			<?php
			return;
		}

		// Load Remote Site Manager.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

		// Get all configured remote sites.
		$remote_sites = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		?>
<div class="toolkit-card">
<h2><?php esc_html_e( 'Remote Sites Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
<p><?php esc_html_e( 'This toolkit can interact with the following remote sites. Configure remote sites in the main Remote Sites settings page.', 'mcp-ai-wpoos-pro' ); ?></p>

		<?php if ( empty( $remote_sites ) ) : ?>
<div class="notice notice-warning inline">
<p>
			<?php
			echo wp_kses_post(
				sprintf(
				/* translators: %s: Link to remote sites settings */
					__( 'No remote sites configured. <a href="%s">Add remote sites</a> to enable cross-site functionality.', 'mcp-ai-wpoos-pro' ),
					admin_url( 'admin.php?page=wp-mcp-ai-pro-remote-sites' )
				)
			);
			?>
</p>
</div>
<?php else : ?>
<table class="wp-list-table widefat fixed striped">
<thead>
<tr>
<th><?php esc_html_e( 'Site Name', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'URL', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
</tr>
</thead>
<tbody>
	<?php foreach ( $remote_sites as $site_id => $site ) : ?>
<tr>
<td><strong><?php echo esc_html( $site['name'] ?? __( '(Unnamed Site)', 'mcp-ai-wpoos-pro' ) ); ?></strong></td>
<td><code><?php echo esc_html( $site['url'] ?? '-' ); ?></code></td>
<td><?php echo esc_html( ucfirst( $site['type'] ?? 'WordPress' ) ); ?></td>
<td>
		<?php if ( ! empty( $site['enabled'] ) ) : ?>
<span style="color: green;">●</span> <?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?>
<?php else : ?>
<span style="color: red;">●</span> <?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p>
<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-pro-remote-sites' ) ); ?>" class="button button-secondary">
	<?php esc_html_e( 'Manage Remote Sites', 'mcp-ai-wpoos-pro' ); ?>
</a>
</p>
<?php endif; ?>
</div>

		<?php
		// Render toolkit-specific remote sites functionality.
		$this->render_toolkit_remote_features();
	}

	/**
	 * Render toolkit-specific remote sites features.
	 * Child classes can override this to provide custom remote features.
	 */
	protected function render_toolkit_remote_features() {
		?>
<div class="toolkit-card">
<h2><?php esc_html_e( 'Remote Features', 'mcp-ai-wpoos-pro' ); ?></h2>
<p><?php esc_html_e( 'This toolkit supports the following remote site capabilities:', 'mcp-ai-wpoos-pro' ); ?></p>

		<?php
		// Get toolkit-specific capabilities.
		$capabilities = $this->get_remote_capabilities();

		if ( ! empty( $capabilities ) ) :
			?>
<ul style="list-style: disc; margin-left: 20px;">
			<?php foreach ( $capabilities as $capability ) : ?>
<li><?php echo esc_html( $capability ); ?></li>
<?php endforeach; ?>
</ul>
	<?php else : ?>
<p><em><?php esc_html_e( 'No specific remote capabilities configured for this toolkit.', 'mcp-ai-wpoos-pro' ); ?></em></p>
	<?php endif; ?>
</div>
		<?php
	}

	/**
	 * Get toolkit-specific remote capabilities.
	 * Child classes should override this to specify their capabilities.
	 *
	 * @return array Array of capability descriptions.
	 */
	protected function get_remote_capabilities() {
		// Try to load from centralized capabilities loader.
		$loader_file = WP_MCP_AI_PRO_PATH . 'includes/admin/remote-capabilities/class-wp-mcp-ai-remote-capabilities-loader.php';

		if ( file_exists( $loader_file ) ) {
			require_once $loader_file;
			return WP_MCP_AI_Remote_Capabilities_Loader::get_capabilities( $this->toolkit_slug );
		}

		return array();
	}

	/**
	 * Render help & documentation tab.
	 */
	protected function render_help_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Quick Start Guide', 'mcp-ai-wpoos-pro' ); ?></h2>
			<ol>
				<li>
					<strong><?php esc_html_e( 'Enable the Toolkit:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php
					/* translators: %s: Toolkit name */
					printf( esc_html__( 'Go to Settings → NV oOS → Tools & Features and enable the %s', 'mcp-ai-wpoos-pro' ), esc_html( $this->toolkit_name ) );
					?>
				</li>
				<li><strong><?php esc_html_e( 'Configure Settings:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Add any required API keys or credentials in the Configuration tab', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Use with Assistants:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'The toolkit tools will be automatically available to your AI assistants', 'mcp-ai-wpoos-pro' ); ?></li>
			</ol>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Support & Documentation', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p><?php esc_html_e( 'For more information and detailed documentation:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ul>
				<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/tool-reference.md" target="_blank"><?php esc_html_e( 'Tool Reference Documentation', 'mcp-ai-wpoos-pro' ); ?></a></li>
				<li><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues" target="_blank"><?php esc_html_e( 'Report Issues or Request Features', 'mcp-ai-wpoos-pro' ); ?></a></li>
			</ul>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Toolkit Limits', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %d: Maximum number of toolkits */
						__( '<strong>Important:</strong> You can enable a maximum of %d toolkits simultaneously to maintain optimal performance.', 'mcp-ai-wpoos-pro' ),
						apply_filters( 'wp_mcp_ai_max_active_pro_toolkits', 5 )
					)
				);
				?>
			</p>
			<p><?php esc_html_e( 'If you need to enable this toolkit and have reached the limit, disable another toolkit first.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render config section description.
	 */
	public function render_config_section_description() {
		echo '<p>' . esc_html__( 'Configure settings for this toolkit.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render enable remote sites field.
	 */
	public function render_enable_remote_sites_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_remote_sites'] ) ? (bool) $options['enable_remote_sites'] : false;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_remote_sites]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable remote sites integration for this toolkit', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, this toolkit can query and interact with remote sites in your mesh network.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render enable research field.
	 */
	public function render_enable_research_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_research'] ) ? (bool) $options['enable_research'] : false;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_research]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable Research & Add functionality', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, you can use AI to create and manage data for this toolkit.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render research assistant field.
	 */
	public function render_research_assistant_field() {
		$options      = get_option( $this->option_name, array() );
		$assistant_id = isset( $options['research_assistant_id'] ) ? $options['research_assistant_id'] : '';

		// Get list of assistants.
		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		?>
		<select
			name="<?php echo esc_attr( $this->option_name ); ?>[research_assistant_id]"
			id="research_assistant_id"
			class="regular-text"
		>
			<option value=""><?php esc_html_e( '-- Select Assistant --', 'mcp-ai-wpoos-pro' ); ?></option>
			<?php foreach ( $assistants as $assistant ) : ?>
				<option
					value="<?php echo esc_attr( $assistant->ID ); ?>"
					<?php selected( $assistant_id, $assistant->ID ); ?>
				>
					<?php echo esc_html( $assistant->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select the AI assistant to use for Research & Add functionality.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		if ( isset( $input['enable_remote_sites'] ) ) {
			$sanitized['enable_remote_sites'] = (bool) $input['enable_remote_sites'];
		}

		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		}

		if ( isset( $input['research_assistant_id'] ) ) {
			$sanitized['research_assistant_id'] = absint( $input['research_assistant_id'] );
		}

		return $sanitized;
	}

	/**
	 * Resolve the per-toolkit MCP server registered for this settings page.
	 *
	 * Tries the literal toolkit_slug then a kebab-case variant
	 * ('architectural_design' → 'architectural-design').
	 *
	 * @return WP_MCP_AI_Toolkit_Server_Interface|null
	 */
	protected function get_mcp_server() {
		if ( ! class_exists( 'WP_MCP_AI_Toolkit_Server_Registry' ) ) {
			return null;
		}
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		$server   = $registry->get( $this->toolkit_slug );
		if ( null === $server ) {
			$server = $registry->get( str_replace( '_', '-', $this->toolkit_slug ) );
		}
		return $server;
	}

	/**
	 * Render the MCP Server tab.
	 *
	 * Provides a full server configuration interface with status indicators,
	 * endpoint details, allowlist management, ingestion surface toggles, and
	 * rate-limit overrides.  Form posts to admin-post.php action
	 * `wp_mcp_ai_save_toolkit_mcp_server`.
	 *
	 * @since 1.2.0  Original implementation.
	 * @since 1.4.0  Enhanced with status badge, copy URL, Select All / Deselect All,
	 *               tool count badge, global-default hints, and well-known link.
	 */
	protected function render_mcp_server_tab() {
		$server = $this->get_mcp_server();
		if ( ! $server ) {
			return;
		}

		// Settings-saved notice.
		if ( isset( $_GET['mcp_saved'] ) && '1' === $_GET['mcp_saved'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'MCP server settings saved.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			<?php
		}

		$config         = $server->get_configuration();
		$candidates     = $server->candidate_tool_slugs();
		$native         = $server->ingestion_surfaces();
		$mounted        = $server->mounted_surfaces();
		$descriptor_url = rest_url( WP_MCP_AI_Toolkit_MCP_REST_Controller::REST_NAMESPACE . '/mcp/' . $server->get_slug() );

		$is_enabled      = ! empty( $config['enabled'] );
		$allowlist       = isset( $config['tools_allowlist'] ) ? (array) $config['tools_allowlist'] : array();
		$candidate_count = count( $candidates );
		$exposed_count   = empty( $allowlist ) ? $candidate_count : count( $allowlist );

		// Global defaults for per-server limit hints.
		$global_rpm = (int) apply_filters( 'wp_mcp_ai_default_mcp_requests_per_minute', 60 );
		$global_mpb = (int) apply_filters( 'wp_mcp_ai_default_mcp_max_payload_bytes', 0 );
		$global_mit = (int) apply_filters( 'wp_mcp_ai_max_agentic_iterations', 15 );

		$rpm = isset( $config['requests_per_minute'] ) ? (int) $config['requests_per_minute'] : 0;
		$mpb = isset( $config['max_payload_bytes'] ) ? (int) $config['max_payload_bytes'] : 0;
		$mit = isset( $config['max_iterations'] ) ? (int) $config['max_iterations'] : 0;

		// Last-activity timestamp from the cross-mount audit log.
		$last_activity = '';
		if ( class_exists( 'WP_MCP_AI_Toolkit_MCP_Audit_Log' ) ) {
			$entries = WP_MCP_AI_Toolkit_MCP_Audit_Log::get_instance()->get_entries( 200 );
			$latest  = 0;
			foreach ( $entries as $entry ) {
				if ( isset( $entry['source'] ) && $entry['source'] === $server->get_slug() && isset( $entry['ts'] ) ) {
					if ( (int) $entry['ts'] > $latest ) {
						$latest = (int) $entry['ts'];
					}
				}
			}
			if ( $latest > 0 ) {
				$last_activity = human_time_diff( $latest ) . ' ' . __( 'ago', 'mcp-ai-wpoos-pro' );
			}
		}

		// Token count.
		$token_count  = 0;
		$token_option = get_option( 'wp_mcp_ai_tk_mcp_token_' . $server->get_slug(), array() );
		if ( is_array( $token_option ) ) {
			$token_count = count( $token_option );
		}

		$effective_tool_count = $server instanceof WP_MCP_AI_Toolkit_Server_Base
			? count( $server->effective_tool_slugs() )
			: $exposed_count;

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wp_mcp_ai_save_toolkit_mcp_server_' . $server->get_slug() ); ?>
			<input type="hidden" name="action" value="wp_mcp_ai_save_toolkit_mcp_server" />
			<input type="hidden" name="server_slug" value="<?php echo esc_attr( $server->get_slug() ); ?>" />
			<input type="hidden" name="redirect_page" value="<?php echo esc_attr( $this->page_slug ); ?>" />

			<?php /* ── Card 1: MCP Server (status + endpoint + master switch) ── */ ?>
			<div class="toolkit-card">
				<h2><?php esc_html_e( 'MCP Server', 'mcp-ai-wpoos-pro' ); ?></h2>

				<table class="wp-mcp-ai-server-status-table" style="margin-bottom:16px;">
					<tr>
						<td style="padding-right:20px;">
							<span class="wp-mcp-ai-status-pill <?php echo $is_enabled ? 'enabled' : 'disabled'; ?>"
								title="<?php echo $is_enabled ? esc_attr__( 'Server is enabled', 'mcp-ai-wpoos-pro' ) : esc_attr__( 'Server is disabled', 'mcp-ai-wpoos-pro' ); ?>">
							</span>
							<strong>
								<?php
								if ( $is_enabled ) {
									esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' );
								} else {
									esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' );
								}
								?>
							</strong>
						</td>
						<td style="padding-right:20px;">
							<span class="dashicons dashicons-admin-tools" style="vertical-align:middle;"></span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: tool count */
									_n(
										'%d tool',
										'%d tools',
										$effective_tool_count,
										'mcp-ai-wpoos-pro'
									),
									$effective_tool_count
								)
							);
							?>
						</td>
						<td style="padding-right:20px;">
							<span class="dashicons dashicons-admin-network" style="vertical-align:middle;"></span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: token count */
									_n(
										'%d token',
										'%d tokens',
										$token_count,
										'mcp-ai-wpoos-pro'
									),
									$token_count
								)
							);
							?>
						</td>
						<?php if ( '' !== $last_activity ) : ?>
						<td>
							<span class="dashicons dashicons-clock" style="vertical-align:middle;"></span>
							<?php
							printf(
								/* translators: %s: human-readable time diff, e.g. "5 mins ago" */
								esc_html__( 'Last activity: %s', 'mcp-ai-wpoos-pro' ),
								esc_html( $last_activity )
							);
							?>
						</td>
						<?php endif; ?>
					</tr>
				</table>

				<p>
					<label>
						<input type="checkbox" name="enabled" value="1" <?php checked( $is_enabled ); ?> />
						<?php esc_html_e( 'Enable this MCP server', 'mcp-ai-wpoos-pro' ); ?>
					</label>
				</p>
				<p class="description">
					<?php esc_html_e( 'When disabled, JSON-RPC clients will receive a method-not-found response for every method except initialize and ping.', 'mcp-ai-wpoos-pro' ); ?>
				</p>

				<p style="margin-top:16px;">
					<strong><?php esc_html_e( 'JSON-RPC Endpoint', 'mcp-ai-wpoos-pro' ); ?></strong><br/>
					<code style="word-break:break-all;"><?php echo esc_html( $descriptor_url ); ?></code>
					<button type="button" class="button button-small wp-mcp-ai-copy-endpoint"
						data-endpoint="<?php echo esc_attr( $descriptor_url ); ?>"
						title="<?php esc_attr_e( 'Copy to clipboard', 'mcp-ai-wpoos-pro' ); ?>">
						<span class="dashicons dashicons-clipboard" style="vertical-align:middle;font-size:16px;width:16px;height:16px;"></span>
						<?php esc_html_e( 'Copy URL', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</p>

				<details style="margin-top:12px;">
					<summary><?php esc_html_e( 'Test with curl', 'mcp-ai-wpoos-pro' ); ?></summary>
					<pre style="background:#f0f0f1;padding:10px;overflow-x:auto;font-size:12px;"><code>curl -X POST <?php echo esc_html( $descriptor_url ); ?> \
	-H "Content-Type: application/json" \
	-d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'</code></pre>
				</details>

				<p style="margin-top:8px;">
					<a href="<?php echo esc_url( home_url( '/.well-known/mcp' ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'View /.well-known/mcp discovery document →', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>

			<?php /* ── Card 2: Tools (allowlist with Select All / search) ── */ ?>
			<div class="toolkit-card">
				<h2><?php esc_html_e( 'Tools', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php if ( empty( $candidates ) ) : ?>
					<p><em><?php esc_html_e( 'No candidate tools registered for this toolkit yet.', 'mcp-ai-wpoos-pro' ); ?></em></p>
				<?php else : ?>
					<p>
						<span class="wp-mcp-ai-tools-badge" style="background:#f0f0f1;padding:2px 10px;border-radius:10px;font-size:13px;">
							<?php
							if ( empty( $allowlist ) ) {
								/* translators: %d: tool count */
								printf( esc_html__( '%1$d of %2$d tools exposed (all)', 'mcp-ai-wpoos-pro' ), (int) $candidate_count, (int) $candidate_count );
							} else {
								/* translators: 1: exposed count, 2: total count */
								printf( esc_html__( '%1$d of %2$d tools exposed (restricted)', 'mcp-ai-wpoos-pro' ), (int) $exposed_count, (int) $candidate_count );
							}
							?>
						</span>
					</p>
					<p class="description">
						<?php esc_html_e( 'Leave all unchecked to expose every candidate tool. Check individual tools to restrict the set the MCP server surfaces.', 'mcp-ai-wpoos-pro' ); ?>
					</p>

					<div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
						<button type="button" class="button button-small wp-mcp-ai-tools-select-all">
							<?php esc_html_e( 'Select All', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<button type="button" class="button button-small wp-mcp-ai-tools-deselect-all">
							<?php esc_html_e( 'Deselect All', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<input type="search" class="wp-mcp-ai-tools-search"
							placeholder="<?php esc_attr_e( 'Filter tools…', 'mcp-ai-wpoos-pro' ); ?>"
							style="max-width:200px;" />
					</div>

					<ul class="wp-mcp-ai-tools-checklist" style="columns: 2; -webkit-columns: 2; -moz-columns: 2;">
					<?php
					foreach ( $candidates as $slug ) :
						$checked = in_array( $slug, $allowlist, true );
						?>
						<li class="wp-mcp-ai-tool-item">
							<label>
								<input type="checkbox" class="wp-mcp-ai-tool-checkbox" name="tools_allowlist[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?> />
								<code class="wp-mcp-ai-tool-slug"><?php echo esc_html( $slug ); ?></code>
							</label>
						</li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="toolkit-card">
				<h2><?php esc_html_e( 'Ingestion Surfaces — Native', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php if ( empty( $native ) ) : ?>
					<p><em><?php esc_html_e( 'No native ingestion surfaces registered.', 'mcp-ai-wpoos-pro' ); ?></em></p>
				<?php else : ?>
					<p class="description">
						<?php esc_html_e( 'Disabled surfaces are hidden from prompts/list and resources/list.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width: 50px;"><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Page', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Entity', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						$disabled_surfaces = isset( $config['disabled_surfaces'] ) ? (array) $config['disabled_surfaces'] : array();
						foreach ( $native as $surface ) :
							$page_slug = isset( $surface['page_slug'] ) ? $surface['page_slug'] : '';
							$checked   = in_array( $page_slug, $disabled_surfaces, true );
							?>
							<tr>
								<td>
									<input type="checkbox" name="disabled_surfaces[]" value="<?php echo esc_attr( $page_slug ); ?>" <?php checked( $checked ); ?> />
								</td>
								<td>
									<strong><?php echo esc_html( isset( $surface['label'] ) ? $surface['label'] : $page_slug ); ?></strong>
									<br /><code><?php echo esc_html( $page_slug ); ?></code>
								</td>
								<td><code><?php echo esc_html( isset( $surface['type'] ) ? $surface['type'] : '' ); ?></code></td>
								<td><code><?php echo esc_html( isset( $surface['entity_type'] ) ? $surface['entity_type'] : '' ); ?></code></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="toolkit-card">
				<h2><?php esc_html_e( 'Ingestion Surfaces — Mounted from other toolkits', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php if ( empty( $mounted ) ) : ?>
					<p><em><?php esc_html_e( 'No mounted surfaces. This toolkit does not consume read-only ingestion surfaces from other toolkits.', 'mcp-ai-wpoos-pro' ); ?></em></p>
				<?php else : ?>
					<p class="description">
						<?php esc_html_e( 'Disable a mount to hide the foreign surface from this toolkit\'s descriptor. The source toolkit retains write authority and can also revoke its own surface.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width: 50px;"><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Source toolkit', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Page', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Entity', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						$disabled_mounts = isset( $config['disabled_mounts'] ) ? (array) $config['disabled_mounts'] : array();
						foreach ( $mounted as $surface ) :
							$page_slug = isset( $surface['page_slug'] ) ? $surface['page_slug'] : '';
							$source    = isset( $surface['source_toolkit_slug'] ) ? $surface['source_toolkit_slug'] : '';
							$mount_key = $source . '::' . $page_slug;
							$checked   = in_array( $mount_key, $disabled_mounts, true );
							?>
							<tr>
								<td>
									<input type="checkbox" name="disabled_mounts[]" value="<?php echo esc_attr( $mount_key ); ?>" <?php checked( $checked ); ?> />
								</td>
								<td><code><?php echo esc_html( $source ); ?></code></td>
								<td>
									<strong><?php echo esc_html( isset( $surface['label'] ) ? $surface['label'] : $page_slug ); ?></strong>
									<br /><code><?php echo esc_html( $page_slug ); ?></code>
								</td>
								<td><code><?php echo esc_html( isset( $surface['entity_type'] ) ? $surface['entity_type'] : '' ); ?></code></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="toolkit-card">
				<h2><?php esc_html_e( 'Limits', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Per-server overrides. Leave any value at 0 to inherit the global default shown in parentheses.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="requests_per_minute"><?php esc_html_e( 'Requests per minute', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td>
								<input type="number" min="0" id="requests_per_minute" name="requests_per_minute" value="<?php echo esc_attr( $rpm ); ?>" class="small-text" />
								<?php if ( 0 === $rpm && $global_rpm > 0 ) : ?>
									<span class="description" style="display:inline;margin-left:6px;">
										<?php
										/* translators: %d: global default value */
										printf( esc_html__( '(global default: %1$d req/min)', 'mcp-ai-wpoos-pro' ), (int) $global_rpm );
										?>
									</span>
								<?php elseif ( $rpm > 0 ) : ?>
									<span class="description" style="display:inline;margin-left:6px;">
										<?php esc_html_e( '(override active)', 'mcp-ai-wpoos-pro' ); ?>
									</span>
								<?php else : ?>
									<span class="description" style="display:inline;margin-left:6px;">
										<?php esc_html_e( '(unlimited)', 'mcp-ai-wpoos-pro' ); ?>
									</span>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Per-user JSON-RPC rate limit.', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="max_payload_bytes"><?php esc_html_e( 'Max request body size (bytes)', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td>
								<input type="number" min="0" id="max_payload_bytes" name="max_payload_bytes" value="<?php echo esc_attr( $mpb ); ?>" class="regular-text" />
								<?php if ( 0 === $mpb && $global_mpb > 0 ) : ?>
									<span class="description" style="display:inline;margin-left:6px;">
										<?php
										printf( /* translators: %s: formatted byte count */ esc_html__( '(global default: %s bytes)', 'mcp-ai-wpoos-pro' ), esc_html( number_format_i18n( $global_mpb ) ) );
										?>
									</span>
								<?php elseif ( $mpb > 0 ) : ?>
									<span class="description" style="display:inline;margin-left:6px;">
										<?php esc_html_e( '(override active)', 'mcp-ai-wpoos-pro' ); ?>
									</span>
								<?php else : ?>
									<span class="description" style="display:inline;margin-left:6px;">
										<?php esc_html_e( '(no limit)', 'mcp-ai-wpoos-pro' ); ?>
									</span>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Reject JSON-RPC bodies larger than this many bytes.', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="max_iterations"><?php esc_html_e( 'Max agentic iterations', 'mcp-ai-wpoos-pro' ); ?></label></th>
							<td>
								<input type="number" min="0" id="max_iterations" name="max_iterations" value="<?php echo esc_attr( $mit ); ?>" class="small-text" />
								<?php if ( 0 === $mit && $global_mit > 0 ) : ?>
									<span class="description" style="display:inline;margin-left:6px;">
										<?php
										printf(
											/* translators: %1$d: number of iterations */
											esc_html__( '(global default: %1$d iterations)', 'mcp-ai-wpoos-pro' ),
											(int) $global_mit
										);
										?>
									</span>
								<?php elseif ( $mit > 0 ) : ?>
									<span class="description" style="display:inline;margin-left:6px;">
										<?php esc_html_e( '(override active)', 'mcp-ai-wpoos-pro' ); ?>
									</span>
								<?php else : ?>
									<span class="description" style="display:inline;margin-left:6px;">
										<?php esc_html_e( '(unlimited)', 'mcp-ai-wpoos-pro' ); ?>
									</span>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Per-server cap on agentic loop iterations.', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<?php submit_button( __( 'Save MCP Server Settings', 'mcp-ai-wpoos-pro' ) ); ?>
			</form>

			<script>
			( function( $ ) {
				$( '.wp-mcp-ai-copy-endpoint' ).on( 'click', function() {
					var btn = $( this ), url = btn.data( 'endpoint' );
					if ( navigator.clipboard ) {
						navigator.clipboard.writeText( url ).catch( function() {} );
					} else {
						var t = $( '<textarea>' );
						$( 'body' ).append( t );
						t.val( url ).select();
						document.execCommand( 'copy' );
						t.remove();
					}
					var orig = btn.html();
					btn.addClass( 'copied' ).html( '<span class="dashicons dashicons-yes" style="vertical-align:middle;"></span> <?php echo esc_js( __( 'Copied!', 'mcp-ai-wpoos-pro' ) ); ?>' );
					setTimeout( function() { btn.removeClass( 'copied' ).html( orig ); }, 2000 );
				} );

				$( '.wp-mcp-ai-tools-select-all' ).on( 'click', function() {
					$( this ).closest( '.toolkit-card' ).find( '.wp-mcp-ai-tool-checkbox' ).prop( 'checked', true );
				} );

				$( '.wp-mcp-ai-tools-deselect-all' ).on( 'click', function() {
					$( this ).closest( '.toolkit-card' ).find( '.wp-mcp-ai-tool-checkbox' ).prop( 'checked', false );
				} );

				$( '.wp-mcp-ai-tools-search' ).on( 'keyup search', function() {
					var q = $( this ).val().toLowerCase(),
						list = $( this ).closest( '.toolkit-card' ).find( '.wp-mcp-ai-tools-checklist' );
					list.find( '.wp-mcp-ai-tool-item' ).each( function() {
						var item = $( this ), slug = item.find( '.wp-mcp-ai-tool-slug' ).text().toLowerCase();
						item.toggleClass( 'filtered-hidden', q !== '' && slug.indexOf( q ) === -1 );
					} );
				} );
			} )( jQuery );
			</script>
			<?php
	}
}
