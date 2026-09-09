<?php
/**
 * WP_MCP_AI_Tool_Duplicate_Reg_Product (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
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
		return __( 'Duplicate Regulatory Product', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a copy of an existing product with optional data selection. Useful for product variants or similar products. Can optionally copy registrations and documents.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id'         => array(
					'type'        => 'integer',
					'description' => __( 'Product ID to duplicate (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'new_title'          => array(
					'type'        => 'string',
					'description' => __( 'Title for the duplicated product (optional, defaults to "[Copy] Original Title")', 'nvoos-content-graph-pro' ),
				),
				'copy_meta'          => array(
					'type'        => 'boolean',
					'description' => __( 'Copy metadata fields (optional, default: true)', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'copy_taxonomies'    => array(
					'type'        => 'boolean',
					'description' => __( 'Copy taxonomy terms (optional, default: true)', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'copy_registrations' => array(
					'type'        => 'boolean',
					'description' => __( 'Copy associated registrations (optional, default: false)', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'copy_documents'     => array(
					'type'        => 'boolean',
					'description' => __( 'Copy associated documents (optional, default: false)', 'nvoos-content-graph-pro' ),
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
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['product_id'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Product ID is required.', 'nvoos-content-graph-pro' )
			);
		}

		$product_id = absint( $arguments['product_id'] );

		// Verify source product exists.
		$source_product = get_post( $product_id );
		if ( ! $source_product || 'mcp_ai_reg_product' !== $source_product->post_type ) {
			return new WP_Error(
				'tool_error',
				__( 'Source product not found.', 'nvoos-content-graph-pro' )
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
			return new WP_Error(
				'tool_error',
				$new_product_id->get_error_message()
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
			$registration_count            = $this->copy_registrations( $product_id, $new_product_id );
			$copied_items['registrations'] = $registration_count;
		}

		// Copy documents if requested.
		$document_count = 0;
		if ( ! empty( $arguments['copy_documents'] ) ) {
			$document_count            = $this->copy_documents( $product_id, $new_product_id );
			$copied_items['documents'] = $document_count;
		}

		return array(
			'success'           => true,
			'new_product_id'    => $new_product_id,
			'new_title'         => $new_title,
			'source_product_id' => $product_id,
			'copied_items'      => $copied_items,
			'message'           => sprintf(
				/* translators: %s: new product title */
				__( 'Product duplicated successfully: %s', 'nvoos-content-graph-pro' ),
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
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'duplicate_reg_product', 0, 1000 ) : 1000,
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
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'duplicate_reg_product', 0, 1000 ) : 1000,
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
