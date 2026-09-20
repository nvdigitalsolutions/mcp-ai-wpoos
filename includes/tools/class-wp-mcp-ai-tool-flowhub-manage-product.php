<?php
/**
 * Tool that manages product data in Flowhub API (create/update).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for creating and updating products in Flowhub.
 */
class WP_MCP_AI_Tool_Flowhub_Manage_Product implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flowhub_manage_product';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Flowhub Manage Product', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create or update cannabis products in Flowhub dispensary system. Supports managing product details, pricing, THC/CBD content, categories, and compliance information.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Creating or updating Flowhub products with pricing, THC/CBD content, and category.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Reading the catalog or stock; use flowhub_get_products or flowhub_get_inventory.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'flowhub_get_products', 'flowhub_get_inventory' ),
			'notes'           => __( 'Action is create or update; product_id is required for update. strain_type accepts indica, sativa, hybrid, cbd.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites connection ID for Flowhub (conn_...). Omit to auto-resolve: toolkit settings credentials, then the configured sync connections, then the first enabled FlowHub connection.', 'mcp-ai-wpoos' ),
				),
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: "create" or "update".', 'mcp-ai-wpoos' ),
					'enum'        => array( 'create', 'update' ),
				),
				'product_id'    => array(
					'type'        => 'string',
					'description' => __( 'Product ID (required for update action).', 'mcp-ai-wpoos' ),
				),
				'name'          => array(
					'type'        => 'string',
					'description' => __( 'Product name.', 'mcp-ai-wpoos' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'Product description.', 'mcp-ai-wpoos' ),
				),
				'category'      => array(
					'type'        => 'string',
					'description' => __( 'Product category (e.g., "flower", "concentrate", "edible").', 'mcp-ai-wpoos' ),
				),
				'price'         => array(
					'type'        => 'number',
					'description' => __( 'Product price.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
				),
				'thc_percent'   => array(
					'type'        => 'number',
					'description' => __( 'THC percentage content.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'cbd_percent'   => array(
					'type'        => 'number',
					'description' => __( 'CBD percentage content.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'strain_type'   => array(
					'type'        => 'string',
					'description' => __( 'Strain type (e.g., "indica", "sativa", "hybrid").', 'mcp-ai-wpoos' ),
					'enum'        => array( 'indica', 'sativa', 'hybrid', 'cbd' ),
				),
				'brand'         => array(
					'type'        => 'string',
					'description' => __( 'Product brand name.', 'mcp-ai-wpoos' ),
				),
				'sku'           => array(
					'type'        => 'string',
					'description' => __( 'Product SKU/barcode.', 'mcp-ai-wpoos' ),
				),
				'timeout'       => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-60).', 'mcp-ai-wpoos' ),
					'minimum'     => 5,
					'maximum'     => 60,
					'default'     => 30,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check whether the tool can be registered.
	 *
	 * @since 1.1.22
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_Flowhub_Client' );
	}

	/**
	 * Provide a message explaining why the tool is unavailable.
	 *
	 * @since 1.1.22
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Flowhub client is not available. The Flowhub integration requires API credentials to be configured.', 'mcp-ai-wpoos' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to manage Flowhub products.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			// Require manage_woocommerce or manage_options capability.
			if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage product data.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		if ( ! in_array( $action, array( 'create', 'update' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_action',
				__( 'Action must be either "create" or "update".', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( 'update' === $action && empty( $arguments['product_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_product_id',
				__( 'A product_id is required for update action.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Resolve the target FlowHub connection: explicit argument, settings
		// credentials, configured sync connections, or the first enabled
		// FlowHub connection in Remote Sites.
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Connection_Helper' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-connection-helper.php';
		}
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;
		$resolved      = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection( $connection_id );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$connection_id = $resolved['connection_id'];
		$client_id     = $resolved['credentials']['client_id'];
		$api_key       = $resolved['credentials']['api_key'];
		$location_id   = $resolved['credentials']['location_id'];

		// Use the base client class, passing connection_id for lazy credential resolution.
		if ( ! class_exists( 'WP_MCP_AI_Flowhub_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}
		$client       = new WP_MCP_AI_Flowhub_Client( $connection_id );
		$product_data = array();

		if ( isset( $arguments['name'] ) ) {
			$product_data['name'] = sanitize_text_field( $arguments['name'] );
		}
		if ( isset( $arguments['description'] ) ) {
			$product_data['description'] = sanitize_textarea_field( $arguments['description'] );
		}
		if ( isset( $arguments['category'] ) ) {
			$product_data['category'] = sanitize_text_field( $arguments['category'] );
		}
		if ( isset( $arguments['price'] ) ) {
			$product_data['price'] = floatval( $arguments['price'] );
		}
		if ( isset( $arguments['thc_percent'] ) ) {
			$product_data['thc_percent'] = floatval( $arguments['thc_percent'] );
		}
		if ( isset( $arguments['cbd_percent'] ) ) {
			$product_data['cbd_percent'] = floatval( $arguments['cbd_percent'] );
		}
		if ( isset( $arguments['strain_type'] ) ) {
			$product_data['strain_type'] = sanitize_text_field( $arguments['strain_type'] );
		}
		if ( isset( $arguments['brand'] ) ) {
			$product_data['brand'] = sanitize_text_field( $arguments['brand'] );
		}
		if ( isset( $arguments['sku'] ) ) {
			$product_data['sku'] = sanitize_text_field( $arguments['sku'] );
		}

		$options = array();
		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 60, absint( $arguments['timeout'] ) ) );
		}

		if ( 'create' === $action ) {
			$result = $client->create_product( $product_data, $options );
		} else {
			$product_id = sanitize_text_field( $arguments['product_id'] );
			$result     = $client->update_product( $product_id, $product_data, $options );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add summary for frontend display.
		$product_id = isset( $result['id'] ) ? $result['id'] : '';
		$summary    = sprintf(
			/* translators: 1: action, 2: product ID */
			__( 'Successfully %1$s product: %2$s', 'mcp-ai-wpoos' ),
			'create' === $action ? __( 'created', 'mcp-ai-wpoos' ) : __( 'updated', 'mcp-ai-wpoos' ),
			$product_id
		);

		$result = array_merge(
			array(
				'message' => $summary, // Chat client.
				'summary' => $summary, // Backward compatibility.
			),
			$result
		);

		/**
		 * Allow third parties to filter the Flowhub manage product result.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$result = apply_filters( 'wp_mcp_ai_flowhub_manage_product_result', $result, $arguments, $context );

		return $result;
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ecommerce_business',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'product_manager', 'inventory_specialist' ),

			'risk_level'            => 'standard',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'external-api',         // Makes external API calls.
			'requires-credentials', // Requires Flowhub API credentials.
			'requires-capability',  // Requires user capabilities.
			'write',                // Creates or modifies data.
			'state-changing',       // Modifies external system state.
			'rate-limited',         // Subject to Flowhub API rate limits.
		);
	}
}
