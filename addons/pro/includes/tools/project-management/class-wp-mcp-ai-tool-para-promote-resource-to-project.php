<?php
/**
 * Tool: para_promote_resource_to_project
 *
 * Common PARA workflow: a reference item (in Resources) becomes actionable
 * and is promoted to a Project. Creates a project from the resource and
 * reclassifies the source.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Promote a resource into a new project.
 */
class WP_MCP_AI_Tool_PARA_Promote_Resource_To_Project implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'para_promote_resource_to_project';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'PARA: Promote Resource to Project', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Promote a PARA Resource into a new actionable Project. Creates a `mcp_ai_project` from the resource title/description and links the new project to the source resource via the `_para_source_resource_id` post meta.', 'mcp-ai-wpoos-pro' );
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
				'resource_post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'project_title'    => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'project_status'   => array(
					'type' => 'string',
					'enum' => array( 'planning', 'active' ),
				),
				'end_date'         => array(
					'type'    => 'string',
					'pattern' => '^\d{4}-\d{2}-\d{2}$',
				),
			),
			'required'             => array( 'resource_post_id' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create projects.', 'mcp-ai-wpoos-pro' ) );
		}

		$resource_id = isset( $arguments['resource_post_id'] ) ? absint( $arguments['resource_post_id'] ) : 0;
		$resource    = $resource_id ? get_post( $resource_id ) : null;
		if ( ! $resource ) {
			return new WP_Error( 'wp_mcp_ai_invalid_resource', __( 'Resource post not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$bucket = WP_MCP_AI_PARA_Taxonomy::get_post_bucket( $resource_id );
		if ( 'resources' !== $bucket ) {
			return new WP_Error( 'wp_mcp_ai_not_resource', __( 'Source post is not classified as a Resource.', 'mcp-ai-wpoos-pro' ) );
		}

		$title  = isset( $arguments['project_title'] )
			? sanitize_text_field( $arguments['project_title'] )
			: sprintf( /* translators: %s: source title */ __( 'Project: %s', 'mcp-ai-wpoos-pro' ), $resource->post_title );
		$status = isset( $arguments['project_status'] ) ? sanitize_key( $arguments['project_status'] ) : 'planning';
		if ( ! in_array( $status, array( 'planning', 'active' ), true ) ) {
			$status = 'planning';
		}
		$end_date = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';

		$project_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_project',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $resource->post_content,
				'post_author'  => $user_id,
			),
			true
		);
		if ( is_wp_error( $project_id ) ) {
			return $project_id;
		}

		update_post_meta( $project_id, '_project_status', $status );
		if ( $end_date ) {
			update_post_meta( $project_id, '_project_end_date', $end_date );
		}
		update_post_meta( $project_id, '_para_source_resource_id', $resource_id );

		WP_MCP_AI_PARA_Taxonomy::assign( $project_id, 'projects', __( 'Promoted from resource.', 'mcp-ai-wpoos-pro' ) );

		return array(
			'success'     => true,
			'project_id'  => (int) $project_id,
			'resource_id' => $resource_id,
			'message'     => __( 'Resource promoted to project.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
