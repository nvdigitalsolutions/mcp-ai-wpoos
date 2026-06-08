<?php
/**
 * Banner REST controller — handles the global banner singleton.
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
 * Handles GET/PUT /funiq/v1/globals/banner.
 *
 * GET is public; PUT requires manage_funiq capability.
 */
class BannerController extends WP_REST_Controller {

	/** @var string */
	protected $namespace = 'funiq/v1';

	/** @var string */
	protected $rest_base = 'globals/banner';

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
	 * GET /funiq/v1/globals/banner
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$banner = get_option( Schema::OPTION_BANNER, Schema::banner_defaults() );

		return rest_ensure_response( $this->transform( $banner ) );
	}

	/**
	 * PUT /funiq/v1/globals/banner
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_item( $request ) {
		$data = $request->get_json_params();

		update_option(
			Schema::OPTION_BANNER,
			array(
				'image'     => isset( $data['image'] ) ? ( is_numeric( $data['image'] ) ? (int) $data['image'] : null ) : null,
				'promotion' => isset( $data['promotion'] ) ? ( is_numeric( $data['promotion'] ) ? (int) $data['promotion'] : null ) : null,
			)
		);

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
	 * @param array{image: ?int, promotion: ?int} $banner Raw option.
	 * @return array{image: string, promotion: array<string, mixed>|null}
	 */
	private function transform( array $banner ): array {
		$image_url = '';
		if ( ! empty( $banner['image'] ) ) {
			$image_url = wp_get_attachment_url( (int) $banner['image'] ) ?: '';
		}

		$promotion = null;
		if ( ! empty( $banner['promotion'] ) ) {
			$p = get_post( (int) $banner['promotion'] );
			if ( $p && Schema::CPT_PROMOTION === $p->post_type ) {
				$promotion = ( new PromotionTransformer() )->transform( $p );
			}
		}

		return array(
			'image'     => $image_url,
			'promotion' => $promotion,
		);
	}
}
