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

		add_settings_field(
			'provider',
			__( 'AI Provider', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_provider_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'model',
			__( 'Model', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_model_field' ),
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
				<p><?php esc_html_e( 'These settings control which AI assistant, provider, and model are used for the Research & Add functionality.', 'mcp-ai-wpoos-pro' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Assistant:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'The AI assistant that will be used in the research chat interface.', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Provider:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'The AI service provider (OpenAI, Gemini, or Ollama).', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Model:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'The specific AI model to use for generating content.', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
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
	 * Render provider selection field.
	 */
	public function render_provider_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['provider'] ) ? sanitize_text_field( $options['provider'] ) : 'openai';

		$providers = array(
			'openai' => __( 'OpenAI', 'mcp-ai-wpoos-pro' ),
			'gemini' => __( 'Google Gemini', 'mcp-ai-wpoos-pro' ),
			'ollama' => __( 'Ollama (Local)', 'mcp-ai-wpoos-pro' ),
		);

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[provider]" id="provider">
			<?php foreach ( $providers as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select the AI provider to use for content generation.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render model selection field.
	 */
	public function render_model_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['model'] ) ? sanitize_text_field( $options['model'] ) : 'gpt-4o';

		$models = array(
			// OpenAI models.
			'gpt-4o'              => __( 'GPT-4o (OpenAI)', 'mcp-ai-wpoos-pro' ),
			'gpt-4o-mini'         => __( 'GPT-4o Mini (OpenAI)', 'mcp-ai-wpoos-pro' ),
			'gpt-4-turbo'         => __( 'GPT-4 Turbo (OpenAI)', 'mcp-ai-wpoos-pro' ),
			'gpt-3.5-turbo'       => __( 'GPT-3.5 Turbo (OpenAI)', 'mcp-ai-wpoos-pro' ),
			// Gemini models.
			'gemini-2.0-flash'    => __( 'Gemini 2.0 Flash (Google)', 'mcp-ai-wpoos-pro' ),
			'gemini-1.5-pro'      => __( 'Gemini 1.5 Pro (Google)', 'mcp-ai-wpoos-pro' ),
			'gemini-1.5-flash'    => __( 'Gemini 1.5 Flash (Google)', 'mcp-ai-wpoos-pro' ),
			// Ollama models.
			'llama2'              => __( 'Llama 2 (Ollama)', 'mcp-ai-wpoos-pro' ),
			'mistral'             => __( 'Mistral (Ollama)', 'mcp-ai-wpoos-pro' ),
			'codellama'           => __( 'Code Llama (Ollama)', 'mcp-ai-wpoos-pro' ),
		);

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[model]" id="model">
			<?php foreach ( $models as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select the AI model to use. Make sure the model matches the provider selected above.', 'mcp-ai-wpoos-pro' ); ?>
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

		if ( isset( $input['provider'] ) ) {
			$allowed_providers           = array( 'openai', 'gemini', 'ollama' );
			$provider                    = sanitize_text_field( $input['provider'] );
			$sanitized['provider']       = in_array( $provider, $allowed_providers, true ) ? $provider : 'openai';
		}

		if ( isset( $input['model'] ) ) {
			// Define allowed models.
			$allowed_models = array(
				// OpenAI models.
				'gpt-4o',
				'gpt-4o-mini',
				'gpt-4-turbo',
				'gpt-3.5-turbo',
				// Gemini models.
				'gemini-2.0-flash',
				'gemini-1.5-pro',
				'gemini-1.5-flash',
				// Ollama models.
				'llama2',
				'mistral',
				'codellama',
			);
			
			$model              = sanitize_text_field( $input['model'] );
			$sanitized['model'] = in_array( $model, $allowed_models, true ) ? $model : 'gpt-4o';
		}

		return $sanitized;
	}
}
