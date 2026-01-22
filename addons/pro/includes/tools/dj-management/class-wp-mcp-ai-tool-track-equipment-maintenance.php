<?php
/**
 * Tool for tracking equipment maintenance schedules.
 *
 * Allows AI assistants to record and track maintenance activities for DJ equipment.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks equipment maintenance schedules and history.
 */
class WP_MCP_AI_Tool_Track_Equipment_Maintenance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'track_equipment_maintenance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Track Equipment Maintenance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Records and tracks maintenance activities for DJ equipment. Logs maintenance dates, types, and schedules future maintenance.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'equipment_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Equipment ID to track maintenance for (required)', 'mcp-ai-wpoos-pro' ),
				),
				'maintenance_type'    => array(
					'type'        => 'string',
					'description' => __( 'Type of maintenance (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'cleaning', 'repair', 'inspection', 'calibration', 'replacement', 'upgrade' ),
				),
				'maintenance_date'    => array(
					'type'        => 'string',
					'description' => __( 'Maintenance date in ISO 8601 format (YYYY-MM-DD) (required)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'performed_by'        => array(
					'type'        => 'string',
					'description' => __( 'Name of person who performed maintenance (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'cost'                => array(
					'type'        => 'number',
					'description' => __( 'Maintenance cost (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'notes'               => array(
					'type'        => 'string',
					'description' => __( 'Maintenance notes/details (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'next_maintenance'    => array(
					'type'        => 'string',
					'description' => __( 'Next scheduled maintenance date in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
			),
			'required'             => array( 'equipment_id', 'maintenance_type', 'maintenance_date' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments, array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['equipment_id'] ) || empty( $arguments['maintenance_type'] ) || empty( $arguments['maintenance_date'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Equipment ID, maintenance type, and maintenance date are required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$equipment_id = absint( $arguments['equipment_id'] );

		// Verify equipment exists.
		if ( ! get_post( $equipment_id ) || get_post_type( $equipment_id ) !== 'dj_equipment' ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid equipment ID.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Sanitize inputs.
		$maintenance_type = sanitize_text_field( $arguments['maintenance_type'] );
		$maintenance_date = sanitize_text_field( $arguments['maintenance_date'] );
		$performed_by     = ! empty( $arguments['performed_by'] ) ? sanitize_text_field( $arguments['performed_by'] ) : '';
		$cost             = ! empty( $arguments['cost'] ) ? floatval( $arguments['cost'] ) : 0;
		$notes            = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';
		$next_maintenance = ! empty( $arguments['next_maintenance'] ) ? sanitize_text_field( $arguments['next_maintenance'] ) : '';

		// Get existing maintenance history.
		$maintenance_history = get_post_meta( $equipment_id, '_maintenance_history', true );
		if ( ! is_array( $maintenance_history ) ) {
			$maintenance_history = array();
		}

		// Add new maintenance record.
		$maintenance_record = array(
			'type'             => $maintenance_type,
			'date'             => $maintenance_date,
			'performed_by'     => $performed_by,
			'cost'             => $cost,
			'notes'            => $notes,
			'recorded_at'      => current_time( 'mysql' ),
		);

		$maintenance_history[] = $maintenance_record;

		// Update maintenance history.
		update_post_meta( $equipment_id, '_maintenance_history', $maintenance_history );

		// Update last maintenance date.
		update_post_meta( $equipment_id, '_last_maintenance_date', $maintenance_date );
		update_post_meta( $equipment_id, '_last_maintenance_type', $maintenance_type );

		// Update next maintenance date if provided.
		if ( $next_maintenance ) {
			update_post_meta( $equipment_id, '_next_maintenance_date', $next_maintenance );
		}

		$equipment_name = get_the_title( $equipment_id );

		return array(
			'success'             => true,
			'message'             => sprintf(
				/* translators: 1: maintenance type, 2: equipment name */
				__( '%1$s maintenance logged for equipment "%2$s".', 'mcp-ai-wpoos-pro' ),
				ucfirst( $maintenance_type ),
				$equipment_name
			),
			'equipment_id'        => $equipment_id,
			'maintenance_record'  => $maintenance_record,
			'next_maintenance'    => $next_maintenance,
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
