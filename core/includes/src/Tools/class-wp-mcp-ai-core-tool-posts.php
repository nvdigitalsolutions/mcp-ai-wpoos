<?php
/**
 * Posts Tool - CRUD operations for WordPress posts.
 *
 * @package WP_MCP_AI_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for WordPress post operations.
 *
 * Provides read and write access to WordPress posts including:
 * - Getting recent posts
 * - Searching posts
 * - Creating/updating posts
 * - Getting single post by ID
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Core_Tool_Posts implements WP_MCP_AI_Core_Tool_Interface, WP_MCP_AI_Core_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'posts';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Posts', 'wp-mcp-ai-core' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Query, create, update, and manage WordPress posts. Supports filtering by post type, status, author, and search terms.', 'wp-mcp-ai-core' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'The action to perform: get, list, create, update, delete, search.', 'wp-mcp-ai-core' ),
					'enum'        => array( 'get', 'list', 'create', 'update', 'delete', 'search' ),
					'default'     => 'list',
				),
				'post_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Post ID for get, update, or delete actions.', 'wp-mcp-ai-core' ),
				),
				'post_type'   => array(
					'type'        => 'string',
					'description' => __( 'Post type to query. Default: post.', 'wp-mcp-ai-core' ),
					'default'     => 'post',
				),
				'post_status' => array(
					'type'        => 'string',
					'description' => __( 'Post status to query. Default: publish.', 'wp-mcp-ai-core' ),
					'default'     => 'publish',
				),
				'per_page'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of posts to return. Default: 10. Max: 100.', 'wp-mcp-ai-core' ),
					'default'     => 10,
					'maximum'     => 100,
				),
				'page'        => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'wp-mcp-ai-core' ),
					'default'     => 1,
				),
				'search'      => array(
					'type'        => 'string',
					'description' => __( 'Search term to filter posts.', 'wp-mcp-ai-core' ),
				),
				'author'      => array(
					'type'        => 'integer',
					'description' => __( 'Filter posts by author ID.', 'wp-mcp-ai-core' ),
				),
				'orderby'     => array(
					'type'        => 'string',
					'description' => __( 'Field to order by. Default: date.', 'wp-mcp-ai-core' ),
					'enum'        => array( 'date', 'title', 'modified', 'ID', 'author', 'menu_order' ),
					'default'     => 'date',
				),
				'order'       => array(
					'type'        => 'string',
					'description' => __( 'Order direction. Default: DESC.', 'wp-mcp-ai-core' ),
					'enum'        => array( 'ASC', 'DESC' ),
					'default'     => 'DESC',
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Post title for create/update.', 'wp-mcp-ai-core' ),
				),
				'content'     => array(
					'type'        => 'string',
					'description' => __( 'Post content for create/update.', 'wp-mcp-ai-core' ),
				),
				'excerpt'     => array(
					'type'        => 'string',
					'description' => __( 'Post excerpt for create/update.', 'wp-mcp-ai-core' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'read-only',     // list/get/search operations.
			'write',         // create/update/delete operations.
			'local-only',    // No external API calls.
			'reversible',    // Changes can be undone via revisions.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

		switch ( $action ) {
			case 'get':
				return $this->get_post( $arguments );
			case 'list':
				return $this->list_posts( $arguments );
			case 'create':
				return $this->create_post( $arguments, $context );
			case 'update':
				return $this->update_post( $arguments, $context );
			case 'delete':
				return $this->delete_post( $arguments, $context );
			case 'search':
				return $this->search_posts( $arguments );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'wp-mcp-ai-core' )
				);
		}
	}

	/**
	 * Get a single post by ID.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function get_post( $arguments ) {
		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error(
				'missing_post_id',
				__( 'Post ID is required for get action.', 'wp-mcp-ai-core' )
			);
		}

		$post = get_post( absint( $arguments['post_id'] ) );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'wp-mcp-ai-core' )
			);
		}

		return $this->format_post( $post );
	}

	/**
	 * List posts.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function list_posts( $arguments ) {
		$query_args = $this->build_query_args( $arguments );
		$query      = new WP_Query( $query_args );

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = $this->format_post( $post );
		}

		return array(
			'posts'       => $posts,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
			'page'        => isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1,
		);
	}

	/**
	 * Search posts.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function search_posts( $arguments ) {
		if ( empty( $arguments['search'] ) ) {
			return new WP_Error(
				'missing_search_term',
				__( 'Search term is required for search action.', 'wp-mcp-ai-core' )
			);
		}

		$arguments['action'] = 'list';
		return $this->list_posts( $arguments );
	}

	/**
	 * Create a new post.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function create_post( $arguments, $context ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to create posts.', 'wp-mcp-ai-core' )
			);
		}

		$post_data = array(
			'post_author' => $user_id,
			'post_type'   => isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post',
			'post_status' => isset( $arguments['post_status'] ) ? sanitize_key( $arguments['post_status'] ) : 'draft',
		);

		if ( ! empty( $arguments['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['title'] );
		}

		if ( ! empty( $arguments['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $arguments['content'] );
		}

		if ( ! empty( $arguments['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $arguments['excerpt'] );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return $this->format_post( get_post( $post_id ) );
	}

	/**
	 * Update an existing post.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function update_post( $arguments, $context ) {
		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error(
				'missing_post_id',
				__( 'Post ID is required for update action.', 'wp-mcp-ai-core' )
			);
		}

		$post_id = absint( $arguments['post_id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'wp-mcp-ai-core' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to edit this post.', 'wp-mcp-ai-core' )
			);
		}

		$post_data = array( 'ID' => $post_id );

		if ( ! empty( $arguments['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['title'] );
		}

		if ( ! empty( $arguments['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $arguments['content'] );
		}

		if ( ! empty( $arguments['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $arguments['excerpt'] );
		}

		if ( ! empty( $arguments['post_status'] ) ) {
			$post_data['post_status'] = sanitize_key( $arguments['post_status'] );
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->format_post( get_post( $post_id ) );
	}

	/**
	 * Delete a post.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function delete_post( $arguments, $context ) {
		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error(
				'missing_post_id',
				__( 'Post ID is required for delete action.', 'wp-mcp-ai-core' )
			);
		}

		$post_id = absint( $arguments['post_id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'wp-mcp-ai-core' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'delete_post', $post_id ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to delete this post.', 'wp-mcp-ai-core' )
			);
		}

		$result = wp_trash_post( $post_id );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete post.', 'wp-mcp-ai-core' )
			);
		}

		return array(
			'deleted' => true,
			'post_id' => $post_id,
		);
	}

	/**
	 * Build WP_Query arguments from tool arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function build_query_args( $arguments ) {
		$query_args = array(
			'post_type'      => isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post',
			'post_status'    => isset( $arguments['post_status'] ) ? sanitize_key( $arguments['post_status'] ) : 'publish',
			'posts_per_page' => isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 10,
			'paged'          => isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1,
			'orderby'        => isset( $arguments['orderby'] ) ? sanitize_key( $arguments['orderby'] ) : 'date',
			'order'          => isset( $arguments['order'] ) ? strtoupper( sanitize_key( $arguments['order'] ) ) : 'DESC',
		);

		if ( ! empty( $arguments['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $arguments['search'] );
		}

		if ( ! empty( $arguments['author'] ) ) {
			$query_args['author'] = absint( $arguments['author'] );
		}

		return $query_args;
	}

	/**
	 * Format a post for output.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	protected function format_post( $post ) {
		return array(
			'id'             => $post->ID,
			'title'          => get_the_title( $post ),
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'status'         => $post->post_status,
			'type'           => $post->post_type,
			'author'         => absint( $post->post_author ),
			'date'           => $post->post_date,
			'date_gmt'       => $post->post_date_gmt,
			'modified'       => $post->post_modified,
			'modified_gmt'   => $post->post_modified_gmt,
			'slug'           => $post->post_name,
			'permalink'      => get_permalink( $post ),
			'featured_image' => get_post_thumbnail_id( $post ),
		);
	}
}
