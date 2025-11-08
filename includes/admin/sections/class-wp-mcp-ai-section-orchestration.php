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
				'orchestration_intro'       => array(
					'type'        => 'html',
					'content'     => $this->get_intro_content(),
				),
				'enable_budget_management'  => array(
					'type'        => 'checkbox',
					'label'       => __( 'Enable Dynamic Budget Management', 'wp-mcp-ai' ),
					'description' => __( 'Automatically allocate and adjust token budgets based on system resources and workload tier.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'enable_predictive_optimization' => array(
					'type'        => 'checkbox',
					'label'       => __( 'Enable Predictive Optimization', 'wp-mcp-ai' ),
					'description' => __( 'Use historical usage patterns to forecast and prevent resource exhaustion.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'enable_capability_gating'  => array(
					'type'        => 'checkbox',
					'label'       => __( 'Enable Capability-Based Tool Gating', 'wp-mcp-ai' ),
					'description' => __( 'Enforce WordPress capability checks for tool access based on user roles.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'enable_cron_orchestration' => array(
					'type'        => 'checkbox',
					'label'       => __( 'Enable Cron-Based Task Orchestration', 'wp-mcp-ai' ),
					'description' => __( 'Allow AI agents to create and manage scheduled background tasks with inherited budget constraints.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'orchestration_stats'       => array(
					'type'        => 'html',
					'content'     => $this->get_stats_content(),
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
		 * Get statistics content HTML.
		 *
		 * @return string
		 */
		private function get_stats_content() {
			// Get resource manager instance.
			$resource_manager = WP_MCP_AI_Resource_Manager::instance();
			
			$content = '<div class="wp-mcp-ai-orchestration-stats" style="margin: 1.5rem 0;">';
			$content .= '<h3>' . esc_html__( 'Current Orchestration Status', 'wp-mcp-ai' ) . '</h3>';
			
			$content .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 1rem 0;">';
			
			// Memory tier.
			$memory_limit = $resource_manager->get_memory_limit();
			$memory_tier = 'Unknown';
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
			$content .= '<div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; border-radius: 4px;">';
			$content .= '<div style="font-size: 0.875rem; color: #646970; margin-bottom: 0.5rem;">' . esc_html__( 'Max Tokens', 'wp-mcp-ai' ) . '</div>';
			$content .= '<div style="font-size: 1.5rem; font-weight: 600;">' . esc_html( number_format( $max_tokens ) ) . '</div>';
			$content .= '</div>';
			
			// Request timeout.
			$timeout = $resource_manager->get_request_timeout();
			$content .= '<div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; border-radius: 4px;">';
			$content .= '<div style="font-size: 0.875rem; color: #646970; margin-bottom: 0.5rem;">' . esc_html__( 'Request Timeout', 'wp-mcp-ai' ) . '</div>';
			$content .= '<div style="font-size: 1.5rem; font-weight: 600;">' . esc_html( $timeout ) . 's</div>';
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
