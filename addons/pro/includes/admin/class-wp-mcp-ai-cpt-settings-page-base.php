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
		add_submenu_page(
			'edit.php?post_type=' . $this->post_type,
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

		// Check for settings update.
		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress core handles nonce verification for settings pages.
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
