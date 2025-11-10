<?php
/**
 * Orchestration Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Orchestration' ) ) {
	/**
	 * Orchestration settings section - manages AI orchestration layer features.
	 */
	class WP_MCP_AI_Section_Orchestration extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'orchestration';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Orchestration Layer', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'orchestration';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure the dynamic AI orchestration layer that manages resource budgets, capability gating, and distributed AI operations.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'orchestration_intro'             => array(
					'type'    => 'html',
					'content' => $this->get_intro_content(),
				),
				'health_status'                   => array(
					'type'    => 'html',
					'content' => $this->get_health_status_content(),
				),
				'configuration_presets'           => array(
					'type'    => 'html',
					'content' => $this->get_presets_content(),
				),
				'enable_budget_management'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Dynamic Budget Management', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable dynamic budget management', 'wp-mcp-ai' ),
					'description'    => __( 'Automatically allocate and adjust token budgets based on system resources and workload tier.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'enable_predictive_optimization'  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Predictive Optimization', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable predictive optimization', 'wp-mcp-ai' ),
					'description'    => __( 'Use historical usage patterns to forecast and prevent resource exhaustion.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'enable_capability_gating'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Capability-Based Tool Gating', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable capability-based tool gating', 'wp-mcp-ai' ),
					'description'    => __( 'Enforce WordPress capability checks for tool access based on user roles.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'enable_cron_orchestration'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Cron-Based Task Orchestration', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable cron-based task orchestration', 'wp-mcp-ai' ),
					'description'    => __( 'Allow AI agents to create and manage scheduled background tasks with inherited budget constraints.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'slider_section_health'           => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Health Monitoring Thresholds', 'wp-mcp-ai' ) . '</h3>',
				),
				'memory_warning_threshold'        => array(
					'type'        => 'slider',
					'label'       => __( 'Memory Warning Threshold', 'wp-mcp-ai' ),
					'description' => __( 'Trigger warnings when memory usage exceeds this percentage.', 'wp-mcp-ai' ),
					'min'         => 50,
					'max'         => 95,
					'step'        => 5,
					'default'     => 75,
					'suffix'      => '%',
				),
				'memory_critical_threshold'       => array(
					'type'        => 'slider',
					'label'       => __( 'Memory Critical Threshold', 'wp-mcp-ai' ),
					'description' => __( 'Trigger critical alerts when memory usage exceeds this percentage.', 'wp-mcp-ai' ),
					'min'         => 75,
					'max'         => 99,
					'step'        => 1,
					'default'     => 90,
					'suffix'      => '%',
				),
				'error_rate_warning_threshold'    => array(
					'type'        => 'slider',
					'label'       => __( 'Error Rate Warning Threshold', 'wp-mcp-ai' ),
					'description' => __( 'Trigger warnings when error rate exceeds this percentage.', 'wp-mcp-ai' ),
					'min'         => 5,
					'max'         => 25,
					'step'        => 1,
					'default'     => 10,
					'suffix'      => '%',
				),
				'error_rate_critical_threshold'   => array(
					'type'        => 'slider',
					'label'       => __( 'Error Rate Critical Threshold', 'wp-mcp-ai' ),
					'description' => __( 'Trigger critical alerts when error rate exceeds this percentage.', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 50,
					'step'        => 5,
					'default'     => 20,
					'suffix'      => '%',
				),
				'slider_section_budget'           => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Adaptive Budget Allocation', 'wp-mcp-ai' ) . '</h3>',
				),
				'high_priority_budget'            => array(
					'type'        => 'slider',
					'label'       => __( 'High Priority Budget', 'wp-mcp-ai' ),
					'description' => __( 'Percentage of available budget allocated to high-priority tasks.', 'wp-mcp-ai' ),
					'min'         => 50,
					'max'         => 100,
					'step'        => 5,
					'default'     => 100,
					'suffix'      => '%',
				),
				'medium_priority_budget'          => array(
					'type'        => 'slider',
					'label'       => __( 'Medium Priority Budget', 'wp-mcp-ai' ),
					'description' => __( 'Percentage of available budget allocated to medium-priority tasks.', 'wp-mcp-ai' ),
					'min'         => 30,
					'max'         => 100,
					'step'        => 5,
					'default'     => 80,
					'suffix'      => '%',
				),
				'low_priority_budget'             => array(
					'type'        => 'slider',
					'label'       => __( 'Low Priority Budget', 'wp-mcp-ai' ),
					'description' => __( 'Percentage of available budget allocated to low-priority tasks.', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 80,
					'step'        => 5,
					'default'     => 50,
					'suffix'      => '%',
				),
				'critical_health_reduction'       => array(
					'type'        => 'slider',
					'label'       => __( 'Critical Health Budget Reduction', 'wp-mcp-ai' ),
					'description' => __( 'Reduce budgets to this percentage when system health is critical.', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 80,
					'step'        => 5,
					'default'     => 50,
					'suffix'      => '%',
				),
				'warning_health_reduction'        => array(
					'type'        => 'slider',
					'label'       => __( 'Warning Health Budget Reduction', 'wp-mcp-ai' ),
					'description' => __( 'Reduce budgets to this percentage when system health shows warnings.', 'wp-mcp-ai' ),
					'min'         => 50,
					'max'         => 100,
					'step'        => 5,
					'default'     => 75,
					'suffix'      => '%',
				),
				'slider_section_tokens'           => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Token Limits by Workload Tier', 'wp-mcp-ai' ) . '</h3>',
				),
				'low_tier_max_tokens'             => array(
					'type'        => 'slider',
					'label'       => __( 'Low Tier Max Tokens', 'wp-mcp-ai' ),
					'description' => __( 'Maximum tokens for low-tier workloads (< 128MB memory).', 'wp-mcp-ai' ),
					'min'         => 500,
					'max'         => 5000,
					'step'        => 100,
					'default'     => 1000,
					'suffix'      => '',
				),
				'medium_tier_max_tokens'          => array(
					'type'        => 'slider',
					'label'       => __( 'Medium Tier Max Tokens', 'wp-mcp-ai' ),
					'description' => __( 'Maximum tokens for medium-tier workloads (128-512MB memory).', 'wp-mcp-ai' ),
					'min'         => 2000,
					'max'         => 10000,
					'step'        => 500,
					'default'     => 4000,
					'suffix'      => '',
				),
				'high_tier_max_tokens'            => array(
					'type'        => 'slider',
					'label'       => __( 'High Tier Max Tokens', 'wp-mcp-ai' ),
					'description' => __( 'Maximum tokens for high-tier workloads (> 512MB memory).', 'wp-mcp-ai' ),
					'min'         => 8000,
					'max'         => 32000,
					'step'        => 1000,
					'default'     => 16000,
					'suffix'      => '',
				),
				'slider_section_predictive'       => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Predictive Analytics', 'wp-mcp-ai' ) . '</h3>',
				),
				'prediction_confidence_threshold' => array(
					'type'        => 'slider',
					'label'       => __( 'Prediction Confidence Threshold', 'wp-mcp-ai' ),
					'description' => __( 'Minimum confidence level required to act on predictions.', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 90,
					'step'        => 5,
					'default'     => 30,
					'suffix'      => '%',
				),
				'prediction_safety_buffer'        => array(
					'type'        => 'slider',
					'label'       => __( 'Prediction Safety Buffer', 'wp-mcp-ai' ),
					'description' => __( 'Extra safety margin when making predictive adjustments.', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 50,
					'step'        => 5,
					'default'     => 20,
					'suffix'      => '%',
				),
				'orchestration_stats'             => array(
					'type'    => 'html',
					'content' => $this->get_stats_content(),
				),
			);
		}

		/**
		 * Get intro content HTML.
		 *
		 * @return string
		 */
		private function get_intro_content() {
			$doc_path   = WP_MCP_AI_PATH . 'docs/ORCHESTRATION-LAYER-ARCHITECTURE.md';
			$doc_exists = file_exists( $doc_path );

			$content  = '<div class="wp-mcp-ai-orchestration-intro" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1.5rem; margin: 1rem 0;">';
			$content .= '<h3 style="margin-top: 0;">' . esc_html__( 'About the Orchestration Layer', 'wp-mcp-ai' ) . '</h3>';
			$content .= '<p>' . esc_html__( 'The WP oOS Dynamic AI Orchestration Layer extends standard SSE and MCP implementations with sophisticated resource management, security enforcement, and predictive optimization. This overcomes PHP\'s architectural limitations to provide Node.js-level orchestration capabilities within WordPress.', 'wp-mcp-ai' ) . '</p>';

			$content .= '<h4>' . esc_html__( 'Key Features:', 'wp-mcp-ai' ) . '</h4>';
			$content .= '<ul>';
			$content .= '<li><strong>' . esc_html__( 'Real-Time Budget Enforcement', 'wp-mcp-ai' ) . ':</strong> ' . esc_html__( 'Monitors and adjusts token/memory budgets dynamically', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li><strong>' . esc_html__( 'Capability-Based Tool Gating', 'wp-mcp-ai' ) . ':</strong> ' . esc_html__( 'Enforces WordPress role permissions for tool access', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li><strong>' . esc_html__( 'Predictive Optimization', 'wp-mcp-ai' ) . ':</strong> ' . esc_html__( 'Prevents resource exhaustion before it occurs', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li><strong>' . esc_html__( 'Distributed Orchestration', 'wp-mcp-ai' ) . ':</strong> ' . esc_html__( 'Multi-provider coordination with unified policies', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li><strong>' . esc_html__( 'Cron-Based Task Management', 'wp-mcp-ai' ) . ':</strong> ' . esc_html__( 'Scheduled operations with budget inheritance', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li><strong>' . esc_html__( 'Auditability & Compliance', 'wp-mcp-ai' ) . ':</strong> ' . esc_html__( 'Complete logging and deterministic behavior', 'wp-mcp-ai' ) . '</li>';
			$content .= '</ul>';

			if ( $doc_exists ) {
				$content .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=orchestration#architecture-doc' ) ) . '" class="button button-secondary">' . esc_html__( 'View Full Architecture Documentation', 'wp-mcp-ai' ) . '</a></p>';
			}

			$content .= '</div>';

			return $content;
		}

		/**
		 * Get health status content HTML.
		 *
		 * @return string
		 */
		private function get_health_status_content() {
			try {
				if ( ! class_exists( 'WP_MCP_AI_Orchestration_Renderer' ) ) {
					return '<div class="notice notice-info inline"><p>' . esc_html__( 'Health status monitoring is not available.', 'wp-mcp-ai' ) . '</p></div>';
				}

				return WP_MCP_AI_Orchestration_Renderer::render_health_status();
			} catch ( Exception $e ) {
				// Log error if logger is available.
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_warning' ) ) {
					try {
						WP_MCP_AI_Logger::log_warning(
							'Health status rendering failed: ' . $e->getMessage(),
							array(
								'component' => 'orchestration_section',
								'method'    => 'get_health_status_content',
								'exception' => $e->getMessage(),
							)
						);
					} catch ( Exception $log_error ) {
						// Fallback to PHP error log if WP logger fails.
						error_log( 'WP_MCP_AI: Failed to log health status error - ' . $log_error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				} else {
					// Logger not available, use PHP error log directly.
					error_log( 'WP_MCP_AI: Health status rendering failed - ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}

				// Return safe fallback.
				return '<div class="notice notice-warning inline"><p>' . esc_html__( 'Health status temporarily unavailable.', 'wp-mcp-ai' ) . '</p></div>';
			}
		}

		/**
		 * Get presets content HTML.
		 *
		 * @return string
		 */
		private function get_presets_content() {
			try {
				if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
					return '<div class="notice notice-info inline"><p>' . esc_html__( 'Preset service not available.', 'wp-mcp-ai' ) . '</p></div>';
				}

				if ( ! class_exists( 'WP_MCP_AI_Orchestration_Renderer' ) ) {
					return '<div class="notice notice-info inline"><p>' . esc_html__( 'Renderer not available.', 'wp-mcp-ai' ) . '</p></div>';
				}

				$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
				return WP_MCP_AI_Orchestration_Renderer::render_presets_selector( $presets );
			} catch ( Exception $e ) {
				// Log error if logger is available.
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_warning' ) ) {
					try {
						WP_MCP_AI_Logger::log_warning(
							'Presets rendering failed: ' . $e->getMessage(),
							array(
								'component' => 'orchestration_section',
								'method'    => 'get_presets_content',
								'exception' => $e->getMessage(),
							)
						);
					} catch ( Exception $log_error ) {
						// Fallback to PHP error log if WP logger fails.
						error_log( 'WP_MCP_AI: Failed to log presets error - ' . $log_error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				} else {
					// Logger not available, use PHP error log directly.
					error_log( 'WP_MCP_AI: Presets rendering failed - ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}

				// Return safe fallback.
				return '<div class="notice notice-warning inline"><p>' . esc_html__( 'Configuration presets temporarily unavailable.', 'wp-mcp-ai' ) . '</p></div>';
			}
		}

		/**
		 * Get statistics content HTML.
		 *
		 * @return string
		 */
		private function get_stats_content() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			try {
				// Check if Resource Manager is available.
				if ( ! class_exists( 'WP_MCP_AI_Resource_Manager' ) ) {
					throw new Exception( 'Resource Manager not available' );
				}

				// Get resource manager instance.
				$resource_manager = WP_MCP_AI_Resource_Manager::instance();

				$content  = '<div class="wp-mcp-ai-orchestration-stats" style="margin: 1.5rem 0;">';
				$content .= '<h3>' . esc_html__( 'Current Orchestration Status', 'wp-mcp-ai' ) . '</h3>';

				$content .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 1rem 0;">';

				// Memory tier.
				$memory_limit = $resource_manager->get_memory_limit();
				$memory_tier  = 'Unknown';
				if ( $memory_limit < 128 * 1024 * 1024 ) {
					$memory_tier = 'Low';
				} elseif ( $memory_limit < 512 * 1024 * 1024 ) {
					$memory_tier = 'Medium';
				} else {
					$memory_tier = 'High';
				}

				$content .= '<div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; border-radius: 4px;">';
				$content .= '<div style="font-size: 0.875rem; color: #646970; margin-bottom: 0.5rem;">' . esc_html__( 'Workload Tier', 'wp-mcp-ai' ) . '</div>';
				$content .= '<div style="font-size: 1.5rem; font-weight: 600;">' . esc_html( $memory_tier ) . '</div>';
				$content .= '</div>';

				// Max tokens.
				$max_tokens = $resource_manager->get_max_tokens();
				$content   .= '<div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; border-radius: 4px;">';
				$content   .= '<div style="font-size: 0.875rem; color: #646970; margin-bottom: 0.5rem;">' . esc_html__( 'Max Tokens', 'wp-mcp-ai' ) . '</div>';
				$content   .= '<div style="font-size: 1.5rem; font-weight: 600;">' . esc_html( number_format( $max_tokens ) ) . '</div>';
				$content   .= '</div>';

				// Request timeout.
				$timeout  = $resource_manager->get_request_timeout();
				$content .= '<div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; border-radius: 4px;">';
				$content .= '<div style="font-size: 0.875rem; color: #646970; margin-bottom: 0.5rem;">' . esc_html__( 'Request Timeout', 'wp-mcp-ai' ) . '</div>';
				$content .= '<div style="font-size: 1.5rem; font-weight: 600;">' . esc_html( $timeout ) . 's</div>';
				$content .= '</div>';

				// Active cron jobs - use cached count for performance.
				if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
					// Use transient cache to avoid expensive lookups on every page load.
					$active_jobs = get_transient( 'wp_mcp_ai_active_cron_count' );

					if ( false === $active_jobs ) {
						$cron_jobs   = WP_MCP_AI_Cron_Manager::get_jobs();
						$active_jobs = 0;

						if ( is_array( $cron_jobs ) && ! empty( $cron_jobs ) ) {
							// Limit to first 50 jobs to prevent performance issues.
							$cron_jobs_slice = array_slice( $cron_jobs, 0, 50 );

							foreach ( $cron_jobs_slice as $job ) {
								if ( ! is_array( $job ) || ! isset( $job['hook'], $job['args'] ) ) {
									continue;
								}
								$event = wp_get_scheduled_event( $job['hook'], $job['args'] );
								if ( $event ) {
									++$active_jobs;
								}
							}

							// Add indicator if there are more jobs.
							if ( count( $cron_jobs ) > 50 ) {
								$active_jobs .= '+';
							}
						}

						// Cache for 5 minutes.
						set_transient( 'wp_mcp_ai_active_cron_count', $active_jobs, 5 * MINUTE_IN_SECONDS );
					}
				} else {
					$active_jobs = 0;
				}

				$content .= '<div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; border-radius: 4px;">';
				$content .= '<div style="font-size: 0.875rem; color: #646970; margin-bottom: 0.5rem;">' . esc_html__( 'Active Cron Jobs', 'wp-mcp-ai' ) . '</div>';
				$content .= '<div style="font-size: 1.5rem; font-weight: 600;">' . esc_html( $active_jobs ) . '</div>';
				$content .= '</div>';

				$content .= '</div>';

				// Quick actions.
				$content .= '<div style="margin-top: 1.5rem;">';
				$content .= '<h4>' . esc_html__( 'Quick Actions', 'wp-mcp-ai' ) . '</h4>';
				$content .= '<p>';
				$content .= '<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-cron-manager' ) ) . '" class="button button-secondary">' . esc_html__( 'Manage Cron Jobs', 'wp-mcp-ai' ) . '</a> ';
				$content .= '<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager' ) ) . '" class="button button-secondary">' . esc_html__( 'View Token Manager', 'wp-mcp-ai' ) . '</a> ';
				$content .= '<a href="' . esc_url( admin_url( 'tools.php?page=wp-mcp-ai-diagnostic' ) ) . '" class="button button-secondary">' . esc_html__( 'Run Diagnostics', 'wp-mcp-ai' ) . '</a>';
				$content .= '</p>';
				$content .= '</div>';

				$content .= '</div>';

				return $content;

			} catch ( Exception $e ) {
				// Log the error for debugging.
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_error' ) ) {
					try {
						WP_MCP_AI_Logger::log_error(
							'Orchestration stats rendering failed: ' . $e->getMessage(),
							array(
								'component' => 'orchestration_section',
								'method'    => 'get_stats_content',
								'exception' => $e->getMessage(),
								'trace'     => $e->getTraceAsString(),
							)
						);
					} catch ( Exception $log_error ) {
						// Fallback to PHP error log if WP logger fails.
						error_log( 'WP_MCP_AI: Failed to log stats error - ' . $log_error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				} else {
					// Logger not available, use PHP error log directly.
					error_log( 'WP_MCP_AI: Orchestration stats rendering failed - ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}

				// Return a safe fallback that doesn't break the page.
				return sprintf(
					'<div class="notice notice-warning inline"><p>%s</p></div>',
					esc_html__( 'Orchestration statistics temporarily unavailable. Please refresh the page or contact support if this persists.', 'wp-mcp-ai' )
				);
			}
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				if ( 'html' === $field['type'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in getter methods.
					echo $field['content'];
				} elseif ( 'slider' === $field['type'] ) {
					// Use orchestration renderer for sliders.
					if ( class_exists( 'WP_MCP_AI_Orchestration_Renderer' ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_slider method.
						echo WP_MCP_AI_Orchestration_Renderer::render_slider( $key, $field );
					}
				} else {
					$this->render_field( $key, $field );
				}
			}
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			// All fields are boolean checkboxes or sliders, no special validation needed.
			return $input;
		}
	}
}
