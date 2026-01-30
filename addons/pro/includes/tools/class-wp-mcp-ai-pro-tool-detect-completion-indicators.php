<?php
/**
 * Tool: Detect Completion Indicators
 *
 * Analyzes responses for semantic completion indicators.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect Completion Indicators Tool
 */
class WP_MCP_AI_Pro_Tool_Detect_Completion_Indicators {

	/**
	 * Completion indicator patterns
	 *
	 * @var array
	 */
	private $completion_patterns = array(
		'/\b(done|finished|completed?|accomplished)\b/i',
		'/\b(all tasks? (?:are )?(?:complete|done|finished))\b/i',
		'/\b(successfully (?:completed|finished))\b/i',
		'/\b(nothing (?:more|else) to (?:do|add|fix))\b/i',
		'/\b(ready (?:for|to) (?:review|deploy|merge|release))\b/i',
		'/\b((?:fully?|completely) (?:implemented|working))\b/i',
		'/\b(no (?:more|further|additional) (?:changes|work|tasks?))\b/i',
		'/\b(objective achieved|goal (?:reached|met|accomplished))\b/i',
		'/\b(implementation complete)\b/i',
		'/\b(all requirements? (?:met|satisfied|fulfilled))\b/i',
	);

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'detect_completion_indicators';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'detect_completion_indicators',
			'description'         => 'Analyze text for semantic completion indicators. Returns a score indicating how many completion signals are detected. Used for intelligent exit detection in autonomous loops.',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'text'      => array(
						'type'        => 'string',
						'description' => 'Text to analyze for completion indicators',
					),
					'plan_id'   => array(
						'type'        => 'integer',
						'description' => 'Optional task plan ID to check completion against',
					),
					'threshold' => array(
						'type'        => 'integer',
						'description' => 'Minimum indicators required (default: 2)',
						'default'     => 2,
					),
				),
				'required'   => array( 'text' ),
			),
			'required_capability' => 'read',
		);
	}

	/**
	 * Execute the tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( empty( $arguments['text'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: text',
			);
		}

		$text      = $arguments['text'];
		$threshold = ! empty( $arguments['threshold'] ) ? intval( $arguments['threshold'] ) : 2;

		// Detect semantic indicators.
		$indicators = $this->detect_indicators( $text );

		// Check task plan completion if provided.
		$task_completion = null;
		if ( ! empty( $arguments['plan_id'] ) ) {
			$task_completion = $this->check_plan_completion( intval( $arguments['plan_id'] ) );
		}

		// Calculate total score.
		$total_score    = count( $indicators['matches'] );
		$completion_met = $total_score >= $threshold;

		// Add task plan score if available.
		if ( null !== $task_completion ) {
			$total_score += $task_completion['progress'] >= 100 ? 1 : 0;
		}

		return array(
			'success'          => true,
			'indicator_count'  => count( $indicators['matches'] ),
			'indicators_found' => $indicators['matches'],
			'total_score'      => $total_score,
			'threshold'        => $threshold,
			'completion_met'   => $completion_met,
			'task_completion'  => $task_completion,
			'recommendation'   => $this->get_recommendation( $total_score, $threshold, $task_completion ),
		);
	}

	/**
	 * Detect completion indicators in text
	 *
	 * @param string $text Text to analyze.
	 * @return array
	 */
	private function detect_indicators( $text ) {
		$matches = array();

		foreach ( $this->completion_patterns as $pattern ) {
			if ( preg_match( $pattern, $text, $match ) ) {
				$matches[] = array(
					'pattern' => $pattern,
					'match'   => $match[0],
					'context' => $this->extract_context( $text, $match[0] ),
				);
			}
		}

		return array(
			'matches' => $matches,
			'count'   => count( $matches ),
		);
	}

	/**
	 * Extract context around match
	 *
	 * @param string $text  Full text.
	 * @param string $match Match string.
	 * @return string
	 */
	private function extract_context( $text, $match ) {
		$pos = strpos( $text, $match );
		if ( false === $pos ) {
			return '';
		}

		$start   = max( 0, $pos - 50 );
		$length  = min( strlen( $text ) - $start, 150 );
		$context = substr( $text, $start, $length );

		return trim( $context );
	}

	/**
	 * Check task plan completion
	 *
	 * @param int $plan_id Plan ID.
	 * @return array|null
	 */
	private function check_plan_completion( $plan_id ) {
		// Ensure Get Task Plan tool is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Get_Task_Plan' ) ) {
			if ( defined( 'WP_MCP_AI_PRO_PATH' ) && file_exists( WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-get-task-plan.php' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-get-task-plan.php';
			} else {
				return null;
			}
		}

		// Get task plan using existing tool.
		$tool   = new WP_MCP_AI_Pro_Tool_Get_Task_Plan();
		$result = $tool->execute(
			array( 'plan_id' => $plan_id ),
			array()
		);

		if ( empty( $result['success'] ) ) {
			return null;
		}

		return array(
			'plan_id'         => $plan_id,
			'progress'        => $result['progress'],
			'completed_count' => $result['completed_count'],
			'task_count'      => $result['task_count'],
			'status'          => $result['status'],
		);
	}

	/**
	 * Get recommendation
	 *
	 * @param int        $score           Total score.
	 * @param int        $threshold       Threshold.
	 * @param array|null $task_completion Task completion data.
	 * @return string
	 */
	private function get_recommendation( $score, $threshold, $task_completion ) {
		if ( $score >= $threshold ) {
			if ( null !== $task_completion && $task_completion['progress'] >= 100 ) {
				return 'READY_TO_EXIT: Both semantic indicators and task completion criteria met. Consider setting EXIT_SIGNAL.';
			} elseif ( null !== $task_completion ) {
				return sprintf(
					'SEMANTIC_COMPLETE: %d completion indicators found, but task plan is %.1f%% complete. Review remaining tasks.',
					$score,
					$task_completion['progress']
				);
			} else {
				return sprintf(
					'INDICATORS_MET: %d completion indicators detected (threshold: %d). Verify work before exiting.',
					$score,
					$threshold
				);
			}
		} else {
			$needed = $threshold - $score;
			return sprintf(
				'CONTINUE_WORKING: Only %d of %d indicators found. Need %d more completion signal(s).',
				$score,
				$threshold,
				$needed
			);
		}
	}
}
