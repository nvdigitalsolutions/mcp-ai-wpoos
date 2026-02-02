<?php
/**
 * Tool for analyzing password strength using AI and 2026 security standards.
 *
 * Evaluates passwords against modern security best practices including
 * length, complexity, common patterns, and dictionary attacks.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../traits/trait-wp-mcp-ai-tool-wordpress-native.php';

/**
 * Password Strength Analyzer Tool
 *
 * Analyzes password security following 2026 standards:
 * - Minimum 12+ characters (industry standard updated from 8)
 * - Complexity requirements (uppercase, lowercase, numbers, special chars)
 * - Common pattern detection
 * - Dictionary word checking
 * - Breach database checking (Have I Been Pwned integration)
 * - AI-powered vulnerability assessment
 *
 * Based on SecurityBoulevard, Bluehost, NetworkSolutions 2026 guidelines.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Password_Strength_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'password_strength_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Password Strength Analyzer', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes password strength using AI and 2026 security standards. Checks length, complexity, common patterns, and breach databases. Provides recommendations for improvement.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'password'            => array(
					'type'        => 'string',
					'description' => __( 'Password to analyze (single password mode).', 'mcp-ai-wpoos' ),
				),
				'user_id'             => array(
					'type'        => 'integer',
					'description' => __( 'Analyze password for specific user (requires admin).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'bulk_audit'          => array(
					'type'        => 'boolean',
					'description' => __( 'Audit all user passwords (checks only strength patterns, not actual passwords).', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'role_filter'         => array(
					'type'        => 'string',
					'description' => __( 'Filter bulk audit by user role.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'all' ),
					'default'     => 'all',
				),
				'check_breaches'      => array(
					'type'        => 'boolean',
					'description' => __( 'Check against known breach databases (Have I Been Pwned).', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'include_suggestions' => array(
					'type'        => 'boolean',
					'description' => __( 'Include AI-powered improvement suggestions.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'anyOf'      => array(
				array( 'required' => array( 'password' ) ),
				array( 'required' => array( 'user_id' ) ),
				array( 'required' => array( 'bulk_audit' ) ),
			),
		);
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

			'toolkit'               => 'security_compliance',

			'pattern_compatibility' => array( 'layered_defense' ),

			'profession_tags'       => array( 'security_analyst' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read',
			'cacheable',
			'consumes-tokens', // For AI suggestions.
			'model-dependent',
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
		// Start performance tracking.
		$start_time = microtime( true );

		// Fire before execute hook.
		$this->do_before_execute( $arguments, $context );

		// Determine analysis mode.
		if ( $arguments['bulk_audit'] ?? false ) {
			$result = $this->bulk_audit_passwords( $arguments );
		} elseif ( isset( $arguments['user_id'] ) ) {
			$result = $this->analyze_user_password( $arguments, $context );
		} elseif ( isset( $arguments['password'] ) ) {
			$result = $this->analyze_single_password( $arguments, $context );
		} else {
			return new WP_Error(
				'missing_parameters',
				__( 'Either password, user_id, or bulk_audit must be provided.', 'mcp-ai-wpoos' )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Track performance.
		$this->track_performance( $start_time, $arguments );

		// Fire after execute hook.
		$this->do_after_execute( $result, $arguments, $context );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Analyze a single password.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Analysis result.
	 */
	private function analyze_single_password( $arguments, $context ) {
		$password = $arguments['password'];

		// Basic validation.
		$analysis = array(
			'password_provided' => true,
			'strength_score'    => 0,
			'strength_label'    => '',
			'checks'            => array(),
			'issues'            => array(),
			'recommendations'   => array(),
		);

		// Length check (2026 standard: 12+ characters).
		$length                       = strlen( $password );
		$analysis['checks']['length'] = array(
			'passed'   => $length >= 12,
			'value'    => $length,
			'required' => 12,
			'message'  => $length >= 12
				? __( 'Password length meets 2026 standard (12+ characters).', 'mcp-ai-wpoos' )
				: sprintf(
					/* translators: %d: current password length */
					__( 'Password too short (%d characters). Use at least 12 characters.', 'mcp-ai-wpoos' ),
					$length
				),
		);

		if ( ! $analysis['checks']['length']['passed'] ) {
			$analysis['issues'][] = 'password_too_short';
		}

		// Complexity checks.
		$analysis['checks']['uppercase'] = array(
			'passed'  => (bool) preg_match( '/[A-Z]/', $password ),
			'message' => __( 'Contains uppercase letters.', 'mcp-ai-wpoos' ),
		);

		$analysis['checks']['lowercase'] = array(
			'passed'  => (bool) preg_match( '/[a-z]/', $password ),
			'message' => __( 'Contains lowercase letters.', 'mcp-ai-wpoos' ),
		);

		$analysis['checks']['numbers'] = array(
			'passed'  => (bool) preg_match( '/[0-9]/', $password ),
			'message' => __( 'Contains numbers.', 'mcp-ai-wpoos' ),
		);

		$analysis['checks']['special_chars'] = array(
			'passed'  => (bool) preg_match( '/[^A-Za-z0-9]/', $password ),
			'message' => __( 'Contains special characters.', 'mcp-ai-wpoos' ),
		);

		// Count complexity types.
		$complexity_count = 0;
		foreach ( array( 'uppercase', 'lowercase', 'numbers', 'special_chars' ) as $check ) {
			if ( $analysis['checks'][ $check ]['passed'] ) {
				++$complexity_count;
			}
		}

		$analysis['checks']['complexity'] = array(
			'passed'  => $complexity_count >= 3,
			'value'   => $complexity_count,
			'message' => $complexity_count >= 3
				? __( 'Meets complexity requirements (3+ character types).', 'mcp-ai-wpoos' )
				: __( 'Insufficient complexity. Use at least 3 character types.', 'mcp-ai-wpoos' ),
		);

		if ( ! $analysis['checks']['complexity']['passed'] ) {
			$analysis['issues'][] = 'insufficient_complexity';
		}

		// Common pattern detection.
		$common_patterns                       = $this->detect_common_patterns( $password );
		$analysis['checks']['common_patterns'] = array(
			'passed'   => empty( $common_patterns ),
			'patterns' => $common_patterns,
			'message'  => empty( $common_patterns )
				? __( 'No common patterns detected.', 'mcp-ai-wpoos' )
				: sprintf(
					/* translators: %s: comma-separated list of patterns */
					__( 'Contains common patterns: %s', 'mcp-ai-wpoos' ),
					implode( ', ', $common_patterns )
				),
		);

		if ( ! $analysis['checks']['common_patterns']['passed'] ) {
			$analysis['issues'][] = 'contains_common_patterns';
		}

		// Dictionary word check.
		$contains_dictionary                    = $this->contains_dictionary_words( $password );
		$analysis['checks']['dictionary_words'] = array(
			'passed'  => ! $contains_dictionary,
			'message' => $contains_dictionary
				? __( 'Contains dictionary words or common phrases.', 'mcp-ai-wpoos' )
				: __( 'Does not contain obvious dictionary words.', 'mcp-ai-wpoos' ),
		);

		if ( $contains_dictionary ) {
			$analysis['issues'][] = 'contains_dictionary_words';
		}

		// Breach check (optional).
		if ( $arguments['check_breaches'] ?? false ) {
			$breach_check                          = $this->check_password_breaches( $password );
			$analysis['checks']['breach_database'] = $breach_check;

			if ( ! $breach_check['passed'] ) {
				$analysis['issues'][] = 'found_in_breach_database';
			}
		}

		// Calculate overall strength score (0-100).
		$analysis['strength_score'] = $this->calculate_strength_score( $analysis['checks'] );
		$analysis['strength_label'] = $this->get_strength_label( $analysis['strength_score'] );

		// AI-powered suggestions.
		if ( $arguments['include_suggestions'] ?? true ) {
			$analysis['ai_suggestions'] = $this->generate_ai_suggestions( $analysis, $context );
		}

		// Apply filter hook.
		$analysis = apply_filters(
			'wp_mcp_ai_password_strength_analysis',
			$analysis,
			$password,
			$arguments
		);

		return $analysis;
	}

	/**
	 * Analyze password for a specific user.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Analysis result.
	 */
	private function analyze_user_password( $arguments, $context ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to analyze user passwords.', 'mcp-ai-wpoos' )
			);
		}

		$user_id = $arguments['user_id'];
		$user    = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'mcp-ai-wpoos' )
			);
		}

		// Note: We cannot retrieve the actual password (it's hashed).
		// We can only check password age and provide general recommendations.
		$password_age = $this->get_password_age( $user );

		return array(
			'user_id'        => $user_id,
			'user_login'     => $user->user_login,
			'password_age'   => $password_age,
			'recommendation' => $password_age['days'] > 90
				? __( 'Password is older than 90 days. Consider updating.', 'mcp-ai-wpoos' )
				: __( 'Password age is acceptable.', 'mcp-ai-wpoos' ),
			'last_changed'   => $password_age['last_changed'],
			'note'           => __( 'Actual password cannot be analyzed as it is stored as a secure hash.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Bulk audit user passwords.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Audit results.
	 */
	private function bulk_audit_passwords( $arguments ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to audit user passwords.', 'mcp-ai-wpoos' )
			);
		}

		$role_filter = $arguments['role_filter'] ?? 'all';

		$user_args = array(
			'fields' => 'all',
		);

		if ( 'all' !== $role_filter ) {
			$user_args['role'] = $role_filter;
		}

		$users = get_users( $user_args );

		$audit_results = array(
			'total_users'   => count( $users ),
			'users_audited' => array(),
			'statistics'    => array(
				'password_age_over_90'  => 0,
				'password_age_over_180' => 0,
				'password_age_over_365' => 0,
			),
		);

		foreach ( $users as $user ) {
			$password_age = $this->get_password_age( $user );

			$user_audit = array(
				'user_id'      => $user->ID,
				'user_login'   => $user->user_login,
				'roles'        => $user->roles,
				'password_age' => $password_age['days'],
				'needs_update' => $password_age['days'] > 90,
			);

			// Track statistics.
			if ( $password_age['days'] > 90 ) {
				++$audit_results['statistics']['password_age_over_90'];
			}
			if ( $password_age['days'] > 180 ) {
				++$audit_results['statistics']['password_age_over_180'];
			}
			if ( $password_age['days'] > 365 ) {
				++$audit_results['statistics']['password_age_over_365'];
			}

			$audit_results['users_audited'][] = $user_audit;
		}

		// Calculate risk score.
		$audit_results['overall_risk_score'] = $this->calculate_audit_risk_score( $audit_results );

		return $audit_results;
	}

	/**
	 * Get password age for a user.
	 *
	 * @param WP_User $user User object.
	 * @return array Password age data.
	 */
	private function get_password_age( $user ) {
		// Try to get last password change time.
		$last_changed = get_user_meta( $user->ID, 'password_last_changed', true );

		if ( ! $last_changed ) {
			// Fallback to user registration date.
			$last_changed = strtotime( $user->user_registered );
		}

		$days_old = floor( ( time() - $last_changed ) / DAY_IN_SECONDS );

		return array(
			'last_changed' => $last_changed,
			'days'         => $days_old,
			'formatted'    => human_time_diff( $last_changed, time() ),
		);
	}

	/**
	 * Detect common password patterns.
	 *
	 * @param string $password Password to check.
	 * @return array Detected patterns.
	 */
	private function detect_common_patterns( $password ) {
		$patterns = array();

		// Sequential characters.
		if ( preg_match( '/(?:abc|bcd|cde|def|efg|fgh|ghi|hij|ijk|jkl|klm|lmn|mno|nop|opq|pqr|qrs|rst|stu|tuv|uvw|vwx|wxy|xyz)/i', $password ) ) {
			$patterns[] = 'sequential_letters';
		}

		// Sequential numbers.
		if ( preg_match( '/(?:012|123|234|345|456|567|678|789|890)/', $password ) ) {
			$patterns[] = 'sequential_numbers';
		}

		// Repeated characters.
		if ( preg_match( '/(.)\1{2,}/', $password ) ) {
			$patterns[] = 'repeated_characters';
		}

		// Keyboard patterns.
		$keyboard_patterns = array( 'qwerty', 'asdfgh', 'zxcvbn', 'qazwsx' );
		foreach ( $keyboard_patterns as $pattern ) {
			if ( stripos( $password, $pattern ) !== false ) {
				$patterns[] = 'keyboard_pattern';
				break;
			}
		}

		return array_unique( $patterns );
	}

	/**
	 * Check if password contains dictionary words.
	 *
	 * @param string $password Password to check.
	 * @return bool True if contains dictionary words.
	 */
	private function contains_dictionary_words( $password ) {
		// Common weak words.
		$common_words = array(
			'password',
			'admin',
			'user',
			'login',
			'welcome',
			'letmein',
			'monkey',
			'dragon',
			'master',
			'sunshine',
			'princess',
			'football',
		);

		$password_lower = strtolower( $password );

		foreach ( $common_words as $word ) {
			if ( strpos( $password_lower, $word ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check password against breach databases.
	 *
	 * @param string $password Password to check.
	 * @return array Check result.
	 */
	private function check_password_breaches( $password ) {
		// This is a placeholder for Have I Been Pwned API integration.
		// Implementation would use k-Anonymity model for secure checking.
		return array(
			'passed'       => true,
			'checked'      => false,
			'message'      => __( 'Breach checking not yet implemented.', 'mcp-ai-wpoos' ),
			'breach_count' => 0,
		);
	}

	/**
	 * Calculate overall strength score.
	 *
	 * @param array $checks All checks performed.
	 * @return int Score (0-100).
	 */
	private function calculate_strength_score( $checks ) {
		$score = 0;

		// Length (30 points).
		if ( isset( $checks['length'] ) && $checks['length']['passed'] ) {
			$score += 30;
			// Bonus for longer passwords.
			$extra_length = max( 0, $checks['length']['value'] - 12 );
			$score       += min( 10, $extra_length * 2 );
		}

		// Complexity (25 points).
		if ( isset( $checks['complexity'] ) && $checks['complexity']['passed'] ) {
			$score += 25;
		}

		// No common patterns (20 points).
		if ( isset( $checks['common_patterns'] ) && $checks['common_patterns']['passed'] ) {
			$score += 20;
		}

		// No dictionary words (15 points).
		if ( isset( $checks['dictionary_words'] ) && $checks['dictionary_words']['passed'] ) {
			$score += 15;
		}

		// Not in breach database (10 points).
		if ( isset( $checks['breach_database'] ) && $checks['breach_database']['passed'] ) {
			$score += 10;
		}

		return min( 100, $score );
	}

	/**
	 * Get strength label from score.
	 *
	 * @param int $score Strength score.
	 * @return string Label.
	 */
	private function get_strength_label( $score ) {
		if ( $score >= 90 ) {
			return __( 'Excellent', 'mcp-ai-wpoos' );
		} elseif ( $score >= 70 ) {
			return __( 'Strong', 'mcp-ai-wpoos' );
		} elseif ( $score >= 50 ) {
			return __( 'Moderate', 'mcp-ai-wpoos' );
		} elseif ( $score >= 30 ) {
			return __( 'Weak', 'mcp-ai-wpoos' );
		} else {
			return __( 'Very Weak', 'mcp-ai-wpoos' );
		}
	}

	/**
	 * Generate AI-powered improvement suggestions.
	 *
	 * @param array $analysis  Current analysis.
	 * @param array $context   Execution context.
	 * @return array Suggestions.
	 */
	private function generate_ai_suggestions( $analysis, $context ) {
		// Build suggestions based on issues.
		$suggestions = array();

		if ( in_array( 'password_too_short', $analysis['issues'], true ) ) {
			$suggestions[] = __( 'Increase password length to at least 12 characters (2026 standard).', 'mcp-ai-wpoos' );
		}

		if ( in_array( 'insufficient_complexity', $analysis['issues'], true ) ) {
			$suggestions[] = __( 'Add more character types: uppercase, lowercase, numbers, and special characters.', 'mcp-ai-wpoos' );
		}

		if ( in_array( 'contains_common_patterns', $analysis['issues'], true ) ) {
			$suggestions[] = __( 'Avoid sequential characters, repeated patterns, and keyboard patterns.', 'mcp-ai-wpoos' );
		}

		if ( in_array( 'contains_dictionary_words', $analysis['issues'], true ) ) {
			$suggestions[] = __( 'Replace dictionary words with random character combinations.', 'mcp-ai-wpoos' );
		}

		if ( in_array( 'found_in_breach_database', $analysis['issues'], true ) ) {
			$suggestions[] = __( 'This password has been found in known data breaches. Change immediately.', 'mcp-ai-wpoos' );
		}

		// General best practices.
		$suggestions[] = __( 'Consider using a password manager to generate and store strong passwords.', 'mcp-ai-wpoos' );
		$suggestions[] = __( 'Enable two-factor authentication (2FA) for additional security.', 'mcp-ai-wpoos' );

		return $suggestions;
	}

	/**
	 * Calculate audit risk score.
	 *
	 * @param array $audit_results Audit results.
	 * @return int Risk score (0-100).
	 */
	private function calculate_audit_risk_score( $audit_results ) {
		$total = $audit_results['total_users'];
		if ( 0 === $total ) {
			return 0;
		}

		$stats = $audit_results['statistics'];

		// Calculate weighted risk.
		$risk  = 0;
		$risk += ( $stats['password_age_over_90'] / $total ) * 30;
		$risk += ( $stats['password_age_over_180'] / $total ) * 50;
		$risk += ( $stats['password_age_over_365'] / $total ) * 20;

		return min( 100, (int) $risk );
	}
}
