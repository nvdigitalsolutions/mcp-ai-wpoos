<?php
/**
 * Tool for adding DJ equipment to inventory.
 *
 * Allows AI assistants to add new equipment items to the DJ inventory system.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a new equipment item to DJ inventory.
 */
class WP_MCP_AI_Tool_Add_Equipment_Item implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'add_equipment_item';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Add Equipment Item', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Adds a new DJ equipment item to the inventory system. Tracks equipment details, purchase information, and current status.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'            => array(
					'type'        => 'string',
					'description' => __( 'Equipment name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'type'            => array(
					'type'        => 'string',
					'description' => __( 'Equipment type (e.g., Mixer, Turntable, Speaker, Controller, Lighting) (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'mixer', 'turntable', 'speaker', 'controller', 'lighting', 'microphone', 'headphones', 'cable', 'other' ),
				),
				'brand'           => array(
					'type'        => 'string',
					'description' => __( 'Brand/manufacturer (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'model'           => array(
					'type'        => 'string',
					'description' => __( 'Model number (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'serial_number'   => array(
					'type'        => 'string',
					'description' => __( 'Serial number (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'purchase_date'   => array(
					'type'        => 'string',
					'description' => __( 'Purchase date in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'purchase_price'  => array(
					'type'        => 'number',
					'description' => __( 'Purchase price (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'status'          => array(
					'type'        => 'string',
					'description' => __( 'Current status (optional, defaults to "available")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'available', 'in_use', 'maintenance', 'retired' ),
					'default'     => 'available',
				),
				'location'        => array(
					'type'        => 'string',
					'description' => __( 'Storage location (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
			),
			'required'             => array( 'name', 'type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments, array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['name'] ) || empty( $arguments['type'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Equipment name and type are required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Sanitize inputs.
		$name           = sanitize_text_field( $arguments['name'] );
		$type           = sanitize_text_field( $arguments['type'] );
		$brand          = ! empty( $arguments['brand'] ) ? sanitize_text_field( $arguments['brand'] ) : '';
		$model          = ! empty( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : '';
		$serial_number  = ! empty( $arguments['serial_number'] ) ? sanitize_text_field( $arguments['serial_number'] ) : '';
		$purchase_date  = ! empty( $arguments['purchase_date'] ) ? sanitize_text_field( $arguments['purchase_date'] ) : '';
		$purchase_price = ! empty( $arguments['purchase_price'] ) ? floatval( $arguments['purchase_price'] ) : 0;
		$status         = ! empty( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : 'available';
		$location       = ! empty( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '';
		$notes          = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';

		// Create equipment post.
		$post_data = array(
			'post_title'   => $name,
			'post_content' => $notes,
			'post_status'  => 'publish',
			'post_type'    => 'dj_equipment',
		);

		$equipment_id = wp_insert_post( $post_data );

		if ( is_wp_error( $equipment_id ) ) {
			return array(
				'success' => false,
				'error'   => $equipment_id->get_error_message(),
			);
		}

		// Store equipment metadata.
		update_post_meta( $equipment_id, '_equipment_type', $type );
		update_post_meta( $equipment_id, '_brand', $brand );
		update_post_meta( $equipment_id, '_model', $model );
		update_post_meta( $equipment_id, '_serial_number', $serial_number );
		update_post_meta( $equipment_id, '_purchase_date', $purchase_date );
		update_post_meta( $equipment_id, '_purchase_price', $purchase_price );
		update_post_meta( $equipment_id, '_status', $status );
		update_post_meta( $equipment_id, '_location', $location );

		return array(
			'success'      => true,
			'equipment_id' => $equipment_id,
			'message'      => sprintf(
				/* translators: %s: equipment name */
				__( 'Equipment item "%s" added successfully to inventory.', 'mcp-ai-wpoos-pro' ),
				$name
			),
			'equipment'    => array(
				'id'             => $equipment_id,
				'name'           => $name,
				'type'           => $type,
				'brand'          => $brand,
				'model'          => $model,
				'serial_number'  => $serial_number,
				'purchase_date'  => $purchase_date,
				'purchase_price' => $purchase_price,
				'status'         => $status,
				'location'       => $location,
			),
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
		return array( 'write' );
	}
}
