<?php
/**
 * Password Strength Analyzer tool (D8 Cluster 2c port of the base
 * plugin's WP_MCP_AI_Tool_Password_Strength_Analyzer — byte-identical
 * slug, schema, error codes, envelope, check set, and scoring;
 * per-mode hook/cache seams via WordPressNativeTrait).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Analyzes password strength using 2026 security standards: length,
 * complexity, common patterns, and (optional) breach databases.
 */
class PasswordStrengthAnalyzerTool extends AbstractAiTool {

	use WordPressNativeTrait;

	public function getSlug(): string {
		return 'password_strength_analyzer';
	}

	public function getName(): string {
		return __( 'Password Strength Analyzer', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Analyzes password strength using AI and 2026 security standards. Checks length, complexity, common patterns, and breach databases. Provides recommendations for improvement.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'password'            => array(
					'type'        => 'string',
					'description' => __( 'Password to analyze (single password mode).', 'nvoos-content-graph-ai' ),
				),
				'user_id'             => array(
					'type'        => 'integer',
					'description' => __( 'Analyze password for specific user (requires admin).', 'nvoos-content-graph-ai' ),
					'minimum'     => 1,
				),
				'bulk_audit'          => array(
					'type'        => 'boolean',
					'description' => __( 'Audit all user passwords (checks only strength patterns, not actual passwords).', 'nvoos-content-graph-ai' ),
					'default'     => false,
				),
				'role_filter'         => array(
					'type'        => 'string',
					'description' => __( 'Filter bulk audit by user role.', 'nvoos-content-graph-ai' ),
					'enum'        => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'all' ),
					'default'     => 'all',
				),
				'check_breaches'      => array(
					'type'        => 'boolean',
					'description' => __( 'Check against known breach databases (Have I Been Pwned).', 'nvoos-content-graph-ai' ),
					'default'     => false,
				),
				'include_suggestions' => array(
					'type'        => 'boolean',
					'description' => __( 'Include AI-powered improvement suggestions.', 'nvoos-content-graph-ai' ),
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

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'read', 'cacheable', 'consumes-tokens', 'model-dependent', 'requires-capability' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		// Start performance tracking.
		$start_time = microtime( true );

		// Fire before execute hook (base-identical).
		$intercepted = $this->do_before_execute( $arguments, $context );
		if ( null !== $intercepted ) {
			return $intercepted;
		}

		// Determine analysis mode.
		if ( $arguments['bulk_audit'] ?? false ) {
			$result = $this->bulk_audit_passwords( $arguments );
		} elseif ( isset( $arguments['user_id'] ) ) {
			$result = $this->analyze_user_password( $arguments, $context );
		} elseif ( isset( $arguments['password'] ) ) {
			$result = $this->analyze_single_password( $arguments, $context );
		} else {
			return new \WP_Error(
				'missing_parameters',
				__( 'Either password, user_id, or bulk_audit must be provided.', 'nvoos-content-graph-ai' )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Track performance (base-identical action).
		$this->track_performance( $start_time, $arguments );

		// Fire after execute hook (base-identical).
		$this->do_after_execute( $result, $arguments, $context );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Analyze a single password (base-identical checks and scoring).
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|\WP_Error Analysis result.
	 */
	private function analyze_single_password( $arguments, $context ) {
		$password = $arguments['password'];

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
				? __( 'Password length meets 2026 standard (12+ characters).', 'nvoos-content-graph-ai' )
				: sprintf(
					/* translators: %d: current password length */
					__( 'Password too short (%d characters). Use at least 12 characters.', 'nvoos-content-graph-ai' ),
					$length
				),
		);

		if ( ! $analysis['checks']['length']['passed'] ) {
			$analysis['issues'][] = 'password_too_short';
		}

		// Complexity checks.
		$analysis['checks']['uppercase'] = array(
			'passed'  => (bool) preg_match( '/[A-Z]/', $password ),
			'message' => __( 'Contains uppercase letters.', 'nvoos-content-graph-ai' ),
		);

		$analysis['checks']['lowercase'] = array(
			'passed'  => (bool) preg_match( '/[a-z]/', $password ),
			'message' => __( 'Contains lowercase letters.', 'nvoos-content-graph-ai' ),
		);

		$analysis['checks']['numbers'] = array(
			'passed'  => (bool) preg_match( '/[0-9]/', $password ),
			'message' => __( 'Contains numbers.', 'nvoos-content-graph-ai' ),
		);

		$analysis['checks']['special_chars'] = array(
			'passed'  => (bool) preg_match( '/[^A-Za-z0-9]/', $password ),
			'message' => __( 'Contains special characters.', 'nvoos-content-graph-ai' ),
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
				? __( 'Meets complexity requirements (3+ character types).', 'nvoos-content-graph-ai' )
				: __( 'Insufficient complexity. Use at least 3 character types.', 'nvoos-content-graph-ai' ),
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
				? __( 'No common patterns detected.', 'nvoos-content-graph-ai' )
				: sprintf(
					/* translators: %s: comma-separated list of patterns */
					__( 'Contains common patterns: %s', 'nvoos-content-graph-ai' ),
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
				? __( 'Contains dictionary words or common phrases.', 'nvoos-content-graph-ai' )
				: __( 'Does not contain obvious dictionary words.', 'nvoos-content-graph-ai' ),
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

		// Apply filter hook (base-identical).
		$analysis = apply_filters(
			'wp_mcp_ai_password_strength_analysis',
			$analysis,
			$password,
			$arguments
		);

		return $analysis;
	}

	/**
	 * Analyze password age for a specific user (base-identical).
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|\WP_Error Analysis result.
	 */
	private function analyze_user_password( $arguments, $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Context reserved for future AI analysis.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to analyze user passwords.', 'nvoos-content-graph-ai' )
			);
		}

		$user_id = $arguments['user_id'];
		$user    = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			return new \WP_Error(
				'user_not_found',
				__( 'User not found.', 'nvoos-content-graph-ai' )
			);
		}

		$password_age = $this->get_password_age( $user );

		return array(
			'user_id'        => $user_id,
			'user_login'     => $user->user_login,
			'password_age'   => $password_age,
			'recommendation' => $password_age['days'] > 90
				? __( 'Password is older than 90 days. Consider updating.', 'nvoos-content-graph-ai' )
				: __( 'Password age is acceptable.', 'nvoos-content-graph-ai' ),
			'last_changed'   => $password_age['last_changed'],
			'note'           => __( 'Actual password cannot be analyzed as it is stored as a secure hash.', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Bulk audit user passwords (base-identical).
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|\WP_Error Audit results.
	 */
	private function bulk_audit_passwords( $arguments ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to audit user passwords.', 'nvoos-content-graph-ai' )
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

		$audit_results['overall_risk_score'] = $this->calculate_audit_risk_score( $audit_results );

		return $audit_results;
	}

	/**
	 * Get password age for a user (base-identical).
	 *
	 * @param \WP_User $user User object.
	 * @return array Password age data.
	 */
	private function get_password_age( $user ) {
		$last_changed = get_user_meta( $user->ID, 'password_last_changed', true );

		if ( ! $last_changed ) {
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
	 * Detect common password patterns (base-identical).
	 *
	 * @param string $password Password to check.
	 * @return array Detected patterns.
	 */
	private function detect_common_patterns( $password ) {
		$patterns = array();

		if ( preg_match( '/(?:abc|bcd|cde|def|efg|fgh|ghi|hij|ijk|jkl|klm|lmn|mno|nop|opq|pqr|qrs|rst|stu|tuv|uvw|vwx|wxy|xyz)/i', $password ) ) {
			$patterns[] = 'sequential_letters';
		}

		if ( preg_match( '/(?:012|123|234|345|456|567|678|789|890)/', $password ) ) {
			$patterns[] = 'sequential_numbers';
		}

		if ( preg_match( '/(.)\1{2,}/', $password ) ) {
			$patterns[] = 'repeated_characters';
		}

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
	 * Check if password contains dictionary words (base-identical).
	 *
	 * @param string $password Password to check.
	 * @return bool True if contains dictionary words.
	 */
	private function contains_dictionary_words( $password ) {
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
	 * Check password against breach databases (base-identical placeholder).
	 *
	 * @param string $password Password to check.
	 * @return array Check result.
	 */
	private function check_password_breaches( $password ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Placeholder for Have I Been Pwned k-Anonymity integration; parameter reserved for the future implementation.
		return array(
			'passed'       => true,
			'checked'      => false,
			'message'      => __( 'Breach checking not yet implemented.', 'nvoos-content-graph-ai' ),
			'breach_count' => 0,
		);
	}

	/**
	 * Calculate overall strength score (base-identical).
	 *
	 * @param array $checks All checks performed.
	 * @return int Score (0-100).
	 */
	private function calculate_strength_score( $checks ) {
		$score = 0;

		// Length (30 points).
		if ( isset( $checks['length'] ) && $checks['length']['passed'] ) {
			$score       += 30;
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
	 * Get strength label from score (base-identical).
	 *
	 * @param int $score Strength score.
	 * @return string Label.
	 */
	private function get_strength_label( $score ) {
		if ( $score >= 90 ) {
			return __( 'Excellent', 'nvoos-content-graph-ai' );
		} elseif ( $score >= 70 ) {
			return __( 'Strong', 'nvoos-content-graph-ai' );
		} elseif ( $score >= 50 ) {
			return __( 'Moderate', 'nvoos-content-graph-ai' );
		} elseif ( $score >= 30 ) {
			return __( 'Weak', 'nvoos-content-graph-ai' );
		} else {
			return __( 'Very Weak', 'nvoos-content-graph-ai' );
		}
	}

	/**
	 * Generate AI-powered improvement suggestions (base-identical).
	 *
	 * @param array $analysis Current analysis.
	 * @param array $context  Execution context.
	 * @return array Suggestions.
	 */
	private function generate_ai_suggestions( $analysis, $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Context reserved for future AI-client integration.
		$suggestions = array();

		if ( in_array( 'password_too_short', $analysis['issues'], true ) ) {
			$suggestions[] = __( 'Increase password length to at least 12 characters (2026 standard).', 'nvoos-content-graph-ai' );
		}

		if ( in_array( 'insufficient_complexity', $analysis['issues'], true ) ) {
			$suggestions[] = __( 'Add more character types: uppercase, lowercase, numbers, and special characters.', 'nvoos-content-graph-ai' );
		}

		if ( in_array( 'contains_common_patterns', $analysis['issues'], true ) ) {
			$suggestions[] = __( 'Avoid sequential characters, repeated patterns, and keyboard patterns.', 'nvoos-content-graph-ai' );
		}

		if ( in_array( 'contains_dictionary_words', $analysis['issues'], true ) ) {
			$suggestions[] = __( 'Replace dictionary words with random character combinations.', 'nvoos-content-graph-ai' );
		}

		if ( in_array( 'found_in_breach_database', $analysis['issues'], true ) ) {
			$suggestions[] = __( 'This password has been found in known data breaches. Change immediately.', 'nvoos-content-graph-ai' );
		}

		// General best practices.
		$suggestions[] = __( 'Consider using a password manager to generate and store strong passwords.', 'nvoos-content-graph-ai' );
		$suggestions[] = __( 'Enable two-factor authentication (2FA) for additional security.', 'nvoos-content-graph-ai' );

		return $suggestions;
	}

	/**
	 * Calculate audit risk score (base-identical).
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

		$risk  = 0;
		$risk += ( $stats['password_age_over_90'] / $total ) * 30;
		$risk += ( $stats['password_age_over_180'] / $total ) * 50;
		$risk += ( $stats['password_age_over_365'] / $total ) * 20;

		return min( 100, (int) $risk );
	}
}
