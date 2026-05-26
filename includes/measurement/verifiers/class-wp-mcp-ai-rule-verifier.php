<?php
/**
 * Rule Verifier
 *
 * Declarative rule-based verifier. Given a set of rules, each subject value is
 * checked against predicate functions (required keys, value patterns, numeric
 * bounds, enum membership). The verifier is deterministic and does NOT call
 * out to any LLM — its independence profile is therefore permissive.
 *
 * Rules are supplied via the constructor or via the
 * `wp_mcp_ai_rule_verifier_rules` filter so site owners can extend the
 * default rule set without subclassing.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declarative rule-based verifier.
 */
class WP_MCP_AI_Rule_Verifier extends WP_MCP_AI_Verifier_Base {

	/**
	 * Supported rule types.
	 */
	const RULE_REQUIRED = 'required';
	const RULE_PATTERN  = 'pattern';
	const RULE_ENUM     = 'enum';
	const RULE_MIN      = 'min';
	const RULE_MAX      = 'max';
	const RULE_CALLBACK = 'callback';

	/**
	 * Rule definitions.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	protected $rules = array();

	/**
	 * Constructor.
	 *
	 * @param string $slug  Slug override (defaults to `rule_verifier`).
	 * @param array  $rules Initial rule definitions.
	 */
	public function __construct( $slug = 'rule_verifier', array $rules = array() ) {
		$this->slug  = '' !== sanitize_key( $slug ) ? sanitize_key( $slug ) : 'rule_verifier';
		$this->label = __( 'Rule Verifier', 'mcp-ai-wpoos' );
		$this->kind  = 'rule';
		// Rule verifiers do no LLM work, so they are trivially independent.
		$this->independence_profile = array(
			'disallowed_providers' => array(),
			'disallowed_models'    => array(),
			'disallowed_tools'     => array(),
			'allowed_domains'      => array(),
		);
		$this->rules                = $this->normalize_rules( $rules );
	}

	/**
	 * Replace the current rule set.
	 *
	 * @param array $rules Rules.
	 * @return void
	 */
	public function set_rules( array $rules ) {
		$this->rules = $this->normalize_rules( $rules );
	}

	/**
	 * Append rules to the current set.
	 *
	 * @param array $rules Rules.
	 * @return void
	 */
	public function add_rules( array $rules ) {
		$this->rules = array_merge( $this->rules, $this->normalize_rules( $rules ) );
	}

	/**
	 * Get the active rule set.
	 *
	 * @return array
	 */
	public function get_rules() {
		return $this->rules;
	}

