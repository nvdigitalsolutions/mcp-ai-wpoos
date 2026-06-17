<?php
/**
 * Pro Rubric Presets
 *
 * Three ready-made rubric verifier factories so sites can adopt
 * structured evals without hand-building criteria. Each preset is a
 * static factory that returns a configured
 * {@see WP_MCP_AI_Pro_Rubric_Verifier} using callback criteria — this
 * avoids chaining through other registered verifiers (which would
 * couple a preset to the site's current registry wiring).
 *
 * Presets ship:
 *   - `pro_prompt_adherence_rubric` — checks that the response
 *     addresses the prompt, stays within a length envelope, and does
 *     not contain prohibited phrasings.
 *   - `pro_json_schema_rubric`      — deterministic schema validation
 *     with partial-credit scoring over validated sub-paths.
 *   - `pro_citation_presence_rubric` — scores presence, count, shape,
 *     and de-duplication of citations.
 *
 * Every preset is filterable via `wp_mcp_ai_pro_{slug}_criteria` so
 * site owners can tune criteria without subclassing. The slug is a
 * stable contract — eval suites reference it directly.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro rubric preset factories.
 */
class WP_MCP_AI_Pro_Rubric_Presets {

	/**
	 * Stable slugs. Exposed as class constants so tests and filter
	 * authors do not have to stringly-type them.
	 */
	const SLUG_PROMPT_ADHERENCE  = 'pro_prompt_adherence_rubric';
	const SLUG_JSON_SCHEMA       = 'pro_json_schema_rubric';
	const SLUG_CITATION_PRESENCE = 'pro_citation_presence_rubric';

	/**
	 * Build the prompt-adherence rubric.
	 *
	 * Criteria (all callback-based so the rubric stays independent of
	 * the rest of the registered verifier graph):
	 *   - `addresses_prompt` — any overlap between prompt nouns and
	 *     the response (weight 2.0).
	 *   - `length_envelope`  — response falls inside
	 *     `[min_words, max_words]` (weight 1.0). Defaults 5..500.
	 *   - `no_prohibited_phrases` — none of the configured phrases
	 *     appear in the response (weight 1.0).
	 *
	 * Subject shape: expects `value` (response string) and optionally
	 * `input` (the prompt). Without an input, `addresses_prompt` is
	 * treated as a soft pass (score 1.0) rather than falsely failing.
	 *
	 * @param array $overrides Optional overrides: `min_words`, `max_words`,
	 *                         `prohibited_phrases`, `pass_threshold`.
	 * @return WP_MCP_AI_Pro_Rubric_Verifier
	 */
	public static function prompt_adherence( array $overrides = array() ) {
		$defaults  = array(
			'min_words'          => 5,
			'max_words'          => 500,
			'prohibited_phrases' => array( 'as an ai language model', 'i cannot help with that' ),
			'pass_threshold'     => 0.7,
		);
		$config    = array_merge( $defaults, $overrides );
		$min_words = max( 1, (int) $config['min_words'] );
		$max_words = max( $min_words, (int) $config['max_words'] );
		$phrases   = array_values( array_filter( array_map( 'strtolower', array_map( 'strval', (array) $config['prohibited_phrases'] ) ) ) );

		$criteria = array(
			array(
				'slug'        => 'addresses_prompt',
				'description' => 'Response touches on keywords from the prompt.',
				'weight'      => 2.0,
				'callback'    => static function ( $subject ) {
					$response = self::subject_text( $subject );
					$prompt   = self::context_text( $subject, 'input' );
					if ( '' === $prompt ) {
						return 1.0;
					}
					if ( '' === $response ) {
						return 0.0;
					}
					$prompt_tokens   = self::tokenize( $prompt );
					$response_tokens = array_flip( self::tokenize( $response ) );
					if ( empty( $prompt_tokens ) ) {
						return 1.0;
					}
					$hits = 0;
					foreach ( $prompt_tokens as $t ) {
						if ( isset( $response_tokens[ $t ] ) ) {
							++$hits;
						}
					}
					return $hits / max( 1, count( $prompt_tokens ) );
				},
			),
			array(
				'slug'        => 'length_envelope',
				'description' => 'Response word count is within [min,max].',
				'weight'      => 1.0,
				'callback'    => static function ( $subject ) use ( $min_words, $max_words ) {
					$response = self::subject_text( $subject );
					if ( '' === $response ) {
						return 0.0;
					}
					$n = count( self::tokenize( $response ) );
					return ( $n >= $min_words && $n <= $max_words ) ? 1.0 : 0.0;
				},
			),
			array(
				'slug'        => 'no_prohibited_phrases',
				'description' => 'Response avoids prohibited phrases.',
				'weight'      => 1.0,
				'callback'    => static function ( $subject ) use ( $phrases ) {
					$response = strtolower( self::subject_text( $subject ) );
					if ( '' === $response || empty( $phrases ) ) {
						return 1.0;
					}
					foreach ( $phrases as $p ) {
						if ( '' !== $p && false !== strpos( $response, $p ) ) {
							return 0.0;
						}
					}
					return 1.0;
				},
			),
		);

		$criteria = self::filter_criteria( self::SLUG_PROMPT_ADHERENCE, $criteria );

		return new WP_MCP_AI_Pro_Rubric_Verifier(
			self::SLUG_PROMPT_ADHERENCE,
			$criteria,
			__( 'Pro Prompt Adherence Rubric', 'mcp-ai-wpoos' ),
			(float) $config['pass_threshold']
		);
	}

