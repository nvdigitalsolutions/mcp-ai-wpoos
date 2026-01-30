<?php
/**
 * Tool for validating document checklist in the Regulatory Registration system.
 *
 * Allows AI assistants to check if all required documents are present for a registration.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates document checklist for a registration.
 */
class WP_MCP_AI_Tool_Validate_Document_Checklist implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'validate_document_checklist';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Validate Document Checklist', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates that all required documents are present for a product or registration. Returns missing documents and overall compliance status.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Product ID to validate (optional, must provide product_id or registration_id)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'registration_id' => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID to validate (optional, must provide product_id or registration_id)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'country'         => array(
					'type'        => 'string',
					'description' => __( 'Country code to check country-specific requirements (optional)', 'mcp-ai-wpoos-pro' ),
				),
			),
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
			'cacheable',            // Results can be cached.
			'idempotent',           // Can be called multiple times safely with same result.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
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
		// Must have either product_id or registration_id.
		if ( empty( $arguments['product_id'] ) && empty( $arguments['registration_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Either product_id or registration_id must be provided.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Determine entity type and ID.
		$entity_type = ! empty( $arguments['registration_id'] ) ? 'registration' : 'product';
		$entity_id   = ! empty( $arguments['registration_id'] ) ? absint( $arguments['registration_id'] ) : absint( $arguments['product_id'] );

		// Verify entity exists.
		$post_type = 'registration' === $entity_type ? 'mcp_ai_registration' : 'mcp_ai_reg_product';
		$entity    = get_post( $entity_id );
		if ( ! $entity || $post_type !== $entity->post_type ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: entity type */
					__( '%s not found.', 'mcp-ai-wpoos-pro' ),
					ucfirst( $entity_type )
				),
			);
		}

		// Get country for country-specific requirements.
		$country = ! empty( $arguments['country'] ) ? sanitize_text_field( $arguments['country'] ) : '';
		if ( 'registration' === $entity_type && empty( $country ) ) {
			$country = get_post_meta( $entity_id, 'country', true );
		}

		// Define required document types (default set).
		$required_documents = $this->get_required_documents( $country );

		// Get existing documents.
		$meta_key      = 'registration' === $entity_type ? 'registration_id' : 'product_id';
		$existing_docs = get_posts(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'posts_per_page' => -1,
				'meta_key'       => $meta_key,
				'meta_value'     => $entity_id,
				'fields'         => 'ids',
			)
		);

		// Get document types of existing documents.
		$existing_types = array();
		$expired_docs   = array();
		foreach ( $existing_docs as $doc_id ) {
			$doc_type = get_post_meta( $doc_id, 'document_type', true );
			if ( $doc_type ) {
				$existing_types[] = $doc_type;

				// Check expiry.
				$expiry_date = get_post_meta( $doc_id, 'expiry_date', true );
				if ( ! empty( $expiry_date ) && strtotime( $expiry_date ) < time() ) {
					$expired_docs[] = array(
						'document_id'   => $doc_id,
						'document_type' => $doc_type,
						'expiry_date'   => $expiry_date,
					);
				}
			}
		}

		// Check for missing documents.
		$missing_documents = array_diff( $required_documents, $existing_types );

		// Calculate compliance.
		$total_required        = count( $required_documents );
		$total_present         = count( array_intersect( $required_documents, $existing_types ) );
		$completion_percentage = $total_required > 0 ? round( ( $total_present / $total_required ) * 100, 2 ) : 100;

		// Determine overall status.
		$is_compliant = empty( $missing_documents ) && empty( $expired_docs );
		$status       = $is_compliant ? 'compliant' : ( $total_present > 0 ? 'partially_compliant' : 'non_compliant' );

		return array(
			'success'               => true,
			'entity_type'           => $entity_type,
			'entity_id'             => $entity_id,
			'country'               => $country,
			'is_compliant'          => $is_compliant,
			'status'                => $status,
			'completion_percentage' => $completion_percentage,
			'required_documents'    => $required_documents,
			'present_documents'     => array_values( array_unique( $existing_types ) ),
			'missing_documents'     => array_values( $missing_documents ),
			'expired_documents'     => $expired_docs,
			'total_required'        => $total_required,
			'total_present'         => $total_present,
			'total_missing'         => count( $missing_documents ),
			'total_expired'         => count( $expired_docs ),
			'message'               => $is_compliant
				? __( 'All required documents are present and valid.', 'mcp-ai-wpoos-pro' )
				: sprintf(
					/* translators: 1: number of missing documents, 2: number of expired documents */
					__( 'Missing %1$d required document(s). %2$d document(s) expired.', 'mcp-ai-wpoos-pro' ),
					count( $missing_documents ),
					count( $expired_docs )
				),
		);
	}

	/**
	 * Get required documents for a country.
	 *
	 * @param string $country Country code.
	 * @return array Array of required document types.
	 */
	private function get_required_documents( $country ) {
		// Default required documents for most countries.
		$default_required = array(
			'loa',       // Letter of Authorization.
			'fsc',       // Free Sale Certificate.
			'coa',       // Certificate of Analysis.
			'gmp',       // GMP Certificate.
			'msds',      // Material Safety Data Sheet.
			'pif',       // Product Information File.
			'artwork',   // Label Artwork.
			'formula',   // Product Formula.
		);

		// Country-specific requirements.
		$country_requirements = array(
			'LK' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula', 'iso' ), // Sri Lanka NMRA.
			'AE' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula' ), // UAE.
			'SA' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'cpsr', 'artwork', 'formula' ), // Saudi Arabia.
			'QA' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula' ), // Qatar.
			'KW' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula' ), // Kuwait.
			'OM' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula' ), // Oman.
			'IN' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula', 'stability' ), // India.
		);

		return $country_requirements[ strtoupper( $country ) ] ?? $default_required;
	}
}
