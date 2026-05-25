<?php
/**
 * Schema Verifier
 *
 * Lightweight JSON Schema-style structural verifier. Supports a deliberately
 * small subset of JSON Schema — the goal is to validate tool outputs,
 * assistant replies, and eval fixtures without pulling in a full JSON Schema
 * implementation. For richer validation, register a custom verifier via
 * `wp_mcp_ai_register_verifiers`.
 *
 * Supported keywords: `type`, `required`, `properties`, `enum`, `minimum`,
 * `maximum`, `minLength`, `maxLength`, `pattern`, `items` (for arrays).
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
 * Schema verifier.
 */
class WP_MCP_AI_Schema_Verifier extends WP_MCP_AI_Verifier_Base {

	/**
	 * Schema definition.
	 *
	 * @var array<string,mixed>
	 */
	protected $schema = array();

	/**
	 * Constructor.
	 *
	 * @param string $slug   Slug override.
	 * @param array  $schema JSON Schema-style definition.
	 */
	public function __construct( $slug = 'schema_verifier', array $schema = array() ) {
		$this->slug                 = '' !== sanitize_key( $slug ) ? sanitize_key( $slug ) : 'schema_verifier';
		$this->label                = __( 'Schema Verifier', 'mcp-ai-wpoos' );
		$this->kind                 = 'schema';
		$this->independence_profile = array(
			'disallowed_providers' => array(),
			'disallowed_models'    => array(),
			'disallowed_tools'     => array(),
			'allowed_domains'      => array(),
		);
		$this->schema               = $schema;
	}

	/**
	 * Replace the active schema.
	 *
	 * @param array $schema Schema.
	 * @return void
	 */
	public function set_schema( array $schema ) {
		$this->schema = $schema;
	}

	/**
	 * Get the active schema.
	 *
	 * @return array
	 */
	public function get_schema() {
		return $this->schema;
	}

	/**
	 * Verify the subject against the schema.
	 *
	 * Subject layout:
	 *   array( 'value' => ..., ... )
	 *
	 * If `value` is missing, the entire subject is treated as the value.
	 *
	 * @param array $subject Subject.
	 * @param array $context Context (unused).
	 * @return array
	 */
	public function verify( array $subject, array $context = array() ) {
		unset( $context );
		$value = array_key_exists( 'value', $subject ) ? $subject['value'] : $subject;

		if ( empty( $this->schema ) ) {
			return $this->result_pass( 1.0, 1.0, array( 'no schema declared' ) );
		}

		$errors = array();
		$this->validate( $value, $this->schema, '', $errors );

		if ( empty( $errors ) ) {
			return $this->result_pass( 1.0, 1.0, array(), array( 'checked_keywords' => array_keys( $this->schema ) ) );
		}

		return $this->result_fail(
			0.0,
			1.0,
			array_slice( $errors, 0, 25 ),
			array( 'error_count' => count( $errors ) )
		);
	}

	/**
	 * Recursive validation step.
	 *
	 * @param mixed  $value  Value being validated.
	 * @param array  $schema Current schema node.
	 * @param string $path   Dotted path for error messages.
	 * @param array  $errors Error accumulator passed by reference.
	 * @return void
	 */
	protected function validate( $value, array $schema, $path, array &$errors ) {
		if ( isset( $schema['type'] ) ) {
			$types = is_array( $schema['type'] ) ? $schema['type'] : array( $schema['type'] );
			if ( ! $this->matches_any_type( $value, $types ) ) {
				$errors[] = sprintf(
					/* translators: 1: path, 2: type list. */
					__( 'Value at "%1$s" does not match type(s): %2$s', 'mcp-ai-wpoos' ),
					'' === $path ? '<root>' : $path,
					implode( ',', $types )
				);
				return;
			}
		}

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
			if ( ! in_array( $value, $schema['enum'], true ) ) {
				$errors[] = sprintf(
					/* translators: %s: path. */
					__( 'Value at "%s" is not in enum.', 'mcp-ai-wpoos' ),
					'' === $path ? '<root>' : $path
				);
			}
		}

