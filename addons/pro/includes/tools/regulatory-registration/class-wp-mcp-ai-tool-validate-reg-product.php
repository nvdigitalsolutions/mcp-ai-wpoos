<?php
/**
 * Tool for validating products in the Regulatory Registration system.
 *
 * Allows AI assistants to perform comprehensive product validation.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates a regulatory product.
 */
class WP_MCP_AI_Tool_Validate_Reg_Product implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'validate_reg_product';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Validate Regulatory Product', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Performs comprehensive validation of a product including INCI ingredients, HS code, data completeness, and readiness for registration.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Product ID to validate (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'check_completeness' => array(
					'type'        => 'boolean',
					'description' => __( 'Check data completeness (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'check_inci'       => array(
					'type'        => 'boolean',
					'description' => __( 'Validate INCI ingredients (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'check_hs_code'    => array(
					'type'        => 'boolean',
					'description' => __( 'Validate HS code (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'target_country'   => array(
					'type'        => 'string',
					'description' => __( 'Target country for registration (optional, for country-specific validation)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'product_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
			'idempotent',           // Can be called multiple times safely with same result.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( $arguments, $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['product_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Product ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$product_id = absint( $arguments['product_id'] );

		// Verify product exists.
		$product = get_post( $product_id );
		if ( ! $product || 'mcp_ai_reg_product' !== $product->post_type ) {
			return array(
				'success' => false,
				'error'   => __( 'Product not found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Initialize validation results.
		$validation_results = array();
		$errors = array();
		$warnings = array();
		$passed_checks = array();

		// Check data completeness if enabled.
		if ( ! empty( $arguments['check_completeness'] ) ) {
			$completeness_result = $this->check_completeness( $product_id, $product );
			$validation_results['completeness'] = $completeness_result;
			$errors = array_merge( $errors, $completeness_result['errors'] );
			$warnings = array_merge( $warnings, $completeness_result['warnings'] );
			if ( $completeness_result['is_complete'] ) {
				$passed_checks[] = 'Data completeness';
			}
		}

		// Validate INCI ingredients if enabled.
		if ( ! empty( $arguments['check_inci'] ) ) {
			$inci_result = $this->check_inci( $product_id, $arguments['target_country'] ?? '' );
			$validation_results['inci'] = $inci_result;
			if ( ! $inci_result['is_valid'] ) {
				$errors = array_merge( $errors, $inci_result['errors'] );
			} else {
				$passed_checks[] = 'INCI validation';
			}
			$warnings = array_merge( $warnings, $inci_result['warnings'] );
		}

		// Validate HS code if enabled.
		if ( ! empty( $arguments['check_hs_code'] ) ) {
			$hs_code_result = $this->check_hs_code( $product_id, $product );
			$validation_results['hs_code'] = $hs_code_result;
			if ( ! $hs_code_result['is_valid'] ) {
				$errors = array_merge( $errors, $hs_code_result['errors'] );
			} else {
				$passed_checks[] = 'HS code validation';
			}
			$warnings = array_merge( $warnings, $hs_code_result['warnings'] );
		}

		// Calculate overall validation status.
		$is_valid = empty( $errors );
		$has_warnings = ! empty( $warnings );
		$status = $is_valid ? ( $has_warnings ? 'valid_with_warnings' : 'valid' ) : 'invalid';

		// Determine readiness for registration.
		$ready_for_registration = $is_valid && ! empty( $validation_results['completeness']['is_complete'] );

		return array(
			'success'               => true,
			'product_id'            => $product_id,
			'product_title'         => $product->post_title,
			'is_valid'              => $is_valid,
			'status'                => $status,
			'ready_for_registration' => $ready_for_registration,
			'validation_results'    => $validation_results,
			'errors'                => $errors,
			'warnings'              => $warnings,
			'passed_checks'         => $passed_checks,
			'total_checks'          => count( $passed_checks ) + count( $errors ),
			'message'               => $is_valid
				? __( 'Product validation passed.', 'mcp-ai-wpoos-pro' )
				: sprintf(
					/* translators: %d: number of errors */
					__( 'Product validation failed with %d error(s).', 'mcp-ai-wpoos-pro' ),
					count( $errors )
				),
		);
	}

	/**
	 * Check data completeness.
	 *
	 * @param int     $product_id Product ID.
	 * @param WP_Post $product Product post.
	 * @return array Completeness result.
	 */
	private function check_completeness( $product_id, $product ) {
		$errors = array();
		$warnings = array();
		$missing_fields = array();
		$present_fields = array();

		// Required fields.
		$required_fields = array(
			'brand'           => get_post_meta( $product_id, 'brand', true ),
			'manufacturer'    => get_post_meta( $product_id, 'manufacturer', true ),
			'origin_country'  => get_post_meta( $product_id, 'origin_country', true ),
			'inci_ingredients' => get_post_meta( $product_id, 'inci_ingredients', true ),
			'hs_code'         => get_post_meta( $product_id, 'hs_code', true ),
		);

		foreach ( $required_fields as $field => $value ) {
			if ( empty( $value ) ) {
				$missing_fields[] = $field;
				$errors[] = sprintf(
					/* translators: %s: field name */
					__( 'Required field missing: %s', 'mcp-ai-wpoos-pro' ),
					ucfirst( str_replace( '_', ' ', $field ) )
				);
			} else {
				$present_fields[] = $field;
			}
		}

		// Optional but recommended fields.
		$optional_fields = array(
			'barcode'   => get_post_meta( $product_id, 'barcode', true ),
			'pack_size' => get_post_meta( $product_id, 'pack_size', true ),
			'allergens' => get_post_meta( $product_id, 'allergens', true ),
		);

		foreach ( $optional_fields as $field => $value ) {
			if ( empty( $value ) ) {
				$warnings[] = sprintf(
					/* translators: %s: field name */
					__( 'Recommended field missing: %s', 'mcp-ai-wpoos-pro' ),
					ucfirst( str_replace( '_', ' ', $field ) )
				);
			}
		}

		$total_fields = count( $required_fields );
		$completion_percentage = $total_fields > 0 ? round( ( count( $present_fields ) / $total_fields ) * 100, 2 ) : 0;

		return array(
			'is_complete'           => empty( $missing_fields ),
			'completion_percentage' => $completion_percentage,
			'missing_fields'        => $missing_fields,
			'present_fields'        => $present_fields,
			'errors'                => $errors,
			'warnings'              => $warnings,
		);
	}

	/**
	 * Check INCI ingredients.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $country Target country.
	 * @return array INCI validation result.
	 */
	private function check_inci( $product_id, $country ) {
		$inci_ingredients = get_post_meta( $product_id, 'inci_ingredients', true );
		$errors = array();
		$warnings = array();

		if ( empty( $inci_ingredients ) ) {
			$errors[] = __( 'INCI ingredients list is empty.', 'mcp-ai-wpoos-pro' );
			return array(
				'is_valid' => false,
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}

		// Basic INCI format checks.
		$ingredients = array_map( 'trim', explode( ',', $inci_ingredients ) );

		foreach ( $ingredients as $ingredient ) {
			// Check capitalization.
			if ( ! empty( $ingredient ) && ! preg_match( '/^[A-Z]/', $ingredient ) ) {
				$warnings[] = sprintf(
					/* translators: %s: ingredient name */
					__( 'Ingredient should start with capital letter: %s', 'mcp-ai-wpoos-pro' ),
					$ingredient
				);
			}

			// Check for numbers (should use Roman numerals).
			if ( preg_match( '/[0-9]/', $ingredient ) ) {
				$warnings[] = sprintf(
					/* translators: %s: ingredient name */
					__( 'INCI names should not contain numbers: %s', 'mcp-ai-wpoos-pro' ),
					$ingredient
				);
			}
		}

		return array(
			'is_valid' => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
			'ingredient_count' => count( $ingredients ),
		);
	}

	/**
	 * Check HS code.
	 *
	 * @param int     $product_id Product ID.
	 * @param WP_Post $product Product post.
	 * @return array HS code validation result.
	 */
	private function check_hs_code( $product_id, $product ) {
		$hs_code = get_post_meta( $product_id, 'hs_code', true );
		$errors = array();
		$warnings = array();

		if ( empty( $hs_code ) ) {
			$errors[] = __( 'HS code is missing.', 'mcp-ai-wpoos-pro' );
			return array(
				'is_valid' => false,
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}

		// Clean HS code.
		$clean_code = str_replace( array( ' ', '.' ), '', $hs_code );

		// Check format.
		if ( ! preg_match( '/^\d{6,10}$/', $clean_code ) ) {
			$errors[] = __( 'HS code must be 6-10 digits.', 'mcp-ai-wpoos-pro' );
		}

		// Check if starts with 33 (cosmetics chapter).
		if ( '33' !== substr( $clean_code, 0, 2 ) ) {
			$warnings[] = __( 'Cosmetics typically use HS codes starting with 33.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'is_valid' => empty( $errors ),
			'hs_code'  => $hs_code,
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}
}
