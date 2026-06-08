<?php
/**
 * Carousel REST controller — handles the global carousel singleton.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Schema;
use FuniqBridge\Transformers\PromotionTransformer;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles GET/PUT /funiq/v1/globals/carousel.
 *
 * GET is public; PUT requires manage_funiq capability.
 */
class CarouselController extends WP_REST_Controller {

	/** @var string */
	protected $namespace = 'funiq/v1';

	/** @var string */
	protected $rest_base = 'globals/carousel';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * GET /funiq/v1/globals/carousel
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$carousel = get_option( Schema::OPTION_CAROUSEL, Schema::carousel_defaults() );

		return rest_ensure_response( $this->transform( $carousel ) );
	}

	/**
	 * PUT /funiq/v1/globals/carousel
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_item( $request ) {
		$data = $request->get_json_params();

		// Sanitize the carousel array.
		$items = array();
		if ( isset( $data['carousel'] ) && is_array( $data['carousel'] ) ) {
			foreach ( $data['carousel'] as $item ) {
				$items[] = array(
					'image'     => isset( $item['image'] ) && is_numeric( $item['image'] )
						? (int) $item['image'] : null,
					'promotion' => isset( $item['promotion'] ) && is_numeric( $item['promotion'] )
						? (int) $item['promotion'] : null,
				);
			}
		}

		update_option( Schema::OPTION_CAROUSEL, array( 'carousel' => $items ) );

		return $this->get_item( $request );
	}

	/**
	 * Permission check for PUT.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		if ( ! current_user_can( Schema::CAP_MANAGE_FUNIQ ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'Sorry, you are not allowed to do that.',
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Transform the raw option into the API response.
	 *
	 * @param array{carousel: list<array{image: ?int, promotion: ?int}>} $carousel Raw option.
	 * @return array{carousel: list<array{image: string, promotion: array<string, mixed>|null}>}
	 */
	private function transform( array $carousel ): array {
		$items = array();

		foreach ( (array) ( $carousel['carousel'] ?? array() ) as $item ) {
			$image_url = '';
			if ( ! empty( $item['image'] ) ) {
				$image_url = wp_get_attachment_url( (int) $item['image'] ) ?: '';
			}

			$promotion = null;
			if ( ! empty( $item['promotion'] ) ) {
				$p = get_post( (int) $item['promotion'] );
				if ( $p && Schema::CPT_PROMOTION === $p->post_type ) {
					$promotion = ( new PromotionTransformer() )->transform( $p );
				}
			}

			$items[] = array(
				'image'     => $image_url,
				'promotion' => $promotion,
			);
		}

		return array( 'carousel' => $items );
	}
}
