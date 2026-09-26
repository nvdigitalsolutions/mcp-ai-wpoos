<?php
/**
 * Tool: get_post_revisions — Lists the saved revisions of a WordPress post.
 *
 * Port of mcp-wordpress wp_get_post_revisions tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the saved revisions of a WordPress post with author, date, and excerpt details.
 */
class WP_MCP_AI_Tool_Get_Post_Revisions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_post_revisions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Post Revisions', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists the saved revisions of a WordPress post, including author, date, title, and a short content excerpt.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Reviewing the edit history of a known post ID before restoring or reverting content.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Fetching the current content of a post; use get_post instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_post', 'save_post', 'get_recent_posts' ),
			'notes'           => __( 'post_id comes from get_post / get_recent_posts responses. Posts without revisions return an empty list.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'  => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the post whose revisions to list.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of revisions to return per page (1-100).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'Page number of revisions to return.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'default'     => 1,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'post_id' ),
		);
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
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view post revisions.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;

		if ( ! $post_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'post_id is required.', 'mcp-ai-wpoos' ) );
		}

		$per_page = isset( $arguments['per_page'] ) ? min( 100, max( 1, absint( $arguments['per_page'] ) ) ) : 20;
		$page     = isset( $arguments['page'] ) ? max( 1, absint( $arguments['page'] ) ) : 1;

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'wp_mcp_ai_post_not_found', __( 'The requested post does not exist.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'edit_post', $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view revisions for this post.', 'mcp-ai-wpoos' ) );
		}

		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => $per_page,
				'paged'          => $page,
			)
		);

		// Total revision count. Loading all revisions in one query is acceptable
		// for typical revision histories but may be heavy on very large sites.
		$total = count( wp_get_post_revisions( $post_id, array( 'posts_per_page' => -1 ) ) );

		$formatted = array();
		foreach ( $revisions as $revision ) {
			$formatted[] = $this->format_revision( $revision );
		}

		if ( empty( $formatted ) ) {
			return array(
				'message'   => __( 'No revisions found for this post.', 'mcp-ai-wpoos' ),
				'revisions' => array(),
				'total'     => absint( $total ),
				'page'      => absint( $page ),
				'per_page'  => absint( $per_page ),
				'post_id'   => absint( $post_id ),
			);
		}

		$summary_text = sprintf(
			/* translators: 1: number of revisions, 2: post ID */
			__( 'Found %1$d revision(s) for post ID %2$d.', 'mcp-ai-wpoos' ),
			count( $formatted ),
			$post_id
		);

		return array(
			'message'   => $summary_text,
			'revisions' => $formatted,
			'total'     => absint( $total ),
			'page'      => absint( $page ),
			'per_page'  => absint( $per_page ),
			'post_id'   => absint( $post_id ),
		);
	}

	/**
	 * Format a single revision for output.
	 *
	 * @param WP_Post $revision Revision post object.
	 * @return array Revision payload.
	 */
	private function format_revision( $revision ) {
		$revision_excerpt = wp_strip_all_tags( $revision->post_content );
		$revision_excerpt = mb_substr( $revision_excerpt, 0, 120 );

		return array(
			'id'          => absint( $revision->ID ),
			'author_id'   => absint( $revision->post_author ),
			'author_name' => esc_html( get_the_author_meta( 'display_name', $revision->post_author ) ),
			'date'        => esc_html( $revision->post_date ),
			'title'       => esc_html( $revision->post_title ),
			'excerpt'     => $revision_excerpt,
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'content_creator', 'editor' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'requires-capability', // Requires edit_post capability on the post.
		);
	}
}
