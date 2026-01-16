<?php
/**
 * Tool for listing members (people & pets).
 *
 * Allows AI assistants to list members in the health wellness system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists members (people and pets).
 */
class WP_MCP_AI_Tool_List_Members implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_members';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Members', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists members (people and pets) in the health and wellness system. Supports filtering by type and search by name.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'type'     => array(
					'type'        => 'string',
					'description' => __( 'Filter by member type: person or pet (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'person', 'pet', '' ),
				),
				'search'   => array(
					'type'        => 'string',
					'description' => __( 'Search members by name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of members to return per page (optional, default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination (optional, default: 1)', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list members.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate and sanitize inputs.
		$type     = isset( $arguments['type'] ) ? sanitize_key( $arguments['type'] ) : '';
		$search   = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		// Validate per_page.
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_member',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Add search if provided.
		if ( $search ) {
			$query_args['s'] = $search;
		}

		// Add type filter if provided.
		if ( $type && in_array( $type, array( 'person', 'pet' ), true ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_member_type',
					'field'    => 'slug',
					'terms'    => $type,
				),
			);
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		// Build response.
		$members = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$member_id = get_the_ID();

				// Get member type.
				$types      = wp_get_object_terms( $member_id, 'mcp_ai_member_type', array( 'fields' => 'slugs' ) );
				$member_type = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : 'person';

				// Build member data.
				$member_data = array(
					'id'            => $member_id,
					'name'          => get_the_title(),
					'type'          => $member_type,
					'date_of_birth' => get_post_meta( $member_id, '_member_date_of_birth', true ),
					'email'         => get_post_meta( $member_id, '_member_email', true ),
					'phone'         => get_post_meta( $member_id, '_member_phone', true ),
				);

				// Add pet-specific fields.
				if ( 'pet' === $member_type ) {
					$member_data['species'] = get_post_meta( $member_id, '_pet_species', true );
					$member_data['breed']   = get_post_meta( $member_id, '_pet_breed', true );
				}

				$members[] = $member_data;
			}
			wp_reset_postdata();
		}

		return array(
			'success'    => true,
			'members'    => $members,
			'pagination' => array(
				'total'       => $query->found_posts,
				'total_pages' => $query->max_num_pages,
				'current_page' => $page,
				'per_page'    => $per_page,
			),
		);
	}
}
