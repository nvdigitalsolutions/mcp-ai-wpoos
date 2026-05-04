<?php
/**
 * Structured Output Guardrail — validates and auto-corrects LLM JSON responses.
 *
 * When an assistant has a `response_schema` post meta key set (a JSON Schema
 * object), this guardrail:
 *
 *   1. Validates the raw LLM text response against that schema.
 *   2. On failure, triggers one Self-Refine pass that asks the model to
 *      reformat its answer to match the schema.
 *   3. Returns the validated, schema-conformant output or a WP_Error.
 *
 * This covers Gap 7 from the orchestration gap analysis: "Structured output
 * enforcement is opt-in and inconsistent." All existing assistants without
 * `response_schema` meta are unaffected.
 *
 * ## Enabling
 *
 * 1. Add `response_schema` post meta to the assistant CPT post (JSON Schema).
 * 2. Enable via harness profile key `structured_output.enabled = true`.
 * 3. Optionally filter `wp_mcp_ai_enforce_structured_output` to add custom logic.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured output guardrail for the agentic loop.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Structured_Output_Guardrail {

	/**
	 * Post meta key that stores the JSON Schema for assistant responses.
	 */
	const SCHEMA_META_KEY = '_wp_mcp_ai_response_schema';

	/**
	 * Harness profile key.
	 */
	const PROFILE_KEY = 'structured_output';

	/**
	 * Maximum depth for JSON Schema validation (prevents DoS via deeply nested schemas).
	 */
	const MAX_SCHEMA_DEPTH = 8;

	/**
	 * Validate a raw LLM response against the assistant's response schema.
	 *
	 * Returns an array:
	 *  - valid     (bool)    Whether the response passed validation.
	 *  - data      (mixed)   Decoded response data (null if not valid JSON).
	 *  - errors    (array)   Validation error messages (empty when valid).
	 *  - schema    (array)   The schema used (empty array if not configured).
	 *
	 * @param string $raw_response Raw LLM response text (expected to be JSON).
	 * @param int    $assistant_id Assistant post ID.
	 * @return array Validation result.
	 */
	public static function validate( $raw_response, $assistant_id ) {
		$raw_response = (string) $raw_response;
		$assistant_id = (int) $assistant_id;

		$result = array(
			'valid'  => true,
			'data'   => null,
			'errors' => array(),
			'schema' => array(),
		);

		$schema = self::get_schema( $assistant_id );
		if ( empty( $schema ) ) {
			// No schema configured — nothing to validate against.
			return $result;
		}
		$result['schema'] = $schema;

		/**
		 * Filter whether structured output enforcement is active for this request.
		 *
		 * @param bool  $enabled      Default true when a schema is present.
		 * @param int   $assistant_id Assistant post ID.
		 * @param array $schema       The schema array.
		 */
		$enabled = apply_filters( 'wp_mcp_ai_enforce_structured_output', true, $assistant_id, $schema );
		if ( ! $enabled ) {
			return $result;
		}

		// Step 1: parse JSON.
		$text = trim( $raw_response );
		// Strip optional markdown code fences (```json ... ```).
		if ( str_starts_with_compat( $text, '```' ) ) {
			$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
			$text = preg_replace( '/\s*```$/i', '', $text );
		}

		$decoded = json_decode( $text, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			$result['valid']    = false;
			$result['errors'][] = sprintf(
				/* translators: %s: JSON error message */
				__( 'Response is not valid JSON: %s', 'mcp-ai-wpoos' ),
				esc_html( json_last_error_msg() )
			);
			return $result;
		}
		$result['data'] = $decoded;

		// Step 2: JSON Schema validation.
		$errors = self::validate_against_schema( $decoded, $schema, '$', 0 );
		if ( ! empty( $errors ) ) {
			$result['valid']  = false;
			$result['errors'] = $errors;
		}

		return $result;
	}

	/**
	 * Validate $data against a JSON Schema $schema recursively.
	 *
	 * This is a lightweight validator covering: type, required, properties,
	 * minLength, maxLength, minimum, maximum, enum, and items.
	 * It is intentionally not a full JSON Schema implementation (draft-07+).
	 *
	 * @param mixed  $data   Data to validate.
	 * @param array  $schema Schema node.
	 * @param string $path   JSON Pointer-style path for error messages.
	 * @param int    $depth  Current recursion depth.
	 * @return array Error messages (empty = valid).
	 */
	public static function validate_against_schema( $data, array $schema, $path = '$', $depth = 0 ) {
		$errors = array();

		if ( $depth > self::MAX_SCHEMA_DEPTH ) {
			return $errors; // Safety: ignore deeply nested schemas.
		}

		// ── Type check ────────────────────────────────────────────────────────
		if ( isset( $schema['type'] ) ) {
			$expected = $schema['type'];
			$actual   = self::json_type_of( $data );
			if ( is_array( $expected ) ) {
				if ( ! in_array( $actual, $expected, true ) ) {
					$errors[] = sprintf( '%s: expected one of [%s], got %s', $path, implode( ', ', $expected ), $actual );
				}
			} elseif ( is_string( $expected ) && $actual !== $expected ) {
				$errors[] = sprintf( '%s: expected %s, got %s', $path, $expected, $actual );
			}
		}

		// ── Enum ──────────────────────────────────────────────────────────────
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
			if ( ! in_array( $data, $schema['enum'], true ) ) {
				$errors[] = sprintf( '%s: value not in enum [%s]', $path, implode( ', ', array_map( 'strval', $schema['enum'] ) ) );
			}
		}

		// ── String constraints ────────────────────────────────────────────────
		if ( is_string( $data ) ) {
			if ( isset( $schema['minLength'] ) && strlen( $data ) < (int) $schema['minLength'] ) {
				$errors[] = sprintf( '%s: string too short (min %d)', $path, (int) $schema['minLength'] );
			}
			if ( isset( $schema['maxLength'] ) && strlen( $data ) > (int) $schema['maxLength'] ) {
				$errors[] = sprintf( '%s: string too long (max %d)', $path, (int) $schema['maxLength'] );
			}
		}

		// ── Number constraints ────────────────────────────────────────────────
		if ( is_numeric( $data ) ) {
			if ( isset( $schema['minimum'] ) && $data < $schema['minimum'] ) {
				$errors[] = sprintf( '%s: value %s below minimum %s', $path, $data, $schema['minimum'] );
			}
			if ( isset( $schema['maximum'] ) && $data > $schema['maximum'] ) {
				$errors[] = sprintf( '%s: value %s above maximum %s', $path, $data, $schema['maximum'] );
			}
		}

		// ── Object properties ─────────────────────────────────────────────────
		if ( is_array( $data ) && ! self::is_list( $data ) ) {
			if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
				foreach ( $schema['required'] as $req_key ) {
					if ( ! array_key_exists( $req_key, $data ) ) {
						$errors[] = sprintf( '%s: missing required property "%s"', $path, $req_key );
					}
				}
			}
			if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
				foreach ( $schema['properties'] as $prop => $prop_schema ) {
					if ( array_key_exists( $prop, $data ) ) {
						$child_errors = self::validate_against_schema(
							$data[ $prop ],
							$prop_schema,
							$path . '.' . $prop,
							$depth + 1
						);
						$errors = array_merge( $errors, $child_errors );
					}
				}
			}
		}

		// ── Array items ───────────────────────────────────────────────────────
		if ( is_array( $data ) && self::is_list( $data ) && isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			foreach ( $data as $i => $item ) {
				$child_errors = self::validate_against_schema(
					$item,
					$schema['items'],
					$path . '[' . $i . ']',
					$depth + 1
				);
				$errors = array_merge( $errors, $child_errors );
			}
		}

		return $errors;
	}

	/**
	 * Build the Self-Refine critic for structured output re-formatting.
	 *
	 * @param array $schema JSON Schema.
	 * @return callable Critic function compatible with WP_MCP_AI_Self_Refine_Loop.
	 */
	public static function make_schema_critic( array $schema ) {
		return function ( $task, $candidate ) use ( $schema ) {
			$result = self::validate( $candidate, 0 );
			// Override schema for inline call.
			$result = self::validate_inline( $candidate, $schema );
			if ( $result['valid'] ) {
				return array( 'verdict' => 'accept', 'feedback' => '' );
			}
			$feedback = implode( '; ', $result['errors'] );
			return array(
				'verdict'  => 'revise',
				'feedback' => sprintf(
					/* translators: %s: validation error messages */
					__( 'The response does not conform to the required JSON schema. Errors: %s. Please reformat the answer as valid JSON matching the schema.', 'mcp-ai-wpoos' ),
					$feedback
				),
			);
		};
	}

	/**
	 * Validate raw text directly against a provided schema (without assistant lookup).
	 *
	 * @param string $raw_response Raw response text.
	 * @param array  $schema       JSON Schema array.
	 * @return array Same shape as validate().
	 */
	public static function validate_inline( $raw_response, array $schema ) {
		$result = array(
			'valid'  => true,
			'data'   => null,
			'errors' => array(),
			'schema' => $schema,
		);

		$text = trim( (string) $raw_response );
		if ( str_starts_with_compat( $text, '```' ) ) {
			$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
			$text = preg_replace( '/\s*```$/i', '', $text );
		}

		$decoded = json_decode( $text, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			$result['valid']    = false;
			$result['errors'][] = sprintf(
				/* translators: %s: JSON error message */
				__( 'Response is not valid JSON: %s', 'mcp-ai-wpoos' ),
				esc_html( json_last_error_msg() )
			);
			return $result;
		}
		$result['data'] = $decoded;

		$errors = self::validate_against_schema( $decoded, $schema, '$', 0 );
		if ( ! empty( $errors ) ) {
			$result['valid']  = false;
			$result['errors'] = $errors;
		}

		return $result;
	}

	/**
	 * Retrieve and decode the response schema for an assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array JSON Schema as PHP array, or empty array if not set.
	 */
	public static function get_schema( $assistant_id ) {
		$assistant_id = (int) $assistant_id;
		if ( $assistant_id <= 0 ) {
			return array();
		}

		$raw = get_post_meta( $assistant_id, self::SCHEMA_META_KEY, true );
		if ( empty( $raw ) ) {
			return array();
		}

		if ( is_array( $raw ) ) {
			return $raw;
		}

		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Return the JSON Schema type name for a PHP value.
	 *
	 * @param mixed $value PHP value.
	 * @return string JSON Schema type name.
	 */
	private static function json_type_of( $value ) {
		if ( is_null( $value ) ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'number';
		}
		if ( is_string( $value ) ) {
			return 'string';
		}
		if ( is_array( $value ) ) {
			return self::is_list( $value ) ? 'array' : 'object';
		}
		return 'unknown';
	}

	/**
	 * Determine whether a PHP array is a sequential list (JSON array).
	 *
	 * @param array $arr Input array.
	 * @return bool True for 0-indexed list, false for associative map.
	 */
	private static function is_list( array $arr ) {
		if ( empty( $arr ) ) {
			return true;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}
}

/**
 * PHP 7.4-compatible str_starts_with() polyfill (uses function if available).
 *
 * @param string $haystack String to search in.
 * @param string $needle   String to search for.
 * @return bool
 */
if ( ! function_exists( 'str_starts_with_compat' ) ) {
	function str_starts_with_compat( $haystack, $needle ) {
		if ( function_exists( 'str_starts_with' ) ) {
			return str_starts_with( $haystack, $needle );
		}
		return '' === $needle || 0 === strpos( $haystack, $needle );
	}
}
