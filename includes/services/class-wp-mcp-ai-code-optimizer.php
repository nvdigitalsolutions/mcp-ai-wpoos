<?php
/**
 * Code Generation Optimizer Service
 *
 * Special handling for code generation tasks with context optimization,
 * validation, and session management. Part of Phase 3: Advanced Reasoning
 * Support enhancements.
 *
 * Features:
 * - Context optimization for long code sequences
 * - Syntax and security validation
 * - Coding session context preservation
 * - WordPress coding standards integration
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Code Generation Optimizer class
 *
 * Optimizes code generation tasks for better quality and performance.
 *
 * @since 1.1.1
 */
class WP_MCP_AI_Code_Optimizer {

	/**
	 * Storage keys
	 */
	const CODING_SESSION_KEY = 'wp_mcp_ai_coding_session_';
	const CODE_CACHE_KEY     = 'wp_mcp_ai_code_cache_';

	/**
	 * Configuration
	 */
	const SESSION_TTL        = 3600; // 1 hour.
	const MAX_CONTEXT_LENGTH = 8000; // Tokens approximate.
	const CACHE_TTL          = 1800; // 30 minutes.

	/**
	 * Optimize context for long code sequences
	 *
	 * Reduces context size while preserving essential information.
	 *
	 * @param string $code_task Code generation task.
	 * @param string $existing_code Existing code context.
	 * @param array  $options Optimization options.
	 * @return array Optimized context.
	 */
	public function optimize_code_context( $code_task, $existing_code, $options = array() ) {
		$defaults = array(
			'max_length'        => self::MAX_CONTEXT_LENGTH,
			'include_comments'  => true,
			'include_structure' => true,
			'preserve_recent'   => true,
		);

		$options = wp_parse_args( $options, $defaults );

		// Extract relevant sections.
		$relevant_sections = $this->extract_relevant_sections( $existing_code, $code_task );

		// Prioritize recent changes.
		if ( $options['preserve_recent'] ) {
			$relevant_sections = $this->prioritize_recent_changes( $relevant_sections );
		}

		// Include necessary dependencies.
		$dependencies = $this->extract_dependencies( $existing_code, $code_task );

		// Compress boilerplate.
		$compressed = $this->compress_boilerplate( $relevant_sections, $dependencies );

		return array(
			'optimized_code'    => $compressed['code'],
			'structure'         => $compressed['structure'],
			'dependencies'      => $dependencies,
			'original_length'   => strlen( $existing_code ),
			'optimized_length'  => strlen( $compressed['code'] ),
			'compression_ratio' => $this->calculate_compression_ratio(
				strlen( $existing_code ),
				strlen( $compressed['code'] )
			),
		);
	}

	/**
	 * Validate generated code
	 *
	 * Performs syntax, style, and security validation.
	 *
	 * @param string $generated_code Generated code.
	 * @param string $language Programming language.
	 * @param array  $requirements Validation requirements.
	 * @return array Validation results.
	 */
	public function validate_code( $generated_code, $language, $requirements = array() ) {
		$results = array(
			'valid'       => true,
			'issues'      => array(),
			'suggestions' => array(),
			'security'    => array(),
		);

		// Syntax validation.
		$syntax_check = $this->validate_syntax( $generated_code, $language );
		if ( ! $syntax_check['valid'] ) {
			$results['valid']  = false;
			$results['issues'] = array_merge( $results['issues'], $syntax_check['errors'] );
		}

		// Style checking (WPCS for PHP).
		if ( 'php' === $language ) {
			$style_check            = $this->check_wordpress_coding_standards( $generated_code );
			$results['suggestions'] = array_merge( $results['suggestions'], $style_check['suggestions'] );
		}

		// Security scanning.
		$security_check = $this->scan_security_issues( $generated_code, $language );
		if ( ! empty( $security_check['vulnerabilities'] ) ) {
			$results['valid']    = false;
			$results['security'] = $security_check['vulnerabilities'];
		}

		// Logic verification against requirements.
		if ( ! empty( $requirements ) ) {
			$logic_check = $this->verify_logic( $generated_code, $requirements );
			if ( ! $logic_check['valid'] ) {
				$results['suggestions'] = array_merge(
					$results['suggestions'],
					$logic_check['missing_requirements']
				);
			}
		}

		return $results;
	}

	/**
	 * Preserve coding context across sessions
	 *
	 * Stores session data for continuity in coding tasks.
	 *
	 * @param string $session_id Session identifier.
	 * @param array  $context_data Context data to preserve.
	 * @return bool Success status.
	 */
	public function preserve_coding_context( $session_id, $context_data ) {
		$session_key = self::CODING_SESSION_KEY . sanitize_key( $session_id );

		$session_data = array(
			'file_structure'   => $context_data['file_structure'] ?? array(),
			'code_changes'     => $context_data['code_changes'] ?? array(),
			'dependency_graph' => $context_data['dependency_graph'] ?? array(),
			'cached_code'      => $context_data['cached_code'] ?? array(),
			'timestamp'        => time(),
		);

		return set_transient( $session_key, $session_data, self::SESSION_TTL );
	}