		if ( is_numeric( $value ) ) {
			if ( isset( $schema['minimum'] ) && (float) $value < (float) $schema['minimum'] ) {
				$errors[] = sprintf(
					/* translators: 1: path, 2: minimum. */
					__( 'Value at "%1$s" is below minimum %2$s.', 'mcp-ai-wpoos' ),
					$path,
					(string) $schema['minimum']
				);
			}
			if ( isset( $schema['maximum'] ) && (float) $value > (float) $schema['maximum'] ) {
				$errors[] = sprintf(
					/* translators: 1: path, 2: maximum. */
					__( 'Value at "%1$s" is above maximum %2$s.', 'mcp-ai-wpoos' ),
					$path,
					(string) $schema['maximum']
				);
			}
		}

		if ( is_string( $value ) ) {
			if ( isset( $schema['minLength'] ) && strlen( $value ) < (int) $schema['minLength'] ) {
				$errors[] = sprintf(
					/* translators: 1: path, 2: minimum length. */
					__( 'String at "%1$s" shorter than %2$s.', 'mcp-ai-wpoos' ),
					$path,
					(string) (int) $schema['minLength']
				);
			}
			if ( isset( $schema['maxLength'] ) && strlen( $value ) > (int) $schema['maxLength'] ) {
				$errors[] = sprintf(
					/* translators: 1: path, 2: max length. */
					__( 'String at "%1$s" longer than %2$s.', 'mcp-ai-wpoos' ),
					$path,
					(string) (int) $schema['maxLength']
				);
			}
			if ( isset( $schema['pattern'] ) && is_string( $schema['pattern'] ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: preg_match() may emit warnings for invalid schema patterns; return value validated with 1 !== check.
				if ( 1 !== @preg_match( $schema['pattern'], $value ) ) {
					$errors[] = sprintf(
						/* translators: %s: path. */
						__( 'String at "%s" does not match pattern.', 'mcp-ai-wpoos' ),
						$path
					);
				}
			}
		}

		if ( is_array( $value ) && isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
				foreach ( $schema['required'] as $required_key ) {
					if ( ! array_key_exists( $required_key, $value ) ) {
						$errors[] = sprintf(
							/* translators: 1: required key, 2: path. */
							__( 'Missing required property "%1$s" at "%2$s".', 'mcp-ai-wpoos' ),
							(string) $required_key,
							'' === $path ? '<root>' : $path
						);
					}
				}
			}
			foreach ( $schema['properties'] as $prop_name => $prop_schema ) {
				if ( is_array( $prop_schema ) && array_key_exists( $prop_name, $value ) ) {
					$this->validate( $value[ $prop_name ], $prop_schema, '' === $path ? $prop_name : $path . '.' . $prop_name, $errors );
				}
			}
		}

		if ( is_array( $value ) && isset( $schema['items'] ) && is_array( $schema['items'] ) && $this->is_sequential( $value ) ) {
			foreach ( $value as $i => $item ) {
				$this->validate( $item, $schema['items'], $path . '[' . $i . ']', $errors );
			}
		}
	}

	/**
	 * Whether the value matches any of the given JSON Schema types.
	 *
	 * @param mixed $value Value.
	 * @param array $types Type names.
	 * @return bool
	 */
	protected function matches_any_type( $value, array $types ) {
		foreach ( $types as $type ) {
			switch ( $type ) {
				case 'string':
					if ( is_string( $value ) ) {
						return true;
					}
					break;
				case 'integer':
					if ( is_int( $value ) || ( is_numeric( $value ) && (int) $value == $value ) ) {  // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Intentional loose comparison: "42" == 42 is valid for JSON Schema integer type matching per spec.
						return true;
					}
					break;
				case 'number':
					if ( is_int( $value ) || is_float( $value ) || is_numeric( $value ) ) {
						return true;
					}
					break;
				case 'boolean':
					if ( is_bool( $value ) ) {
						return true;
					}
					break;
				case 'array':
					if ( is_array( $value ) && $this->is_sequential( $value ) ) {
						return true;
					}
					break;
				case 'object':
					if ( is_array( $value ) && ! $this->is_sequential( $value ) ) {
						return true;
					}
					if ( is_object( $value ) ) {
						return true;
					}
					break;
				case 'null':
					if ( null === $value ) {
						return true;
					}
					break;
			}
		}
		return false;
	}

	/**
	 * Whether an array is sequential (list-like).
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	protected function is_sequential( array $arr ) {
		if ( array() === $arr ) {
			return true;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}
}
