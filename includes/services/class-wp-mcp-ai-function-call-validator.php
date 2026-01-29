<?php
/**
 * Function Call Validator Service
 *
 * Enhanced validation and execution of JSON-based function calls.
 * Part of Phase 4.2: Structured Function Calling Enhancements.
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Function Call Validator Service Class
 *
 * Provides deep JSON schema validation, nested tool call support,
 * and parallel execution coordination for MCP function calls.
 *
 * @since 1.1.1
 */
class WP_MCP_AI_Function_Call_Validator {

	/**
	 * Tool registry instance
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $registry;

	/**
	 * Tool executor instance
	 *
	 * @var WP_MCP_AI_Tool_Execution_Orchestrator|null
	 */
	protected $orchestrator;

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Tool_Registry|null                  $registry Tool registry.
	 * @param WP_MCP_AI_Tool_Execution_Orchestrator|null $orchestrator Tool orchestrator.
	 */
	public function __construct( $registry = null, $orchestrator = null ) {
		$this->registry     = $registry ?? WP_MCP_AI_Tool_Registry::get_instance();
		$this->orchestrator = $orchestrator;
	}

	/**
	 * Validate function call against schema
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $arguments Arguments to validate.
	 * @param array  $schema JSON schema.
	 * @return array Validation result with normalized arguments.
	 */
	public function validate_function_call( $tool_slug, $arguments, $schema ) {
		$errors          = array();
		$normalized_args = array();

		// Get tool instance to retrieve schema if not provided.
		if ( empty( $schema ) ) {
			$tool = $this->registry->get_tool( $tool_slug );
			if ( ! $tool ) {
				return array(
					'valid'           => false,
					'errors'          => array( sprintf( __( 'Tool %s not found', 'mcp-ai-wpoos' ), $tool_slug ) ),
					'normalized_args' => array(),
				);
			}
			$schema = $tool->get_parameters_schema();
		}

		// Validate based on schema type.
		if ( isset( $schema['type'] ) && 'object' === $schema['type'] ) {
			$result = $this->validate_object( $arguments, $schema, '' );
			$errors = $result['errors'];
			$normalized_args = $result['value'];
		} else {
			$errors[] = __( 'Root schema must be of type "object"', 'mcp-ai-wpoos' );
		}

		return array(
			'valid'           => empty( $errors ),
			'errors'          => $errors,
			'normalized_args' => $normalized_args,
		);
	}

	/**
	 * Validate object against schema
	 *
	 * @param mixed  $value Value to validate.
	 * @param array  $schema Schema definition.
	 * @param string $path Current path in object (for error messages).
	 * @return array Validation result.
	 */
	protected function validate_object( $value, $schema, $path ) {
		$errors     = array();
		$normalized = array();

		if ( ! is_array( $value ) ) {
			$errors[] = sprintf(
				/* translators: %s: property path */
				__( 'Value at %s must be an object/array', 'mcp-ai-wpoos' ),
				$path ?: 'root'
			);
			return array( 'errors' => $errors, 'value' => $normalized );
		}

		$properties = $schema['properties'] ?? array();
		$required   = $schema['required'] ?? array();

		// Check required properties.
		foreach ( $required as $req_prop ) {
			if ( ! isset( $value[ $req_prop ] ) ) {
				$errors[] = sprintf(
					/* translators: 1: property name, 2: property path */
					__( 'Required property "%1$s" missing at %2$s', 'mcp-ai-wpoos' ),
					$req_prop,
					$path ?: 'root'
				);
			}
		}

		// Validate each property.
		foreach ( $properties as $prop_name => $prop_schema ) {
			$prop_path = $path ? $path . '.' . $prop_name : $prop_name;

			if ( ! isset( $value[ $prop_name ] ) ) {
				// Use default if provided.
				if ( isset( $prop_schema['default'] ) ) {
					$normalized[ $prop_name ] = $prop_schema['default'];
				}
				continue;
			}

			$prop_result = $this->validate_value( $value[ $prop_name ], $prop_schema, $prop_path );
			$errors = array_merge( $errors, $prop_result['errors'] );
			$normalized[ $prop_name ] = $prop_result['value'];
		}

		return array( 'errors' => $errors, 'value' => $normalized );
	}

