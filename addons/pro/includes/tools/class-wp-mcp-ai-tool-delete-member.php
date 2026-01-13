<?php
/**
 * Tool for deleting members.
 *
 * Allows AI assistants to delete members from the health wellness system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes a member (person or pet).
 */
class WP_MCP_AI_Tool_Delete_Member implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_member';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Member', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a member (person or pet) from the health and wellness system. Only the member creator or users with delete_others_posts capability can delete members. Optionally deletes all related records (allergies, prescriptions, checkups, medical records, policies).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Member ID to delete (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'delete_related'      => array(
					'type'        => 'boolean',
					'description' => __( 'Also delete all related records (allergies, prescriptions, checkups, medical records, policies). Default: false', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'force'               => array(
					'type'        => 'boolean',
					'description' => __( 'Force permanent deletion (bypass trash). Default: false', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'member_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'destructive' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Health and Wellness management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to delete members.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$member_id      = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$delete_related = isset( $arguments['delete_related'] ) ? (bool) $arguments['delete_related'] : false;
		$force           = isset( $arguments['force'] ) ? (bool) $arguments['force'] : false;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );

		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions: must be author or have delete_others_posts capability.
		$is_author = absint( $member->post_author ) === $current_user_id;
		$can_delete_others = user_can( $current_user_id, 'delete_others_posts' );

		if ( ! $is_author && ! $can_delete_others ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete this member.', 'mcp-ai-wpoos-pro' ) );
		}

		$deleted_counts = array();

		// Delete related records if requested.
		if ( $delete_related ) {
			$deleted_counts = $this->delete_related_records( $member_id, $force );
		} else {
			// Check if member has related records and warn user.
			$has_related = $this->has_related_records( $member_id );
			if ( $has_related ) {
				// Return warning but don't prevent deletion.
				$deleted_counts['warning'] = __( 'Member has related records that were not deleted. Use delete_related=true to remove all related data.', 'mcp-ai-wpoos-pro' );
			}
		}

		// Delete the member.
		$result = wp_delete_post( $member_id, $force );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete member.', 'mcp-ai-wpoos-pro' ) );
		}

		$response = array(
			'success'   => true,
			'member_id' => $member_id,
			'message'   => sprintf(
				/* translators: 1: member name, 2: action (deleted/trashed) */
				__( 'Member "%1$s" has been %2$s.', 'mcp-ai-wpoos-pro' ),
				$member->post_title,
				$force ? __( 'permanently deleted', 'mcp-ai-wpoos-pro' ) : __( 'moved to trash', 'mcp-ai-wpoos-pro' )
			),
		);

		if ( ! empty( $deleted_counts ) ) {
			$response['deleted_related'] = $deleted_counts;
		}

		return $response;
	}

	/**
	 * Check if member has any related records.
	 *
	 * @param int $member_id Member ID.
	 * @return bool True if member has related records.
	 */
	private function has_related_records( $member_id ) {
		$post_types = array(
			'mcp_ai_allergy',
			'mcp_ai_prescription',
			'mcp_ai_checkup',
			'mcp_ai_medical_record',
			'mcp_ai_policy',
		);

		foreach ( $post_types as $post_type ) {
			$meta_key = $this->get_meta_key_for_post_type( $post_type );
			$query = new WP_Query( array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'meta_key'       => $meta_key,
				'meta_value'     => $member_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			) );

			if ( $query->have_posts() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete all related records for a member.
	 *
	 * @param int  $member_id Member ID.
	 * @param bool $force     Whether to permanently delete or trash.
	 * @return array Counts of deleted records by type.
	 */
	private function delete_related_records( $member_id, $force = false ) {
		$deleted_counts = array();

		$post_types = array(
			'mcp_ai_allergy'        => '_allergy_member_id',
			'mcp_ai_prescription'   => '_prescription_member_id',
			'mcp_ai_checkup'        => '_checkup_member_id',
			'mcp_ai_medical_record' => '_record_member_id',
			'mcp_ai_policy'         => '_policy_member_id',
		);

		foreach ( $post_types as $post_type => $meta_key ) {
			$query = new WP_Query( array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'meta_key'       => $meta_key,
				'meta_value'     => $member_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );

			$count = 0;
			if ( $query->have_posts() ) {
				foreach ( $query->posts as $post_id ) {
					$result = wp_delete_post( $post_id, $force );
					if ( $result ) {
						++$count;
					}
				}
			}

			if ( $count > 0 ) {
				$deleted_counts[ $post_type ] = $count;
			}
		}

		return $deleted_counts;
	}

	/**
	 * Get the meta key that stores member ID for a given post type.
	 *
	 * @param string $post_type Post type.
	 * @return string Meta key.
	 */
	private function get_meta_key_for_post_type( $post_type ) {
		$map = array(
			'mcp_ai_allergy'        => '_allergy_member_id',
			'mcp_ai_prescription'   => '_prescription_member_id',
			'mcp_ai_checkup'        => '_checkup_member_id',
			'mcp_ai_medical_record' => '_record_member_id',
			'mcp_ai_policy'         => '_policy_member_id',
		);

		return isset( $map[ $post_type ] ) ? $map[ $post_type ] : '_member_id';
	}
}
