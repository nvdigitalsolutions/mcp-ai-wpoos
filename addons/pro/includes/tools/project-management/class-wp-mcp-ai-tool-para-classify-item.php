<?php
/**
 * Tool: para_classify_item
 *
 * Sets the PARA classification (Projects / Areas / Resources / Archives) on
 * any supported post. Validates that the term descends from one of the four
 * locked roots.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classify a post into a PARA bucket.
 */
class WP_MCP_AI_Tool_PARA_Classify_Item implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'para_classify_item';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'PARA: Classify Item', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Assign a project, task, event, area, or document to one of the four PARA buckets: projects, areas, resources, or archives. A sub-bucket term ID may be provided instead of the root slug to use a user-defined sub-bucket. The item must already exist.', 'mcp-ai-wpoos-pro' );
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
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the post (project/task/event/area/document) to classify.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'bucket'  => array(
					'type'        => 'string',
					'description' => __( 'PARA root bucket slug.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'projects', 'areas', 'resources', 'archives' ),
				),
				'term_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional sub-bucket term ID. If provided, takes precedence over `bucket`.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'reason'  => array(
					'type'        => 'string',
					'description' => __( 'Optional reason recorded with the classification.', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
			),
			'required'             => array( 'post_id' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to classify items.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		if ( ! $post_id ) {
			return new WP_Error( 'wp_mcp_ai_invalid_post', __( 'A valid post_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You cannot edit this post.', 'mcp-ai-wpoos-pro' ) );
		}

		$term_value = '';
		if ( ! empty( $arguments['term_id'] ) ) {
			$term_value = absint( $arguments['term_id'] );
		} elseif ( ! empty( $arguments['bucket'] ) ) {
			$term_value = sanitize_key( $arguments['bucket'] );
			if ( ! in_array( $term_value, WP_MCP_AI_PARA_Taxonomy::ROOTS, true ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_bucket', __( 'Bucket must be one of: projects, areas, resources, archives.', 'mcp-ai-wpoos-pro' ) );
			}
		} else {
			return new WP_Error( 'wp_mcp_ai_missing_term', __( 'Either `bucket` or `term_id` is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$reason = isset( $arguments['reason'] ) ? sanitize_textarea_field( $arguments['reason'] ) : '';

		$result = WP_MCP_AI_PARA_Taxonomy::assign( $post_id, $term_value, $reason );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'post_id' => $post_id,
			'bucket'  => WP_MCP_AI_PARA_Taxonomy::get_post_bucket( $post_id ),
			'message' => __( 'Item classified.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
