<?php
/**
 * Schema Generator - Automatic JSON Schema from PHP Reflection
 *
 * Generates JSON schemas from method signatures and PHPDoc.
 * Proof of concept for modernization roadmap Phase 3.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Schema_Generator class
 *
 * Automatically generates JSON schemas from PHP method signatures
 * using reflection and PHPDoc parsing.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Schema_Generator {

	/**
	 * Generate JSON schema from a method
	 *
	 * @param string $class_name  Class name containing the method.
	 * @param string $method_name Method name to analyze (default: 'execute').
	 * @return array JSON schema array.
	 * @throws ReflectionException If class or method doesn't exist.
	 */
	public function generate_schema( $class_name, $method_name = 'execute' ) {
		if ( ! method_exists( $class_name, $method_name ) ) {
			return array(
				'type'       => 'object',
				'properties' => array(),
			);
		}

		$reflector   = new ReflectionMethod( $class_name, $method_name );
		$doc_comment = $reflector->getDocComment();

		// Parse PHPDoc.
		$phpdoc = $this->parse_phpdoc( $doc_comment );

		$properties = array();
		$required   = array();

		foreach ( $reflector->getParameters() as $param ) {
			$param_name = $param->getName();

			// Skip context parameters.
			if ( in_array( $param_name, array( 'context', 'arguments' ), true ) ) {
				continue;
			}

			$param_info = isset( $phpdoc['params'][ $param_name ] ) ? $phpdoc['params'][ $param_name ] : array();

			// Build property schema.
			$property = array(
				'type'        => $this->get_json_type( $param ),
				'description' => $param_info['description'] ?? '',
			);

			// Handle nullable parameters.
			if ( $param->allowsNull() ) {
				$property['nullable'] = true;
			}

			// Handle array types.
			if ( 'array' === $property['type'] && isset( $param_info['array_type'] ) ) {
				$property['items'] = array(
					'type' => $param_info['array_type'],
				);
			}

			// Handle enum values from PHPDoc.
			if ( isset( $param_info['enum'] ) ) {
				$property['enum'] = $param_info['enum'];
			}

			// Add to required if no default value.
			if ( ! $param->isOptional() ) {
				$required[] = $param_name;
			} else {
				// Add default value to schema.
				if ( $param->isDefaultValueAvailable() ) {
					$property['default'] = $param->getDefaultValue();
				}
			}

			$properties[ $param_name ] = $property;
		}

		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);

		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Get JSON schema type from PHP type
	 *
	 * @param ReflectionParameter $param Parameter to analyze.
	 * @return string JSON schema type.
	 */
	private function get_json_type( ReflectionParameter $param ) {
		$type = $param->getType();

		if ( ! $type ) {
			return 'string'; // Default type.
		}

		// PHP 7.4 doesn't have ReflectionNamedType yet, so we use getName() directly.
		$type_name = method_exists( $type, 'getName' ) ? $type->getName() : (string) $type;

		$type_map = array(
			'int'    => 'integer',
			'float'  => 'number',
			'bool'   => 'boolean',
			'string' => 'string',
			'array'  => 'array',
			'object' => 'object',
		);

		return isset( $type_map[ $type_name ] ) ? $type_map[ $type_name ] : 'string';
	}

	/**
	 * Parse PHPDoc comment
	 *
	 * @param string|false $doc_comment PHPDoc comment string.
	 * @return array Parsed PHPDoc data.
	 */
	private function parse_phpdoc( $doc_comment ) {
		if ( ! $doc_comment ) {
			return array(
				'description' => '',
				'params'      => array(),
			);
		}

		$result = array(
			'description' => '',
			'params'      => array(),
		);

		// Extract description (first line before @tags).
		$lines = explode( "\n", $doc_comment );
		$description_lines = array();

		foreach ( $lines as $line ) {
			$line = trim( $line, " \t/*" );

			if ( empty( $line ) || strpos( $line, '@' ) === 0 ) {
				break;
			}

			$description_lines[] = $line;
		}

		$result['description'] = implode( ' ', $description_lines );

		// Parse @param tags.
		if ( preg_match_all( '/@param\s+(\S+)\s+\$(\w+)\s+(.*)/', $doc_comment, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$type        = $match[1];
				$param_name  = $match[2];
				$description = trim( $match[3] );

				$param_info = array(
					'type'        => $type,
					'description' => $description,
				);

				// Parse array types like "string[]".
				if ( preg_match( '/^(\w+)\[\]$/', $type, $array_match ) ) {
					$param_info['array_type'] = $array_match[1];
				}

				// Parse enum values from description like "One of: 'draft', 'publish'".
				if ( preg_match( '/One of:?\s*(.+)/', $description, $enum_match ) ) {
					$enum_values = preg_split( '/[,\s]+/', $enum_match[1] );
					$enum_values = array_map(
						function( $val ) {
							return trim( $val, " '\"`" );
						},
						$enum_values
					);
					$param_info['enum'] = array_filter( $enum_values );
				}

				$result['params'][ $param_name ] = $param_info;
			}
		}

		return $result;
	}

	/**
	 * Generate schema for all tools
	 *
	 * @return array Array of tool schemas keyed by tool slug.
	 */
	public function generate_all_tool_schemas() {
		$registry = wp_mcp_ai_get_tool_registry();
		$schemas  = array();

		foreach ( $registry->get_all_tools() as $slug => $tool_instance ) {
			try {
				$class_name = get_class( $tool_instance );
				$schema     = $this->generate_schema( $class_name );

				$schemas[ $slug ] = array(
					'name'        => $slug,
					'class'       => $class_name,
					'inputSchema' => $schema,
				);
			} catch ( Exception $e ) {
				// Log error but continue.
				if ( function_exists( 'wp_mcp_ai_log' ) ) {
					wp_mcp_ai_log(
						sprintf( 'Schema generation failed for tool %s: %s', $slug, $e->getMessage() ),
						'error'
					);
				}
			}
		}

		return $schemas;
	}

	/**
	 * Validate arguments against generated schema
	 *
	 * @param array  $arguments Arguments to validate.
	 * @param string $class_name Tool class name.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_arguments( $arguments, $class_name ) {
		try {
			$schema = $this->generate_schema( $class_name );

			// Check required parameters.
			if ( isset( $schema['required'] ) ) {
				foreach ( $schema['required'] as $required_param ) {
					if ( ! isset( $arguments[ $required_param ] ) ) {
						return new WP_Error(
							'missing_required_param',
							sprintf( 'Missing required parameter: %s', $required_param )
						);
					}
				}
			}

			// Validate types.
			if ( isset( $schema['properties'] ) ) {
				foreach ( $arguments as $key => $value ) {
					if ( ! isset( $schema['properties'][ $key ] ) ) {
						continue; // Allow extra parameters.
					}

					$expected_type = $schema['properties'][ $key ]['type'];
					$actual_type   = $this->get_value_type( $value );

					if ( $expected_type !== $actual_type ) {
						return new WP_Error(
							'invalid_param_type',
							sprintf(
								'Invalid type for parameter %s: expected %s, got %s',
								$key,
								$expected_type,
								$actual_type
							)
						);
					}

					// Validate enum.
					if ( isset( $schema['properties'][ $key ]['enum'] ) ) {
						$allowed = $schema['properties'][ $key ]['enum'];
						if ( ! in_array( $value, $allowed, true ) ) {
							return new WP_Error(
								'invalid_param_value',
								sprintf(
									'Invalid value for parameter %s: must be one of %s',
									$key,
									implode( ', ', $allowed )
								)
							);
						}
					}
				}
			}

			return true;

		} catch ( Exception $e ) {
			return new WP_Error( 'schema_generation_failed', $e->getMessage() );
		}
	}

	/**
	 * Get value type as JSON schema type
	 *
	 * @param mixed $value Value to check.
	 * @return string JSON schema type.
	 */
	private function get_value_type( $value ) {
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'number';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_array( $value ) ) {
			return 'array';
		}
		if ( is_object( $value ) ) {
			return 'object';
		}
		return 'string';
	}
}
