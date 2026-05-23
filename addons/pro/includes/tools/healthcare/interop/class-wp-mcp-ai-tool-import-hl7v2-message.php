<?php
/**
 * Tool: import_hl7v2_message
 *
 * Parses a pipe-delimited HL7 v2.x message (ER7) and upserts the data
 * into the local healthcare CPTs.  Supported message types out of the
 * box: ADT^A04 (register a patient), ADT^A08 (update patient info),
 * ORU^R01 (lab/observation result).  Unknown message types are
 * reported back without raising an error so callers can decide how to
 * handle them.
 *
 * The parser is dependency-free and intentionally lenient; sites that
 * need stricter validation should hook the
 * `wp_mcp_ai_healthcare_hl7v2_segments` filter on the parsed segment
 * map before persistence.
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
 * Import HL7 v2 message tool.
 */
class WP_MCP_AI_Tool_Import_HL7v2_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'import_hl7v2_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import HL7 v2 Message', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Parse a pipe-delimited HL7 v2.x ER7 message (ADT^A04, ADT^A08, ORU^R01) and upsert the patient and observations into local CPTs.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'message' => array(
					'type'        => 'string',
					'description' => __( 'The HL7 v2 message in ER7 format. Lines may be separated by \r, \n, or \r\n.', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'required'   => array( 'message' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'phi-data' );
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
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_others_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to import HL7 v2 messages.', 'mcp-ai-wpoos-pro' ) );
		}

		$raw = isset( $arguments['message'] ) ? (string) $arguments['message'] : '';
		if ( '' === trim( $raw ) ) {
			return new WP_Error( 'wp_mcp_ai_hl7v2_empty', __( 'An HL7 message is required.', 'mcp-ai-wpoos-pro' ) );
		}
		$dry_run = ! empty( $arguments['dry_run'] );

		$segments = $this->parse( $raw );
		if ( is_wp_error( $segments ) ) {
			return $segments;
		}

		/**
		 * Filter parsed HL7 v2 segments before persistence.
		 *
		 * @since 1.4.0
		 *
		 * @param array $segments Parsed segment map keyed by 3-letter type.
		 */
		$segments = apply_filters( 'wp_mcp_ai_healthcare_hl7v2_segments', $segments );

		if ( empty( $segments['MSH'] ) ) {
			return new WP_Error( 'wp_mcp_ai_hl7v2_no_msh', __( 'HL7 message is missing an MSH segment.', 'mcp-ai-wpoos-pro' ) );
		}

		$msh = $segments['MSH'][0];
		// Field 9 = message type, field 10 = control id (positions are 1-based per HL7; we pad MSH to start at MSH-1).
		$message_type = isset( $msh[8] ) ? str_replace( '^', '_', (string) $msh[8] ) : '';

		$result = array(
			'success'      => true,
			'dry_run'      => $dry_run,
			'message_type' => $message_type,
			'member_id'    => 0,
			'observations' => 0,
			'notes'        => array(),
		);

		// Patient (PID) handling.
		$member_id = 0;
		if ( ! empty( $segments['PID'] ) ) {
			$member_id = $this->upsert_patient( $segments['PID'][0], $dry_run );
			if ( is_wp_error( $member_id ) ) {
				return $member_id;
			}
			$result['member_id'] = (int) $member_id;
		}

		// Observation result (OBX) handling for ORU^R01.
		if ( ! empty( $segments['OBX'] ) ) {
			$count = 0;
			foreach ( $segments['OBX'] as $obx ) {
				$ok = $this->record_observation( $obx, $member_id, $dry_run );
				if ( ! is_wp_error( $ok ) ) {
					++$count;
				}
			}
			$result['observations'] = $count;
		}

		if ( ! in_array( $message_type, array( 'ADT_A04', 'ADT_A08', 'ORU_R01' ), true ) ) {
			$result['notes'][] = __( 'Message type handler is not registered; only PID + OBX segments were processed.', 'mcp-ai-wpoos-pro' );
		}

		if ( ! $dry_run && class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'import',
				'hl7v2_message',
				(int) $member_id,
				array(
					'user_id'      => $current_user_id,
					'tool'         => $this->get_slug(),
					'message_type' => $message_type,
				)
			);
		}

		return $result;
	}

	/**
	 * Parse an ER7 message into segment arrays.
	 *
	 * Each parsed segment is an array indexed from 0; index 0 is the
	 * three-letter segment type (MSH/PID/OBX/...).
	 *
	 * @param string $raw Raw message.
	 * @return array|WP_Error
	 */
	private function parse( $raw ) {
		$raw   = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ), 'strlen' );
		if ( empty( $lines ) ) {
			return new WP_Error( 'wp_mcp_ai_hl7v2_empty', __( 'No HL7 segments found.', 'mcp-ai-wpoos-pro' ) );
		}
		$first = reset( $lines );
		if ( 0 !== strpos( $first, 'MSH' ) ) {
			return new WP_Error( 'wp_mcp_ai_hl7v2_no_msh', __( 'HL7 message must begin with an MSH segment.', 'mcp-ai-wpoos-pro' ) );
		}
		$segments = array();
		foreach ( $lines as $line ) {
			$fields = explode( '|', $line );
			$type   = isset( $fields[0] ) ? substr( $fields[0], 0, 3 ) : '';
			if ( '' === $type ) {
				continue;
			}
			if ( ! isset( $segments[ $type ] ) ) {
				$segments[ $type ] = array();
			}
			$segments[ $type ][] = $fields;
		}
		return $segments;
	}

	/**
	 * Upsert an `mcp_ai_member` from a PID segment.
	 *
	 * @param array $pid     Parsed PID fields.
	 * @param bool  $dry_run Dry-run flag.
	 * @return int|WP_Error
	 */
	private function upsert_patient( $pid, $dry_run ) {
		// PID-3 = patient identifier (we read the first repetition's first component).
		$identifier = '';
		if ( isset( $pid[3] ) ) {
			$rep        = explode( '~', (string) $pid[3] );
			$first_rep  = $rep[0];
			$components = explode( '^', $first_rep );
			$identifier = sanitize_text_field( $components[0] );
		}
		// PID-5 = patient name (^family^given).
		$family = '';
		$given  = '';
		if ( isset( $pid[5] ) ) {
			$comp   = explode( '^', (string) $pid[5] );
			$family = isset( $comp[0] ) ? sanitize_text_field( $comp[0] ) : '';
			$given  = isset( $comp[1] ) ? sanitize_text_field( $comp[1] ) : '';
		}
		// PID-7 = DOB (YYYYMMDD).
		$dob = isset( $pid[7] ) ? sanitize_text_field( (string) $pid[7] ) : '';
		// PID-8 = sex.
		$sex = isset( $pid[8] ) ? sanitize_text_field( (string) $pid[8] ) : '';

		if ( $dry_run ) {
			return 0;
		}

		$existing = 0;
		if ( '' !== $identifier ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_member',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'meta_query'     => array(
						array(
							'key'   => '_member_mrn',
							'value' => $identifier,
						),
					),
				)
			);
			if ( ! empty( $query->posts ) ) {
				$existing = (int) $query->posts[0];
			}
		}

		$title = trim( $given . ' ' . $family );
		if ( '' === $title ) {
			$title = '' !== $identifier ? $identifier : __( '(unnamed)', 'mcp-ai-wpoos-pro' );
		}

		if ( $existing > 0 ) {
			wp_update_post(
				array(
					'ID'         => $existing,
					'post_title' => $title,
				)
			);
			$post_id = $existing;
		} else {
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_member',
					'post_title'  => $title,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
		}

		if ( '' !== $identifier ) {
			update_post_meta( $post_id, '_member_mrn', $identifier );
		}
		if ( '' !== $given ) {
			update_post_meta( $post_id, '_member_first_name', $given );
		}
		if ( '' !== $family ) {
			update_post_meta( $post_id, '_member_last_name', $family );
		}
		if ( '' !== $dob ) {
			update_post_meta( $post_id, '_member_date_of_birth', $dob );
		}
		if ( '' !== $sex ) {
			update_post_meta( $post_id, '_member_gender', strtolower( $sex ) );
		}
		return (int) $post_id;
	}

	/**
	 * Record an OBX as a checkup-type record on the member.
	 *
	 * @param array $obx       Parsed OBX fields.
	 * @param int   $member_id Member id.
	 * @param bool  $dry_run   Dry-run flag.
	 * @return int|WP_Error
	 */
	private function record_observation( $obx, $member_id, $dry_run ) {
		// OBX-3 = identifier (^name^codingSystem); OBX-5 = value; OBX-6 = units.
		$name = '';
		if ( isset( $obx[3] ) ) {
			$comp = explode( '^', (string) $obx[3] );
			$name = isset( $comp[1] ) && '' !== $comp[1] ? sanitize_text_field( $comp[1] ) : sanitize_text_field( $comp[0] ?? '' );
		}
		$value = isset( $obx[5] ) ? sanitize_text_field( (string) $obx[5] ) : '';
		$units = isset( $obx[6] ) ? sanitize_text_field( (string) $obx[6] ) : '';
		if ( '' === $name && '' === $value ) {
			return new WP_Error( 'wp_mcp_ai_hl7v2_obx_empty', __( 'OBX has no name or value.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $dry_run ) {
			return 0;
		}
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_med_record',
				'post_title'   => '' !== $name ? $name : $value,
				'post_content' => trim( $value . ' ' . $units ),
				'post_status'  => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, '_record_type', 'observation' );
		update_post_meta( $post_id, '_record_value', $value );
		update_post_meta( $post_id, '_record_units', $units );
		if ( $member_id ) {
			update_post_meta( $post_id, '_medical_record_member_id', $member_id );
		}
		return (int) $post_id;
	}
}
