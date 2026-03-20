<?php
/**
 * Specialist Agent Role
 *
 * Applies deep domain expertise to tasks requiring specialized knowledge.
 * Inspired by DeepSeek V4's specialized expert agent patterns.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Specialist Agent Role class
 *
 * Responsible for:
 * - Applying domain-specific expertise to complex tasks
 * - Providing authoritative answers within a specialty
 * - Evaluating domain-specific quality criteria
 * - Collaborating with Planner/Executor agents on specialist sub-tasks
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Agent_Role_Specialist extends WP_MCP_AI_Agent_Role_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->role_type        = 'specialist';
		$this->role_name        = __( 'Specialist', 'mcp-ai-wpoos' );
		$this->role_description = __( 'Applies deep domain-specific expertise to tasks, delivering authoritative results within a defined specialty area.', 'mcp-ai-wpoos' );

		$this->capabilities = array(
			'can-specialize',
			'can-validate',
			'autonomous',
		);

		$this->recommended_tools = array(
			'web_search',
			'crawl4ai',
			'get_recent_posts',
			'check_site_security',
		);
	}

	/**
	 * Get recommended system prompt additions for this role
	 *
	 * @return string Additional system prompt text.
	 */
	public function get_system_prompt_additions() {
		return __(
			'You are a Specialist agent with deep domain expertise. When given a task, apply your specialized knowledge to deliver authoritative, high-quality results. Focus on the nuances and complexities of your domain. Provide detailed explanations that demonstrate your expertise, identify domain-specific risks or considerations, and ensure your output meets the highest standards for your area of specialization.',
			'mcp-ai-wpoos'
		);
	}

	/**
	 * Execute a specialist task
	 *
	 * Applies domain expertise to the task and returns expert-level results.
	 *
	 * @param array $task    Task data including description, context, requirements,
	 *                       and optional 'domain' key indicating the specialty area.
	 * @param array $context Execution context including assistant_id, user_id, etc.
	 * @return array|WP_Error Task result or error.
	 */
	public function execute_role_task( $task, $context ) {
		// Validate inputs.
		$task_validation = $this->validate_task( $task );
		if ( is_wp_error( $task_validation ) ) {
			return $task_validation;
		}

		$context_validation = $this->validate_context( $context );
		if ( is_wp_error( $context_validation ) ) {
			return $context_validation;
		}

		$domain = isset( $task['domain'] ) ? sanitize_text_field( $task['domain'] ) : 'general';

		$this->log(
			'Specialist agent executing domain task',
			'info',
			array(
				'task_description' => $task['description'],
				'domain'           => $domain,
				'assistant_id'     => $context['assistant_id'],
			)
		);

		$start_time = microtime( true );

		// Perform the domain-specific analysis.
		$domain_insights   = $this->apply_domain_expertise( $task, $domain );
		$quality_checks    = $this->run_domain_quality_checks( $task, $domain );
		$recommendations   = $this->generate_specialist_recommendations( $task, $domain );

		$execution_time = microtime( true ) - $start_time;

		$result = array(
			'task_id'          => isset( $task['id'] ) ? $task['id'] : uniqid( 'specialist_task_', true ),
			'status'           => 'completed',
			'domain'           => $domain,
			'result'           => $domain_insights,
			'quality_checks'   => $quality_checks,
			'recommendations'  => $recommendations,
			'execution_time'   => round( $execution_time, 4 ),
			'completed_at'     => current_time( 'mysql' ),
		);

		$this->log(
			'Specialist task complete',
			'info',
			array(
				'task_id'        => $result['task_id'],
				'domain'         => $domain,
				'execution_time' => $result['execution_time'],
			)
		);

		return $result;
	}

	/**
	 * Apply domain expertise to produce specialist insights
	 *
	 * @param array  $task   Task data.
	 * @param string $domain Specialty domain.
	 * @return array Domain-specific insights.
	 */
	protected function apply_domain_expertise( $task, $domain ) {
		return array(
			'description'  => $task['description'],
			'domain'       => $domain,
			'analysis'     => sprintf(
				/* translators: 1: domain, 2: task description */
				__( 'Specialist analysis for %1$s domain applied to: %2$s', 'mcp-ai-wpoos' ),
				$domain,
				$task['description']
			),
			'confidence'   => 'high',
		);
	}

	/**
	 * Run domain-specific quality checks
	 *
	 * @param array  $task   Task data.
	 * @param string $domain Specialty domain.
	 * @return array Quality check results.
	 */
	protected function run_domain_quality_checks( $task, $domain ) {
		$checks = array(
			'domain_relevance' => array(
				'score'  => 1.0,
				'issues' => array(),
			),
		);

		// Verify the task description is aligned with the stated domain.
		if ( ! empty( $domain ) && 'general' !== $domain ) {
			if ( stripos( $task['description'], $domain ) === false ) {
				$checks['domain_relevance']['score']    = 0.8;
				$checks['domain_relevance']['issues'][] = sprintf(
					/* translators: %s: domain */
					__( 'Task description does not explicitly mention the %s domain', 'mcp-ai-wpoos' ),
					$domain
				);
			}
		}

		return $checks;
	}

	/**
	 * Generate specialist recommendations
	 *
	 * @param array  $task   Task data.
	 * @param string $domain Specialty domain.
	 * @return array List of recommendations.
	 */
	protected function generate_specialist_recommendations( $task, $domain ) {
		return array(
			sprintf(
				/* translators: %s: domain */
				__( 'Ensure all %s-specific requirements are addressed', 'mcp-ai-wpoos' ),
				$domain
			),
			__( 'Review output with domain best-practices checklist', 'mcp-ai-wpoos' ),
		);
	}
}
