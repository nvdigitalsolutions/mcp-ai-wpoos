<?php
/**
 * WP_MCP_AI_Tool_Create_Reg_Product (ecosystem port - Wave F2, regulatory-registration tool batch).
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
 * Creates a new regulatory product.
 */
class WP_MCP_AI_Tool_Create_Reg_Product implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Context_Restrictions_Interface {

	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_reg_product';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Regulatory Product', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new product in the regulatory registration system. Products can include perfumes, skincare, haircare, makeup, and other cosmetic items with detailed information like INCI ingredients, HS codes, barcodes, and origin country.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'product_name'       => array(
					'type'        => 'string',
					'description' => __( 'Product name (required)', 'nvoos-content-graph-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'brand'              => array(
					'type'        => 'string',
					'description' => __( 'Brand name (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'supplier_reference' => array(
					'type'        => 'string',
					'description' => __( 'Supplier reference code (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'item_group'         => array(
					'type'        => 'string',
					'description' => __( 'Item group or family (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'category'           => array(
					'type'        => 'string',
					'description' => __( 'Product category: skincare, haircare, makeup, perfumes, or cosmetics (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'skincare', 'haircare', 'makeup', 'perfumes', 'cosmetics' ),
				),
				'description'        => array(
					'type'        => 'string',
					'description' => __( 'Detailed product description (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 5000,
				),
				'origin_country'     => array(
					'type'        => 'string',
					'description' => __( 'Country of origin (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'manufacturer'       => array(
					'type'        => 'string',
					'description' => __( 'Manufacturer name (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'inci_ingredients'   => array(
					'type'        => 'string',
					'description' => __( 'INCI (International Nomenclature of Cosmetic Ingredients) list (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 5000,
				),
				'allergens'          => array(
					'type'        => 'string',
					'description' => __( 'Known allergens or restricted substances (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 1000,
				),
				'hs_code'            => array(
					'type'        => 'string',
					'description' => __( 'HS Code / Customs code (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 50,
				),
				'barcode'            => array(
					'type'        => 'string',
					'description' => __( 'Barcode / GTIN (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 50,
				),
				'pack_size'          => array(
					'type'        => 'string',
					'description' => __( 'Pack size / units (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'variant'            => array(
					'type'        => 'string',
					'description' => __( 'Product variant (size, concentration, etc.) (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
			),
			'required'             => array( 'product_name' ),
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
			'state-changing',       // Modifies database state.
			'reversible',           // Can be undone by deleting the product.
			'idempotent',           // Can be called multiple times safely (creates new products each time).
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Regulatory Registration is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create products.', 'nvoos-content-graph-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['product_name'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Product name is required.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize inputs.
		$product_name     = sanitize_text_field( $arguments['product_name'] );
		$brand            = ! empty( $arguments['brand'] ) ? sanitize_text_field( $arguments['brand'] ) : '';
		$supplier_ref     = ! empty( $arguments['supplier_reference'] ) ? sanitize_text_field( $arguments['supplier_reference'] ) : '';
		$item_group       = ! empty( $arguments['item_group'] ) ? sanitize_text_field( $arguments['item_group'] ) : '';
		$category         = ! empty( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : '';
		$description      = ! empty( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$origin_country   = ! empty( $arguments['origin_country'] ) ? sanitize_text_field( $arguments['origin_country'] ) : '';
		$manufacturer     = ! empty( $arguments['manufacturer'] ) ? sanitize_text_field( $arguments['manufacturer'] ) : '';
		$inci_ingredients = ! empty( $arguments['inci_ingredients'] ) ? sanitize_textarea_field( $arguments['inci_ingredients'] ) : '';
		$allergens        = ! empty( $arguments['allergens'] ) ? sanitize_textarea_field( $arguments['allergens'] ) : '';
		$hs_code          = ! empty( $arguments['hs_code'] ) ? sanitize_text_field( $arguments['hs_code'] ) : '';
		$barcode          = ! empty( $arguments['barcode'] ) ? sanitize_text_field( $arguments['barcode'] ) : '';
		$pack_size        = ! empty( $arguments['pack_size'] ) ? sanitize_text_field( $arguments['pack_size'] ) : '';
		$variant          = ! empty( $arguments['variant'] ) ? sanitize_text_field( $arguments['variant'] ) : '';

		// Create the post.
		$post_data = array(
			'post_title'   => $product_name,
			'post_content' => $description,
			'post_type'    => 'mcp_ai_reg_product',
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$product_id = wp_insert_post( $post_data );

		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		// Save meta fields.
		if ( $brand ) {
			update_post_meta( $product_id, 'brand', $brand );
		}
		if ( $supplier_ref ) {
			update_post_meta( $product_id, 'supplier_reference', $supplier_ref );
		}
		if ( $item_group ) {
			update_post_meta( $product_id, 'item_group', $item_group );
		}
		if ( $origin_country ) {
			update_post_meta( $product_id, 'origin_country', $origin_country );
		}
		if ( $manufacturer ) {
			update_post_meta( $product_id, 'manufacturer', $manufacturer );
		}
		if ( $inci_ingredients ) {
			update_post_meta( $product_id, 'inci_ingredients', $inci_ingredients );
		}
		if ( $allergens ) {
			update_post_meta( $product_id, 'allergens', $allergens );
		}
		if ( $hs_code ) {
			update_post_meta( $product_id, 'hs_code', $hs_code );
		}
		if ( $barcode ) {
			update_post_meta( $product_id, 'barcode', $barcode );
		}
		if ( $pack_size ) {
			update_post_meta( $product_id, 'pack_size', $pack_size );
		}
		if ( $variant ) {
			update_post_meta( $product_id, 'variant', $variant );
		}

		// Set category taxonomy if provided.
		if ( $category ) {
			$term = get_term_by( 'slug', strtolower( $category ), 'mcp_ai_reg_category' );
			if ( ! $term ) {
				// Create the term if it doesn't exist.
				$term_result = wp_insert_term( ucfirst( $category ), 'mcp_ai_reg_category' );
				if ( ! is_wp_error( $term_result ) ) {
					$term = get_term( $term_result['term_id'], 'mcp_ai_reg_category' );
				}
			}
			if ( $term && ! is_wp_error( $term ) ) {
				wp_set_object_terms( $product_id, array( $term->term_id ), 'mcp_ai_reg_category' );
			}
		}

		// Set brand taxonomy if provided.
		if ( $brand ) {
			$brand_term = get_term_by( 'name', $brand, 'mcp_ai_reg_brand' );
			if ( ! $brand_term ) {
				$brand_result = wp_insert_term( $brand, 'mcp_ai_reg_brand' );
				if ( ! is_wp_error( $brand_result ) ) {
					$brand_term = get_term( $brand_result['term_id'], 'mcp_ai_reg_brand' );
				}
			}
			if ( $brand_term && ! is_wp_error( $brand_term ) ) {
				wp_set_object_terms( $product_id, array( $brand_term->term_id ), 'mcp_ai_reg_brand' );
			}
		}

		// Log activity.
		if ( function_exists( 'wp_mcp_ai_log_activity' ) ) {
			wp_mcp_ai_log_activity(
				'create_reg_product',
				sprintf( 'Created product: %s (ID: %d)', $product_name, $product_id ),
				array(
					'product_id' => $product_id,
					'user_id'    => $current_user_id,
				)
			);
		}

		return array(
			'success'    => true,
			'product_id' => $product_id,
			// translators: %s is the product name.
			'message'    => sprintf( __( 'Product "%s" created successfully.', 'nvoos-content-graph-pro' ), $product_name ),
			'edit_url'   => get_edit_post_link( $product_id, 'raw' ),
		);
	}
}
