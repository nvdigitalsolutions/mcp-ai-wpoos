<?php
/**
 * Tool for listing allergies.
 *
 * Allows AI assistants to list allergy records for members.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists allergies with filtering and pagination.
 */
class WP_MCP_AI_Tool_List_Allergies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_allergies';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Allergies', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists allergy records with optional filtering by member, type, severity, and allergen. Supports pagination.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Filter by member ID (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'type'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by allergy type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'food', 'medication', 'environmental', 'insect', 'other' ),
				),
				'severity'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by severity level (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'mild', 'moderate', 'severe', 'life-threatening' ),
				),
				'allergen'  => array(
					'type'        => 'string',
					'description' => __( 'Search by allergen name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'search'    => array(
					'type'        => 'string',
					'description' => __( 'Search by any text (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'per_page'  => array(
					'type'        => 'integer',
					'description' => __( 'Allergies per page (optional, default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'      => array(
					'type'        => 'integer',
					'description' => __( 'Page number (optional, default: 1)', 'mcp-ai-wpoos-pro' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list allergies.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate and sanitize inputs.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$type      = isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : '';
		$severity  = isset( $arguments['severity'] ) ? sanitize_text_field( $arguments['severity'] ) : '';
		$allergen  = isset( $arguments['allergen'] ) ? sanitize_text_field( $arguments['allergen'] ) : '';
		$search    = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$per_page  = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page      = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		// Validate per_page.
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_allergy',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Add meta query for filters.
		$meta_query = array( 'relation' => 'AND' );

		if ( $member_id ) {
			$meta_query[] = array(
				'key'   => '_allergy_member_id',
				'value' => $member_id,
			);
		}

		if ( $type ) {
			$meta_query[] = array(
				'key'   => '_allergy_type',
				'value' => $type,
			);
		}

		if ( $severity ) {
			$meta_query[] = array(
				'key'   => '_allergy_severity',
				'value' => $severity,
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Add search if provided.
		if ( $allergen ) {
			$query_args['s'] = $allergen;
		} elseif ( $search ) {
			$query_args['s'] = $search;
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		// Build allergies array.
		$allergies = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$allergy_id = get_the_ID();

				// Get member info.
				$allergy_member_id = get_post_meta( $allergy_id, '_allergy_member_id', true );
				$member_name       = '';
				if ( $allergy_member_id ) {
					$member = get_post( $allergy_member_id );
					$member_name = $member ? $member->post_title : '';
				}

				$allergies[] = array(
					'id'             => $allergy_id,
					'allergen'       => get_the_title(),
					'member_id'      => $allergy_member_id,
					'member_name'    => $member_name,
					'type'           => get_post_meta( $allergy_id, '_allergy_type', true ),
					'severity'       => get_post_meta( $allergy_id, '_allergy_severity', true ),
					'reactions'      => get_post_meta( $allergy_id, '_allergy_reactions', true ),
					'diagnosed_date' => get_post_meta( $allergy_id, '_allergy_diagnosed_date', true ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'    => true,
			'allergies'  => $allergies,
			'pagination' => array(
				'total'        => $query->found_posts,
				'per_page'     => $per_page,
				'current_page' => $page,
				'total_pages'  => $query->max_num_pages,
			),
		);
	}
}
