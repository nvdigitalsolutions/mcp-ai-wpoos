<?php
/**
 * Renderer for Model Configuration UI.
 *
 * Handles UI rendering for the per-model view in the Token Manager tab.
 * Follows Separation of Concerns - only handles presentation logic.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders model configuration UI components.
 *
 * This class is responsible ONLY for rendering HTML output.
 * Data retrieval and business logic are handled by WP_MCP_AI_Model_Config.
 */
class WP_MCP_AI_Model_Config_Renderer {

	/**
	 * Render the per-model configuration table.
	 *
	 * @return string HTML output.
	 */
	public static function render_model_table() {
		try {
			// Get model configurations from the data layer.
			$model_configs = WP_MCP_AI_Model_Config::get_all_configs();
			$providers     = WP_MCP_AI_Model_Config::get_available_providers();

			if ( empty( $model_configs ) ) {
				return self::render_empty_state();
			}

			ob_start();
			?>
			<div class="wp-mcp-ai-model-config-table-wrapper">
				<div class="wp-mcp-ai-model-config-header" style="margin-bottom: 20px;">
					<h3><?php esc_html_e( 'Model Configurations', 'wp-mcp-ai' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Configure rate limits, fallback models, and other settings for each AI model. These settings are stored in WordPress options with optional JetEngine CCT backup.', 'wp-mcp-ai' ); ?>
					</p>
				</div>

				<?php echo self::render_storage_info(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<table class="wp-list-table widefat fixed striped wp-mcp-ai-model-config-table">
					<thead>
						<tr>
							<th style="width: 15%;"><?php esc_html_e( 'Model Name', 'wp-mcp-ai' ); ?></th>
							<th style="width: 10%;"><?php esc_html_e( 'Provider', 'wp-mcp-ai' ); ?></th>
							<th style="width: 10%;" class="wp-mcp-ai-tooltip" title="<?php esc_attr_e( 'Tokens Per Minute', 'wp-mcp-ai' ); ?>">
								<?php esc_html_e( 'TPM', 'wp-mcp-ai' ); ?>
								<span class="dashicons dashicons-info" style="font-size: 14px;"></span>
							</th>
							<th style="width: 10%;" class="wp-mcp-ai-tooltip" title="<?php esc_attr_e( 'Requests Per Minute', 'wp-mcp-ai' ); ?>">
								<?php esc_html_e( 'RPM', 'wp-mcp-ai' ); ?>
								<span class="dashicons dashicons-info" style="font-size: 14px;"></span>
							</th>
							<th style="width: 10%;" class="wp-mcp-ai-tooltip" title="<?php esc_attr_e( 'Context Window Size', 'wp-mcp-ai' ); ?>">
								<?php esc_html_e( 'Context', 'wp-mcp-ai' ); ?>
								<span class="dashicons dashicons-info" style="font-size: 14px;"></span>
							</th>
							<th style="width: 15%;"><?php esc_html_e( 'Fallback Model', 'wp-mcp-ai' ); ?></th>
							<th style="width: 10%;" class="wp-mcp-ai-tooltip" title="<?php esc_attr_e( 'Cost per 1K tokens (USD)', 'wp-mcp-ai' ); ?>">
								<?php esc_html_e( 'Cost/1K', 'wp-mcp-ai' ); ?>
								<span class="dashicons dashicons-info" style="font-size: 14px;"></span>
							</th>
							<th style="width: 10%;"><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
							<th style="width: 10%;"><?php esc_html_e( 'Actions', 'wp-mcp-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $model_configs as $model_id => $config ) : ?>
							<?php echo self::render_model_row( $model_id, $config, $providers ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php echo self::render_legend(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<style>
				.wp-mcp-ai-model-config-table-wrapper {
					background: #fff;
					padding: 20px;
					border: 1px solid #ccd0d4;
					box-shadow: 0 1px 1px rgba(0,0,0,.04);
				}
				.wp-mcp-ai-model-config-table input[type="number"],
				.wp-mcp-ai-model-config-table input[type="text"] {
					width: 100%;
					max-width: 150px;
				}
				.wp-mcp-ai-model-config-table select {
					width: 100%;
					max-width: 250px;
				}
				.wp-mcp-ai-model-status-active {
					color: #46b450;
					font-weight: bold;
				}
				.wp-mcp-ai-model-status-disabled {
					color: #dc3232;
				}
				.wp-mcp-ai-model-provider-badge {
					display: inline-block;
					padding: 3px 8px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: bold;
					text-transform: uppercase;
					color: white;
				}
				.wp-mcp-ai-model-provider-badge.openai {
					background-color: #10a37f;
				}
				.wp-mcp-ai-model-provider-badge.anthropic {
					background-color: #d4a574;
				}
				.wp-mcp-ai-model-provider-badge.gemini {
					background-color: #4285f4;
				}
				.wp-mcp-ai-model-provider-badge.ollama,
				.wp-mcp-ai-model-provider-badge.lm_studio {
					background-color: #666;
				}
				.wp-mcp-ai-storage-info {
					background: #f0f6fc;
					border-left: 4px solid #0073aa;
					padding: 12px;
					margin-bottom: 20px;
				}
				.wp-mcp-ai-storage-info p {
					margin: 0;
					font-size: 13px;
				}
				.wp-mcp-ai-legend {
					margin-top: 15px;
					padding: 12px;
					background: #f9f9f9;
					border: 1px solid #ddd;
					border-radius: 4px;
				}
				.wp-mcp-ai-legend h4 {
					margin-top: 0;
					font-size: 13px;
				}
				.wp-mcp-ai-legend ul {
					margin: 0;
					padding-left: 20px;
					font-size: 12px;
				}
			</style>
			<?php
			return ob_get_clean();

		} catch ( Exception $e ) {
			return self::render_error( $e->getMessage() );
		}
	}

	/**
	 * Render a single model configuration row.
	 *
	 * @param string $model_id Model identifier.
	 * @param array  $config   Model configuration.
	 * @param array  $providers Available providers.
	 * @return string HTML output.
	 */
	protected static function render_model_row( $model_id, $config, $providers ) {
		$model_id = sanitize_text_field( $model_id );

		// Extract config values with defaults.
		$name           = isset( $config['name'] ) ? esc_html( $config['name'] ) : esc_html( $model_id );
		$provider       = isset( $config['provider'] ) ? sanitize_key( $config['provider'] ) : '';
		$tpm            = isset( $config['tpm'] ) ? absint( $config['tpm'] ) : 0;
		$rpm            = isset( $config['rpm'] ) ? absint( $config['rpm'] ) : 0;
		$context        = isset( $config['context_window'] ) ? absint( $config['context_window'] ) : 0;
		$fallback       = isset( $config['fallback_model'] ) ? sanitize_text_field( $config['fallback_model'] ) : '';
		$cost           = isset( $config['cost_per_1k'] ) ? floatval( $config['cost_per_1k'] ) : 0.0;
		$status         = isset( $config['status'] ) ? sanitize_key( $config['status'] ) : 'active';
		$provider_label = isset( $providers[ $provider ] ) ? esc_html( $providers[ $provider ] ) : esc_html( ucfirst( $provider ) );

		// Get capability flags for the current model.
		$capability_flags = self::get_model_capability_flags( $model_id, $provider );

		// Get available models for fallback selection with capability filtering.
		$available_models = self::get_available_models_for_fallback( $model_id, $capability_flags );

		ob_start();
		?>
		<tr data-model-id="<?php echo esc_attr( $model_id ); ?>">
			<td>
				<strong><?php echo esc_html( $name ); ?></strong>
				<br>
				<code style="font-size: 11px;"><?php echo esc_html( $model_id ); ?></code>
			</td>
			<td>
				<span class="wp-mcp-ai-model-provider-badge <?php echo esc_attr( $provider ); ?>">
					<?php echo esc_html( $provider_label ); ?>
				</span>
			</td>
			<td>
				<input 
					type="number" 
					class="wp-mcp-ai-model-config-input"
					data-model="<?php echo esc_attr( $model_id ); ?>"
					data-field="tpm"
					value="<?php echo esc_attr( $tpm ); ?>"
					min="0"
				/>
			</td>
			<td>
				<input 
					type="number" 
					class="wp-mcp-ai-model-config-input"
					data-model="<?php echo esc_attr( $model_id ); ?>"
					data-field="rpm"
					value="<?php echo esc_attr( $rpm ); ?>"
					min="0"
				/>
			</td>
			<td>
				<?php echo number_format_i18n( $context ); ?>
			</td>
			<td>
				<select 
					class="wp-mcp-ai-model-config-input wp-mcp-ai-fallback-model-select"
					data-model="<?php echo esc_attr( $model_id ); ?>"
					data-field="fallback_model"
					style="width: 100%; max-width: 250px;"
				>
					<option value=""><?php esc_html_e( 'None', 'wp-mcp-ai' ); ?></option>
					<?php
					foreach ( $available_models as $group_key => $group_data ) :
						// Handle optgroup or single option.
						if ( is_array( $group_data ) && isset( $group_data['label'] ) && isset( $group_data['options'] ) ) {
							?>
							<optgroup label="<?php echo esc_attr( $group_data['label'] ); ?>">
								<?php foreach ( $group_data['options'] as $fallback_model_id => $fallback_model_label ) : ?>
									<?php if ( $fallback_model_id !== $model_id ) : // Don't allow model to be its own fallback ?>
										<option value="<?php echo esc_attr( $fallback_model_id ); ?>" <?php selected( $fallback, $fallback_model_id ); ?>>
											<?php echo esc_html( $fallback_model_label ); ?>
										</option>
									<?php endif; ?>
								<?php endforeach; ?>
							</optgroup>
							<?php
						}
					endforeach;
					?>
				</select>
			</td>
			<td>
				$<?php echo number_format( $cost, 4 ); ?>
			</td>
			<td>
				<span class="wp-mcp-ai-model-status-<?php echo esc_attr( $status ); ?>">
					<?php echo esc_html( ucfirst( $status ) ); ?>
				</span>
			</td>
			<td>
				<button 
					type="button" 
					class="button button-small wp-mcp-ai-save-model-config"
					data-model="<?php echo esc_attr( $model_id ); ?>"
				>
					<?php esc_html_e( 'Save', 'wp-mcp-ai' ); ?>
				</button>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render storage information banner.
	 *
	 * @return string HTML output.
	 */
	protected static function render_storage_info() {
		$jetengine_active = class_exists( 'Jet_Engine' );
		$cct_available    = class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' );

		ob_start();
		?>
		<div class="wp-mcp-ai-storage-info">
			<p>
				<span class="dashicons dashicons-database" style="vertical-align: middle;"></span>
				<strong><?php esc_html_e( 'Storage:', 'wp-mcp-ai' ); ?></strong>
				<?php esc_html_e( 'Primary: WordPress Options', 'wp-mcp-ai' ); ?>
				<?php if ( $jetengine_active && $cct_available ) : ?>
					| <?php esc_html_e( 'Backup: JetEngine CCT (Active)', 'wp-mcp-ai' ); ?>
					<span class="dashicons dashicons-yes-alt" style="color: #46b450; vertical-align: middle;"></span>
				<?php else : ?>
					| <?php esc_html_e( 'Backup: JetEngine CCT (Not Available)', 'wp-mcp-ai' ); ?>
					<span class="dashicons dashicons-marker" style="color: #999; vertical-align: middle;"></span>
				<?php endif; ?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render legend for abbreviations.
	 *
	 * @return string HTML output.
	 */
	protected static function render_legend() {
		ob_start();
		?>
		<div class="wp-mcp-ai-legend">
			<h4><?php esc_html_e( 'Legend', 'wp-mcp-ai' ); ?></h4>
			<ul>
				<li><strong>TPM:</strong> <?php esc_html_e( 'Tokens Per Minute - Maximum tokens that can be processed per minute', 'wp-mcp-ai' ); ?></li>
				<li><strong>RPM:</strong> <?php esc_html_e( 'Requests Per Minute - Maximum API requests allowed per minute', 'wp-mcp-ai' ); ?></li>
				<li><strong>Context:</strong> <?php esc_html_e( 'Context Window - Maximum tokens the model can process in a single request', 'wp-mcp-ai' ); ?></li>
				<li><strong>Fallback Model:</strong> <?php esc_html_e( 'Alternative model to use if this model is unavailable or rate limited', 'wp-mcp-ai' ); ?></li>
				<li><strong>Cost/1K:</strong> <?php esc_html_e( 'Cost per 1,000 tokens (input tokens, USD)', 'wp-mcp-ai' ); ?></li>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render empty state when no models are configured.
	 *
	 * @return string HTML output.
	 */
	protected static function render_empty_state() {
		ob_start();
		?>
		<div class="wp-mcp-ai-empty-state" style="text-align: center; padding: 40px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
			<span class="dashicons dashicons-admin-generic" style="font-size: 48px; color: #999; margin-bottom: 15px;"></span>
			<h3><?php esc_html_e( 'No Models Configured', 'wp-mcp-ai' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Model configurations will appear here once you configure API keys in the main settings.', 'wp-mcp-ai' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=general' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Configure API Keys', 'wp-mcp-ai' ); ?>
				</a>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render error message.
	 *
	 * @param string $message Error message.
	 * @return string HTML output.
	 */
	protected static function render_error( $message ) {
		ob_start();
		?>
		<div class="notice notice-error inline">
			<p>
				<strong><?php esc_html_e( 'Error:', 'wp-mcp-ai' ); ?></strong>
				<?php echo esc_html( $message ); ?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render JavaScript for model configuration table.
	 *
	 * This outputs the JS needed for inline editing functionality.
	 *
	 * @return string JavaScript code.
	 */
	public static function render_javascript() {
		ob_start();
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			'use strict';

			// Handle save button clicks.
			$('.wp-mcp-ai-save-model-config').on('click', function(e) {
				e.preventDefault();
				
				var $button = $(this);
				var modelId = $button.data('model');
				var $row = $button.closest('tr');
				
				// Collect all input values for this model.
				var config = {};
				$row.find('.wp-mcp-ai-model-config-input').each(function() {
					var $input = $(this);
					var field = $input.data('field');
					var value = $input.val();
					config[field] = value;
				});

				// Disable button and show loading state.
				$button.prop('disabled', true).text('<?php esc_html_e( 'Saving...', 'wp-mcp-ai' ); ?>');

				// Send AJAX request.
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wp_mcp_ai_save_model_config',
						nonce: wpMcpAi.nonce,
						model: modelId,
						config: config
					},
					success: function(response) {
						if (response.success) {
							// Show success feedback.
							$button.text('<?php esc_html_e( 'Saved!', 'wp-mcp-ai' ); ?>');
							$row.css('background-color', '#d4edda');
							
							setTimeout(function() {
								$button.prop('disabled', false).text('<?php esc_html_e( 'Save', 'wp-mcp-ai' ); ?>');
								$row.css('background-color', '');
							}, 2000);
						} else {
							// Show error.
							$button.prop('disabled', false).text('<?php esc_html_e( 'Error', 'wp-mcp-ai' ); ?>');
							alert(response.data || '<?php esc_html_e( 'Failed to save model configuration.', 'wp-mcp-ai' ); ?>');
							
							setTimeout(function() {
								$button.text('<?php esc_html_e( 'Save', 'wp-mcp-ai' ); ?>');
							}, 2000);
						}
					},
					error: function() {
						$button.prop('disabled', false).text('<?php esc_html_e( 'Error', 'wp-mcp-ai' ); ?>');
						alert('<?php esc_html_e( 'AJAX request failed', 'wp-mcp-ai' ); ?>');
						
						setTimeout(function() {
							$button.text('<?php esc_html_e( 'Save', 'wp-mcp-ai' ); ?>');
						}, 2000);
					}
				});
			});
		});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get capability flags for a model.
	 *
	 * Determines what capabilities a model has (vision, multimodal, etc.)
	 * based on known model capabilities.
	 *
	 * @param string $model_id Model identifier.
	 * @param string $provider Provider identifier.
	 * @return array Capability flags.
	 */
	protected static function get_model_capability_flags( $model_id, $provider ) {
		$capability_flags = array();

		// OpenAI models.
		if ( 'openai' === $provider ) {
			// GPT-5 Codex variants (coding-optimized, text-only).
			if ( strpos( $model_id, 'gpt-5-codex' ) !== false ) {
				return $capability_flags; // Text-only, no special flags.
			}

			// GPT-5 series (multimodal - vision capable) - 2025.
			if ( strpos( $model_id, 'gpt-5' ) !== false ) {
				$capability_flags[] = 'vision';
				$capability_flags[] = 'multimodal';
				return $capability_flags;
			}

			// Reasoning models (text-only).
			$reasoning_models = array( 'o1-2024-12-17', 'o1-preview', 'o1-mini', 'o3-mini', 'o3', 'o4-mini' );
			if ( in_array( $model_id, $reasoning_models, true ) ) {
				return $capability_flags; // Text-only, no special flags.
			}

			// GPT-4o series (multimodal - vision capable).
			if ( strpos( $model_id, 'gpt-4o' ) !== false || strpos( $model_id, 'chatgpt-4o' ) !== false ) {
				$capability_flags[] = 'vision';
				$capability_flags[] = 'multimodal';
				return $capability_flags;
			}

			// GPT-4 Turbo (multimodal - vision capable).
			if ( strpos( $model_id, 'gpt-4-turbo' ) !== false ) {
				$capability_flags[] = 'vision';
				$capability_flags[] = 'multimodal';
				return $capability_flags;
			}

			// GPT-4 Vision.
			if ( strpos( $model_id, 'gpt-4-vision' ) !== false ) {
				$capability_flags[] = 'vision';
				$capability_flags[] = 'multimodal';
				return $capability_flags;
			}

			// Legacy GPT-4 and GPT-3.5 (text-only).
			return $capability_flags;
		}

		// Anthropic models (all Claude models are multimodal).
		if ( 'anthropic' === $provider ) {
			$capability_flags[] = 'vision';
			$capability_flags[] = 'multimodal';
			return $capability_flags;
		}

		// Google Gemini models.
		if ( 'gemini' === $provider ) {
			// Gemini 3.x, 2.x and 1.5 series (multimodal - text, image, video).
			if ( strpos( $model_id, 'gemini-3' ) !== false || strpos( $model_id, 'gemini-2' ) !== false || strpos( $model_id, 'gemini-1.5' ) !== false || strpos( $model_id, 'gemini-exp' ) !== false || strpos( $model_id, 'gemini-live' ) !== false ) {
				$capability_flags[] = 'vision';
				$capability_flags[] = 'multimodal';
				return $capability_flags;
			}

			// Gemini Pro Vision.
			if ( strpos( $model_id, 'gemini-pro-vision' ) !== false ) {
				$capability_flags[] = 'vision';
				return $capability_flags;
			}

			// Gemma models (text-only).
			if ( strpos( $model_id, 'gemma' ) !== false ) {
				return $capability_flags;
			}

			// Default Gemini Pro (text-only).
			return $capability_flags;
		}

		// Ollama and LM Studio models - assume text-only unless specified.
		return $capability_flags;
	}

	/**
	 * Get available models for fallback selection with capability filtering.
	 *
	 * Returns models grouped by provider, filtered based on the source model's capabilities.
	 * This ensures fallback models have compatible capabilities.
	 *
	 * Uses WP_MCP_AI_Tool_Token_Limits as the source of truth for model availability,
	 * ensuring consistency with the rest of the plugin.
	 *
	 * @param string $source_model_id  The model we're selecting a fallback for.
	 * @param array  $source_capability_flags Capability flags of the source model.
	 * @return array Available models grouped by provider.
	 */
	protected static function get_available_models_for_fallback( $source_model_id, $source_capability_flags ) {
		// Determine if source model requires specific capabilities.
		$requires_vision     = in_array( 'vision', $source_capability_flags, true );
		$requires_multimodal = in_array( 'multimodal', $source_capability_flags, true );

		// Use WP_MCP_AI_Tool_Token_Limits to get available models (PRIMARY SOURCE OF TRUTH).
		if ( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			// Get capability flags in the format expected by get_available_models.
			$capability_flags = array();
			if ( $requires_vision ) {
				$capability_flags[] = 'requires-vision-model';
			}
			if ( $requires_multimodal ) {
				$capability_flags[] = 'requires-multimodal-model';
			}

			// Create a temporary tool slug to get filtered models.
			$temp_tool_slug = 'model_config_fallback_' . sanitize_key( $source_model_id );

			// Use filter to inject capability flags.
			add_filter(
				'wp_mcp_ai_tool_capability_flags',
				function ( $flags, $tool_slug ) use ( $temp_tool_slug, $capability_flags ) {
					if ( $tool_slug === $temp_tool_slug ) {
						return $capability_flags;
					}
					return $flags;
				},
				10,
				2
			);

			$models = WP_MCP_AI_Tool_Token_Limits::get_available_models( $temp_tool_slug );

			// Remove the filter.
			remove_all_filters( 'wp_mcp_ai_tool_capability_flags' );

			// Remove the 'default' option as it's not applicable for fallback.
			unset( $models['default'] );

			return $models;
		}

		// Fallback: use Model Service if Tool Token Limits not available.
		return self::get_models_from_service( $requires_vision, $requires_multimodal );
	}

	/**
	 * Get models from Model Service.
	 *
	 * Uses WP_MCP_AI_Model_Service as the source of truth for available models.
	 * This ensures the Model Configurations UI always reflects the latest model updates.
	 *
	 * @param bool $requires_vision       Whether vision capability is required.
	 * @param bool $requires_multimodal   Whether multimodal capability is required.
	 * @return array Model list grouped by provider.
	 */
	protected static function get_models_from_service( $requires_vision = false, $requires_multimodal = false ) {
		// Use Model Service if available (RECOMMENDED SOURCE OF TRUTH).
		if ( class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			$model_service = new WP_MCP_AI_Model_Service();
			$models        = array();
			$settings      = get_option( 'wp_mcp_ai_settings', array() );

			// Build capability flags for filtering.
			$capability_flags = array();
			if ( $requires_vision ) {
				$capability_flags[] = 'vision';
			}
			if ( $requires_multimodal ) {
				$capability_flags[] = 'multimodal';
			}

			$args = array( 'capability_flags' => $capability_flags );

			// Get models for each configured provider.
			$providers = array(
				'openai'    => array(
					'label' => __( 'OpenAI', 'wp-mcp-ai' ),
					'check' => ! empty( $settings['openai_api_key'] ),
				),
				'anthropic' => array(
					'label' => __( 'Anthropic (Claude)', 'wp-mcp-ai' ),
					'check' => ! empty( $settings['anthropic_api_key'] ),
				),
				'gemini'    => array(
					'label' => __( 'Google Gemini & Gemma', 'wp-mcp-ai' ),
					'check' => ! empty( $settings['gemini_api_key'] ),
				),
				'ollama'    => array(
					'label' => __( 'Ollama (Local)', 'wp-mcp-ai' ),
					'check' => ! empty( $settings['ollama_endpoint_url'] ) && ! empty( $settings['ollama_model'] ),
				),
				'lm_studio' => array(
					'label' => __( 'LM Studio (Local)', 'wp-mcp-ai' ),
					'check' => ! empty( $settings['lm_studio_endpoint_url'] ) && ! empty( $settings['lm_studio_model'] ),
				),
			);

			foreach ( $providers as $provider => $config ) {
				if ( ! $config['check'] ) {
					continue;
				}

				$provider_models = $model_service->get_models_for_provider( $provider, $args );

				if ( ! empty( $provider_models ) ) {
					$models[ $provider . '_group' ] = array(
						'label'   => $config['label'],
						'options' => $provider_models,
					);
				}
			}

			return $models;
		}

		// Ultimate fallback: return empty array if Model Service is not available.
		// This should never happen in production, but provides safety.
		return array();
	}
}
