<?php
/**
 * Agent Orchestration Metabox for Professions.
 *
 * Provides UI for configuring multi-agent orchestration settings including
 * agent roles, task patterns, decision criteria, and other orchestration metadata.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
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
 * Manages agent orchestration configuration UI for profession posts.
 *
 * @since 1.9.0
 */
class ProfessionMetaboxAgentOrchestration extends ProfessionMetaboxBase {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_profession_agent_orchestration';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Agent Orchestration', 'nvoos-content-graph-ai-platform' );
	}

	/**
	 * Get the metabox context.
	 *
	 * @return string
	 */
	public function get_context() {
		return 'normal';
	}

	/**
	 * Get the metabox priority.
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
		return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/project/proposals/DEEPSEEK-V4-IMPLEMENTATION-STATUS.md';
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view( $post ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this profession.', 'nvoos-content-graph-ai-platform' ), '', array( 'response' => 403 ) );
		}

		wp_nonce_field( $this->get_id() . '_save', $this->get_id() . '_nonce' );

		// Get current values.
		$agent_role            = get_post_meta( $post->ID, ProfessionCpt::META_AGENT_ROLE, true ) ? get_post_meta( $post->ID, ProfessionCpt::META_AGENT_ROLE, true ) : 'generalist';
		$task_patterns         = get_post_meta( $post->ID, ProfessionCpt::META_TASK_PATTERNS, true ) ? get_post_meta( $post->ID, ProfessionCpt::META_TASK_PATTERNS, true ) : '{}';
		$decision_criteria     = get_post_meta( $post->ID, ProfessionCpt::META_DECISION_CRITERIA, true ) ? get_post_meta( $post->ID, ProfessionCpt::META_DECISION_CRITERIA, true ) : '{}';
		$orchestration_rules   = get_post_meta( $post->ID, ProfessionCpt::META_ORCHESTRATION_RULES, true ) ? get_post_meta( $post->ID, ProfessionCpt::META_ORCHESTRATION_RULES, true ) : '{}';
		$quality_metrics       = get_post_meta( $post->ID, ProfessionCpt::META_QUALITY_METRICS, true ) ? get_post_meta( $post->ID, ProfessionCpt::META_QUALITY_METRICS, true ) : '{}';
		$tool_execution_order  = get_post_meta( $post->ID, ProfessionCpt::META_TOOL_EXECUTION_ORDER, true ) ? get_post_meta( $post->ID, ProfessionCpt::META_TOOL_EXECUTION_ORDER, true ) : '[]';
		$confidence_thresholds = get_post_meta( $post->ID, ProfessionCpt::META_CONFIDENCE_THRESHOLDS, true ) ? get_post_meta( $post->ID, ProfessionCpt::META_CONFIDENCE_THRESHOLDS, true ) : '{}';

		// Format JSON for display.
		$task_patterns         = $this->format_json( $task_patterns );
		$decision_criteria     = $this->format_json( $decision_criteria );
		$orchestration_rules   = $this->format_json( $orchestration_rules );
		$quality_metrics       = $this->format_json( $quality_metrics );
		$tool_execution_order  = $this->format_json( $tool_execution_order );
		$confidence_thresholds = $this->format_json( $confidence_thresholds );

		?>
		<div class="wp-mcp-ai-profession-orchestration">
			<p class="description" style="margin-bottom: 20px;">
				<?php
				esc_html_e(
					'Configure how this profession behaves in multi-agent workflows. Agent roles determine the profession\'s function (planning, execution, validation, or specialized tasks). Task patterns define workflow templates for common operations.',
					'nvoos-content-graph-ai-platform'
				);
				?>
			</p>

			<?php
			wp_add_inline_style(
				'wp-mcp-ai-metabox-agent-orchestration',
				'.wp-mcp-ai-orchestration-field{margin-bottom:25px}'
				. '.wp-mcp-ai-orchestration-field label{display:block;font-weight:600;margin-bottom:8px}'
				. '.wp-mcp-ai-orchestration-field textarea{width:100%;font-family:\'Courier New\',Courier,monospace;font-size:13px}'
				. '.wp-mcp-ai-orchestration-field .description{margin-top:5px;font-style:italic}'
				. '.wp-mcp-ai-role-option{margin-bottom:10px}'
			);
			?>

			<!-- Agent Role -->
			<div class="wp-mcp-ai-orchestration-field">
				<label for="wp_mcp_ai_agent_role">
					<?php esc_html_e( 'Agent Role', 'nvoos-content-graph-ai-platform' ); ?>
					<span class="required" style="color: #dc3232;">*</span>
				</label>
				<select 
					name="wp_mcp_ai_agent_role" 
					id="wp_mcp_ai_agent_role" 
					class="widefat"
					style="max-width: 300px;"
				>
					<option value="generalist" <?php selected( $agent_role, 'generalist' ); ?>>
						<?php esc_html_e( 'Generalist (Can perform any role)', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
					<option value="planner" <?php selected( $agent_role, 'planner' ); ?>>
						<?php esc_html_e( 'Planner (Task decomposition & sequencing)', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
					<option value="executor" <?php selected( $agent_role, 'executor' ); ?>>
						<?php esc_html_e( 'Executor (Task execution & operations)', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
					<option value="critic" <?php selected( $agent_role, 'critic' ); ?>>
						<?php esc_html_e( 'Critic (Quality validation & review)', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
					<option value="specialist" <?php selected( $agent_role, 'specialist' ); ?>>
						<?php esc_html_e( 'Specialist (Domain-specific expertise)', 'nvoos-content-graph-ai-platform' ); ?>
					</option>
				</select>
				<p class="description">
					<?php esc_html_e( 'Primary role in multi-agent workflows. This determines how the profession is selected for team composition.', 'nvoos-content-graph-ai-platform' ); ?>
				</p>
			</div>

			<!-- Task Patterns -->
			<div class="wp-mcp-ai-orchestration-field">
				<label for="wp_mcp_ai_task_patterns">
					<?php esc_html_e( 'Task Patterns (JSON)', 'nvoos-content-graph-ai-platform' ); ?>
				</label>
				<textarea 
					name="wp_mcp_ai_task_patterns" 
					id="wp_mcp_ai_task_patterns" 
					rows="8"
					placeholder='{"workflow_name": {"steps": ["step1", "step2"], "dependencies": {"step2": "step1"}}}'
				><?php echo esc_textarea( $task_patterns ); ?></textarea>
				<p class="description">
					<?php
					esc_html_e(
						'Workflow templates for common tasks. Example: {"data_analysis": {"steps": ["get_dataset", "analyze", "visualize"], "dependencies": {"analyze": "get_dataset"}}}',
						'nvoos-content-graph-ai-platform'
					);
					?>
				</p>
			</div>

			<!-- Decision Criteria -->
			<div class="wp-mcp-ai-orchestration-field">
				<label for="wp_mcp_ai_decision_criteria">
					<?php esc_html_e( 'Decision Criteria (JSON)', 'nvoos-content-graph-ai-platform' ); ?>
				</label>
				<textarea 
					name="wp_mcp_ai_decision_criteria" 
					id="wp_mcp_ai_decision_criteria" 
					rows="6"
					placeholder='{"if_condition": "action", "min_confidence": 0.7}'
				><?php echo esc_textarea( $decision_criteria ); ?></textarea>
				<p class="description">
					<?php
					esc_html_e(
						'Condition→action mappings for autonomous operation. Example: {"if_dataset_size > 10MB": "escalate_to_specialist", "min_confidence": 0.7}',
						'nvoos-content-graph-ai-platform'
					);
					?>
				</p>
			</div>

			<!-- Orchestration Rules -->
			<div class="wp-mcp-ai-orchestration-field">
				<label for="wp_mcp_ai_orchestration_rules">
					<?php esc_html_e( 'Orchestration Rules (JSON)', 'nvoos-content-graph-ai-platform' ); ?>
				</label>
				<textarea 
					name="wp_mcp_ai_orchestration_rules" 
					id="wp_mcp_ai_orchestration_rules" 
					rows="6"
					placeholder='{"delegation_policy": "delegate_if_confidence_low", "collaboration_mode": "sequential"}'
				><?php echo esc_textarea( $orchestration_rules ); ?></textarea>
				<p class="description">
					<?php
					esc_html_e(
						'Coordination and collaboration rules. Example: {"delegation_policy": "delegate_if_confidence_low", "collaboration_mode": "sequential", "result_aggregation": "consensus"}',
						'nvoos-content-graph-ai-platform'
					);
					?>
				</p>
			</div>

			<!-- Quality Metrics -->
			<div class="wp-mcp-ai-orchestration-field">
				<label for="wp_mcp_ai_quality_metrics">
					<?php esc_html_e( 'Quality Metrics (JSON)', 'nvoos-content-graph-ai-platform' ); ?>
				</label>
				<textarea 
					name="wp_mcp_ai_quality_metrics" 
					id="wp_mcp_ai_quality_metrics" 
					rows="6"
					placeholder='{"min_completeness": 0.85, "min_accuracy": 0.80}'
				><?php echo esc_textarea( $quality_metrics ); ?></textarea>
				<p class="description">
					<?php
					esc_html_e(
						'Success criteria for validation. Example: {"min_completeness": 0.85, "min_accuracy": 0.80, "required_fields": ["title", "content"]}',
						'nvoos-content-graph-ai-platform'
					);
					?>
				</p>
			</div>

			<!-- Tool Execution Order -->
			<div class="wp-mcp-ai-orchestration-field">
				<label for="wp_mcp_ai_tool_execution_order">
					<?php esc_html_e( 'Tool Execution Order (JSON)', 'nvoos-content-graph-ai-platform' ); ?>
				</label>
				<textarea 
					name="wp_mcp_ai_tool_execution_order" 
					id="wp_mcp_ai_tool_execution_order" 
					rows="6"
					placeholder='[{"tool": "web_search", "parallel": true}, {"tool": "analyze_data", "depends_on": ["web_search"]}]'
				><?php echo esc_textarea( $tool_execution_order ); ?></textarea>
				<p class="description">
					<?php
					esc_html_e(
						'Tool chains with dependencies. Example: [{"tool": "web_search", "parallel": true}, {"tool": "analyze_data", "depends_on": ["web_search"]}]',
						'nvoos-content-graph-ai-platform'
					);
					?>
				</p>
			</div>

			<!-- Confidence Thresholds -->
			<div class="wp-mcp-ai-orchestration-field">
				<label for="wp_mcp_ai_confidence_thresholds">
					<?php esc_html_e( 'Confidence Thresholds (JSON)', 'nvoos-content-graph-ai-platform' ); ?>
				</label>
				<textarea 
					name="wp_mcp_ai_confidence_thresholds" 
					id="wp_mcp_ai_confidence_thresholds" 
					rows="5"
					placeholder='{"escalation_threshold": 0.7, "human_review_threshold": 0.5}'
				><?php echo esc_textarea( $confidence_thresholds ); ?></textarea>
				<p class="description">
					<?php
					esc_html_e(
						'Escalation rules based on confidence. Example: {"escalation_threshold": 0.7, "human_review_threshold": 0.5}',
						'nvoos-content-graph-ai-platform'
					);
					?>
				</p>
			</div>

			<p class="description" style="margin-top: 20px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
				<strong><?php esc_html_e( 'Note:', 'nvoos-content-graph-ai-platform' ); ?></strong>
				<?php
				esc_html_e(
					'All JSON fields will be validated on save. Use WP-CLI command "wp profession seed-orchestration" to auto-populate these fields for all professions using research-backed heuristics.',
					'nvoos-content-graph-ai-platform'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Format JSON for display in textarea.
	 *
	 * @param string $json JSON string.
	 * @return string Formatted JSON.
	 */
	protected function format_json( $json ) {
		$decoded = json_decode( $json, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			return wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}
		return $json;
	}

	/**
	 * Save metabox data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		// Verify nonce.
		if ( ! isset( $_POST[ $this->get_id() . '_nonce' ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $this->get_id() . '_nonce' ] ) ), $this->get_id() . '_save' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! $this->can_save( $post_id, $post ) ) {
			return;
		}

		// Save agent role.
		if ( isset( $_POST['wp_mcp_ai_agent_role'] ) ) {
			$agent_role = sanitize_key( wp_unslash( $_POST['wp_mcp_ai_agent_role'] ) );
			update_post_meta( $post_id, ProfessionCpt::META_AGENT_ROLE, $agent_role );
		}

		// Save JSON fields — decode to validate structure, then re-encode cleanly.
		$json_fields = array(
			'wp_mcp_ai_task_patterns'         => ProfessionCpt::META_TASK_PATTERNS,
			'wp_mcp_ai_decision_criteria'     => ProfessionCpt::META_DECISION_CRITERIA,
			'wp_mcp_ai_orchestration_rules'   => ProfessionCpt::META_ORCHESTRATION_RULES,
			'wp_mcp_ai_quality_metrics'       => ProfessionCpt::META_QUALITY_METRICS,
			'wp_mcp_ai_tool_execution_order'  => ProfessionCpt::META_TOOL_EXECUTION_ORDER,
			'wp_mcp_ai_confidence_thresholds' => ProfessionCpt::META_CONFIDENCE_THRESHOLDS,
		);

		foreach ( $json_fields as $field_name => $meta_key ) {
			if ( isset( $_POST[ $field_name ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JSON string must be decoded first; all nested values are sanitized recursively via \NvoosContentGraphAiPlatform\Schema\Sanitize::recursive() below.
				$raw_value     = wp_unslash( $_POST[ $field_name ] );
				$decoded_value = json_decode( $raw_value, true );
				if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded_value ) ) {
					$decoded_value = \NvoosContentGraphAiPlatform\Schema\Sanitize::recursive( $decoded_value );
					$value         = wp_json_encode( $decoded_value );
				} else {
					$value = '{}';
				}
				update_post_meta( $post_id, $meta_key, $value );
			}
		}
	}
}
