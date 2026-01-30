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
			return __( 'Orchestration Layer', 'mcp-ai-wpoos' );
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
			return __( 'Configure the dynamic AI orchestration layer that manages resource budgets, capability gating, and distributed AI operations.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md';
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			// Cache provider options to avoid duplicate method calls.
			$provider_options = array( '' => __( '-- Use Global Default --', 'mcp-ai-wpoos' ) ) + WP_MCP_AI_Admin_Settings::get_available_providers();

			return array(
				'orchestration_intro'             => array(
					'type'    => 'html',
					'content' => $this->get_intro_content(),
				),
				'health_status'                   => array(
					'type'    => 'html',
					'content' => $this->get_health_status_content(),
				),
				'load_monitoring'                 => array(
					'type'    => 'html',
					'content' => $this->get_load_monitoring_content(),
				),
				'performance_statistics'          => array(
					'type'    => 'html',
					'content' => $this->get_performance_statistics_content(),
				),
				'configuration_presets'           => array(
					'type'    => 'html',
					'content' => $this->get_presets_content(),
				),
				'orchestration_preset'            => array(
					'type'        => 'hidden',
					'default'     => 'auto',
					'description' => __( 'Current orchestration configuration preset. Managed by the preset selector above.', 'mcp-ai-wpoos' ),
				),
				'enable_budget_management'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Dynamic Budget Management', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable dynamic budget management', 'mcp-ai-wpoos' ),
					'description'    => __( 'Automatically allocate and adjust token budgets based on system resources and workload tier.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_predictive_optimization'  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Predictive Optimization', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable predictive optimization', 'mcp-ai-wpoos' ),
					'description'    => __( 'Use historical usage patterns to forecast and prevent resource exhaustion.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_capability_gating'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Capability-Based Tool Gating', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable capability-based tool gating', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enforce WordPress capability checks for tool access based on user roles.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_cron_orchestration'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Cron-Based Task Orchestration', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable cron-based task orchestration', 'mcp-ai-wpoos' ),
					'description'    => __( 'Allow AI agents to create and manage scheduled background tasks with inherited budget constraints.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_auto_async_execution'     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Automatic Async Tool Execution', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Automatically queue long-running tools in background', 'mcp-ai-wpoos' ),
					'description'    => __( 'Automatically execute long-running tools (video generation, image generation, etc.) asynchronously via WordPress cron to prevent PHP timeouts. When enabled, tools with "async", "long-running", or "may-timeout" capability flags will be queued immediately and return a job_id for status polling.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'section_multi_agent'             => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Multi-Agent Orchestration', 'mcp-ai-wpoos' ) . '</h3><p class="description">' . esc_html__( 'Control multi-agent coordination features inspired by DeepSeek V4 patterns. These features enable sophisticated agent role management, profession-based AI workforce, and team-based workflows.', 'mcp-ai-wpoos' ) . '</p>',
				),
				'enable_agent_roles'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Agent Role System', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable agent roles (Planner, Executor, Critic, Specialist)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enable the agent role abstraction layer for multi-agent coordination. Agent roles define specialized behaviors (planning, execution, validation, domain expertise) that can be assigned to AI professions. Disabling this will hide the Agents view from the orchestration dashboard.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_professions'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable AI Professions', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable profession-based AI workforce management', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enable the AI professions custom post type for creating specialized AI assistants with specific roles, tools, and expertise. Professions are the deployable agents used in multi-agent workflows. Disabling this will hide the Professions view and limit multi-agent capabilities.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_multi_agent_teams'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Multi-Agent Teams', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable team-based multi-agent coordination', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enable creating teams of AI professions that work together on complex tasks. Teams allow agents with different roles (Planner, Executor, Critic) to collaborate, with automatic task delegation and result aggregation. Requires Agent Roles and Professions to be enabled.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_agent_coordination_tools' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Agent Coordination Tools', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable create_agent_team, delegate_to_agent, aggregate_agent_results tools', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enable specialized tools for multi-agent coordination: create_agent_team (compose teams), delegate_to_agent (task delegation), and aggregate_agent_results (result merging). These tools allow AI assistants to orchestrate other AI assistants for complex workflows.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'profession_default_provider'     => array(
					'type'        => 'select',
					'label'       => __( 'Professions Default Provider', 'mcp-ai-wpoos' ),
					'description' => __( 'Default AI provider for all professions. Individual professions can override this setting.', 'mcp-ai-wpoos' ),
					'options'     => $provider_options,
					'default'     => '',
				),
				'profession_default_model'        => array(
					'type'        => 'text',
					'label'       => __( 'Professions Default Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Default AI model for all professions (e.g., gpt-4o, claude-3-5-sonnet-20241022). Leave empty to use provider default.', 'mcp-ai-wpoos' ),
					'default'     => '',
				),
				'profession_default_temperature'  => array(
					'type'        => 'number',
					'label'       => __( 'Professions Default Temperature', 'mcp-ai-wpoos' ),
					'description' => __( 'Default creativity/randomness setting for professions (0.0 = deterministic, 1.0 = creative). Individual professions can override.', 'mcp-ai-wpoos' ),
					'default'     => 0.7,
					'min'         => 0,
					'max'         => 1,
					'step'        => 0.1,
				),
				'team_default_provider'           => array(
					'type'        => 'select',
					'label'       => __( 'Teams Default Provider', 'mcp-ai-wpoos' ),
					'description' => __( 'Default AI provider for all team members. Individual teams can override this setting.', 'mcp-ai-wpoos' ),
					'options'     => $provider_options,
					'default'     => '',
				),
				'team_default_model'              => array(
					'type'        => 'text',
					'label'       => __( 'Teams Default Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Default AI model for all team members (e.g., gpt-4o, claude-3-5-sonnet-20241022). Leave empty to use provider default.', 'mcp-ai-wpoos' ),
					'default'     => '',
				),
				'team_default_temperature'        => array(
					'type'        => 'number',
					'label'       => __( 'Teams Default Temperature', 'mcp-ai-wpoos' ),
					'description' => __( 'Default creativity/randomness setting for teams (0.0 = deterministic, 1.0 = creative). Individual teams can override.', 'mcp-ai-wpoos' ),
					'default'     => 0.7,
					'min'         => 0,
					'max'         => 1,
					'step'        => 0.1,
				),
				'async_tool_timeout'              => array(
					'type'        => 'number',
					'label'       => __( 'Async Tool Timeout (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum time in seconds to wait for async tools (like video generation) to complete before timing out. Default is 300 seconds (5 minutes). Increase this for tools that may take longer, such as high-quality video generation.', 'mcp-ai-wpoos' ),
					'default'     => 300,
					'min'         => 60,
					'max'         => 900,
					'step'        => 30,
				),
				'cron_job_retention_period'       => array(
					'type'        => 'select',
					'label'       => __( 'Cron Job History Retention', 'mcp-ai-wpoos' ),
					'description' => __( 'How long to keep executed cron jobs visible in the Cron Manager after they run. This allows you to verify test jobs ran successfully and review execution history. Jobs with "Executed" status will remain visible for this period before being automatically removed.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'1'   => __( '1 hour - Quick tests only', 'mcp-ai-wpoos' ),
						'6'   => __( '6 hours - Short-term testing', 'mcp-ai-wpoos' ),
						'24'  => __( '24 hours - Standard (Recommended)', 'mcp-ai-wpoos' ),
						'72'  => __( '3 days - Extended review', 'mcp-ai-wpoos' ),
						'168' => __( '1 week - Full audit trail', 'mcp-ai-wpoos' ),
						'720' => __( '30 days - Maximum retention', 'mcp-ai-wpoos' ),
						'0'   => __( 'Never - Remove immediately (not recommended for testing)', 'mcp-ai-wpoos' ),
					),
					'default'     => '24',
				),
				'slider_section_health'           => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Health Monitoring Thresholds', 'mcp-ai-wpoos' ) . '</h3>',
				),
				'memory_warning_threshold'        => array(
					'type'        => 'slider',
					'label'       => __( 'Memory Warning Threshold', 'mcp-ai-wpoos' ),
					'description' => __( 'Trigger warnings when memory usage exceeds this percentage (modern cloud-native standard: 70%).', 'mcp-ai-wpoos' ),
					'min'         => 50,
					'max'         => 95,
					'step'        => 5,
					'default'     => 70,
					'suffix'      => '%',
				),
				'memory_critical_threshold'       => array(
					'type'        => 'slider',
					'label'       => __( 'Memory Critical Threshold', 'mcp-ai-wpoos' ),
					'description' => __( 'Trigger critical alerts when memory usage exceeds this percentage (modern cloud-native standard: 85%).', 'mcp-ai-wpoos' ),
					'min'         => 75,
					'max'         => 99,
					'step'        => 1,
					'default'     => 85,
					'suffix'      => '%',
				),
				'error_rate_warning_threshold'    => array(
					'type'        => 'slider',
					'label'       => __( 'Error Rate Warning Threshold', 'mcp-ai-wpoos' ),
					'description' => __( 'Trigger warnings when error rate exceeds this percentage (SRE best practice: 5%).', 'mcp-ai-wpoos' ),
					'min'         => 5,
					'max'         => 25,
					'step'        => 1,
					'default'     => 5,
					'suffix'      => '%',
				),
				'error_rate_critical_threshold'   => array(
					'type'        => 'slider',
					'label'       => __( 'Error Rate Critical Threshold', 'mcp-ai-wpoos' ),
					'description' => __( 'Trigger critical alerts when error rate exceeds this percentage (SRE best practice: 10%).', 'mcp-ai-wpoos' ),
					'min'         => 10,
					'max'         => 50,
					'step'        => 5,
					'default'     => 10,
					'suffix'      => '%',
				),
				'slider_section_budget'           => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Adaptive Budget Allocation', 'mcp-ai-wpoos' ) . '</h3>',
				),
				'high_priority_budget'            => array(
					'type'        => 'slider',
					'label'       => __( 'High Priority Budget', 'mcp-ai-wpoos' ),
					'description' => __( 'Percentage of available budget allocated to high-priority tasks.', 'mcp-ai-wpoos' ),
					'min'         => 50,
					'max'         => 100,
					'step'        => 5,
					'default'     => 100,
					'suffix'      => '%',
				),
				'medium_priority_budget'          => array(
					'type'        => 'slider',
					'label'       => __( 'Medium Priority Budget', 'mcp-ai-wpoos' ),
					'description' => __( 'Percentage of available budget allocated to medium-priority tasks (modern standard: 75%).', 'mcp-ai-wpoos' ),
					'min'         => 30,
					'max'         => 100,
					'step'        => 5,
					'default'     => 75,
					'suffix'      => '%',
				),
				'low_priority_budget'             => array(
					'type'        => 'slider',
					'label'       => __( 'Low Priority Budget', 'mcp-ai-wpoos' ),
					'description' => __( 'Percentage of available budget allocated to low-priority tasks.', 'mcp-ai-wpoos' ),
					'min'         => 10,
					'max'         => 80,
					'step'        => 5,
					'default'     => 50,
					'suffix'      => '%',
				),
				'critical_health_reduction'       => array(
					'type'        => 'slider',
					'label'       => __( 'Critical Health Budget Reduction', 'mcp-ai-wpoos' ),
					'description' => __( 'Reduce budgets to this percentage when system health is critical.', 'mcp-ai-wpoos' ),
					'min'         => 10,
					'max'         => 80,
					'step'        => 5,
					'default'     => 50,
					'suffix'      => '%',
				),
				'warning_health_reduction'        => array(
					'type'        => 'slider',
					'label'       => __( 'Warning Health Budget Reduction', 'mcp-ai-wpoos' ),
					'description' => __( 'Reduce budgets to this percentage when system health shows warnings.', 'mcp-ai-wpoos' ),
					'min'         => 50,
					'max'         => 100,
					'step'        => 5,
					'default'     => 75,
					'suffix'      => '%',
				),
				'slider_section_tokens'           => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Context Window Limits by Workload Tier', 'mcp-ai-wpoos' ) . '</h3><p class="description">' . esc_html__( 'These limits represent the total token budget per request, including system prompt, conversation history, user input, tool data, and AI output. Configuration Presets (above) set these values automatically, or you can customize them here.', 'mcp-ai-wpoos' ) . '</p><p class="description"><strong>' . esc_html__( 'Note:', 'mcp-ai-wpoos' ) . '</strong> ' . esc_html__( 'This is different from the "Tier Base Limits (tokens/day)" in the Token Manager, which control daily usage quotas per user tier.', 'mcp-ai-wpoos' ) . '</p>',
				),
				'low_tier_max_tokens'             => array(
					'type'        => 'slider',
					'label'       => __( 'Low Tier Context Window', 'mcp-ai-wpoos' ),
					'description' => __( 'Total context window for low-tier workloads (< 128MB memory). Includes all input and output tokens. Modern AI standard: 2000 tokens.', 'mcp-ai-wpoos' ),
					'min'         => 500,
					'max'         => 5000,
					'step'        => 100,
					'default'     => 2000,
					'suffix'      => '',
				),
				'medium_tier_max_tokens'          => array(
					'type'        => 'slider',
					'label'       => __( 'Medium Tier Context Window', 'mcp-ai-wpoos' ),
					'description' => __( 'Total context window for medium-tier workloads (128-512MB memory). Includes all input and output tokens. Modern AI standard: 8000 tokens.', 'mcp-ai-wpoos' ),
					'min'         => 2000,
					'max'         => 20000,
					'step'        => 500,
					'default'     => 8000,
					'suffix'      => '',
				),
				'high_tier_max_tokens'            => array(
					'type'        => 'slider',
					'label'       => __( 'High Tier Context Window', 'mcp-ai-wpoos' ),
					'description' => __( 'Total context window for high-tier workloads (> 512MB memory). Includes all input and output tokens. Modern AI standard: 32000 tokens.', 'mcp-ai-wpoos' ),
					'min'         => 8000,
					'max'         => 128000,
					'step'        => 1000,
					'default'     => 32000,
					'suffix'      => '',
				),
				'slider_section_call_limits'      => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Per-Call and Per-Session Limits', 'mcp-ai-wpoos' ) . '</h3><p class="description">' . esc_html__( 'Set maximum token limits for individual tool calls and chat sessions to prevent runaway costs and ensure fair resource distribution.', 'mcp-ai-wpoos' ) . '</p>',
				),
				'enable_per_call_limits'          => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Per-Call Token Limits', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable per-call token limits', 'mcp-ai-wpoos' ),
					'description'    => __( 'Limit the maximum number of tokens a single tool call can consume.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'per_call_token_limit'            => array(
					'type'        => 'slider',
					'label'       => __( 'Per-Call Token Limit', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum tokens per individual tool call (applies to all tools unless overridden). Set to 0 for unlimited.', 'mcp-ai-wpoos' ),
					'min'         => 0,
					'max'         => 100000,
					'step'        => 1000,
					'default'     => 10000,
					'suffix'      => '',
				),
				'enable_per_session_limits'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Per-Session Token Limits', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable per-session token limits', 'mcp-ai-wpoos' ),
					'description'    => __( 'Limit the total number of tokens a single chat session can consume across all tool calls.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'per_session_token_limit'         => array(
					'type'        => 'slider',
					'label'       => __( 'Per-Session Token Limit', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum tokens per chat session (cumulative across all tool calls). Set to 0 for unlimited.', 'mcp-ai-wpoos' ),
					'min'         => 0,
					'max'         => 500000,
					'step'        => 5000,
					'default'     => 50000,
					'suffix'      => '',
				),
				'slider_section_predictive'       => array(
					'type'    => 'html',
					'content' => '<h3>' . esc_html__( 'Predictive Analytics', 'mcp-ai-wpoos' ) . '</h3>',
				),
				'prediction_confidence_threshold' => array(
					'type'        => 'slider',
					'label'       => __( 'Prediction Confidence Threshold', 'mcp-ai-wpoos' ),
					'description' => __( 'Minimum confidence level required to act on predictions (modern ML standard: 40%).', 'mcp-ai-wpoos' ),
					'min'         => 10,
					'max'         => 90,
					'step'        => 5,
					'default'     => 40,
					'suffix'      => '%',
				),
				'prediction_safety_buffer'        => array(
					'type'        => 'slider',
					'label'       => __( 'Prediction Safety Buffer', 'mcp-ai-wpoos' ),
					'description' => __( 'Extra safety margin when making predictive adjustments (modern standard: 15%).', 'mcp-ai-wpoos' ),
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
			$doc_path   = WP_MCP_AI_PATH . 'docs/architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md';
			$doc_exists = file_exists( $doc_path );

			$content  = '<div class="wp-mcp-ai-orchestration-intro">';
			$content .= '<h3>' . esc_html__( 'About the Orchestration Layer', 'mcp-ai-wpoos' ) . '</h3>';
			$content .= '<p>' . esc_html__( 'The NV oOS Dynamic AI Orchestration Layer extends standard SSE and MCP implementations with sophisticated resource management, security enforcement, and predictive optimization. This overcomes PHP\'s architectural limitations to provide Node.js-level orchestration capabilities within WordPress.', 'mcp-ai-wpoos' ) . '</p>';

			$content .= '<h4>' . esc_html__( 'Key Features:', 'mcp-ai-wpoos' ) . '</h4>';
			$content .= '<ul>';
			$content .= '<li><strong>' . esc_html__( 'Real-Time Budget Enforcement', 'mcp-ai-wpoos' ) . ':</strong> ' . esc_html__( 'Monitors and adjusts token/memory budgets dynamically', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li><strong>' . esc_html__( 'Capability-Based Tool Gating', 'mcp-ai-wpoos' ) . ':</strong> ' . esc_html__( 'Enforces WordPress role permissions for tool access', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li><strong>' . esc_html__( 'Predictive Optimization', 'mcp-ai-wpoos' ) . ':</strong> ' . esc_html__( 'Prevents resource exhaustion before it occurs', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li><strong>' . esc_html__( 'Distributed Orchestration', 'mcp-ai-wpoos' ) . ':</strong> ' . esc_html__( 'Multi-provider coordination with unified policies', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li><strong>' . esc_html__( 'Cron-Based Task Management', 'mcp-ai-wpoos' ) . ':</strong> ' . esc_html__( 'Scheduled operations with budget inheritance', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li><strong>' . esc_html__( 'Auditability & Compliance', 'mcp-ai-wpoos' ) . ':</strong> ' . esc_html__( 'Complete logging and deterministic behavior', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '</ul>';

			if ( $doc_exists ) {
				$content .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=orchestration#architecture-doc' ) ) . '" class="button button-secondary">' . esc_html__( 'View Full Architecture Documentation', 'mcp-ai-wpoos' ) . '</a></p>';
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
					<h2><?php esc_html_e( 'System Health', 'mcp-ai-wpoos' ); ?></h2>
					<div class="health-status health-status-<?php echo esc_attr( $report['overall_health'] ); ?>">
						<span class="health-icon dashicons dashicons-<?php echo esc_attr( $this->get_health_icon( $report['overall_health'] ) ); ?>"></span>
						<span class="wp-mcp-ai-health-label"><?php echo esc_html( ucfirst( $report['overall_health'] ) ); ?></span>
					</div>

					<!-- Summary Stats -->
					<div class="performance-summary">
						<div class="stat-card">
							<h3><?php esc_html_e( 'Components', 'mcp-ai-wpoos' ); ?></h3>
							<div class="stat-value"><?php echo esc_html( $report['summary']['total_components'] ); ?></div>
						</div>
						<div class="stat-card">
							<h3><?php esc_html_e( 'Alerts', 'mcp-ai-wpoos' ); ?></h3>
							<div class="stat-value"><?php echo esc_html( $report['summary']['total_alerts'] ); ?></div>
						</div>
						<div class="stat-card">
							<h3><?php esc_html_e( 'Recommendations', 'mcp-ai-wpoos' ); ?></h3>
							<div class="stat-value"><?php echo esc_html( $report['summary']['total_recommendations'] ); ?></div>
						</div>
					</div>
				</div>

				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
				?>
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
				return '<div class="notice notice-warning inline"><p>' . esc_html__( 'Health status temporarily unavailable.', 'mcp-ai-wpoos' ) . '</p></div>';
			}
		}

		/**
		 * Get load monitoring content HTML.
		 *
		 * @return string
		 */
		private function get_load_monitoring_content() {
			try {
				// Check if Load Monitor is available.
				if ( ! class_exists( 'WP_MCP_AI_Tool_Load_Monitor' ) ) {
					return '<div class="notice notice-info inline"><p>' . esc_html__( 'Load monitoring not available. Phase 2.1 implementation required.', 'mcp-ai-wpoos' ) . '</p></div>';
				}

				$monitor        = new WP_MCP_AI_Tool_Load_Monitor();
				$system_metrics = $monitor->get_system_load_metrics();

				ob_start();
				?>
				<!-- Load Monitoring Dashboard -->
				<div class="wp-mcp-ai-load-monitoring">
					<h2>
						<?php esc_html_e( 'Load Monitoring & Capacity', 'mcp-ai-wpoos' ); ?>
						<span class="dashicons dashicons-chart-line" style="font-size: 24px; vertical-align: middle;"></span>
					</h2>
					<p class="description">
						<?php esc_html_e( 'Real-time tool execution load monitored via Little\'s Law (L = λ × W). Capacity-aware routing prevents system overload.', 'mcp-ai-wpoos' ); ?>
					</p>

					<!-- System Health Overview -->
					<div class="load-system-health">
						<div class="health-card health-<?php echo esc_attr( $system_metrics['health_status'] ); ?>">
							<h3><?php esc_html_e( 'System Health', 'mcp-ai-wpoos' ); ?></h3>
							<div class="wp-mcp-ai-health-indicator">
								<span class="health-icon dashicons dashicons-<?php echo esc_attr( $this->get_health_icon( $system_metrics['health_status'] ) ); ?>"></span>
								<span class="wp-mcp-ai-health-label"><?php echo esc_html( ucfirst( $system_metrics['health_status'] ) ); ?></span>
							</div>
						</div>

						<div class="capacity-card">
							<h3><?php esc_html_e( 'Available Capacity', 'mcp-ai-wpoos' ); ?></h3>
							<div class="capacity-value">
								<?php echo esc_html( number_format( $system_metrics['available_capacity'], 1 ) ); ?>%
							</div>
							<div class="capacity-bar">
								<div class="capacity-fill" style="width: <?php echo esc_attr( $system_metrics['available_capacity'] ); ?>%;"></div>
							</div>
						</div>

						<div class="utilization-card">
							<h3><?php esc_html_e( 'Utilization', 'mcp-ai-wpoos' ); ?></h3>
							<div class="utilization-value">
								<?php echo esc_html( number_format( $system_metrics['overall_utilization'] * 100, 1 ) ); ?>%
							</div>
						</div>

						<div class="tools-card">
							<h3><?php esc_html_e( 'Active Tools', 'mcp-ai-wpoos' ); ?></h3>
							<div class="tools-value">
								<?php echo esc_html( $system_metrics['active_tools'] ); ?>
							</div>
						</div>
					</div>

					<!-- Top Tools by Load -->
					<?php if ( ! empty( $system_metrics['top_tools'] ) ) : ?>
					<div class="top-tools-section">
						<h3><?php esc_html_e( 'Top Tools by Utilization', 'mcp-ai-wpoos' ); ?></h3>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Tool', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Arrival Rate (λ)', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Service Time (W)', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Queue Length (L)', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Utilization', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Capacity', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'SLA Tier', 'mcp-ai-wpoos' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								$count = 0;
								foreach ( $system_metrics['top_tools'] as $tool_slug => $metrics ) :
									if ( ++$count > 10 ) {
										break;
									}
									$utilization_pct   = $metrics['utilization'] * 100;
									$utilization_class = $utilization_pct > 85 ? 'critical' : ( $utilization_pct > 70 ? 'warning' : 'good' );
									?>
									<tr>
										<td><code><?php echo esc_html( $tool_slug ); ?></code></td>
										<td><?php echo esc_html( number_format( $metrics['arrival_rate'], 3 ) ); ?>/s</td>
										<td><?php echo esc_html( number_format( $metrics['service_time'], 2 ) ); ?>s</td>
										<td><?php echo esc_html( number_format( $metrics['queue_length'], 2 ) ); ?></td>
										<td class="utilization-<?php echo esc_attr( $utilization_class ); ?>">
											<?php echo esc_html( number_format( $utilization_pct, 1 ) ); ?>%
										</td>
										<td>
											<span class="capacity-score" style="color: <?php echo esc_attr( $metrics['capacity_score'] > 70 ? '#46b450' : ( $metrics['capacity_score'] > 30 ? '#f0b849' : '#dc3232' ) ); ?>;">
												<?php echo esc_html( number_format( $metrics['capacity_score'], 0 ) ); ?>
											</span>
										</td>
										<td>
											<span class="sla-tier sla-<?php echo esc_attr( $metrics['sla_tier'] ); ?>">
												<?php echo esc_html( ucfirst( str_replace( '_', ' ', $metrics['sla_tier'] ) ) ); ?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php endif; ?>

					<!-- Recommendations -->
					<?php if ( ! empty( $system_metrics['recommendations'] ) ) : ?>
					<div class="recommendations-section">
						<h3><?php esc_html_e( 'Recommendations', 'mcp-ai-wpoos' ); ?></h3>
						<ul class="recommendations-list">
							<?php foreach ( $system_metrics['recommendations'] as $recommendation ) : ?>
								<li><?php echo esc_html( $recommendation ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>
				</div>

				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
				?>
				<style>
					.wp-mcp-ai-load-monitoring {
						background: #fff;
						padding: 20px;
						margin: 20px 0;
						border: 1px solid #ccd0d4;
						box-shadow: 0 1px 1px rgba(0,0,0,.04);
					}
					.load-system-health {
						display: grid;
						grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
						gap: 15px;
						margin: 20px 0;
					}
					.health-card, .capacity-card, .utilization-card, .tools-card {
						background: #f8f9fa;
						padding: 20px;
						border-radius: 8px;
						text-align: center;
					}
					.health-card h3, .capacity-card h3, .utilization-card h3, .tools-card h3 {
						margin: 0 0 15px;
						font-size: 14px;
						color: #666;
						text-transform: uppercase;
					}
					.wp-mcp-ai-health-indicator {
						display: flex;
						align-items: center;
						justify-content: center;
						gap: 10px;
					}
					.health-icon {
						font-size: 32px;
					}
					.wp-mcp-ai-health-label {
						font-size: 24px;
						font-weight: bold;
					}
					.health-excellent .health-icon, .health-excellent .wp-mcp-ai-health-label { color: #46b450; }
					.health-good .health-icon, .health-good .wp-mcp-ai-health-label { color: #46b450; }
					.health-warning .health-icon, .health-warning .wp-mcp-ai-health-label { color: #f0b849; }
					.health-critical .health-icon, .health-critical .wp-mcp-ai-health-label { color: #dc3232; }
					.capacity-value, .utilization-value, .tools-value {
						font-size: 36px;
						font-weight: bold;
						color: #2271b1;
						margin: 10px 0;
					}
					.capacity-bar {
						height: 12px;
						background: #e0e0e0;
						border-radius: 6px;
						overflow: hidden;
						margin-top: 10px;
					}
					.capacity-fill {
						height: 100%;
						background: linear-gradient(90deg, #46b450, #2271b1);
						transition: width 0.3s ease;
					}
					.top-tools-section {
						margin: 30px 0;
					}
					.utilization-good { color: #46b450; }
					.utilization-warning { color: #f0b849; }
					.utilization-critical { color: #dc3232; font-weight: bold; }
					.sla-tier {
						display: inline-block;
						padding: 3px 8px;
						border-radius: 3px;
						font-size: 11px;
						font-weight: 600;
						text-transform: uppercase;
					}
					.sla-realtime { background: #e7f5ff; color: #0c5aa7; }
					.sla-near_realtime { background: #fff3cd; color: #856404; }
					.sla-batch { background: #f0f0f0; color: #666; }
					.recommendations-section {
						margin: 30px 0;
						background: #fff3cd;
						padding: 15px;
						border-left: 4px solid #f0b849;
						border-radius: 4px;
					}
					.recommendations-list {
						margin: 10px 0 0 20px;
					}
					.recommendations-list li {
						margin: 5px 0;
					}
				</style>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				// Log error.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_warning(
						'Load monitoring rendering failed: ' . $e->getMessage(),
						array(
							'component' => 'orchestration_section',
							'method'    => 'get_load_monitoring_content',
						)
					);
				}

				return '<div class="notice notice-warning inline"><p>' . esc_html__( 'Load monitoring temporarily unavailable.', 'mcp-ai-wpoos' ) . '</p></div>';
			}
		}

		/**
		 * Get performance statistics content HTML.
		 *
		 * @return string
		 */
		private function get_performance_statistics_content() {
			try {
				// Check if Load Monitor is available.
				if ( ! class_exists( 'WP_MCP_AI_Tool_Load_Monitor' ) ) {
					return '<div class="notice notice-info inline"><p>' . esc_html__( 'Performance statistics not available.', 'mcp-ai-wpoos' ) . '</p></div>';
				}

				$monitor        = new WP_MCP_AI_Tool_Load_Monitor();
				$system_metrics = $monitor->get_system_load_metrics();

				// Get performance stats for top 5 tools.
				$top_tools = array_slice( $system_metrics['top_tools'], 0, 5, true );
				$stats     = array();

				foreach ( $top_tools as $tool_slug => $metrics ) {
					$stats[ $tool_slug ] = $monitor->get_tool_performance_stats( $tool_slug, 24 );
				}

				ob_start();
				?>
				<!-- Performance Statistics -->
				<div class="wp-mcp-ai-performance-stats">
					<h2>
						<?php esc_html_e( 'Performance Statistics', 'mcp-ai-wpoos' ); ?>
						<span class="dashicons dashicons-chart-bar" style="font-size: 24px; vertical-align: middle;"></span>
					</h2>
					<p class="description">
						<?php esc_html_e( 'P50/P95/P99 latency metrics and success rates for the last 24 hours.', 'mcp-ai-wpoos' ); ?>
					</p>

					<?php if ( ! empty( $stats ) ) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Tool', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Total Executions', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Success Rate', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Avg Duration', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'P50 Latency', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'P95 Latency', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'P99 Latency', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $stats as $tool_slug => $tool_stats ) : ?>
								<tr>
									<td><code><?php echo esc_html( $tool_slug ); ?></code></td>
									<td><?php echo esc_html( number_format( $tool_stats['total_count'] ) ); ?></td>
									<td>
										<span style="color: <?php echo esc_attr( $tool_stats['success_rate'] > 95 ? '#46b450' : ( $tool_stats['success_rate'] > 80 ? '#f0b849' : '#dc3232' ) ); ?>;">
											<?php echo esc_html( number_format( $tool_stats['success_rate'], 1 ) ); ?>%
										</span>
									</td>
									<td><?php echo esc_html( number_format( $tool_stats['avg_duration'], 3 ) ); ?>s</td>
									<td><?php echo esc_html( number_format( $tool_stats['p50_latency'], 3 ) ); ?>s</td>
									<td><?php echo esc_html( number_format( $tool_stats['p95_latency'], 3 ) ); ?>s</td>
									<td><?php echo esc_html( number_format( $tool_stats['p99_latency'], 3 ) ); ?>s</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<div class="performance-explanation" style="margin-top: 20px; padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 4px;">
						<h4 style="margin-top: 0;"><?php esc_html_e( 'Understanding Latency Metrics', 'mcp-ai-wpoos' ); ?></h4>
						<ul style="margin: 10px 0 0 20px;">
							<li><strong>P50 (Median):</strong> <?php esc_html_e( '50% of executions completed faster than this time', 'mcp-ai-wpoos' ); ?></li>
							<li><strong>P95:</strong> <?php esc_html_e( '95% of executions completed faster (acceptable user experience)', 'mcp-ai-wpoos' ); ?></li>
							<li><strong>P99:</strong> <?php esc_html_e( '99% of executions completed faster (outlier detection)', 'mcp-ai-wpoos' ); ?></li>
						</ul>
					</div>
					<?php else : ?>
					<div class="notice notice-info inline">
						<p><?php esc_html_e( 'No performance data available yet. Statistics will appear after tool executions.', 'mcp-ai-wpoos' ); ?></p>
					</div>
					<?php endif; ?>
				</div>

				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
				?>
				<style>
					.wp-mcp-ai-performance-stats {
						background: #fff;
						padding: 20px;
						margin: 20px 0;
						border: 1px solid #ccd0d4;
						box-shadow: 0 1px 1px rgba(0,0,0,.04);
					}
					.wp-mcp-ai-performance-stats table {
						margin-top: 20px;
					}
				</style>
				<?php
				return ob_get_clean();

			} catch ( Exception $e ) {
				// Log error.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_warning(
						'Performance statistics rendering failed: ' . $e->getMessage(),
						array(
							'component' => 'orchestration_section',
							'method'    => 'get_performance_statistics_content',
						)
					);
				}

				return '<div class="notice notice-warning inline"><p>' . esc_html__( 'Performance statistics temporarily unavailable.', 'mcp-ai-wpoos' ) . '</p></div>';
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
					return '<div class="notice notice-info inline"><p>' . esc_html__( 'Preset service not available.', 'mcp-ai-wpoos' ) . '</p></div>';
				}

				if ( ! class_exists( 'WP_MCP_AI_Orchestration_Renderer' ) ) {
					return '<div class="notice notice-info inline"><p>' . esc_html__( 'Renderer not available.', 'mcp-ai-wpoos' ) . '</p></div>';
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
				return '<div class="notice notice-warning inline"><p>' . esc_html__( 'Configuration presets temporarily unavailable.', 'mcp-ai-wpoos' ) . '</p></div>';
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
				$content .= '<h3>' . esc_html__( 'Current Orchestration Status', 'mcp-ai-wpoos' ) . '</h3>';

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
				$content .= '<div class="wp-mcp-ai-stats-card__label">' . esc_html__( 'Workload Tier', 'mcp-ai-wpoos' ) . '</div>';
				$content .= '<div class="wp-mcp-ai-stats-card__value">' . esc_html( $memory_tier ) . '</div>';
				$content .= '</div>';
				$content .= '</div>';

				// Max tokens - Context Window.
				$max_tokens = $resource_manager->get_max_tokens();
				$content   .= '<div class="wp-mcp-ai-stats-card wp-mcp-ai-stats-card--context-window">';
				$content   .= '<div class="wp-mcp-ai-stats-card__icon"><span class="dashicons dashicons-chart-bar"></span></div>';
				$content   .= '<div class="wp-mcp-ai-stats-card__content">';
				$content   .= '<div class="wp-mcp-ai-stats-card__label">';
				$content   .= esc_html__( 'Context Window (Max Tokens)', 'mcp-ai-wpoos' );
				$content   .= ' <span class="dashicons dashicons-info-outline wp-mcp-ai-tooltip-trigger" data-tooltip="context-window-info"></span>';
				$content   .= '</div>';
				$content   .= '<div class="wp-mcp-ai-stats-card__value">' . esc_html( number_format( $max_tokens ) ) . '</div>';
				$content   .= '<div class="wp-mcp-ai-stats-card__subtitle">' . esc_html__( 'Total Budget Per Request', 'mcp-ai-wpoos' ) . '</div>';
				$content   .= '</div>';
				$content   .= '</div>';

				// Request timeout.
				$timeout  = $resource_manager->get_request_timeout();
				$content .= '<div class="wp-mcp-ai-stats-card">';
				$content .= '<div class="wp-mcp-ai-stats-card__icon"><span class="dashicons dashicons-clock"></span></div>';
				$content .= '<div class="wp-mcp-ai-stats-card__content">';
				$content .= '<div class="wp-mcp-ai-stats-card__label">' . esc_html__( 'Request Timeout', 'mcp-ai-wpoos' ) . '</div>';
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
				$content .= '<div class="wp-mcp-ai-stats-card__label">' . esc_html__( 'Active Cron Jobs', 'mcp-ai-wpoos' ) . '</div>';
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
				$content .= '<h4>' . esc_html__( 'Quick Actions', 'mcp-ai-wpoos' ) . '</h4>';
				$content .= '<p>';
				$content .= '<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-cron-manager' ) ) . '" class="button button-secondary">' . esc_html__( 'Manage Cron Jobs', 'mcp-ai-wpoos' ) . '</a> ';
				$content .= '<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager' ) ) . '" class="button button-secondary">' . esc_html__( 'View Token Manager', 'mcp-ai-wpoos' ) . '</a> ';
				$content .= '<a href="' . esc_url( admin_url( 'tools.php?page=wp-mcp-ai-diagnostic' ) ) . '" class="button button-secondary">' . esc_html__( 'Run Diagnostics', 'mcp-ai-wpoos' ) . '</a>';
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
					esc_html__( 'Orchestration statistics temporarily unavailable. Please refresh the page or contact support if this persists.', 'mcp-ai-wpoos' )
				);
			}
		}

		/**
		 * Override render_wrapper to use custom structure without table.
		 */
		public function render_wrapper() {
			$description       = $this->get_description();
			$documentation_url = $this->get_documentation_url();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
				<?php if ( $documentation_url ) : ?>
					<p class="section-documentation">
						<span class="dashicons dashicons-book-alt" style="color: #2271b1;"></span>
						<a href="<?php echo esc_url( $documentation_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?>
							<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
						</a>
					</p>
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
			<?php esc_html_e( 'Overview', 'mcp-ai-wpoos' ); ?>
</a>
<a href="<?php echo esc_url( $this->get_view_url( 'presets' ) ); ?>" class="wp-mcp-ai-orchestration__nav-item <?php echo 'presets' === $active_view ? 'active' : ''; ?>">
<span class="dashicons dashicons-admin-generic"></span>
			<?php esc_html_e( 'Presets', 'mcp-ai-wpoos' ); ?>
</a>
<a href="<?php echo esc_url( $this->get_view_url( 'settings' ) ); ?>" class="wp-mcp-ai-orchestration__nav-item <?php echo 'settings' === $active_view ? 'active' : ''; ?>">
<span class="dashicons dashicons-admin-settings"></span>
			<?php esc_html_e( 'Settings', 'mcp-ai-wpoos' ); ?>
</a>
<a href="<?php echo esc_url( $this->get_view_url( 'thresholds' ) ); ?>" class="wp-mcp-ai-orchestration__nav-item <?php echo 'thresholds' === $active_view ? 'active' : ''; ?>">
<span class="dashicons dashicons-performance"></span>
			<?php esc_html_e( 'Thresholds', 'mcp-ai-wpoos' ); ?>
</a>
<a href="<?php echo esc_url( $this->get_view_url( 'tools' ) ); ?>" class="wp-mcp-ai-orchestration__nav-item <?php echo 'tools' === $active_view ? 'active' : ''; ?>">
<span class="dashicons dashicons-admin-tools"></span>
			<?php esc_html_e( 'Tools', 'mcp-ai-wpoos' ); ?>
</a>
			<?php
			// Conditionally show Agents tab if agent roles are enabled.
			$enable_agent_roles = WP_MCP_AI_Settings_Registry::get_setting( 'enable_agent_roles', true );
			if ( $enable_agent_roles ) :
				?>
<a href="<?php echo esc_url( $this->get_view_url( 'agents' ) ); ?>" class="wp-mcp-ai-orchestration__nav-item <?php echo 'agents' === $active_view ? 'active' : ''; ?>">
<span class="dashicons dashicons-groups"></span>
				<?php esc_html_e( 'Agents', 'mcp-ai-wpoos' ); ?>
</a>
				<?php
			endif;

			// Conditionally show Professions tab if professions are enabled.
			$enable_professions = WP_MCP_AI_Settings_Registry::get_setting( 'enable_professions', true );
			if ( $enable_professions ) :
				?>
<a href="<?php echo esc_url( $this->get_view_url( 'professions' ) ); ?>" class="wp-mcp-ai-orchestration__nav-item <?php echo 'professions' === $active_view ? 'active' : ''; ?>">
<span class="dashicons dashicons-businessperson"></span>
				<?php esc_html_e( 'Professions', 'mcp-ai-wpoos' ); ?>
</a>
				<?php
			endif;

			// Conditionally show Teams tab if multi-agent teams are enabled.
			$enable_multi_agent_teams = WP_MCP_AI_Settings_Registry::get_setting( 'enable_multi_agent_teams', true );
			if ( $enable_multi_agent_teams ) :
				?>
<a href="<?php echo esc_url( $this->get_view_url( 'teams' ) ); ?>" class="wp-mcp-ai-orchestration__nav-item <?php echo 'teams' === $active_view ? 'active' : ''; ?>">
<span class="dashicons dashicons-networking"></span>
				<?php esc_html_e( 'Teams', 'mcp-ai-wpoos' ); ?>
</a>
				<?php
			endif;
			?>
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
				case 'presets':
					$this->render_presets_view();
					break;
				case 'thresholds':
					$this->render_thresholds_view();
					break;
				case 'tools':
					$this->render_tools_view();
					break;
				case 'agents':
					// Check if agent roles are enabled.
					$enable_agent_roles = WP_MCP_AI_Settings_Registry::get_setting( 'enable_agent_roles', true );
					if ( $enable_agent_roles ) {
						$this->render_agents_view();
					} else {
						echo '<div class="notice notice-warning inline"><p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
						esc_html_e( 'Agent Roles are currently disabled. Enable them in Settings to view this dashboard.', 'mcp-ai-wpoos' );
						echo ' <a href="' . esc_url( $this->get_view_url( 'settings' ) ) . '">' . esc_html__( 'Go to Settings', 'mcp-ai-wpoos' ) . '</a>';
						echo '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
					}
					break;
				case 'professions':
					// Check if professions are enabled.
					$enable_professions = WP_MCP_AI_Settings_Registry::get_setting( 'enable_professions', true );
					if ( $enable_professions ) {
						$this->render_professions_view();
					} else {
						echo '<div class="notice notice-warning inline"><p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
						esc_html_e( 'AI Professions are currently disabled. Enable them in Settings to view this dashboard.', 'mcp-ai-wpoos' );
						echo ' <a href="' . esc_url( $this->get_view_url( 'settings' ) ) . '">' . esc_html__( 'Go to Settings', 'mcp-ai-wpoos' ) . '</a>';
						echo '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
					}
					break;
				case 'teams':
					// Check if multi-agent teams are enabled.
					$enable_multi_agent_teams = WP_MCP_AI_Settings_Registry::get_setting( 'enable_multi_agent_teams', true );
					if ( $enable_multi_agent_teams ) {
						$this->render_teams_view();
					} else {
						echo '<div class="notice notice-warning inline"><p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
						esc_html_e( 'Multi-Agent Teams are currently disabled. Enable them in Settings to view this dashboard.', 'mcp-ai-wpoos' );
						echo ' <a href="' . esc_url( $this->get_view_url( 'settings' ) ) . '">' . esc_html__( 'Go to Settings', 'mcp-ai-wpoos' ) . '</a>';
						echo '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
					}
					break;
				case 'overview':
				default:
					$this->render_overview_view();
					break;
			}
			?>
</div>
			<?php $this->render_pro_banner(); ?>
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
		 * Render overview view - Executive Dashboard.
		 *
		 * Comprehensive overview of orchestration system with real-time metrics,
		 * charts, health monitoring, and quick actions.
		 */
		private function render_overview_view() {
			$health      = $this->get_orchestration_health_metrics();
			$professions = $this->get_professions_list();
			$agent_roles = $this->get_available_agent_roles();
			$role_counts = $this->count_professions_by_role( $professions );

			// Calculate executive metrics.
			$total_tools = 0;
			foreach ( $professions as $prof ) {
				$total_tools += $prof['tools_count'];
			}

			// Team readiness check.
			$has_planner  = isset( $role_counts['planner'] ) && $role_counts['planner'] > 0;
			$has_executor = isset( $role_counts['executor'] ) && $role_counts['executor'] > 0;
			$has_critic   = isset( $role_counts['critic'] ) && $role_counts['critic'] > 0;
			$team_ready   = $has_planner && $has_executor && $has_critic;

			?>
			<div class="wp-mcp-ai-overview-dashboard">
				<!-- Executive Header -->
				<div class="wp-mcp-ai-orchestration-header executive-header">
					<div class="wp-mcp-ai-header-left">
						<h2><?php esc_html_e( 'Orchestration Layer Executive Dashboard', 'mcp-ai-wpoos' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Real-time overview of your AI orchestration system - agents, professions, tools, health, and performance metrics at a glance.', 'mcp-ai-wpoos' ); ?>
						</p>
					</div>
					<div class="wp-mcp-ai-header-right">
						<div class="status-indicators">
							<div class="status-item">
								<span class="dashicons dashicons-heart status-icon-<?php echo esc_attr( $health['memory_status'] ); ?>"></span>
								<span class="status-label"><?php esc_html_e( 'System', 'mcp-ai-wpoos' ); ?></span>
							</div>
							<div class="status-item">
								<span class="dashicons dashicons-<?php echo esc_attr( $team_ready ? 'yes-alt' : 'warning' ); ?> status-icon-<?php echo esc_attr( $team_ready ? 'good' : 'warning' ); ?>"></span>
								<span class="status-label"><?php esc_html_e( 'Teams', 'mcp-ai-wpoos' ); ?></span>
							</div>
						</div>
					</div>
				</div>

				<!-- Executive Metrics Grid -->
				<div class="executive-metrics-grid">
					<div class="executive-metric-card">
						<div class="metric-header">
							<span class="dashicons dashicons-groups"></span>
							<h4><?php esc_html_e( 'Workforce', 'mcp-ai-wpoos' ); ?></h4>
						</div>
						<div class="metric-body">
							<div class="metric-primary"><?php echo esc_html( count( $professions ) ); ?></div>
							<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'AI Professions', 'mcp-ai-wpoos' ); ?></div>
							<div class="metric-stats">
								<?php
								foreach ( $role_counts as $role => $count ) {
									if ( $count > 0 ) {
										echo '<span class="role-stat">' . esc_html( ucfirst( $role ) . ': ' . $count ) . '</span>';
									}
								}
								?>
							</div>
						</div>
						<div class="metric-footer">
							<a href="<?php echo esc_url( $this->get_view_url( 'professions' ) ); ?>" class="metric-link">
								<?php esc_html_e( 'Manage Professions', 'mcp-ai-wpoos' ); ?> →
							</a>
						</div>
					</div>

					<div class="executive-metric-card">
						<div class="metric-header">
							<span class="dashicons dashicons-admin-tools"></span>
							<h4><?php esc_html_e( 'Tools & Capabilities', 'mcp-ai-wpoos' ); ?></h4>
						</div>
						<div class="metric-body">
							<div class="metric-primary"><?php echo esc_html( $total_tools ); ?></div>
							<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Total Assignments', 'mcp-ai-wpoos' ); ?></div>
							<div class="metric-stats">
								<?php
								$avg_tools = count( $professions ) > 0 ? round( $total_tools / count( $professions ), 1 ) : 0;
								?>
								<span class="role-stat"><?php echo esc_html( sprintf( __( 'Avg per Agent: %s', 'mcp-ai-wpoos' ), $avg_tools ) ); ?></span>
							</div>
						</div>
						<div class="metric-footer">
							<a href="<?php echo esc_url( $this->get_view_url( 'tools' ) ); ?>" class="metric-link">
								<?php esc_html_e( 'View All Tools', 'mcp-ai-wpoos' ); ?> →
							</a>
						</div>
					</div>

					<div class="executive-metric-card status-<?php echo esc_attr( $health['memory_status'] ); ?>">
						<div class="metric-header">
							<span class="dashicons dashicons-performance"></span>
							<h4><?php esc_html_e( 'System Health', 'mcp-ai-wpoos' ); ?></h4>
						</div>
						<div class="metric-body">
							<div class="metric-primary"><?php echo esc_html( ucfirst( $health['memory_status'] ) ); ?></div>
							<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Overall Status', 'mcp-ai-wpoos' ); ?></div>
							<div class="metric-stats">
								<span class="role-stat"><?php echo esc_html( sprintf( __( 'Memory: %s%%', 'mcp-ai-wpoos' ), number_format( $health['memory_usage'], 1 ) ) ); ?></span>
								<span class="role-stat"><?php echo esc_html( sprintf( __( 'Errors: %s%%', 'mcp-ai-wpoos' ), number_format( $health['error_rate'], 1 ) ) ); ?></span>
							</div>
						</div>
						<div class="metric-footer">
							<a href="<?php echo esc_url( $this->get_view_url( 'thresholds' ) ); ?>" class="metric-link">
								<?php esc_html_e( 'Configure Thresholds', 'mcp-ai-wpoos' ); ?> →
							</a>
						</div>
					</div>

					<div class="executive-metric-card <?php echo esc_attr( $team_ready ? 'status-good' : 'status-warning' ); ?>">
						<div class="metric-header">
							<span class="dashicons dashicons-networking"></span>
							<h4><?php esc_html_e( 'Team Coordination', 'mcp-ai-wpoos' ); ?></h4>
						</div>
						<div class="metric-body">
							<div class="metric-primary">
								<span class="dashicons dashicons-<?php echo esc_attr( $team_ready ? 'yes-alt' : 'warning' ); ?>"></span>
							</div>
							<div class="wp-mcp-ai-metric-label">
								<?php echo $team_ready ? esc_html__( 'Teams Ready', 'mcp-ai-wpoos' ) : esc_html__( 'Setup Required', 'mcp-ai-wpoos' ); ?>
							</div>
							<div class="metric-stats">
								<?php if ( ! $team_ready ) : ?>
									<span class="role-stat warning">
										<?php
										$missing = array();
										if ( ! $has_planner ) {
											$missing[] = 'Planner';
										}
										if ( ! $has_executor ) {
											$missing[] = 'Executor';
										}
										if ( ! $has_critic ) {
											$missing[] = 'Critic';
										}
										echo esc_html( sprintf( __( 'Missing: %s', 'mcp-ai-wpoos' ), implode( ', ', $missing ) ) );
										?>
									</span>
								<?php else : ?>
									<span class="role-stat"><?php esc_html_e( 'All core roles present', 'mcp-ai-wpoos' ); ?></span>
								<?php endif; ?>
							</div>
						</div>
						<div class="metric-footer">
							<a href="<?php echo esc_url( $this->get_view_url( 'agents' ) ); ?>" class="metric-link">
								<?php esc_html_e( 'View Agent Roles', 'mcp-ai-wpoos' ); ?> →
							</a>
						</div>
					</div>
				</div>

				<!-- Analytics Charts Section -->
				<div class="orchestration-charts-section executive-charts">
					<h3>
						<span class="dashicons dashicons-chart-line"></span>
						<?php esc_html_e( 'Orchestration Analytics Overview', 'mcp-ai-wpoos' ); ?>
					</h3>
					
					<div class="charts-row">
						<div class="chart-container chart-third">
							<h5><?php esc_html_e( 'Workforce by Role', 'mcp-ai-wpoos' ); ?></h5>
							<canvas id="wp-mcp-ai-overview-workforce-chart" height="250"></canvas>
						</div>
						
						<div class="chart-container chart-third">
							<h5><?php esc_html_e( 'Tool Distribution', 'mcp-ai-wpoos' ); ?></h5>
							<canvas id="wp-mcp-ai-overview-tools-chart" height="250"></canvas>
						</div>
						
						<div class="chart-container chart-third">
							<h5><?php esc_html_e( 'System Capacity', 'mcp-ai-wpoos' ); ?></h5>
							<canvas id="wp-mcp-ai-overview-capacity-chart" height="250"></canvas>
						</div>
					</div>
				</div>

				<!-- Quick Actions Section -->
				<div class="executive-actions-section">
					<h3>
						<span class="dashicons dashicons-admin-generic"></span>
						<?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos' ); ?>
					</h3>
					
					<div class="action-cards-grid">
						<div class="action-card">
							<span class="dashicons dashicons-plus-alt action-icon"></span>
							<h4><?php esc_html_e( 'Create Profession', 'mcp-ai-wpoos' ); ?></h4>
							<p><?php esc_html_e( 'Add a new AI profession with specific role and tools', 'mcp-ai-wpoos' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-primary">
								<?php esc_html_e( 'Create New', 'mcp-ai-wpoos' ); ?>
							</a>
						</div>

						<div class="action-card">
							<span class="dashicons dashicons-groups action-icon"></span>
							<h4><?php esc_html_e( 'Build Team', 'mcp-ai-wpoos' ); ?></h4>
							<p><?php esc_html_e( 'Compose multi-agent teams for complex workflows', 'mcp-ai-wpoos' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_team' ) ); ?>" class="button button-primary">
								<?php esc_html_e( 'Build Team', 'mcp-ai-wpoos' ); ?>
							</a>
						</div>

						<div class="action-card">
							<span class="dashicons dashicons-admin-settings action-icon"></span>
							<h4><?php esc_html_e( 'Configure Settings', 'mcp-ai-wpoos' ); ?></h4>
							<p><?php esc_html_e( 'Adjust orchestration settings and thresholds', 'mcp-ai-wpoos' ); ?></p>
							<a href="<?php echo esc_url( $this->get_view_url( 'settings' ) ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Settings', 'mcp-ai-wpoos' ); ?>
							</a>
						</div>

						<div class="action-card">
							<span class="dashicons dashicons-chart-bar action-icon"></span>
							<h4><?php esc_html_e( 'View Analytics', 'mcp-ai-wpoos' ); ?></h4>
							<p><?php esc_html_e( 'Deep dive into agents and professions performance', 'mcp-ai-wpoos' ); ?></p>
							<a href="<?php echo esc_url( $this->get_view_url( 'agents' ) ); ?>" class="button button-secondary">
								<?php esc_html_e( 'View Details', 'mcp-ai-wpoos' ); ?>
							</a>
						</div>
					</div>
				</div>

				<?php
				// Show feature status notice if any multi-agent features are disabled.
				$enable_agent_roles              = WP_MCP_AI_Settings_Registry::get_setting( 'enable_agent_roles', true );
				$enable_professions              = WP_MCP_AI_Settings_Registry::get_setting( 'enable_professions', true );
				$enable_multi_agent_teams        = WP_MCP_AI_Settings_Registry::get_setting( 'enable_multi_agent_teams', true );
				$enable_agent_coordination_tools = WP_MCP_AI_Settings_Registry::get_setting( 'enable_agent_coordination_tools', true );

				$disabled_features = array();
				if ( ! $enable_agent_roles ) {
					$disabled_features[] = __( 'Agent Roles', 'mcp-ai-wpoos' );
				}
				if ( ! $enable_professions ) {
					$disabled_features[] = __( 'AI Professions', 'mcp-ai-wpoos' );
				}
				if ( ! $enable_multi_agent_teams ) {
					$disabled_features[] = __( 'Multi-Agent Teams', 'mcp-ai-wpoos' );
				}
				if ( ! $enable_agent_coordination_tools ) {
					$disabled_features[] = __( 'Agent Coordination Tools', 'mcp-ai-wpoos' );
				}

				if ( ! empty( $disabled_features ) ) :
					?>
					<div class="notice notice-info inline" style="margin: 20px 0;">
						<p>
							<span class="dashicons dashicons-info" style="vertical-align: middle; color: #2271b1;"></span>
							<strong><?php esc_html_e( 'Multi-Agent Features Status:', 'mcp-ai-wpoos' ); ?></strong>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: comma-separated list of disabled features */
									__( 'The following features are currently disabled: %s.', 'mcp-ai-wpoos' ),
									implode( ', ', $disabled_features )
								)
							);
							?>
							<a href="<?php echo esc_url( $this->get_view_url( 'settings' ) ); ?>">
								<?php esc_html_e( 'Enable them in Settings', 'mcp-ai-wpoos' ); ?> →
							</a>
						</p>
					</div>
					<?php
				endif;
				?>

				<!-- Chart Initialization -->
				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
				?>
				<script type="text/javascript">
				/* <![CDATA[ */
				jQuery(document).ready(function($) {
					if (typeof Chart === 'undefined') {
						console.warn('Chart.js not loaded - overview charts will not display');
						return;
					}
					
					var overviewChartData = {
						workforce: <?php echo wp_json_encode( $this->get_agent_role_distribution_data() ); ?>,
						tools: <?php echo wp_json_encode( $this->get_profession_tool_distribution_data() ); ?>,
						capacity: <?php echo wp_json_encode( $this->get_workload_tier_distribution_data() ); ?>
					};
					
					// Workforce Pie Chart
					var workforceCanvas = document.getElementById('wp-mcp-ai-overview-workforce-chart');
					if (workforceCanvas && overviewChartData.workforce.datasets[0].data.length > 0) {
						new Chart(workforceCanvas.getContext('2d'), {
							type: 'pie',
							data: overviewChartData.workforce,
							options: {
								responsive: true,
								maintainAspectRatio: false,
								plugins: {
									legend: {
										display: true,
										position: 'bottom',
										labels: { padding: 10, font: { size: 11 } }
									}
								}
							}
						});
					}
					
					// Tools Distribution Bar Chart
					var toolsCanvas = document.getElementById('wp-mcp-ai-overview-tools-chart');
					if (toolsCanvas) {
						new Chart(toolsCanvas.getContext('2d'), {
							type: 'bar',
							data: overviewChartData.tools,
							options: {
								responsive: true,
								maintainAspectRatio: false,
								plugins: { legend: { display: false } },
								scales: {
									y: { beginAtZero: true, ticks: { stepSize: 1 } }
								}
							}
						});
					}
					
					// Capacity Bar Chart
					var capacityCanvas = document.getElementById('wp-mcp-ai-overview-capacity-chart');
					if (capacityCanvas) {
						new Chart(capacityCanvas.getContext('2d'), {
							type: 'bar',
							data: overviewChartData.capacity,
							options: {
								responsive: true,
								maintainAspectRatio: false,
								plugins: { legend: { display: false } },
								scales: {
									y: {
										beginAtZero: true,
										ticks: {
											callback: function(value) {
												return value >= 1000 ? (value/1000) + 'K' : value;
											}
										}
									}
								}
							}
						});
					}
				});
				/* ]]> */
				</script>

				<!-- Enhanced Styling -->
				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
				?>
				<style>
				.executive-header {
					background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
					color: white;
					border: none;
					padding: 30px;
				}
				
				.executive-header .description {
					color: rgba(255,255,255,0.9);
					font-size: 14px;
				}
				
				.status-indicators {
					display: flex;
					gap: 20px;
				}
				
				.status-item {
					display: flex;
					flex-direction: column;
					align-items: center;
					gap: 5px;
				}
				
				.status-icon-good { color: #4CAF50; font-size: 32px; }
				.status-icon-warning { color: #FF9800; font-size: 32px; }
				.status-icon-critical { color: #F44336; font-size: 32px; }
				
				.status-label {
					font-size: 11px;
					text-transform: uppercase;
					opacity: 0.9;
				}
				
				.executive-metrics-grid {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
					gap: 20px;
					margin: 30px 0;
				}
				
				.executive-metric-card {
					background: white;
					border: 1px solid #ddd;
					border-radius: 8px;
					overflow: hidden;
					transition: all 0.3s;
					border-top: 4px solid #2271b1;
				}
				
				.executive-metric-card.status-warning {
					border-top-color: #FF9800;
				}
				
				.executive-metric-card.status-critical {
					border-top-color: #F44336;
				}
				
				.executive-metric-card.status-good {
					border-top-color: #4CAF50;
				}
				
				.executive-metric-card:hover {
					box-shadow: 0 8px 24px rgba(0,0,0,0.15);
					transform: translateY(-4px);
				}
				
				.metric-header {
					padding: 20px 20px 10px;
					display: flex;
					align-items: center;
					gap: 10px;
					border-bottom: 1px solid #f0f0f0;
				}
				
				.metric-header .dashicons {
					font-size: 24px;
					color: #2271b1;
				}
				
				.metric-header h4 {
					margin: 0;
					font-size: 14px;
					font-weight: 600;
					color: #1d2327;
				}
				
				.metric-body {
					padding: 20px;
					text-align: center;
				}
				
				.metric-primary {
					font-size: 48px;
					font-weight: bold;
					color: #2271b1;
					line-height: 1;
					margin-bottom: 10px;
				}
				
				.metric-primary .dashicons {
					font-size: 48px;
				}
				
				.wp-mcp-ai-metric-label {
					font-size: 13px;
					color: #666;
					margin-bottom: 15px;
					font-weight: 500;
				}
				
				.metric-stats {
					display: flex;
					flex-direction: column;
					gap: 5px;
					font-size: 12px;
					color: #999;
				}
				
				.role-stat {
					background: #f8f9fa;
					padding: 4px 8px;
					border-radius: 3px;
				}
				
				.role-stat.warning {
					background: #fff3e0;
					color: #f57c00;
				}
				
				.metric-footer {
					padding: 15px 20px;
					background: #f8f9fa;
					border-top: 1px solid #f0f0f0;
				}
				
				.metric-link {
					color: #2271b1;
					text-decoration: none;
					font-size: 13px;
					font-weight: 500;
					transition: color 0.2s;
				}
				
				.metric-link:hover {
					color: #135e96;
				}
				
				.executive-charts {
					margin: 30px 0;
				}
				
				.executive-charts h3 {
					display: flex;
					align-items: center;
					gap: 10px;
					margin-bottom: 20px;
					font-size: 18px;
				}
				
				.executive-charts .charts-row {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
					gap: 20px;
					margin-top: 20px;
				}
				
				.executive-charts .chart-container {
					background: #f8f9fa;
					border: 1px solid #e0e0e0;
					border-radius: 4px;
					padding: 15px;
					position: relative;
				}
				
				.executive-charts .chart-container canvas {
					max-height: 250px;
				}
				
				.executive-charts .chart-container.chart-third {
					min-height: 250px;
				}
				
				.executive-charts .chart-container h5 {
					margin: 0 0 10px 0;
					color: #1d2327;
					font-size: 14px;
					font-weight: 600;
				}
				
				.chart-third {
					min-width: 300px;
				}
				
				.executive-actions-section {
					margin: 30px 0;
					padding: 30px;
					background: white;
					border: 1px solid #ddd;
					border-radius: 8px;
				}
				
				.executive-actions-section h3 {
					display: flex;
					align-items: center;
					gap: 10px;
					margin: 0 0 20px 0;
					font-size: 18px;
				}
				
				.action-cards-grid {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
					gap: 20px;
				}
				
				.action-card {
					padding: 25px;
					border: 2px solid #f0f0f0;
					border-radius: 8px;
					text-align: center;
					transition: all 0.3s;
				}
				
				.action-card:hover {
					border-color: #2271b1;
					background: #f8f9fa;
				}
				
				.action-icon {
					font-size: 40px;
					color: #2271b1;
					margin-bottom: 15px;
				}
				
				.action-card h4 {
					margin: 0 0 10px 0;
					font-size: 16px;
					color: #1d2327;
				}
				
				.action-card p {
					margin: 0 0 15px 0;
					font-size: 13px;
					color: #666;
					line-height: 1.5;
				}
				
				.action-card .button {
					margin: 0;
				}
				</style>
			</div>
			<?php
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
				'section_multi_agent', // Section header.
				'enable_agent_roles',
				'enable_professions',
				'enable_multi_agent_teams',
				'enable_agent_coordination_tools',
				'profession_default_provider',
				'profession_default_model',
				'profession_default_temperature',
				'team_default_provider',
				'team_default_model',
				'team_default_temperature',
			);

			echo '<h3>' . esc_html__( 'Orchestration Features', 'mcp-ai-wpoos' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Enable or disable orchestration layer features. These settings control how the AI orchestration system manages resources, security, and task scheduling. All orchestration features work uniformly across all AI providers (OpenAI, Gemini, Anthropic, Ollama, LM Studio).', 'mcp-ai-wpoos' ) . '</p>';

			echo '<table class="form-table" role="presentation">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
			foreach ( $settings_fields as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$field = $fields[ $key ];
					if ( 'html' === $field['type'] ) {
						// Close table for section headers, render HTML, reopen table.
						echo '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
						echo $field['content'];
						echo '<table class="form-table" role="presentation">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
					} else {
						$this->render_field( $key, $field );
					}
				}
			}
			echo '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
		}

		/**
		 * Render presets view.
		 */
		private function render_presets_view() {
			$fields = $this->get_fields();

			echo '<div class="wp-mcp-ai-presets-view">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
			echo '<h3>' . esc_html__( 'Orchestration Configuration Presets', 'mcp-ai-wpoos' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Choose a preset configuration optimized for your expected usage pattern. Presets automatically configure context window limits, health monitoring thresholds, budget allocation, and predictive settings across all AI providers.', 'mcp-ai-wpoos' ) . '</p>';

			// Render presets selector.
			if ( isset( $fields['configuration_presets'] ) ) {
				echo $fields['configuration_presets']['content'];
			}

			// Render hidden field for preset tracking.
			if ( isset( $fields['orchestration_preset'] ) ) {
				$current_preset = WP_MCP_AI_Settings_Registry::get_setting( 'orchestration_preset', 'auto' );
				echo '<input type="hidden" name="wp_mcp_ai_settings[orchestration_preset]" id="orchestration_preset" value="' . esc_attr( $current_preset ) . '" />';
			}

			echo '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
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
						echo $field['content'];
					} elseif ( 'slider' === $field['type'] ) {
						// Use orchestration renderer for sliders.
						if ( class_exists( 'WP_MCP_AI_Orchestration_Renderer' ) ) {
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

			echo '<table class="form-table" role="presentation">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
			foreach ( $checkbox_fields as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$this->render_field( $key, $fields[ $key ] );
				}
			}
			echo '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
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
				'overview'    => array(
					'label'  => __( 'Overview', 'mcp-ai-wpoos' ),
					'fields' => array(
						'orchestration_intro',
						'health_status',
						'configuration_presets',
						'orchestration_stats',
					),
				),
				'settings'    => array(
					'label'  => __( 'Settings', 'mcp-ai-wpoos' ),
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
				'thresholds'  => array(
					'label'  => __( 'Thresholds', 'mcp-ai-wpoos' ),
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
				'tools'       => array(
					'label'  => __( 'Tools', 'mcp-ai-wpoos' ),
					'fields' => array(
						// Tools view is read-only, no editable fields.
					),
				),
				'agents'      => array(
					'label'  => __( 'Agents', 'mcp-ai-wpoos' ),
					'fields' => array(
						// Agents view is read-only, no editable fields.
					),
				),
				'professions' => array(
					'label'  => __( 'Professions', 'mcp-ai-wpoos' ),
					'fields' => array(
						// Professions view is read-only, no editable fields.
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
			echo WP_MCP_AI_Tools_Orchestration_Renderer::render_tools_view();
		}

		/**
		 * Render agents view.
		 *
		 * Displays all available agent roles and their orchestration capabilities.
		 * Shows DeepSeek V4-inspired multi-agent coordination features with real-time metrics.
		 */
		private function render_agents_view() {
			$agent_roles = $this->get_available_agent_roles();
			$health      = $this->get_orchestration_health_metrics();
			$professions = $this->get_professions_list();

			?>
		<div class="wp-mcp-ai-agents-view">
			<!-- Header with Real-Time Status -->
			<div class="wp-mcp-ai-orchestration-header">
				<div class="wp-mcp-ai-header-left">
					<h3><?php esc_html_e( 'Multi-Agent Orchestration Dashboard', 'mcp-ai-wpoos' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'DeepSeek V4-inspired multi-agent coordination with real-time performance monitoring and professional workforce management.', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>
				<div class="wp-mcp-ai-header-right">
					<div class="wp-mcp-ai-health-indicator wp-mcp-ai-health-<?php echo esc_attr( $health['memory_status'] ); ?>">
						<span class="dashicons dashicons-heart"></span>
						<span class="wp-mcp-ai-health-label"><?php esc_html_e( 'System Health', 'mcp-ai-wpoos' ); ?></span>
						<span class="wp-mcp-ai-health-value"><?php echo esc_html( ucfirst( $health['memory_status'] ) ); ?></span>
					</div>
				</div>
			</div>

			<!-- Key Metrics Cards -->
			<div class="wp-mcp-ai-orchestration-metrics-grid">
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-groups"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Agent Role Types', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-metric-value"><?php echo esc_html( count( $agent_roles ) ); ?></div>
						<div class="wp-mcp-ai-metric-subtitle"><?php esc_html_e( 'Available Roles', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-businessperson"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Configured Professions', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-metric-value"><?php echo esc_html( count( $professions ) ); ?></div>
						<div class="wp-mcp-ai-metric-subtitle"><?php esc_html_e( 'Deployable Agents', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-performance"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Memory Usage', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-metric-value"><?php echo esc_html( number_format( $health['memory_usage'], 1 ) ); ?>%</div>
						<div class="wp-mcp-ai-metric-subtitle"><?php esc_html_e( 'of allocated budget', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-warning"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Error Rate', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-metric-value"><?php echo esc_html( number_format( $health['error_rate'], 1 ) ); ?>%</div>
						<div class="metric-subtitle status-<?php echo esc_attr( $health['error_status'] ); ?>">
							<?php echo esc_html( ucfirst( $health['error_status'] ) ); ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Charts Section -->
			<div class="orchestration-charts-section">
				<h4>
					<span class="dashicons dashicons-chart-bar"></span>
					<?php esc_html_e( 'Orchestration Analytics', 'mcp-ai-wpoos' ); ?>
				</h4>
				
				<div class="charts-row">
					<div class="chart-container chart-half">
						<h5><?php esc_html_e( 'Profession Role Distribution', 'mcp-ai-wpoos' ); ?></h5>
						<p class="chart-description">
							<?php esc_html_e( 'Distribution of configured AI professions by their assigned agent role type.', 'mcp-ai-wpoos' ); ?>
						</p>
						<canvas id="wp-mcp-ai-agent-role-distribution-chart" height="300"></canvas>
					</div>
					
					<div class="chart-container chart-half">
						<h5><?php esc_html_e( 'Workload Tier Token Capacity', 'mcp-ai-wpoos' ); ?></h5>
						<p class="chart-description">
							<?php esc_html_e( 'Maximum token allocation per request based on system resource tier.', 'mcp-ai-wpoos' ); ?>
						</p>
						<canvas id="wp-mcp-ai-workload-tier-chart" height="300"></canvas>
					</div>
				</div>
			</div>

			<div class="notice notice-info inline" style="margin: 20px 0;">
				<p>
					<span class="dashicons dashicons-info" style="vertical-align: middle; color: #2271b1;"></span>
					<strong><?php esc_html_e( 'Multi-Agent Orchestration:', 'mcp-ai-wpoos' ); ?></strong>
					<?php esc_html_e( 'This system enables complex workflows where specialized agents collaborate - planners break down tasks, executors implement solutions, critics validate quality, and specialists handle domain-specific work.', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>

			<?php
			// Get available agent roles.
			$agent_roles = $this->get_available_agent_roles();

			if ( ! empty( $agent_roles ) ) {
				?>
				<div class="wp-mcp-ai-agents-grid">
					<?php foreach ( $agent_roles as $role ) : ?>
						<div class="wp-mcp-ai-agent-card">
							<div class="agent-card-header">
								<span class="dashicons <?php echo esc_attr( $role['icon'] ); ?>"></span>
								<h4><?php echo esc_html( $role['name'] ); ?></h4>
							</div>
							<div class="agent-card-body">
								<p class="description"><?php echo esc_html( $role['description'] ); ?></p>
								
								<?php if ( ! empty( $role['capabilities'] ) ) : ?>
									<div class="agent-capabilities">
										<strong><?php esc_html_e( 'Capabilities:', 'mcp-ai-wpoos' ); ?></strong>
										<ul>
											<?php foreach ( $role['capabilities'] as $capability ) : ?>
												<li><?php echo esc_html( $capability ); ?></li>
											<?php endforeach; ?>
										</ul>
									</div>
								<?php endif; ?>

								<?php if ( ! empty( $role['recommended_tools'] ) ) : ?>
									<div class="agent-tools">
										<strong><?php esc_html_e( 'Recommended Tools:', 'mcp-ai-wpoos' ); ?></strong>
										<div class="tool-badges">
											<?php foreach ( array_slice( $role['recommended_tools'], 0, 5 ) as $tool ) : ?>
												<span class="tool-badge"><?php echo esc_html( $tool ); ?></span>
											<?php endforeach; ?>
											<?php if ( count( $role['recommended_tools'] ) > 5 ) : ?>
												<span class="tool-badge-more">
													+<?php echo esc_html( count( $role['recommended_tools'] ) - 5 ); ?> more
												</span>
											<?php endif; ?>
										</div>
									</div>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<?php
			} else {
				?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No agent roles found. Agent roles are loaded from the agents directory.', 'mcp-ai-wpoos' ); ?></p>
				</div>
				<?php
			}
			?>

			<!-- Multi-Agent Coordination Tools -->
			<div class="wp-mcp-ai-agent-tools-section" style="margin-top: 30px;">
				<h3><?php esc_html_e( 'Multi-Agent Coordination Tools', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'The following tools enable multi-agent workflows and team coordination:', 'mcp-ai-wpoos' ); ?>
				</p>

				<div class="coordination-tools-grid">
					<?php
					$coordination_tools = array(
						array(
							'name'        => 'create_agent_team',
							'label'       => __( 'Create Agent Team', 'mcp-ai-wpoos' ),
							'description' => __( 'Compose multi-agent teams dynamically from available professions', 'mcp-ai-wpoos' ),
							'icon'        => 'dashicons-groups',
						),
						array(
							'name'        => 'delegate_to_agent',
							'label'       => __( 'Delegate to Agent', 'mcp-ai-wpoos' ),
							'description' => __( 'Delegate subtasks to specialized agents within a team', 'mcp-ai-wpoos' ),
							'icon'        => 'dashicons-networking',
						),
						array(
							'name'        => 'aggregate_agent_results',
							'label'       => __( 'Aggregate Agent Results', 'mcp-ai-wpoos' ),
							'description' => __( 'Combine outputs from multiple agents using various strategies', 'mcp-ai-wpoos' ),
							'icon'        => 'dashicons-chart-bar',
						),
					);

					foreach ( $coordination_tools as $tool ) :
						?>
						<div class="coordination-tool-card">
							<span class="<?php echo esc_attr( $tool['icon'] ); ?>" style="font-size: 32px; color: #2271b1;"></span>
							<h4><?php echo esc_html( $tool['label'] ); ?></h4>
							<p class="description"><?php echo esc_html( $tool['description'] ); ?></p>
							<code class="tool-slug"><?php echo esc_html( $tool['name'] ); ?></code>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Quick Actions -->
			<div class="wp-mcp-ai-agent-actions" style="margin-top: 30px;">
				<h4><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos' ); ?></h4>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Manage Professions', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_team' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Manage Teams', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'View All Tools', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>

			<!-- Styling -->
			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
			?>
			<style>
				.wp-mcp-ai-agents-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
					gap: 20px;
					margin: 20px 0;
				}
				
				.wp-mcp-ai-agent-card {
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					padding: 20px;
					box-shadow: 0 1px 3px rgba(0,0,0,0.1);
				}
				
				.agent-card-header {
					display: flex;
					align-items: center;
					gap: 10px;
					margin-bottom: 15px;
					padding-bottom: 15px;
					border-bottom: 1px solid #eee;
				}
				
				.agent-card-header .dashicons {
					font-size: 32px;
					width: 32px;
					height: 32px;
					color: #2271b1;
				}
				
				.agent-card-header h4 {
					margin: 0;
					color: #1d2327;
				}
				
				.agent-card-body {
					font-size: 14px;
				}
				
				.agent-capabilities,
				.agent-tools {
					margin-top: 15px;
					padding-top: 10px;
					border-top: 1px solid #f0f0f0;
				}
				
				.agent-capabilities ul {
					margin: 5px 0;
					padding-left: 20px;
				}
				
				.agent-capabilities li {
					margin: 5px 0;
					color: #666;
				}
				
				.tool-badges {
					display: flex;
					flex-wrap: wrap;
					gap: 5px;
					margin-top: 8px;
				}
				
				.tool-badge,
				.tool-badge-more {
					background: #f0f0f1;
					padding: 3px 8px;
					border-radius: 3px;
					font-size: 11px;
					color: #666;
				}
				
				.tool-badge-more {
					background: #2271b1;
					color: #fff;
				}
				
				.coordination-tools-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
					gap: 20px;
					margin: 20px 0;
				}
				
				.coordination-tool-card {
					background: #f8f9fa;
					border: 1px solid #ddd;
					border-left: 4px solid #2271b1;
					padding: 20px;
					border-radius: 4px;
					text-align: center;
				}
				
				.coordination-tool-card h4 {
					margin: 10px 0;
					color: #1d2327;
				}
				
				.coordination-tool-card .tool-slug {
					display: inline-block;
					margin-top: 10px;
					padding: 4px 8px;
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 3px;
					font-size: 12px;
					color: #d63638;
				}
				
				/* Enhanced Orchestration Styles */
				.wp-mcp-ai-orchestration-header {
					display: flex;
					justify-content: space-between;
					align-items: flex-start;
					margin-bottom: 20px;
					padding: 20px;
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					box-shadow: 0 1px 3px rgba(0,0,0,0.05);
				}
				
				.wp-mcp-ai-health-indicator {
					display: flex;
					align-items: center;
					gap: 8px;
					padding: 10px 15px;
					border-radius: 4px;
					background: #f8f9fa;
					border-left: 4px solid #4CAF50;
					font-size: 13px;
				}
				
				.wp-mcp-ai-health-indicator.health-warning {
					border-left-color: #FF9800;
				}
				
				.wp-mcp-ai-health-indicator.health-critical {
					border-left-color: #F44336;
				}
				
				.wp-mcp-ai-health-indicator .dashicons {
					font-size: 20px;
				}
				
				.wp-mcp-ai-health-indicator.health-good .dashicons {
					color: #4CAF50;
				}
				
				.wp-mcp-ai-health-indicator.health-warning .dashicons {
					color: #FF9800;
				}
				
				.wp-mcp-ai-health-indicator.health-critical .dashicons {
					color: #F44336;
				}
				
				.wp-mcp-ai-health-label {
					font-weight: 600;
					color: #666;
				}
				
				.wp-mcp-ai-health-value {
					font-weight: bold;
					color: #1d2327;
				}
				
				.wp-mcp-ai-orchestration-metrics-grid {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
					gap: 20px;
					margin: 20px 0;
				}
				
				.wp-mcp-ai-metric-card {
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					padding: 20px;
					display: flex;
					gap: 15px;
					align-items: flex-start;
					transition: all 0.2s;
				}
				
				.wp-mcp-ai-metric-card:hover {
					box-shadow: 0 4px 12px rgba(0,0,0,0.1);
					transform: translateY(-2px);
				}
				
				.wp-mcp-ai-metric-icon {
					font-size: 40px;
					color: #2271b1;
					line-height: 1;
				}
				
				.wp-mcp-ai-metric-icon .dashicons {
					width: 40px;
					height: 40px;
					font-size: 40px;
				}
				
				.wp-mcp-ai-metric-content {
					flex: 1;
				}
				
				.wp-mcp-ai-metric-label {
					font-size: 13px;
					color: #666;
					margin-bottom: 5px;
					font-weight: 500;
				}
				
				.wp-mcp-ai-metric-value {
					font-size: 32px;
					font-weight: bold;
					color: #1d2327;
					line-height: 1;
					margin-bottom: 5px;
				}
				
				.wp-mcp-ai-metric-subtitle {
					font-size: 12px;
					color: #999;
				}
				
				.wp-mcp-ai-metric-subtitle.status-good {
					color: #4CAF50;
					font-weight: 600;
				}
				
				.wp-mcp-ai-metric-subtitle.status-warning {
					color: #FF9800;
					font-weight: 600;
				}
				
				.wp-mcp-ai-metric-subtitle.status-critical {
					color: #F44336;
					font-weight: 600;
				}
				
				.orchestration-charts-section {
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					padding: 20px;
					margin: 20px 0;
					box-shadow: 0 1px 3px rgba(0,0,0,0.05);
				}
				
				.orchestration-charts-section h4 {
					display: flex;
					align-items: center;
					gap: 8px;
					margin: 0 0 15px 0;
					color: #1d2327;
					font-size: 16px;
					font-weight: 600;
				}
				
				.orchestration-charts-section h4 .dashicons {
					color: #2271b1;
				}
				
				.charts-row {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
					gap: 20px;
					margin-top: 20px;
				}
				
				.chart-container {
					background: #f8f9fa;
					border: 1px solid #e0e0e0;
					border-radius: 4px;
					padding: 15px;
					position: relative;
				}
				
				.chart-container canvas {
					max-height: 300px;
				}
				
				.chart-container.chart-half {
					min-height: 300px;
				}
				
				.chart-container.chart-third {
					min-height: 250px;
				}
				
				.chart-container h5 {
					margin: 0 0 5px 0;
					color: #1d2327;
					font-size: 14px;
					font-weight: 600;
				}
				
				.chart-description {
					font-size: 12px;
					color: #666;
					margin: 0 0 15px 0;
					line-height: 1.4;
				}
			</style>
			
			<!-- Chart.js Initialization -->
			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
			?>
			<script type="text/javascript">
			/* <![CDATA[ */
			jQuery(document).ready(function($) {
				// Only initialize if Chart.js is loaded
				if (typeof Chart === 'undefined') {
					console.warn('Chart.js not loaded - charts will not display');
					return;
				}
				
				// Chart data
				var chartData = {
					roleDistribution: <?php echo wp_json_encode( $this->get_agent_role_distribution_data() ); ?>,
					workloadTier: <?php echo wp_json_encode( $this->get_workload_tier_distribution_data() ); ?>
				};
				
				// Agent Role Distribution Pie Chart
				var roleCanvas = document.getElementById('wp-mcp-ai-agent-role-distribution-chart');
				if (roleCanvas && chartData.roleDistribution.datasets[0].data.length > 0) {
					new Chart(roleCanvas.getContext('2d'), {
						type: 'doughnut',
						data: chartData.roleDistribution,
						options: {
							responsive: true,
							maintainAspectRatio: false,
							plugins: {
								legend: {
									display: true,
									position: 'right',
									labels: {
										padding: 15,
										font: {
											size: 12
										}
									}
								},
								tooltip: {
									callbacks: {
										label: function(context) {
											var label = context.label || '';
											var value = context.parsed || 0;
											var total = context.dataset.data.reduce((a, b) => a + b, 0);
											var percentage = ((value / total) * 100).toFixed(1);
											return label + ': ' + value + ' (' + percentage + '%)';
										}
									}
								}
							}
						}
					});
				} else if (roleCanvas) {
					// Show message if no data
					roleCanvas.parentElement.innerHTML = '<p style="text-align:center;color:#999;padding:50px 0;"><?php esc_html_e( 'No profession data available. Create professions to see distribution.', 'mcp-ai-wpoos' ); ?></p>';
				}
				
				// Workload Tier Capacity Bar Chart
				var tierCanvas = document.getElementById('wp-mcp-ai-workload-tier-chart');
				if (tierCanvas) {
					new Chart(tierCanvas.getContext('2d'), {
						type: 'bar',
						data: {
							labels: chartData.workloadTier.labels,
							datasets: chartData.workloadTier.datasets
						},
						options: {
							responsive: true,
							maintainAspectRatio: false,
							plugins: {
								legend: {
									display: false
								},
								tooltip: {
									callbacks: {
										label: function(context) {
											return context.parsed.y.toLocaleString() + ' tokens';
										}
									}
								}
							},
							scales: {
								y: {
									beginAtZero: true,
									ticks: {
										callback: function(value) {
											return value.toLocaleString();
										}
									},
									title: {
										display: true,
										text: '<?php esc_html_e( 'Max Tokens per Request', 'mcp-ai-wpoos' ); ?>'
									}
								}
							}
						}
					});
				}
			});
			/* ]]> */
			</script>
		</div>
			<?php
		}

		/**
		 * Get available agent roles.
		 *
		 * @return array Array of agent role data.
		 */
		private function get_available_agent_roles() {
			$roles = array();

			// Check if agent role classes are available.
			$agent_classes = array(
				'planner'    => 'WP_MCP_AI_Agent_Role_Planner',
				'executor'   => 'WP_MCP_AI_Agent_Role_Executor',
				'critic'     => 'WP_MCP_AI_Agent_Role_Critic',
				'specialist' => 'WP_MCP_AI_Agent_Role_Base', // Generic specialist.
			);

			foreach ( $agent_classes as $role_type => $class_name ) {
				if ( class_exists( $class_name ) ) {
					try {
						if ( 'specialist' === $role_type ) {
							// Generic specialist role.
							$roles[] = array(
								'type'              => 'specialist',
								'name'              => __( 'Specialist', 'mcp-ai-wpoos' ),
								'description'       => __( 'Domain-specific expert agent that handles specialized tasks requiring deep knowledge in a particular area.', 'mcp-ai-wpoos' ),
								'icon'              => 'dashicons-lightbulb',
								'capabilities'      => array(
									__( 'Domain expertise', 'mcp-ai-wpoos' ),
									__( 'Technical accuracy', 'mcp-ai-wpoos' ),
									__( 'Specialized problem solving', 'mcp-ai-wpoos' ),
								),
								'recommended_tools' => array(),
							);
						} else {
							$role_instance = new $class_name();

							$roles[] = array(
								'type'              => $role_type,
								'name'              => $role_instance->get_role_name(),
								'description'       => $role_instance->get_role_description(),
								'icon'              => $this->get_role_icon( $role_type ),
								'capabilities'      => $this->format_capabilities( $role_instance->get_capabilities() ),
								'recommended_tools' => $role_instance->get_recommended_tools(),
							);
						}
					} catch ( Exception $e ) {
						// Skip roles that fail to instantiate.
						continue;
					}
				}
			}

			// If no agent roles loaded, provide default generic role.
			if ( empty( $roles ) ) {
				$roles[] = array(
					'type'              => 'generalist',
					'name'              => __( 'Generalist Agent', 'mcp-ai-wpoos' ),
					'description'       => __( 'General-purpose AI agent capable of handling a wide variety of tasks.', 'mcp-ai-wpoos' ),
					'icon'              => 'dashicons-admin-generic',
					'capabilities'      => array(
						__( 'Multi-domain tasks', 'mcp-ai-wpoos' ),
						__( 'Flexible problem solving', 'mcp-ai-wpoos' ),
					),
					'recommended_tools' => array(),
				);
			}

			return $roles;
		}

		/**
		 * Get icon for agent role type.
		 *
		 * @param string $role_type Role type identifier.
		 * @return string Dashicon class.
		 */
		private function get_role_icon( $role_type ) {
			$icons = array(
				'planner'    => 'dashicons-list-view',
				'executor'   => 'dashicons-hammer',
				'critic'     => 'dashicons-yes-alt',
				'specialist' => 'dashicons-lightbulb',
			);

			return isset( $icons[ $role_type ] ) ? $icons[ $role_type ] : 'dashicons-admin-generic';
		}

		/**
		 * Format capabilities for display.
		 *
		 * @param array $capabilities Raw capability flags.
		 * @return array Formatted capability descriptions.
		 */
		private function format_capabilities( $capabilities ) {
			$formatted = array();

			$capability_labels = array(
				'can-delegate'      => __( 'Can delegate tasks to other agents', 'mcp-ai-wpoos' ),
				'can-plan'          => __( 'Can create execution plans', 'mcp-ai-wpoos' ),
				'can-execute'       => __( 'Can execute tasks directly', 'mcp-ai-wpoos' ),
				'can-critique'      => __( 'Can validate and improve results', 'mcp-ai-wpoos' ),
				'requires-feedback' => __( 'Requires validation from other agents', 'mcp-ai-wpoos' ),
				'can-coordinate'    => __( 'Can coordinate team workflows', 'mcp-ai-wpoos' ),
			);

			foreach ( $capabilities as $capability ) {
				if ( isset( $capability_labels[ $capability ] ) ) {
					$formatted[] = $capability_labels[ $capability ];
				} else {
					$formatted[] = ucfirst( str_replace( array( '-', '_' ), ' ', $capability ) );
				}
			}

			return $formatted;
		}

		/**
		 * Get health icon for status.
		 *
		 * @param string $status Health status.
		 * @return string Icon name.

	/**
		 * Render professions view.
		 *
		 * Displays all configured AI professions (concrete agent instances).
		 * Shows profession details, assigned roles, tools, and expertise.
		 */
		private function render_professions_view() {
			$professions = $this->get_professions_list();
			$health      = $this->get_orchestration_health_metrics();
			$role_counts = $this->count_professions_by_role( $professions );

			?>
		<div class="wp-mcp-ai-professions-view">
			<!-- Header with Status -->
			<div class="wp-mcp-ai-orchestration-header">
				<div class="wp-mcp-ai-header-left">
					<h3><?php esc_html_e( 'AI Professions & Specialist Workforce', 'mcp-ai-wpoos' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Manage your deployable AI workforce. Professions are configured assistants with specific roles, expertise, tools, and knowledge bases ready for multi-agent coordination.', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>
				<div class="wp-mcp-ai-header-right">
					<div class="wp-mcp-ai-health-indicator wp-mcp-ai-health-<?php echo esc_attr( $health['memory_status'] ); ?>">
						<span class="dashicons dashicons-businessperson"></span>
						<span class="wp-mcp-ai-health-label"><?php esc_html_e( 'Workforce Status', 'mcp-ai-wpoos' ); ?></span>
						<span class="wp-mcp-ai-health-value"><?php echo esc_html( count( $professions ) ); ?> <?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Key Metrics Cards -->
			<div class="wp-mcp-ai-orchestration-metrics-grid">
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-groups"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Total Professions', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-metric-value"><?php echo esc_html( count( $professions ) ); ?></div>
						<div class="wp-mcp-ai-metric-subtitle"><?php esc_html_e( 'Configured Agents', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-admin-tools"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Avg Tools per Profession', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-metric-value">
							<?php
							$total_tools = 0;
							foreach ( $professions as $prof ) {
								$total_tools += $prof['tools_count'];
							}
							$avg_tools = count( $professions ) > 0 ? round( $total_tools / count( $professions ), 1 ) : 0;
							echo esc_html( $avg_tools );
							?>
						</div>
						<div class="wp-mcp-ai-metric-subtitle"><?php esc_html_e( 'Tool Assignments', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-chart-line"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Specialization Rate', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-metric-value">
							<?php
							$specialist_count = isset( $role_counts['specialist'] ) ? $role_counts['specialist'] : 0;
							$spec_rate        = count( $professions ) > 0 ? round( ( $specialist_count / count( $professions ) ) * 100 ) : 0;
							echo esc_html( $spec_rate );
							?>
							%
						</div>
						<div class="wp-mcp-ai-metric-subtitle"><?php esc_html_e( 'Domain Experts', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-networking"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Team Readiness', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-metric-value">
							<?php
							// Team ready if we have at least planner, executor, and critic
							$has_planner  = isset( $role_counts['planner'] ) && $role_counts['planner'] > 0;
							$has_executor = isset( $role_counts['executor'] ) && $role_counts['executor'] > 0;
							$has_critic   = isset( $role_counts['critic'] ) && $role_counts['critic'] > 0;
							$team_ready   = $has_planner && $has_executor && $has_critic;
							?>
							<span class="dashicons dashicons-<?php echo esc_attr( $team_ready ? 'yes-alt' : 'warning' ); ?>" style="color: <?php echo esc_attr( $team_ready ? '#4CAF50' : '#FF9800' ); ?>"></span>
						</div>
						<div class="metric-subtitle status-<?php echo esc_attr( $team_ready ? 'good' : 'warning' ); ?>">
							<?php echo $team_ready ? esc_html__( 'Ready', 'mcp-ai-wpoos' ) : esc_html__( 'Incomplete', 'mcp-ai-wpoos' ); ?>
						</div>
					</div>
				</div>
			</div>

			<div class="notice notice-info inline" style="margin: 20px 0;">
				<p>
					<span class="dashicons dashicons-info" style="vertical-align: middle; color: #2271b1;"></span>
					<strong><?php esc_html_e( 'Professions vs Agent Roles:', 'mcp-ai-wpoos' ); ?></strong>
						<?php esc_html_e( 'Agent Roles are abstract templates (Planner, Executor, etc.). Professions are concrete implementations - specific AI assistants configured for particular domains like "WordPress Developer", "SEO Consultant", or "Legal Advisor".', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>

				<?php
				if ( ! empty( $professions ) ) {
					?>
				<!-- Charts Section -->
				<div class="orchestration-charts-section">
					<h4>
						<span class="dashicons dashicons-chart-bar"></span>
						<?php esc_html_e( 'Profession Analytics', 'mcp-ai-wpoos' ); ?>
					</h4>
					
					<div class="charts-row">
						<div class="chart-container chart-half">
							<h5><?php esc_html_e( 'Role Distribution', 'mcp-ai-wpoos' ); ?></h5>
							<p class="chart-description">
								<?php esc_html_e( 'Breakdown of your AI workforce by assigned agent role type.', 'mcp-ai-wpoos' ); ?>
							</p>
							<canvas id="wp-mcp-ai-profession-role-chart" height="300"></canvas>
						</div>
						
						<div class="chart-container chart-half">
							<h5><?php esc_html_e( 'Tool Distribution', 'mcp-ai-wpoos' ); ?></h5>
							<p class="chart-description">
								<?php esc_html_e( 'Number of professions by tool count assigned to them.', 'mcp-ai-wpoos' ); ?>
							</p>
							<canvas id="wp-mcp-ai-profession-tools-chart" height="300"></canvas>
						</div>
					</div>
				</div>

				<!-- Professions Grid -->
					<?php foreach ( $professions as $profession ) : ?>
						<div class="wp-mcp-ai-profession-card">
							<div class="profession-card-header">
								<div class="profession-icon-title">
									<span class="dashicons <?php echo esc_attr( $this->get_profession_icon( $profession ) ); ?>"></span>
									<div>
										<h4><?php echo esc_html( $profession['title'] ); ?></h4>
										<div class="profession-roles">
											<?php if ( ! empty( $profession['role'] ) ) : ?>
												<span class="profession-role-badge profession-role-primary <?php echo esc_attr( $profession['role'] ); ?>">
													<?php echo esc_html( ucfirst( $profession['role'] ) ); ?>
												</span>
											<?php endif; ?>
											<?php if ( ! empty( $profession['secondary_roles'] ) ) : ?>
												<?php foreach ( $profession['secondary_roles'] as $secondary_role ) : ?>
													<span class="profession-role-badge profession-role-secondary <?php echo esc_attr( $secondary_role ); ?>">
														<?php echo esc_html( ucfirst( $secondary_role ) ); ?>
													</span>
												<?php endforeach; ?>
											<?php endif; ?>
										</div>
									</div>
								</div>
								<a href="<?php echo esc_url( $profession['edit_url'] ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'mcp-ai-wpoos' ); ?>
								</a>
							</div>
							
							<div class="profession-card-body">
								<?php if ( ! empty( $profession['description'] ) ) : ?>
									<p class="profession-description"><?php echo esc_html( wp_trim_words( $profession['description'], 20 ) ); ?></p>
								<?php endif; ?>

								<?php if ( ! empty( $profession['expertise'] ) ) : ?>
									<div class="profession-expertise">
										<strong><?php esc_html_e( 'Expertise:', 'mcp-ai-wpoos' ); ?></strong>
										<div class="expertise-tags">
											<?php foreach ( array_slice( $profession['expertise'], 0, 4 ) as $expertise ) : ?>
												<span class="expertise-tag"><?php echo esc_html( $expertise ); ?></span>
											<?php endforeach; ?>
											<?php if ( count( $profession['expertise'] ) > 4 ) : ?>
												<span class="expertise-tag-more">
													+<?php echo esc_html( count( $profession['expertise'] ) - 4 ); ?>
												</span>
											<?php endif; ?>
										</div>
									</div>
								<?php endif; ?>

								<?php if ( ! empty( $profession['tools_count'] ) ) : ?>
									<div class="profession-meta">
										<span class="dashicons dashicons-admin-tools"></span>
										<?php
										printf(
											/* translators: %d: number of tools */
											esc_html( _n( '%d tool configured', '%d tools configured', $profession['tools_count'], 'mcp-ai-wpoos' ) ),
											esc_html( $profession['tools_count'] )
										);
										?>
									</div>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
					<?php
				} else {
					?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: link to create profession */
								__( 'No professions found. %s to get started with multi-agent orchestration.', 'mcp-ai-wpoos' ),
								'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ) . '">' . esc_html__( 'Create your first profession', 'mcp-ai-wpoos' ) . '</a>'
							)
						);
						?>
					</p>
				</div>
					<?php
				}
				?>

			<!-- Profession Management Section -->
			<div class="wp-mcp-ai-profession-management" style="margin-top: 30px;">
				<h3><?php esc_html_e( 'Profession Management', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Create and configure AI professions to build your multi-agent workforce.', 'mcp-ai-wpoos' ); ?>
				</p>

				<div class="management-grid">
					<div class="management-card">
						<span class="dashicons dashicons-plus-alt" style="font-size: 48px; color: #2271b1;"></span>
						<h4><?php esc_html_e( 'Create New Profession', 'mcp-ai-wpoos' ); ?></h4>
						<p class="description"><?php esc_html_e( 'Define a new AI specialist with custom tools, expertise, and knowledge base.', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Create Profession', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>

					<div class="management-card">
						<span class="dashicons dashicons-list-view" style="font-size: 48px; color: #2271b1;"></span>
						<h4><?php esc_html_e( 'Manage All Professions', 'mcp-ai-wpoos' ); ?></h4>
						<p class="description"><?php esc_html_e( 'View, edit, and organize all configured AI professions.', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'View All', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>

					<div class="management-card">
						<span class="dashicons dashicons-groups" style="font-size: 48px; color: #2271b1;"></span>
						<h4><?php esc_html_e( 'Build Agent Teams', 'mcp-ai-wpoos' ); ?></h4>
						<p class="description"><?php esc_html_e( 'Combine professions into coordinated teams for complex workflows.', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_team' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Create Team', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>
				</div>
			</div>

			<!-- Styling -->
			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
			?>
			<style>
				/* Enhanced Orchestration Styles */
				.wp-mcp-ai-orchestration-header {
					display: flex;
					justify-content: space-between;
					align-items: flex-start;
					margin-bottom: 20px;
					padding: 20px;
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					box-shadow: 0 1px 3px rgba(0,0,0,0.05);
				}
				
				.wp-mcp-ai-health-indicator {
					display: flex;
					align-items: center;
					gap: 8px;
					padding: 10px 15px;
					border-radius: 4px;
					background: #f8f9fa;
					border-left: 4px solid #4CAF50;
					font-size: 13px;
				}
				
				.wp-mcp-ai-health-indicator.wp-mcp-ai-health-warning {
					border-left-color: #FF9800;
				}
				
				.wp-mcp-ai-health-indicator.wp-mcp-ai-health-critical {
					border-left-color: #F44336;
				}
				
				.wp-mcp-ai-health-indicator .dashicons {
					font-size: 20px;
				}
				
				.wp-mcp-ai-health-indicator.wp-mcp-ai-health-good .dashicons {
					color: #4CAF50;
				}
				
				.wp-mcp-ai-health-indicator.wp-mcp-ai-health-warning .dashicons {
					color: #FF9800;
				}
				
				.wp-mcp-ai-health-indicator.wp-mcp-ai-health-critical .dashicons {
					color: #F44336;
				}
				
				.wp-mcp-ai-health-label {
					font-weight: 600;
					color: #666;
				}
				
				.wp-mcp-ai-health-value {
					font-weight: bold;
					color: #1d2327;
				}
				
				.wp-mcp-ai-orchestration-metrics-grid {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
					gap: 20px;
					margin: 20px 0;
				}
				
				.wp-mcp-ai-metric-card {
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					padding: 20px;
					display: flex;
					gap: 15px;
					align-items: flex-start;
					transition: all 0.2s;
				}
				
				.wp-mcp-ai-metric-card:hover {
					box-shadow: 0 4px 12px rgba(0,0,0,0.1);
					transform: translateY(-2px);
				}
				
				.wp-mcp-ai-metric-icon {
					font-size: 40px;
					color: #2271b1;
					line-height: 1;
				}
				
				.wp-mcp-ai-metric-icon .dashicons {
					width: 40px;
					height: 40px;
					font-size: 40px;
				}
				
				.wp-mcp-ai-metric-content {
					flex: 1;
				}
				
				.wp-mcp-ai-metric-label {
					font-size: 13px;
					color: #666;
					margin-bottom: 5px;
					font-weight: 500;
				}
				
				.wp-mcp-ai-metric-value {
					font-size: 32px;
					font-weight: bold;
					color: #1d2327;
					line-height: 1;
					margin-bottom: 5px;
				}
				
				.wp-mcp-ai-metric-subtitle,
				.metric-subtitle {
					font-size: 12px;
					color: #999;
				}
				
				.wp-mcp-ai-metric-subtitle.status-good,
				.metric-subtitle.status-good {
					color: #4CAF50;
					font-weight: 600;
				}
				
				.wp-mcp-ai-metric-subtitle.status-warning,
				.metric-subtitle.status-warning {
					color: #FF9800;
					font-weight: 600;
				}
				
				.wp-mcp-ai-metric-subtitle.status-critical,
				.metric-subtitle.status-critical {
					color: #F44336;
					font-weight: 600;
				}
				
				.orchestration-charts-section {
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					padding: 20px;
					margin: 20px 0;
					box-shadow: 0 1px 3px rgba(0,0,0,0.05);
				}
				
				.orchestration-charts-section h4 {
					display: flex;
					align-items: center;
					gap: 8px;
					margin: 0 0 15px 0;
					color: #1d2327;
					font-size: 16px;
					font-weight: 600;
				}
				
				.orchestration-charts-section h4 .dashicons {
					color: #2271b1;
				}
				
				/* Professions Specific Styles */
				.professions-stats {
					background: #f8f9fa;
					padding: 15px 20px;
					border-radius: 4px;
					border-left: 4px solid #2271b1;
				}
				
				.stats-summary {
					display: flex;
					flex-wrap: wrap;
					gap: 20px;
				}
				
				.stat-item {
					font-size: 14px;
					color: #666;
				}
				
				.stat-item strong {
					color: #2271b1;
					font-size: 18px;
					margin-right: 5px;
				}
				
				.charts-row {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
					gap: 20px;
					margin-top: 20px;
				}
				
				.chart-container {
					background: #f8f9fa;
					border: 1px solid #e0e0e0;
					border-radius: 4px;
					padding: 15px;
					position: relative;
				}
				
				.chart-container canvas {
					max-height: 300px;
				}
				
				.chart-container.chart-half {
					min-height: 300px;
				}
				
				.chart-container.chart-third {
					min-height: 250px;
				}
				
				.chart-container h5 {
					margin: 0 0 5px 0;
					color: #1d2327;
					font-size: 14px;
					font-weight: 600;
				}
				
				.chart-description {
					font-size: 12px;
					color: #666;
					margin: 0 0 15px 0;
				}
				
				.wp-mcp-ai-professions-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
					gap: 20px;
					margin: 20px 0;
				}
				
				.wp-mcp-ai-profession-card {
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					padding: 20px;
					box-shadow: 0 1px 3px rgba(0,0,0,0.1);
					transition: box-shadow 0.2s;
				}
				
				.wp-mcp-ai-profession-card:hover {
					box-shadow: 0 2px 8px rgba(0,0,0,0.15);
				}
				
				.profession-card-header {
					display: flex;
					justify-content: space-between;
					align-items: flex-start;
					margin-bottom: 15px;
					padding-bottom: 15px;
					border-bottom: 1px solid #eee;
				}
				
				.profession-icon-title {
					display: flex;
					align-items: flex-start;
					gap: 12px;
					flex: 1;
				}
				
				.profession-icon-title .dashicons {
					font-size: 32px;
					width: 32px;
					height: 32px;
					color: #2271b1;
					flex-shrink: 0;
				}
				
				.profession-card-header h4 {
					margin: 0 0 5px 0;
					color: #1d2327;
					font-size: 16px;
				}
				
				.profession-roles {
					display: flex;
					flex-wrap: wrap;
					gap: 4px;
					margin-top: 5px;
				}
				
				.profession-role-badge {
					display: inline-block;
					padding: 2px 8px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
					text-transform: uppercase;
				}
				
				.profession-role-badge.profession-role-primary {
					font-size: 12px;
					padding: 3px 10px;
					font-weight: 700;
					border: 2px solid transparent;
				}
				
				.profession-role-badge.profession-role-secondary {
					font-size: 10px;
					padding: 2px 6px;
					opacity: 0.85;
				}
				
				.profession-role-badge.planner {
					background: #e3f2fd;
					color: #1976d2;
				}
				
				.profession-role-badge.profession-role-primary.planner {
					border-color: #1976d2;
				}
				
				.profession-role-badge.executor {
					background: #fff3e0;
					color: #f57c00;
				}
				
				.profession-role-badge.profession-role-primary.executor {
					border-color: #f57c00;
				}
				
				.profession-role-badge.critic {
					background: #f3e5f5;
					color: #7b1fa2;
				}
				
				.profession-role-badge.profession-role-primary.critic {
					border-color: #7b1fa2;
				}
				
				.profession-role-badge.specialist {
					background: #e8f5e9;
					color: #388e3c;
				}
				
				.profession-role-badge.profession-role-primary.specialist {
					border-color: #388e3c;
				}
				
				.profession-role-badge.generalist {
					background: #f5f5f5;
					color: #616161;
				}
				
				.profession-role-badge.profession-role-primary.generalist {
					border-color: #616161;
				}
				
				.profession-card-body {
					font-size: 14px;
				}
				
				.profession-description {
					margin: 0 0 15px 0;
					color: #666;
					line-height: 1.6;
				}
				
				.profession-expertise {
					margin: 15px 0;
					padding-top: 10px;
					border-top: 1px solid #f0f0f0;
				}
				
				.expertise-tags {
					display: flex;
					flex-wrap: wrap;
					gap: 5px;
					margin-top: 8px;
				}
				
				.expertise-tag,
				.expertise-tag-more {
					background: #f0f0f1;
					padding: 3px 10px;
					border-radius: 3px;
					font-size: 12px;
					color: #666;
				}
				
				.expertise-tag-more {
					background: #2271b1;
					color: #fff;
					font-weight: 600;
				}
				
				.profession-meta {
					margin-top: 10px;
					padding-top: 10px;
					border-top: 1px solid #f0f0f0;
					color: #666;
					font-size: 13px;
					display: flex;
					align-items: center;
					gap: 5px;
				}
				
				.profession-meta .dashicons {
					font-size: 16px;
					width: 16px;
					height: 16px;
				}
				
				.management-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
					gap: 20px;
					margin: 20px 0;
				}
				
				.management-card {
					background: #fff;
					border: 2px solid #ddd;
					padding: 30px 20px;
					border-radius: 4px;
					text-align: center;
					transition: all 0.2s;
				}
				
				.management-card:hover {
					border-color: #2271b1;
					box-shadow: 0 2px 8px rgba(34, 113, 177, 0.1);
				}
				
				.management-card h4 {
					margin: 15px 0 10px;
					color: #1d2327;
				}
				
				.management-card .description {
					min-height: 40px;
					margin-bottom: 15px;
				}
			</style>
			
			<!-- Chart.js Initialization for Professions -->
			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
			?>
			<script type="text/javascript">
			/* <![CDATA[ */
			jQuery(document).ready(function($) {
				// Only initialize if Chart.js is loaded
				if (typeof Chart === 'undefined') {
					console.warn('Chart.js not loaded - profession charts will not display');
					return;
				}
				
				// Chart data
				var professionChartData = {
					roleDistribution: <?php echo wp_json_encode( $this->get_agent_role_distribution_data() ); ?>,
					toolDistribution: <?php echo wp_json_encode( $this->get_profession_tool_distribution_data() ); ?>
				};
				
				// Profession Role Distribution Doughnut Chart
				var profRoleCanvas = document.getElementById('wp-mcp-ai-profession-role-chart');
				if (profRoleCanvas && professionChartData.roleDistribution.datasets[0].data.length > 0) {
					new Chart(profRoleCanvas.getContext('2d'), {
						type: 'doughnut',
						data: professionChartData.roleDistribution,
						options: {
							responsive: true,
							maintainAspectRatio: false,
							plugins: {
								legend: {
									display: true,
									position: 'right',
									labels: {
										padding: 15,
										font: {
											size: 12
										}
									}
								},
								tooltip: {
									callbacks: {
										label: function(context) {
											var label = context.label || '';
											var value = context.parsed || 0;
											var total = context.dataset.data.reduce((a, b) => a + b, 0);
											var percentage = ((value / total) * 100).toFixed(1);
											return label + ': ' + value + ' (' + percentage + '%)';
										}
									}
								}
							}
						}
					});
				} else if (profRoleCanvas) {
					profRoleCanvas.parentElement.innerHTML = '<p style="text-align:center;color:#999;padding:50px 0;"><?php esc_html_e( 'No profession data available.', 'mcp-ai-wpoos' ); ?></p>';
				}
				
				// Tool Distribution Bar Chart
				var toolCanvas = document.getElementById('wp-mcp-ai-profession-tools-chart');
				if (toolCanvas) {
					new Chart(toolCanvas.getContext('2d'), {
						type: 'bar',
						data: professionChartData.toolDistribution,
						options: {
							responsive: true,
							maintainAspectRatio: false,
							plugins: {
								legend: {
									display: false
								},
								tooltip: {
									callbacks: {
										label: function(context) {
											return context.parsed.y + ' professions';
										}
									}
								}
							},
							scales: {
								y: {
									beginAtZero: true,
									ticks: {
										stepSize: 1,
										callback: function(value) {
											return Number.isInteger(value) ? value : '';
										}
									},
									title: {
										display: true,
										text: '<?php esc_html_e( 'Number of Professions', 'mcp-ai-wpoos' ); ?>'
									}
								},
								x: {
									title: {
										display: true,
										text: '<?php esc_html_e( 'Tools Assigned', 'mcp-ai-wpoos' ); ?>'
									}
								}
							}
						}
					});
				}
			});
			/* ]]> */
			</script>
		</div>
			<?php
		}

		/**
		 * Render teams view.
		 *
		 * Displays all configured AI teams (groups of professions).
		 * Shows team details, assigned professions, orchestration mode, and workflow.
		 */
		private function render_teams_view() {
			$teams  = $this->get_teams_list();
			$health = $this->get_orchestration_health_metrics();

			?>
		<div class="wp-mcp-ai-teams-view">
			<!-- Header with Status -->
			<div class="wp-mcp-ai-orchestration-header">
				<div class="wp-mcp-ai-header-left">
					<h3><?php esc_html_e( 'AI Teams & Multi-Agent Coordination', 'mcp-ai-wpoos' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Manage teams of AI professions that work together on complex tasks. Teams coordinate agents with different roles for collaborative problem-solving and workflow orchestration.', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>
				<div class="wp-mcp-ai-header-right">
					<div class="wp-mcp-ai-health-indicator wp-mcp-ai-health-<?php echo esc_attr( $health['memory_status'] ); ?>">
						<span class="dashicons dashicons-networking"></span>
						<span class="wp-mcp-ai-health-label"><?php esc_html_e( 'Teams Status', 'mcp-ai-wpoos' ); ?></span>
						<span class="wp-mcp-ai-health-value"><?php echo esc_html( count( $teams ) ); ?> <?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Key Metrics Cards -->
			<div class="wp-mcp-ai-orchestration-metrics-grid">
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-networking"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Total Teams', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-metric-value"><?php echo esc_html( count( $teams ) ); ?></div>
						<div class="wp-mcp-ai-metric-subtitle"><?php esc_html_e( 'Configured Teams', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-groups"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Avg Members per Team', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-metric-value">
							<?php
							$total_members = 0;
							foreach ( $teams as $team ) {
								$total_members += $team['member_count'];
							}
							$avg_members = count( $teams ) > 0 ? round( $total_members / count( $teams ), 1 ) : 0;
							echo esc_html( $avg_members );
							?>
						</div>
						<div class="wp-mcp-ai-metric-subtitle"><?php esc_html_e( 'Team Size', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-admin-settings"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-label">
							<?php esc_html_e( 'Orchestration Modes', 'mcp-ai-wpoos' ); ?>
							<span class="dashicons dashicons-info-outline" style="font-size: 14px; color: #666; cursor: help;" 
								title="<?php esc_attr_e( 'Available modes: Single (1 agent), Sequential (pipeline), Parallel (simultaneous), Swarm (consensus)', 'mcp-ai-wpoos' ); ?>"></span>
						</div>
						<div class="wp-mcp-ai-metric-value">
							<?php
							// Count orchestration modes used by teams.
							$mode_counts = array();
							foreach ( $teams as $team ) {
								if ( ! empty( $team['orchestration_mode'] ) ) {
									$mode = $team['orchestration_mode'];
									if ( ! isset( $mode_counts[ $mode ] ) ) {
										$mode_counts[ $mode ] = 0;
									}
									++$mode_counts[ $mode ];
								}
							}
							$unique_modes_used = count( $mode_counts );
							$total_available_modes = 4; // single, sequential, parallel, swarm.
							echo esc_html( $unique_modes_used . '/' . $total_available_modes );
							?>
						</div>
						<div class="wp-mcp-ai-metric-subtitle">
							<?php
							if ( ! empty( $mode_counts ) ) {
								$mode_labels = array(
									'single'     => __( 'Single', 'mcp-ai-wpoos' ),
									'sequential' => __( 'Sequential', 'mcp-ai-wpoos' ),
									'parallel'   => __( 'Parallel', 'mcp-ai-wpoos' ),
									'swarm'      => __( 'Swarm', 'mcp-ai-wpoos' ),
								);
								$mode_display = array();
								foreach ( $mode_counts as $mode => $count ) {
									$label = isset( $mode_labels[ $mode ] ) ? $mode_labels[ $mode ] : ucfirst( $mode );
									$mode_display[] = sprintf( '%s (%d)', $label, $count );
								}
								echo esc_html( implode( ', ', $mode_display ) );
							} else {
								esc_html_e( 'No modes configured', 'mcp-ai-wpoos' );
							}
							?>
						</div>
					</div>
				</div>
				
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-yes-alt"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Team Readiness', 'mcp-ai-wpoos' ); ?></div>
						<div class="wp-mcp-ai-metric-value">
							<?php
							$ready_teams = 0;
							foreach ( $teams as $team ) {
								if ( $team['member_count'] >= 2 ) {
									++$ready_teams;
								}
							}
							echo esc_html( $ready_teams . '/' . count( $teams ) );
							?>
						</div>
						<div class="wp-mcp-ai-metric-subtitle"><?php esc_html_e( 'Ready for Deployment', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
			</div>

				<?php if ( ! empty( $teams ) ) : ?>
				<!-- Teams Grid -->
				<div class="wp-mcp-ai-teams-grid">
					<?php foreach ( $teams as $team ) : ?>
						<div class="wp-mcp-ai-team-card">
							<div class="team-card-header">
								<div class="team-icon-title">
									<span class="dashicons dashicons-networking"></span>
									<div>
										<h4><?php echo esc_html( $team['title'] ); ?></h4>
										<?php if ( ! empty( $team['orchestration_mode'] ) ) : ?>
											<span class="team-mode-badge <?php echo esc_attr( $team['orchestration_mode'] ); ?>">
												<?php echo esc_html( ucfirst( $team['orchestration_mode'] ) ); ?>
											</span>
										<?php endif; ?>
									</div>
								</div>
								<a href="<?php echo esc_url( $team['edit_url'] ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'mcp-ai-wpoos' ); ?>
								</a>
							</div>
							
							<div class="team-card-body">
								<?php if ( ! empty( $team['description'] ) ) : ?>
									<p class="team-description"><?php echo esc_html( wp_trim_words( $team['description'], 20 ) ); ?></p>
								<?php endif; ?>

								<div class="team-meta">
									<span class="dashicons dashicons-groups"></span>
									<?php
									printf(
										/* translators: %d: number of team members */
										esc_html( _n( '%d member', '%d members', $team['member_count'], 'mcp-ai-wpoos' ) ),
										esc_html( $team['member_count'] )
									);
									?>
								</div>
								
								<?php if ( ! empty( $team['role_composition'] ) ) : ?>
									<div class="team-roles">
										<strong><?php esc_html_e( 'Agent Roles:', 'mcp-ai-wpoos' ); ?></strong>
										<div class="team-role-badges">
											<?php foreach ( $team['role_composition'] as $role => $count ) : ?>
												<span class="team-role-badge <?php echo esc_attr( $role ); ?>">
													<?php echo esc_html( ucfirst( $role ) ); ?> (<?php echo esc_html( $count ); ?>)
												</span>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endif; ?>
								
								<?php if ( ! empty( $team['result_aggregation'] ) ) : ?>
									<div class="team-aggregation">
										<strong><?php esc_html_e( 'Result Aggregation:', 'mcp-ai-wpoos' ); ?></strong>
										<?php echo esc_html( ucfirst( str_replace( '_', ' ', $team['result_aggregation'] ) ) ); ?>
									</div>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: link to create team */
								__( 'No teams found. %s to start coordinating multiple AI professions for complex workflows.', 'mcp-ai-wpoos' ),
								'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_team' ) ) . '">' . esc_html__( 'Create your first team', 'mcp-ai-wpoos' ) . '</a>'
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<!-- Team Management Section -->
			<div class="wp-mcp-ai-team-management" style="margin-top: 30px;">
				<h3><?php esc_html_e( 'Team Management', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
						<?php esc_html_e( 'Create and configure AI teams to enable multi-agent collaboration.', 'mcp-ai-wpoos' ); ?>
				</p>

				<div class="management-grid">
					<div class="management-card">
						<span class="dashicons dashicons-plus-alt" style="font-size: 48px; color: #2271b1;"></span>
						<h4><?php esc_html_e( 'Create New Team', 'mcp-ai-wpoos' ); ?></h4>
						<p class="description"><?php esc_html_e( 'Build a new multi-agent team with coordinated professions and workflow.', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_team' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Create Team', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>

					<div class="management-card">
						<span class="dashicons dashicons-list-view" style="font-size: 48px; color: #2271b1;"></span>
						<h4><?php esc_html_e( 'Manage All Teams', 'mcp-ai-wpoos' ); ?></h4>
						<p class="description"><?php esc_html_e( 'View, edit, and organize all configured AI teams.', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_team' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'View All', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>

					<div class="management-card">
						<span class="dashicons dashicons-businessperson" style="font-size: 48px; color: #2271b1;"></span>
						<h4><?php esc_html_e( 'Manage Professions', 'mcp-ai-wpoos' ); ?></h4>
						<p class="description"><?php esc_html_e( 'Configure professions that can be assigned to teams.', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( $this->get_view_url( 'professions' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'View Professions', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>
				</div>
			</div>

			<!-- Styling -->
			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
			?>
			<style>
				/* Enhanced Orchestration Styles */
				.wp-mcp-ai-orchestration-header {
					display: flex;
					justify-content: space-between;
					align-items: flex-start;
					margin-bottom: 20px;
					padding: 20px;
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					box-shadow: 0 1px 3px rgba(0,0,0,0.05);
				}
				
				.wp-mcp-ai-health-indicator {
					display: flex;
					align-items: center;
					gap: 8px;
					padding: 10px 15px;
					border-radius: 4px;
					background: #f8f9fa;
					border-left: 4px solid #4CAF50;
					font-size: 13px;
				}
				
				.wp-mcp-ai-health-indicator.wp-mcp-ai-health-warning {
					border-left-color: #FF9800;
				}
				
				.wp-mcp-ai-health-indicator.wp-mcp-ai-health-critical {
					border-left-color: #F44336;
				}
				
				.wp-mcp-ai-health-indicator .dashicons {
					font-size: 20px;
				}
				
				.wp-mcp-ai-health-indicator.wp-mcp-ai-health-good .dashicons {
					color: #4CAF50;
				}
				
				.wp-mcp-ai-health-indicator.wp-mcp-ai-health-warning .dashicons {
					color: #FF9800;
				}
				
				.wp-mcp-ai-health-indicator.wp-mcp-ai-health-critical .dashicons {
					color: #F44336;
				}
				
				.wp-mcp-ai-health-label {
					font-weight: 600;
					color: #666;
				}
				
				.wp-mcp-ai-health-value {
					font-weight: bold;
					color: #1d2327;
				}
				
				.wp-mcp-ai-orchestration-metrics-grid {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
					gap: 20px;
					margin: 20px 0;
				}
				
				.wp-mcp-ai-metric-card {
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					padding: 20px;
					display: flex;
					gap: 15px;
					align-items: flex-start;
					transition: all 0.2s;
				}
				
				.wp-mcp-ai-metric-card:hover {
					box-shadow: 0 4px 12px rgba(0,0,0,0.1);
					transform: translateY(-2px);
				}
				
				.wp-mcp-ai-metric-icon {
					font-size: 40px;
					color: #2271b1;
					line-height: 1;
				}
				
				.wp-mcp-ai-metric-icon .dashicons {
					width: 40px;
					height: 40px;
					font-size: 40px;
				}
				
				.wp-mcp-ai-metric-content {
					flex: 1;
				}
				
				.wp-mcp-ai-metric-label {
					font-size: 13px;
					color: #666;
					margin-bottom: 5px;
					font-weight: 500;
				}
				
				.wp-mcp-ai-metric-value {
					font-size: 32px;
					font-weight: bold;
					color: #1d2327;
					line-height: 1;
					margin-bottom: 5px;
				}
				
				.wp-mcp-ai-metric-subtitle {
					font-size: 12px;
					color: #999;
				}
				
				.wp-mcp-ai-metric-subtitle.status-good {
					color: #4CAF50;
					font-weight: 600;
				}
				
				.wp-mcp-ai-metric-subtitle.status-warning {
					color: #FF9800;
					font-weight: 600;
				}
				
				.wp-mcp-ai-metric-subtitle.status-critical {
					color: #F44336;
					font-weight: 600;
				}
				
				/* Teams Specific Styles */
				.wp-mcp-ai-teams-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
					gap: 20px;
					margin: 20px 0;
				}
				
				.wp-mcp-ai-team-card {
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					padding: 20px;
					box-shadow: 0 1px 3px rgba(0,0,0,0.1);
					transition: box-shadow 0.2s;
				}
				
				.wp-mcp-ai-team-card:hover {
					box-shadow: 0 2px 8px rgba(0,0,0,0.15);
				}
				
				.team-card-header {
					display: flex;
					justify-content: space-between;
					align-items: flex-start;
					margin-bottom: 15px;
					padding-bottom: 15px;
					border-bottom: 1px solid #eee;
				}
				
				.team-icon-title {
					display: flex;
					align-items: flex-start;
					gap: 12px;
					flex: 1;
				}
				
				.team-icon-title .dashicons {
					font-size: 32px;
					width: 32px;
					height: 32px;
					color: #2271b1;
					flex-shrink: 0;
				}
				
				.team-card-header h4 {
					margin: 0 0 5px 0;
					color: #1d2327;
					font-size: 16px;
				}
				
				.team-mode-badge {
					display: inline-block;
					padding: 2px 8px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
					text-transform: uppercase;
				}
				
				.team-mode-badge.sequential {
					background: #e3f2fd;
					color: #1976d2;
				}
				
				.team-mode-badge.parallel {
					background: #fff3e0;
					color: #f57c00;
				}
				
				.team-mode-badge.swarm {
					background: #f3e5f5;
					color: #7b1fa2;
				}
				
				.team-mode-badge.single {
					background: #e8f5e9;
					color: #388e3c;
				}
				
				.team-card-body {
					font-size: 14px;
				}
				
				.team-description {
					margin: 0 0 15px 0;
					color: #666;
					line-height: 1.6;
				}
				
				.team-meta {
					margin-top: 10px;
					padding-top: 10px;
					border-top: 1px solid #f0f0f0;
					color: #666;
					font-size: 13px;
					display: flex;
					align-items: center;
					gap: 5px;
				}
				
				.team-meta .dashicons {
					font-size: 16px;
					width: 16px;
					height: 16px;
				}
				
				.team-roles {
					margin-top: 10px;
					padding: 8px;
					background: #f9f9f9;
					border-radius: 3px;
					font-size: 12px;
				}
				
				.team-role-badges {
					display: flex;
					flex-wrap: wrap;
					gap: 4px;
					margin-top: 5px;
				}
				
				.team-role-badge {
					display: inline-block;
					padding: 2px 6px;
					border-radius: 3px;
					font-size: 10px;
					font-weight: 600;
					text-transform: uppercase;
				}
				
				.team-role-badge.planner {
					background: #e3f2fd;
					color: #1976d2;
				}
				
				.team-role-badge.executor {
					background: #fff3e0;
					color: #f57c00;
				}
				
				.team-role-badge.critic {
					background: #f3e5f5;
					color: #7b1fa2;
				}
				
				.team-role-badge.specialist {
					background: #e8f5e9;
					color: #388e3c;
				}
				
				.team-role-badge.generalist {
					background: #f5f5f5;
					color: #616161;
				}
				
				.team-aggregation {
					margin-top: 10px;
					padding: 8px;
					background: #f9f9f9;
					border-radius: 3px;
					font-size: 12px;
				}
			</style>
		</div>
			<?php
		}

		/**
		 * Get list of teams.
		 *
		 * @return array Array of team data.
		 */
		private function get_teams_list() {
			$teams = array();

			$query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_team',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			if ( $query->have_posts() ) {
				while ( $query->have_posts() ) {
					$query->the_post();
					$post_id = get_the_ID();

					// Get team metadata.
					$members            = get_post_meta( $post_id, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );
					$description        = get_post_meta( $post_id, WP_MCP_AI_Team_CPT::META_TEAM_DESCRIPTION, true );
					$orchestration_mode = get_post_meta( $post_id, WP_MCP_AI_Team_CPT::META_ORCHESTRATION_MODE, true );
					$result_aggregation = get_post_meta( $post_id, WP_MCP_AI_Team_CPT::META_RESULT_AGGREGATION_STRATEGY, true );

					// Get agent role composition from team members.
					$role_composition = array();
					if ( is_array( $members ) && ! empty( $members ) ) {
						foreach ( $members as $member_id ) {
							$primary_role = get_post_meta( $member_id, WP_MCP_AI_Profession_CPT::META_AGENT_ROLE, true );
							if ( ! empty( $primary_role ) ) {
								$normalized = strtolower( trim( $primary_role ) );
								if ( ! isset( $role_composition[ $normalized ] ) ) {
									$role_composition[ $normalized ] = 0;
								}
								++$role_composition[ $normalized ];
							}
						}
					}

					$teams[] = array(
						'id'                 => $post_id,
						'title'              => get_the_title(),
						'description'        => $description ? $description : get_the_excerpt(),
						'member_count'       => is_array( $members ) ? count( $members ) : 0,
						'orchestration_mode' => $orchestration_mode ? $orchestration_mode : 'sequential',
						'result_aggregation' => $result_aggregation ? $result_aggregation : '',
						'role_composition'   => $role_composition,
						'edit_url'           => get_edit_post_link( $post_id ),
					);
				}
				wp_reset_postdata();
			}

			return $teams;
		}

		/**
		 * Get list of professions.
		 *
		 * @return array Array of profession data.
		 */
		private function get_professions_list() {
			$professions = array();

			$query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_profession',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			if ( $query->have_posts() ) {
				while ( $query->have_posts() ) {
					$query->the_post();
					$post_id = get_the_ID();

					// Get profession metadata.
					$agent_role      = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_AGENT_ROLE, true );
					$secondary_roles = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_AGENT_SECONDARY_ROLES, true );
					$expertise       = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true );
					$tools           = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true );
					$description     = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_ROLE_DESCRIPTION, true );

					// Normalize role to lowercase for consistency.
					$normalized_role = $agent_role ? strtolower( trim( $agent_role ) ) : 'generalist';

					// Parse secondary roles (stored as JSON array).
					$secondary_roles_array = array();
					if ( ! empty( $secondary_roles ) ) {
						$decoded = is_string( $secondary_roles ) ? json_decode( $secondary_roles, true ) : $secondary_roles;
						// Check for JSON errors.
						if ( is_string( $secondary_roles ) && json_last_error() !== JSON_ERROR_NONE ) {
							$decoded = array();
						}
						if ( is_array( $decoded ) ) {
							$secondary_roles_array = array_map( 'strtolower', array_map( 'trim', $decoded ) );
						}
					}

					$professions[] = array(
						'id'              => $post_id,
						'title'           => get_the_title(),
						'description'     => $description ? $description : get_the_excerpt(),
						'role'            => $normalized_role,
						'secondary_roles' => $secondary_roles_array,
						'expertise'       => is_array( $expertise ) ? $expertise : array(),
						'tools_count'     => is_array( $tools ) ? count( $tools ) : 0,
						'edit_url'        => get_edit_post_link( $post_id ),
					);
				}
				wp_reset_postdata();
			}

			return $professions;
		}

		/**
		 * Count professions by role.
		 *
		 * @param array $professions Array of profession data.
		 * @return array Role counts.
		 */
		private function count_professions_by_role( $professions ) {
			$counts = array(
				'planner'    => 0,
				'executor'   => 0,
				'critic'     => 0,
				'specialist' => 0,
				'generalist' => 0,
			);

			foreach ( $professions as $profession ) {
				$role = isset( $profession['role'] ) ? $profession['role'] : 'generalist';
				if ( isset( $counts[ $role ] ) ) {
					++$counts[ $role ];
				}
			}

			return $counts;
		}

		/**
		 * Get icon for profession based on role.
		 *
		 * @param array $profession Profession data.
		 * @return string Dashicon class.
		 */
		private function get_profession_icon( $profession ) {
			$role = isset( $profession['role'] ) ? $profession['role'] : 'generalist';

			$icons = array(
				'planner'    => 'dashicons-list-view',
				'executor'   => 'dashicons-hammer',
				'critic'     => 'dashicons-yes-alt',
				'specialist' => 'dashicons-lightbulb',
				'generalist' => 'dashicons-admin-generic',
			);

			return isset( $icons[ $role ] ) ? $icons[ $role ] : 'dashicons-businessperson';
		}

		/**
		 * Get agent role distribution data for pie chart.
		 *
		 * @return array Chart.js formatted data.
		 */
		private function get_agent_role_distribution_data() {
			$professions = $this->get_professions_list();
			$role_counts = $this->count_professions_by_role( $professions );

			$labels = array();
			$data   = array();
			$colors = array(
				'rgba(33, 150, 243, 0.8)',  // Blue - Planner.
				'rgba(255, 152, 0, 0.8)',   // Orange - Executor.
				'rgba(156, 39, 176, 0.8)',  // Purple - Critic.
				'rgba(76, 175, 80, 0.8)',   // Green - Specialist.
				'rgba(158, 158, 158, 0.8)', // Gray - Generalist.
			);

			$role_labels = array(
				'planner'    => __( 'Planners', 'mcp-ai-wpoos' ),
				'executor'   => __( 'Executors', 'mcp-ai-wpoos' ),
				'critic'     => __( 'Critics', 'mcp-ai-wpoos' ),
				'specialist' => __( 'Specialists', 'mcp-ai-wpoos' ),
				'generalist' => __( 'Generalists', 'mcp-ai-wpoos' ),
			);

			$chart_colors = array();
			foreach ( $role_counts as $role => $count ) {
				if ( $count > 0 ) {
					$labels[]       = isset( $role_labels[ $role ] ) ? $role_labels[ $role ] : ucfirst( $role );
					$data[]         = $count;
					$chart_colors[] = array_shift( $colors );
				}
			}

			return array(
				'labels'   => $labels,
				'datasets' => array(
					array(
						'data'            => $data,
						'backgroundColor' => $chart_colors,
						'borderWidth'     => 1,
					),
				),
			);
		}

		/**
		 * Get orchestration health metrics data.
		 *
		 * @return array Health metrics.
		 */
		private function get_orchestration_health_metrics() {
			$health = array(
				'memory_usage'      => 0,
				'memory_status'     => 'good',
				'error_rate'        => 0,
				'error_status'      => 'good',
				'active_jobs'       => 0,
				'queue_depth'       => 0,
				'avg_response_time' => 0,
				'sla_compliance'    => 100,
			);

			// Get memory usage.
			if ( class_exists( 'WP_MCP_AI_Resource_Manager' ) ) {
				$resource_manager       = WP_MCP_AI_Resource_Manager::instance();
				$memory_limit           = $resource_manager->get_memory_limit();
				$memory_usage           = memory_get_usage();
				$health['memory_usage'] = ( $memory_usage / $memory_limit ) * 100;

				$memory_warning  = WP_MCP_AI_Settings_Registry::get_setting( 'memory_warning_threshold', 70 );
				$memory_critical = WP_MCP_AI_Settings_Registry::get_setting( 'memory_critical_threshold', 85 );

				if ( $health['memory_usage'] >= $memory_critical ) {
					$health['memory_status'] = 'critical';
				} elseif ( $health['memory_usage'] >= $memory_warning ) {
					$health['memory_status'] = 'warning';
				}
			}

			// Get active cron jobs.
			if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
				$cached_count          = WP_MCP_AI_Cache_Helper::get( 'active_cron_count' );
				$health['active_jobs'] = $cached_count !== false ? $cached_count : 0;
			}

			// Get error rate from recent logs.
			$recent_errors   = get_option( 'wp_mcp_ai_recent_errors', array() );
			$recent_activity = get_option( 'wp_mcp_ai_recent_activity', array() );

			$total_events = count( $recent_activity );
			$total_errors = count( $recent_errors );

			if ( $total_events > 0 ) {
				$health['error_rate'] = ( $total_errors / $total_events ) * 100;

				$error_warning  = WP_MCP_AI_Settings_Registry::get_setting( 'error_rate_warning_threshold', 5 );
				$error_critical = WP_MCP_AI_Settings_Registry::get_setting( 'error_rate_critical_threshold', 10 );

				if ( $health['error_rate'] >= $error_critical ) {
					$health['error_status'] = 'critical';
				} elseif ( $health['error_rate'] >= $error_warning ) {
					$health['error_status'] = 'warning';
				}
			}

			return $health;
		}

		/**
		 * Get profession tool distribution data.
		 *
		 * @return array Chart.js formatted data.
		 */
		private function get_profession_tool_distribution_data() {
			$professions = $this->get_professions_list();

			// Group professions by tool count ranges.
			$ranges = array(
				'0'    => 0,
				'1-3'  => 0,
				'4-6'  => 0,
				'7-10' => 0,
				'10+'  => 0,
			);

			foreach ( $professions as $profession ) {
				$tool_count = $profession['tools_count'];

				if ( 0 === $tool_count ) {
					++$ranges['0'];
				} elseif ( $tool_count >= 1 && $tool_count <= 3 ) {
					++$ranges['1-3'];
				} elseif ( $tool_count >= 4 && $tool_count <= 6 ) {
					++$ranges['4-6'];
				} elseif ( $tool_count >= 7 && $tool_count <= 10 ) {
					++$ranges['7-10'];
				} else {
					++$ranges['10+'];
				}
			}

			return array(
				'labels'   => array(
					__( 'No Tools', 'mcp-ai-wpoos' ),
					__( '1-3 Tools', 'mcp-ai-wpoos' ),
					__( '4-6 Tools', 'mcp-ai-wpoos' ),
					__( '7-10 Tools', 'mcp-ai-wpoos' ),
					__( '10+ Tools', 'mcp-ai-wpoos' ),
				),
				'datasets' => array(
					array(
						'label'           => __( 'Profession Count', 'mcp-ai-wpoos' ),
						'data'            => array_values( $ranges ),
						'backgroundColor' => array(
							'rgba(158, 158, 158, 0.8)', // Gray - No tools.
							'rgba(255, 193, 7, 0.8)',   // Amber - Few tools.
							'rgba(33, 150, 243, 0.8)',  // Blue - Moderate.
							'rgba(76, 175, 80, 0.8)',   // Green - Good.
							'rgba(156, 39, 176, 0.8)',  // Purple - Many.
						),
						'borderWidth'     => 1,
					),
				),
			);
		}

		/**
		 * Get workload tier distribution data.
		 *
		 * @return array Chart.js formatted data.
		 */
		private function get_workload_tier_distribution_data() {
			// Determine current tier.
			if ( class_exists( 'WP_MCP_AI_Resource_Manager' ) ) {
				$resource_manager = WP_MCP_AI_Resource_Manager::instance();
				$memory_limit     = $resource_manager->get_memory_limit();

				$low_threshold    = 128 * 1024 * 1024;    // 128MB.
				$medium_threshold = 512 * 1024 * 1024; // 512MB.

				if ( $memory_limit < $low_threshold ) {
					$current_tier = 'low';
				} elseif ( $memory_limit < $medium_threshold ) {
					$current_tier = 'medium';
				} else {
					$current_tier = 'high';
				}

				// Get token limits for each tier.
				$low_tokens    = WP_MCP_AI_Settings_Registry::get_setting( 'low_tier_max_tokens', 2000 );
				$medium_tokens = WP_MCP_AI_Settings_Registry::get_setting( 'medium_tier_max_tokens', 8000 );
				$high_tokens   = WP_MCP_AI_Settings_Registry::get_setting( 'high_tier_max_tokens', 32000 );

				return array(
					'current_tier' => $current_tier,
					'labels'       => array(
						__( 'Low Tier', 'mcp-ai-wpoos' ),
						__( 'Medium Tier', 'mcp-ai-wpoos' ),
						__( 'High Tier', 'mcp-ai-wpoos' ),
					),
					'tokens'       => array( $low_tokens, $medium_tokens, $high_tokens ),
					'datasets'     => array(
						array(
							'label'           => __( 'Token Capacity', 'mcp-ai-wpoos' ),
							'data'            => array( $low_tokens, $medium_tokens, $high_tokens ),
							'backgroundColor' => array(
								'rgba(255, 193, 7, 0.8)',  // Amber - Low.
								'rgba(33, 150, 243, 0.8)', // Blue - Medium.
								'rgba(76, 175, 80, 0.8)',  // Green - High.
							),
							'borderWidth'     => 1,
						),
					),
				);
			}

			return array(
				'current_tier' => 'medium',
				'labels'       => array( __( 'Low', 'mcp-ai-wpoos' ), __( 'Medium', 'mcp-ai-wpoos' ), __( 'High', 'mcp-ai-wpoos' ) ),
				'tokens'       => array( 2000, 8000, 32000 ),
				'datasets'     => array(
					array(
						'data'            => array( 2000, 8000, 32000 ),
						'backgroundColor' => array( '#FFC107', '#2196F3', '#4CAF50' ),
					),
				),
			);
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

		/**
		 * Render Pro addon promotional banner for base version.
		 */
		private function render_pro_banner() {
			if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
				return;
			}
			?>
			<div style="padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa; margin: 20px 0;">
				<p style="margin: 0 0 10px 0; font-size: 14px;">
					<strong><?php esc_html_e( 'Get NV oOS Pro for Premium Features', 'mcp-ai-wpoos' ); ?></strong>
				</p>
				<p style="margin: 0 0 10px 0;">
					<?php
					echo wp_kses_post(
						__(
							'Enable AI assistants to automatically install themes, plugins, update options, and create content. More powerful features available in the Pro addon.',
							'mcp-ai-wpoos'
						)
					);
					?>
				</p>
				<p style="margin: 0;">
					<a href="https://link.nvdigital.solutions/wpoos-pro-buy" target="_blank" class="button button-primary" style="margin-right: 10px;">
						<?php esc_html_e( 'Get NV oOS Pro', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="https://link.nvdigital.solutions/wpoos-pro-info" target="_blank" class="button">
						<?php esc_html_e( 'Learn More About Pro Tools', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}
}