	/**
	 * Build the JSON-schema rubric.
	 *
	 * Criteria:
	 *   - `type_match`        — top-level type matches declared type
	 *     (weight 2.0).
	 *   - `required_keys`     — all required keys are present (partial
	 *     credit proportional to present-count, weight 2.0).
	 *   - `no_unknown_keys`   — the object has no keys outside the
	 *     declared schema (weight 1.0). Unused when `additional_properties`
	 *     is true.
	 *
	 * Subject shape: `value` may be a string (decoded as JSON) or
	 * already an array/scalar. Schema lives in `verifier_args['schema']`
	 * or on the criterion overrides.
	 *
	 * @param array $overrides Optional overrides: `schema`, `allow_additional_keys`,
	 *                         `pass_threshold`.
	 * @return WP_MCP_AI_Pro_Rubric_Verifier
	 */
	public static function json_schema( array $overrides = array() ) {
		$defaults = array(
			'schema'                => array(),
			'allow_additional_keys' => false,
			'pass_threshold'        => 0.7,
		);
		$config   = array_merge( $defaults, $overrides );
		$schema   = is_array( $config['schema'] ) ? $config['schema'] : array();
		$allow    = (bool) $config['allow_additional_keys'];

		$criteria = array(
			array(
				'slug'        => 'type_match',
				'description' => 'Top-level type matches the declared schema.',
				'weight'      => 2.0,
				'callback'    => static function ( $subject, $context ) use ( $schema ) {
					$value  = self::decode_subject_value( $subject );
					$active = self::pick_schema( $context, $schema );
					if ( empty( $active ) || empty( $active['type'] ) ) {
						return 1.0;
					}
					return self::type_matches( $value, (string) $active['type'] ) ? 1.0 : 0.0;
				},
			),
			array(
				'slug'        => 'required_keys',
				'description' => 'Required keys are present on the object.',
				'weight'      => 2.0,
				'callback'    => static function ( $subject, $context ) use ( $schema ) {
					$value  = self::decode_subject_value( $subject );
					$active = self::pick_schema( $context, $schema );
					$required = isset( $active['required'] ) && is_array( $active['required'] ) ? $active['required'] : array();
					if ( empty( $required ) ) {
						return 1.0;
					}
					if ( ! is_array( $value ) ) {
						return 0.0;
					}
					$hits = 0;
					foreach ( $required as $key ) {
						if ( array_key_exists( (string) $key, $value ) ) {
							++$hits;
						}
					}
					return $hits / max( 1, count( $required ) );
				},
			),
			array(
				'slug'        => 'no_unknown_keys',
				'description' => 'Object has no keys outside the declared schema.',
				'weight'      => 1.0,
				'callback'    => static function ( $subject, $context ) use ( $schema, $allow ) {
					if ( $allow ) {
						return 1.0;
					}
					$value  = self::decode_subject_value( $subject );
					$active = self::pick_schema( $context, $schema );
					$props  = isset( $active['properties'] ) && is_array( $active['properties'] ) ? array_keys( $active['properties'] ) : array();
					if ( empty( $props ) || ! is_array( $value ) ) {
						return 1.0;
					}
					$known   = array_flip( $props );
					$unknown = 0;
					foreach ( array_keys( $value ) as $k ) {
						if ( ! isset( $known[ (string) $k ] ) ) {
							++$unknown;
						}
					}
					return 0 === $unknown ? 1.0 : 0.0;
				},
			),
		);

		$criteria = self::filter_criteria( self::SLUG_JSON_SCHEMA, $criteria );

		return new WP_MCP_AI_Pro_Rubric_Verifier(
			self::SLUG_JSON_SCHEMA,
			$criteria,
			__( 'Pro JSON Schema Rubric', 'mcp-ai-wpoos' ),
			(float) $config['pass_threshold']
		);
	}

