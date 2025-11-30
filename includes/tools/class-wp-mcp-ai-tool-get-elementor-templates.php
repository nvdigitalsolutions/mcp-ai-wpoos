<?php
/**
 * Tool returning Elementor templates.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides Elementor template listings with metadata suitable for assistants.
 */
class WP_MCP_AI_Tool_Get_Elementor_Templates implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether Elementor (or Elementor Pro) is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$has_elementor = defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin', false );
		$has_pro       = defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\\ElementorPro\\Plugin', false );

		// Elementor Pro relies on Elementor, so either Elementor alone or both Elementor and Pro qualify.
		return ( $has_elementor || $has_pro ) && post_type_exists( 'elementor_library' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Elementor Templates tool is disabled because Elementor is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_elementor_templates';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Elementor Templates', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns Elementor template library entries with type, status, and edit links. Requires Elementor (free or Pro).', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of templates to return.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'status'        => array(
					'description' => __( 'Optional post status or array of statuses to filter by (e.g. publish, draft).', 'wp-mcp-ai' ),
					'anyOf'       => array(
						array(
							'type' => 'string',
						),
						array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
							),
						),
					),
				),
				'template_type' => array(
					'type'        => 'string',
					'description' => __( 'Optional Elementor template type to filter by (e.g. header, footer, popup, page, section).', 'wp-mcp-ai' ),
				),
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Optional search term to match against template titles.', 'wp-mcp-ai' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_elementor_missing', __( 'Elementor is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view Elementor templates.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$post_type_object = get_post_type_object( 'elementor_library' );

		if ( ! $post_type_object ) {
			return new WP_Error( 'wp_mcp_ai_missing_post_type', __( 'The Elementor template library post type is not registered.', 'wp-mcp-ai' ) );
		}

		$required_capability = isset( $post_type_object->cap->edit_posts ) ? $post_type_object->cap->edit_posts : 'edit_posts';

		if ( ! user_can( $user_id, $required_capability ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view Elementor templates.', 'wp-mcp-ai' ) );
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$limit = $limit > 0 ? min( $limit, 50 ) : 10;

		$statuses = $this->prepare_status_filters( $arguments );

		$query_args = array(
			'post_type'              => 'elementor_library',
			'posts_per_page'         => $limit,
			'post_status'            => ! empty( $statuses ) ? $statuses : array( 'publish', 'draft', 'pending', 'private' ),
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'suppress_filters'       => false,
			'update_post_term_cache' => false, // Performance: Skip term cache for templates.
			'update_post_meta_cache' => true,  // Keep meta cache as we use template type meta.
		);

		if ( ! empty( $arguments['search'] ) && is_string( $arguments['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $arguments['search'] );
		}

		if ( ! empty( $arguments['template_type'] ) && is_string( $arguments['template_type'] ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => '_elementor_template_type',
					'value'   => sanitize_key( $arguments['template_type'] ),
					'compare' => '=',
				),
			);
		}

		$query = new WP_Query( $query_args );

		$results = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$template_type = get_post_meta( $post->ID, '_elementor_template_type', true );

				$results[] = array(
					'id'            => $post->ID,
					'title'         => get_the_title( $post ),
					'status'        => $post->post_status,
					'template_type' => $template_type ? sanitize_key( $template_type ) : '',
					'edit_link'     => get_edit_post_link( $post->ID, 'raw' ),
					'permalink'     => get_permalink( $post ),
					'date_created'  => $this->format_post_datetime( $post->post_date_gmt, $post->post_date ),
					'date_modified' => $this->format_post_datetime( $post->post_modified_gmt, $post->post_modified ),
					'author'        => array(
						'id'   => (int) $post->post_author,
						'name' => get_the_author_meta( 'display_name', $post->post_author ),
					),
				);
			}

			wp_reset_postdata();
		}

		return $results;
	}

	/**
	 * Prepare an array of allowed post statuses from the provided arguments.
	 *
	 * @param array $arguments Tool execution arguments.
	 * @return array<int, string>
	 */
	protected function prepare_status_filters( array $arguments ) {
		if ( empty( $arguments['status'] ) ) {
			return array();
		}

		$statuses = array();
		$allowed  = get_post_stati( array(), 'names' );

		if ( is_array( $arguments['status'] ) ) {
			foreach ( $arguments['status'] as $status ) {
				$status = sanitize_key( $status );
				if ( in_array( $status, $allowed, true ) ) {
					$statuses[] = $status;
				}
			}
		} elseif ( is_string( $arguments['status'] ) ) {
			$status = sanitize_key( $arguments['status'] );
			if ( in_array( $status, $allowed, true ) ) {
				$statuses[] = $status;
			}
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Format post datetime fields into ISO8601 strings.
	 *
	 * @param string $gmt_date GMT datetime string.
	 * @param string $local_date Local datetime string.
	 * @return string|null
	 */
	protected function format_post_datetime( $gmt_date, $local_date ) {
		$datetime = '';

		if ( ! empty( $gmt_date ) ) {
			$datetime = $gmt_date;
		} elseif ( ! empty( $local_date ) ) {
			$converted = get_gmt_from_date( $local_date );
			$datetime  = $converted ? $converted : $local_date;
		}

		if ( empty( $datetime ) ) {
			return null;
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		if ( ! $timestamp ) {
			return null;
		}

		return gmdate( DATE_W3C, $timestamp );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
