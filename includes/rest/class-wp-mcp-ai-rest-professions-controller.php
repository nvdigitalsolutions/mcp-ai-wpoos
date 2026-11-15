<?php
/**
 * REST controller for the profession catalogue.
 *
 * Servers the console's profession selector and page (Proposal 090-g Phase 4,
 * WordPress parity in 090-p W1). The Laravel runtime already exposes
 * `GET /api/v1/professions[/{slug}]`; this is the WordPress twin, so the
 * console's `professions` endpoint is no longer blank on WordPress.
 *
 * Read-only by design: the catalogue is code/seeder-owned reference data.
 * `save_profession` is a tool, not a REST surface.
 *
 * @package WP_MCP_AI
 * @since   1.2.22
 * @license GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profession catalogue REST controller.
 */
class WP_MCP_AI_REST_Professions_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/professions';

	/**
	 * Register the catalogue routes.
	 *
	 * Both routes require `manage_options`, matching the Laravel
	 * `can:manage_options` group and the console's admin gating.
	 *
	 * @since 1.2.22
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/(?P<slug>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'slug' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_title',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check — the catalogue is an admin surface.
	 *
	 * @since 1.2.22
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to read the profession catalogue.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * GET /professions — the catalogue list.
	 *
	 * Shape mirrors `ProfessionController@index` on Laravel:
	 * `{ professions, total, catalogue_version }`.
	 *
	 * @since 1.2.22
	 * @return WP_REST_Response
	 */
	public function index() {
		$repository = new WP_MCP_AI_Profession_Repository();
		// The repository disables the meta cache for dropdown queries; the list
		// reads six meta keys per row, so enable it for this call.
		$posts   = $repository->find_all( array( 'update_post_meta_cache' => true ) );
		$items   = array();
		$version = 0;

		foreach ( $posts as $post ) {
			$items[] = $this->summary( $post );

			$modified = (int) strtotime( $post->post_modified_gmt . ' UTC' );
			$version  = max( $version, $modified );
		}

		return rest_ensure_response(
			array(
				'professions'       => $items,
				'total'             => count( $items ),
				'catalogue_version' => $version,
			)
		);
	}

	/**
	 * GET /professions/{slug} — one profession with its creation details.
	 *
	 * Shape mirrors `ProfessionController@show` on Laravel:
	 * `{ success: true, data: { … } }`.
	 *
	 * @since 1.2.22
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( $request ) {
		$slug       = (string) $request['slug'];
		$repository = new WP_MCP_AI_Profession_Repository();
		$post       = $repository->find_one( $slug );

		if ( ! $post ) {
			return new WP_Error(
				'profession_not_found',
				__( 'Profession not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		$data = array_merge(
			$this->summary( $post ),
			array(
				'role_description'  => (string) get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_ROLE_DESCRIPTION, true ),
				'warnings'          => $this->to_string_list( get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_WARNINGS, true ) ),
				'knowledge_base'    => (string) get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, true ),
				'recommended_tools' => $this->to_string_list( get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true ) ),
			)
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * The list row — the same fields the Laravel controller returns.
	 *
	 * @param WP_Post $post Profession post.
	 * @return array<string, mixed>
	 */
	private function summary( $post ) {
		$category = (string) get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );
		$title    = get_the_title( $post );

		return array(
			'slug'          => (string) $post->post_name,
			'title'         => '' !== $title ? $title : (string) $post->post_name,
			'category'      => $this->category_label( $category ),
			'category_slug' => $category,
			'description'   => (string) $post->post_content,
			'expertise'     => $this->to_string_list( get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true ) ),
			'tools_count'   => count( $this->to_string_list( get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true ) ) ),
		);
	}

	/**
	 * Normalise a meta value to a list of strings.
	 *
	 * The seeders write arrays; a hand-authored profession may carry a
	 * comma-separated string. Both render as a list on the console.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string[]
	 */
	private function to_string_list( $value ) {
		if ( is_array( $value ) ) {
			return array_values( array_map( 'strval', $value ) );
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return array_values(
				array_filter(
					array_map( 'trim', explode( ',', $value ) ),
					static function ( $entry ) {
						return '' !== $entry;
					}
				)
			);
		}

		return array();
	}

	/**
	 * Human label for a category slug (same map as the teams controller).
	 *
	 * @param string $slug Category slug.
	 * @return string
	 */
	private function category_label( $slug ) {
		$labels = array(
			'advisory'   => 'Advisory/Consulting',
			'creative'   => 'Creative Services',
			'technical'  => 'Technical',
			'healthcare' => 'Healthcare',
			'legal'      => 'Legal',
			'financial'  => 'Financial',
			'other'      => 'Other',
		);

		return $labels[ $slug ] ?? ( '' === $slug ? '' : ucfirst( $slug ) );
	}
}