	/**
	 * Build the citation-presence rubric.
	 *
	 * Criteria:
	 *   - `has_any_citation`   — ≥1 citation (weight 1.0).
	 *   - `meets_minimum`      — citation count ≥ configured minimum
	 *     (weight 2.0). Default minimum = 2.
	 *   - `no_duplicates`      — all citations are distinct after
	 *     normalization (weight 1.0).
	 *
	 * Citations are extracted from the response string via a
	 * conservative union regex covering markdown reference links,
	 * bracketed numeric citations (`[1]`), and bare URLs. Override
	 * the regex with `citation_pattern` in overrides to adapt to a
	 * site's house-style.
	 *
	 * @param array $overrides Optional overrides: `minimum`, `citation_pattern`, `pass_threshold`.
	 * @return WP_MCP_AI_Pro_Rubric_Verifier
	 */
	public static function citation_presence( array $overrides = array() ) {
		$defaults = array(
			'minimum'          => 2,
			'citation_pattern' => '/(\[[^\]]+\]\([^)]+\))|(\[\d+\])|(https?:\/\/\S+)/',
			'pass_threshold'   => 0.7,
		);
		$config   = array_merge( $defaults, $overrides );
		$minimum  = max( 1, (int) $config['minimum'] );
		$pattern  = (string) $config['citation_pattern'];

		$extract = static function ( $subject ) use ( $pattern ) {
			$text = self::subject_text( $subject );
			if ( '' === $text ) {
				return array();
			}
			$matches = array();
			if ( preg_match_all( $pattern, $text, $m ) && ! empty( $m[0] ) ) {
				$matches = array_values( array_filter( array_map( 'trim', $m[0] ), 'strlen' ) );
			}
			return $matches;
		};

		$criteria = array(
			array(
				'slug'        => 'has_any_citation',
				'description' => 'Response contains at least one citation.',
				'weight'      => 1.0,
				'callback'    => static function ( $subject ) use ( $extract ) {
					return count( $extract( $subject ) ) >= 1 ? 1.0 : 0.0;
				},
			),
			array(
				'slug'        => 'meets_minimum',
				'description' => 'Citation count meets the configured minimum.',
				'weight'      => 2.0,
				'callback'    => static function ( $subject ) use ( $extract, $minimum ) {
					$n = count( $extract( $subject ) );
					if ( 0 === $n ) {
						return 0.0;
					}
					if ( $n >= $minimum ) {
						return 1.0;
					}
					return $n / $minimum;
				},
			),
			array(
				'slug'        => 'no_duplicates',
				'description' => 'Citations are distinct after normalization.',
				'weight'      => 1.0,
				'callback'    => static function ( $subject ) use ( $extract ) {
					$cites = $extract( $subject );
					if ( empty( $cites ) ) {
						return 1.0;
					}
					$normalized = array_map( 'strtolower', $cites );
					$unique     = array_unique( $normalized );
					return count( $unique ) / max( 1, count( $normalized ) );
				},
			),
		);

		$criteria = self::filter_criteria( self::SLUG_CITATION_PRESENCE, $criteria );

		return new WP_MCP_AI_Pro_Rubric_Verifier(
			self::SLUG_CITATION_PRESENCE,
			$criteria,
			__( 'Pro Citation Presence Rubric', 'mcp-ai-wpoos' ),
			(float) $config['pass_threshold']
		);
	}

