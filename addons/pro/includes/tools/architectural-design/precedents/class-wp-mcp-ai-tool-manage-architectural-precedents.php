<?php
/**
 * Tool — Manage Architectural Precedents.
 *
 * CRUD interface to the `mcp_ai_arch_prec` CPT. On every create /
 * update the tool regenerates the cached embedding via
 * `WP_MCP_AI_Architectural_Precedents_Engine` so the search tool always has
 * a current vector to compare against.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Manage architectural precedents.
 */
class WP_MCP_AI_Tool_Manage_Architectural_Precedents implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */

	// phpcs:ignore Squiz.Commenting.FunctionComment.WrongStyle
	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_architectural_design_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'manage_architectural_precedents';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Manage Architectural Precedents', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'List / get / create / update / delete architectural precedents (built case studies). On create / update the tool regenerates the cached OpenAI embedding so search_architectural_precedents can perform cosine-similarity semantic search.', 'mcp-ai-wpoos-pro' );
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
				'action'                => array(
					'type' => 'string',
					'enum' => array( 'list', 'get', 'create', 'update', 'delete' ),
				),
				'precedent_id'          => array( 'type' => 'integer' ),
				'title'                 => array( 'type' => 'string' ),
				'description'           => array( 'type' => 'string' ),
				'excerpt'               => array( 'type' => 'string' ),
				'country_code'          => array( 'type' => 'string' ),
				'building_type'         => array( 'type' => 'string' ),
				'climate_zone'          => array( 'type' => 'string' ),
				'sustainability_rating' => array( 'type' => 'string' ),
				'architect'             => array( 'type' => 'string' ),
				'year_completed'        => array( 'type' => 'integer' ),
				'area_m2'               => array( 'type' => 'number' ),
				'references_url'        => array( 'type' => 'string' ),
				'key_features'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'limit'                 => array(
					'type'        => 'integer',
					'description' => 'Max results for list action (default 50, max 200).',
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'write', 'state-changing' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage precedents.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Precedents_Engine' )
			|| ! class_exists( 'WP_MCP_AI_Architectural_Precedent_CPT' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Precedent engine is unavailable.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_type = WP_MCP_AI_Architectural_Precedent_CPT::POST_TYPE;
		$action    = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';

		switch ( $action ) {
			case 'list':
				$limit = isset( $arguments['limit'] ) ? max( 1, min( 200, (int) $arguments['limit'] ) ) : 50;
				$query = new WP_Query(
					array(
						'post_type'      => $post_type,
						'post_status'    => array( 'publish', 'draft', 'private' ),
						'posts_per_page' => $limit,
						'orderby'        => 'modified',
						'order'          => 'DESC',
						'no_found_rows'  => true,
					)
				);
				$items = array();
				foreach ( $query->posts as $p ) {
					$items[] = $this->serialize_post( $p );
				}
				return array(
					'success'    => true,
					'count'      => count( $items ),
					'precedents' => $items,
				);

			case 'get':
				$post_id = isset( $arguments['precedent_id'] ) ? absint( $arguments['precedent_id'] ) : 0;
				$post    = $this->require_precedent( $post_id );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				return array(
					'success'   => true,
					'precedent' => $this->serialize_post( $post ),
				);

			case 'create':
				if ( empty( $arguments['title'] ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'title is required.', 'mcp-ai-wpoos-pro' ) );
				}
				$post_id = wp_insert_post(
					array(
						'post_type'    => $post_type,
						'post_status'  => 'publish',
						'post_title'   => sanitize_text_field( $arguments['title'] ),
						'post_content' => isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '',
						'post_excerpt' => isset( $arguments['excerpt'] ) ? sanitize_text_field( $arguments['excerpt'] ) : '',
						'post_author'  => $user_id,
					),
					true
				);
				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}
				$this->apply_meta( $post_id, $arguments );
				WP_MCP_AI_Architectural_Precedents_Engine::regenerate_embedding_for_post( $post_id );
				return array(
					'success'   => true,
					'precedent' => $this->serialize_post( get_post( $post_id ) ),
				);

			case 'update':
				$post_id = isset( $arguments['precedent_id'] ) ? absint( $arguments['precedent_id'] ) : 0;
				$post    = $this->require_precedent( $post_id );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit this precedent.', 'mcp-ai-wpoos-pro' ) );
				}
				$update = array( 'ID' => $post_id );
				if ( isset( $arguments['title'] ) ) {
					$update['post_title'] = sanitize_text_field( $arguments['title'] );
				}
				if ( isset( $arguments['description'] ) ) {
					$update['post_content'] = wp_kses_post( $arguments['description'] );
				}
				if ( isset( $arguments['excerpt'] ) ) {
					$update['post_excerpt'] = sanitize_text_field( $arguments['excerpt'] );
				}
				if ( count( $update ) > 1 ) {
					$result = wp_update_post( $update, true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				$this->apply_meta( $post_id, $arguments );
				WP_MCP_AI_Architectural_Precedents_Engine::regenerate_embedding_for_post( $post_id );
				return array(
					'success'   => true,
					'precedent' => $this->serialize_post( get_post( $post_id ) ),
				);

			case 'delete':
				$post_id = isset( $arguments['precedent_id'] ) ? absint( $arguments['precedent_id'] ) : 0;
				$post    = $this->require_precedent( $post_id );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				if ( ! current_user_can( 'delete_post', $post_id ) ) {
					return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete this precedent.', 'mcp-ai-wpoos-pro' ) );
				}
				$deleted = wp_delete_post( $post_id, true );
				if ( ! $deleted ) {
					return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete precedent.', 'mcp-ai-wpoos-pro' ) );
				}
				return array(
					'success'    => true,
					'deleted_id' => $post_id,
				);
		}

		return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'Unknown action.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Apply optional meta fields from arguments.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    Arguments.
	 * @return void
	 */
	private function apply_meta( $post_id, array $args ) {
		$string_map = array(
			'country_code'          => '_arch_prec_country_code',
			'building_type'         => '_arch_prec_building_type',
			'climate_zone'          => '_arch_prec_climate_zone',
			'sustainability_rating' => '_arch_prec_sustainability_rating',
			'architect'             => '_arch_prec_architect',
		);
		foreach ( $string_map as $arg => $meta ) {
			if ( ! isset( $args[ $arg ] ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $args[ $arg ] );
			if ( 'country_code' === $arg ) {
				$value = strtoupper( $value );
			}
			update_post_meta( $post_id, $meta, $value );
			if ( 'country_code' === $arg ) {
				wp_set_object_terms(
					$post_id,
					strtolower( $value ),
					WP_MCP_AI_Architectural_Precedent_CPT::TAX_COUNTRY,
					false
				);
			}
			if ( 'building_type' === $arg ) {
				wp_set_object_terms(
					$post_id,
					sanitize_key( $value ),
					WP_MCP_AI_Architectural_Precedent_CPT::TAX_BUILDING_TYPE,
					false
				);
			}
		}
		if ( isset( $args['references_url'] ) ) {
			update_post_meta( $post_id, '_arch_prec_references_url', esc_url_raw( (string) $args['references_url'] ) );
		}
		if ( isset( $args['year_completed'] ) ) {
			update_post_meta( $post_id, '_arch_prec_year_completed', absint( $args['year_completed'] ) );
		}
		if ( isset( $args['area_m2'] ) ) {
			update_post_meta( $post_id, '_arch_prec_area_m2', max( 0.0, (float) $args['area_m2'] ) );
		}
		if ( isset( $args['key_features'] ) && is_array( $args['key_features'] ) ) {
			$features = array_map( 'sanitize_text_field', array_map( 'strval', $args['key_features'] ) );
			update_post_meta( $post_id, '_arch_prec_key_features', $features );
		}
	}

	/**
	 * Resolve a precedent post id, ensuring it is the right CPT.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|WP_Error
	 */
	private function require_precedent( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'precedent_id is required.', 'mcp-ai-wpoos-pro' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post || WP_MCP_AI_Architectural_Precedent_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Precedent not found.', 'mcp-ai-wpoos-pro' ) );
		}
		return $post;
	}

	/**
	 * Serialize a precedent post for tool output.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function serialize_post( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		$features = get_post_meta( $post->ID, '_arch_prec_key_features', true );
		return array(
			'id'                    => $post->ID,
			'title'                 => $post->post_title,
			'excerpt'               => $post->post_excerpt,
			'description'           => $post->post_content,
			'country_code'          => (string) get_post_meta( $post->ID, '_arch_prec_country_code', true ),
			'building_type'         => (string) get_post_meta( $post->ID, '_arch_prec_building_type', true ),
			'climate_zone'          => (string) get_post_meta( $post->ID, '_arch_prec_climate_zone', true ),
			'sustainability_rating' => (string) get_post_meta( $post->ID, '_arch_prec_sustainability_rating', true ),
			'architect'             => (string) get_post_meta( $post->ID, '_arch_prec_architect', true ),
			'year_completed'        => (int) get_post_meta( $post->ID, '_arch_prec_year_completed', true ),
			'area_m2'               => (float) get_post_meta( $post->ID, '_arch_prec_area_m2', true ),
			'references_url'        => (string) get_post_meta( $post->ID, '_arch_prec_references_url', true ),
			'key_features'          => is_array( $features ) ? $features : array(),
			'has_embedding'         => ! empty( get_post_meta( $post->ID, '_arch_prec_embedding', true ) ),
			'updated_at'            => $post->post_modified_gmt,
		);
	}
}
