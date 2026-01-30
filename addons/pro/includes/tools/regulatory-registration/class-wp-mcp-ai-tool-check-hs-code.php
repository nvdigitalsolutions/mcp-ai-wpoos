<?php
/**
 * Tool for checking HS codes in the Regulatory Registration system.
 *
 * Allows AI assistants to validate Harmonized System codes for products.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks and validates HS codes.
 */
class WP_MCP_AI_Tool_Check_HS_Code implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_hs_code';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check HS Code', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates Harmonized System (HS) tariff codes for cosmetics and perfume products. Checks format, provides product category information, and suggests corrections.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'hs_code'          => array(
					'type'        => 'string',
					'description' => __( 'HS code to validate (required, e.g. 3304.99.00)', 'mcp-ai-wpoos-pro' ),
				),
				'product_type'     => array(
					'type'        => 'string',
					'description' => __( 'Product type to suggest appropriate HS code (optional)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'hs_code' ),
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
	 */
	public function execute( $arguments, $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['hs_code'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'HS code is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$hs_code = sanitize_text_field( $arguments['hs_code'] );

		// Validate HS code format.
		$format_validation = $this->validate_hs_code_format( $hs_code );

		if ( ! $format_validation['is_valid'] ) {
			return array(
				'success'  => false,
				'hs_code'  => $hs_code,
				'is_valid' => false,
				'errors'   => $format_validation['errors'],
				'message'  => __( 'HS code format is invalid.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get HS code information.
		$hs_info = $this->get_hs_code_info( $hs_code );

		// Check if product type matches HS code category.
		$product_type_match = true;
		$suggestions = array();
		if ( ! empty( $arguments['product_type'] ) ) {
			$match_check = $this->check_product_type_match( $hs_code, $arguments['product_type'] );
			$product_type_match = $match_check['matches'];
			$suggestions = $match_check['suggestions'];
		}

		return array(
			'success'            => true,
			'hs_code'            => $hs_code,
			'is_valid'           => true,
			'hs_info'            => $hs_info,
			'product_type_match' => $product_type_match,
			'suggestions'        => $suggestions,
			'message'            => __( 'HS code is valid.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Validate HS code format.
	 *
	 * @param string $hs_code HS code to validate.
	 * @return array Validation result.
	 */
	private function validate_hs_code_format( $hs_code ) {
		$errors = array();

		// Remove spaces and dots for validation.
		$clean_code = str_replace( array( ' ', '.' ), '', $hs_code );

		// HS codes should be 6-10 digits.
		if ( ! preg_match( '/^\d{6,10}$/', $clean_code ) ) {
			$errors[] = __( 'HS code must be 6-10 digits.', 'mcp-ai-wpoos-pro' );
		}

		// Cosmetics codes should start with 33.
		$first_two = substr( $clean_code, 0, 2 );
		if ( '33' !== $first_two ) {
			$errors[] = __( 'Cosmetics and perfumes typically use HS codes starting with 33.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'is_valid' => empty( $errors ),
			'errors'   => $errors,
		);
	}

	/**
	 * Get HS code information.
	 *
	 * @param string $hs_code HS code.
	 * @return array HS code information.
	 */
	private function get_hs_code_info( $hs_code ) {
		// Clean code for lookup.
		$clean_code = str_replace( array( ' ', '.' ), '', $hs_code );
		$chapter = substr( $clean_code, 0, 2 );
		$heading = substr( $clean_code, 0, 4 );

		// Common cosmetics HS codes (sample set).
		$hs_codes = array(
			'3303' => array(
				'description' => 'Perfumes and toilet waters',
				'category'    => 'perfumes',
			),
			'3304' => array(
				'description' => 'Beauty or makeup preparations and skin care preparations',
				'category'    => 'cosmetics',
				'subcodes'    => array(
					'330410' => 'Lip makeup preparations',
					'330420' => 'Eye makeup preparations',
					'330430' => 'Manicure or pedicure preparations',
					'330491' => 'Powders (pressed or unpressed)',
					'330499' => 'Other beauty/makeup preparations',
				),
			),
			'3305' => array(
				'description' => 'Preparations for use on hair',
				'category'    => 'haircare',
				'subcodes'    => array(
					'330510' => 'Shampoos',
					'330520' => 'Preparations for permanent waving or straightening',
					'330530' => 'Hair lacquers',
					'330590' => 'Other hair preparations',
				),
			),
			'3306' => array(
				'description' => 'Preparations for oral or dental hygiene',
				'category'    => 'oral_care',
			),
			'3307' => array(
				'description' => 'Pre-shave, shaving preparations, deodorants',
				'category'    => 'toiletries',
			),
		);

		// Look up heading.
		$info = $hs_codes[ $heading ] ?? array();

		// If not found, look up chapter.
		if ( empty( $info ) && '33' === $chapter ) {
			$info = array(
				'description' => 'Essential oils and resinoids; perfumery, cosmetic or toilet preparations',
				'category'    => 'cosmetics_general',
			);
		}

		// Check for subcode match.
		$subcode = substr( $clean_code, 0, 6 );
		if ( ! empty( $info['subcodes'] ) && isset( $info['subcodes'][ $subcode ] ) ) {
			$info['subcode_description'] = $info['subcodes'][ $subcode ];
		}

		return $info;
	}

	/**
	 * Check if product type matches HS code category.
	 *
	 * @param string $hs_code HS code.
	 * @param string $product_type Product type.
	 * @return array Match result with suggestions.
	 */
	private function check_product_type_match( $hs_code, $product_type ) {
		$clean_code = str_replace( array( ' ', '.' ), '', $hs_code );
		$heading = substr( $clean_code, 0, 4 );

		// Product type to HS code mapping.
		$product_to_hs = array(
			'perfume'       => array( '3303' ),
			'lipstick'      => array( '3304' ),
			'lip gloss'     => array( '3304' ),
			'mascara'       => array( '3304' ),
			'eye shadow'    => array( '3304' ),
			'foundation'    => array( '3304' ),
			'nail polish'   => array( '3304' ),
			'face powder'   => array( '3304' ),
			'cream'         => array( '3304' ),
			'lotion'        => array( '3304' ),
			'serum'         => array( '3304' ),
			'shampoo'       => array( '3305' ),
			'conditioner'   => array( '3305' ),
			'hair oil'      => array( '3305' ),
			'hair spray'    => array( '3305' ),
			'toothpaste'    => array( '3306' ),
			'mouthwash'     => array( '3306' ),
			'deodorant'     => array( '3307' ),
			'shaving cream' => array( '3307' ),
		);

		$product_lower = strtolower( $product_type );
		$expected_codes = array();

		// Find matching product types.
		foreach ( $product_to_hs as $type => $codes ) {
			if ( stripos( $product_lower, $type ) !== false ) {
				$expected_codes = array_merge( $expected_codes, $codes );
			}
		}

		// Check if current heading matches expected.
		$matches = empty( $expected_codes ) || in_array( $heading, $expected_codes, true );

		// Generate suggestions if no match.
		$suggestions = array();
		if ( ! $matches && ! empty( $expected_codes ) ) {
			foreach ( $expected_codes as $code ) {
				$suggestions[] = array(
					'hs_code'     => $code . '.00.00',
					'description' => $this->get_hs_code_info( $code . '0000' )['description'] ?? 'Unknown',
				);
			}
		}

		return array(
			'matches'     => $matches,
			'suggestions' => $suggestions,
		);
	}
}
