<?php
/**
 * Professional Selector Shortcode for WP oOS.
 *
 * Renders a frontend interface for users to select a professional, provider,
 * and model before chatting with an AI assistant.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Professional Selector Shortcode handler.
 *
 * Provides [mcp_ai_professional_selector] shortcode for frontend professional selection.
 */
class WP_MCP_AI_Professional_Selector_Shortcode {
	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'mcp_ai_professional_selector';

	/**
	 * Script handle for the professional selector.
	 */
	const SCRIPT_HANDLE = 'wp-mcp-ai-professional-selector';

	/**
	 * Style handle for the professional selector.
	 */
	const STYLE_HANDLE = 'wp-mcp-ai-professional-selector';

	/**
	 * Bootstraps hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_professional_config', array( $this, 'handle_get_professional_config' ) );
		add_action( 'wp_ajax_nopriv_wp_mcp_ai_get_professional_config', array( $this, 'handle_get_professional_config' ) );

		// Add hooks for model selector (both logged-in and frontend access).
		add_action( 'wp_ajax_wp_mcp_ai_get_models_for_provider', array( $this, 'handle_get_models_for_provider' ) );
		add_action( 'wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider', array( $this, 'handle_get_models_for_provider' ) );

		// Add hooks for rendering professional chat shortcode.
		add_action( 'wp_ajax_wp_mcp_ai_render_professional_chat', array( $this, 'handle_render_professional_chat' ) );
		add_action( 'wp_ajax_nopriv_wp_mcp_ai_render_professional_chat', array( $this, 'handle_render_professional_chat' ) );
	}

	/**
	 * Register assets used by the shortcode.
	 */
	public function register_assets() {
		$script_relative = 'assets/js/professional-selector.js';
		$style_relative  = 'assets/css/professional-selector.css';

		$script_path = WP_MCP_AI_URL . $script_relative;
		$style_path  = WP_MCP_AI_URL . $style_relative;

		$script_version = $this->get_asset_version( $script_relative );
		$style_version  = $this->get_asset_version( $style_relative );

		wp_register_style(
			self::STYLE_HANDLE,
			$style_path,
			array(),
			$style_version
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			$script_path,
			array( 'jquery', WP_MCP_AI_Shortcode::SCRIPT_HANDLE ),
			$script_version,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'wpMcpAiProfessionalSelector',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp-mcp-ai-professional-selector' ),
				'strings' => array(
					'selectAssistant'    => __( '— Select Assistant —', 'wp-mcp-ai' ),
					'selectProfessional' => __( '— Select Professional —', 'wp-mcp-ai' ),
					'selectProvider'     => __( '— Select Provider —', 'wp-mcp-ai' ),
					'selectModel'        => __( '— Select Model —', 'wp-mcp-ai' ),
					'loading'            => __( 'Loading...', 'wp-mcp-ai' ),
					'errorLoading'       => __( 'Failed to load configuration. Please try again.', 'wp-mcp-ai' ),
					'startChat'          => __( 'Start Chat', 'wp-mcp-ai' ),
					'selectRequired'     => __( 'Please select an assistant, professional, provider, and model.', 'wp-mcp-ai' ),
				),
			)
		);
	}

	/**
	 * Render the shortcode output.
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string       $content Shortcode content.
	 * @param string       $tag     Shortcode tag name.
	 * @return string Rendered HTML output.
	 */
	public function render_shortcode( $atts, $content = '', $tag = '' ) {
		$atts = shortcode_atts(
			array(
				'assistant'             => '',
				'default_professional'  => '',
				'default_provider'      => '',
				'default_model'         => '',
				'show_temperature'      => 'false',
				'allow_guests'          => 'false',
				'save_transcript'       => 'true',
				'enable_streaming'      => 'true',
				'allow_sensitive_tools' => 'false',
				'template'              => 'classic',
			),
			$atts,
			$tag
		);

		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_style( self::STYLE_HANDLE );

		$instance_id = wp_unique_id( 'wp-mcp-ai-prof-selector-' );

		$assistants    = $this->get_available_assistants();
		$professionals = $this->get_available_professionals();
		$providers     = $this->get_available_providers();

		$assistant            = sanitize_text_field( $atts['assistant'] );
		$default_professional = sanitize_text_field( $atts['default_professional'] );
		$default_provider     = sanitize_key( $atts['default_provider'] );
		$default_model        = sanitize_text_field( $atts['default_model'] );
		$show_temperature     = wp_validate_boolean( $atts['show_temperature'] );

		ob_start();
		?>
		<div class="wp-mcp-ai-professional-selector" id="<?php echo esc_attr( $instance_id ); ?>" data-wp-mcp-ai-professional-selector>
			<div class="wp-mcp-ai-professional-selector__form-container" data-selector-form>
				<h3 class="wp-mcp-ai-professional-selector__title">
					<?php esc_html_e( 'Select Your Professional Assistant', 'wp-mcp-ai' ); ?>
				</h3>

				<form class="wp-mcp-ai-professional-selector__form">
					<div class="wp-mcp-ai-professional-selector__field">
						<label for="<?php echo esc_attr( $instance_id ); ?>-assistant">
							<?php esc_html_e( 'Assistant', 'wp-mcp-ai' ); ?>
							<span class="required">*</span>
						</label>
						<select 
							id="<?php echo esc_attr( $instance_id ); ?>-assistant" 
							name="assistant" 
							class="wp-mcp-ai-professional-selector__select"
							required
							data-assistant-select
						>
							<option value=""><?php esc_html_e( '— Select Assistant —', 'wp-mcp-ai' ); ?></option>
							<?php foreach ( $assistants as $assistant_id => $assistant_title ) : ?>
								<option 
									value="<?php echo esc_attr( $assistant_id ); ?>"
									<?php selected( $assistant, $assistant_id ); ?>
								>
									<?php echo esc_html( $assistant_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="wp-mcp-ai-professional-selector__field">
						<label for="<?php echo esc_attr( $instance_id ); ?>-professional">
							<?php esc_html_e( 'Professional', 'wp-mcp-ai' ); ?>
							<span class="required">*</span>
						</label>
						<select 
							id="<?php echo esc_attr( $instance_id ); ?>-professional" 
							name="professional" 
							class="wp-mcp-ai-professional-selector__select"
							required
							data-professional-select
						>
							<option value=""><?php esc_html_e( '— Select Professional —', 'wp-mcp-ai' ); ?></option>
							<?php foreach ( $professionals as $prof_id => $prof_title ) : ?>
								<option 
									value="<?php echo esc_attr( $prof_id ); ?>"
									<?php selected( $default_professional, $prof_id ); ?>
								>
									<?php echo esc_html( $prof_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="wp-mcp-ai-professional-selector__field">
						<label for="<?php echo esc_attr( $instance_id ); ?>-provider">
							<?php esc_html_e( 'AI Provider', 'wp-mcp-ai' ); ?>
							<span class="required">*</span>
						</label>
						<select 
							id="<?php echo esc_attr( $instance_id ); ?>-provider" 
							name="provider" 
							class="wp-mcp-ai-professional-selector__select"
							required
							data-provider-select
						>
							<option value=""><?php esc_html_e( '— Select Provider —', 'wp-mcp-ai' ); ?></option>
							<?php foreach ( $providers as $provider_key => $provider_label ) : ?>
								<option 
									value="<?php echo esc_attr( $provider_key ); ?>"
									<?php selected( $default_provider, $provider_key ); ?>
								>
									<?php echo esc_html( $provider_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="wp-mcp-ai-professional-selector__field">
						<label for="<?php echo esc_attr( $instance_id ); ?>-model">
							<?php esc_html_e( 'AI Model', 'wp-mcp-ai' ); ?>
							<span class="required">*</span>
						</label>
						<select 
							id="<?php echo esc_attr( $instance_id ); ?>-model" 
							name="model" 
							class="wp-mcp-ai-professional-selector__select"
							required
							data-model-select
							disabled
						>
							<option value=""><?php esc_html_e( '— Select Model —', 'wp-mcp-ai' ); ?></option>
							<?php if ( $default_model ) : ?>
								<option value="<?php echo esc_attr( $default_model ); ?>" selected>
									<?php echo esc_html( $default_model ); ?>
								</option>
							<?php endif; ?>
						</select>
						<span class="wp-mcp-ai-professional-selector__loading" data-model-loading hidden>
							<span class="spinner is-active"></span>
						</span>
					</div>

					<?php if ( $show_temperature ) : ?>
					<div class="wp-mcp-ai-professional-selector__field">
						<label for="<?php echo esc_attr( $instance_id ); ?>-temperature">
							<?php esc_html_e( 'Temperature', 'wp-mcp-ai' ); ?>
						</label>
						<input 
							type="number" 
							id="<?php echo esc_attr( $instance_id ); ?>-temperature" 
							name="temperature" 
							class="wp-mcp-ai-professional-selector__input"
							min="0"
							max="2"
							step="0.1"
							placeholder="1.0"
							data-temperature-input
						/>
						<p class="description">
							<?php esc_html_e( 'Optional: Override the default temperature (0.0 - 2.0).', 'wp-mcp-ai' ); ?>
						</p>
					</div>
					<?php endif; ?>

					<div class="wp-mcp-ai-professional-selector__actions">
						<button type="submit" class="wp-mcp-ai-professional-selector__button" data-start-button>
							<?php esc_html_e( 'Start Chat', 'wp-mcp-ai' ); ?>
						</button>
					</div>

					<div class="wp-mcp-ai-professional-selector__error" data-error-message hidden></div>
				</form>
			</div>

			<!-- Modal container for chat interface -->
			<div class="wp-mcp-ai-professional-selector-modal" id="<?php echo esc_attr( $instance_id ); ?>-modal" style="display: none;" data-modal>
				<div class="wp-mcp-ai-professional-selector-modal__backdrop" data-modal-backdrop></div>
				<div class="wp-mcp-ai-professional-selector-modal__panel">
					<div class="wp-mcp-ai-professional-selector-modal__header">
						<h2 class="wp-mcp-ai-professional-selector-modal__title" data-modal-title">
							<?php esc_html_e( 'Professional Chat', 'wp-mcp-ai' ); ?>
						</h2>
						<button type="button" class="wp-mcp-ai-professional-selector-modal__close" data-modal-close aria-label="<?php echo esc_attr__( 'Close', 'wp-mcp-ai' ); ?>">
							<span class="dashicons dashicons-no-alt"></span>
						</button>
					</div>
					<div class="wp-mcp-ai-professional-selector-modal__body">
						<div class="wp-mcp-ai-professional-selector-modal__config" data-modal-config></div>
						<div class="wp-mcp-ai-professional-selector-modal__chat" data-modal-chat></div>
					</div>
				</div>
			</div>
		</div>

		<script type="application/json" data-selector-config="<?php echo esc_attr( $instance_id ); ?>">
		<?php
		echo wp_json_encode(
			array(
				'instanceId'          => $instance_id,
				'allowGuests'         => wp_validate_boolean( $atts['allow_guests'] ),
				'saveTranscript'      => wp_validate_boolean( $atts['save_transcript'] ),
				'enableStreaming'     => wp_validate_boolean( $atts['enable_streaming'] ),
				'allowSensitiveTools' => wp_validate_boolean( $atts['allow_sensitive_tools'] ),
				'template'            => sanitize_key( $atts['template'] ),
				'showTemperature'     => $show_temperature,
			)
		);
		?>
		</script>
		<?php

		return ob_get_clean();
	}

	/**
	 * Get available assistants for selection.
	 *
	 * @return array Array of assistant ID => title.
	 */
	protected function get_available_assistants() {
		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return array();
		}

		if ( ! post_type_exists( WP_MCP_AI_Assistant_CPT::POST_TYPE ) ) {
			return array();
		}

		$assistants = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $assistants ) ) {
			return array();
		}

		$options = array();
		foreach ( $assistants as $assistant_id ) {
			$title = get_the_title( $assistant_id );
			if ( $title ) {
				$options[ $assistant_id ] = $title;
			}
		}

		return $options;
	}

	/**
	 * Get available professionals for selection.
	 *
	 * @return array Array of professional ID => title.
	 */
	protected function get_available_professionals() {
		if ( ! post_type_exists( 'mcp_ai_profession' ) ) {
			return array();
		}

		$professionals = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $professionals ) ) {
			return array();
		}

		$options = array();
		foreach ( $professionals as $prof_id ) {
			$title = get_the_title( $prof_id );
			if ( $title ) {
				$options[ $prof_id ] = $title;
			}
		}

		return $options;
	}

	/**
	 * Get available AI providers.
	 *
	 * @return array Array of provider key => label.
	 */
	protected function get_available_providers() {
		$providers = apply_filters(
			'wp_mcp_ai_allowed_providers',
			array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio' )
		);

		$labels = array(
			'openai'      => __( 'OpenAI', 'wp-mcp-ai' ),
			'anthropic'   => __( 'Anthropic (Claude)', 'wp-mcp-ai' ),
			'gemini'      => __( 'Google Gemini', 'wp-mcp-ai' ),
			'huggingface' => __( 'Hugging Face', 'wp-mcp-ai' ),
			'ollama'      => __( 'Ollama (Local)', 'wp-mcp-ai' ),
			'lm_studio'   => __( 'LM Studio (Local)', 'wp-mcp-ai' ),
		);

		$options = array();
		foreach ( $providers as $provider ) {
			$provider = sanitize_key( $provider );
			$options[ $provider ] = isset( $labels[ $provider ] ) 
				? $labels[ $provider ] 
				: ucfirst( str_replace( '_', ' ', $provider ) );
		}

		return $options;
	}

	/**
	 * AJAX handler to get professional configuration.
	 */
	public function handle_get_professional_config() {
		check_ajax_referer( 'wp-mcp-ai-professional-selector', 'nonce' );

		$professional_id = isset( $_POST['professional_id'] ) ? absint( $_POST['professional_id'] ) : 0;

		if ( ! $professional_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid professional ID.', 'wp-mcp-ai' ) ) );
		}

		$professional = get_post( $professional_id );
		if ( ! $professional || 'mcp_ai_profession' !== $professional->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Professional not found.', 'wp-mcp-ai' ) ) );
		}

		// Get professional defaults.
		$defaults = array(
			'provider'    => get_post_meta( $professional_id, '_wp_mcp_ai_profession_provider', true ),
			'model'       => get_post_meta( $professional_id, '_wp_mcp_ai_profession_model', true ),
			'temperature' => get_post_meta( $professional_id, '_wp_mcp_ai_profession_temperature', true ),
		);

		wp_send_json_success( array( 'defaults' => $defaults ) );
	}

	/**
	 * AJAX handler to get models for a provider (nopriv version for frontend).
	 */
	public function handle_get_models_for_provider() {
		check_ajax_referer( 'wp-mcp-ai-professional-selector', 'nonce' );

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

		if ( empty( $provider ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Provider parameter is required.', 'wp-mcp-ai' ),
				)
			);
			return;
		}

		// Get models using the model service.
		$models = array();
		if ( class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			$model_service = new WP_MCP_AI_Model_Service();
			$models        = $model_service->get_models_for_provider( $provider );
		}

		if ( empty( $models ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: provider name */
						__( 'No models available for provider: %s', 'wp-mcp-ai' ),
						$provider
					),
				)
			);
			return;
		}

		wp_send_json_success(
			array(
				'models' => $models,
			)
		);
	}

	/**
	 * AJAX handler to render the professional chat shortcode.
	 *
	 * This handler processes the [mcp_ai_chat] shortcode with the selected
	 * professional configuration and returns the rendered HTML along with
	 * the JavaScript configuration object needed for initialization.
	 */
	public function handle_render_professional_chat() {
		check_ajax_referer( 'wp-mcp-ai-professional-selector', 'nonce' );

		// Get the shortcode attributes from the request.
		// The attributes are pre-constructed in JavaScript with controlled values,
		// so we just need to remove any potential HTML/JS injection attempts.
		$shortcode_atts = isset( $_POST['shortcode_atts'] ) ? sanitize_text_field( wp_unslash( $_POST['shortcode_atts'] ) ) : '';

		if ( empty( $shortcode_atts ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid shortcode attributes.', 'wp-mcp-ai' ),
				)
			);
			return;
		}

		// Build the complete shortcode string.
		$shortcode = '[mcp_ai_chat ' . $shortcode_atts . ']';

		// Clear any existing configs to avoid pollution.
		$GLOBALS['wp_mcp_ai_chat_configs'] = array();

		// Process the shortcode to get rendered HTML.
		$html = do_shortcode( $shortcode );

		// Check if the shortcode was actually processed (do_shortcode returns the original string if no handler found).
		if ( empty( $html ) || $html === $shortcode ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to render chat interface.', 'wp-mcp-ai' ),
				)
			);
			return;
		}

		// Extract the configuration that was stored during shortcode rendering.
		$configs = isset( $GLOBALS['wp_mcp_ai_chat_configs'] ) ? $GLOBALS['wp_mcp_ai_chat_configs'] : array();
		$config  = ! empty( $configs ) ? reset( $configs ) : null;

		if ( ! $config ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to extract chat configuration.', 'wp-mcp-ai' ),
				)
			);
			return;
		}

		wp_send_json_success(
			array(
				'html'   => $html,
				'config' => $config,
			)
		);
	}

	/**
	 * Determine the version string for an asset.
	 *
	 * @param string $relative_path Asset path relative to the plugin root.
	 * @return string
	 */
	protected function get_asset_version( $relative_path ) {
		$relative_path = ltrim( $relative_path, '/' );
		$absolute_path = WP_MCP_AI_PATH . $relative_path;

		if ( file_exists( $absolute_path ) ) {
			$modified = filemtime( $absolute_path );
			if ( $modified ) {
				return WP_MCP_AI_VERSION . '.' . $modified;
			}
		}

		return WP_MCP_AI_VERSION;
	}
}
