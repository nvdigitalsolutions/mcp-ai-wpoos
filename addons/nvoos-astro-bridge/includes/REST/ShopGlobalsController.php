<?php
/**
 * Shop globals REST controller — checkout handoff + currency display hints
 * (Proposal 106 §1.3).
 *
 * GET is public; PATCH is gated by the manage capability. Same short cache
 * TTL (s-maxage=60) as the theme policy doc.
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles GET/PATCH /funiq/v1/globals/shop.
 */
class ShopGlobalsController extends BaseController {

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
	protected $rest_base = 'globals/shop';

	/**
	 * PATCHable fields — anything else is rejected with a typed 400.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = array( 'checkoutUrl', 'cartUrl', 'currency', 'currencySymbol' );

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

		// Short cache TTL (60s) for this route — priority 20 so it overrides
		// the B1 filter (priority 10) after rest_post_dispatch.
		add_filter(
			'rest_post_dispatch',
			array( BaseController::class, 'set_short_cache_headers_on_public_gets' ),
			20,
			3
		);
	}

	/**
	 * GET /funiq/v1/globals/shop — the normalized shop doc.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$stored = get_option( Schema::OPTION_SHOP, Schema::shop_defaults() );
		$stored = is_array( $stored ) ? $stored : Schema::shop_defaults();

		return rest_ensure_response( $this->normalize_doc( $stored ) );
	}

	/**
	 * PATCH /funiq/v1/globals/shop — partial update, full doc back.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'astro_shop_invalid_body',
				'A JSON object body is required.',
				array( 'status' => 400 )
			);
		}

		foreach ( array_keys( $data ) as $key ) {
			if ( ! in_array( $key, self::FIELDS, true ) ) {
				return new WP_Error(
					'astro_shop_invalid_field',
					sprintf( 'Unknown field "%s".', sanitize_text_field( (string) $key ) ),
					array( 'status' => 400 )
				);
			}
		}

		foreach ( array( 'checkoutUrl', 'cartUrl' ) as $field ) {
			if ( isset( $data[ $field ] ) && ! $this->is_valid_http_url( $data[ $field ] ) ) {
				$code = 'checkoutUrl' === $field ? 'astro_shop_invalid_checkout_url' : 'astro_shop_invalid_cart_url';
				return new WP_Error(
					$code,
					sprintf( '%s must be an absolute http(s) URL.', $field ),
					array( 'status' => 400 )
				);
			}
		}
		if ( isset( $data['currency'] ) && ( ! is_string( $data['currency'] ) || 1 !== preg_match( '/^[A-Za-z]{3}$/', $data['currency'] ) ) ) {
			return new WP_Error(
				'astro_shop_invalid_currency',
				'currency must be a 3-letter ISO code.',
				array( 'status' => 400 )
			);
		}
		if ( isset( $data['currencySymbol'] ) && ( ! is_string( $data['currencySymbol'] ) || '' === $data['currencySymbol'] || strlen( $data['currencySymbol'] ) > 8 ) ) {
			return new WP_Error(
				'astro_shop_invalid_currency_symbol',
				'currencySymbol must be a short non-empty string.',
				array( 'status' => 400 )
			);
		}

		$stored = get_option( Schema::OPTION_SHOP, Schema::shop_defaults() );
		$stored = is_array( $stored ) ? $stored : Schema::shop_defaults();
		$doc    = $this->normalize_doc( $stored );

		if ( isset( $data['checkoutUrl'] ) ) {
			$doc['checkoutUrl'] = esc_url_raw( $data['checkoutUrl'] );
		}
		if ( isset( $data['cartUrl'] ) ) {
			$doc['cartUrl'] = esc_url_raw( $data['cartUrl'] );
		}
		if ( isset( $data['currency'] ) ) {
			$doc['currency'] = strtoupper( sanitize_text_field( $data['currency'] ) );
		}
		if ( isset( $data['currencySymbol'] ) ) {
			$doc['currencySymbol'] = sanitize_text_field( $data['currencySymbol'] );
		}
		$doc['updatedAt'] = gmdate( 'c' );

		update_option( Schema::OPTION_SHOP, $doc );

		return $this->get_item( $request );
	}

	/**
	 * Permission check for PATCH.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		if ( ! current_user_can( Schema::CAP_MANAGE ) ) {
			return new WP_Error(
				'rest_forbidden',
				'Sorry, you are not allowed to do that.',
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Validate an absolute http(s) URL without DNS resolution.
	 *
	 * `wp_http_validate_url()` resolves the host, which fails for intranet
	 * hosts and in CI/test environments without DNS (the Pro schedule
	 * manager documents the same gotcha) — filter_var + scheme check is the
	 * repo's canonical substitute.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function is_valid_http_url( $value ): bool {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		return false !== filter_var( $value, FILTER_VALIDATE_URL )
			&& 1 === preg_match( '#^https?://#i', $value );
	}

	/**
	 * Normalize a stored doc — every field coerced/fallbacked to the
	 * contract shape.
	 *
	 * @param array<string, mixed> $doc Stored document.
	 * @return array{checkoutUrl: string, cartUrl: string, currency: string, currencySymbol: string, updatedAt: string}
	 */
	private function normalize_doc( array $doc ): array {
		$defaults = Schema::shop_defaults();

		$url = static function ( $value, string $fallback ): string {
			if ( ! is_string( $value ) ) {
				return $fallback;
			}
			$clean = esc_url_raw( $value );
			return '' !== $clean ? $clean : $fallback;
		};

		return array(
			'checkoutUrl'    => $url( $doc['checkoutUrl'] ?? null, $defaults['checkoutUrl'] ),
			'cartUrl'        => $url( $doc['cartUrl'] ?? null, $defaults['cartUrl'] ),
			'currency'       => ( is_string( $doc['currency'] ?? null ) && 1 === preg_match( '/^[A-Za-z]{3}$/', $doc['currency'] ) )
				? strtoupper( $doc['currency'] )
				: $defaults['currency'],
			'currencySymbol' => ( is_string( $doc['currencySymbol'] ?? null ) && '' !== $doc['currencySymbol'] )
				? $doc['currencySymbol']
				: $defaults['currencySymbol'],
			'updatedAt'      => ( is_string( $doc['updatedAt'] ?? null ) && '' !== $doc['updatedAt'] )
				? $doc['updatedAt']
				: gmdate( 'c' ),
		);
	}
}