	/**
	 * Validate array against schema
	 *
	 * @param mixed  $value Value to validate.
	 * @param array  $schema Schema definition.
	 * @param string $path Current path.
	 * @return array Validation result.
	 */
	protected function validate_array( $value, $schema, $path ) {
		$errors     = array();
		$normalized = array();

		if ( ! is_array( $value ) ) {
			$errors[] = sprintf(
				/* translators: %s: property path */
				__( 'Value at %s must be an array', 'mcp-ai-wpoos' ),
				$path
			);
			return array( 'errors' => $errors, 'value' => $normalized );
		}

		$items_schema = $schema['items'] ?? array();

		// Validate each item.
		foreach ( $value as $index => $item ) {
			$item_path = $path . '[' . $index . ']';
			$item_result = $this->validate_value( $item, $items_schema, $item_path );
			$errors = array_merge( $errors, $item_result['errors'] );
			$normalized[] = $item_result['value'];
		}

		// Check array constraints.
		if ( isset( $schema['minItems'] ) && count( $normalized ) < $schema['minItems'] ) {
			$errors[] = sprintf(
				/* translators: 1: path, 2: minimum items */
				__( 'Array at %1$s must have at least %2$d items', 'mcp-ai-wpoos' ),
				$path,
				$schema['minItems']
			);
		}

		if ( isset( $schema['maxItems'] ) && count( $normalized ) > $schema['maxItems'] ) {
			$errors[] = sprintf(
				/* translators: 1: path, 2: maximum items */
				__( 'Array at %1$s must have at most %2$d items', 'mcp-ai-wpoos' ),
				$path,
				$schema['maxItems']
			);
		}

		return array( 'errors' => $errors, 'value' => $normalized );
	}

	/**
	 * Validate value against schema
	 *
	 * @param mixed  $value Value to validate.
	 * @param array  $schema Schema definition.
	 * @param string $path Current path.
	 * @return array Validation result.
	 */
	protected function validate_value( $value, $schema, $path ) {
		$errors = array();
		$type   = $schema['type'] ?? 'string';

		// Handle null values.
		if ( is_null( $value ) ) {
			if ( isset( $schema['nullable'] ) && $schema['nullable'] ) {
				return array( 'errors' => array(), 'value' => null );
			}
			$errors[] = sprintf(
				/* translators: %s: property path */
				__( 'Value at %s cannot be null', 'mcp-ai-wpoos' ),
				$path
			);
			return array( 'errors' => $errors, 'value' => $value );
		}

		// Validate based on type.
		switch ( $type ) {
			case 'object':
				return $this->validate_object( $value, $schema, $path );

			case 'array':
				return $this->validate_array( $value, $schema, $path );

			case 'string':
				if ( ! is_string( $value ) ) {
					$value = (string) $value; // Type coercion.
				}
				// Check string constraints.
				if ( isset( $schema['minLength'] ) && strlen( $value ) < $schema['minLength'] ) {
					$errors[] = sprintf(
						/* translators: 1: path, 2: minimum length */
						__( 'String at %1$s must be at least %2$d characters', 'mcp-ai-wpoos' ),
						$path,
						$schema['minLength']
					);
				}
				if ( isset( $schema['maxLength'] ) && strlen( $value ) > $schema['maxLength'] ) {
					$errors[] = sprintf(
						/* translators: 1: path, 2: maximum length */
						__( 'String at %1$s must be at most %2$d characters', 'mcp-ai-wpoos' ),
						$path,
						$schema['maxLength']
					);
				}
				if ( isset( $schema['pattern'] ) && ! preg_match( '/' . $schema['pattern'] . '/', $value ) ) {
					$errors[] = sprintf(
						/* translators: 1: path, 2: pattern */
						__( 'String at %1$s does not match pattern %2$s', 'mcp-ai-wpoos' ),
						$path,
						$schema['pattern']
					);
				}
				break;

			case 'number':
			case 'integer':
				if ( ! is_numeric( $value ) ) {
					$errors[] = sprintf(
						/* translators: 1: path, 2: expected type */
						__( 'Value at %1$s must be a %2$s', 'mcp-ai-wpoos' ),
						$path,
						$type
					);
				} else {
					$value = 'integer' === $type ? (int) $value : (float) $value;
					// Check numeric constraints.
					if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
						$errors[] = sprintf(
							/* translators: 1: path, 2: minimum value */
							__( 'Value at %1$s must be at least %2$s', 'mcp-ai-wpoos' ),
							$path,
							$schema['minimum']
						);
					}
					if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
						$errors[] = sprintf(
							/* translators: 1: path, 2: maximum value */
							__( 'Value at %1$s must be at most %2$s', 'mcp-ai-wpoos' ),
							$path,
							$schema['maximum']
						);
					}
				}
				break;

