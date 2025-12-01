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
				'enable_auto_async_execution'     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Automatic Async Tool Execution', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically queue long-running tools in background', 'wp-mcp-ai' ),
					'description'    => __( 'Automatically execute long-running tools (video generation, image generation, etc.) asynchronously via WordPress cron to prevent PHP timeouts. When enabled, tools with "async", "long-running", or "may-timeout" capability flags will be queued immediately and return a job_id for status polling.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'async_tool_timeout'              => array(
					'type'        => 'number',
					'label'       => __( 'Async Tool Timeout (seconds)', 'wp-mcp-ai' ),
					'description' => __( 'Maximum time in seconds to wait for async tools (like video generation) to complete before timing out. Default is 300 seconds (5 minutes). Increase this for tools that may take longer, such as high-quality video generation.', 'wp-mcp-ai' ),
					'default'     => 300,
					'min'         => 60,
					'max'         => 900,
					'step'        => 30,
				),
				'cron_job_retention_period'       => array(
					'type'        => 'select',
					'label'       => __( 'Cron Job History Retention', 'wp-mcp-ai' ),
					'description' => __( 'How long to keep executed cron jobs visible in the Cron Manager after they run. This allows you to verify test jobs ran successfully and review execution history. Jobs with "Executed" status will remain visible for this period before being automatically removed.', 'wp-mcp-ai' ),
					'options'     => array(
						'1'   => __( '1 hour - Quick tests only', 'wp-mcp-ai' ),
						'6'   => __( '6 hours - Short-term testing', 'wp-mcp-ai' ),
						'24'  => __( '24 hours - Standard (Recommended)', 'wp-mcp-ai' ),
						'72'  => __( '3 days - Extended review', 'wp-mcp-ai' ),
						'168' => __( '1 week - Full audit trail', 'wp-mcp-ai' ),
						'720' => __( '30 days - Maximum retention', 'wp-mcp-ai' ),
						'0'   => __( 'Never - Remove immediately (not recommended for testing)', 'wp-mcp-ai' ),
					),
					'default'     => '24',
				),
				'slider_section_health'           => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Health Monitoring Thresholds', 'wp-mcp-ai' ) . '</h3>',
				),
				'memory_warning_threshold'        => array(
					'type'        => 'slider',
					'label'       => __( 'Memory Warning Threshold', 'wp-mcp-ai' ),
					'description' => __( 'Trigger warnings when memory usage exceeds this percentage (modern cloud-native standard: 70%).', 'wp-mcp-ai' ),
					'min'         => 50,
					'max'         => 95,
					'step'        => 5,
					'default'     => 70,
					'suffix'      => '%',
				),
				'memory_critical_threshold'       => array(
					'type'        => 'slider',
					'label'       => __( 'Memory Critical Threshold', 'wp-mcp-ai' ),
					'description' => __( 'Trigger critical alerts when memory usage exceeds this percentage (modern cloud-native standard: 85%).', 'wp-mcp-ai' ),
					'min'         => 75,
					'max'         => 99,
					'step'        => 1,
					'default'     => 85,
					'suffix'      => '%',
				),
				'error_rate_warning_threshold'    => array(
					'type'        => 'slider',
					'label'       => __( 'Error Rate Warning Threshold', 'wp-mcp-ai' ),
					'description' => __( 'Trigger warnings when error rate exceeds this percentage (SRE best practice: 5%).', 'wp-mcp-ai' ),
					'min'         => 5,
					'max'         => 25,
					'step'        => 1,
					'default'     => 5,
					'suffix'      => '%',
				),
				'error_rate_critical_threshold'   => array(
					'type'        => 'slider',
					'label'       => __( 'Error Rate Critical Threshold', 'wp-mcp-ai' ),
					'description' => __( 'Trigger critical alerts when error rate exceeds this percentage (SRE best practice: 10%).', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 50,
					'step'        => 5,
					'default'     => 10,
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
					'description' => __( 'Percentage of available budget allocated to medium-priority tasks (modern standard: 75%).', 'wp-mcp-ai' ),
					'min'         => 30,
					'max'         => 100,
					'step'        => 5,
					'default'     => 75,
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
					'content' => '<h3>' . esc_html__( 'Context Window Limits by Workload Tier', 'wp-mcp-ai' ) . '</h3><p class="description">' . esc_html__( 'These limits represent the total token budget per request, including system prompt, conversation history, user input, tool data, and AI output. Configuration Presets (above) set these values automatically, or you can customize them here.', 'wp-mcp-ai' ) . '</p><p class="description"><strong>' . esc_html__( 'Note:', 'wp-mcp-ai' ) . '</strong> ' . esc_html__( 'This is different from the "Tier Base Limits (tokens/day)" in the Token Manager, which control daily usage quotas per user tier.', 'wp-mcp-ai' ) . '</p>',
				),
				'low_tier_max_tokens'             => array(
					'type'        => 'slider',
					'label'       => __( 'Low Tier Context Window', 'wp-mcp-ai' ),
					'description' => __( 'Total context window for low-tier workloads (< 128MB memory). Includes all input and output tokens. Modern AI standard: 2000 tokens.', 'wp-mcp-ai' ),
					'min'         => 500,
					'max'         => 5000,
					'step'        => 100,
					'default'     => 2000,
					'suffix'      => '',
				),
				'medium_tier_max_tokens'          => array(
					'type'        => 'slider',
					'label'       => __( 'Medium Tier Context Window', 'wp-mcp-ai' ),
					'description' => __( 'Total context window for medium-tier workloads (128-512MB memory). Includes all input and output tokens. Modern AI standard: 8000 tokens.', 'wp-mcp-ai' ),
					'min'         => 2000,
					'max'         => 20000,
					'step'        => 500,
					'default'     => 8000,
					'suffix'      => '',
				),
				'high_tier_max_tokens'            => array(
					'type'        => 'slider',
					'label'       => __( 'High Tier Context Window', 'wp-mcp-ai' ),
					'description' => __( 'Total context window for high-tier workloads (> 512MB memory). Includes all input and output tokens. Modern AI standard: 32000 tokens.', 'wp-mcp-ai' ),
					'min'         => 8000,
					'max'         => 128000,
					'step'        => 1000,
					'default'     => 32000,
					'suffix'      => '',
				),
				'slider_section_call_limits'      => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Per-Call and Per-Session Limits', 'wp-mcp-ai' ) . '</h3><p class="description">' . esc_html__( 'Set maximum token limits for individual tool calls and chat sessions to prevent runaway costs and ensure fair resource distribution.', 'wp-mcp-ai' ) . '</p>',
				),
				'enable_per_call_limits'          => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Per-Call Token Limits', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable per-call token limits', 'wp-mcp-ai' ),
					'description'    => __( 'Limit the maximum number of tokens a single tool call can consume.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'per_call_token_limit'            => array(
					'type'        => 'slider',
					'label'       => __( 'Per-Call Token Limit', 'wp-mcp-ai' ),
					'description' => __( 'Maximum tokens per individual tool call (applies to all tools unless overridden). Set to 0 for unlimited.', 'wp-mcp-ai' ),
					'min'         => 0,
					'max'         => 100000,
					'step'        => 1000,
					'default'     => 10000,
					'suffix'      => '',
				),
				'enable_per_session_limits'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Per-Session Token Limits', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable per-session token limits', 'wp-mcp-ai' ),
					'description'    => __( 'Limit the total number of tokens a single chat session can consume across all tool calls.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'per_session_token_limit'         => array(
					'type'        => 'slider',
					'label'       => __( 'Per-Session Token Limit', 'wp-mcp-ai' ),
					'description' => __( 'Maximum tokens per chat session (cumulative across all tool calls). Set to 0 for unlimited.', 'wp-mcp-ai' ),
					'min'         => 0,
					'max'         => 500000,
					'step'        => 5000,
					'default'     => 50000,
					'suffix'      => '',
				),
				'slider_section_predictive'       => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Predictive Analytics', 'wp-mcp-ai' ) . '</h3>',
				),
				'prediction_confidence_threshold' => array(
					'type'        => 'slider',
					'label'       => __( 'Prediction Confidence Threshold', 'wp-mcp-ai' ),
					'description' => __( 'Minimum confidence level required to act on predictions (modern ML standard: 40%).', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 90,
					'step'        => 5,
					'default'     => 40,
					'suffix'      => '%',
				),
				'prediction_safety_buffer'        => array(
					'type'        => 'slider',
					'label'       => __( 'Prediction Safety Buffer', 'wp-mcp-ai' ),
					'description' => __( 'Extra safety margin when making predictive adjustments (modern standard: 15%).', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 50,
					'step'        => 5,
					'default'     => 15,
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

			$content  = '<div class="wp-mcp-ai-orchestration-intro">';
			$content .= '<h3>' . esc_html__( 'About the Orchestration Layer', 'wp-mcp-ai' ) . '</h3>';
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
				// Load performance reporter.
				if ( ! class_exists( 'WP_MCP_AI_Performance_Reporter' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-performance-reporter.php';
				}

				// Get current metrics.
				$report = WP_MCP_AI_Performance_Reporter::generate_report(
					array(
						'time_period' => '-7 days',
					)
				);

				ob_start();
				?>
				<!-- Overall Health Status -->
				<div class="wp-mcp-ai-performance-dashboard">
					<h2><?php esc_html_e( 'System Health', 'wp-mcp-ai' ); ?></h2>
					<div class="health-status health-status-<?php echo esc_attr( $report['overall_health'] ); ?>">
						<span class="health-icon dashicons dashicons-<?php echo esc_attr( $this->get_health_icon( $report['overall_health'] ) ); ?>"></span>
						<span class="health-label"><?php echo esc_html( ucfirst( $report['overall_health'] ) ); ?></span>
					</div>

					<!-- Summary Stats -->
					<div class="performance-summary">
						<div class="stat-card">
							<h3><?php esc_html_e( 'Components', 'wp-mcp-ai' ); ?></h3>
							<div class="stat-value"><?php echo esc_html( $report['summary']['total_components'] ); ?></div>
						</div>
						<div class="stat-card">
							<h3><?php esc_html_e( 'Alerts', 'wp-mcp-ai' ); ?></h3>
							<div class="stat-value"><?php echo esc_html( $report['summary']['total_alerts'] ); ?></div>
						</div>
						<div class="stat-card">
							<h3><?php esc_html_e( 'Recommendations', 'wp-mcp-ai' ); ?></h3>
							<div class="stat-value"><?php echo esc_html( $report['summary']['total_recommendations'] ); ?></div>
						</div>
					</div>
				</div>
				
				<style>
					.wp-mcp-ai-performance-dashboard {
						background: #fff;
						padding: 20px;
						margin: 20px 0;
						border: 1px solid #ccd0d4;
						box-shadow: 0 1px 1px rgba(0,0,0,.04);
					}
					.health-status {
						display: flex;
						align-items: center;
						font-size: 18px;
						margin: 15px 0;
					}
					.health-status-good { color: #46b450; }
					.health-status-fair { color: #ffb900; }
					.health-status-warning { color: #f0b849; }
					.health-status-critical { color: #dc3232; }
					.health-icon {
						font-size: 24px;
						margin-right: 10px;
					}
					.performance-summary {
						display: grid;
						grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
						gap: 15px;
						margin-top: 20px;
					}
					.stat-card {
						background: #f8f9fa;
						padding: 15px;
						border-radius: 4px;
						text-align: center;
					}
					.stat-card h3 {
						margin: 0 0 10px;
						font-size: 14px;
						color: #666;
					}
					.stat-value {
						font-size: 32px;
						font-weight: bold;
						color: #2271b1;
					}
				</style>
				<?php
				return ob_get_clean();

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

				$content  = '<div class="wp-mcp-ai-orchestration-stats">';
				$content .= '<h3>' . esc_html__( 'Current Orchestration Status', 'wp-mcp-ai' ) . '</h3>';

				$content .= '<div class="wp-mcp-ai-stats-grid">';

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

				$content .= '<div class="wp-mcp-ai-stats-card">';
				$content .= '<div class="wp-mcp-ai-stats-card__icon"><span class="dashicons dashicons-performance"></span></div>';
				$content .= '<div class="wp-mcp-ai-stats-card__content">';
				$content .= '<div class="wp-mcp-ai-stats-card__label">' . esc_html__( 'Workload Tier', 'wp-mcp-ai' ) . '</div>';
				$content .= '<div class="wp-mcp-ai-stats-card__value">' . esc_html( $memory_tier ) . '</div>';
				$content .= '</div>';
				$content .= '</div>';

				// Max tokens - Context Window.
				$max_tokens = $resource_manager->get_max_tokens();
				$content   .= '<div class="wp-mcp-ai-stats-card wp-mcp-ai-stats-card--context-window">';
				$content   .= '<div class="wp-mcp-ai-stats-card__icon"><span class="dashicons dashicons-chart-bar"></span></div>';
				$content   .= '<div class="wp-mcp-ai-stats-card__content">';
				$content   .= '<div class="wp-mcp-ai-stats-card__label">';
				$content   .= esc_html__( 'Context Window (Max Tokens)', 'wp-mcp-ai' );
				$content   .= ' <span class="dashicons dashicons-info-outline wp-mcp-ai-tooltip-trigger" data-tooltip="context-window-info"></span>';
				$content   .= '</div>';
				$content   .= '<div class="wp-mcp-ai-stats-card__value">' . esc_html( number_format( $max_tokens ) ) . '</div>';
				$content   .= '<div class="wp-mcp-ai-stats-card__subtitle">' . esc_html__( 'Total Budget Per Request', 'wp-mcp-ai' ) . '</div>';
				$content   .= '</div>';
				$content   .= '</div>';

				// Request timeout.
				$timeout  = $resource_manager->get_request_timeout();
				$content .= '<div class="wp-mcp-ai-stats-card">';
				$content .= '<div class="wp-mcp-ai-stats-card__icon"><span class="dashicons dashicons-clock"></span></div>';
				$content .= '<div class="wp-mcp-ai-stats-card__content">';
				$content .= '<div class="wp-mcp-ai-stats-card__label">' . esc_html__( 'Request Timeout', 'wp-mcp-ai' ) . '</div>';
				$content .= '<div class="wp-mcp-ai-stats-card__value">' . esc_html( $timeout ) . 's</div>';
				$content .= '</div>';
				$content .= '</div>';

				// Active cron jobs - use cached count for performance.
				if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
					// Use Cache Helper to avoid expensive lookups on every page load.
					$active_jobs = WP_MCP_AI_Cache_Helper::get( 'active_cron_count' );

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

						// Cache for 5 minutes using Cache Helper.
						WP_MCP_AI_Cache_Helper::set( 'active_cron_count', $active_jobs, WP_MCP_AI_Cache_Helper::ANALYTICS_EXPIRATION );
					}
				} else {
					$active_jobs = 0;
				}

				$content .= '<div class="wp-mcp-ai-stats-card">';
				$content .= '<div class="wp-mcp-ai-stats-card__icon"><span class="dashicons dashicons-calendar-alt"></span></div>';
				$content .= '<div class="wp-mcp-ai-stats-card__content">';
				$content .= '<div class="wp-mcp-ai-stats-card__label">' . esc_html__( 'Active Cron Jobs', 'wp-mcp-ai' ) . '</div>';
				$content .= '<div class="wp-mcp-ai-stats-card__value">' . esc_html( $active_jobs ) . '</div>';
				$content .= '</div>';
				$content .= '</div>';

				$content .= '</div>';

				// Token Budget Explanation Panel - delegate to renderer for SoC.
				if ( class_exists( 'WP_MCP_AI_Orchestration_Renderer' ) ) {
					$content .= WP_MCP_AI_Orchestration_Renderer::render_token_budget_explanation( $max_tokens );
				}

				// Quick actions.
				$content .= '<div>';
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
		 * Override render_wrapper to use custom structure without table.
		 */
		public function render_wrapper() {
			$description = $this->get_description();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
				<?php $this->render(); ?>
			</div>
			<?php
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$active_view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			?>
<div class="wp-mcp-ai-orchestration-section">
<!-- View Tabs -->
<nav class="wp-mcp-ai-orchestration__nav">
<a href="<?php echo esc_url( $this->get_view_url( 'overview' ) ); ?>" class="wp-mcp-ai-orchestration__nav-item <?php echo 'overview' === $active_view ? 'active' : ''; ?>">
<span class="dashicons dashicons-dashboard"></span>
			<?php esc_html_e( 'Overview', 'wp-mcp-ai' ); ?>
</a>
<a href="<?php echo esc_url( $this->get_view_url( 'settings' ) ); ?>" class="wp-mcp-ai-orchestration__nav-item <?php echo 'settings' === $active_view ? 'active' : ''; ?>">
<span class="dashicons dashicons-admin-settings"></span>
			<?php esc_html_e( 'Settings', 'wp-mcp-ai' ); ?>
</a>
<a href="<?php echo esc_url( $this->get_view_url( 'thresholds' ) ); ?>" class="wp-mcp-ai-orchestration__nav-item <?php echo 'thresholds' === $active_view ? 'active' : ''; ?>">
<span class="dashicons dashicons-performance"></span>
			<?php esc_html_e( 'Thresholds', 'wp-mcp-ai' ); ?>
</a>
<a href="<?php echo esc_url( $this->get_view_url( 'tools' ) ); ?>" class="wp-mcp-ai-orchestration__nav-item <?php echo 'tools' === $active_view ? 'active' : ''; ?>">
<span class="dashicons dashicons-admin-tools"></span>
			<?php esc_html_e( 'Tools', 'wp-mcp-ai' ); ?>
</a>
</nav>

<!-- Hidden field to preserve view during form submission -->
<input type="hidden" name="view" value="<?php echo esc_attr( $active_view ); ?>" />

<!-- View Content -->
<div class="wp-mcp-ai-orchestration__content">
			<?php
			switch ( $active_view ) {
				case 'settings':
					$this->render_settings_view();
					break;
				case 'thresholds':
					$this->render_thresholds_view();
					break;
				case 'tools':
					$this->render_tools_view();
					break;
				case 'overview':
				default:
					$this->render_overview_view();
					break;
			}
			?>
</div>
</div>
			<?php
		}

		/**
		 * Get URL for a specific view.
		 *
		 * @param string $view View name.
		 * @return string
		 */
		private function get_view_url( $view ) {
			return add_query_arg(
				array(
					'page' => WP_MCP_AI_Settings_Dashboard::PAGE_SLUG,
					'tab'  => 'orchestration',
					'view' => $view,
				),
				admin_url( 'admin.php' )
			);
		}

		/**
		 * Render overview view.
		 */
		private function render_overview_view() {
			$fields = $this->get_fields();

			// Overview fields: intro, health status, presets, and stats.
			$overview_fields = array(
				'orchestration_intro',
				'health_status',
				'configuration_presets',
				'orchestration_stats',
			);

			foreach ( $overview_fields as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$field = $fields[ $key ];
					if ( 'html' === $field['type'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in getter methods.
						echo $field['content'];
					}
				}
			}
		}

		/**
		 * Render settings view.
		 */
		private function render_settings_view() {
			$fields = $this->get_fields();

			// Settings fields: enable/disable toggles, cron retention, and async execution settings.
			$settings_fields = array(
				'enable_budget_management',
				'enable_predictive_optimization',
				'enable_capability_gating',
				'enable_cron_orchestration',
				'enable_auto_async_execution',
				'async_tool_timeout',
				'cron_job_retention_period',
			);

			echo '<h3>' . esc_html__( 'Orchestration Features', 'wp-mcp-ai' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Enable or disable orchestration layer features. These settings control how the AI orchestration system manages resources, security, and task scheduling. All orchestration features work uniformly across all AI providers (OpenAI, Gemini, Anthropic, Ollama, LM Studio).', 'wp-mcp-ai' ) . '</p>';

			echo '<table class="form-table" role="presentation">';
			foreach ( $settings_fields as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$this->render_field( $key, $fields[ $key ] );
				}
			}
			echo '</table>';
		}

		/**
		 * Render thresholds view.
		 */
		private function render_thresholds_view() {
			$fields = $this->get_fields();

			// Thresholds fields: all sliders and their section headers.
			$threshold_fields = array(
				'slider_section_health',
				'memory_warning_threshold',
				'memory_critical_threshold',
				'error_rate_warning_threshold',
				'error_rate_critical_threshold',
				'slider_section_budget',
				'high_priority_budget',
				'medium_priority_budget',
				'low_priority_budget',
				'critical_health_reduction',
				'warning_health_reduction',
				'slider_section_tokens',
				'low_tier_max_tokens',
				'medium_tier_max_tokens',
				'high_tier_max_tokens',
				'slider_section_call_limits',
				'per_call_token_limit',
				'per_session_token_limit',
				'slider_section_predictive',
				'prediction_confidence_threshold',
				'prediction_safety_buffer',
			);

			// Render sliders and section headers.
			foreach ( $threshold_fields as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$field = $fields[ $key ];
					if ( 'html' === $field['type'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in field content.
						echo $field['content'];
					} elseif ( 'slider' === $field['type'] ) {
						// Use orchestration renderer for sliders.
						if ( class_exists( 'WP_MCP_AI_Orchestration_Renderer' ) ) {
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in render_slider method.
							echo WP_MCP_AI_Orchestration_Renderer::render_slider( $key, $field );
						}
					}
				}
			}

			// Render checkbox fields in a table after sliders.
			$checkbox_fields = array(
				'enable_per_call_limits',
				'enable_per_session_limits',
			);

			echo '<table class="form-table" role="presentation">';
			foreach ( $checkbox_fields as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$this->render_field( $key, $fields[ $key ] );
				}
			}
			echo '</table>';
		}


		/**
		 * Sanitize input for this section.
		 *
		 * Orchestration section uses views similar to subtabs.
		 * Only process fields from the active view to prevent clearing
		 * settings from other views when saving.
		 *
		 * @param array $input Raw input from form.
		 * @return array Sanitized input.
		 */
		public function sanitize( $input ) {
			return $this->sanitize_with_views( $input );
		}

		/**
		 * Sanitize input for sections with views.
		 *
		 * Only processes fields from the active view to prevent clearing
		 * settings from inactive views when saving.
		 *
		 * @param array $input Raw input from form.
		 * @return array Sanitized input for active view only.
		 */
		protected function sanitize_with_views( $input ) {
			$view_groups = $this->get_view_groups();

			// Get the submitted view from the hidden field in the form.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
			$submitted_view = isset( $_POST['view'] ) ? sanitize_key( $_POST['view'] ) : '';

			// If no valid view submitted, return empty to preserve all existing settings.
			if ( ! isset( $view_groups[ $submitted_view ] ) ) {
				return array();
			}

			$active_field_keys = $view_groups[ $submitted_view ]['fields'];
			$all_fields        = $this->get_fields();

			// Filter to only active fields.
			$active_fields = array();
			foreach ( $active_field_keys as $field_key ) {
				if ( isset( $all_fields[ $field_key ] ) ) {
					$active_fields[ $field_key ] = $all_fields[ $field_key ];
				}
			}

			return $this->sanitize_fields( $input, $active_fields, true );
		}

		/**
		 * Get view groups with their fields.
		 *
		 * @return array View groups configuration.
		 */
		protected function get_view_groups() {
			return array(
				'overview'   => array(
					'label'  => __( 'Overview', 'wp-mcp-ai' ),
					'fields' => array(
						'orchestration_intro',
						'health_status',
						'configuration_presets',
						'orchestration_stats',
					),
				),
				'settings'   => array(
					'label'  => __( 'Settings', 'wp-mcp-ai' ),
					'fields' => array(
						'enable_budget_management',
						'enable_predictive_optimization',
						'enable_capability_gating',
						'enable_cron_orchestration',
						'enable_auto_async_execution',
						'async_tool_timeout',
						'cron_job_retention_period',
					),
				),
				'thresholds' => array(
					'label'  => __( 'Thresholds', 'wp-mcp-ai' ),
					'fields' => array(
						'slider_section_health',
						'memory_warning_threshold',
						'memory_critical_threshold',
						'error_rate_warning_threshold',
						'error_rate_critical_threshold',
						'slider_section_budget',
						'high_priority_budget',
						'medium_priority_budget',
						'low_priority_budget',
						'critical_health_reduction',
						'warning_health_reduction',
						'slider_section_tokens',
						'low_tier_max_tokens',
						'medium_tier_max_tokens',
						'high_tier_max_tokens',
						'slider_section_call_limits',
						'per_call_token_limit',
						'per_session_token_limit',
						'enable_per_call_limits',
						'enable_per_session_limits',
						'slider_section_predictive',
						'prediction_confidence_threshold',
						'prediction_safety_buffer',
					),
				),
				'tools'      => array(
					'label'  => __( 'Tools', 'wp-mcp-ai' ),
					'fields' => array(
						// Tools view is read-only, no editable fields.
					),
				),
			);
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

		/**
		 * Render tools view.
		 *
		 * Displays all registered tools with their capabilities and orchestration settings.
		 * Delegates rendering to WP_MCP_AI_Tools_Orchestration_Renderer (SoC).
		 */
		private function render_tools_view() {
			// Load renderer class if not already loaded.
			if ( ! class_exists( 'WP_MCP_AI_Tools_Orchestration_Renderer' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-tools-orchestration-renderer.php';
			}

			// Delegate rendering to the renderer class (SoC).
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in renderer methods.
			echo WP_MCP_AI_Tools_Orchestration_Renderer::render_tools_view();
		}

		/**
		 * Get health icon for status.
		 *
		 * @param string $status Health status.
		 * @return string Icon name.
		 */
		protected function get_health_icon( $status ) {
			$icons = array(
				'good'     => 'yes-alt',
				'fair'     => 'info',
				'warning'  => 'warning',
				'critical' => 'dismiss',
			);

			return isset( $icons[ $status ] ) ? $icons[ $status ] : 'info';
		}
	}
}
