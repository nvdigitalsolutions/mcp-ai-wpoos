<?php
/**
 * Tool: get_health_timeline
 *
 * Returns a unified, chronological timeline for a member by stitching
 * together the existing healthcare CPTs: medical records, prescriptions,
 * checkups, allergies, and vital-sign logs.  Entirely read-only — no new
 * persistence layer.
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
 * Get health timeline tool.
 */
class WP_MCP_AI_Tool_Get_Health_Timeline implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'get_health_timeline';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Health Timeline', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Return a chronological timeline for a member combining medical records, prescriptions, checkups, allergies, and vital-sign logs (newest first by default).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'member_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Member post ID.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'date_from'   => array(
					'type'        => 'string',
					'description' => __( 'Inclusive start date (YYYY-MM-DD). Optional.', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'     => array(
					'type'        => 'string',
					'description' => __( 'Inclusive end date (YYYY-MM-DD). Optional.', 'mcp-ai-wpoos-pro' ),
				),
				'event_types' => array(
					'type'        => 'array',
					'description' => __( 'Restrict to specific event types.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'medical_record', 'prescription', 'checkup', 'allergy', 'vital_log' ),
					),
				),
				'order'       => array(
					'type'    => 'string',
					'enum'    => array( 'asc', 'desc' ),
					'default' => 'desc',
				),
				'per_page'    => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 200,
					'default' => 50,
				),
			),
			'required'   => array( 'member_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'pii-data', 'cacheable', 'paginated' );
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
		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view health timeline data.', 'mcp-ai-wpoos-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		if ( $member_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'A valid member_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$date_from = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '';
		$date_to   = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : '';
		$order     = isset( $arguments['order'] ) && 'asc' === strtolower( (string) $arguments['order'] ) ? 'asc' : 'desc';
		$per_page  = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 50;
		if ( $per_page < 1 || $per_page > 200 ) {
			$per_page = 50;
		}
		$ts_from = '' !== $date_from ? strtotime( $date_from ) : false;
		$ts_to   = '' !== $date_to ? strtotime( $date_to . ' 23:59:59' ) : false;

		$want_types = array( 'medical_record', 'prescription', 'checkup', 'allergy', 'vital_log' );
		if ( isset( $arguments['event_types'] ) && is_array( $arguments['event_types'] ) ) {
			$want_types = array_values(
				array_intersect(
					$want_types,
					array_map( 'sanitize_key', $arguments['event_types'] )
				)
			);
		}

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'read',
				'health_timeline',
				$member_id,
				array(
					'user_id' => $current_user_id,
					'tool'    => $this->get_slug(),
					'types'   => $want_types,
				)
			);
		}

		$events = array();

		if ( in_array( 'medical_record', $want_types, true ) ) {
			$events = array_merge( $events, $this->collect_records( $member_id ) );
		}
		if ( in_array( 'prescription', $want_types, true ) ) {
			$events = array_merge( $events, $this->collect_prescriptions( $member_id ) );
		}
		if ( in_array( 'checkup', $want_types, true ) ) {
			$events = array_merge( $events, $this->collect_checkups( $member_id ) );
		}
		if ( in_array( 'allergy', $want_types, true ) ) {
			$events = array_merge( $events, $this->collect_allergies( $member_id ) );
		}
		if ( in_array( 'vital_log', $want_types, true ) ) {
			$events = array_merge( $events, $this->collect_vitals( $member_id ) );
		}

		// Filter by date range.
		if ( false !== $ts_from || false !== $ts_to ) {
			$events = array_values(
				array_filter(
					$events,
					static function ( $e ) use ( $ts_from, $ts_to ) {
						$ts = isset( $e['timestamp'] ) ? (int) $e['timestamp'] : 0;
						if ( $ts <= 0 ) {
							return false;
						}
						if ( false !== $ts_from && $ts < $ts_from ) {
							return false;
						}
						if ( false !== $ts_to && $ts > $ts_to ) {
							return false;
						}
						return true;
					}
				)
			);
		}

		usort(
			$events,
			static function ( $a, $b ) use ( $order ) {
				$ta = isset( $a['timestamp'] ) ? (int) $a['timestamp'] : 0;
				$tb = isset( $b['timestamp'] ) ? (int) $b['timestamp'] : 0;
				if ( $ta === $tb ) {
					return 0;
				}
				if ( 'asc' === $order ) {
					return ( $ta < $tb ) ? -1 : 1;
				}
				return ( $ta > $tb ) ? -1 : 1;
			}
		);

		$total  = count( $events );
		$events = array_slice( $events, 0, $per_page );

		return array(
			'success'    => true,
			'member_id'  => $member_id,
			'events'     => $events,
			'pagination' => array(
				'returned' => count( $events ),
				'total'    => $total,
				'per_page' => $per_page,
			),
		);
	}

	/**
	 * Collect medical records for a member.
	 *
	 * @param int $member_id Member ID.
	 * @return array
	 */
	private function collect_records( $member_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_med_record',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'get_health_timeline', 0, 1000 ) : 1000,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_medical_record_member_id',
						'value' => $member_id,
					),
				),
				'no_found_rows'  => true,
			)
		);
		$out   = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id    = get_the_ID();
				$date  = (string) get_post_meta( $id, '_medical_record_date', true );
				$ts    = '' !== $date ? strtotime( $date ) : strtotime( get_post_field( 'post_date', $id ) );
				$out[] = array(
					'event_type' => 'medical_record',
					'id'         => $id,
					'title'      => get_the_title(),
					'date'       => $date,
					'timestamp'  => $ts ? $ts : 0,
					'provider'   => (string) get_post_meta( $id, '_medical_record_provider', true ),
				);
			}
			wp_reset_postdata();
		}
		return $out;
	}

	/**
	 * Collect prescriptions for a member.
	 *
	 * @param int $member_id Member ID.
	 * @return array
	 */
	private function collect_prescriptions( $member_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_prescription',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'get_health_timeline', 0, 1000 ) : 1000,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_prescription_member_id',
						'value' => $member_id,
					),
				),
				'no_found_rows'  => true,
			)
		);
		$out   = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id    = get_the_ID();
				$start = (string) get_post_meta( $id, '_prescription_start_date', true );
				$ts    = '' !== $start ? strtotime( $start ) : strtotime( get_post_field( 'post_date', $id ) );
				$out[] = array(
					'event_type'      => 'prescription',
					'id'              => $id,
					'title'           => get_the_title(),
					'medication_name' => (string) get_post_meta( $id, '_prescription_medication_name', true ),
					'date'            => $start,
					'timestamp'       => $ts ? $ts : 0,
					'status'          => (string) get_post_meta( $id, '_prescription_status', true ),
				);
			}
			wp_reset_postdata();
		}
		return $out;
	}

	/**
	 * Collect checkups for a member.
	 *
	 * @param int $member_id Member ID.
	 * @return array
	 */
	private function collect_checkups( $member_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_checkup',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'get_health_timeline', 0, 1000 ) : 1000,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_checkup_member_id',
						'value' => $member_id,
					),
				),
				'no_found_rows'  => true,
			)
		);
		$out   = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id    = get_the_ID();
				$dt    = (string) get_post_meta( $id, '_checkup_datetime', true );
				$ts    = '' !== $dt ? strtotime( $dt ) : strtotime( get_post_field( 'post_date', $id ) );
				$out[] = array(
					'event_type' => 'checkup',
					'id'         => $id,
					'title'      => get_the_title(),
					'date'       => $dt,
					'timestamp'  => $ts ? $ts : 0,
					'provider'   => (string) get_post_meta( $id, '_checkup_provider', true ),
					'status'     => (string) get_post_meta( $id, '_checkup_status', true ),
				);
			}
			wp_reset_postdata();
		}
		return $out;
	}

	/**
	 * Collect allergies for a member.
	 *
	 * @param int $member_id Member ID.
	 * @return array
	 */
	private function collect_allergies( $member_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_allergy',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'get_health_timeline', 0, 1000 ) : 1000,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_allergy_member_id',
						'value' => $member_id,
					),
				),
				'no_found_rows'  => true,
			)
		);
		$out   = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id    = get_the_ID();
				$dx    = (string) get_post_meta( $id, '_allergy_diagnosed_date', true );
				$ts    = '' !== $dx ? strtotime( $dx ) : strtotime( get_post_field( 'post_date', $id ) );
				$out[] = array(
					'event_type' => 'allergy',
					'id'         => $id,
					'title'      => get_the_title(),
					'date'       => $dx,
					'timestamp'  => $ts ? $ts : 0,
					'reactions'  => (string) get_post_meta( $id, '_allergy_reactions', true ),
				);
			}
			wp_reset_postdata();
		}
		return $out;
	}

	/**
	 * Collect vital logs from the auxiliary CPT, falling back gracefully when
	 * the CPT is not registered.
	 *
	 * @param int $member_id Member ID.
	 * @return array
	 */
	private function collect_vitals( $member_id ) {
		if ( ! post_type_exists( 'mcp_ai_hc_vital_log' ) ) {
			return array();
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_hc_vital_log',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'get_health_timeline', 0, 1000 ) : 1000,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_member_id',
						'value' => $member_id,
					),
				),
				'no_found_rows'  => true,
			)
		);
		$out   = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id    = get_the_ID();
				$date  = (string) get_post_meta( $id, '_measurement_date', true );
				$ts    = '' !== $date ? strtotime( $date ) : strtotime( get_post_field( 'post_date', $id ) );
				$out[] = array(
					'event_type' => 'vital_log',
					'id'         => $id,
					'title'      => get_the_title(),
					'date'       => $date,
					'timestamp'  => $ts ? $ts : 0,
				);
			}
			wp_reset_postdata();
		}
		return $out;
	}
}