	/**
	 * Normalize user-supplied rules.
	 *
	 * Invalid rules are silently dropped — the verifier must never crash on
	 * bad configuration; instead it emits a low score and records reasons.
	 *
	 * @param array $rules Raw rules.
	 * @return array
	 */
	protected function normalize_rules( array $rules ) {
		$out = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$type = isset( $rule['type'] ) ? (string) $rule['type'] : '';
			$path = isset( $rule['path'] ) ? (string) $rule['path'] : '';
			if ( '' === $type || '' === $path ) {
				continue;
			}
			$allowed = array(
				self::RULE_REQUIRED,
				self::RULE_PATTERN,
				self::RULE_ENUM,
				self::RULE_MIN,
				self::RULE_MAX,
				self::RULE_CALLBACK,
			);
			if ( ! in_array( $type, $allowed, true ) ) {
				continue;
			}
			if ( self::RULE_CALLBACK === $type && ( empty( $rule['callback'] ) || ! is_callable( $rule['callback'] ) ) ) {
				continue;
			}

			$out[] = array(
				'type'     => $type,
				'path'     => $path,
				'value'    => isset( $rule['value'] ) ? $rule['value'] : null,
				'callback' => isset( $rule['callback'] ) ? $rule['callback'] : null,
				'message'  => isset( $rule['message'] ) ? (string) $rule['message'] : '',
				'weight'   => isset( $rule['weight'] ) ? max( 0.0, (float) $rule['weight'] ) : 1.0,
			);
		}
		return $out;
	}

	/**
	 * Verify a subject against the rule set.
	 *
	 * @param array $subject Subject.
	 * @param array $context Context.
	 * @return array
	 */
	public function verify( array $subject, array $context = array() ) {
		/**
		 * Filters rules for a specific rule verifier instance.
		 *
		 * @since 1.3.0
		 *
		 * @param array  $rules   Rules.
		 * @param string $slug    Verifier slug.
		 * @param array  $subject Subject being verified.
		 * @param array  $context Context.
		 */
		$rules = apply_filters( 'wp_mcp_ai_rule_verifier_rules', $this->rules, $this->slug, $subject, $context );
		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return $this->result_pass( 1.0, 1.0, array( 'no rules declared' ) );
		}

		$failures      = array();
		$total_weight  = 0.0;
		$passed_weight = 0.0;

		foreach ( $rules as $rule ) {
			$weight        = (float) $rule['weight'];
			$total_weight += $weight;

			$value       = $this->extract_path( $subject, $rule['path'] );
			$rule_passed = $this->evaluate_rule( $rule, $value );

			if ( $rule_passed ) {
				$passed_weight += $weight;
				continue;
			}

			$message = '' !== $rule['message']
				? $rule['message']
				: sprintf(
					/* translators: 1: rule type, 2: dotted path. */
					__( 'Rule "%1$s" failed at path "%2$s".', 'mcp-ai-wpoos' ),
					$rule['type'],
					$rule['path']
				);
			$failures[] = $message;
		}

		$score = $total_weight > 0.0 ? ( $passed_weight / $total_weight ) : 1.0;
		if ( empty( $failures ) ) {
			return $this->result_pass( $score, 1.0, array() );
		}
		return $this->result_fail(
			$score,
			1.0,
			$failures,
			array(
				'failed_rules' => count( $failures ),
				'total_rules'  => count( $rules ),
			)
		);
	}

	/**
	 * Evaluate a single rule against a value.
	 *
	 * @param array $rule  Rule.
	 * @param mixed $value Value extracted from the subject.
	 * @return bool
	 */
	protected function evaluate_rule( array $rule, $value ) {
		switch ( $rule['type'] ) {
			case self::RULE_REQUIRED:
				return null !== $value && '' !== $value;

			case self::RULE_PATTERN:
				if ( ! is_string( $value ) || ! is_string( $rule['value'] ) ) {
					return false;
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: preg_match() may emit warnings for invalid user-supplied regex patterns; return value validated with 1 === $match check.
				$match = @preg_match( $rule['value'], $value );
				return 1 === $match;

			case self::RULE_ENUM:
				if ( ! is_array( $rule['value'] ) ) {
					return false;
				}
				return in_array( $value, $rule['value'], true );

			case self::RULE_MIN:
				return is_numeric( $value ) && is_numeric( $rule['value'] ) && (float) $value >= (float) $rule['value'];

			case self::RULE_MAX:
				return is_numeric( $value ) && is_numeric( $rule['value'] ) && (float) $value <= (float) $rule['value'];

			case self::RULE_CALLBACK:
				if ( ! is_callable( $rule['callback'] ) ) {
					return false;
				}
				return (bool) call_user_func( $rule['callback'], $value );

			default:
				return false;
		}
	}

	/**
	 * Extract a value at a dotted path from the subject.
	 *
	 * Example: `"answer.citations.0.url"` walks an array.
	 *
	 * @param mixed  $subject Subject (array or object).
	 * @param string $path    Dotted path.
	 * @return mixed|null
	 */
	protected function extract_path( $subject, $path ) {
		$path    = trim( (string) $path );
		$current = $subject;
		if ( '' === $path ) {
			return $current;
		}
		$segments = explode( '.', $path );
		foreach ( $segments as $segment ) {
			if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
				$current = $current[ $segment ];
				continue;
			}
			if ( is_object( $current ) && isset( $current->$segment ) ) {
				$current = $current->$segment;
				continue;
			}
			return null;
		}
		return $current;
	}
}