			case 'boolean':
				if ( ! is_bool( $value ) ) {
					$value = (bool) $value; // Type coercion.
				}
				break;
		}

		// Check enum constraints.
		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$errors[] = sprintf(
				/* translators: 1: path, 2: allowed values */
				__( 'Value at %1$s must be one of: %2$s', 'mcp-ai-wpoos' ),
				$path,
				implode( ', ', $schema['enum'] )
			);
		}

		return array( 'errors' => $errors, 'value' => $value );
	}

	/**
	 * Execute nested tool calls
	 *
	 * @param array $tool_calls_tree Tree of nested tool calls.
	 * @param array $context Execution context.
	 * @return array Execution results.
	 */
	public function execute_nested_calls( $tool_calls_tree, $context = array() ) {
		$results = array();

		// Parse dependency tree.
		$execution_order = $this->parse_dependency_tree( $tool_calls_tree );

		// Execute in correct order.
		foreach ( $execution_order as $level ) {
			$level_results = array();

			foreach ( $level as $call_id => $call_data ) {
				// Resolve dependencies.
				$resolved_args = $this->resolve_dependencies( $call_data['arguments'], $results );

				// Execute tool call.
				$tool = $this->registry->get_tool( $call_data['tool_slug'] );
				if ( ! $tool ) {
					$level_results[ $call_id ] = new WP_Error(
						'tool_not_found',
						sprintf( __( 'Tool %s not found', 'mcp-ai-wpoos' ), $call_data['tool_slug'] )
					);
					continue;
				}

				$result = $tool->execute( $resolved_args, $context );
				$level_results[ $call_id ] = $result;
			}

			$results = array_merge( $results, $level_results );
		}

		return $results;
	}

	/**
	 * Execute parallel tool calls
	 *
	 * @param array $tool_calls Array of independent tool calls.
	 * @param array $context Execution context.
	 * @return array Aggregated results.
	 */
	public function execute_parallel_calls( $tool_calls, $context = array() ) {
		$results = array();
		$errors  = array();

		// Identify independent calls (all calls with no dependencies).
		foreach ( $tool_calls as $call_id => $call_data ) {
			$tool = $this->registry->get_tool( $call_data['tool_slug'] );
			if ( ! $tool ) {
				$errors[ $call_id ] = new WP_Error(
					'tool_not_found',
					sprintf( __( 'Tool %s not found', 'mcp-ai-wpoos' ), $call_data['tool_slug'] )
				);
				continue;
			}

			// Execute tool (in PHP, we can't truly parallelize, but we batch them).
			try {
				$result = $tool->execute( $call_data['arguments'], $context );
				$results[ $call_id ] = $result;
			} catch ( Exception $e ) {
				$errors[ $call_id ] = new WP_Error(
					'execution_error',
					$e->getMessage()
				);
			}
		}

		// Aggregate results.
		return array(
			'results'         => $results,
			'errors'          => $errors,
			'total_calls'     => count( $tool_calls ),
			'successful'      => count( $results ),
			'failed'          => count( $errors ),
		);
	}

	/**
	 * Parse dependency tree
	 *
	 * @param array $tool_calls_tree Tool calls with dependencies.
	 * @return array Execution order (array of levels).
	 */
	protected function parse_dependency_tree( $tool_calls_tree ) {
		$levels    = array();
		$processed = array();

		// Simple topological sort.
		while ( count( $processed ) < count( $tool_calls_tree ) ) {
			$current_level = array();

			foreach ( $tool_calls_tree as $call_id => $call_data ) {
				if ( in_array( $call_id, $processed, true ) ) {
					continue;
				}

				// Check if all dependencies are processed.
				$dependencies = $call_data['depends_on'] ?? array();
				$all_deps_met = true;

				foreach ( $dependencies as $dep_id ) {
					if ( ! in_array( $dep_id, $processed, true ) ) {
						$all_deps_met = false;
						break;
					}
				}

				if ( $all_deps_met ) {
					$current_level[ $call_id ] = $call_data;
					$processed[] = $call_id;
				}
			}

			if ( empty( $current_level ) && count( $processed ) < count( $tool_calls_tree ) ) {
				// Circular dependency or orphaned nodes.
				break;
			}

			$levels[] = $current_level;
		}

		return $levels;
	}

	/**
	 * Resolve dependencies in arguments
	 *
	 * @param array $arguments Arguments with potential references.
	 * @param array $previous_results Previous execution results.
	 * @return array Resolved arguments.
	 */
	protected function resolve_dependencies( $arguments, $previous_results ) {
		$resolved = array();

		foreach ( $arguments as $key => $value ) {
			if ( is_string( $value ) && 0 === strpos( $value, '$ref:' ) ) {
				// Reference to previous result.
				$ref_path = substr( $value, 5 ); // Remove '$ref:' prefix.
				$resolved[ $key ] = $this->resolve_reference( $ref_path, $previous_results );
			} elseif ( is_array( $value ) ) {
				$resolved[ $key ] = $this->resolve_dependencies( $value, $previous_results );
			} else {
				$resolved[ $key ] = $value;
			}
		}

		return $resolved;
	}

	/**
	 * Resolve reference path
	 *
	 * @param string $ref_path Reference path (e.g., 'call1.result.data').
	 * @param array  $results Previous results.
	 * @return mixed Resolved value.
	 */
	protected function resolve_reference( $ref_path, $results ) {
		$parts = explode( '.', $ref_path );
		$value = $results;

		foreach ( $parts as $part ) {
			if ( is_array( $value ) && isset( $value[ $part ] ) ) {
				$value = $value[ $part ];
			} elseif ( is_object( $value ) && isset( $value->$part ) ) {
				$value = $value->$part;
			} else {
				return null;
			}
		}

		return $value;
	}
}
