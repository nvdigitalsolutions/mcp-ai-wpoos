<?php
/**
 * Tool: merge_duplicate_members
 *
 * Safely merges two `mcp_ai_member` posts.  All child records that
 * reference the source member by post-meta are re-parented to the
 * destination member, then the source member is moved to trash (or
 * permanently deleted when `permanent=true`).
 *
 * Re-parented post-meta keys:
 *   _allergy_member_id, _prescription_member_id, _checkup_member_id,
 *   _medical_record_member_id, _member_id (auxiliary vital_log CPT).
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
 * Merge duplicate members tool.
 */
class WP_MCP_AI_Tool_Merge_Duplicate_Members implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'merge_duplicate_members';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Merge Duplicate Members', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Merge two duplicate member records by re-parenting allergies, prescriptions, checkups, medical records, and vital logs from a source member to a destination member, then trashing or deleting the source.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'source_member_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Member to merge FROM (will be trashed or deleted).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'destination_member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member to merge INTO (the survivor).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'dry_run'               => array(
					'type'        => 'boolean',
					'description' => __( 'If true, return the planned changes without modifying any data.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'permanent'             => array(
					'type'        => 'boolean',
					'description' => __( 'If true, permanently delete the source member instead of trashing it.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'source_member_id', 'destination_member_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'destructive' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Map of child post types and the meta keys that reference a member.
	 *
	 * @return array
	 */
	private function child_meta_map() {
		$map = array(
			'mcp_ai_allergy'      => '_allergy_member_id',
			'mcp_ai_prescription' => '_prescription_member_id',
			'mcp_ai_checkup'      => '_checkup_member_id',
			'mcp_ai_med_record'   => '_medical_record_member_id',
		);
		if ( post_type_exists( 'mcp_ai_hc_vital_log' ) ) {
			$map['mcp_ai_hc_vital_log'] = '_member_id';
		}
		/**
		 * Filter the map of child post-types and the meta keys that link
		 * them to a member, so partner code can extend the merge sweep.
		 *
		 * @since 1.4.0
		 *
		 * @param array $map Post_type => meta_key.
		 */
		return (array) apply_filters( 'wp_mcp_ai_healthcare_member_child_meta_map', $map );
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_others_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to merge member records.', 'mcp-ai-wpoos-pro' ) );
		}

		$source      = isset( $arguments['source_member_id'] ) ? absint( $arguments['source_member_id'] ) : 0;
		$destination = isset( $arguments['destination_member_id'] ) ? absint( $arguments['destination_member_id'] ) : 0;
		$dry_run     = ! empty( $arguments['dry_run'] );
		$permanent   = ! empty( $arguments['permanent'] );

		if ( $source <= 0 || $destination <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_ids', __( 'Both source_member_id and destination_member_id are required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $source === $destination ) {
			return new WP_Error( 'wp_mcp_ai_same_member', __( 'Source and destination must be different members.', 'mcp-ai-wpoos-pro' ) );
		}

		$src_post = get_post( $source );
		$dst_post = get_post( $destination );
		if ( ! $src_post || 'mcp_ai_member' !== $src_post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_source', __( 'Source member not found.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! $dst_post || 'mcp_ai_member' !== $dst_post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_destination', __( 'Destination member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$child_map = $this->child_meta_map();
		$plan      = array();
		$total     = 0;

		foreach ( $child_map as $post_type => $meta_key ) {
			$query = new WP_Query(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'merge_duplicate_members', 0, 1000 ) : 1000,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => $meta_key,
							'value' => $source,
						),
					),
					'no_found_rows'  => true,
				)
			);
			$ids   = $query->posts;
			if ( ! is_array( $ids ) ) {
				$ids = array();
			}
			$plan[ $post_type ] = array(
				'meta_key' => $meta_key,
				'count'    => count( $ids ),
				'ids'      => array_map( 'intval', $ids ),
			);
			$total             += count( $ids );
		}

		if ( $dry_run ) {
			return array(
				'success'        => true,
				'dry_run'        => true,
				'source'         => array(
					'id'   => $source,
					'name' => $src_post->post_title,
				),
				'destination'    => array(
					'id'   => $destination,
					'name' => $dst_post->post_title,
				),
				'planned_moves'  => $plan,
				'total_children' => $total,
			);
		}

		// Perform the actual re-parenting.
		$applied = array();
		foreach ( $plan as $post_type => $info ) {
			$count = 0;
			foreach ( $info['ids'] as $cid ) {
				$cid = (int) $cid;
				if ( $cid <= 0 ) {
					continue;
				}
				update_post_meta( $cid, $info['meta_key'], $destination );
				++$count;
			}
			$applied[ $post_type ] = $count;
		}

		// Trash or delete the source.
		if ( $permanent ) {
			wp_delete_post( $source, true );
			$source_state = 'deleted';
		} else {
			wp_trash_post( $source );
			$source_state = 'trashed';
		}

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'merge',
				'member',
				$destination,
				array(
					'user_id'      => $current_user_id,
					'tool'         => $this->get_slug(),
					'source'       => $source,
					'children'     => $applied,
					'source_state' => $source_state,
				)
			);
		}

		/**
		 * Fires after two members are merged.
		 *
		 * @since 1.4.0
		 *
		 * @param int   $destination Destination member ID (survivor).
		 * @param int   $source      Source member ID (trashed/deleted).
		 * @param array $applied     Post_type => count of re-parented children.
		 */
		do_action( 'wp_mcp_ai_healthcare_after_merge_members', $destination, $source, $applied );

		return array(
			'success'        => true,
			'source'         => array(
				'id'    => $source,
				'name'  => $src_post->post_title,
				'state' => $source_state,
			),
			'destination'    => array(
				'id'   => $destination,
				'name' => $dst_post->post_title,
			),
			'children_moved' => $applied,
			'total_moved'    => array_sum( $applied ),
		);
	}
}