	/**
	 * Retrieve coding session context
	 *
	 * @param string $session_id Session identifier.
	 * @return array|false Session data or false if not found.
	 */
	public function get_coding_context( $session_id ) {
		$session_key = self::CODING_SESSION_KEY . sanitize_key( $session_id );
		return get_transient( $session_key );
	}

	/**
	 * Extract relevant code sections
	 *
	 * @param string $code Full code.
	 * @param string $task Task description.
	 * @return array Relevant code sections.
	 */
	protected function extract_relevant_sections( $code, $task ) {
		// Simple extraction based on function/class names in task.
		$sections = array();

		// Extract function names from task.
		preg_match_all( '/\b([a-z_][a-z0-9_]*)\s*\(/i', $task, $function_matches );
		$mentioned_functions = $function_matches[1] ?? array();

		// Extract class names from task.
		preg_match_all( '/\b([A-Z][a-zA-Z0-9_]*)\b/', $task, $class_matches );
		$mentioned_classes = $class_matches[1] ?? array();

		// Find relevant sections in code.
		$lines               = explode( "\n", $code );
		$in_relevant_section = false;
		$current_section     = array();

		foreach ( $lines as $line ) {
			// Check if line starts a relevant function or class.
			foreach ( $mentioned_functions as $func ) {
				if ( false !== stripos( $line, "function $func" ) ) {
					$in_relevant_section = true;
					break;
				}
			}

			foreach ( $mentioned_classes as $class ) {
				if ( false !== stripos( $line, "class $class" ) ) {
					$in_relevant_section = true;
					break;
				}
			}

			if ( $in_relevant_section ) {
				$current_section[] = $line;

				// Check for section end (closing brace).
				if ( false !== strpos( $line, '}' ) && empty( trim( str_replace( '}', '', $line ) ) ) ) {
					$sections[]          = implode( "\n", $current_section );
					$current_section     = array();
					$in_relevant_section = false;
				}
			}
		}

		return $sections;
	}

	/**
	 * Prioritize recent changes
	 *
	 * @param array $sections Code sections.
	 * @return array Prioritized sections.
	 */
	protected function prioritize_recent_changes( $sections ) {
		// In a real implementation, this would use git history or timestamps.
		// For now, just return as-is.
		return $sections;
	}

	/**
	 * Extract dependencies
	 *
	 * @param string $code Code to analyze.
	 * @param string $task Task description.
	 * @return array Dependencies.
	 */
	protected function extract_dependencies( $code, $task ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for task-specific extraction.
		$dependencies = array();

		// Extract use statements (PHP).
		preg_match_all( '/use\s+([^;]+);/', $code, $use_matches );
		if ( ! empty( $use_matches[1] ) ) {
			$dependencies['uses'] = $use_matches[1];
		}

		// Extract requires/includes.
		preg_match_all( '/require(?:_once)?\s*[\'"]([^\'"]+)[\'"]/', $code, $require_matches );
		if ( ! empty( $require_matches[1] ) ) {
			$dependencies['requires'] = $require_matches[1];
		}

		return $dependencies;
	}

	/**
	 * Compress boilerplate code
	 *
	 * @param array $sections Code sections.
	 * @param array $dependencies Dependencies.
	 * @return array Compressed code and structure.
	 */
	protected function compress_boilerplate( $sections, $dependencies ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for dependency optimization.
		$compressed_code = implode( "\n\n", $sections );

		// Remove excessive whitespace.
		$compressed_code = preg_replace( '/\n{3,}/', "\n\n", $compressed_code );

		// Extract structure.
		$structure = $this->extract_code_structure( $compressed_code );

		return array(
			'code'      => $compressed_code,
			'structure' => $structure,
		);
	}

	/**
	 * Extract code structure
	 *
	 * @param string $code Code to analyze.
	 * @return array Code structure.
	 */
	protected function extract_code_structure( $code ) {
		$structure = array(
			'classes'   => array(),
			'functions' => array(),
			'constants' => array(),
		);

		// Extract class names.
		preg_match_all( '/class\s+([A-Za-z_][A-Za-z0-9_]*)/i', $code, $class_matches );
		if ( ! empty( $class_matches[1] ) ) {
			$structure['classes'] = $class_matches[1];
		}

		// Extract function names.
		preg_match_all( '/function\s+([a-z_][a-z0-9_]*)/i', $code, $function_matches );
		if ( ! empty( $function_matches[1] ) ) {
			$structure['functions'] = $function_matches[1];
		}

		return $structure;
	}

