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
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return simple fallback.
				return sprintf(
					'<p class="description error">%s</p>',
					esc_html__( 'Slider control temporarily unavailable.', 'wp-mcp-ai' )
				);
			}
		}

		/**
		 * Render configuration presets selector.
		 *
		 * @param array $presets Available presets configuration.
		 * @return string HTML output or fallback on error.
		 */
		public static function render_presets_selector( $presets ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			try {
				if ( ! is_array( $presets ) || empty( $presets ) ) {
					throw new Exception( 'Invalid presets array' );
				}

				$current_preset = WP_MCP_AI_Orchestration_Preset_Service::get_active_preset();

				// Get the current preset name for display.
				$current_preset_name = isset( $presets[ $current_preset ]['name'] ) ? $presets[ $current_preset ]['name'] : __( 'Unknown', 'wp-mcp-ai' );

				ob_start();
				?>
				<div class="wp-mcp-ai-presets-section">
					<h3><?php esc_html_e( 'Configuration Presets', 'wp-mcp-ai' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Choose a preset configuration or customize your own settings. Clicking a preset will apply its settings immediately.', 'wp-mcp-ai' ); ?>
					</p>
					<div class="wp-mcp-ai-current-preset-indicator">
						<span class="dashicons dashicons-admin-settings"></span>
						<strong><?php esc_html_e( 'Currently Active:', 'wp-mcp-ai' ); ?></strong>
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
										<span class="preset-badge default"><?php esc_html_e( 'DEFAULT', 'wp-mcp-ai' ); ?></span>
									<?php elseif ( $is_recommended ) : ?>
										<span class="preset-badge recommended"><?php esc_html_e( 'RECOMMENDED', 'wp-mcp-ai' ); ?></span>
									<?php endif; ?>
								</div>
								<p class="preset-description">
									<?php echo esc_html( isset( $preset_config['description'] ) ? $preset_config['description'] : '' ); ?>
								</p>
								<?php if ( $is_active ) : ?>
									<div class="preset-status"><?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></div>
								<?php else : ?>
									<button type="button" class="button button-secondary apply-preset" 
										data-preset="<?php echo esc_attr( $preset_id ); ?>"
										aria-label="
										<?php
										/* translators: %s: preset name */
										echo esc_attr( sprintf( __( 'Apply %s preset', 'wp-mcp-ai' ), $preset_config['name'] ) );
										?>
										">
										<?php esc_html_e( 'Apply', 'wp-mcp-ai' ); ?>
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
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return simple fallback.
				return sprintf(
					'<div class="notice notice-warning inline"><p>%s</p></div>',
					esc_html__( 'Configuration presets temporarily unavailable. Your settings are safe.', 'wp-mcp-ai' )
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
				$label   = isset( $health['label'] ) ? sanitize_text_field( $health['label'] ) : __( 'Unknown', 'wp-mcp-ai' );
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
						<strong><?php esc_html_e( 'System Health:', 'wp-mcp-ai' ); ?></strong>
						<?php echo esc_html( $label ); ?>
					</div>
					<div class="health-metrics">
						<span class="metric">
							<?php
							/* translators: %s: memory usage percentage */
							printf( esc_html__( 'Memory: %s%%', 'wp-mcp-ai' ), esc_html( number_format( $memory_percent, 1 ) ) );
							?>
						</span>
						<span class="metric">
							<?php
							/* translators: %s: error rate percentage */
							printf( esc_html__( 'Errors: %s%%', 'wp-mcp-ai' ), esc_html( number_format( $error_rate, 1 ) ) );
							?>
						</span>
						<span class="metric">
							<?php
							/* translators: %s: average response time in seconds */
							printf( esc_html__( 'Avg Response: %ss', 'wp-mcp-ai' ), esc_html( number_format( $avg_response, 1 ) ) );
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
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return a safe fallback banner - don't break the UI.
				ob_start();
				?>
				<div class="wp-mcp-ai-health-banner status-unknown">
					<div class="health-status">
						<span class="health-icon">○</span>
						<strong><?php esc_html_e( 'System Health:', 'wp-mcp-ai' ); ?></strong>
						<?php esc_html_e( 'Unavailable', 'wp-mcp-ai' ); ?>
					</div>
					<p class="description">
						<?php esc_html_e( 'Health monitoring temporarily unavailable. Your plugin is still functioning normally.', 'wp-mcp-ai' ); ?>
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
						<?php esc_html_e( 'Memory Usage', 'wp-mcp-ai' ); ?>
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
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return simple fallback.
				return sprintf(
					'<p class="description">%s</p>',
					esc_html__( 'Memory usage monitor temporarily unavailable.', 'wp-mcp-ai' )
				);
			}
		}

		/**
		 * Render predictive insights panel.
		 *
		 * @return string HTML output or fallback on error.
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
						<?php esc_html_e( 'Predictive Insights', 'wp-mcp-ai' ); ?>
					</h4>
					<?php if ( ! empty( $insights ) ) : ?>
						<p><?php esc_html_e( 'Based on current trends:', 'wp-mcp-ai' ); ?></p>
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
											<?php esc_html_e( 'confidence', 'wp-mcp-ai' ); ?>)
										</span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="no-insights">
							<?php esc_html_e( 'Collecting data for predictions. Check back after 24 hours of activity.', 'wp-mcp-ai' ); ?>
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
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return simple message - this is optional functionality.
				return sprintf(
					'<div class="wp-mcp-ai-predictive-insights"><p class="description">%s</p></div>',
					esc_html__( 'Predictive insights will appear after 24 hours of activity.', 'wp-mcp-ai' )
				);
			}
		}

		/**
		 * Render models and capabilities view.
		 *
		 * @return string HTML output or fallback on error.
		 */
		public static function render_models_view() {
			try {
				// Check if Model Rate Limits CCT class is available.
				if ( ! class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
					return '<div class="notice notice-warning inline"><p>' . esc_html__( 'Model rate limits system not available. Please ensure JetEngine is active.', 'wp-mcp-ai' ) . '</p></div>';
				}

				// Get the item handler.
				$handler = WP_MCP_AI_Model_Rate_Limits_CCT::get_item_handler();

				if ( ! $handler ) {
					return '<div class="notice notice-warning inline"><p>' . esc_html__( 'Model rate limits database not accessible. Please ensure JetEngine Custom Content Types module is active.', 'wp-mcp-ai' ) . '</p></div>';
				}

				$factory = $handler->get_factory();

				if ( ! $factory || empty( $factory->db ) ) {
					return '<div class="notice notice-warning inline"><p>' . esc_html__( 'Unable to access model database.', 'wp-mcp-ai' ) . '</p></div>';
				}

				// Query all models.
				$models = $factory->db->query( array() );

				if ( empty( $models ) || ! is_array( $models ) ) {
					return '<div class="notice notice-info inline"><p>' . esc_html__( 'No models configured yet. Models will be auto-populated on first use.', 'wp-mcp-ai' ) . '</p></div>';
				}

				// Group models by provider.
				$models_by_provider = array();
				foreach ( $models as $model ) {
					if ( ! isset( $model['provider'] ) ) {
						continue;
					}
					$provider = sanitize_text_field( $model['provider'] );
					if ( ! isset( $models_by_provider[ $provider ] ) ) {
						$models_by_provider[ $provider ] = array();
					}
					$models_by_provider[ $provider ][] = $model;
				}

				// Get JetEngine admin URL for the CCT.
				$cct_url = admin_url( 'admin.php?page=jet-cct-ai_model_rate_limits' );

				ob_start();
				?>
				<div class="wp-mcp-ai-models-view">
					<div class="models-header">
						<h3><?php esc_html_e( 'AI Models and Capabilities', 'wp-mcp-ai' ); ?></h3>
						<p class="description">
							<?php esc_html_e( 'View available AI models and their capabilities. This data is managed in the JetEngine Custom Content Type.', 'wp-mcp-ai' ); ?>
						</p>
						<p>
							<a href="<?php echo esc_url( $cct_url ); ?>" class="button button-secondary">
								<span class="dashicons dashicons-admin-generic"></span>
								<?php esc_html_e( 'Manage Models in CCT', 'wp-mcp-ai' ); ?>
							</a>
						</p>
					</div>

					<div class="models-stats">
						<div class="stat-card">
							<span class="dashicons dashicons-admin-site-alt3"></span>
							<div class="stat-content">
								<div class="stat-value"><?php echo absint( count( $models ) ); ?></div>
								<div class="stat-label"><?php esc_html_e( 'Total Models', 'wp-mcp-ai' ); ?></div>
							</div>
						</div>
						<div class="stat-card">
							<span class="dashicons dashicons-networking"></span>
							<div class="stat-content">
								<div class="stat-value"><?php echo absint( count( $models_by_provider ) ); ?></div>
								<div class="stat-label"><?php esc_html_e( 'Providers', 'wp-mcp-ai' ); ?></div>
							</div>
						</div>
					</div>

					<?php foreach ( $models_by_provider as $provider => $provider_models ) : ?>
						<?php
						$provider_label = self::get_provider_label( $provider );
						?>
						<div class="provider-section">
							<h4 class="provider-title">
								<span class="dashicons dashicons-admin-site-alt3"></span>
								<?php echo esc_html( $provider_label ); ?>
								<span class="provider-count">(<?php echo absint( count( $provider_models ) ); ?> <?php esc_html_e( 'models', 'wp-mcp-ai' ); ?>)</span>
							</h4>

							<div class="models-grid">
								<?php foreach ( $provider_models as $model ) : ?>
									<?php echo self::render_model_card( $model ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_model_card method. ?>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				// Log error if logger is available.
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_error' ) ) {
					try {
						WP_MCP_AI_Logger::log_error(
							'Models view rendering failed: ' . $e->getMessage(),
							array(
								'component' => 'orchestration_renderer',
								'method'    => 'render_models_view',
								'exception' => $e->getMessage(),
							)
						);
					} catch ( Exception $log_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Ignore logging errors to prevent cascading failures.
					}
				}

				// Return safe fallback.
				return '<div class="notice notice-error inline"><p>' . esc_html__( 'Failed to load models view. Please try again later.', 'wp-mcp-ai' ) . '</p></div>';
			}
		}

		/**
		 * Render a single model card.
		 *
		 * @param array $model Model data.
		 * @return string HTML output.
		 */
		protected static function render_model_card( $model ) {
			$model_name                = isset( $model['model_name'] ) ? sanitize_text_field( $model['model_name'] ) : '';
			$tpm_limit                 = isset( $model['tpm_limit'] ) ? absint( $model['tpm_limit'] ) : 0;
			$rpm_limit                 = isset( $model['rpm_limit'] ) ? absint( $model['rpm_limit'] ) : 0;
			$context_window            = isset( $model['context_window'] ) ? absint( $model['context_window'] ) : 0;
			$max_output_tokens         = isset( $model['max_output_tokens'] ) ? absint( $model['max_output_tokens'] ) : 0;
			$tier                      = isset( $model['tier'] ) ? sanitize_text_field( $model['tier'] ) : '';
			$supports_streaming        = isset( $model['supports_streaming'] ) && $model['supports_streaming'];
			$supports_function_calling = isset( $model['supports_function_calling'] ) && $model['supports_function_calling'];
			$supports_vision           = isset( $model['supports_vision'] ) && $model['supports_vision'];
			$cost_input                = isset( $model['cost_per_1k_input_tokens'] ) ? floatval( $model['cost_per_1k_input_tokens'] ) : 0;
			$cost_output               = isset( $model['cost_per_1k_output_tokens'] ) ? floatval( $model['cost_per_1k_output_tokens'] ) : 0;
			$notes                     = isset( $model['notes'] ) ? sanitize_textarea_field( $model['notes'] ) : '';

			ob_start();
			?>
			<div class="model-card">
				<div class="model-header">
					<h5 class="model-name"><?php echo esc_html( $model_name ); ?></h5>
					<?php if ( $tier ) : ?>
						<span class="model-tier tier-<?php echo esc_attr( $tier ); ?>">
							<?php echo esc_html( ucfirst( str_replace( '-', ' ', $tier ) ) ); ?>
						</span>
					<?php endif; ?>
				</div>

				<div class="model-capabilities">
					<h6><?php esc_html_e( 'Capabilities', 'wp-mcp-ai' ); ?></h6>
					<div class="capabilities-list">
						<?php echo self::render_capability( 'Streaming', $supports_streaming ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_capability method. ?>
						<?php echo self::render_capability( 'Function Calling', $supports_function_calling ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_capability method. ?>
						<?php echo self::render_capability( 'Vision', $supports_vision ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_capability method. ?>
					</div>
				</div>

				<div class="model-limits">
					<h6><?php esc_html_e( 'Token Limits', 'wp-mcp-ai' ); ?></h6>
					<div class="limits-grid">
						<?php if ( $tpm_limit > 0 ) : ?>
							<div class="limit-item">
								<span class="limit-label"><?php esc_html_e( 'TPM:', 'wp-mcp-ai' ); ?></span>
								<span class="limit-value"><?php echo esc_html( number_format( $tpm_limit ) ); ?></span>
							</div>
						<?php endif; ?>
						<?php if ( $rpm_limit > 0 ) : ?>
							<div class="limit-item">
								<span class="limit-label"><?php esc_html_e( 'RPM:', 'wp-mcp-ai' ); ?></span>
								<span class="limit-value"><?php echo esc_html( number_format( $rpm_limit ) ); ?></span>
							</div>
						<?php endif; ?>
						<div class="limit-item">
							<span class="limit-label"><?php esc_html_e( 'Context:', 'wp-mcp-ai' ); ?></span>
							<span class="limit-value"><?php echo esc_html( number_format( $context_window ) ); ?></span>
						</div>
						<?php if ( $max_output_tokens > 0 ) : ?>
							<div class="limit-item">
								<span class="limit-label"><?php esc_html_e( 'Max Output:', 'wp-mcp-ai' ); ?></span>
								<span class="limit-value"><?php echo esc_html( number_format( $max_output_tokens ) ); ?></span>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( $cost_input > 0 || $cost_output > 0 ) : ?>
					<div class="model-costs">
						<h6><?php esc_html_e( 'Pricing (per 1K tokens)', 'wp-mcp-ai' ); ?></h6>
						<div class="costs-grid">
							<div class="cost-item">
								<span class="cost-label"><?php esc_html_e( 'Input:', 'wp-mcp-ai' ); ?></span>
								<span class="cost-value">$<?php echo esc_html( number_format( $cost_input, 4 ) ); ?></span>
							</div>
							<div class="cost-item">
								<span class="cost-label"><?php esc_html_e( 'Output:', 'wp-mcp-ai' ); ?></span>
								<span class="cost-value">$<?php echo esc_html( number_format( $cost_output, 4 ) ); ?></span>
							</div>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $notes ) : ?>
					<div class="model-notes">
						<p><?php echo esc_html( $notes ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render a capability indicator.
		 *
		 * @param string $label     Capability label.
		 * @param bool   $supported Whether the capability is supported.
		 * @return string HTML output.
		 */
		protected static function render_capability( $label, $supported ) {
			$class = $supported ? 'enabled' : 'disabled';
			$icon  = $supported ? 'yes' : 'no';

			return sprintf(
				'<span class="capability %s"><span class="dashicons dashicons-%s"></span>%s</span>',
				esc_attr( $class ),
				esc_attr( $icon ),
				esc_html( $label )
			);
		}

		/**
		 * Get human-readable provider label.
		 *
		 * @param string $provider Provider key.
		 * @return string Provider label.
		 */
		protected static function get_provider_label( $provider ) {
			$labels = array(
				'openai'    => 'OpenAI',
				'anthropic' => 'Anthropic',
				'google'    => 'Google',
				'azure'     => 'Azure OpenAI',
				'ollama'    => 'Ollama',
				'lm_studio' => 'LM Studio',
			);

			return isset( $labels[ $provider ] ) ? $labels[ $provider ] : ucfirst( $provider );
		}
	}
}
