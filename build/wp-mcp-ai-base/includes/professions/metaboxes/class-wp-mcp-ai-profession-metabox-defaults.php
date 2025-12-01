<?php
/**
 * Profession Defaults Metabox.
 *
 * Handles default AI provider, model, and temperature settings for professions.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profession defaults metabox.
 */
class WP_MCP_AI_Profession_Metabox_Defaults extends WP_MCP_AI_Profession_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_profession_defaults';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Default AI Settings', 'wp-mcp-ai' );
	}

	/**
	 * Get metabox context.
	 *
	 * @return string
	 */
	public function get_context() {
		return 'side';
	}

	/**
	 * Get metabox priority.
	 *
	 * @return string
	 */
	public function get_priority() {
		return 'default';
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( 'wp_mcp_ai_save_profession_defaults', 'wp_mcp_ai_profession_defaults_nonce' );

		$default_provider      = get_post_meta( $post->ID, '_wp_mcp_ai_profession_default_provider', true );
		$default_model         = get_post_meta( $post->ID, '_wp_mcp_ai_profession_default_model', true );
		$default_temperature   = get_post_meta( $post->ID, '_wp_mcp_ai_profession_default_temperature', true );
		$associated_assistant  = get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );

		if ( empty( $default_provider ) ) {
			$default_provider = 'openai';
		}
		if ( empty( $default_model ) ) {
			$default_model = 'gpt-4';
		}
		if ( '' === $default_temperature ) {
			$default_temperature = 0.7;
		}

		// Get all published assistants for the dropdown.
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
		<div class="wp-mcp-ai-profession-defaults">
			<p class="description">
				<?php esc_html_e( 'These settings will be applied to assistants created from this professional template.', 'wp-mcp-ai' ); ?>
			</p>

			<p>
				<label for="profession_associated_assistant">
					<strong><?php esc_html_e( 'Test Assistant', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<select name="profession_associated_assistant" id="profession_associated_assistant" class="widefat">
					<option value=""><?php esc_html_e( '— Use Profession Settings —', 'wp-mcp-ai' ); ?></option>
					<?php foreach ( $assistants as $assistant ) : ?>
						<option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $associated_assistant, $assistant->ID ); ?>>
							<?php echo esc_html( $assistant->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span class="description" style="display: block; margin-top: 5px;">
					<?php esc_html_e( 'Associate with an existing assistant to use its configuration when testing this profession.', 'wp-mcp-ai' ); ?>
				</span>
			</p>

			<p>
				<label for="profession_default_provider">
					<strong><?php esc_html_e( 'AI Provider', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<select name="profession_default_provider" id="profession_default_provider" class="widefat">
					<option value="openai" <?php selected( $default_provider, 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'wp-mcp-ai' ); ?></option>
					<option value="gemini" <?php selected( $default_provider, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'wp-mcp-ai' ); ?></option>
					<option value="anthropic" <?php selected( $default_provider, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic Claude', 'wp-mcp-ai' ); ?></option>
					<option value="ollama" <?php selected( $default_provider, 'ollama' ); ?>><?php esc_html_e( 'Ollama (Local)', 'wp-mcp-ai' ); ?></option>
					<option value="lm_studio" <?php selected( $default_provider, 'lm_studio' ); ?>><?php esc_html_e( 'LM Studio', 'wp-mcp-ai' ); ?></option>
				</select>
			</p>

			<p>
				<label for="profession_default_model">
					<strong><?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<input type="text" name="profession_default_model" id="profession_default_model" class="widefat" value="<?php echo esc_attr( $default_model ); ?>" placeholder="<?php esc_attr_e( 'e.g., gpt-4, gemini-pro', 'wp-mcp-ai' ); ?>">
			</p>

			<p>
				<label for="profession_default_temperature">
					<strong><?php esc_html_e( 'Temperature', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<input type="number" name="profession_default_temperature" id="profession_default_temperature" class="widefat" value="<?php echo esc_attr( $default_temperature ); ?>" min="0" max="2" step="0.1">
				<span class="description" style="display: block; margin-top: 5px;"><?php esc_html_e( '0-2. Lower is more deterministic.', 'wp-mcp-ai' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Save metabox data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['wp_mcp_ai_profession_defaults_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_profession_defaults_nonce'] ) ), 'wp_mcp_ai_save_profession_defaults' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save associated assistant.
		if ( isset( $_POST['profession_associated_assistant'] ) ) {
			$associated_assistant = absint( wp_unslash( $_POST['profession_associated_assistant'] ) );
			if ( $associated_assistant > 0 ) {
				// Verify the assistant exists and is published.
				$assistant_post = get_post( $associated_assistant );
				if ( $assistant_post && 'mcp_ai_assistant' === $assistant_post->post_type && 'publish' === $assistant_post->post_status ) {
					update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, $associated_assistant );
				} else {
					delete_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT );
				}
			} else {
				delete_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT );
			}
		}

		// Save default provider.
		$default_provider = isset( $_POST['profession_default_provider'] ) ? sanitize_key( wp_unslash( $_POST['profession_default_provider'] ) ) : 'openai';
		update_post_meta( $post_id, '_wp_mcp_ai_profession_default_provider', $default_provider );

		// Save default model.
		$default_model = isset( $_POST['profession_default_model'] ) ? sanitize_text_field( wp_unslash( $_POST['profession_default_model'] ) ) : 'gpt-4';
		update_post_meta( $post_id, '_wp_mcp_ai_profession_default_model', $default_model );

		// Save default temperature.
		$default_temperature = isset( $_POST['profession_default_temperature'] ) ? floatval( wp_unslash( $_POST['profession_default_temperature'] ) ) : 0.7;
		if ( $default_temperature < 0 || $default_temperature > 2 ) {
			$default_temperature = 0.7;
		}
		update_post_meta( $post_id, '_wp_mcp_ai_profession_default_temperature', $default_temperature );
	}
}
