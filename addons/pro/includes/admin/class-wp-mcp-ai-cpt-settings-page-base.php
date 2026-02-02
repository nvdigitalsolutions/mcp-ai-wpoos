<?php
/**
 * Base class for Pro CPT Settings Pages
 *
 * Provides common functionality for settings pages that configure
 * AI provider, model, and assistant for Research & Add functionality.
 *
 * @package WP_MCP_AI_Pro
 */

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
			__( 'Research & Add Configuration', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'assistant_id',
			__( 'Assistant', 'mcp-ai-wpoos-pro' ),
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
				__( 'Settings saved successfully.', 'mcp-ai-wpoos-pro' ),
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
			'settings' => __( 'Settings', 'mcp-ai-wpoos-pro' ),
		);

		// Allow child classes to add Overview tab.
		if ( method_exists( $this, 'render_overview_tab' ) ) {
			$tabs = array( 'overview' => __( 'Overview', 'mcp-ai-wpoos-pro' ) ) + $tabs;
		}

		// Allow child classes to add Tools tab.
		if ( method_exists( $this, 'get_tools_list' ) ) {
			$tabs['tools'] = __( 'Available Tools', 'mcp-ai-wpoos-pro' );
		}

		if ( count( $tabs ) <= 1 ) {
			return; // No tabs if only settings.
		}

		?>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab_title ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug ) ); ?>"
					class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; ?>"
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
			submit_button( __( 'Save Settings', 'mcp-ai-wpoos-pro' ) );
			?>
		</form>

		<div class="card" style="max-width: 800px; margin-top: 20px;">
			<h2><?php esc_html_e( 'How This Works', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p><?php esc_html_e( 'These settings control which AI assistant is used for the Research & Add functionality.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p><?php esc_html_e( 'The assistant you select will be used in the research chat interface. The assistant\'s own provider and model configuration will be used for generating content.', 'mcp-ai-wpoos-pro' ); ?></p>
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
						<code style="margin-left: 10px; color: #666;"><?php echo esc_html( $tool_slug ); ?></code>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'How to Use These Tools', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p><?php esc_html_e( 'All tools from this toolkit are automatically available to your AI assistants once the toolkit is enabled.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p><?php esc_html_e( 'These tools can be called by AI assistants to perform various tasks related to this toolkit.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI settings for the Research & Add functionality.', 'mcp-ai-wpoos-pro' ) . '</p>';
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
			<option value="0"><?php esc_html_e( '-- Auto-select first available --', 'mcp-ai-wpoos-pro' ); ?></option>
			<?php foreach ( $assistants as $assistant ) : ?>
				<option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $value, $assistant->ID ); ?>>
					<?php echo esc_html( $assistant->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select the AI assistant to use for research. Leave as auto-select to use the most recent assistant.', 'mcp-ai-wpoos-pro' ); ?>
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
			$sanitized['assistant_id'] = absint( $input['assistant_id'] );
		}

		return $sanitized;
	}
}
