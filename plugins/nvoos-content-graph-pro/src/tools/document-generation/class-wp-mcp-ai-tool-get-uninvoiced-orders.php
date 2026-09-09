<?php
/**
 * WP_MCP_AI_Tool_Get_Uninvoiced_Orders (ecosystem port - Wave F2, document-generation tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/document-generation/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger/trait requires gain exists-check seams resolving from the addon's D8-compat `src/` copies; the monolith-gated openai/gemini/ollama client requires stay monolith-gated (architectural-design precedent).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
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
 * Retrieves WooCommerce orders that have not yet been invoiced.
 *
 * Queries WooCommerce orders that are missing the '_invoiced' meta flag,
 * optionally filtered by date range, customer, or minimum amount.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Tool_Get_Uninvoiced_Orders implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_uninvoiced_orders';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Uninvoiced Orders', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves WooCommerce orders that have not yet been invoiced, optionally filtered by date range, customer, or minimum order amount. Requires WooCommerce to be active.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'date_from'   => array(
					'type'        => 'string',
					'description' => __( 'Filter orders from this date (YYYY-MM-DD).', 'nvoos-content-graph-pro' ),
				),
				'date_to'     => array(
					'type'        => 'string',
					'description' => __( 'Filter orders up to this date (YYYY-MM-DD).', 'nvoos-content-graph-pro' ),
				),
				'customer_id' => array(
					'type'        => 'integer',
					'description' => __( 'Filter by customer ID.', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'min_amount'  => array(
					'type'        => 'number',
					'description' => __( 'Minimum order total amount.', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of orders to return. Default: 100.', 'nvoos-content-graph-pro' ),
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 1000,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'document_generation',
			'post_type'             => 'shop_order',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'accountant', 'shop_manager' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
			'cacheable',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Document Generation Toolkit to be enabled AND WooCommerce active.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
			return false;
		}
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.9.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
			return __( 'The Get Uninvoiced Orders tool requires the Document Generation Toolkit to be enabled in plugin settings.', 'nvoos-content-graph-pro' );
		}
		return __( 'The Get Uninvoiced Orders tool requires WooCommerce to be installed and active.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Uninvoiced orders result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Document Generation Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'nvoos-content-graph-pro' ),
			);
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'WooCommerce is not installed or active. This tool requires WooCommerce to query orders.', 'nvoos-content-graph-pro' ),
			);
		}

		$date_from   = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '';
		$date_to     = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : '';
		$customer_id = isset( $arguments['customer_id'] ) ? absint( $arguments['customer_id'] ) : 0;
		$min_amount  = isset( $arguments['min_amount'] ) ? floatval( $arguments['min_amount'] ) : 0;
		$limit       = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 100;
		$limit       = min( max( $limit, 1 ), 1000 );

		$query_args = array(
			'limit'        => $limit,
			'status'       => array_keys( wc_get_order_statuses() ),
			'orderby'      => 'date',
			'order'        => 'DESC',
			'return'       => 'ids',
			'meta_key'     => '_invoiced',
			'meta_compare' => 'NOT EXISTS',
		);

		// Date range filter.
		if ( ! empty( $date_from ) || ! empty( $date_to ) ) {
			$date_query = array();
			if ( ! empty( $date_from ) ) {
				$date_query['after'] = $date_from;
			}
			if ( ! empty( $date_to ) ) {
				$date_query['before'] = $date_to;
			}
			$date_query['inclusive']    = true;
			$query_args['date_created'] = $date_query['after'] . '...' . $date_query['before'];
		}

		// Customer filter.
		if ( $customer_id > 0 ) {
			$query_args['customer_id'] = $customer_id;
		}

		$orders_ids = wc_get_orders( $query_args );
		$orders     = array();

		foreach ( $orders_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$total = floatval( $order->get_total() );

			// Filter by minimum amount.
			if ( $min_amount > 0 && $total < $min_amount ) {
				continue;
			}

			$orders[] = array(
				'id'            => $order_id,
				'order_number'  => $order->get_order_number(),
				'status'        => $order->get_status(),
				'total'         => $total,
				'currency'      => $order->get_currency(),
				'date_created'  => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
				'customer_id'   => $order->get_customer_id(),
				'billing_name'  => $order->get_formatted_billing_full_name(),
				'billing_email' => $order->get_billing_email(),
				'item_count'    => count( $order->get_items() ),
				'edit_url'      => get_edit_post_link( $order_id, 'raw' ),
			);
		}

		return array(
			'success'     => true,
			'message'     => sprintf(
				/* translators: %d: number of uninvoiced orders found */
				__( 'Found %d orders without invoices.', 'nvoos-content-graph-pro' ),
				count( $orders )
			),
			'total_count' => count( $orders ),
			'orders'      => $orders,
		);
	}
}
