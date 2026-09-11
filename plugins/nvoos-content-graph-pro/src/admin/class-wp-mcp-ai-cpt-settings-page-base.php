<?php
/**
 * Financial admin page (ecosystem port — Wave F2, financial admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the
 * `src/` root (base-class/yfinance-service requires).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for Pro CPT Settings Pages
 */
abstract class WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Settings option name.
	 *
	 * @var string
	 */
	protected $option_name;

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	protected $post_type;

	/**
	 * Page title.
	 *
	 * @var string
	 */
	protected $page_title;

	/**
	 * Menu title.
	 *
	 * @var string
	 */
	protected $menu_title;

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	protected $page_slug;

	/**
	 * Constructor - sets up hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ), 25 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add settings submenu page.
	 */
	public function add_settings_page() {
		// For the built-in 'post' post type, the parent slug is just 'edit.php'.
		// For all other post types, it's 'edit.php?post_type={post_type}'.
		$parent_slug = ( 'post' === $this->post_type ) ? 'edit.php' : 'edit.php?post_type=' . $this->post_type;

		add_submenu_page(
			$parent_slug,
			$this->page_title,
			$this->menu_title,
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

		add_settings_section(
			$this->option_name . '_section',
			__( 'Research & Add Configuration', 'nvoos-content-graph-pro' ),
			array( $this, 'render_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'assistant_id',
			__( 'Assistant', 'nvoos-content-graph-pro' ),
			array( $this, 'render_assistant_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get active tab.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Check for settings update.
		if ( isset( $_GET['settings-updated'] ) && 'settings' === $active_tab ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress core handles nonce verification for settings pages.
			add_settings_error(
				$this->option_name . '_messages',
				$this->option_name . '_message',
				__( 'Settings saved successfully.', 'nvoos-content-graph-pro' ),
				'success'
			);
		}

		settings_errors( $this->option_name . '_messages' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->page_title ); ?></h1>

			<?php $this->render_tabs( $active_tab ); ?>

			<?php
			switch ( $active_tab ) {
				case 'overview':
					$this->render_overview_tab();
					break;
				case 'tools':
					$this->render_tools_tab();
					break;
				case 'settings':
				default:
					$this->render_settings_tab();
					break;
			}
			?>
		</div>

		<style>
			.nav-tab-wrapper {
				border-bottom: 1px solid #ccd0d4;
				margin: 13px 0;
			}
			.toolkit-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin: 20px 0;
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
				min-width: 250px;
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
			'settings' => __( 'Settings', 'nvoos-content-graph-pro' ),
		);

		// Allow child classes to add Overview tab.
		if ( method_exists( $this, 'render_overview_tab' ) ) {
			$tabs = array( 'overview' => __( 'Overview', 'nvoos-content-graph-pro' ) ) + $tabs;
		}

		// Allow child classes to add Tools tab.
		if ( method_exists( $this, 'get_tools_list' ) ) {
			$tabs['tools'] = __( 'Available Tools', 'nvoos-content-graph-pro' );
		}

		if ( count( $tabs ) <= 1 ) {
			return; // No tabs if only settings.
		}

		?>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab_title ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug ) ); ?>"
					class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded CSS class. ?>"
				>
					<?php echo esc_html( $tab_title ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render settings tab content.
	 */
	protected function render_settings_tab() {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( $this->option_name . '_group' );
			do_settings_sections( $this->option_name );
			submit_button( __( 'Save Settings', 'nvoos-content-graph-pro' ) );
			?>
		</form>

		<div class="card" style="max-width: 800px; margin-top: 20px;">
			<h2><?php esc_html_e( 'How This Works', 'nvoos-content-graph-pro' ); ?></h2>
			<p><?php esc_html_e( 'These settings control which AI assistant is used for the Research & Add functionality.', 'nvoos-content-graph-pro' ); ?></p>
			<p><?php esc_html_e( 'The assistant you select will be used in the research chat interface. The assistant\'s own provider and model configuration will be used for generating content.', 'nvoos-content-graph-pro' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render tools tab content.
	 * Child classes should implement get_tools_list() to enable this tab.
	 */
	protected function render_tools_tab() {
		if ( ! method_exists( $this, 'get_tools_list' ) ) {
			return;
		}

		$tools = $this->get_tools_list();
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Available Tools', 'nvoos-content-graph-pro' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %d: Number of tools */
					esc_html__( 'This toolkit provides %d AI-powered tools for your assistants.', 'nvoos-content-graph-pro' ),
					count( $tools )
				);
				?>
			</p>

			<div class="tools-list" style="margin-top: 20px;">
				<?php foreach ( $tools as $tool_slug => $tool_name ) : ?>
					<div class="tool-item">
						<strong><?php echo esc_html( $tool_name ); ?></strong>
						<code style="margin-left: 10px; color: #666;"><?php echo esc_html( $tool_slug ); ?></code>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'How to Use These Tools', 'nvoos-content-graph-pro' ); ?></h2>
			<p><?php esc_html_e( 'All tools from this toolkit are automatically available to your AI assistants once the toolkit is enabled.', 'nvoos-content-graph-pro' ); ?></p>
			<p><?php esc_html_e( 'These tools can be called by AI assistants to perform various tasks related to this toolkit.', 'nvoos-content-graph-pro' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI settings for the Research & Add functionality.', 'nvoos-content-graph-pro' ) . '</p>';
	}

	/**
	 * Render assistant selection field.
	 */
	public function render_assistant_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['assistant_id'] ) ? absint( $options['assistant_id'] ) : 0;

		// Get available assistants.
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
		<select name="<?php echo esc_attr( $this->option_name ); ?>[assistant_id]" id="assistant_id">
			<option value="0"><?php esc_html_e( '-- Auto-select first available --', 'nvoos-content-graph-pro' ); ?></option>
			<?php foreach ( $assistants as $assistant ) : ?>
				<option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $value, $assistant->ID ); ?>>
					<?php echo esc_html( $assistant->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select the AI assistant to use for research. Leave as auto-select to use the most recent assistant.', 'nvoos-content-graph-pro' ); ?>
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

		if ( isset( $input['assistant_id'] ) ) {
			// Negative or non-numeric IDs are invalid; absint() would flip
			// negatives to positives, so clamp instead.
			$assistant_id              = max( 0, (int) $input['assistant_id'] );
			$sanitized['assistant_id'] = $assistant_id;
		}

		return $sanitized;
	}
}
