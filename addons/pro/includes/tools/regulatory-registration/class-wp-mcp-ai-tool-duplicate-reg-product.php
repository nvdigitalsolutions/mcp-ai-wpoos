<?php
/**
 * Tool for duplicating products in the Regulatory Registration system.
 *
 * Allows AI assistants to clone products with optional data selection.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Duplicates a regulatory product.
 */
class WP_MCP_AI_Tool_Duplicate_Reg_Product implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'duplicate_reg_product';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Duplicate Regulatory Product', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a copy of an existing product with optional data selection. Useful for product variants or similar products. Can optionally copy registrations and documents.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Product ID to duplicate (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'new_title'        => array(
					'type'        => 'string',
					'description' => __( 'Title for the duplicated product (optional, defaults to "[Copy] Original Title")', 'mcp-ai-wpoos-pro' ),
				),
				'copy_meta'        => array(
					'type'        => 'boolean',
					'description' => __( 'Copy metadata fields (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'copy_taxonomies'  => array(
					'type'        => 'boolean',
					'description' => __( 'Copy taxonomy terms (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'copy_registrations' => array(
					'type'        => 'boolean',
					'description' => __( 'Copy associated registrations (optional, default: false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'copy_documents'   => array(
					'type'        => 'boolean',
					'description' => __( 'Copy associated documents (optional, default: false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
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
			'database-write',       // Writes to database.
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

		// Verify source product exists.
		$source_product = get_post( $product_id );
		if ( ! $source_product || 'mcp_ai_reg_product' !== $source_product->post_type ) {
			return array(
				'success' => false,
				'error'   => __( 'Source product not found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Determine new title.
		$new_title = ! empty( $arguments['new_title'] )
			? sanitize_text_field( $arguments['new_title'] )
			: sprintf( '[Copy] %s', $source_product->post_title );

		// Create duplicate product.
		$new_product_data = array(
			'post_title'   => $new_title,
			'post_type'    => 'mcp_ai_reg_product',
			'post_status'  => 'draft', // Start as draft for review.
			'post_content' => $source_product->post_content,
			'post_excerpt' => $source_product->post_excerpt,
		);

		$new_product_id = wp_insert_post( $new_product_data );

		if ( is_wp_error( $new_product_id ) ) {
			return array(
				'success' => false,
				'error'   => $new_product_id->get_error_message(),
			);
		}

		// Copy metadata if requested.
		$copied_items = array( 'product' => true );
		if ( ! empty( $arguments['copy_meta'] ) ) {
			$this->copy_post_meta( $product_id, $new_product_id );
			$copied_items['metadata'] = true;
		}

		// Copy taxonomies if requested.
		if ( ! empty( $arguments['copy_taxonomies'] ) ) {
			$this->copy_taxonomies( $product_id, $new_product_id );
			$copied_items['taxonomies'] = true;
		}

		// Copy registrations if requested.
		$registration_count = 0;
		if ( ! empty( $arguments['copy_registrations'] ) ) {
			$registration_count = $this->copy_registrations( $product_id, $new_product_id );
			$copied_items['registrations'] = $registration_count;
		}

		// Copy documents if requested.
		$document_count = 0;
		if ( ! empty( $arguments['copy_documents'] ) ) {
			$document_count = $this->copy_documents( $product_id, $new_product_id );
			$copied_items['documents'] = $document_count;
		}

		return array(
			'success'        => true,
			'new_product_id' => $new_product_id,
			'new_title'      => $new_title,
			'source_product_id' => $product_id,
			'copied_items'   => $copied_items,
			'message'        => sprintf(
				/* translators: %s: new product title */
				__( 'Product duplicated successfully: %s', 'mcp-ai-wpoos-pro' ),
				$new_title
			),
		);
	}

	/**
	 * Copy post meta from source to destination.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $dest_id Destination post ID.
	 */
	private function copy_post_meta( $source_id, $dest_id ) {
		$meta_fields = array(
			'brand',
			'supplier_reference',
			'item_group',
			'origin_country',
			'manufacturer',
			'inci_ingredients',
			'allergens',
			'hs_code',
			'barcode',
			'pack_size',
			'variant',
		);

		foreach ( $meta_fields as $field ) {
			$value = get_post_meta( $source_id, $field, true );
			if ( ! empty( $value ) ) {
				update_post_meta( $dest_id, $field, $value );
			}
		}
	}

	/**
	 * Copy taxonomies from source to destination.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $dest_id Destination post ID.
	 */
	private function copy_taxonomies( $source_id, $dest_id ) {
		$taxonomies = array( 'mcp_ai_reg_category', 'mcp_ai_reg_brand' );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $dest_id, $terms, $taxonomy );
			}
		}
	}

	/**
	 * Copy registrations from source to destination product.
	 *
	 * @param int $source_id Source product ID.
	 * @param int $dest_id Destination product ID.
	 * @return int Number of registrations copied.
	 */
	private function copy_registrations( $source_id, $dest_id ) {
		$registrations = get_posts(
			array(
				'post_type'      => 'mcp_ai_registration',
				'posts_per_page' => -1,
				'meta_key'       => 'product_id',
				'meta_value'     => $source_id,
			)
		);

		$count = 0;
		foreach ( $registrations as $registration ) {
			// Create new registration.
			$new_registration_data = array(
				'post_title'   => $registration->post_title,
				'post_type'    => 'mcp_ai_registration',
				'post_status'  => 'draft', // Start as draft.
				'post_content' => $registration->post_content,
			);

			$new_registration_id = wp_insert_post( $new_registration_data );

			if ( ! is_wp_error( $new_registration_id ) ) {
				// Copy registration meta.
				update_post_meta( $new_registration_id, 'product_id', $dest_id );
				update_post_meta( $new_registration_id, 'country', get_post_meta( $registration->ID, 'country', true ) );
				update_post_meta( $new_registration_id, 'authority', get_post_meta( $registration->ID, 'authority', true ) );
				update_post_meta( $new_registration_id, 'registration_type', get_post_meta( $registration->ID, 'registration_type', true ) );

				// Don't copy COS number, dates (registration-specific).
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Copy documents from source to destination product.
	 *
	 * @param int $source_id Source product ID.
	 * @param int $dest_id Destination product ID.
	 * @return int Number of documents copied.
	 */
	private function copy_documents( $source_id, $dest_id ) {
		$documents = get_posts(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'posts_per_page' => -1,
				'meta_key'       => 'product_id',
				'meta_value'     => $source_id,
			)
		);

		$count = 0;
		foreach ( $documents as $document ) {
			// Create new document.
			$new_document_data = array(
				'post_title'   => $document->post_title,
				'post_type'    => 'mcp_ai_reg_document',
				'post_status'  => 'publish',
				'post_content' => $document->post_content,
			);

			$new_document_id = wp_insert_post( $new_document_data );

			if ( ! is_wp_error( $new_document_id ) ) {
				// Copy document meta.
				update_post_meta( $new_document_id, 'product_id', $dest_id );
				update_post_meta( $new_document_id, 'document_type', get_post_meta( $document->ID, 'document_type', true ) );
				update_post_meta( $new_document_id, 'file_url', get_post_meta( $document->ID, 'file_url', true ) );
				update_post_meta( $new_document_id, 'version', get_post_meta( $document->ID, 'version', true ) );
				update_post_meta( $new_document_id, 'issue_date', get_post_meta( $document->ID, 'issue_date', true ) );
				update_post_meta( $new_document_id, 'expiry_date', get_post_meta( $document->ID, 'expiry_date', true ) );

				++$count;
			}
		}

		return $count;
	}
}
