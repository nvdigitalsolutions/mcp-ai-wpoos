<?php
/**
 * Blueprint REST Controller.
 *
 * CRUD endpoints for blueprint records at
 * `nvoos-content-graph/v1/platform/blueprints`.
 *
 * Read operations require `edit_posts`; write operations require
 * `manage_options` (blueprints are deployable agent definitions).
 *
 * @package NvoosContentGraphAiPlatform\Blueprints
 * @since 2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Blueprints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for blueprints.
 */
final class BlueprintRestController {

	/**
	 * Route namespace.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public const NAMESPACE = 'nvoos-content-graph/v1/platform/blueprints';

	/**
	 * Register routes on rest_api_init.
	 *
	 * @since 2.0.0
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the blueprint routes.
	 *
	 * @since 2.0.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => static function (): bool {
						return current_user_can( 'edit_posts' );
					},
					'args'                => array(
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'kind'     => array(
							'type'              => 'string',
							'default'           => '',
							'enum'              => array_merge( array( '' ), BlueprintValidator::KINDS ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => static function (): bool {
						return current_user_can( 'manage_options' );
					},
					'args'                => $this->write_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => static function (): bool {
						return current_user_can( 'edit_posts' );
					},
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => static function (): bool {
						return current_user_can( 'manage_options' );
					},
					'args'                => $this->write_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => static function (): bool {
						return current_user_can( 'manage_options' );
					},
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * List blueprints.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_items( \WP_REST_Request $request ): \WP_REST_Response {
		$args = array(
			'posts_per_page' => $request->get_param( 'per_page' ),
			'paged'          => $request->get_param( 'page' ),
		);

		$kind = $request->get_param( 'kind' );
		$kind = is_string( $kind ) ? $kind : '';
		if ( '' !== $kind ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Kind filtering on a small CPT; acceptable for blueprint lists.
				array(
					'key'   => BlueprintRegistry::META_KIND,
					'value' => $kind,
				),
			);
		}

		$registry   = new BlueprintRegistry();
		$blueprints = $registry->all( $args );

		return new \WP_REST_Response( array( 'blueprints' => $blueprints ), 200 );
	}

	/**
	 * Get one blueprint.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $request ) {
		$registry  = new BlueprintRegistry();
		$blueprint = $registry->get( $request->get_param( 'id' ) );

		if ( null === $blueprint ) {
			return new \WP_Error( 'blueprint_not_found', __( 'Blueprint not found.', 'nvoos-content-graph-ai-platform' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $blueprint, 200 );
	}

	/**
	 * Create a blueprint.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( \WP_REST_Request $request ) {
		$payload = $this->read_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$registry = new BlueprintRegistry();
		$created  = $registry->create(
			array(
				'title'       => $payload['title'],
				'slug'        => isset( $payload['slug'] ) ? $payload['slug'] : '',
				'description' => isset( $payload['description'] ) ? $payload['description'] : '',
				'definition'  => $payload['definition'],
			)
		);

		if ( is_wp_error( $created ) ) {
			return new \WP_Error( $created->get_error_code(), $created->get_error_message(), array( 'status' => 400 ) );
		}

		$blueprint = $registry->get( $created );

		return new \WP_REST_Response( $blueprint, 201 );
	}

	/**
	 * Update a blueprint.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( \WP_REST_Request $request ) {
		$payload = $this->read_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$registry = new BlueprintRegistry();
		$updated  = $registry->update( $request->get_param( 'id' ), $payload );

		if ( is_wp_error( $updated ) ) {
			return new \WP_Error( $updated->get_error_code(), $updated->get_error_message(), array( 'status' => 400 ) );
		}

		$blueprint = $registry->get( $updated );

		return new \WP_REST_Response( $blueprint, 200 );
	}

	/**
	 * Delete a blueprint.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( \WP_REST_Request $request ) {
		$registry = new BlueprintRegistry();
		$deleted  = $registry->delete( $request->get_param( 'id' ) );

		if ( ! $deleted ) {
			return new \WP_Error( 'blueprint_not_found', __( 'Blueprint not found.', 'nvoos-content-graph-ai-platform' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Shared write args schema.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, array>
	 */
	private function write_args(): array {
		return array(
			'title'       => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug'        => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_title',
			),
			'description' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'wp_kses_post',
			),
			'definition'  => array(
				'type'     => 'object',
				'required' => true,
			),
		);
	}

	/**
	 * Read and validate a write payload.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error Payload array or WP_Error with status.
	 */
	private function read_payload( \WP_REST_Request $request ) {
		$definition = $request->get_param( 'definition' );
		if ( ! is_array( $definition ) ) {
			return new \WP_Error( 'blueprint_invalid_definition', __( 'Blueprint definition must be an object.', 'nvoos-content-graph-ai-platform' ), array( 'status' => 400 ) );
		}

		$validator = new BlueprintValidator();
		$check     = $validator->validate( $definition );
		if ( is_wp_error( $check ) ) {
			return new \WP_Error( $check->get_error_code(), $check->get_error_message(), array( 'status' => 400 ) );
		}

		return array(
			'title'       => $request->get_param( 'title' ),
			'slug'        => $request->get_param( 'slug' ),
			'description' => $request->get_param( 'description' ),
			'definition'  => $definition,
		);
	}
}
