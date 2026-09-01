<?php
/**
 * Profession Defaults Metabox.
 *
 * Handles default AI provider, model, and temperature settings for professions.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Professions\Metaboxes;

use NvoosContentGraphAiPlatform\Professions\ProfessionCpt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profession defaults metabox.
 */
class ProfessionMetaboxDefaults extends ProfessionMetaboxBase {

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
		return __( 'Default AI Settings', 'nvoos-content-graph-ai-platform' );
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
	 * Get documentation URL for this metabox.
	 *
	 * @return string
	 */
	public function get_documentation_url() {
		return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/admin-guides/SETTINGS_DASHBOARD_GUIDE.md#ai-providers-tab';
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		// Enqueue model selector JavaScript.
		// Script is registered globally in WP_MCP_AI_Admin_Scripts with localization.
		// We just need to enqueue it here for this metabox.
		wp_enqueue_script( 'wp-mcp-ai-model-selector' );

		wp_nonce_field( $this->get_id() . '_save', $this->get_id() . '_nonce' );

		$default_provider     = get_post_meta( $post->ID, '_wp_mcp_ai_profession_default_provider', true );
		$default_model        = get_post_meta( $post->ID, '_wp_mcp_ai_profession_default_model', true );
		$default_temperature  = get_post_meta( $post->ID, '_wp_mcp_ai_profession_default_temperature', true );
		$associated_assistant = get_post_meta( $post->ID, ProfessionCpt::META_ASSOCIATED_ASSISTANT, true );

		if ( empty( $default_provider ) ) {
			$default_provider = 'openai';
		}
		if ( empty( $default_model ) ) {
			$default_model = 'gpt-4.1';
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

			// Load model service if available (base plugin, monolith mode).
			// Standalone mode has no model service — the select degrades to the
			// saved value / empty list.
			$models = array();
		if ( ! empty( $default_provider ) && class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			$model_service = new \WP_MCP_AI_Model_Service();
			$models        = $model_service->get_models_for_provider( $default_provider );
		}

		?>
		<div class="wp-mcp-ai-profession-defaults">
			<p class="description">
				<?php esc_html_e( 'These settings will be applied to assistants created from this professional template.', 'nvoos-content-graph-ai-platform' ); ?>
			</p>

			<p>
				<label for="profession_associated_assistant">
					<strong><?php esc_html_e( 'Test Assistant', 'nvoos-content-graph-ai-platform' ); ?></strong>
				</label><br>
				<select name="profession_associated_assistant" id="profession_associated_assistant" class="widefat">
					<option value=""><?php esc_html_e( '— Use Profession Settings —', 'nvoos-content-graph-ai-platform' ); ?></option>
					<?php foreach ( $assistants as $assistant ) : ?>
						<option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $associated_assistant, $assistant->ID ); ?>>
							<?php echo esc_html( $assistant->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span class="description" style="display: block; margin-top: 5px;">
					<?php esc_html_e( 'Associate with an existing assistant to use its configuration when testing this profession.', 'nvoos-content-graph-ai-platform' ); ?>
				</span>
			</p>

			<p>
				<label for="profession_default_provider">
					<strong><?php esc_html_e( 'AI Provider', 'nvoos-content-graph-ai-platform' ); ?></strong>
				</label><br>
				<select name="profession_default_provider" id="profession_default_provider" class="widefat wp-mcp-ai-provider-select" data-model-target="#profession_default_model">
					<?php
					$available_providers = class_exists( 'WP_MCP_AI_Admin_Settings' )
						? \WP_MCP_AI_Admin_Settings::get_available_providers()
						: array();
					foreach ( $available_providers as $provider_slug => $provider_label ) {
						?>
						<option value="<?php echo esc_attr( $provider_slug ); ?>" <?php selected( $default_provider, $provider_slug ); ?>><?php echo esc_html( $provider_label ); ?></option>
						<?php
					}
					?>
				</select>
			</p>

			<p>
				<label for="profession_default_model">
					<strong><?php esc_html_e( 'Model', 'nvoos-content-graph-ai-platform' ); ?></strong>
				</label><br>
				<select name="profession_default_model" id="profession_default_model" class="widefat">
					<option value=""><?php esc_html_e( '— Select Model —', 'nvoos-content-graph-ai-platform' ); ?></option>
					<?php if ( ! empty( $models ) ) : ?>
						<?php foreach ( $models as $model_id => $model_name ) : ?>
							<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $default_model, $model_id ); ?>>
								<?php echo esc_html( $model_name ); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php if ( $default_model && ( empty( $models ) || ! isset( $models[ $default_model ] ) ) ) : ?>
						<option value="<?php echo esc_attr( $default_model ); ?>" selected="selected">
							<?php echo esc_html( $default_model ); ?><?php echo ! empty( $models ) ? ' (custom)' : ''; ?>
						</option>
					<?php endif; ?>
				</select>
			</p>

			<p>
				<label for="profession_default_temperature">
					<strong><?php esc_html_e( 'Temperature', 'nvoos-content-graph-ai-platform' ); ?></strong>
				</label><br>
				<input type="number" name="profession_default_temperature" id="profession_default_temperature" class="widefat" value="<?php echo esc_attr( $default_temperature ); ?>" min="0" max="2" step="0.1">
				<span class="description" style="display: block; margin-top: 5px;"><?php esc_html_e( '0-2. Lower is more deterministic.', 'nvoos-content-graph-ai-platform' ); ?></span>
			</p>
		</div>
		<?php
		$this->render_documentation_link();
	}

	/**
	 * Save metabox data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! $this->can_save( $post_id ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST['wp_mcp_ai_profession_defaults_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_profession_defaults_nonce'] ) ), 'wp_mcp_ai_profession_defaults_save' ) ) {
			return;
		}

		// Save associated assistant.
		if ( isset( $_POST['profession_associated_assistant'] ) ) {
			$associated_assistant = absint( wp_unslash( $_POST['profession_associated_assistant'] ) );
			if ( $associated_assistant > 0 ) {
				// Verify the assistant exists and is published.
				$assistant_post = get_post( $associated_assistant );
				if ( $assistant_post && 'mcp_ai_assistant' === $assistant_post->post_type && 'publish' === $assistant_post->post_status ) {
					update_post_meta( $post_id, ProfessionCpt::META_ASSOCIATED_ASSISTANT, $associated_assistant );
				} else {
					delete_post_meta( $post_id, ProfessionCpt::META_ASSOCIATED_ASSISTANT );
				}
			} else {
				delete_post_meta( $post_id, ProfessionCpt::META_ASSOCIATED_ASSISTANT );
			}
		}

		// Save default provider.
		$default_provider = isset( $_POST['profession_default_provider'] ) ? sanitize_key( wp_unslash( $_POST['profession_default_provider'] ) ) : 'openai';
		update_post_meta( $post_id, '_wp_mcp_ai_profession_default_provider', $default_provider );

		// Save default model.
		$default_model = isset( $_POST['profession_default_model'] ) ? sanitize_text_field( wp_unslash( $_POST['profession_default_model'] ) ) : 'gpt-4.1';
		update_post_meta( $post_id, '_wp_mcp_ai_profession_default_model', $default_model );

		// Save default temperature.
		$default_temperature = isset( $_POST['profession_default_temperature'] ) ? floatval( wp_unslash( $_POST['profession_default_temperature'] ) ) : 0.7;
		if ( $default_temperature < 0 || $default_temperature > 2 ) {
			$default_temperature = 0.7;
		}
		update_post_meta( $post_id, '_wp_mcp_ai_profession_default_temperature', $default_temperature );
	}
}
