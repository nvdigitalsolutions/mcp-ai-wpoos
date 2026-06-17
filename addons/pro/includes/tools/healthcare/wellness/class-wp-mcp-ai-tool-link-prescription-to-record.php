<?php
/**
 * Tool: link_prescription_to_record
 *
 * Establishes a bi-directional back-reference between a prescription post
 * (`mcp_ai_prescription`) and a medical-record post (`mcp_ai_med_record`)
 * using `_prescription_record_ids` / `_medical_record_prescription_ids`
 * post-meta arrays.  Linking is idempotent and supports unlinking via the
 * `unlink` action.
 *
 * Both posts must reference the same member.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link prescription to record tool.
 */
class WP_MCP_AI_Tool_Link_Prescription_To_Record implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'link_prescription_to_record';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Link Prescription to Record', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Link or unlink a prescription with a medical record. Both posts must reference the same member; the link is stored as bi-directional post-meta arrays.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'          => array(
					'type'    => 'string',
					'enum'    => array( 'link', 'unlink', 'list' ),
					'default' => 'link',
				),
				'prescription_id' => array(
					'type'        => 'integer',
					'description' => __( 'Prescription post ID.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'record_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Medical record post ID.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'   => array( 'prescription_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'reversible' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to modify health records.', 'mcp-ai-wpoos-pro' ) );
		}

		$action          = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'link';
		$prescription_id = isset( $arguments['prescription_id'] ) ? absint( $arguments['prescription_id'] ) : 0;
		$record_id       = isset( $arguments['record_id'] ) ? absint( $arguments['record_id'] ) : 0;

		if ( $prescription_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_prescription', __( 'A valid prescription_id is required.', 'mcp-ai-wpoos-pro' ) );
		}
		$prescription = get_post( $prescription_id );
		if ( ! $prescription || 'mcp_ai_prescription' !== $prescription->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_prescription', __( 'Prescription not found.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( 'list' === $action ) {
			$linked = (array) get_post_meta( $prescription_id, '_prescription_record_ids', true );
			$linked = array_values( array_filter( array_map( 'absint', $linked ) ) );
			$rows   = array();
			foreach ( $linked as $rid ) {
				$rec = get_post( $rid );
				if ( $rec && 'mcp_ai_med_record' === $rec->post_type ) {
					$rows[] = array(
						'record_id' => $rid,
						'title'     => $rec->post_title,
						'date'      => (string) get_post_meta( $rid, '_medical_record_date', true ),
					);
				}
			}
			return array(
				'success'         => true,
				'prescription_id' => $prescription_id,
				'linked_records'  => $rows,
			);
		}

		if ( $record_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_record', __( 'A valid record_id is required.', 'mcp-ai-wpoos-pro' ) );
		}
		$record = get_post( $record_id );
		if ( ! $record || 'mcp_ai_med_record' !== $record->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_record', __( 'Medical record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate same member for link operations.
		$rx_member  = absint( get_post_meta( $prescription_id, '_prescription_member_id', true ) );
		$rec_member = absint( get_post_meta( $record_id, '_medical_record_member_id', true ) );
		if ( 'link' === $action && $rx_member > 0 && $rec_member > 0 && $rx_member !== $rec_member ) {
			return new WP_Error(
				'wp_mcp_ai_member_mismatch',
				__( 'Prescription and medical record reference different members; cannot link.', 'mcp-ai-wpoos-pro' )
			);
		}

		$rx_links  = (array) get_post_meta( $prescription_id, '_prescription_record_ids', true );
		$rec_links = (array) get_post_meta( $record_id, '_medical_record_prescription_ids', true );
		$rx_links  = array_values( array_filter( array_map( 'absint', $rx_links ) ) );
		$rec_links = array_values( array_filter( array_map( 'absint', $rec_links ) ) );

		if ( 'link' === $action ) {
			if ( ! in_array( $record_id, $rx_links, true ) ) {
				$rx_links[] = $record_id;
			}
			if ( ! in_array( $prescription_id, $rec_links, true ) ) {
				$rec_links[] = $prescription_id;
			}
			$msg = __( 'Prescription linked to medical record.', 'mcp-ai-wpoos-pro' );
		} elseif ( 'unlink' === $action ) {
			$rx_links  = array_values( array_diff( $rx_links, array( $record_id ) ) );
			$rec_links = array_values( array_diff( $rec_links, array( $prescription_id ) ) );
			$msg       = __( 'Prescription unlinked from medical record.', 'mcp-ai-wpoos-pro' );
		} else {
			return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Unsupported action; use link, unlink, or list.', 'mcp-ai-wpoos-pro' ) );
		}

		update_post_meta( $prescription_id, '_prescription_record_ids', $rx_links );
		update_post_meta( $record_id, '_medical_record_prescription_ids', $rec_links );

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				$action,
				'prescription_record_link',
				$prescription_id,
				array(
					'user_id'   => $current_user_id,
					'tool'      => $this->get_slug(),
					'record_id' => $record_id,
				)
			);
		}

		return array(
			'success'         => true,
			'message'         => $msg,
			'action'          => $action,
			'prescription_id' => $prescription_id,
			'record_id'       => $record_id,
			'linked_records'  => $rx_links,
		);
	}
}
