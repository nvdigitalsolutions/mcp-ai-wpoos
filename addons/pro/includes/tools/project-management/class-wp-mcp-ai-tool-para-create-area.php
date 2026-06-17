<?php
/**
 * Tool: para_create_area
 *
 * Creates a new PARA Area (ongoing responsibility) with standard, owner, and
 * review cadence. Auto-classifies the new Area into the `areas` bucket.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create a new Area.
 */
class WP_MCP_AI_Tool_PARA_Create_Area implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'para_create_area';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'PARA: Create Area', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create a new PARA Area — an ongoing responsibility with a standard to maintain, an owner, and a review cadence (weekly, biweekly, monthly, quarterly, annually). Use Areas for things like Health, Finance, or Team Management — distinct from Projects which have a deadline.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'          => array(
					'type'        => 'string',
					'description' => __( 'Area title.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description'    => array(
					'type'        => 'string',
					'description' => __( 'Optional area description.', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'standard'       => array(
					'type'        => 'string',
					'description' => __( 'The standard to maintain in this area.', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 1000,
				),
				'owner_id'       => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID of the area owner.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'review_cadence' => array(
					'type'        => 'string',
					'description' => __( 'How often this area is reviewed.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'weekly', 'biweekly', 'monthly', 'quarterly', 'annually' ),
					'default'     => 'monthly',
				),
			),
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_PARA_Taxonomy' ) && WP_MCP_AI_PARA_Taxonomy::is_enabled();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create areas.', 'mcp-ai-wpoos-pro' ) );
		}

		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$description = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$standard    = isset( $arguments['standard'] ) ? sanitize_textarea_field( $arguments['standard'] ) : '';
		$owner_id    = isset( $arguments['owner_id'] ) ? absint( $arguments['owner_id'] ) : $user_id;
		$cadence     = isset( $arguments['review_cadence'] ) ? sanitize_key( $arguments['review_cadence'] ) : 'monthly';

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Area title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$valid_cadences = array( 'weekly', 'biweekly', 'monthly', 'quarterly', 'annually' );
		if ( ! in_array( $cadence, $valid_cadences, true ) ) {
			$cadence = 'monthly';
		}

		if ( $owner_id && ! get_user_by( 'id', $owner_id ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_owner', __( 'Owner user does not exist.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => WP_MCP_AI_PARA_Area_CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $description,
				'post_author'  => $user_id,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_para_standard', $standard );
		update_post_meta( $post_id, '_para_owner', $owner_id );
		update_post_meta( $post_id, '_para_review_cadence', $cadence );

		// Auto-classify into 'areas' bucket.
		WP_MCP_AI_PARA_Taxonomy::assign( $post_id, 'areas', __( 'Area created.', 'mcp-ai-wpoos-pro' ) );

		return array(
			'success' => true,
			'area_id' => (int) $post_id,
			'data'    => WP_MCP_AI_PARA_Area_CPT::get_area( $post_id ),
			'message' => __( 'Area created.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
