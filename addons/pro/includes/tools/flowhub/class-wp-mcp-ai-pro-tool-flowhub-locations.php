<?php
/**
 * FlowHub Locations Tool.
 *
 * Enables AI assistants to list FlowHub dispensary locations from the
 * local CCT cache. Locations are extracted from inventory data since
 * FlowHub v0 API does not have a dedicated locations endpoint.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/trait-wp-mcp-ai-flowhub-connection-resolver.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cct-manager.php';

/**
 * FlowHub Locations Tool.
 *
 * List FlowHub locations with item counts from the CCT cache.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Pro_Tool_FlowHub_Locations implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_FlowHub_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flowhub_locations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'FlowHub Locations', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List FlowHub dispensary locations and view location details from the local cache. Each location shows the number of inventory items available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list', 'get_location' ),
					'default'     => 'list',
				),
				'location_id'   => array(
					'type'        => 'string',
					'description' => __( 'FlowHub location ID for get_location action.', 'mcp-ai-wpoos-pro' ),
				),
				'location_name' => array(
					'type'        => 'string',
					'description' => __( 'Location name (partial match) for get_location action.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'external-api', 'requires-credentials', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_woocommerce';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1: Sanitize.
		$action        = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';
		$location_id   = isset( $arguments['location_id'] ) ? sanitize_text_field( $arguments['location_id'] ) : '';
		$location_name = isset( $arguments['location_name'] ) ? sanitize_text_field( $arguments['location_name'] ) : '';

		// Capability.
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_flowhub_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		// Dependencies.
		$deps = $this->check_flowhub_dependencies();
		if ( is_wp_error( $deps ) ) {
			return $deps;
		}

		$cct_manager = $this->get_flowhub_cct_manager();

		switch ( $action ) {
			case 'list':
				$locations = $this->build_location_list( $cct_manager );
				return array(
					'success' => true,
					'message' => sprintf(
						__( 'Found %d locations.', 'mcp-ai-wpoos-pro' ),
						count( $locations )
					),
					'data'    => $locations,
				);

			case 'get_location':
				if ( empty( $location_id ) && empty( $location_name ) ) {
					return new WP_Error(
						'wp_mcp_ai_flowhub_missing_location',
						__( 'Provide location_id or location_name.', 'mcp-ai-wpoos-pro' )
					);
				}

				$detail = $this->build_location_detail( $cct_manager, $location_id, $location_name );

				if ( ! $detail ) {
					return new WP_Error(
						'wp_mcp_ai_flowhub_location_not_found',
						__( 'Location not found.', 'mcp-ai-wpoos-pro' )
					);
				}

				return array(
					'success' => true,
					'message' => __( 'Location found.', 'mcp-ai-wpoos-pro' ),
					'data'    => $detail,
				);

			default:
				return new WP_Error( 'wp_mcp_ai_flowhub_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Build a list of all locations with item counts.
	 *
	 * @since 1.2.0
	 * @param WP_MCP_AI_FlowHub_CCT_Manager $cct_manager CCT manager.
	 * @return array
	 */
	protected function build_location_list( $cct_manager ) {
		// Get all items to group by location.
		$items     = $cct_manager->get_cached_items( array( 'per_page' => 100 ) );
		$locations = array();
		$seen      = array();

		foreach ( $items as $item ) {
			$lid = isset( $item['location_id'] ) ? $item['location_id'] : '';
			if ( empty( $lid ) ) {
				continue;
			}

			if ( ! isset( $seen[ $lid ] ) ) {
				$seen[ $lid ] = array(
					'location_id'   => esc_html( $lid ),
					'location_name' => esc_html( isset( $item['location_name'] ) ? $item['location_name'] : '' ),
					'item_count'    => 0,
				);
			}

			++$seen[ $lid ]['item_count'];
		}

		return array_values( $seen );
	}

	/**
	 * Build detail for a single location.
	 *
	 * @since 1.2.0
	 * @param WP_MCP_AI_FlowHub_CCT_Manager $cct_manager   CCT manager.
	 * @param string                        $location_id   Location ID.
	 * @param string                        $location_name Location name.
	 * @return array|null
	 */
	protected function build_location_detail( $cct_manager, $location_id, $location_name ) {
		$filters = array( 'per_page' => 100 );

		if ( ! empty( $location_id ) ) {
			$filters['location_id'] = $location_id;
		} elseif ( ! empty( $location_name ) ) {
			$filters['location'] = $location_name;
		}

		$items = $cct_manager->get_cached_items( $filters );

		if ( empty( $items ) ) {
			return null;
		}

		$first = $items[0];

		// Count by stock status.
		$in_stock  = 0;
		$low_stock = 0;
		$out_stock = 0;
		$settings  = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
		$threshold = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;

		foreach ( $items as $item ) {
			$qty = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );
			if ( $qty >= $threshold ) {
				++$in_stock;
			} elseif ( $qty > 0 ) {
				++$low_stock;
			} else {
				++$out_stock;
			}
		}

		return array(
			'location_id'   => esc_html( isset( $first['location_id'] ) ? $first['location_id'] : '' ),
			'location_name' => esc_html( isset( $first['location_name'] ) ? $first['location_name'] : '' ),
			'total_items'   => count( $items ),
			'in_stock'      => $in_stock,
			'low_stock'     => $low_stock,
			'out_of_stock'  => $out_stock,
		);
	}
}
