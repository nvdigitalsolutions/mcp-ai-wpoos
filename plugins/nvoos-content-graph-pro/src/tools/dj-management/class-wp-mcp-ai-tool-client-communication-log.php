<?php
/**
 * DJ-management tool batch (ecosystem port - Wave F2, dj-management toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/dj-management/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro` (no path
 * constants in the gated batch - the D8-compat interface copies resolve via the entry's spl autoloader; the
 * import tool's `NVOOS_CONTENT_GRAPH_PRO_PATH` refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root).
 *
 * Tool for logging client communications.
 *
 * Allows AI assistants to log all communications with DJ clients.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs client communications.
 */
class WP_MCP_AI_Tool_Client_Communication_Log implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'client_communication_log';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Client Communication Log', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Logs all communications with DJ clients. Track emails, calls, meetings, and notes for complete client interaction history.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'client_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Client ID (optional, can use booking_id instead)', 'nvoos-content-graph-pro' ),
				),
				'booking_id'         => array(
					'type'        => 'integer',
					'description' => __( 'Booking ID (optional, can use client_id instead)', 'nvoos-content-graph-pro' ),
				),
				'communication_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of communication (required)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'email', 'phone', 'meeting', 'text', 'video_call', 'note' ),
				),
				'subject'            => array(
					'type'        => 'string',
					'description' => __( 'Communication subject/topic (required)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'notes'              => array(
					'type'        => 'string',
					'description' => __( 'Communication notes/details (required)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 2000,
				),
				'communication_date' => array(
					'type'        => 'string',
					'description' => __( 'Communication date in ISO 8601 format (YYYY-MM-DD) (optional, defaults to today)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'follow_up_required' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether follow-up is required (optional)', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'follow_up_date'     => array(
					'type'        => 'string',
					'description' => __( 'Follow-up date in ISO 8601 format (YYYY-MM-DD) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
			),
			'required'             => array( 'communication_type', 'subject', 'notes' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['communication_type'] ) || empty( $arguments['subject'] ) || empty( $arguments['notes'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Communication type, subject, and notes are required.', 'nvoos-content-graph-pro' ),
			);
		}

		// Need either client_id or booking_id.
		if ( empty( $arguments['client_id'] ) && empty( $arguments['booking_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Either client_id or booking_id is required.', 'nvoos-content-graph-pro' ),
			);
		}

		$client_id  = ! empty( $arguments['client_id'] ) ? absint( $arguments['client_id'] ) : 0;
		$booking_id = ! empty( $arguments['booking_id'] ) ? absint( $arguments['booking_id'] ) : 0;

		// Get client ID from booking if needed.
		if ( ! $client_id && $booking_id ) {
			if ( ! get_post( $booking_id ) || get_post_type( $booking_id ) !== 'dj_booking' ) {
				return array(
					'success' => false,
					'error'   => __( 'Invalid booking ID.', 'nvoos-content-graph-pro' ),
				);
			}
			$client_email = get_post_meta( $booking_id, '_client_email', true );
			// Find client by email.
			$clients = get_posts(
				array(
					'post_type'   => 'dj_client',
					'meta_key'    => '_email',
					'meta_value'  => $client_email,
					'numberposts' => 1,
				)
			);
			if ( ! empty( $clients ) ) {
				$client_id = $clients[0]->ID;
			}
		}

		// Sanitize inputs.
		$communication_type = sanitize_text_field( $arguments['communication_type'] );
		$subject            = sanitize_text_field( $arguments['subject'] );
		$notes              = sanitize_textarea_field( $arguments['notes'] );
		$communication_date = ! empty( $arguments['communication_date'] ) ? sanitize_text_field( $arguments['communication_date'] ) : current_time( 'Y-m-d' );
		$follow_up_required = ! empty( $arguments['follow_up_required'] );
		$follow_up_date     = ! empty( $arguments['follow_up_date'] ) ? sanitize_text_field( $arguments['follow_up_date'] ) : '';

		// Create communication record.
		$communication = array(
			'type'               => $communication_type,
			'subject'            => $subject,
			'notes'              => $notes,
			'date'               => $communication_date,
			'follow_up_required' => $follow_up_required,
			'follow_up_date'     => $follow_up_date,
			'logged_at'          => current_time( 'mysql' ),
			'booking_id'         => $booking_id,
		);

		// Store communication log.
		if ( $client_id ) {
			$communications = get_post_meta( $client_id, '_communications', true );
			if ( ! is_array( $communications ) ) {
				$communications = array();
			}
			$communications[] = $communication;
			update_post_meta( $client_id, '_communications', $communications );
		}

		// Also store on booking if provided.
		if ( $booking_id ) {
			$booking_communications = get_post_meta( $booking_id, '_communications', true );
			if ( ! is_array( $booking_communications ) ) {
				$booking_communications = array();
			}
			$booking_communications[] = $communication;
			update_post_meta( $booking_id, '_communications', $booking_communications );
		}

		return array(
			'success'       => true,
			'message'       => __( 'Communication logged successfully.', 'nvoos-content-graph-pro' ),
			'client_id'     => $client_id,
			'booking_id'    => $booking_id,
			'communication' => $communication,
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
	public function get_capability_flags() {
		return array( 'write' );
	}
}