	// -------------------------------------------------------------------
	// Helpers — static because criteria are static closures.
	// -------------------------------------------------------------------

	/**
	 * Apply the per-preset criterion filter.
	 *
	 * @param string $slug     Preset slug (stable contract).
	 * @param array  $criteria Built-in criteria.
	 * @return array
	 */
	private static function filter_criteria( $slug, array $criteria ) {
		$hook = 'wp_mcp_ai_pro_' . $slug . '_criteria';
		/**
		 * Filters the criteria of a pro rubric preset.
		 *
		 * Deployments should prefer this filter over unregistering the
		 * preset entirely, because the slug is a stable contract that
		 * eval suites, dashboards, and alerts reference by name.
		 *
		 * @since 1.3.0
		 *
		 * @param array $criteria Built-in criteria.
		 */
		$filtered = apply_filters( $hook, $criteria );
		return is_array( $filtered ) && ! empty( $filtered ) ? $filtered : $criteria;
	}

	/**
	 * Coerce the subject value into a string for text-based criteria.
	 *
	 * @param array $subject Subject.
	 * @return string
	 */
	private static function subject_text( $subject ) {
		if ( ! is_array( $subject ) ) {
			return '';
		}
		$value = array_key_exists( 'value', $subject ) ? $subject['value'] : $subject;
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			// Arrays are flattened to space-separated scalars so the
			// rubric can score shape-agnostic responses.
			$parts = array();
			array_walk_recursive(
				$value,
				static function ( $v ) use ( &$parts ) {
					if ( is_scalar( $v ) ) {
						$parts[] = (string) $v;
					}
				}
			);
			return implode( ' ', $parts );
		}
		return '';
	}

	/**
	 * Extract a string from subject context by key, normalized.
	 *
	 * @param array  $subject Subject.
	 * @param string $key     Key.
	 * @return string
	 */
	private static function context_text( $subject, $key ) {
		if ( ! is_array( $subject ) || ! array_key_exists( $key, $subject ) ) {
			return '';
		}
		return self::subject_text( array( 'value' => $subject[ $key ] ) );
	}

	/**
	 * Tokenize a string into lowercased word tokens.
	 *
	 * @param string $text Text.
	 * @return array<int,string>
	 */
	private static function tokenize( $text ) {
		$text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', (string) $text );
		$text = strtolower( trim( (string) $text ) );
		if ( '' === $text ) {
			return array();
		}
		$parts = preg_split( '/\s+/', $text );
		return is_array( $parts ) ? array_values( array_filter( $parts, 'strlen' ) ) : array();
	}

	/**
	 * Decode the subject value (string JSON or already-decoded).
	 *
	 * @param array $subject Subject.
	 * @return mixed
	 */
	private static function decode_subject_value( $subject ) {
		if ( ! is_array( $subject ) ) {
			return null;
		}
		$value = array_key_exists( 'value', $subject ) ? $subject['value'] : $subject;
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return null !== $decoded ? $decoded : $value;
		}
		return $value;
	}

	/**
	 * Pick the active schema — prefer context-declared, fall back to
	 * the rubric's configured schema.
	 *
	 * @param array $context Verifier context.
	 * @param array $default Default schema.
	 * @return array
	 */
	private static function pick_schema( $context, array $default ) {
		if ( is_array( $context ) && isset( $context['schema'] ) && is_array( $context['schema'] ) ) {
			return $context['schema'];
		}
		return $default;
	}

	/**
	 * Very small subset of JSON-schema type matching — matches the
	 * shape used elsewhere in the plugin's schema verifier.
	 *
	 * @param mixed  $value Value.
	 * @param string $type  Declared type.
	 * @return bool
	 */
	private static function type_matches( $value, $type ) {
		switch ( $type ) {
			case 'object':
				return is_array( $value ) && ( empty( $value ) || self::is_assoc( $value ) );
			case 'array':
				return is_array( $value ) && ( empty( $value ) || ! self::is_assoc( $value ) );
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value );
			case 'number':
				return is_int( $value ) || is_float( $value );
			case 'boolean':
				return is_bool( $value );
			case 'null':
				return null === $value;
			default:
				return true;
		}
	}

	/**
	 * Is an array associative?
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	private static function is_assoc( array $arr ) {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
