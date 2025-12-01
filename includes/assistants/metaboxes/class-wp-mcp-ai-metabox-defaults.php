<?php
/**
 * Defaults Metabox for Assistants.
 *
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Defaults metabox for assistant posts.
 *
 * Manages default provider, model, temperature, and system prompt settings.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Metabox_Defaults extends WP_MCP_AI_Metabox_Base {

	/**
	 * Reference to the Assistant CPT class for constants.
	 *
	 * @var WP_MCP_AI_Assistant_CPT
	 */
	protected $cpt;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param WP_MCP_AI_Assistant_CPT $cpt Assistant CPT instance.
	 */
	public function __construct( $cpt ) {
		$this->cpt = $cpt;
	}

	/**
	 * Get the metabox ID.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_defaults';
	}

	/**
	 * Get the metabox title.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_title() {
		return __( 'Default Settings', 'wp-mcp-ai' );
	}

	/**
	 * Check if current user can view this metabox.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	protected function can_view() {
		global $post;
		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Render the metabox content.
	 *
	 * @since 1.0.0
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
		}

		wp_nonce_field( 'wp_mcp_ai_defaults_meta', 'wp_mcp_ai_defaults_meta_nonce' );

		$provider      = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_PROVIDER, true );
		$provider      = WP_MCP_AI_Assistant_CPT::sanitize_provider_meta( $provider );
		$model         = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_MODEL, true );
		$temperature   = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_TEMPERATURE, true );
		$system_prompt = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, true );

		$settings         = WP_MCP_AI_Admin_Settings::get_settings();
		$default_provider = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';

		if ( '' === $provider ) {
			$provider = $default_provider;
		}

		$provider_choices = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' ) );
		if ( ! is_array( $provider_choices ) ) {
			$provider_choices = array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' );
		}

		if ( '' === $temperature ) {
			$temperature = '';
		}

		?>
	<p>
		<label for="wp-mcp-ai-provider"><strong><?php esc_html_e( 'Provider', 'wp-mcp-ai' ); ?></strong></label>
		<select id="wp-mcp-ai-provider" name="wp_mcp_ai_provider" class="widefat">
			<?php
			foreach ( $provider_choices as $choice ) {
				$choice = sanitize_key( $choice );
				if ( '' === $choice ) {
					continue;
				}

				$provider_labels = array(
					'openai'    => __( 'OpenAI', 'wp-mcp-ai' ),
					'gemini'    => __( 'Gemini', 'wp-mcp-ai' ),
					'ollama'    => __( 'Ollama', 'wp-mcp-ai' ),
					'lm_studio' => __( 'LM Studio', 'wp-mcp-ai' ),
				);

				$label = isset( $provider_labels[ $choice ] ) ? $provider_labels[ $choice ] : ucfirst( str_replace( '_', ' ', $choice ) );
				?>
				<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $provider, $choice ); ?>><?php echo esc_html( $label ); ?></option>
				<?php
			}
			?>
		</select>
	</p>
	<p>
		<label for="wp-mcp-ai-model"><strong><?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?></strong></label>
		<input type="text" id="wp-mcp-ai-model" name="wp_mcp_ai_model" value="<?php echo esc_attr( $model ); ?>" class="widefat" />
	</p>
	<p>
		<label for="wp-mcp-ai-temperature"><strong><?php esc_html_e( 'Temperature', 'wp-mcp-ai' ); ?></strong></label>
		<input type="number" step="0.1" min="0" max="2" id="wp-mcp-ai-temperature" name="wp_mcp_ai_temperature" value="<?php echo esc_attr( $temperature ); ?>" class="widefat" />
	</p>
	<p>
		<label for="wp-mcp-ai-system-prompt"><strong><?php esc_html_e( 'System Prompt', 'wp-mcp-ai' ); ?></strong></label>
		<textarea id="wp-mcp-ai-system-prompt" name="wp_mcp_ai_system_prompt" class="widefat" rows="5"><?php echo esc_textarea( $system_prompt ); ?></textarea>
	</p>
		<?php
	}
}
