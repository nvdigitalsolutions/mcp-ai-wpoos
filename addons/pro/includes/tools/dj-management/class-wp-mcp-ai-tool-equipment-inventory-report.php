<?php
/**
 * Tool for generating equipment inventory reports.
 *
 * Allows AI assistants to generate comprehensive inventory reports for DJ equipment.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates equipment inventory reports.
 */
class WP_MCP_AI_Tool_Equipment_Inventory_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'equipment_inventory_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Equipment Inventory Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates a comprehensive inventory report for DJ equipment. Includes equipment details, values, status, and maintenance schedules.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'status'              => array(
					'type'        => 'string',
					'description' => __( 'Filter by equipment status (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'all', 'available', 'in_use', 'maintenance', 'retired' ),
					'default'     => 'all',
				),
				'type'                => array(
					'type'        => 'string',
					'description' => __( 'Filter by equipment type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'all', 'mixer', 'turntable', 'speaker', 'controller', 'lighting', 'microphone', 'headphones', 'cable', 'other' ),
					'default'     => 'all',
				),
				'include_values'      => array(
					'type'        => 'boolean',
					'description' => __( 'Include purchase values in report (optional, defaults to true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_maintenance' => array(
					'type'        => 'boolean',
					'description' => __( 'Include maintenance information (optional, defaults to true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments, array $context = array() ) {
		// Parse arguments.
		$status              = ! empty( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : 'all';
		$type                = ! empty( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : 'all';
		$include_values      = isset( $arguments['include_values'] ) ? (bool) $arguments['include_values'] : true;
		$include_maintenance = isset( $arguments['include_maintenance'] ) ? (bool) $arguments['include_maintenance'] : true;

		// Build query args.
		$query_args = array(
			'post_type'      => 'dj_equipment',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $status !== 'all' ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => '_status',
					'value'   => $status,
					'compare' => '=',
				),
			);
		}

		if ( $type !== 'all' ) {
			if ( ! isset( $query_args['meta_query'] ) ) {
				$query_args['meta_query'] = array();
			}
			$query_args['meta_query'][] = array(
				'key'     => '_equipment_type',
				'value'   => $type,
				'compare' => '=',
			);
		}

		// Execute query.
		$equipment_query = new WP_Query( $query_args );
		$equipment_items = array();
		$total_value     = 0;
		$status_counts   = array();
		$type_counts     = array();

		if ( $equipment_query->have_posts() ) {
			while ( $equipment_query->have_posts() ) {
				$equipment_query->the_post();
				$equipment_id = get_the_ID();

				$item = array(
					'id'     => $equipment_id,
					'name'   => get_the_title(),
					'type'   => get_post_meta( $equipment_id, '_equipment_type', true ),
					'brand'  => get_post_meta( $equipment_id, '_brand', true ),
					'model'  => get_post_meta( $equipment_id, '_model', true ),
					'status' => get_post_meta( $equipment_id, '_status', true ),
				);

				if ( $include_values ) {
					$purchase_price         = floatval( get_post_meta( $equipment_id, '_purchase_price', true ) );
					$item['purchase_price'] = $purchase_price;
					$item['purchase_date']  = get_post_meta( $equipment_id, '_purchase_date', true );
					$total_value           += $purchase_price;
				}

				if ( $include_maintenance ) {
					$item['last_maintenance'] = get_post_meta( $equipment_id, '_last_maintenance_date', true );
					$item['next_maintenance'] = get_post_meta( $equipment_id, '_next_maintenance_date', true );
				}

				$equipment_items[] = $item;

				// Count by status.
				$item_status                   = $item['status'] ?: 'available';
				$status_counts[ $item_status ] = isset( $status_counts[ $item_status ] ) ? $status_counts[ $item_status ] + 1 : 1;

				// Count by type.
				$item_type                 = $item['type'] ?: 'other';
				$type_counts[ $item_type ] = isset( $type_counts[ $item_type ] ) ? $type_counts[ $item_type ] + 1 : 1;
			}
			wp_reset_postdata();
		}

		return array(
			'success'       => true,
			'total_items'   => count( $equipment_items ),
			'total_value'   => $include_values ? $total_value : null,
			'status_counts' => $status_counts,
			'type_counts'   => $type_counts,
			'equipment'     => $equipment_items,
			'report_date'   => current_time( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_flag_capabilities() {
		return array( 'read' );
	}
}
