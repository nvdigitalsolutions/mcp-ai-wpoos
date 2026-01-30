<?php
/**
 * Tool for validating INCI ingredients in the Regulatory Registration system.
 *
 * Allows AI assistants to validate ingredient nomenclature against INCI standards.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates INCI ingredients.
 */
class WP_MCP_AI_Tool_Validate_INCI_Ingredients implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'validate_inci_ingredients';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Validate INCI Ingredients', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates ingredient names against INCI (International Nomenclature Cosmetic Ingredient) standards. Checks for proper formatting, common names, and restricted substances.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'ingredients'        => array(
					'type'        => 'string',
					'description' => __( 'Comma-separated list of ingredients to validate (required)', 'mcp-ai-wpoos-pro' ),
				),
				'country'            => array(
					'type'        => 'string',
					'description' => __( 'Country code for country-specific restrictions (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'check_restrictions' => array(
					'type'        => 'boolean',
					'description' => __( 'Check for restricted substances (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'ingredients' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
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
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['ingredients'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Ingredients list is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Parse ingredients.
		$ingredients_text = sanitize_textarea_field( $arguments['ingredients'] );
		$ingredients      = array_map( 'trim', explode( ',', $ingredients_text ) );

		// Validate each ingredient.
		$validation_results = array();
		$invalid_count      = 0;
		$restricted_count   = 0;

		foreach ( $ingredients as $ingredient ) {
			if ( empty( $ingredient ) ) {
				continue;
			}

			$validation           = $this->validate_single_ingredient( $ingredient, $arguments );
			$validation_results[] = $validation;

			if ( ! $validation['is_valid_inci'] ) {
				++$invalid_count;
			}

			if ( ! empty( $validation['is_restricted'] ) ) {
				++$restricted_count;
			}
		}

		// Calculate overall validation status.
		$total_ingredients = count( $validation_results );
		$valid_count       = $total_ingredients - $invalid_count;
		$validation_score  = $total_ingredients > 0 ? round( ( $valid_count / $total_ingredients ) * 100, 2 ) : 0;

		$is_valid = 0 === $invalid_count && 0 === $restricted_count;
		$status   = $is_valid ? 'valid' : ( $validation_score >= 80 ? 'mostly_valid' : 'invalid' );

		return array(
			'success'                => true,
			'is_valid'               => $is_valid,
			'status'                 => $status,
			'validation_score'       => $validation_score,
			'total_ingredients'      => $total_ingredients,
			'valid_ingredients'      => $valid_count,
			'invalid_ingredients'    => $invalid_count,
			'restricted_ingredients' => $restricted_count,
			'ingredients'            => $validation_results,
			'message'                => $is_valid
				? __( 'All ingredients are valid INCI names and not restricted.', 'mcp-ai-wpoos-pro' )
				: sprintf(
					/* translators: 1: invalid count, 2: restricted count */
					__( '%1$d invalid INCI name(s), %2$d restricted ingredient(s) found.', 'mcp-ai-wpoos-pro' ),
					$invalid_count,
					$restricted_count
				),
		);
	}

	/**
	 * Validate a single ingredient.
	 *
	 * @param string $ingredient Ingredient name.
	 * @param array  $arguments Tool arguments.
	 * @return array Validation result.
	 */
	private function validate_single_ingredient( $ingredient, $arguments ) {
		$ingredient = trim( $ingredient );

		// Basic INCI validation rules.
		$is_valid_inci = $this->check_inci_format( $ingredient );
		$common_name   = $this->get_common_name( $ingredient );
		$warnings      = array();

		// Check for restricted substances if enabled.
		$is_restricted    = false;
		$restriction_info = array();
		if ( ! empty( $arguments['check_restrictions'] ) ) {
			$restriction_check = $this->check_restrictions( $ingredient, $arguments['country'] ?? '' );
			$is_restricted     = $restriction_check['is_restricted'];
			$restriction_info  = $restriction_check['info'];
		}

		// Check for common issues.
		if ( strlen( $ingredient ) < 3 ) {
			$warnings[] = __( 'Ingredient name too short (minimum 3 characters)', 'mcp-ai-wpoos-pro' );
		}

		if ( preg_match( '/[0-9]/', $ingredient ) ) {
			$warnings[] = __( 'INCI names should not contain numbers (use Roman numerals)', 'mcp-ai-wpoos-pro' );
		}

		if ( preg_match( '/[^a-zA-Z\s\-\(\)]/', $ingredient ) ) {
			$warnings[] = __( 'Contains special characters not typically used in INCI names', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'ingredient'       => $ingredient,
			'is_valid_inci'    => $is_valid_inci,
			'common_name'      => $common_name,
			'is_restricted'    => $is_restricted,
			'restriction_info' => $restriction_info,
			'warnings'         => $warnings,
		);
	}

	/**
	 * Check INCI format.
	 *
	 * @param string $ingredient Ingredient name.
	 * @return bool True if valid INCI format.
	 */
	private function check_inci_format( $ingredient ) {
		// Basic format checks:
		// - Should start with capital letter.
		// - May contain spaces, hyphens, parentheses.
		// - Should not contain numbers (except in special cases).
		// - Should not contain special symbols.

		if ( empty( $ingredient ) ) {
			return false;
		}

		// Must start with uppercase letter.
		if ( ! preg_match( '/^[A-Z]/', $ingredient ) ) {
			return false;
		}

		// Should only contain letters, spaces, hyphens, and parentheses.
		if ( ! preg_match( '/^[A-Za-z\s\-\(\)]+$/', $ingredient ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get common name for INCI ingredient.
	 *
	 * @param string $ingredient INCI ingredient name.
	 * @return string Common name or empty string.
	 */
	private function get_common_name( $ingredient ) {
		// Common INCI to common name mappings (sample set).
		$inci_to_common = array(
			'Aqua'                   => 'Water',
			'Butyrospermum Parkii'   => 'Shea Butter',
			'Cocos Nucifera'         => 'Coconut Oil',
			'Olea Europaea'          => 'Olive Oil',
			'Tocopherol'             => 'Vitamin E',
			'Ascorbic Acid'          => 'Vitamin C',
			'Retinol'                => 'Vitamin A',
			'Glycerin'               => 'Glycerine',
			'Sodium Chloride'        => 'Salt',
			'Parfum'                 => 'Fragrance',
			'Cetearyl Alcohol'       => 'Fatty Alcohol',
			'Sodium Lauryl Sulfate'  => 'SLS',
			'Sodium Laureth Sulfate' => 'SLES',
		);

		return $inci_to_common[ $ingredient ] ?? '';
	}

	/**
	 * Check if ingredient is restricted.
	 *
	 * @param string $ingredient Ingredient name.
	 * @param string $country Country code.
	 * @return array Restriction check result.
	 */
	private function check_restrictions( $ingredient, $country ) {
		// Common restricted/banned substances (sample set).
		$globally_restricted = array(
			'Hydroquinone' => array(
				'reason' => 'Banned in EU, restricted in many countries',
				'level'  => 'banned',
			),
			'Mercury'      => array(
				'reason' => 'Toxic heavy metal, globally banned',
				'level'  => 'banned',
			),
			'Lead'         => array(
				'reason' => 'Toxic heavy metal, banned in cosmetics',
				'level'  => 'banned',
			),
			'Formaldehyde' => array(
				'reason' => 'Carcinogenic, restricted in many countries',
				'level'  => 'restricted',
			),
			'Parabens'     => array(
				'reason' => 'Endocrine disruptor, restricted in some countries',
				'level'  => 'restricted',
			),
			'Triclosan'    => array(
				'reason' => 'Antimicrobial resistance concerns',
				'level'  => 'restricted',
			),
		);

		// Check if ingredient is in restricted list.
		foreach ( $globally_restricted as $restricted_name => $info ) {
			if ( stripos( $ingredient, $restricted_name ) !== false ) {
				return array(
					'is_restricted' => true,
					'info'          => array(
						'restriction_level' => $info['level'],
						'reason'            => $info['reason'],
						'country'           => $country ? $country : 'Global',
					),
				);
			}
		}

		return array(
			'is_restricted' => false,
			'info'          => array(),
		);
	}
}
