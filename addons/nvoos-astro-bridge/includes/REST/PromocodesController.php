<?php
/**
 * Promocodes REST controller.
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\Schema;
use NvoosAstroBridge\Transformers\PromocodeTransformer;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles /funiq/v1/promocodes.
 */
class PromocodesController extends PostTypeController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'funiq/v1';

	/**
	 * REST base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'promocodes';

	/**
	 * Get the post type slug.
	 *
	 * @return string
	 */
	protected function post_type(): string {
		return Schema::CPT_PROMOCODE;
	}

	/**
	 * Transform a WP_Post into the output shape.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	protected function transform( WP_Post $post ): array {
		return ( new PromocodeTransformer() )->transform( $post );
	}

	/**
	 * Register routes — CRUD (inherited) plus the public validation lookup.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/validate',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'validate_code' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'code' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * GET /promocodes/validate?code=… — public, idempotent read.
	 *
	 * Returns the promocode document when it exists, is active and has not
	 * expired; 404/422 otherwise. Case-insensitive on the code.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_code( $request ) {
		$code = (string) $request->get_param( 'code' );

		$query = new WP_Query(
			array(
				'post_type'      => Schema::CPT_PROMOCODE,
				'post_status'    => 'publish',
				's'              => $code,
				'posts_per_page' => 20,
			)
		);

		$match = null;
		foreach ( $query->posts as $post ) {
			if ( 0 === strcasecmp( (string) $post->post_title, $code ) ) {
				$match = $post;
				break;
			}
		}

		if ( null === $match ) {
			return new WP_Error(
				'nvoos_promocode_not_found',
				'Promo code not found.',
				array( 'status' => 404 )
			);
		}

		$is_active = (bool) get_post_meta( $match->ID, Schema::META_PROMOCODE_IS_ACTIVE, true );
		if ( ! $is_active ) {
			return new WP_Error(
				'nvoos_promocode_inactive',
				'This promo code is no longer active.',
				array( 'status' => 422 )
			);
		}

		$expires = get_post_meta( $match->ID, Schema::META_PROMOCODE_EXPIRES_AT, true );
		if ( $expires && strtotime( (string) $expires ) < time() ) {
			return new WP_Error(
				'nvoos_promocode_expired',
				'This promo code has expired.',
				array( 'status' => 422 )
			);
		}

		return rest_ensure_response( $this->transform( $match ) );
	}

	/**
	 * Build post insert/update args from request data.
	 *
	 * @param array<string, mixed> $data Request body.
	 * @param int|null             $id   Post ID (null for create).
	 * @return array<string, mixed>
	 */
	protected function post_args( array $data, ?int $id ): array {
		$args = array(
			'post_type' => Schema::CPT_PROMOCODE,
		);

		if ( null !== $id ) {
			$args['ID'] = $id;
		}

		if ( isset( $data['code'] ) ) {
			$args['post_title'] = sanitize_text_field( $data['code'] );
		}

		return $args;
	}

	/**
	 * Save meta fields after creating/updating the post.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $data    Request body.
	 * @return void
	 */
	protected function save_meta( int $post_id, array $data ): void {
		$fields = array(
			Schema::META_PROMOCODE_DISCOUNT   => 'discount',
			Schema::META_PROMOCODE_EXPIRES_AT => 'expiresAt',
			Schema::META_PROMOCODE_IS_ACTIVE  => 'isActive',
			Schema::META_PROMOCODE_NAME       => 'name',
			Schema::META_PROMOCODE_TITLE      => 'title',
			Schema::META_PROMOCODE_LOGO       => 'logo',
		);

		foreach ( $fields as $key => $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$value = $data[ $field ];

			if ( 'discount' === $field ) {
				$value = (int) $value;
			} elseif ( 'isActive' === $field ) {
				$value = (bool) $value;
			} elseif ( 'logo' === $field && is_numeric( $value ) ) {
				$value = (int) $value;
			}

			update_post_meta( $post_id, $key, $value );
		}
	}
}
