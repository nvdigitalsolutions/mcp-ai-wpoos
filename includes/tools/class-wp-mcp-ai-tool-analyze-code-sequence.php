<?php
/**
 * Tool for analyzing and optimizing long code sequences.
 *
 * Part of Phase 3.3: Reasoning Tools implementation.
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyze Code Sequence Tool
 *
 * Analyzes code for optimization opportunities, validates syntax and security,
 * and provides recommendations. Uses the Code Optimizer service for comprehensive
 * code analysis.
 *
 * @since 1.1.1
 */
class WP_MCP_AI_Tool_Analyze_Code_Sequence implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_code_sequence';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Code Sequence', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes and optimizes long code sequences. Performs syntax validation, WordPress Coding Standards checking, security scanning (eval, SQL injection, XSS, file inclusion), and provides improvement suggestions. Supports PHP and identifies patterns, issues, and optimization opportunities.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'code'         => array(
					'type'        => 'string',
					'description' => __( 'Code to analyze and validate.', 'mcp-ai-wpoos' ),
				),
				'language'     => array(
					'type'        => 'string',
					'description' => __( 'Programming language of the code (php, javascript, python, etc.). Only php has full validation support.', 'mcp-ai-wpoos' ),
					'default'     => 'php',
				),
				'requirements' => array(
					'type'        => 'array',
					'description' => __( 'Optional list of requirements to verify against the code.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
				),
			),
			'required'   => array( 'code' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'developer_technical',

			'pattern_compatibility' => array( 'sequential' ),

			'profession_tags'       => array( 'software_developer', 'qa_engineer' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'          => true,
			'modifies-wp'   => false,
			'deterministic' => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool execution result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['code'] ) ) {
			return new WP_Error(
				'missing_parameter',
				__( 'Code parameter is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$code         = $arguments['code'];
		$language     = sanitize_text_field( $arguments['language'] ?? 'php' );
		$requirements = $arguments['requirements'] ?? array();

		// Get code optimizer instance.
		if ( ! class_exists( 'WP_MCP_AI_Code_Optimizer' ) ) {
			return new WP_Error(
				'service_unavailable',
				__( 'Code Optimizer service is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$code_optimizer = new WP_MCP_AI_Code_Optimizer();

		// Validate the code.
		$validation_result = $code_optimizer->validate_code( $code, $language, $requirements );

		// Build analysis result.
		$result = array(
			'language'           => $language,
			'code_length'        => strlen( $code ),
			'validation'         => $validation_result,
			'analysis_timestamp' => current_time( 'mysql' ),
		);

		// Generate summary message.
		$issues_count      = count( $validation_result['issues'] ?? array() );
		$suggestions_count = count( $validation_result['suggestions'] ?? array() );
		$security_count    = count( $validation_result['security'] ?? array() );

		if ( $validation_result['valid'] ) {
			$message = sprintf(
				/* translators: %d: number of suggestions */
				__( 'Code analysis complete. Code is syntactically valid. Found %d suggestions for improvement.', 'mcp-ai-wpoos' ),
				$suggestions_count
			);
		} else {
			$total_issues = $issues_count + $security_count;
			$message      = sprintf(
				/* translators: 1: number of issues, 2: number of security issues */
				__( 'Code analysis complete. Found %1$d validation issues (including %2$d security concerns). Review and address these issues before deployment.', 'mcp-ai-wpoos' ),
				$total_issues,
				$security_count
			);
		}

		$result['message'] = $message;

		// Add detailed breakdown.
		$result['summary'] = array(
			'valid'             => $validation_result['valid'],
			'syntax_errors'     => $issues_count,
			'style_suggestions' => $suggestions_count,
			'security_issues'   => $security_count,
		);

		if ( $security_count > 0 ) {
			$result['security_alert'] = __( 'SECURITY WARNING: Code contains potential vulnerabilities. Review security issues before use.', 'mcp-ai-wpoos' );
		}

		return $this->success( $result, $message );
	}
}
