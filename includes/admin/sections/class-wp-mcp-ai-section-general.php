<?php
/**
 * General Settings Section
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_General' ) ) {
	/**
	 * General settings section.
	 */
	class WP_MCP_AI_Section_General extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'general';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'General Settings', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'general';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 10;
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure basic plugin settings and operational parameters.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			// Get available providers for dropdown.
			$provider_options = array(
				'openai'    => __( 'OpenAI', 'wp-mcp-ai' ),
				'gemini'    => __( 'Google Gemini', 'wp-mcp-ai' ),
				'ollama'    => __( 'Ollama (Local AI)', 'wp-mcp-ai' ),
				'lm_studio' => __( 'LM Studio (Local AI)', 'wp-mcp-ai' ),
			);

			// Get available assistants for dropdown.
			$assistant_options = $this->get_assistant_options();

			return array(
				'default_provider'                      => array(
					'type'        => 'select',
					'label'       => __( 'Default AI Provider', 'wp-mcp-ai' ),
					'description' => __( 'The primary AI provider used when no specific provider is specified. This affects new conversations and REST API requests. Make sure the selected provider is properly configured in the Providers tab.', 'wp-mcp-ai' ),
					'options'     => $provider_options,
					'default'     => 'openai',
				),
				'default_assistant'                     => array(
					'type'        => 'select',
					'label'       => __( 'Default Assistant', 'wp-mcp-ai' ),
					'description' => __( 'The assistant used by default when one is not explicitly specified in REST API interactions. Leave as "None" to require explicit assistant selection.', 'wp-mcp-ai' ),
					'options'     => $assistant_options,
					'default'     => 0,
				),
				'enable_logging'                        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Logging', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable basic error and activity logging', 'wp-mcp-ai' ),
					'description'    => __( 'Records errors, warnings, and key activity (tool executions, API requests) to help troubleshoot issues. View logs in the Advanced tab.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'enable_extended_logging'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Extended Logging', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable verbose debug logging with full request/response data', 'wp-mcp-ai' ),
					'description'    => __( 'Logs complete API request/response payloads, context data, and detailed execution traces. Warning: This can generate very large log files and may impact site performance. Only enable for short-term debugging.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'enable_agentic_loop_logging'           => array(
					'type'           => 'checkbox',
					'label'          => __( 'Agentic Loop Logging', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable detailed logging for agentic loop iterations and tool calls', 'wp-mcp-ai' ),
					'description'    => __( 'Logs each iteration of the agentic loop, including tool calls, tool results, and iteration timing. Useful for debugging assistant behavior and tool execution flow.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'enable_api_logging'                    => array(
					'type'           => 'checkbox',
					'label'          => __( 'API Request/Response Logging', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable logging for AI provider API requests and responses', 'wp-mcp-ai' ),
					'description'    => __( 'Logs API requests to OpenAI, Anthropic, Gemini, LM Studio and their responses. Helps debug API connectivity and response issues.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'enable_tool_execution_logging'         => array(
					'type'           => 'checkbox',
					'label'          => __( 'Tool Execution Logging', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable detailed logging for individual tool executions', 'wp-mcp-ai' ),
					'description'    => __( 'Logs each tool execution with arguments and results. Useful for debugging tool behavior and data flow.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'enable_chat_interaction_logging'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Chat Interaction Logging', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable logging for chat requests and responses', 'wp-mcp-ai' ),
					'description'    => __( 'Logs complete chat interactions including user messages and assistant responses. Helps track conversation flow and debugging message handling.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'show_usage_costs'                      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Show Usage Costs', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Display token usage and estimated costs in chat interface', 'wp-mcp-ai' ),
					'description'    => __( 'Shows small badges with total tokens and estimated cost (in USD) after each assistant response in the frontend chat. Helps users understand API usage and costs in real-time. Phase 7: Enhanced Token Tracking with Real-Time Cost Attribution.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'delete_on_uninstall'                   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Delete Data on Uninstall', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Remove all plugin data when uninstalling', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, all settings and data will be deleted when the plugin is uninstalled.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'max_history_messages'                  => array(
					'type'        => 'number',
					'label'       => __( 'Max History Messages', 'wp-mcp-ai' ),
					'description' => __( 'Maximum number of previous messages to include in chat context. Higher values provide more context but increase token usage.', 'wp-mcp-ai' ),
					'default'     => 10,
					'placeholder' => '10',
					'min'         => 1,
					'max'         => 100,
				),
				'request_timeout'                       => array(
					'type'        => 'number',
					'label'       => __( 'Request Timeout (seconds)', 'wp-mcp-ai' ),
					'description' => __( 'How long to wait for AI provider responses before timing out. Increase for complex requests or slower providers.', 'wp-mcp-ai' ),
					'default'     => 60,
					'placeholder' => '60',
					'min'         => 10,
					'max'         => 600,
				),
				// Custom Filters fields.
				'filter_default_light_model'            => array(
					'type'        => 'text',
					'label'       => __( 'Default Light Model', 'wp-mcp-ai' ),
					'description' => __( 'Default AI model for simple tasks. Overrides the wp_mcp_ai_default_light_model filter. Default: gpt-4o-mini', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'gpt-4o-mini',
				),
				'filter_default_advanced_model'         => array(
					'type'        => 'text',
					'label'       => __( 'Default Advanced Model', 'wp-mcp-ai' ),
					'description' => __( 'Default AI model for complex tasks. Overrides the wp_mcp_ai_default_advanced_model filter. Default: gpt-4o', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'gpt-4o',
				),
				'filter_max_agentic_iterations'         => array(
					'type'        => 'number',
					'label'       => __( 'Max Agentic Iterations', 'wp-mcp-ai' ),
					'description' => __( 'Maximum number of tool execution loops per request. Prevents infinite loops. Range: 1-50. Default: 5', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '5',
				),
				'filter_resource_max_tokens'            => array(
					'type'        => 'number',
					'label'       => __( 'Resource Max Tokens', 'wp-mcp-ai' ),
					'description' => __( 'Maximum tokens for AI responses based on workload tier. Overrides the wp_mcp_ai_resource_max_tokens filter. Leave empty for auto-detection.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'Auto',
				),
				'filter_resource_request_timeout'       => array(
					'type'        => 'number',
					'label'       => __( 'Resource Request Timeout (seconds)', 'wp-mcp-ai' ),
					'description' => __( 'Request timeout based on workload tier. Overrides the wp_mcp_ai_resource_request_timeout filter. Leave empty for auto-detection.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'Auto',
				),
				'filter_max_retries'                    => array(
					'type'        => 'number',
					'label'       => __( 'Max Retries', 'wp-mcp-ai' ),
					'description' => __( 'Maximum retry attempts for failed API requests. Overrides the wp_mcp_ai_max_retries filter. Default: 3', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '3',
				),
				'filter_max_retry_delay'                => array(
					'type'        => 'number',
					'label'       => __( 'Max Retry Delay (seconds)', 'wp-mcp-ai' ),
					'description' => __( 'Maximum delay between retry attempts. Overrides the wp_mcp_ai_max_retry_delay filter. Default: 60', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '60',
				),
				'filter_max_attachment_bytes'           => array(
					'type'        => 'number',
					'label'       => __( 'Max Attachment Size (bytes)', 'wp-mcp-ai' ),
					'description' => __( 'Maximum size for file attachments in chat. Overrides the wp_mcp_ai_max_attachment_bytes filter. Default: 10485760 (10MB)', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '10485760',
				),
				'filter_default_ollama_endpoint_url'    => array(
					'type'        => 'url',
					'label'       => __( 'Default Ollama Endpoint URL', 'wp-mcp-ai' ),
					'description' => __( 'Default endpoint URL for Ollama local AI. Overrides the wp_mcp_ai_default_ollama_endpoint_url filter. Default: http://localhost:11434', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'http://localhost:11434',
				),
				'filter_default_lm_studio_endpoint_url' => array(
					'type'        => 'url',
					'label'       => __( 'Default LM Studio Endpoint URL', 'wp-mcp-ai' ),
					'description' => __( 'Default endpoint URL for LM Studio local AI. Overrides the wp_mcp_ai_default_lm_studio_endpoint_url filter. Default: http://localhost:1234', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'http://localhost:1234',
				),
			);
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		protected function get_subtab_groups() {
			return array(
				'core'           => array(
					'id'     => 'core',
					'label'  => __( 'Core Settings', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-settings',
					'fields' => array( 'default_provider', 'default_assistant' ),
				),
				'behavior'       => array(
					'id'     => 'behavior',
					'label'  => __( 'Behavior & Limits', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-performance',
					'fields' => array( 'max_history_messages', 'request_timeout' ),
				),
				'data'           => array(
					'id'     => 'data',
					'label'  => __( 'Data Management', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-database',
					'fields' => array(
						'enable_logging',
						'enable_extended_logging',
						'enable_agentic_loop_logging',
						'enable_api_logging',
						'enable_tool_execution_logging',
						'enable_chat_interaction_logging',
						'show_usage_costs',
						'delete_on_uninstall',
					),
				),
				'custom_filters' => array(
					'id'          => 'custom_filters',
					'label'       => __( 'Custom AI Settings (Filters)', 'wp-mcp-ai' ),
					'icon'        => 'dashicons-filter',
					'description' => __( 'Configure advanced AI behavior settings through a user-friendly interface. These settings override WordPress filter defaults and allow you to customize AI operations without writing code. <strong>Leave fields empty to use system defaults.</strong>', 'wp-mcp-ai' ),
					'fields'      => array(
						'filter_default_light_model',
						'filter_default_advanced_model',
						'filter_max_agentic_iterations',
						'filter_resource_max_tokens',
						'filter_resource_request_timeout',
						'filter_max_retries',
						'filter_max_retry_delay',
						'filter_max_attachment_bytes',
						'filter_default_ollama_endpoint_url',
						'filter_default_lm_studio_endpoint_url',
					),
				),
			);
		}

		/**
		 * Get active sub-tab.
		 *
		 * @return string
		 */
		protected function get_active_subtab() {
			$subtab_groups = $this->get_subtab_groups();
			$subtab        = '';

			// Check POST data first (when form is being submitted), then fall back to GET.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			if ( isset( $_POST['subtab'] ) ) {
				$subtab = sanitize_key( $_POST['subtab'] );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( $_GET['subtab'] );
			}

			// Default to 'core' if not set or invalid.
			if ( empty( $subtab ) || ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'core';
			}

			return $subtab;
		}

		/**
		 * Get assistant options for select dropdown.
		 *
		 * @return array
		 */
		private function get_assistant_options() {
			$options = array(
				0 => __( 'None (explicit selection required)', 'wp-mcp-ai' ),
			);

			// Get published assistants.
			$assistants = get_posts(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			if ( ! empty( $assistants ) ) {
				foreach ( $assistants as $assistant ) {
					$options[ $assistant->ID ] = $assistant->post_title;
				}
			}

			return $options;
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields        = $this->get_fields();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();

			// Get the active group.
			if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
				return;
			}

			$active_group = $subtab_groups[ $active_subtab ];

			// Special handling for custom_filters subtab with grouped fields.
			if ( 'custom_filters' === $active_subtab ) {
				$this->render_custom_filters_fields( $fields, $active_group );
			} else {
				// Render fields for other sub-tabs normally.
				foreach ( $active_group['fields'] as $key ) {
					if ( isset( $fields[ $key ] ) ) {
						$this->render_field( $key, $fields[ $key ] );
					}
				}
			}

			// Render logging table if we're on the data management sub-tab.
			if ( 'data' === $active_subtab ) {
				echo '</table>'; // Close the form table.
				$this->render_logging_table();
				echo '<table class="form-table" role="presentation" style="display:none;">'; // Re-open hidden table for structure.
			}
		}

		/**
		 * Render custom filters fields with grouping.
		 *
		 * @param array $fields All available fields.
		 * @param array $active_group Active subtab group configuration.
		 */
		private function render_custom_filters_fields( $fields, $active_group ) {
			// Organize fields into logical groups for better UX.
			$groups = array(
				'model_selection'     => array(
					'title'  => __( 'AI Model Selection', 'wp-mcp-ai' ),
					'fields' => array( 'filter_default_light_model', 'filter_default_advanced_model' ),
				),
				'resource_management' => array(
					'title'  => __( 'Resource Management', 'wp-mcp-ai' ),
					'fields' => array( 'filter_max_agentic_iterations', 'filter_resource_max_tokens', 'filter_resource_request_timeout' ),
				),
				'retry_handling'      => array(
					'title'  => __( 'Retry & Error Handling', 'wp-mcp-ai' ),
					'fields' => array( 'filter_max_retries', 'filter_max_retry_delay' ),
				),
				'file_limits'         => array(
					'title'  => __( 'File & Attachment Limits', 'wp-mcp-ai' ),
					'fields' => array( 'filter_max_attachment_bytes' ),
				),
				'endpoint_urls'       => array(
					'title'  => __( 'Local AI Endpoint URLs', 'wp-mcp-ai' ),
					'fields' => array( 'filter_default_ollama_endpoint_url', 'filter_default_lm_studio_endpoint_url' ),
				),
			);

			foreach ( $groups as $group_id => $group ) {
				?>
				<tr class="filter-group-header">
					<td colspan="2">
						<h3 class="filter-group-title">
							<?php echo esc_html( $group['title'] ); ?>
						</h3>
					</td>
				</tr>
				<?php
				foreach ( $group['fields'] as $field_key ) {
					if ( isset( $fields[ $field_key ] ) ) {
						$this->render_field( $field_key, $fields[ $field_key ] );
					}
				}
			}
		}

		/**
		 * Override render_wrapper to include sub-tab navigation.
		 */
		public function render_wrapper() {
			$description   = $this->get_description();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();
			$active_group  = isset( $subtab_groups[ $active_subtab ] ) ? $subtab_groups[ $active_subtab ] : null;
			?>
		<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
			<h2><?php echo esc_html( $this->get_title() ); ?></h2>
			<?php if ( $description ) : ?>
				<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
			<?php endif; ?>

			<div class="wp-mcp-ai-provider-subtabs">
				<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'General settings sub-tabs', 'wp-mcp-ai' ); ?>">
					<?php foreach ( $subtab_groups as $group ) : ?>
						<?php
						$subtab_url = add_query_arg(
							array(
								'page'   => 'wp-mcp-ai-dashboard',
								'tab'    => 'general',
								'subtab' => $group['id'],
							),
							admin_url( 'admin.php' )
						);
						$is_active  = ( $group['id'] === $active_subtab );
						?>
						<a href="<?php echo esc_url( $subtab_url ); ?>" 
							class="wp-mcp-ai-subtab <?php echo $is_active ? 'wp-mcp-ai-subtab-active' : ''; ?>"
							data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
							<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
							<?php echo esc_html( $group['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<!-- Hidden field to preserve subtab during form submission -->
				<input type="hidden" name="subtab" value="<?php echo esc_attr( $active_subtab ); ?>" />

				<div class="wp-mcp-ai-subtab-content">
					<?php if ( $active_group && isset( $active_group['description'] ) ) : ?>
						<p class="subtab-description"><?php echo wp_kses_post( $active_group['description'] ); ?></p>
					<?php endif; ?>
					<table class="form-table" role="presentation">
						<?php $this->render(); ?>
					</table>
				</div>
			</div>
		</div>
			<?php
		}

		/**
		 * Render the logging table if logging is enabled.
		 */
		private function render_logging_table() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Only show the logging table if logging is enabled.
			if ( empty( $settings['enable_logging'] ) ) {
				return;
			}

			$entries = WP_MCP_AI_Logger::get_recent_error_messages();
			?>
			<div class="wp-mcp-ai-error-log-section" style="margin-top: 30px;">
				<h3><?php esc_html_e( 'Recent Error & Activity Log', 'wp-mcp-ai' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Recent error and warning messages (most recent first). Expand an entry to view additional context.', 'wp-mcp-ai' ); ?></p>
				<?php if ( empty( $entries ) ) : ?>
					<p class="description"><?php esc_html_e( 'No error or warning messages have been recorded yet.', 'wp-mcp-ai' ); ?></p>
				<?php else : ?>
					<ul class="wp-mcp-ai-log-preview" style="list-style: none; padding: 0; margin: 15px 0;">
						<?php
						foreach ( $entries as $entry ) :
							$timestamp = '';

							if ( ! empty( $entry['timestamp'] ) ) {
								$timestamp = get_date_from_gmt(
									$entry['timestamp'],
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
								);
							}

							$type_label    = strtoupper( $entry['type'] );
							$message_label = $entry['message'];
							$context_label = '';

							if ( isset( $entry['context'] ) && ! empty( $entry['context'] ) ) {
								$options = 0;

								if ( defined( 'JSON_PRETTY_PRINT' ) ) {
									$options |= JSON_PRETTY_PRINT;
								}

								if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
									$options |= JSON_UNESCAPED_SLASHES;
								}

								$context_json = wp_json_encode( $entry['context'], $options );

								if ( false !== $context_json ) {
									$context_label = $context_json;
								}
							}
							?>
							<li style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border-left: 3px solid #dc3232; border-radius: 3px;">
								<?php if ( ! empty( $timestamp ) ) : ?>
									<span class="wp-mcp-ai-log-preview__time" style="color: #666; font-size: 0.9em;"><?php echo esc_html( $timestamp ); ?></span>
									&mdash;
								<?php endif; ?>
								<span class="wp-mcp-ai-log-preview__type" style="font-weight: bold; color: #dc3232;"><?php echo esc_html( $type_label ); ?></span>:
								<span class="wp-mcp-ai-log-preview__message"><?php echo esc_html( $message_label ); ?></span>
								<?php if ( '' !== $context_label ) : ?>
									<details class="wp-mcp-ai-log-preview__context" style="margin-top: 10px;">
										<summary style="cursor: pointer; color: #0073aa;"><?php esc_html_e( 'Context details', 'wp-mcp-ai' ); ?></summary>
										<pre style="background: #fff; padding: 10px; margin-top: 10px; overflow-x: auto; border: 1px solid #ddd; border-radius: 3px; font-size: 0.85em;"><?php echo esc_html( $context_label ); ?></pre>
									</details>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php
				$log_file_path    = WP_MCP_AI_Logger::get_log_file_path();
				$log_file_exists  = WP_MCP_AI_Logger::does_log_file_exist();
				$log_file_size    = WP_MCP_AI_Logger::get_log_file_size();
				$log_size_display = '';

				if ( null !== $log_file_size ) {
					$log_size_display = function_exists( 'size_format' )
					? size_format( $log_file_size, 2 )
					: $log_file_size . ' bytes';
				}
				?>
				<div class="wp-mcp-ai-log-meta" style="margin-top: 15px; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 3px;">
					<?php if ( '' !== $log_file_path ) : ?>
						<p class="description">
							<?php
							if ( $log_file_exists ) {
								if ( '' === $log_size_display ) {
									$log_size_display = __( 'Unknown size', 'wp-mcp-ai' );
								}

								printf(
									/* translators: 1: Path to the PHP error log. 2: Human readable size. */
									esc_html__( 'PHP error log: %1$s (%2$s).', 'wp-mcp-ai' ),
									'<code>' . esc_html( $log_file_path ) . '</code>',
									esc_html( $log_size_display )
								);
							} else {
								printf(
									/* translators: %s: Path to the PHP error log. */
									esc_html__( 'PHP error log: %s (not created yet).', 'wp-mcp-ai' ),
									'<code>' . esc_html( $log_file_path ) . '</code>'
								);
							}
							?>
						</p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Unable to determine the PHP error log location. Check your server configuration if you need to inspect or prune the log.', 'wp-mcp-ai' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Sanitize section input.
		 *
		 * @param array $input Raw input from form.
		 * @return array Sanitized input.
		 */
		public function sanitize( $input ) {
			// Use parent's subtab-aware sanitization.
			$sanitized = parent::sanitize( $input );

			// Special handling for default_assistant: convert to integer.
			if ( isset( $sanitized['default_assistant'] ) ) {
				$sanitized['default_assistant'] = absint( $sanitized['default_assistant'] );
			}

			return $sanitized;
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			$errors = array();

			// Validate default_provider.
			if ( isset( $input['default_provider'] ) ) {
				$valid_providers = array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' );
				if ( ! in_array( $input['default_provider'], $valid_providers, true ) ) {
					$errors[] = __( 'Invalid AI provider selected.', 'wp-mcp-ai' );
				}
			}

			// Validate default_assistant.
			if ( isset( $input['default_assistant'] ) ) {
				$assistant_id = absint( $input['default_assistant'] );
				if ( $assistant_id > 0 ) {
					$assistant = get_post( $assistant_id );
					if ( ! $assistant || 'mcp_ai_assistant' !== $assistant->post_type ) {
						$errors[] = __( 'Invalid assistant selected.', 'wp-mcp-ai' );
					}
				}
			}

			// Validate max_history_messages.
			if ( isset( $input['max_history_messages'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['max_history_messages'],
					1,
					100
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				}
			}

			// Validate request_timeout.
			if ( isset( $input['request_timeout'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['request_timeout'],
					10,
					600
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				}
			}

			// Validate filter_max_agentic_iterations.
			if ( isset( $input['filter_max_agentic_iterations'] ) && '' !== $input['filter_max_agentic_iterations'] ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['filter_max_agentic_iterations'],
					1,
					50
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Max Agentic Iterations: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate filter_resource_max_tokens.
			if ( isset( $input['filter_resource_max_tokens'] ) && '' !== $input['filter_resource_max_tokens'] ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['filter_resource_max_tokens'],
					100,
					128000
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Resource Max Tokens: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate filter_resource_request_timeout.
			if ( isset( $input['filter_resource_request_timeout'] ) && '' !== $input['filter_resource_request_timeout'] ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['filter_resource_request_timeout'],
					10,
					600
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Resource Request Timeout: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate filter_max_retries.
			if ( isset( $input['filter_max_retries'] ) && '' !== $input['filter_max_retries'] ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['filter_max_retries'],
					0,
					10
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Max Retries: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate filter_max_retry_delay.
			if ( isset( $input['filter_max_retry_delay'] ) && '' !== $input['filter_max_retry_delay'] ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['filter_max_retry_delay'],
					1,
					300
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Max Retry Delay: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate filter_max_attachment_bytes.
			if ( isset( $input['filter_max_attachment_bytes'] ) && '' !== $input['filter_max_attachment_bytes'] ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['filter_max_attachment_bytes'],
					1024,
					104857600 // 100MB max.
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Max Attachment Size: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate URLs.
			if ( isset( $input['filter_default_ollama_endpoint_url'] ) && '' !== $input['filter_default_ollama_endpoint_url'] ) {
				$url = filter_var( $input['filter_default_ollama_endpoint_url'], FILTER_VALIDATE_URL );
				if ( false === $url ) {
					$errors[] = __( 'Default Ollama Endpoint URL must be a valid URL.', 'wp-mcp-ai' );
				}
			}

			if ( isset( $input['filter_default_lm_studio_endpoint_url'] ) && '' !== $input['filter_default_lm_studio_endpoint_url'] ) {
				$url = filter_var( $input['filter_default_lm_studio_endpoint_url'], FILTER_VALIDATE_URL );
				if ( false === $url ) {
					$errors[] = __( 'Default LM Studio Endpoint URL must be a valid URL.', 'wp-mcp-ai' );
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}
