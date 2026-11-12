<?php
/**
 * Validate Tool Schema tool — checks a JSON-schema parameter definition.
 *
 * Pure logic: structure, type, required-field, description, naming and
 * security heuristics over a caller-supplied schema. Findings and counts are
 * identical to the WordPress implementation.
 *
 * The WordPress tool can also read a schema out of a PHP file; its extractor
 * is a documented stub that always yields an empty schema, so this port
 * reproduces the observable behaviour (the same two errors) without adding a
 * caller-controlled file read to the core runtime.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

class ValidateToolSchemaTool extends AbstractTool {

	/**
	 * JSON-schema types this runtime understands.
	 */
	private const VALID_TYPES = array( 'string', 'integer', 'number', 'boolean', 'array', 'object' );

	public function getSlug(): string {
		return 'validate_tool_schema';
	}

	public function getName(): string {
		return 'Validate Tool Schema';
	}

	public function getDescription(): string {
		return 'Validate tool parameter schemas for correctness, completeness, and WordPress compatibility. Checks JSON schema structure, type definitions, validation rules, and provides improvement recommendations.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'schema'                 => array(
					'type'        => 'object',
					'description' => 'Parameter schema to validate (JSON schema format)',
				),
				'tool_file'              => array(
					'type'        => 'string',
					'description' => 'Path to tool file (alternative to providing schema directly)',
				),
				'strict_mode'            => array(
					'type'        => 'boolean',
					'description' => 'Enable strict validation (all recommended practices required)',
					'default'     => false,
				),
				'check_wordpress_compat' => array(
					'type'        => 'boolean',
					'description' => 'Check WordPress-specific compatibility',
					'default'     => true,
				),
				'check_security'         => array(
					'type'        => 'boolean',
					'description' => 'Check for security best practices',
					'default'     => true,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		unset( $context );

		$schema    = $this->objectParam( $arguments, 'schema' );
		$tool_file = $this->stringParam( $arguments, 'tool_file' );

		if ( array() === $schema && '' !== $tool_file ) {
			if ( ! file_exists( $tool_file ) ) {
				return $this->errors->validationFailed(
					'Tool file not found.',
					array( 'tool_file' => array( 'The path does not exist.' ) )
				);
			}

			// Schema extraction from a PHP file is not implemented in this
			// runtime; the WordPress extractor is a stub too, so this is the
			// same observable result.
			return $this->errors->validationFailed(
				'Either schema or tool_file must be provided.',
				array( 'schema' => array( 'Pass the schema object; file extraction is unavailable.' ) )
			);
		}

		if ( array() === $schema ) {
			return $this->errors->validationFailed(
				'Either schema or tool_file must be provided.',
				array( 'schema' => array( 'Pass the schema object to validate.' ) )
			);
		}

		$strict_mode     = $this->boolParam( $arguments, 'strict_mode' );
		$check_wp_compat = $this->boolParam( $arguments, 'check_wordpress_compat', true );
		$check_security  = $this->boolParam( $arguments, 'check_security', true );

		$results = array(
			'structure'    => $this->validate_structure( $schema ),
			'types'        => $this->validate_types( $schema ),
			'required'     => $this->validate_required_fields( $schema ),
			'descriptions' => $this->validate_descriptions( $schema ),
		);

		if ( $check_wp_compat ) {
			$results['wordpress'] = $this->validate_wordpress_compatibility( $schema );
		}

		if ( $check_security ) {
			$results['security'] = $this->validate_security_practices( $schema );
		}

		$errors          = array();
		$warnings        = array();
		$recommendations = array();

		foreach ( $results as $result ) {
			$errors          = array_merge( $errors, $result['errors'] ?? array() );
			$warnings        = array_merge( $warnings, $result['warnings'] ?? array() );
			$recommendations = array_merge( $recommendations, $result['recommendations'] ?? array() );
		}

		$is_valid = array() === $errors;
		if ( $strict_mode && array() !== $warnings ) {
			$is_valid = false;
		}

		return $this->success(
			'Schema validated.',
			array(
				'valid'                => $is_valid,
				'errors'               => $errors,
				'warnings'             => $warnings,
				'recommendations'      => $recommendations,
				'error_count'          => count( $errors ),
				'warning_count'        => count( $warnings ),
				'recommendation_count' => count( $recommendations ),
				'validation_results'   => $results,
			)
		);
	}

	/**
	 * @param array<string, mixed> $schema Schema to validate.
	 * @return array{errors: string[], warnings: string[]}
	 */
	private function validate_structure( array $schema ): array {
		$errors   = array();
		$warnings = array();

		if ( ! isset( $schema['type'] ) ) {
			$errors[] = 'Schema missing required "type" field.';
		}

		if ( ! isset( $schema['properties'] ) ) {
			$errors[] = 'Schema missing required "properties" field.';
		}

		if ( isset( $schema['properties'] ) && ! is_array( $schema['properties'] ) ) {
			$errors[] = 'Schema "properties" must be an array.';
		}

		if ( isset( $schema['properties'] ) && array() === $schema['properties'] ) {
			$warnings[] = 'Schema has no parameters defined.';
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * @param array<string, mixed> $schema Schema to validate.
	 * @return array{errors: string[], warnings: string[]}
	 */
	private function validate_types( array $schema ): array {
		$errors   = array();
		$warnings = array();

		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return array(
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}

		foreach ( $schema['properties'] as $param_name => $param_def ) {
			if ( ! is_array( $param_def ) ) {
				continue;
			}

			if ( ! isset( $param_def['type'] ) ) {
				$errors[] = sprintf( 'Parameter "%s" missing required "type" field.', $param_name );
				continue;
			}

			if ( ! in_array( $param_def['type'], self::VALID_TYPES, true ) ) {
				$errors[] = sprintf(
					'Parameter "%1$s" has invalid type "%2$s".',
					$param_name,
					is_scalar( $param_def['type'] ) ? (string) $param_def['type'] : gettype( $param_def['type'] )
				);
			}

			if ( 'array' === $param_def['type'] && ! isset( $param_def['items'] ) ) {
				$warnings[] = sprintf( 'Array parameter "%s" should define "items" schema.', $param_name );
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * @param array<string, mixed> $schema Schema to validate.
	 * @return array{errors: string[], warnings: string[]}
	 */
	private function validate_required_fields( array $schema ): array {
		$errors   = array();
		$warnings = array();

		if ( ! isset( $schema['required'] ) ) {
			$warnings[] = 'Schema missing "required" array. All parameters will be optional.';

			return array(
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}

		if ( ! is_array( $schema['required'] ) ) {
			$errors[] = 'Schema "required" field must be an array.';

			return array(
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}

		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] )
			? array_keys( $schema['properties'] )
			: array();

		foreach ( $schema['required'] as $required_field ) {
			if ( ! in_array( $required_field, $properties, true ) ) {
				$errors[] = sprintf( 'Required field "%s" not defined in properties.', $required_field );
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * @param array<string, mixed> $schema Schema to validate.
	 * @return array{errors: string[], warnings: string[], recommendations: string[]}
	 */
	private function validate_descriptions( array $schema ): array {
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
			if ( ! is_array( $param_def ) ) {
				continue;
			}

			if ( ! isset( $param_def['description'] ) || '' === $param_def['description'] ) {
				$warnings[] = sprintf( 'Parameter "%s" missing description.', $param_name );
			} elseif ( strlen( (string) $param_def['description'] ) < 10 ) {
				$recommendations[] = sprintf(
					'Parameter "%s" description is too short. Consider adding more detail.',
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
	 * @param array<string, mixed> $schema Schema to validate.
	 * @return array{errors: string[], warnings: string[], recommendations: string[]}
	 */
	private function validate_wordpress_compatibility( array $schema ): array {
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
			if ( ! is_array( $param_def ) ) {
				continue;
			}

			// Equivalent to `sanitize_key( $name ) !== $name` without the
			// WordPress helper: lowercase letters, digits, underscore, hyphen.
			if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', (string) $param_name ) ) {
				$recommendations[] = sprintf(
					'Parameter "%s" should use snake_case naming (WordPress convention).',
					$param_name
				);
			}

			if ( isset( $param_def['description'] ) ) {
				$desc_lower = strtolower( (string) $param_def['description'] );
				if ( false !== strpos( $desc_lower, 'post id' ) && 'integer' !== ( $param_def['type'] ?? '' ) ) {
					$recommendations[] = sprintf(
						'Parameter "%s" appears to be a post ID but type is not "integer".',
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
	 * @param array<string, mixed> $schema Schema to validate.
	 * @return array{errors: string[], warnings: string[], recommendations: string[]}
	 */
	private function validate_security_practices( array $schema ): array {
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
			if ( ! is_array( $param_def ) ) {
				continue;
			}

			$name = (string) $param_name;
			$desc = isset( $param_def['description'] ) ? strtolower( (string) $param_def['description'] ) : '';

			if ( false !== strpos( $name, 'file' ) || false !== strpos( $name, 'path' ) ) {
				$recommendations[] = sprintf(
					'Parameter "%s" handles file paths. Ensure proper validation and sanitization in implementation.',
					$name
				);
			}

			if ( false !== strpos( $name, 'sql' ) || false !== strpos( $name, 'query' ) ) {
				$warnings[] = sprintf(
					'Parameter "%s" appears to handle SQL. Ensure prepared statements are used.',
					$name
				);
			}

			if ( false !== strpos( $desc, 'html' ) || false !== strpos( $desc, 'script' ) ) {
				$recommendations[] = sprintf(
					'Parameter "%s" may contain HTML/scripts. Ensure proper sanitization.',
					$name
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
