<?php
/**
 * Tool for listing allergies.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List allergies.
 */
class WP_MCP_AI_Tool_List_Allergies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public function get_slug() {
		return 'list_allergies';
	}

	public function get_name() {
		return __( 'List Allergies', 'mcp-ai-wpoos-pro' );
	}

	public function get_description() {
		return __( 'Lists allergies with optional filtering by member and severity.', 'mcp-ai-wpoos-pro' );
	}

	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Filter by member ID (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'severity'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by severity (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'mild', 'moderate', 'severe', '' ),
				),
				'per_page'  => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'      => array(
					'type'        => 'integer',
					'description' => __( 'Page number (default: 1)', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
	}

	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list allergies.', 'mcp-ai-wpoos-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$severity  = isset( $arguments['severity'] ) ? sanitize_key( $arguments['severity'] ) : '';
		$per_page  = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page      = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		if ( $per_page < 1 || $per_page > 100 ) {
			$per_page = 20;
		}

		$query_args = array(
			'post_type'      => 'mcp_ai_allergy',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $member_id ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_allergy_member_id',
					'value' => $member_id,
				),
			);
		}

		if ( $severity ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_allergy_severity',
					'field'    => 'slug',
					'terms'    => $severity,
				),
			);
		}

		$query = new WP_Query( $query_args );

		$allergies = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$allergy_id = get_the_ID();

				$severities = wp_get_object_terms( $allergy_id, 'mcp_ai_allergy_severity', array( 'fields' => 'slugs' ) );
				$sev        = ! empty( $severities ) && ! is_wp_error( $severities ) ? $severities[0] : '';

				$mid   = get_post_meta( $allergy_id, '_allergy_member_id', true );
				$mname = '';
				if ( $mid ) {
					$mem   = get_post( $mid );
					$mname = $mem ? $mem->post_title : '';
				}

				$allergies[] = array(
					'id'             => $allergy_id,
					'allergen'       => get_the_title(),
					'member_id'      => $mid,
					'member_name'    => $mname,
					'severity'       => $sev,
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
				'total_pages'  => $query->max_num_pages,
				'current_page' => $page,
				'per_page'     => $per_page,
			),
		);
	}
}