	/**
	 * Calculate compression ratio
	 *
	 * @param int $original_length Original length.
	 * @param int $compressed_length Compressed length.
	 * @return float Compression ratio.
	 */
	protected function calculate_compression_ratio( $original_length, $compressed_length ) {
		if ( 0 === $original_length ) {
			return 0;
		}

		return round( ( 1 - ( $compressed_length / $original_length ) ) * 100, 2 );
	}

	/**
	 * Validate PHP syntax
	 *
	 * @param string $code Code to validate.
	 * @param string $language Programming language.
	 * @return array Validation result.
	 */
	protected function validate_syntax( $code, $language ) {
		if ( 'php' !== $language ) {
			// Only PHP syntax checking supported for now.
			return array(
				'valid'  => true,
				'errors' => array(),
			);
		}

		// Use php -l for syntax checking.
		$temp_file = tempnam( sys_get_temp_dir(), 'php_syntax_' );
		file_put_contents( $temp_file, $code );

		$output     = array();
		$return_var = 0;
		exec( 'php -l ' . escapeshellarg( $temp_file ) . ' 2>&1', $output, $return_var );

		unlink( $temp_file );

		$valid = 0 === $return_var;

		return array(
			'valid'  => $valid,
			'errors' => $valid ? array() : $output,
		);
	}

	/**
	 * Check WordPress coding standards
	 *
	 * @param string $code PHP code to check.
	 * @return array Style check results.
	 */
	protected function check_wordpress_coding_standards( $code ) {
		// Placeholder - real implementation would use PHPCS.
		$suggestions = array();

		// Simple checks.
		if ( false === strpos( $code, '<?php' ) ) {
			$suggestions[] = 'Missing PHP opening tag';
		}

		// Check for proper indentation (tabs vs spaces).
		if ( preg_match( '/^\s{2,4}/m', $code ) ) {
			$suggestions[] = 'Use tabs for indentation, not spaces';
		}

		// Check for inline comments.
		if ( false === strpos( $code, '//' ) && false === strpos( $code, '/*' ) ) {
			$suggestions[] = 'Consider adding comments for complex logic';
		}

		return array(
			'suggestions' => $suggestions,
		);
	}

	/**
	 * Scan for security issues
	 *
	 * @param string $code Code to scan.
	 * @param string $language Programming language.
	 * @return array Security scan results.
	 */
	protected function scan_security_issues( $code, $language ) {
		$vulnerabilities = array();

		if ( 'php' === $language ) {
			// Check for common PHP security issues.
			if ( false !== stripos( $code, 'eval(' ) ) {
				$vulnerabilities[] = array(
					'type'     => 'dangerous_function',
					'severity' => 'high',
					'message'  => 'Use of eval() detected - security risk',
				);
			}

			// Check for SQL injection risks.
			if ( preg_match( '/\$wpdb->(query|get_results|get_var)\s*\(\s*["\'].*\$/', $code ) ) {
				$vulnerabilities[] = array(
					'type'     => 'sql_injection',
					'severity' => 'high',
					'message'  => 'Potential SQL injection - use prepared statements',
				);
			}

			// Check for XSS risks.
			if ( preg_match( '/echo\s+\$|print\s+\$/', $code ) && false === strpos( $code, 'esc_' ) ) {
				$vulnerabilities[] = array(
					'type'     => 'xss_risk',
					'severity' => 'medium',
					'message'  => 'Unescaped output detected - use esc_html(), esc_attr(), etc.',
				);
			}

			// Check for file inclusion risks.
			if ( preg_match( '/include|require.*\$/', $code ) ) {
				$vulnerabilities[] = array(
					'type'     => 'file_inclusion',
					'severity' => 'medium',
					'message'  => 'Dynamic file inclusion detected - ensure proper sanitization',
				);
			}
		}

		return array(
			'vulnerabilities' => $vulnerabilities,
		);
	}

	/**
	 * Verify logic against requirements
	 *
	 * @param string $code Generated code.
	 * @param array  $requirements Requirements.
	 * @return array Verification results.
	 */
	protected function verify_logic( $code, $requirements ) {
		$missing_requirements = array();

		foreach ( $requirements as $requirement ) {
			// Simple check: requirement keyword appears in code.
			if ( false === stripos( $code, $requirement ) ) {
				$missing_requirements[] = "Requirement may not be met: $requirement";
			}
		}

		return array(
			'valid'                => empty( $missing_requirements ),
			'missing_requirements' => $missing_requirements,
		);
	}

	/**
	 * Clear coding session
	 *
	 * @param string $session_id Session identifier.
	 * @return bool Success status.
	 */
	public function clear_session( $session_id ) {
		$session_key = self::CODING_SESSION_KEY . sanitize_key( $session_id );
		return delete_transient( $session_key );
	}
}
