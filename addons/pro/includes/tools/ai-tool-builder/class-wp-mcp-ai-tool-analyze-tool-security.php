<?php
/**
 * Tool for analyzing tool security and providing recommendations.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyze AI tool code for security vulnerabilities and best practices.
 *
 * This tool performs comprehensive security analysis including input validation,
 * sanitization, capability checks, SQL injection risks, XSS vulnerabilities,
 * and provides actionable recommendations.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Analyze_Tool_Security implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_tool_security';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Tool Security', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Perform comprehensive security analysis on AI tool code. Checks for input validation, sanitization, capability checks, SQL injection risks, XSS vulnerabilities, CSRF protection, and provides actionable security recommendations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tool_file'               => array(
					'type'        => 'string',
					'description' => __( 'Path to tool file to analyze', 'mcp-ai-wpoos-pro' ),
				),
				'code'                    => array(
					'type'        => 'string',
					'description' => __( 'Tool code to analyze (alternative to file_path)', 'mcp-ai-wpoos-pro' ),
				),
				'severity_threshold'      => array(
					'type'        => 'string',
					'enum'        => array( 'critical', 'high', 'medium', 'low', 'info' ),
					'description' => __( 'Minimum severity level to report', 'mcp-ai-wpoos-pro' ),
					'default'     => 'medium',
				),
				'check_categories'        => array(
					'type'        => 'array',
					'description' => __( 'Security categories to check', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'injection', 'xss', 'capability', 'sanitization', 'csrf', 'file-ops', 'external-api' ),
					),
					'default'     => array( 'injection', 'xss', 'capability', 'sanitization' ),
				),
				'include_recommendations' => array(
					'type'        => 'boolean',
					'description' => __( 'Include fix recommendations', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'ai_enhanced'             => array(
					'type'        => 'boolean',
					'description' => __( 'Use AI for deeper analysis (consumes tokens)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'model'                   => array(
					'type'        => 'string',
					'description' => __( 'AI model to use for enhanced analysis', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'requires-capability',
			'consumes-tokens',
			'model-dependent',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Get code to analyze.
		$code = '';

		if ( ! empty( $arguments['code'] ) ) {
			$code = $arguments['code'];
		} elseif ( ! empty( $arguments['tool_file'] ) ) {
			$tool_file = sanitize_text_field( $arguments['tool_file'] );

			// Security: Resolve canonical path to prevent directory traversal attacks.
			$resolved = realpath( $tool_file );
			if ( false === $resolved ) {
				return array(
					'success' => false,
					'error'   => __( 'Tool file not found or not accessible.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Security: Restrict to the WordPress content directory so this tool
			// cannot be used to read arbitrary server files (e.g. /etc/passwd).
			if ( 0 !== strpos( wp_normalize_path( $resolved ), trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Tool file must be within the WordPress content directory.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local PHP tool file.
			$code = file_get_contents( $resolved );
		}

		if ( empty( $code ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Either code or tool_file must be provided.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$severity_threshold      = isset( $arguments['severity_threshold'] ) ? sanitize_text_field( $arguments['severity_threshold'] ) : 'medium';
		$check_categories        = isset( $arguments['check_categories'] ) ? array_map( 'sanitize_text_field', (array) $arguments['check_categories'] ) : array( 'injection', 'xss', 'capability', 'sanitization' );
		$include_recommendations = isset( $arguments['include_recommendations'] ) ? (bool) $arguments['include_recommendations'] : true;
		$ai_enhanced             = isset( $arguments['ai_enhanced'] ) ? (bool) $arguments['ai_enhanced'] : false;

		// Perform security checks.
		$security_issues = array();

		if ( in_array( 'injection', $check_categories, true ) ) {
			$security_issues = array_merge( $security_issues, $this->check_sql_injection( $code ) );
		}

		if ( in_array( 'xss', $check_categories, true ) ) {
			$security_issues = array_merge( $security_issues, $this->check_xss_vulnerabilities( $code ) );
		}

		if ( in_array( 'capability', $check_categories, true ) ) {
			$security_issues = array_merge( $security_issues, $this->check_capability_checks( $code ) );
		}

		if ( in_array( 'sanitization', $check_categories, true ) ) {
			$security_issues = array_merge( $security_issues, $this->check_input_sanitization( $code ) );
		}

		if ( in_array( 'csrf', $check_categories, true ) ) {
			$security_issues = array_merge( $security_issues, $this->check_csrf_protection( $code ) );
		}

		if ( in_array( 'file-ops', $check_categories, true ) ) {
			$security_issues = array_merge( $security_issues, $this->check_file_operations( $code ) );
		}

		if ( in_array( 'external-api', $check_categories, true ) ) {
			$security_issues = array_merge( $security_issues, $this->check_external_api_calls( $code ) );
		}

		// Filter by severity threshold.
		$filtered_issues = $this->filter_by_severity( $security_issues, $severity_threshold );

		// Add recommendations if requested.
		if ( $include_recommendations ) {
			$filtered_issues = $this->add_recommendations( $filtered_issues );
		}

		// AI-enhanced analysis if requested.
		$ai_analysis = null;
		if ( $ai_enhanced ) {
			$ai_analysis = $this->perform_ai_analysis( $code, $arguments, $context );
		}

		// Calculate security score.
		$security_score = $this->calculate_security_score( $security_issues );

		return array(
			'success'         => true,
			'security_score'  => $security_score,
			'total_issues'    => count( $filtered_issues ),
			'critical_count'  => $this->count_by_severity( $filtered_issues, 'critical' ),
			'high_count'      => $this->count_by_severity( $filtered_issues, 'high' ),
			'medium_count'    => $this->count_by_severity( $filtered_issues, 'medium' ),
			'low_count'       => $this->count_by_severity( $filtered_issues, 'low' ),
			'issues'          => $filtered_issues,
			'ai_analysis'     => $ai_analysis,
			'recommendations' => $this->generate_summary_recommendations( $filtered_issues ),
		);
	}

	/**
	 * Check for SQL injection vulnerabilities.
	 *
	 * @param string $code Tool code.
	 * @return array Security issues found.
	 */
	private function check_sql_injection( $code ) {
		$issues = array();

		// Check for direct SQL queries without preparation.
		if ( preg_match( '/\$wpdb->query\s*\(\s*["\']/', $code ) ) {
			$issues[] = array(
				'type'        => 'sql_injection',
				'severity'    => 'critical',
				'description' => __( 'Direct SQL query detected without prepared statement.', 'mcp-ai-wpoos-pro' ),
				'line'        => 0,
			);
		}

		// Check for variable concatenation in queries.
		if ( preg_match( '/\$wpdb->query\s*\(.*?\$/', $code ) || preg_match( '/\$wpdb->get_/', $code ) ) {
			$issues[] = array(
				'type'        => 'sql_injection',
				'severity'    => 'high',
				'description' => __( 'Potential SQL injection via variable concatenation.', 'mcp-ai-wpoos-pro' ),
				'line'        => 0,
			);
		}

		return $issues;
	}

	/**
	 * Check for XSS vulnerabilities.
	 *
	 * @param string $code Tool code.
	 * @return array Security issues found.
	 */
	private function check_xss_vulnerabilities( $code ) {
		$issues = array();

		// Check for unescaped output.
		if ( preg_match( '/echo\s+\$/', $code ) && strpos( $code, 'esc_' ) === false ) {
			$issues[] = array(
				'type'        => 'xss',
				'severity'    => 'high',
				'description' => __( 'Potential XSS: Variable echoed without escaping.', 'mcp-ai-wpoos-pro' ),
				'line'        => 0,
			);
		}

		// Check for print statements.
		if ( preg_match( '/print\s+\$/', $code ) ) {
			$issues[] = array(
				'type'        => 'xss',
				'severity'    => 'medium',
				'description' => __( 'Use echo with proper escaping instead of print.', 'mcp-ai-wpoos-pro' ),
				'line'        => 0,
			);
		}

		return $issues;
	}

	/**
	 * Check for proper capability checks.
	 *
	 * @param string $code Tool code.
	 * @return array Security issues found.
	 */
	private function check_capability_checks( $code ) {
		$issues = array();

		// Check if execute method has capability check.
		if ( strpos( $code, 'public function execute' ) !== false && strpos( $code, 'current_user_can' ) === false ) {
			$issues[] = array(
				'type'        => 'capability',
				'severity'    => 'high',
				'description' => __( 'No capability check found in execute() method.', 'mcp-ai-wpoos-pro' ),
				'line'        => 0,
			);
		}

		return $issues;
	}

	/**
	 * Check input sanitization.
	 *
	 * @param string $code Tool code.
	 * @return array Security issues found.
	 */
	private function check_input_sanitization( $code ) {
		$issues = array();

		// Check if arguments are used without sanitization.
		if ( preg_match( '/\$arguments\[/', $code ) && strpos( $code, 'sanitize_' ) === false ) {
			$issues[] = array(
				'type'        => 'sanitization',
				'severity'    => 'high',
				'description' => __( 'Input arguments used without sanitization.', 'mcp-ai-wpoos-pro' ),
				'line'        => 0,
			);
		}

		return $issues;
	}

	/**
	 * Check CSRF protection.
	 *
	 * @param string $code Tool code.
	 * @return array Security issues found.
	 */
	private function check_csrf_protection( $code ) {
		$issues = array();

		// Check for state-changing operations without nonce verification.
		$state_changing_patterns = array( 'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'update_option', 'delete_option' );

		foreach ( $state_changing_patterns as $pattern ) {
			if ( strpos( $code, $pattern ) !== false && strpos( $code, 'wp_verify_nonce' ) === false ) {
				$issues[] = array(
					'type'        => 'csrf',
					'severity'    => 'medium',
					'description' => sprintf(
						/* translators: %s: function name */
						__( 'State-changing operation (%s) without nonce verification.', 'mcp-ai-wpoos-pro' ),
						$pattern
					),
					'line'        => 0,
				);
				break;
			}
		}

		return $issues;
	}

	/**
	 * Check file operations.
	 *
	 * @param string $code Tool code.
	 * @return array Security issues found.
	 */
	private function check_file_operations( $code ) {
		$issues = array();

		// Check for file operations.
		$file_functions = array( 'file_get_contents', 'file_put_contents', 'fopen', 'unlink', 'file' );

		foreach ( $file_functions as $func ) {
			if ( strpos( $code, $func ) !== false ) {
				$issues[] = array(
					'type'        => 'file_operations',
					'severity'    => 'medium',
					'description' => sprintf(
						/* translators: %s: function name */
						__( 'File operation (%s) detected. Ensure path validation.', 'mcp-ai-wpoos-pro' ),
						$func
					),
					'line'        => 0,
				);
			}
		}

		return $issues;
	}

	/**
	 * Check external API calls.
	 *
	 * @param string $code Tool code.
	 * @return array Security issues found.
	 */
	private function check_external_api_calls( $code ) {
		$issues = array();

		// Check for HTTP requests.
		if ( strpos( $code, 'wp_remote_' ) !== false ) {
			$issues[] = array(
				'type'        => 'external_api',
				'severity'    => 'low',
				'description' => __( 'External API call detected. Ensure SSL verification and timeout handling.', 'mcp-ai-wpoos-pro' ),
				'line'        => 0,
			);
		}

		return $issues;
	}

	/**
	 * Filter issues by severity threshold.
	 *
	 * @param array  $issues   Security issues.
	 * @param string $threshold Severity threshold.
	 * @return array Filtered issues.
	 */
	private function filter_by_severity( $issues, $threshold ) {
		$severity_levels = array(
			'critical' => 4,
			'high'     => 3,
			'medium'   => 2,
			'low'      => 1,
			'info'     => 0,
		);
		$threshold_level = isset( $severity_levels[ $threshold ] ) ? $severity_levels[ $threshold ] : 2;

		return array_filter(
			$issues,
			function ( $issue ) use ( $severity_levels, $threshold_level ) {
				$issue_level = isset( $severity_levels[ $issue['severity'] ] ) ? $severity_levels[ $issue['severity'] ] : 0;
				return $issue_level >= $threshold_level;
			}
		);
	}

	/**
	 * Add recommendations to issues.
	 *
	 * @param array $issues Security issues.
	 * @return array Issues with recommendations.
	 */
	private function add_recommendations( $issues ) {
		foreach ( $issues as &$issue ) {
			$issue['recommendation'] = $this->get_recommendation_for_issue( $issue['type'] );
		}
		return $issues;
	}

	/**
	 * Get recommendation for issue type.
	 *
	 * @param string $type Issue type.
	 * @return string Recommendation.
	 */
	private function get_recommendation_for_issue( $type ) {
		$recommendations = array(
			'sql_injection'   => __( 'Use $wpdb->prepare() for all database queries with user input.', 'mcp-ai-wpoos-pro' ),
			'xss'             => __( 'Use esc_html(), esc_attr(), or esc_url() when outputting variables.', 'mcp-ai-wpoos-pro' ),
			'capability'      => __( 'Add current_user_can() check at the beginning of execute() method.', 'mcp-ai-wpoos-pro' ),
			'sanitization'    => __( 'Use sanitize_text_field(), sanitize_textarea_field(), or appropriate sanitization function.', 'mcp-ai-wpoos-pro' ),
			'csrf'            => __( 'Implement nonce verification for state-changing operations.', 'mcp-ai-wpoos-pro' ),
			'file_operations' => __( 'Validate file paths and use WordPress file system API (WP_Filesystem).', 'mcp-ai-wpoos-pro' ),
			'external_api'    => __( 'Use wp_remote_get/post with timeout and SSL verification enabled.', 'mcp-ai-wpoos-pro' ),
		);

		return isset( $recommendations[ $type ] ) ? $recommendations[ $type ] : __( 'Review code for security best practices.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Calculate security score.
	 *
	 * @param array $issues Security issues.
	 * @return int Security score (0-100).
	 */
	private function calculate_security_score( $issues ) {
		$score              = 100;
		$severity_penalties = array(
			'critical' => 25,
			'high'     => 15,
			'medium'   => 8,
			'low'      => 3,
		);

		foreach ( $issues as $issue ) {
			$severity = $issue['severity'];
			if ( isset( $severity_penalties[ $severity ] ) ) {
				$score -= $severity_penalties[ $severity ];
			}
		}

		return max( 0, $score );
	}

	/**
	 * Count issues by severity.
	 *
	 * @param array  $issues   Security issues.
	 * @param string $severity Severity level.
	 * @return int Count.
	 */
	private function count_by_severity( $issues, $severity ) {
		return count(
			array_filter(
				$issues,
				function ( $issue ) use ( $severity ) {
					return $issue['severity'] === $severity;
				}
			)
		);
	}

	/**
	 * Generate summary recommendations.
	 *
	 * @param array $issues Security issues.
	 * @return array Summary recommendations.
	 */
	private function generate_summary_recommendations( $issues ) {
		$recommendations = array();
		$critical_count  = $this->count_by_severity( $issues, 'critical' );
		$high_count      = $this->count_by_severity( $issues, 'high' );

		if ( $critical_count > 0 ) {
			$recommendations[] = __( 'Address critical security issues immediately before deployment.', 'mcp-ai-wpoos-pro' );
		}

		if ( $high_count > 0 ) {
			$recommendations[] = __( 'Fix high-severity issues to prevent potential exploits.', 'mcp-ai-wpoos-pro' );
		}

		if ( empty( $issues ) ) {
			$recommendations[] = __( 'No major security issues detected. Good job!', 'mcp-ai-wpoos-pro' );
		}

		return $recommendations;
	}

	/**
	 * Perform AI-enhanced security analysis.
	 *
	 * @param string $code      Tool code.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array|null AI analysis result.
	 */
	private function perform_ai_analysis( $code, $arguments, $context ) {
		// Placeholder for AI-enhanced analysis.
		// Would integrate with AI service for deeper code analysis.
		return null;
	}
}
