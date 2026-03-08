<?php
/**
 * Orchestration Dashboard Renderer
 *
 * Handles UI rendering for orchestration dashboard components including
 * sliders, presets, health monitoring, and predictive insights.
 *
 * CRITICAL: All methods implement defensive programming with try-catch blocks
 * and fallback rendering to ensure the admin UI never breaks, even if
 * underlying services fail.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Orchestration_Renderer' ) ) {
	/**
	 * Renders orchestration dashboard UI components.
	 *
	 * Each rendering method includes:
	 * - Try-catch error handling
	 * - Comprehensive logging on failure
	 * - Graceful fallback UI when errors occur
	 * - Security (escaping, sanitization)
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Orchestration_Renderer {

		/**
		 * Render a range slider field.
		 *
		 * @param string $key    Field key.
		 * @param array  $config Field configuration.
		 * @return string HTML output or fallback on error.
		 */
		public static function render_slider( $key, $config ) {
			try {
				$label       = isset( $config['label'] ) ? $config['label'] : '';
				$description = isset( $config['description'] ) ? $config['description'] : '';
				$min         = isset( $config['min'] ) ? absint( $config['min'] ) : 0;
				$max         = isset( $config['max'] ) ? absint( $config['max'] ) : 100;
				$step        = isset( $config['step'] ) ? absint( $config['step'] ) : 1;
				$default     = isset( $config['default'] ) ? absint( $config['default'] ) : $min;
				$suffix      = isset( $config['suffix'] ) ? sanitize_text_field( $config['suffix'] ) : '';
				$value       = WP_MCP_AI_Settings_Registry::get_setting( $key, $default );

				// Validate value is within bounds.
				$value = max( $min, min( $max, absint( $value ) ) );

				// Output buffering for slider control rendering - buffer is closed with ob_get_clean() at line 122.
				ob_start();
				?>
				<div class="wp-mcp-ai-slider-control">
					<label for="<?php echo esc_attr( $key ); ?>" class="slider-label">
						<?php echo esc_html( $label ); ?>
					</label>
					<div class="slider-wrapper">
						<span class="slider-min"><?php echo esc_html( $min . $suffix ); ?></span>
						<input
							type="range"
							id="<?php echo esc_attr( $key ); ?>"
							name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
							min="<?php echo esc_attr( $min ); ?>"
							max="<?php echo esc_attr( $max ); ?>"
							step="<?php echo esc_attr( $step ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
							class="wp-mcp-ai-slider"
							data-suffix="<?php echo esc_attr( $suffix ); ?>"
							aria-label="<?php echo esc_attr( $label ); ?>"
						/>
						<span class="slider-max"><?php echo esc_html( $max . $suffix ); ?></span>
						<span class="slider-value" id="<?php echo esc_attr( $key ); ?>-value">
							[<?php echo esc_html( $value . $suffix ); ?>]
						</span>
					</div>
					<?php if ( $description ) : ?>
						<p class="description"><?php echo wp_kses_post( $description ); ?></p>
					<?php endif; ?>
				</div>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				// Log error if logger is available.
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_error' ) ) {
					try {
						WP_MCP_AI_Logger::log_error(
							'Slider rendering failed: ' . $e->getMessage(),
							array(
								'component' => 'orchestration_renderer',
								'method'    => 'render_slider',
								'field_key' => $key,
								'exception' => $e->getMessage(),
							)
						);
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Empty catch block intentional; exception is non-critical in this rendering context and silently ignored by design.
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return simple fallback.
				return sprintf(
					'<p class="description error">%s</p>',
					esc_html__( 'Slider control temporarily unavailable.', 'mcp-ai-wpoos' )
				);
			}
		}

		/**
		 * Render configuration presets selector.
		 *
		 * @param array $presets Available presets configuration.
		 * @return string HTML output or fallback on error.
		 * @throws Exception If presets array is invalid or empty.
		 */
		public static function render_presets_selector( $presets ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- Private/protected helper method with self-documenting name; PHPDoc block not required by WPCS for private methods.
			try {
				if ( ! is_array( $presets ) || empty( $presets ) ) {
					throw new Exception( 'Invalid presets array' );
				}

				$current_preset = WP_MCP_AI_Orchestration_Preset_Service::get_active_preset();

				// Get the current preset name for display.
				$current_preset_name = isset( $presets[ $current_preset ]['name'] ) ? $presets[ $current_preset ]['name'] : __( 'Unknown', 'mcp-ai-wpoos' );

				ob_start();
				?>
				<div class="wp-mcp-ai-presets-section">
					<h3><?php esc_html_e( 'Configuration Presets', 'mcp-ai-wpoos' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Choose a preset configuration or customize your own settings. Clicking a preset will apply its settings immediately.', 'mcp-ai-wpoos' ); ?>
					</p>
					<div class="notice notice-info inline" style="margin: 15px 0; padding: 10px 15px;">
						<p style="margin: 0;">
							<span class="dashicons dashicons-info" style="vertical-align: middle; color: #2271b1;"></span>
							<strong><?php esc_html_e( 'What Presets Control:', 'mcp-ai-wpoos' ); ?></strong>
							<?php esc_html_e( 'Each preset configures context window limits (max tokens per request), health monitoring thresholds, budget allocation, and predictive settings. These apply uniformly across all AI providers (OpenAI, Gemini, Anthropic, Ollama, LM Studio).', 'mcp-ai-wpoos' ); ?>
						</p>
					</div>
					<div class="wp-mcp-ai-current-preset-indicator">
						<span class="dashicons dashicons-admin-settings"></span>
						<strong><?php esc_html_e( 'Currently Active:', 'mcp-ai-wpoos' ); ?></strong>
						<span class="current-preset-name"><?php echo esc_html( $current_preset_name ); ?></span>
					</div>
					<div class="wp-mcp-ai-presets-grid">
						<?php foreach ( $presets as $preset_id => $preset_config ) : ?>
							<?php
							if ( ! is_array( $preset_config ) || ! isset( $preset_config['name'] ) ) {
								continue; // Skip invalid presets.
							}

							$is_active      = ( $preset_id === $current_preset );
							$is_default     = ( 'custom' === $preset_id );
							$is_recommended = ( 'auto' === $preset_id );
							$badge_class    = $is_default ? 'default' : ( $is_recommended ? 'recommended' : '' );
							$active_class   = $is_active ? 'active' : '';
							?>
							<div class="preset-card <?php echo esc_attr( $badge_class . ' ' . $active_class ); ?>"
								data-preset="<?php echo esc_attr( $preset_id ); ?>">
								<div class="preset-header">
									<h4><?php echo esc_html( $preset_config['name'] ); ?></h4>
									<?php if ( $is_default ) : ?>
										<span class="preset-badge default"><?php esc_html_e( 'DEFAULT', 'mcp-ai-wpoos' ); ?></span>
									<?php elseif ( $is_recommended ) : ?>
										<span class="preset-badge recommended"><?php esc_html_e( 'RECOMMENDED', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</div>
								<p class="preset-description">
									<?php echo esc_html( isset( $preset_config['description'] ) ? $preset_config['description'] : '' ); ?>
								</p>
								<?php
								// Display key settings for this preset.
								if ( ! empty( $preset_config['settings'] ) ) :
									$settings = $preset_config['settings'];
									?>
									<div class="preset-settings-preview">
										<?php if ( isset( $settings['high_tier_max_tokens'] ) ) : ?>
											<div class="preset-setting-item">
												<span class="preset-setting-icon dashicons dashicons-chart-bar"></span>
												<span class="preset-setting-label"><?php esc_html_e( 'Context Window:', 'mcp-ai-wpoos' ); ?></span>
												<span class="preset-setting-value">
													<?php
													/* translators: %s: formatted token count */
													printf( esc_html__( '%s tokens', 'mcp-ai-wpoos' ), esc_html( number_format( $settings['high_tier_max_tokens'] ) ) );
													?>
												</span>
											</div>
										<?php endif; ?>
										<?php if ( isset( $settings['per_call_token_limit'] ) ) : ?>
											<div class="preset-setting-item">
												<span class="preset-setting-icon dashicons dashicons-admin-tools"></span>
												<span class="preset-setting-label"><?php esc_html_e( 'Per-Call Limit:', 'mcp-ai-wpoos' ); ?></span>
												<span class="preset-setting-value">
													<?php
													/* translators: %s: formatted token count */
													printf( esc_html__( '%s tokens', 'mcp-ai-wpoos' ), esc_html( number_format( $settings['per_call_token_limit'] ) ) );
													?>
												</span>
											</div>
										<?php endif; ?>
										<?php if ( isset( $settings['memory_critical_threshold'] ) ) : ?>
											<div class="preset-setting-item">
												<span class="preset-setting-icon dashicons dashicons-warning"></span>
												<span class="preset-setting-label"><?php esc_html_e( 'Memory Threshold:', 'mcp-ai-wpoos' ); ?></span>
												<span class="preset-setting-value"><?php echo esc_html( $settings['memory_critical_threshold'] ); ?>%</span>
											</div>
										<?php endif; ?>
									</div>
								<?php endif; ?>
								<?php if ( $is_active ) : ?>
									<div class="preset-status"><?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?></div>
								<?php else : ?>
									<button type="button" class="button button-secondary apply-preset"
										data-preset="<?php echo esc_attr( $preset_id ); ?>"
										aria-label="
										<?php
										/* translators: %s: preset name */
										echo esc_attr( sprintf( __( 'Apply %s preset', 'mcp-ai-wpoos' ), $preset_config['name'] ) );
										?>
										">
										<?php esc_html_e( 'Apply', 'mcp-ai-wpoos' ); ?>
									</button>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
					<input type="hidden" name="wp_mcp_ai_settings[orchestration_preset]"
						id="orchestration_preset" value="<?php echo esc_attr( $current_preset ); ?>" />
				</div>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				// Log error if logger is available.
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_error' ) ) {
					try {
						WP_MCP_AI_Logger::log_error(
							'Presets selector rendering failed: ' . $e->getMessage(),
							array(
								'component' => 'orchestration_renderer',
								'method'    => 'render_presets_selector',
								'exception' => $e->getMessage(),
							)
						);
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Empty catch block intentional; exception is non-critical in this rendering context and silently ignored by design.
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return simple fallback.
				return sprintf(
					'<div class="notice notice-warning inline"><p>%s</p></div>',
					esc_html__( 'Configuration presets temporarily unavailable. Your settings are safe.', 'mcp-ai-wpoos' )
				);
			}
		}

		/**
		 * Render health status banner.
		 *
		 * Uses Health Service with full error handling to ensure
		 * the UI never breaks even if monitoring fails.
		 *
		 * @return string HTML output or fallback on error.
		 * @throws Exception If health service is not available.
		 */
		public static function render_health_status() {
			try {
				// Use the Health Service for robust health status retrieval.
				if ( ! class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
					throw new Exception( 'Health service not available' );
				}

				$health = WP_MCP_AI_Orchestration_Health_Service::get_health_status();

				if ( ! is_array( $health ) ) {
					throw new Exception( 'Invalid health status response' );
				}

				$status  = isset( $health['status'] ) ? sanitize_key( $health['status'] ) : 'unknown';
				$label   = isset( $health['label'] ) ? sanitize_text_field( $health['label'] ) : __( 'Unknown', 'mcp-ai-wpoos' );
				$icon    = isset( $health['icon'] ) ? sanitize_text_field( $health['icon'] ) : '○';
				$metrics = isset( $health['metrics'] ) && is_array( $health['metrics'] ) ? $health['metrics'] : array();

				$memory_percent = isset( $metrics['memory']['percent'] ) ? floatval( $metrics['memory']['percent'] ) : 0;
				$error_rate     = isset( $metrics['error_rate'] ) ? floatval( $metrics['error_rate'] ) : 0;
				$avg_response   = isset( $metrics['avg_response'] ) ? floatval( $metrics['avg_response'] ) : 0;

				ob_start();
				?>
				<div class="wp-mcp-ai-health-banner status-<?php echo esc_attr( $status ); ?>">
					<div class="health-status">
						<span class="health-icon"><?php echo esc_html( $icon ); ?></span>
						<strong><?php esc_html_e( 'System Health:', 'mcp-ai-wpoos' ); ?></strong>
						<?php echo esc_html( $label ); ?>
					</div>
					<div class="health-metrics">
						<span class="metric">
							<?php
							/* translators: %s: memory usage percentage */
							printf( esc_html__( 'Memory: %s%%', 'mcp-ai-wpoos' ), esc_html( number_format( $memory_percent, 1 ) ) );
							?>
						</span>
						<span class="metric">
							<?php
							/* translators: %s: error rate percentage */
							printf( esc_html__( 'Errors: %s%%', 'mcp-ai-wpoos' ), esc_html( number_format( $error_rate, 1 ) ) );
							?>
						</span>
						<span class="metric">
							<?php
							/* translators: %s: average response time in seconds */
							printf( esc_html__( 'Avg Response: %ss', 'mcp-ai-wpoos' ), esc_html( number_format( $avg_response, 1 ) ) );
							?>
						</span>
					</div>
				</div>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				// Log error if logger is available.
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_warning' ) ) {
					try {
						WP_MCP_AI_Logger::log_warning(
							'Health status banner rendering failed: ' . $e->getMessage(),
							array(
								'component' => 'orchestration_renderer',
								'method'    => 'render_health_status',
								'exception' => $e->getMessage(),
							)
						);
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Empty catch block intentional; exception is non-critical in this rendering context and silently ignored by design.
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return a safe fallback banner - don't break the UI.
				ob_start();
				?>
				<div class="wp-mcp-ai-health-banner status-unknown">
					<div class="health-status">
						<span class="health-icon">○</span>
						<strong><?php esc_html_e( 'System Health:', 'mcp-ai-wpoos' ); ?></strong>
						<?php esc_html_e( 'Unavailable', 'mcp-ai-wpoos' ); ?>
					</div>
					<p class="description">
						<?php esc_html_e( 'Health monitoring temporarily unavailable. Your plugin is still functioning normally.', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>
				<?php
				return ob_get_clean();
			}
		}

		/**
		 * Render memory usage progress bar.
		 *
		 * @return string HTML output or fallback on error.
		 * @throws Exception If health service is not available.
		 */
		public static function render_memory_progress() {
			try {
				if ( ! class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
					throw new Exception( 'Health service not available' );
				}

				$health         = WP_MCP_AI_Orchestration_Health_Service::get_health_status();
				$metrics        = isset( $health['metrics'] ) && is_array( $health['metrics'] ) ? $health['metrics'] : array();
				$memory_percent = isset( $metrics['memory']['percent'] ) ? floatval( $metrics['memory']['percent'] ) : 0;

				// Determine color based on thresholds.
				$memory_warning_threshold  = WP_MCP_AI_Settings_Registry::get_setting( 'memory_warning_threshold', 75 );
				$memory_critical_threshold = WP_MCP_AI_Settings_Registry::get_setting( 'memory_critical_threshold', 90 );

				if ( $memory_percent >= $memory_critical_threshold ) {
					$color_class = 'critical';
				} elseif ( $memory_percent >= $memory_warning_threshold ) {
					$color_class = 'warning';
				} else {
					$color_class = 'safe';
				}

				ob_start();
				?>
				<div class="wp-mcp-ai-memory-progress">
					<div class="progress-label">
						<?php esc_html_e( 'Memory Usage', 'mcp-ai-wpoos' ); ?>
					</div>
					<div class="progress-bar-wrapper">
						<div class="progress-bar" role="progressbar"
							aria-valuenow="<?php echo esc_attr( round( $memory_percent ) ); ?>"
							aria-valuemin="0"
							aria-valuemax="100">
							<div class="progress-fill <?php echo esc_attr( $color_class ); ?>"
								style="width: <?php echo esc_attr( min( 100, $memory_percent ) ) . '%'; ?>"></div>
						</div>
						<span class="progress-value">
							<?php echo esc_html( number_format( $memory_percent, 1 ) ); ?>%
						</span>
					</div>
				</div>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				// Log error if logger is available.
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_warning' ) ) {
					try {
						WP_MCP_AI_Logger::log_warning(
							'Memory progress rendering failed: ' . $e->getMessage(),
							array(
								'component' => 'orchestration_renderer',
								'method'    => 'render_memory_progress',
								'exception' => $e->getMessage(),
							)
						);
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Empty catch block intentional; exception is non-critical in this rendering context and silently ignored by design.
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return simple fallback.
				return sprintf(
					'<p class="description">%s</p>',
					esc_html__( 'Memory usage monitor temporarily unavailable.', 'mcp-ai-wpoos' )
				);
			}
		}

		/**
		 * Render predictive insights panel.
		 *
		 * @return string HTML output or fallback on error.
		 * @throws Exception If health service is not available.
		 */
		public static function render_predictive_insights() {
			try {
				if ( ! class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
					throw new Exception( 'Health service not available' );
				}

				$insights = WP_MCP_AI_Orchestration_Health_Service::get_predictive_insights();

				if ( ! is_array( $insights ) ) {
					$insights = array();
				}

				ob_start();
				?>
				<div class="wp-mcp-ai-predictive-insights">
					<h4>
						<span class="dashicons dashicons-lightbulb"></span>
						<?php esc_html_e( 'Predictive Insights', 'mcp-ai-wpoos' ); ?>
					</h4>
					<?php if ( ! empty( $insights ) ) : ?>
						<p><?php esc_html_e( 'Based on current trends:', 'mcp-ai-wpoos' ); ?></p>
						<ul class="insights-list">
							<?php foreach ( $insights as $insight ) : ?>
								<?php
								if ( ! is_array( $insight ) || ! isset( $insight['message'] ) ) {
									continue;
								}
								?>
								<li>
									<?php echo esc_html( $insight['message'] ); ?>
									<?php if ( isset( $insight['confidence'] ) ) : ?>
										<span class="confidence">
											(<?php echo esc_html( absint( $insight['confidence'] ) ); ?>%
											<?php esc_html_e( 'confidence', 'mcp-ai-wpoos' ); ?>)
										</span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="no-insights">
							<?php esc_html_e( 'Collecting data for predictions. Check back after 24 hours of activity.', 'mcp-ai-wpoos' ); ?>
						</p>
					<?php endif; ?>
				</div>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				// Log error if logger is available.
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_debug' ) ) {
					try {
						WP_MCP_AI_Logger::log_debug(
							'Predictive insights rendering failed: ' . $e->getMessage(),
							array(
								'component' => 'orchestration_renderer',
								'method'    => 'render_predictive_insights',
								'exception' => $e->getMessage(),
							)
						);
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Empty catch block intentional; exception is non-critical in this rendering context and silently ignored by design.
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return simple message - this is optional functionality.
				return sprintf(
					'<div class="wp-mcp-ai-predictive-insights"><p class="description">%s</p></div>',
					esc_html__( 'Predictive insights will appear after 24 hours of activity.', 'mcp-ai-wpoos' )
				);
			}
		}

		/**
		 * Render token budget explanation panel.
		 *
		 * Explains how the context window budget is allocated across different components
		 * of an AI request/response cycle. This helps users understand that the max tokens
		 * limit applies to the total context, not just the AI's response.
		 *
		 * @param int $max_tokens Current max tokens limit from resource manager.
		 * @return string HTML output or fallback on error.
		 * @throws Exception If max tokens value is invalid.
		 */
		public static function render_token_budget_explanation( $max_tokens ) {
			try {
				$max_tokens = absint( $max_tokens );

				if ( $max_tokens <= 0 ) {
					throw new Exception( 'Invalid max tokens value' );
				}

				ob_start();
				?>
				<div class="wp-mcp-ai-token-budget-explanation">
					<h4>
						<span class="dashicons dashicons-lightbulb"></span>
						<?php esc_html_e( 'Understanding Your Token Budget', 'mcp-ai-wpoos' ); ?>
					</h4>
					<div class="wp-mcp-ai-budget-breakdown">
						<p class="description">
							<?php
							printf(
								/* translators: %s: formatted max tokens number */
								esc_html__( 'The %s token Context Window limit represents the total budget for each complete AI interaction. This budget includes:', 'mcp-ai-wpoos' ),
								'<strong>' . esc_html( number_format( $max_tokens ) ) . '</strong>'
							);
							?>
						</p>
						<ul class="wp-mcp-ai-budget-components">
							<li>
								<span class="dashicons dashicons-admin-settings"></span>
								<strong><?php esc_html_e( 'System Prompt:', 'mcp-ai-wpoos' ); ?></strong>
								<?php esc_html_e( 'Initial instructions given to the AI assistant', 'mcp-ai-wpoos' ); ?>
							</li>
							<li>
								<span class="dashicons dashicons-format-chat"></span>
								<strong><?php esc_html_e( 'Conversation History:', 'mcp-ai-wpoos' ); ?></strong>
								<?php esc_html_e( 'Previous messages in the current chat session', 'mcp-ai-wpoos' ); ?>
							</li>
							<li>
								<span class="dashicons dashicons-admin-users"></span>
								<strong><?php esc_html_e( 'User Input:', 'mcp-ai-wpoos' ); ?></strong>
								<?php esc_html_e( 'Your most recent message or question', 'mcp-ai-wpoos' ); ?>
							</li>
							<li>
								<span class="dashicons dashicons-database"></span>
								<strong><?php esc_html_e( 'Tool/API Data:', 'mcp-ai-wpoos' ); ?></strong>
								<?php esc_html_e( 'Information fetched from WordPress or other sources', 'mcp-ai-wpoos' ); ?>
							</li>
							<li>
								<span class="dashicons dashicons-editor-alignleft"></span>
								<strong><?php esc_html_e( 'AI Output:', 'mcp-ai-wpoos' ); ?></strong>
								<?php esc_html_e( 'The actual response generated by the AI', 'mcp-ai-wpoos' ); ?>
							</li>
						</ul>
						<div class="notice notice-info inline">
							<p>
								<strong><?php esc_html_e( 'Important:', 'mcp-ai-wpoos' ); ?></strong>
								<?php esc_html_e( 'If the input (history + user prompt + data) is very large, the space remaining for the AI\'s answer will be smaller. The orchestration layer manages this budget automatically to prevent errors.', 'mcp-ai-wpoos' ); ?>
							</p>
						</div>
						<p class="description">
							<span class="dashicons dashicons-info"></span>
							<?php
							$doc_path = WP_MCP_AI_PATH . 'docs/reference/technical/TOKEN-CONTEXT-WINDOW-EXPLAINED.md';
							if ( file_exists( $doc_path ) ) {
								printf(
									/* translators: 1: Documentation link opening tag, 2: closing link tag, 3: Token Manager link opening tag, 4: closing link tag */
									esc_html__( '%1$sLearn more about context windows%2$s or visit the %3$sToken Manager%4$s for detailed analytics.', 'mcp-ai-wpoos' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=orchestration#context-window-docs' ) ) . '">',
									'</a>',
									'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager' ) ) . '">',
									'</a>'
								);
							} else {
								printf(
									/* translators: 1: Token Manager link, 2: closing link tag */
									esc_html__( 'For more detailed token tracking and analytics, visit the %1$sToken Manager%2$s.', 'mcp-ai-wpoos' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager' ) ) . '">',
									'</a>'
								);
							}
							?>
						</p>
					</div>
				</div>

				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for token budget explanation section layout and styling on this admin page only
				?>
				<style>
					.wp-mcp-ai-token-budget-explanation {
						background: #f8f9fa;
						border: 1px solid #ddd;
						border-left: 4px solid #2271b1;
						padding: 20px;
						margin: 20px 0;
						border-radius: 4px;
					}
					.wp-mcp-ai-token-budget-explanation h4 {
						margin-top: 0;
						display: flex;
						align-items: center;
						gap: 8px;
						color: #2271b1;
					}
					.wp-mcp-ai-token-budget-explanation h4 .dashicons {
						color: #f0b849;
					}
					.wp-mcp-ai-budget-breakdown {
						margin-top: 15px;
					}
					.wp-mcp-ai-budget-components {
						list-style: none;
						padding: 0;
						margin: 15px 0;
					}
					.wp-mcp-ai-budget-components li {
						padding: 10px 15px;
						margin: 8px 0;
						background: #fff;
						border-left: 3px solid #2271b1;
						border-radius: 3px;
						display: flex;
						align-items: flex-start;
						gap: 8px;
					}
					.wp-mcp-ai-budget-components li .dashicons {
						flex-shrink: 0;
						margin-top: 2px;
						color: #2271b1;
					}
					.wp-mcp-ai-stats-card--context-window {
						position: relative;
					}
					.wp-mcp-ai-stats-card__subtitle {
						font-size: 12px;
						color: #666;
						margin-top: 4px;
						font-weight: normal;
					}
					.wp-mcp-ai-tooltip-trigger {
						cursor: help;
						font-size: 16px;
						color: #2271b1;
						vertical-align: middle;
					}
				</style>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				// Log error if logger is available.
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_warning' ) ) {
					try {
						WP_MCP_AI_Logger::log_warning(
							'Token budget explanation rendering failed: ' . $e->getMessage(),
							array(
								'component'  => 'orchestration_renderer',
								'method'     => 'render_token_budget_explanation',
								'max_tokens' => $max_tokens,
								'exception'  => $e->getMessage(),
							)
						);
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Empty catch block intentional; exception is non-critical in this rendering context and silently ignored by design.
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return simple fallback - don't break the UI.
				return sprintf(
					'<div class="notice notice-info inline"><p>%s</p></div>',
					esc_html__( 'Token budget information temporarily unavailable.', 'mcp-ai-wpoos' )
				);
			}
		}
	}
}
