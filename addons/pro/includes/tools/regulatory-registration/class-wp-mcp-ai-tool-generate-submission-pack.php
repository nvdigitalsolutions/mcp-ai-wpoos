<?php
/**
 * Tool for generating submission pack in the Regulatory Registration system.
 *
 * Allows AI assistants to create a complete submission package with all required documents.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a submission pack for a registration.
 */
class WP_MCP_AI_Tool_Generate_Submission_Pack implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_submission_pack';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Submission Pack', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates a complete submission package for a registration, bundling all required documents and creating a submission record.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID to generate pack for (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'submission_type'  => array(
					'type'        => 'string',
					'description' => __( 'Type of submission: new, renewal, variation (optional, default: new)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'new', 'renewal', 'variation' ),
					'default'     => 'new',
				),
				'include_cover_letter' => array(
					'type'        => 'boolean',
					'description' => __( 'Generate cover letter (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_index'    => array(
					'type'        => 'boolean',
					'description' => __( 'Generate document index (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'registration_id' ),
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
			'database-write',       // Creates submission record.
			'file-generation',      // Generates files.
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
		if ( empty( $arguments['registration_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Registration ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$registration_id = absint( $arguments['registration_id'] );

		// Verify registration exists.
		$registration = get_post( $registration_id );
		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return array(
				'success' => false,
				'error'   => __( 'Registration not found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get registration details.
		$product_id = get_post_meta( $registration_id, 'product_id', true );
		$country = get_post_meta( $registration_id, 'country', true );
		$authority = get_post_meta( $registration_id, 'authority', true );

		// Get product details.
		$product = get_post( $product_id );
		if ( ! $product ) {
			return array(
				'success' => false,
				'error'   => __( 'Associated product not found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get all documents for this registration.
		$documents = get_posts(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => 'registration_id',
						'value' => $registration_id,
					),
				),
			)
		);

		// Check for missing required documents.
		$required_docs = $this->get_required_documents( $country );
		$existing_types = array();
		foreach ( $documents as $doc ) {
			$doc_type = get_post_meta( $doc->ID, 'document_type', true );
			if ( $doc_type ) {
				$existing_types[] = $doc_type;
			}
		}

		$missing_docs = array_diff( $required_docs, $existing_types );
		if ( ! empty( $missing_docs ) ) {
			return array(
				'success'           => false,
				'error'             => __( 'Cannot generate submission pack. Missing required documents.', 'mcp-ai-wpoos-pro' ),
				'missing_documents' => array_values( $missing_docs ),
			);
		}

		// Create submission pack metadata.
		$submission_type = ! empty( $arguments['submission_type'] ) ? sanitize_text_field( $arguments['submission_type'] ) : 'new';
		$pack_data = array(
			'registration_id' => $registration_id,
			'product_id'      => $product_id,
			'country'         => $country,
			'authority'       => $authority,
			'submission_type' => $submission_type,
			'generated_date'  => current_time( 'mysql' ),
			'document_count'  => count( $documents ),
		);

		// Store submission pack metadata.
		update_post_meta( $registration_id, 'submission_pack', $pack_data );
		update_post_meta( $registration_id, 'submission_pack_date', current_time( 'mysql' ) );

		// Build document list.
		$document_list = array();
		foreach ( $documents as $doc ) {
			$document_list[] = array(
				'document_id'   => $doc->ID,
				'title'         => $doc->post_title,
				'document_type' => get_post_meta( $doc->ID, 'document_type', true ),
				'file_url'      => get_post_meta( $doc->ID, 'file_url', true ),
				'version'       => get_post_meta( $doc->ID, 'version', true ),
			);
		}

		// Generate cover letter if requested.
		$cover_letter = null;
		if ( ! empty( $arguments['include_cover_letter'] ) ) {
			$cover_letter = $this->generate_cover_letter( $registration, $product, $country, $authority, $submission_type );
		}

		// Generate document index if requested.
		$document_index = null;
		if ( ! empty( $arguments['include_index'] ) ) {
			$document_index = $this->generate_document_index( $document_list, $product, $country );
		}

		return array(
			'success'         => true,
			'registration_id' => $registration_id,
			'pack_data'       => $pack_data,
			'document_count'  => count( $documents ),
			'documents'       => $document_list,
			'cover_letter'    => $cover_letter,
			'document_index'  => $document_index,
			'message'         => sprintf(
				/* translators: %d: number of documents */
				__( 'Submission pack generated successfully with %d documents.', 'mcp-ai-wpoos-pro' ),
				count( $documents )
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
		$default_required = array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula' );

		$country_requirements = array(
			'LK' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula', 'iso' ),
			'AE' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula' ),
			'SA' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'cpsr', 'artwork', 'formula' ),
			'QA' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula' ),
			'KW' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula' ),
			'OM' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula' ),
			'IN' => array( 'loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula', 'stability' ),
		);

		return $country_requirements[ strtoupper( $country ) ] ?? $default_required;
	}

	/**
	 * Generate cover letter.
	 *
	 * @param WP_Post $registration Registration post.
	 * @param WP_Post $product Product post.
	 * @param string  $country Country code.
	 * @param string  $authority Authority name.
	 * @param string  $submission_type Submission type.
	 * @return string Cover letter content.
	 */
	private function generate_cover_letter( $registration, $product, $country, $authority, $submission_type ) {
		$date = current_time( 'F j, Y' );
		$product_name = $product->post_title;
		$brand = get_post_meta( $product->ID, 'brand', true );

		$letter = "Date: {$date}\n\n";
		$letter .= "To: {$authority}\n";
		$letter .= "Country: {$country}\n\n";
		$letter .= "Re: " . ucfirst( $submission_type ) . " Registration Application for {$product_name}\n\n";
		$letter .= "Dear Sir/Madam,\n\n";
		$letter .= "We hereby submit the complete application dossier for the " . strtolower( $submission_type ) . " registration of our product:\n\n";
		$letter .= "Product Name: {$product_name}\n";
		if ( $brand ) {
			$letter .= "Brand: {$brand}\n";
		}
		$letter .= "\nThe submission package includes all required documentation as per your regulatory requirements.\n\n";
		$letter .= "We remain available for any clarifications or additional information.\n\n";
		$letter .= "Sincerely,\n";
		$letter .= "[Company Name]\n";

		return $letter;
	}

	/**
	 * Generate document index.
	 *
	 * @param array  $documents Array of document data.
	 * @param WP_Post $product Product post.
	 * @param string $country Country code.
	 * @return string Document index content.
	 */
	private function generate_document_index( $documents, $product, $country ) {
		$index = "DOCUMENT INDEX\n";
		$index .= "==============\n\n";
		$index .= "Product: {$product->post_title}\n";
		$index .= "Country: {$country}\n";
		$index .= "Generated: " . current_time( 'F j, Y' ) . "\n\n";
		$index .= "Documents Included:\n\n";

		$counter = 1;
		foreach ( $documents as $doc ) {
			$index .= "{$counter}. {$doc['title']} ({$doc['document_type']}) - Version {$doc['version']}\n";
			++$counter;
		}

		$index .= "\nTotal Documents: " . count( $documents ) . "\n";

		return $index;
	}
}
