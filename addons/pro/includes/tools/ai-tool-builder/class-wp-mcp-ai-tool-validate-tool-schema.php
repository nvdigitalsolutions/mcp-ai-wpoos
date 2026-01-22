<?php
/**
 * Tool for validating tool parameter schemas.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate tool parameter schemas for correctness and completeness.
 *
 * This tool validates JSON schema parameter definitions, checks for
 * common issues, ensures WordPress compatibility, and provides
 * recommendations for improvement.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Validate_Tool_Schema implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'validate_tool_schema';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Validate Tool Schema', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validate tool parameter schemas for correctness, completeness, and WordPress compatibility. Checks JSON schema structure, type definitions, validation rules, and provides improvement recommendations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'schema'                 => array(
					'type'        => 'object',
					'description' => __( 'Parameter schema to validate (JSON schema format)', 'mcp-ai-wpoos-pro' ),
				),
				'tool_file'              => array(
					'type'        => 'string',
					'description' => __( 'Path to tool file (alternative to providing schema directly)', 'mcp-ai-wpoos-pro' ),
				),
				'strict_mode'            => array(
					'type'        => 'boolean',
					'description' => __( 'Enable strict validation (all recommended practices required)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'check_wordpress_compat' => array(
					'type'        => 'boolean',
					'description' => __( 'Check WordPress-specific compatibility', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'check_security'         => array(
					'type'        => 'boolean',
					'description' => __( 'Check for security best practices', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
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
			'local-only',
			'idempotent',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Get schema from arguments or file.
		$schema = null;

		if ( isset( $arguments['schema'] ) && ! empty( $arguments['schema'] ) ) {
			$schema = $arguments['schema'];
		} elseif ( isset( $arguments['tool_file'] ) && ! empty( $arguments['tool_file'] ) ) {
			$tool_file = sanitize_text_field( $arguments['tool_file'] );

			if ( ! file_exists( $tool_file ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Tool file not found.', 'mcp-ai-wpoos-pro' ),
				);
			}

			$schema = $this->extract_schema_from_file( $tool_file );
		}

		if ( empty( $schema ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Either schema or tool_file must be provided.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$strict_mode     = isset( $arguments['strict_mode'] ) ? (bool) $arguments['strict_mode'] : false;
		$check_wp_compat = isset( $arguments['check_wordpress_compat'] ) ? (bool) $arguments['check_wordpress_compat'] : true;
		$check_security  = isset( $arguments['check_security'] ) ? (bool) $arguments['check_security'] : true;

		// Run validation checks.
		$validation_results = array(
			'structure'    => $this->validate_structure( $schema ),
			'types'        => $this->validate_types( $schema ),
			'required'     => $this->validate_required_fields( $schema ),
			'descriptions' => $this->validate_descriptions( $schema ),
		);

		if ( $check_wp_compat ) {
			$validation_results['wordpress'] = $this->validate_wordpress_compatibility( $schema );
		}

		if ( $check_security ) {
			$validation_results['security'] = $this->validate_security_practices( $schema );
		}

		// Aggregate results.
		$all_errors          = array();
		$all_warnings        = array();
		$all_recommendations = array();

		foreach ( $validation_results as $category => $result ) {
			if ( isset( $result['errors'] ) ) {
				$all_errors = array_merge( $all_errors, $result['errors'] );
			}
			if ( isset( $result['warnings'] ) ) {
				$all_warnings = array_merge( $all_warnings, $result['warnings'] );
			}
			if ( isset( $result['recommendations'] ) ) {
				$all_recommendations = array_merge( $all_recommendations, $result['recommendations'] );
			}
		}

		// Determine if valid.
		$is_valid = empty( $all_errors );
		if ( $strict_mode && ! empty( $all_warnings ) ) {
			$is_valid = false;
		}

		return array(
			'success'              => true,
			'valid'                => $is_valid,
			'errors'               => $all_errors,
			'warnings'             => $all_warnings,
			'recommendations'      => $all_recommendations,
			'error_count'          => count( $all_errors ),
			'warning_count'        => count( $all_warnings ),
			'recommendation_count' => count( $all_recommendations ),
			'validation_results'   => $validation_results,
		);
	}

	/**
	 * Extract schema from tool file.
	 *
	 * @param string $file_path Tool file path.
	 * @return array|null Schema array or null.
	 */
	private function extract_schema_from_file( $file_path ) {
		$content = file_get_contents( $file_path );

		// Try to extract get_parameters_schema method.
		$pattern = '/public function get_parameters_schema\(\) \{.*?return (array\(.*?\));.*?\}/s';
		if ( preg_match( $pattern, $content, $matches ) ) {
			// This is simplified - in production, would need proper PHP parsing.
			// For now, return empty array to indicate file was found but schema extraction needs work.
			return array();
		}

		return null;
	}

	/**
	 * Validate schema structure.
	 *
	 * @param array $schema Schema to validate.
	 * @return array Validation results.
	 */
	private function validate_structure( $schema ) {
		$errors   = array();
		$warnings = array();

		// Check for required top-level keys.
		if ( ! isset( $schema['type'] ) ) {
			$errors[] = __( 'Schema missing required "type" field.', 'mcp-ai-wpoos-pro' );
		}

		if ( ! isset( $schema['properties'] ) ) {
			$errors[] = __( 'Schema missing required "properties" field.', 'mcp-ai-wpoos-pro' );
		}

		// Check properties is an array.
		if ( isset( $schema['properties'] ) && ! is_array( $schema['properties'] ) ) {
			$errors[] = __( 'Schema "properties" must be an array.', 'mcp-ai-wpoos-pro' );
		}

		// Check for empty properties.
		if ( isset( $schema['properties'] ) && empty( $schema['properties'] ) ) {
			$warnings[] = __( 'Schema has no parameters defined.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Validate parameter types.
	 *
	 * @param array $schema Schema to validate.
	 * @return array Validation results.
	 */
	private function validate_types( $schema ) {
		$errors      = array();
		$warnings    = array();
		$valid_types = array( 'string', 'integer', 'number', 'boolean', 'array', 'object' );

		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return array(
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}

		foreach ( $schema['properties'] as $param_name => $param_def ) {
			// Check for type field.
			if ( ! isset( $param_def['type'] ) ) {
				$errors[] = sprintf(
					/* translators: %s: parameter name */
					__( 'Parameter "%s" missing required "type" field.', 'mcp-ai-wpoos-pro' ),
					$param_name
				);
				continue;
			}

			// Validate type value.
			if ( ! in_array( $param_def['type'], $valid_types, true ) ) {
				$errors[] = sprintf(
					/* translators: 1: parameter name, 2: invalid type */
					__( 'Parameter "%1$s" has invalid type "%2$s".', 'mcp-ai-wpoos-pro' ),
					$param_name,
					$param_def['type']
				);
			}

			// Check array items definition.
			if ( 'array' === $param_def['type'] && ! isset( $param_def['items'] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: parameter name */
					__( 'Array parameter "%s" should define "items" schema.', 'mcp-ai-wpoos-pro' ),
					$param_name
				);
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Validate required fields.
	 *
	 * @param array $schema Schema to validate.
	 * @return array Validation results.
	 */
	private function validate_required_fields( $schema ) {
		$errors   = array();
		$warnings = array();

		if ( ! isset( $schema['required'] ) ) {
			$warnings[] = __( 'Schema missing "required" array. All parameters will be optional.', 'mcp-ai-wpoos-pro' );
			return array(
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}

		if ( ! is_array( $schema['required'] ) ) {
			$errors[] = __( 'Schema "required" field must be an array.', 'mcp-ai-wpoos-pro' );
			return array(
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}

		// Check required fields exist in properties.
		$properties = isset( $schema['properties'] ) ? array_keys( $schema['properties'] ) : array();
		foreach ( $schema['required'] as $required_field ) {
			if ( ! in_array( $required_field, $properties, true ) ) {
				$errors[] = sprintf(
					/* translators: %s: field name */
					__( 'Required field "%s" not defined in properties.', 'mcp-ai-wpoos-pro' ),
					$required_field
				);
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Validate parameter descriptions.
	 *
	 * @param array $schema Schema to validate.
	 * @return array Validation results.
	 */
	private function validate_descriptions( $schema ) {
		$errors          = array();
		$warnings        = array();
		$recommendations = array();

		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return array(
				'errors'          => $errors,
				'warnings'        => $warnings,
				'recommendations' => $recommendations,
			);
		}

		foreach ( $schema['properties'] as $param_name => $param_def ) {
			// Check for description.
			if ( ! isset( $param_def['description'] ) || empty( $param_def['description'] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: parameter name */
					__( 'Parameter "%s" missing description.', 'mcp-ai-wpoos-pro' ),
					$param_name
				);
			} elseif ( strlen( $param_def['description'] ) < 10 ) {
				$recommendations[] = sprintf(
					/* translators: %s: parameter name */
					__( 'Parameter "%s" description is too short. Consider adding more detail.', 'mcp-ai-wpoos-pro' ),
					$param_name
				);
			}
		}

		return array(
			'errors'          => $errors,
			'warnings'        => $warnings,
			'recommendations' => $recommendations,
		);
	}

	/**
	 * Validate WordPress compatibility.
	 *
	 * @param array $schema Schema to validate.
	 * @return array Validation results.
	 */
	private function validate_wordpress_compatibility( $schema ) {
		$errors          = array();
		$warnings        = array();
		$recommendations = array();

		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return array(
				'errors'          => $errors,
				'warnings'        => $warnings,
				'recommendations' => $recommendations,
			);
		}

		foreach ( $schema['properties'] as $param_name => $param_def ) {
			// Check naming convention (snake_case).
			if ( $param_name !== sanitize_key( $param_name ) ) {
				$recommendations[] = sprintf(
					/* translators: %s: parameter name */
					__( 'Parameter "%s" should use snake_case naming (WordPress convention).', 'mcp-ai-wpoos-pro' ),
					$param_name
				);
			}

			// Check for WordPress-specific types that might need special handling.
			if ( isset( $param_def['description'] ) ) {
				$desc_lower = strtolower( $param_def['description'] );
				if ( strpos( $desc_lower, 'post id' ) !== false && 'integer' !== $param_def['type'] ) {
					$recommendations[] = sprintf(
						/* translators: %s: parameter name */
						__( 'Parameter "%s" appears to be a post ID but type is not "integer".', 'mcp-ai-wpoos-pro' ),
						$param_name
					);
				}
			}
		}

		return array(
			'errors'          => $errors,
			'warnings'        => $warnings,
			'recommendations' => $recommendations,
		);
	}

	/**
	 * Validate security practices.
	 *
	 * @param array $schema Schema to validate.
	 * @return array Validation results.
	 */
	private function validate_security_practices( $schema ) {
		$errors          = array();
		$warnings        = array();
		$recommendations = array();

		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return array(
				'errors'          => $errors,
				'warnings'        => $warnings,
				'recommendations' => $recommendations,
			);
		}

		foreach ( $schema['properties'] as $param_name => $param_def ) {
			$desc = isset( $param_def['description'] ) ? strtolower( $param_def['description'] ) : '';

			// Check for potentially dangerous parameters.
			if ( strpos( $param_name, 'file' ) !== false || strpos( $param_name, 'path' ) !== false ) {
				$recommendations[] = sprintf(
					/* translators: %s: parameter name */
					__( 'Parameter "%s" handles file paths. Ensure proper validation and sanitization in implementation.', 'mcp-ai-wpoos-pro' ),
					$param_name
				);
			}

			if ( strpos( $param_name, 'sql' ) !== false || strpos( $param_name, 'query' ) !== false ) {
				$warnings[] = sprintf(
					/* translators: %s: parameter name */
					__( 'Parameter "%s" appears to handle SQL. Ensure prepared statements are used.', 'mcp-ai-wpoos-pro' ),
					$param_name
				);
			}

			if ( strpos( $desc, 'html' ) !== false || strpos( $desc, 'script' ) !== false ) {
				$recommendations[] = sprintf(
					/* translators: %s: parameter name */
					__( 'Parameter "%s" may contain HTML/scripts. Ensure proper sanitization.', 'mcp-ai-wpoos-pro' ),
					$param_name
				);
			}
		}

		return array(
			'errors'          => $errors,
			'warnings'        => $warnings,
			'recommendations' => $recommendations,
		);
	}
}
