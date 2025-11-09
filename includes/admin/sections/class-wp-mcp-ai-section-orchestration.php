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
				'orchestration_intro'            => array(
					'type'    => 'html',
					'content' => $this->get_intro_content(),
				),
				'orchestration_presets'          => array(
					'type'    => 'html',
					'content' => $this->get_presets_selector(),
				),
				'enable_budget_management'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Dynamic Budget Management', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable dynamic budget management', 'wp-mcp-ai' ),
					'description'    => __( 'Automatically allocate and adjust token budgets based on system resources and workload tier.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'enable_predictive_optimization' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Predictive Optimization', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable predictive optimization', 'wp-mcp-ai' ),
					'description'    => __( 'Use historical usage patterns to forecast and prevent resource exhaustion.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'enable_capability_gating'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Capability-Based Tool Gating', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable capability-based tool gating', 'wp-mcp-ai' ),
					'description'    => __( 'Enforce WordPress capability checks for tool access based on user roles.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'enable_cron_orchestration'      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Cron-Based Task Orchestration', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable cron-based task orchestration', 'wp-mcp-ai' ),
					'description'    => __( 'Allow AI agents to create and manage scheduled background tasks with inherited budget constraints.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'orchestration_divider_health'   => array(
					'type'    => 'html',
					'content' => '<h3 style="margin-top:2rem;border-top:1px solid #ddd;padding-top:1.5rem;">' . esc_html__( 'Health Monitoring Thresholds', 'wp-mcp-ai' ) . '</h3>',
				),
				'memory_warning_threshold'       => array(
					'type'        => 'range',
					'label'       => __( 'Memory Warning Threshold', 'wp-mcp-ai' ),
					'description' => __( 'System health shows warning when memory usage exceeds this percentage.', 'wp-mcp-ai' ),
					'min'         => 50,
					'max'         => 95,
					'step'        => 5,
					'unit'        => '%',
					'default'     => 75,
				),
				'memory_critical_threshold'      => array(
					'type'        => 'range',
					'label'       => __( 'Memory Critical Threshold', 'wp-mcp-ai' ),
					'description' => __( 'System health becomes critical when memory usage exceeds this percentage.', 'wp-mcp-ai' ),
					'min'         => 75,
					'max'         => 99,
					'step'        => 1,
					'unit'        => '%',
					'default'     => 90,
				),
				'error_rate_warning_threshold'   => array(
					'type'        => 'range',
					'label'       => __( 'Error Rate Warning Threshold', 'wp-mcp-ai' ),
					'description' => __( 'System health shows warning when error rate exceeds this percentage (last hour).', 'wp-mcp-ai' ),
					'min'         => 5,
					'max'         => 25,
					'step'        => 1,
					'unit'        => '%',
					'default'     => 10,
				),
				'error_rate_critical_threshold'  => array(
					'type'        => 'range',
					'label'       => __( 'Error Rate Critical Threshold', 'wp-mcp-ai' ),
					'description' => __( 'System health becomes critical when error rate exceeds this percentage (last hour).', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 50,
					'step'        => 5,
					'unit'        => '%',
					'default'     => 20,
				),
				'orchestration_divider_budget'   => array(
					'type'    => 'html',
					'content' => '<h3 style="margin-top:2rem;border-top:1px solid #ddd;padding-top:1.5rem;">' . esc_html__( 'Adaptive Budget Allocation', 'wp-mcp-ai' ) . '</h3>',
				),
				'budget_high_priority_percent'   => array(
					'type'        => 'range',
					'label'       => __( 'High Priority Budget Allocation', 'wp-mcp-ai' ),
					'description' => __( 'Percentage of max tokens allocated to high priority requests.', 'wp-mcp-ai' ),
					'min'         => 50,
					'max'         => 100,
					'step'        => 5,
					'unit'        => '%',
					'default'     => 100,
				),
				'budget_medium_priority_percent' => array(
					'type'        => 'range',
					'label'       => __( 'Medium Priority Budget Allocation', 'wp-mcp-ai' ),
					'description' => __( 'Percentage of max tokens allocated to medium priority requests.', 'wp-mcp-ai' ),
					'min'         => 30,
					'max'         => 100,
					'step'        => 5,
					'unit'        => '%',
					'default'     => 80,
				),
				'budget_low_priority_percent'    => array(
					'type'        => 'range',
					'label'       => __( 'Low Priority Budget Allocation', 'wp-mcp-ai' ),
					'description' => __( 'Percentage of max tokens allocated to low priority requests.', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 80,
					'step'        => 5,
					'unit'        => '%',
					'default'     => 50,
				),
				'budget_critical_health_percent' => array(
					'type'        => 'range',
					'label'       => __( 'Critical Health Budget Reduction', 'wp-mcp-ai' ),
					'description' => __( 'Reduce token budget to this percentage when system health is critical.', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 80,
					'step'        => 5,
					'unit'        => '%',
					'default'     => 50,
				),
				'budget_warning_health_percent'  => array(
					'type'        => 'range',
					'label'       => __( 'Warning Health Budget Reduction', 'wp-mcp-ai' ),
					'description' => __( 'Reduce token budget to this percentage when system health shows warning.', 'wp-mcp-ai' ),
					'min'         => 50,
					'max'         => 100,
					'step'        => 5,
					'unit'        => '%',
					'default'     => 75,
				),
				'orchestration_divider_tokens'   => array(
					'type'    => 'html',
					'content' => '<h3 style="margin-top:2rem;border-top:1px solid #ddd;padding-top:1.5rem;">' . esc_html__( 'Token Limits by Tier', 'wp-mcp-ai' ) . '</h3>',
				),
				'max_tokens_low_tier'            => array(
					'type'        => 'range',
					'label'       => __( 'Low Tier Max Tokens', 'wp-mcp-ai' ),
					'description' => __( 'Maximum tokens for low tier servers (< 128MB memory).', 'wp-mcp-ai' ),
					'min'         => 500,
					'max'         => 5000,
					'step'        => 100,
					'unit'        => ' tokens',
					'default'     => 1000,
				),
				'max_tokens_medium_tier'         => array(
					'type'        => 'range',
					'label'       => __( 'Medium Tier Max Tokens', 'wp-mcp-ai' ),
					'description' => __( 'Maximum tokens for medium tier servers (128MB - 512MB memory).', 'wp-mcp-ai' ),
					'min'         => 2000,
					'max'         => 10000,
					'step'        => 500,
					'unit'        => ' tokens',
					'default'     => 4000,
				),
				'max_tokens_high_tier'           => array(
					'type'        => 'range',
					'label'       => __( 'High Tier Max Tokens', 'wp-mcp-ai' ),
					'description' => __( 'Maximum tokens for high tier servers (> 512MB memory).', 'wp-mcp-ai' ),
					'min'         => 8000,
					'max'         => 32000,
					'step'        => 1000,
					'unit'        => ' tokens',
					'default'     => 16000,
				),
				'orchestration_divider_predict'  => array(
					'type'    => 'html',
					'content' => '<h3 style="margin-top:2rem;border-top:1px solid #ddd;padding-top:1.5rem;">' . esc_html__( 'Predictive Analytics', 'wp-mcp-ai' ) . '</h3>',
				),
				'prediction_confidence_threshold' => array(
					'type'        => 'range',
					'label'       => __( 'Prediction Display Confidence Threshold', 'wp-mcp-ai' ),
					'description' => __( 'Only show predictions when confidence exceeds this percentage.', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 90,
					'step'        => 5,
					'unit'        => '%',
					'default'     => 30,
				),
				'prediction_buffer_percent'      => array(
					'type'        => 'range',
					'label'       => __( 'Prediction Safety Buffer', 'wp-mcp-ai' ),
					'description' => __( 'Add this percentage as safety buffer to predicted resource needs.', 'wp-mcp-ai' ),
					'min'         => 10,
					'max'         => 50,
					'step'        => 5,
					'unit'        => '%',
					'default'     => 20,
				),
				'orchestration_stats'            => array(
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
			$doc_path = WP_MCP_AI_PATH . 'docs/ORCHESTRATION-LAYER-ARCHITECTURE.md';
			$doc_exists = file_exists( $doc_path );
			
			$content = '<div class="wp-mcp-ai-orchestration-intro" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1.5rem; margin: 1rem 0;">';
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
 * Get configuration presets for quick setup.
 *
 * @return array Preset configurations.
 */
private function get_presets() {
return array(
'custom'            => array(
'name'        => __( 'Custom', 'wp-mcp-ai' ),
'description' => __( 'Your current customized settings - manually adjust individual parameters below', 'wp-mcp-ai' ),
'values'      => array(), // Empty - uses current settings.
),
'auto'              => array(
'name'        => __( 'Auto (Recommended)', 'wp-mcp-ai' ),
'description' => __( 'Automatically detects server capabilities and applies optimal configuration', 'wp-mcp-ai' ),
'values'      => 'auto', // Special value to trigger auto-detection.
),
'default'           => array(
'name'        => __( 'Balanced (Default)', 'wp-mcp-ai' ),
'description' => __( 'Recommended for most production sites with moderate traffic', 'wp-mcp-ai' ),
'values'      => array(
'memory_warning_threshold'        => 75,
'memory_critical_threshold'       => 90,
'error_rate_warning_threshold'    => 10,
'error_rate_critical_threshold'   => 20,
'budget_high_priority_percent'    => 100,
'budget_medium_priority_percent'  => 80,
'budget_low_priority_percent'     => 50,
'budget_critical_health_percent'  => 50,
'budget_warning_health_percent'   => 75,
'max_tokens_low_tier'             => 1000,
'max_tokens_medium_tier'          => 4000,
'max_tokens_high_tier'            => 16000,
'prediction_confidence_threshold' => 30,
'prediction_buffer_percent'       => 20,
),
),
'conservative'      => array(
'name'        => __( 'Conservative', 'wp-mcp-ai' ),
'description' => __( 'Strict limits for resource-constrained environments or shared hosting', 'wp-mcp-ai' ),
'values'      => array(
'memory_warning_threshold'        => 60,
'memory_critical_threshold'       => 80,
'error_rate_warning_threshold'    => 5,
'error_rate_critical_threshold'   => 15,
'budget_high_priority_percent'    => 80,
'budget_medium_priority_percent'  => 60,
'budget_low_priority_percent'     => 30,
'budget_critical_health_percent'  => 30,
'budget_warning_health_percent'   => 60,
'max_tokens_low_tier'             => 500,
'max_tokens_medium_tier'          => 2000,
'max_tokens_high_tier'            => 8000,
'prediction_confidence_threshold' => 50,
'prediction_buffer_percent'       => 30,
),
),
'aggressive'        => array(
'name'        => __( 'Aggressive', 'wp-mcp-ai' ),
'description' => __( 'Maximum performance for dedicated servers with ample resources', 'wp-mcp-ai' ),
'values'      => array(
'memory_warning_threshold'        => 85,
'memory_critical_threshold'       => 95,
'error_rate_warning_threshold'    => 15,
'error_rate_critical_threshold'   => 30,
'budget_high_priority_percent'    => 100,
'budget_medium_priority_percent'  => 100,
'budget_low_priority_percent'     => 80,
'budget_critical_health_percent'  => 70,
'budget_warning_health_percent'   => 90,
'max_tokens_low_tier'             => 2000,
'max_tokens_medium_tier'          => 8000,
'max_tokens_high_tier'            => 32000,
'prediction_confidence_threshold' => 20,
'prediction_buffer_percent'       => 10,
),
),
'development'       => array(
'name'        => __( 'Development', 'wp-mcp-ai' ),
'description' => __( 'Relaxed limits for development and testing environments', 'wp-mcp-ai' ),
'values'      => array(
'memory_warning_threshold'        => 90,
'memory_critical_threshold'       => 98,
'error_rate_warning_threshold'    => 20,
'error_rate_critical_threshold'   => 40,
'budget_high_priority_percent'    => 100,
'budget_medium_priority_percent'  => 100,
'budget_low_priority_percent'     => 80,
'budget_critical_health_percent'  => 80,
'budget_warning_health_percent'   => 100,
'max_tokens_low_tier'             => 2000,
'max_tokens_medium_tier'          => 6000,
'max_tokens_high_tier'            => 24000,
'prediction_confidence_threshold' => 10,
'prediction_buffer_percent'       => 10,
),
),
'high_traffic'      => array(
'name'        => __( 'High Traffic', 'wp-mcp-ai' ),
'description' => __( 'Optimized for high-volume sites with predictable load patterns', 'wp-mcp-ai' ),
'values'      => array(
'memory_warning_threshold'        => 70,
'memory_critical_threshold'       => 85,
'error_rate_warning_threshold'    => 8,
'error_rate_critical_threshold'   => 18,
'budget_high_priority_percent'    => 90,
'budget_medium_priority_percent'  => 70,
'budget_low_priority_percent'     => 40,
'budget_critical_health_percent'  => 40,
'budget_warning_health_percent'   => 70,
'max_tokens_low_tier'             => 800,
'max_tokens_medium_tier'          => 3000,
'max_tokens_high_tier'            => 12000,
'prediction_confidence_threshold' => 40,
'prediction_buffer_percent'       => 25,
),
),
'burst_workload'    => array(
'name'        => __( 'Burst Workload', 'wp-mcp-ai' ),
'description' => __( 'Handles sudden spikes with dynamic resource allocation', 'wp-mcp-ai' ),
'values'      => array(
'memory_warning_threshold'        => 80,
'memory_critical_threshold'       => 92,
'error_rate_warning_threshold'    => 12,
'error_rate_critical_threshold'   => 25,
'budget_high_priority_percent'    => 100,
'budget_medium_priority_percent'  => 85,
'budget_low_priority_percent'     => 60,
'budget_critical_health_percent'  => 60,
'budget_warning_health_percent'   => 80,
'max_tokens_low_tier'             => 1500,
'max_tokens_medium_tier'          => 6000,
'max_tokens_high_tier'            => 20000,
'prediction_confidence_threshold' => 25,
'prediction_buffer_percent'       => 30,
),
),
'cost_optimized'    => array(
'name'        => __( 'Cost Optimized', 'wp-mcp-ai' ),
'description' => __( 'Minimizes API token usage while maintaining functionality', 'wp-mcp-ai' ),
'values'      => array(
'memory_warning_threshold'        => 65,
'memory_critical_threshold'       => 85,
'error_rate_warning_threshold'    => 7,
'error_rate_critical_threshold'   => 15,
'budget_high_priority_percent'    => 75,
'budget_medium_priority_percent'  => 50,
'budget_low_priority_percent'     => 25,
'budget_critical_health_percent'  => 25,
'budget_warning_health_percent'   => 50,
'max_tokens_low_tier'             => 600,
'max_tokens_medium_tier'          => 2500,
'max_tokens_high_tier'            => 10000,
'prediction_confidence_threshold' => 60,
'prediction_buffer_percent'       => 15,
),
),
'enterprise'        => array(
'name'        => __( 'Enterprise', 'wp-mcp-ai' ),
'description' => __( 'Fine-tuned for enterprise deployments with SLA requirements', 'wp-mcp-ai' ),
'values'      => array(
'memory_warning_threshold'        => 70,
'memory_critical_threshold'       => 88,
'error_rate_warning_threshold'    => 5,
'error_rate_critical_threshold'   => 12,
'budget_high_priority_percent'    => 100,
'budget_medium_priority_percent'  => 75,
'budget_low_priority_percent'     => 50,
'budget_critical_health_percent'  => 45,
'budget_warning_health_percent'   => 70,
'max_tokens_low_tier'             => 1200,
'max_tokens_medium_tier'          => 5000,
'max_tokens_high_tier'            => 18000,
'prediction_confidence_threshold' => 35,
'prediction_buffer_percent'       => 22,
),
),
'failsafe'          => array(
'name'        => __( 'Failsafe', 'wp-mcp-ai' ),
'description' => __( 'Maximum protection against resource exhaustion and cascading failures', 'wp-mcp-ai' ),
'values'      => array(
'memory_warning_threshold'        => 55,
'memory_critical_threshold'       => 75,
'error_rate_warning_threshold'    => 5,
'error_rate_critical_threshold'   => 10,
'budget_high_priority_percent'    => 70,
'budget_medium_priority_percent'  => 50,
'budget_low_priority_percent'     => 25,
'budget_critical_health_percent'  => 20,
'budget_warning_health_percent'   => 50,
'max_tokens_low_tier'             => 500,
'max_tokens_medium_tier'          => 2000,
'max_tokens_high_tier'            => 8000,
'prediction_confidence_threshold' => 70,
'prediction_buffer_percent'       => 40,
),
),
'predictive_first'  => array(
'name'        => __( 'Predictive-First', 'wp-mcp-ai' ),
'description' => __( 'Emphasizes machine learning predictions for proactive resource management', 'wp-mcp-ai' ),
'values'      => array(
'memory_warning_threshold'        => 75,
'memory_critical_threshold'       => 90,
'error_rate_warning_threshold'    => 10,
'error_rate_critical_threshold'   => 20,
'budget_high_priority_percent'    => 95,
'budget_medium_priority_percent'  => 75,
'budget_low_priority_percent'     => 50,
'budget_critical_health_percent'  => 50,
'budget_warning_health_percent'   => 75,
'max_tokens_low_tier'             => 1000,
'max_tokens_medium_tier'          => 4000,
'max_tokens_high_tier'            => 16000,
'prediction_confidence_threshold' => 15,
'prediction_buffer_percent'       => 35,
),
),
);
}

/**
 * Get presets selector HTML.
 *
 * @return string
 */
private function get_presets_selector() {
$presets = $this->get_presets();

$content  = '<div class="wp-mcp-ai-preset-selector" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 1.5rem; margin: 1rem 0;">';
$content .= '<h3 style="margin-top: 0;">' . esc_html__( 'Quick Configuration Presets', 'wp-mcp-ai' ) . '</h3>';
$content .= '<p>' . esc_html__( 'Start with a pre-configured template optimized for common scenarios. You can fine-tune individual settings after applying a preset.', 'wp-mcp-ai' ) . '</p>';

$content .= '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; margin: 1.5rem 0;">';

foreach ( $presets as $preset_key => $preset ) {
$is_custom = 'custom' === $preset_key;
$is_auto = 'auto' === $preset_key;
$border_color = $is_custom ? '#2271b1' : ( $is_auto ? '#00a32a' : '#dcdcde' );
$content   .= '<div class="preset-card" style="border: 2px solid ' . $border_color . '; border-radius: 4px; padding: 1rem; cursor: pointer; transition: all 0.2s;" onclick="wpMcpAiApplyPreset(\'' . esc_js( $preset_key ) . '\')">';
$content   .= '<h4 style="margin: 0 0 0.5rem 0; color: #2271b1;">' . esc_html( $preset['name'] ) . '</h4>';
$content   .= '<p style="margin: 0; font-size: 0.9em; color: #646970;">' . esc_html( $preset['description'] ) . '</p>';
if ( $is_custom ) {
$content .= '<span style="display: inline-block; margin-top: 0.5rem; padding: 0.25rem 0.5rem; background: #2271b1; color: #fff; font-size: 0.75em; border-radius: 3px;">' . esc_html__( 'DEFAULT', 'wp-mcp-ai' ) . '</span>';
} elseif ( $is_auto ) {
$content .= '<span style="display: inline-block; margin-top: 0.5rem; padding: 0.25rem 0.5rem; background: #00a32a; color: #fff; font-size: 0.75em; border-radius: 3px;">' . esc_html__( 'RECOMMENDED', 'wp-mcp-ai' ) . '</span>';
}
$content .= '</div>';
}

$content .= '</div>';

// Add JavaScript for preset application.
$content .= '<script>';
$content .= 'var wpMcpAiPresets = ' . wp_json_encode( $presets ) . ';';
$content .= 'function wpMcpAiApplyPreset(presetKey) {';
$content .= '  if (!wpMcpAiPresets[presetKey]) return;';
$content .= '  var preset = wpMcpAiPresets[presetKey];';
$content .= '  var values = preset.values;';
$content .= '  if (confirm("Apply preset: " + preset.name + "?\\n\\n" + preset.description + "\\n\\nThis will update all orchestration settings.")) {';
$content .= '    for (var key in values) {';
$content .= '      var input = document.getElementById(key);';
$content .= '      if (input) {';
$content .= '        input.value = values[key];';
$content .= '        var display = document.getElementById(key + "_display");';
$content .= '        if (display) display.textContent = values[key];';
$content .= '        var val = document.getElementById(key + "_val");';
$content .= '        if (val) val.textContent = values[key];';
$content .= '      }';
$content .= '    }';
$content .= '    alert("Preset applied! Click \'Save Changes\' at the bottom to persist these settings.");';
$content .= '  }';
$content .= '}';
$content .= '</script>';

$content .= '<style>';
$content .= '.preset-card:hover { border-color: #2271b1; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transform: translateY(-2px); }';
$content .= '.preset-card h4 { font-size: 1em; }';
$content .= '</style>';

$content .= '</div>';

return $content;
}

/**
 * Get statistics content HTML.
 *
 * @return string
 */
private function get_stats_content() {
// Get resource manager instance.
$resource_manager = WP_MCP_AI_Resource_Manager::instance();
$health_status    = $resource_manager->get_health_status();

$content = '<div class="wp-mcp-ai-orchestration-stats" style="margin: 1.5rem 0;">';

// Health Status Banner.
$health_class = 'healthy' === $health_status['overall_health'] ? 'success' : ( 'warning' === $health_status['overall_health'] ? 'warning' : 'error' );
$health_icon  = 'healthy' === $health_status['overall_health'] ? 'yes-alt' : ( 'warning' === $health_status['overall_health'] ? 'warning' : 'dismiss' );

$content .= '<div style="background: #f0f6fc; border-left: 4px solid ' . ( 'healthy' === $health_status['overall_health'] ? '#00a32a' : ( 'warning' === $health_status['overall_health'] ? '#dba617' : '#d63638' ) ) . '; padding: 1rem; margin-bottom: 1.5rem; border-radius: 4px;">';
$content .= '<div style="display: flex; align-items: center; gap: 0.5rem;">';
$content .= '<span class="dashicons dashicons-' . esc_attr( $health_icon ) . '" style="color: ' . ( 'healthy' === $health_status['overall_health'] ? '#00a32a' : ( 'warning' === $health_status['overall_health'] ? '#dba617' : '#d63638' ) ) . ';"></span>';
$content .= '<strong>' . esc_html__( 'System Health: ', 'wp-mcp-ai' ) . '</strong>';
$content .= '<span>' . esc_html( ucfirst( $health_status['overall_health'] ) ) . '</span>';
$content .= '</div>';
if ( ! empty( $health_status['issues'] ) ) {
$content .= '<div style="margin-top: 0.5rem; font-size: 0.875rem;">';
$content .= '<strong>' . esc_html__( 'Issues:', 'wp-mcp-ai' ) . '</strong> ';
$content .= esc_html( implode( ', ', array_map( 'ucwords', str_replace( '_', ' ', $health_status['issues'] ) ) ) );
$content .= '</div>';
}
$content .= '</div>';

$content .= '<h3>' . esc_html__( 'Current Orchestration Status', 'wp-mcp-ai' ) . '</h3>';

$content .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 1rem 0;">';

// Memory tier.
$memory_tier = ucfirst( $health_status['tier'] );

$content .= '<div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; border-radius: 4px;">';
$content .= '<div style="font-size: 0.875rem; color: #646970; margin-bottom: 0.5rem;">' . esc_html__( 'Workload Tier', 'wp-mcp-ai' ) . '</div>';
$content .= '<div style="font-size: 1.5rem; font-weight: 600;">' . esc_html( $memory_tier ) . '</div>';
$content .= '</div>';

// Max tokens.
$max_tokens = $health_status['max_tokens'];
$content .= '<div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; border-radius: 4px;">';
$content .= '<div style="font-size: 0.875rem; color: #646970; margin-bottom: 0.5rem;">' . esc_html__( 'Max Tokens', 'wp-mcp-ai' ) . '</div>';
$content .= '<div style="font-size: 1.5rem; font-weight: 600;">' . esc_html( number_format( $max_tokens ) ) . '</div>';
$content .= '</div>';

// Memory Usage.
$memory_percent = $health_status['memory']['percent'];
$content .= '<div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; border-radius: 4px;">';
$content .= '<div style="font-size: 0.875rem; color: #646970; margin-bottom: 0.5rem;">' . esc_html__( 'Memory Usage', 'wp-mcp-ai' ) . '</div>';
$content .= '<div style="font-size: 1.5rem; font-weight: 600;">' . esc_html( round( $memory_percent, 1 ) ) . '%</div>';
$content .= '<div style="background: #dcdcde; height: 4px; border-radius: 2px; margin-top: 0.5rem; overflow: hidden;">';
$content .= '<div style="background: ' . ( $memory_percent > 90 ? '#d63638' : ( $memory_percent > 75 ? '#dba617' : '#00a32a' ) ) . '; height: 100%; width: ' . esc_attr( $memory_percent ) . '%;"></div>';
$content .= '</div>';
$content .= '</div>';

// Error Rate.
$error_rate = $health_status['metrics']['error_rate'];
$content .= '<div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; border-radius: 4px;">';
$content .= '<div style="font-size: 0.875rem; color: #646970; margin-bottom: 0.5rem;">' . esc_html__( 'Error Rate (1h)', 'wp-mcp-ai' ) . '</div>';
$content .= '<div style="font-size: 1.5rem; font-weight: 600;">' . esc_html( round( $error_rate, 1 ) ) . '%</div>';
$content .= '</div>';

// Active cron jobs.
$cron_jobs = WP_MCP_AI_Cron_Manager::get_jobs();
$active_jobs = 0;
foreach ( $cron_jobs as $job ) {
$event = wp_get_scheduled_event( $job['hook'], $job['args'] );
if ( $event ) {
$active_jobs++;
}
}

$content .= '<div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; border-radius: 4px;">';
$content .= '<div style="font-size: 0.875rem; color: #646970; margin-bottom: 0.5rem;">' . esc_html__( 'Active Cron Jobs', 'wp-mcp-ai' ) . '</div>';
$content .= '<div style="font-size: 1.5rem; font-weight: 600;">' . esc_html( $active_jobs ) . '</div>';
$content .= '</div>';

// Avg Response Time.
$avg_response = $health_status['metrics']['avg_response_time'];
$content .= '<div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; border-radius: 4px;">';
$content .= '<div style="font-size: 0.875rem; color: #646970; margin-bottom: 0.5rem;">' . esc_html__( 'Avg Response (1h)', 'wp-mcp-ai' ) . '</div>';
$content .= '<div style="font-size: 1.5rem; font-weight: 600;">' . esc_html( round( $avg_response, 2 ) ) . 's</div>';
$content .= '</div>';

$content .= '</div>';

// Predictive Insights.
$prediction = $resource_manager->predict_requirements( 'chat' );
if ( $prediction['confidence'] > WP_MCP_AI_Settings_Registry::get_setting( 'prediction_confidence_threshold', 30 ) / 100.0 ) {
$content .= '<div style="margin-top: 1.5rem;">';
$content .= '<h4>' . esc_html__( 'Predictive Insights', 'wp-mcp-ai' ) . '</h4>';
$content .= '<div style="background: #f0f6fc; border: 1px solid #c3e4ff; padding: 1rem; border-radius: 4px;">';
$content .= '<p style="margin: 0;">';
$content .= '<strong>' . esc_html__( 'Predicted Resource Needs:', 'wp-mcp-ai' ) . '</strong> ';
$content .= sprintf(
/* translators: %1$d: predicted tokens, %2$d: confidence percentage */
esc_html__( 'Next operations will likely need ~%1$d tokens (%2$d%% confidence)', 'wp-mcp-ai' ),
$prediction['predicted_tokens'],
round( $prediction['confidence'] * 100 )
);
$content .= '</p>';
if ( 'proceed' !== $prediction['recommendation'] ) {
$content .= '<p style="margin: 0.5rem 0 0 0; color: #dba617;">';
$content .= '<span class="dashicons dashicons-warning" style="vertical-align: text-bottom;"></span> ';
$content .= '<strong>' . esc_html__( 'Recommendation:', 'wp-mcp-ai' ) . '</strong> ';
$content .= esc_html( str_replace( '_', ' ', ucwords( $prediction['recommendation'], '_' ) ) );
$content .= '</p>';
}
$content .= '</div>';
$content .= '</div>';
}

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
}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				if ( 'html' === $field['type'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in get_intro_content() and get_stats_content().
					echo $field['content'];
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
			// All fields are boolean checkboxes, no special validation needed.
			return $input;
		}
	}
}
