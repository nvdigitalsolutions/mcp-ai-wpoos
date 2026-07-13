<?php
/**
 * Page Agent Admin Settings
 *
 * Dedicated settings class for the Page Agent addon admin page.
 * Handles settings registration, rendering, and sanitization.
 *
 * @package NV_oOS_Page_Agent
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page for the Page Agent addon.
 *
 * @since 0.1.0
 */
class WP_MCP_AI_Page_Agent_Settings {

	/**
	 * Settings group key.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const SETTINGS_GROUP = 'nvoos_page_agent_settings';

	/**
	 * Option key for addon settings.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_page_agent_settings';

	/**
	 * Page slug.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-page-agent';

	/**
	 * Constructor — registers hooks.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
	}

	/**
	 * Register the settings, sections, and fields.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(
					'enabled'   => true,
					'model'     => 'gpt-4o-mini',
					'language'  => 'en-US',
					'max_steps' => 50,
				),
			)
		);

		add_settings_section(
			'nvoos_page_agent_general',
			__( 'General Settings', 'nvoos-page-agent' ),
			array( $this, 'render_general_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'enabled',
			__( 'Enable Page Agent', 'nvoos-page-agent' ),
			array( $this, 'render_enabled_field' ),
			self::PAGE_SLUG,
			'nvoos_page_agent_general'
		);

		add_settings_field(
			'model',
			__( 'LLM Model', 'nvoos-page-agent' ),
			array( $this, 'render_model_field' ),
			self::PAGE_SLUG,
			'nvoos_page_agent_general'
		);

		add_settings_field(
			'language',
			__( 'Language', 'nvoos-page-agent' ),
			array( $this, 'render_language_field' ),
			self::PAGE_SLUG,
			'nvoos_page_agent_general'
		);

		add_settings_field(
			'max_steps',
			__( 'Max Steps', 'nvoos-page-agent' ),
			array( $this, 'render_max_steps_field' ),
			self::PAGE_SLUG,
			'nvoos_page_agent_general'
		);
	}

	/**
	 * Add the settings page to the NV oOS admin menu.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function add_page() {
		// Try to add under the base plugin's menu.
		$parent_slug = 'wp-mcp-ai-settings';

		// Fall back to a top-level menu if the base plugin menu doesn't exist.
		global $submenu;
		if ( ! isset( $submenu[ $parent_slug ] ) ) {
			$parent_slug = 'options-general.php';
		}

		add_submenu_page(
			$parent_slug,
			__( 'NV oOS Page Agent', 'nvoos-page-agent' ),
			__( 'Page Agent', 'nvoos-page-agent' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the general settings section description.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_general_section() {
		echo '<p>';
		esc_html_e( 'Configure the Page Agent copilot. The agent runs in the browser and uses the configured LLM to interpret natural language instructions and interact with the current page.', 'nvoos-page-agent' );
		echo '</p>';
	}

	/**
	 * Render the enabled checkbox field.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_enabled_field() {
		$settings = WP_MCP_AI_Page_Agent::get_settings();
		?>
		<label>
			<input type="checkbox"
				   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]"
				   value="1"
				   <?php checked( $settings['enabled'], true ); ?>>
			<?php esc_html_e( 'Inject the Page Agent bridge script on frontend pages that include the NV oOS chat widget.', 'nvoos-page-agent' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the model text field.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_model_field() {
		$settings = WP_MCP_AI_Page_Agent::get_settings();
		?>
		<input type="text"
			   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[model]"
			   value="<?php echo esc_attr( $settings['model'] ); ?>"
			   class="regular-text"
			   placeholder="gpt-4o-mini">
		<p class="description">
			<?php esc_html_e( 'OpenAI-compatible model slug for the Page Agent LLM. Recommended: gpt-4o-mini (fast & cheap), gpt-4o, claude-3.5-sonnet, or a local model via Ollama.', 'nvoos-page-agent' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the language text field.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_language_field() {
		$settings = WP_MCP_AI_Page_Agent::get_settings();
		?>
		<input type="text"
			   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[language]"
			   value="<?php echo esc_attr( $settings['language'] ); ?>"
			   class="regular-text"
			   placeholder="en-US">
		<p class="description">
			<?php esc_html_e( 'Language locale for the Page Agent UI and instruction interpretation (BCP 47 format, e.g., en-US, zh-CN, ja-JP, de-DE).', 'nvoos-page-agent' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the max steps number field.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_max_steps_field() {
		$settings = WP_MCP_AI_Page_Agent::get_settings();
		?>
		<input type="number"
			   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_steps]"
			   value="<?php echo esc_attr( $settings['max_steps'] ); ?>"
			   min="1"
			   max="200"
			   class="small-text"
			   step="1">
		<p class="description">
			<?php esc_html_e( 'Maximum number of browser interaction steps per instruction (1–200). Higher values allow complex multi-step workflows but increase LLM cost.', 'nvoos-page-agent' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the full settings page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-page-agent' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Shortcode Usage', 'nvoos-page-agent' ); ?></h2>
			<p><?php esc_html_e( 'Use the following shortcode to place the Page Agent UI anywhere on your site:', 'nvoos-page-agent' ); ?></p>
			<code>[mcp_ai_page_agent]</code>
			<p class="description">
				<?php esc_html_e( 'Optional attributes: model, language, max_steps, position (bottom-right, bottom-left, top-right, top-left), show_toggle (true/false).', 'nvoos-page-agent' ); ?>
			</p>

			<h2><?php esc_html_e( 'NV oOS Tool', 'nvoos-page-agent' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %1$s: tool slug, %2$s: opening code tag, %3$s: closing code tag */
					esc_html__( 'The %1$s tool is automatically registered with the NV oOS tool registry. Assistants can delegate browser-level actions to the Page Agent through this tool.', 'nvoos-page-agent' ),
					'<code>page_agent_execute</code>',
					'',
					''
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Sanitize the settings array before saving.
	 *
	 * @since 0.1.0
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public function sanitize( $input ) {
		$sanitized = array();

		$sanitized['enabled']   = ! empty( $input['enabled'] );
		$sanitized['model']     = isset( $input['model'] )
			? sanitize_text_field( $input['model'] )
			: 'gpt-4o-mini';
		$sanitized['language']  = isset( $input['language'] )
			? sanitize_text_field( $input['language'] )
			: 'en-US';
		$sanitized['max_steps'] = isset( $input['max_steps'] )
			? absint( $input['max_steps'] )
			: 50;

		// Clamp max_steps.
		if ( $sanitized['max_steps'] < 1 ) {
			$sanitized['max_steps'] = 1;
		} elseif ( $sanitized['max_steps'] > 200 ) {
			$sanitized['max_steps'] = 200;
		}

		return $sanitized;
	}
}
