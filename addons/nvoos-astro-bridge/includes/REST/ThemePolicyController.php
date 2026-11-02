<?php
/**
 * Theme policy REST controller — the storefront theme ladder singleton
 * (Proposal 105 §1).
 *
 * GET is public (the SSR boot reads it); PATCH is gated by the manage
 * capability and fires the debounced storefront rebuild through
 * RebuildWebhook's $pending seam. The document carries its own short cache
 * TTL (s-maxage=60) — see BaseController::set_short_cache_headers_on_public_gets().
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\RebuildWebhook;
use NvoosAstroBridge\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles GET/PATCH /funiq/v1/globals/theme.
 */
class ThemePolicyController extends BaseController {

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
	protected $rest_base = 'globals/theme';

	/**
	 * Plain policy values (brand:<slug> is validated separately).
	 *
	 * @var array<int, string>
	 */
	private const POLICIES = array( 'auto', 'light', 'dark' );

	/**
	 * Allowed contrast modes.
	 *
	 * @var array<int, string>
	 */
	private const CONTRASTS = array( 'auto', 'standard', 'high' );

	/**
	 * Allowed default themes.
	 *
	 * @var array<int, string>
	 */
	private const DEFAULT_THEMES = array( 'light', 'dark' );

	/**
	 * PATCHable fields — anything else is rejected with a typed 400.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = array( 'policy', 'contrast', 'defaultTheme' );

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
		// the B1 filter (priority 10) after rest_post_dispatch. add_filter is
		// idempotent for identical callables, so the double-namespace
		// registration (Plugin::register_rest_routes) adds it exactly once.
		add_filter(
			'rest_post_dispatch',
			array( BaseController::class, 'set_short_cache_headers_on_public_gets' ),
			20,
			3
		);
	}

	/**
	 * GET /funiq/v1/globals/theme — the normalized policy doc.
	 *
	 * The stored option is the default; the `funiq_bridge_theme_policy`
	 * filter can override it per request (the product-source pattern).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$stored = get_option( Schema::OPTION_THEME_POLICY, Schema::theme_policy_defaults() );
		$stored = is_array( $stored ) ? $stored : Schema::theme_policy_defaults();

		$doc = apply_filters( 'funiq_bridge_theme_policy', $stored );
		$doc = is_array( $doc ) ? $doc : Schema::theme_policy_defaults();

		return rest_ensure_response( $this->normalize_doc( $doc ) );
	}

	/**
	 * PATCH /funiq/v1/globals/theme — partial update, full doc back.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'astro_theme_policy_invalid_body',
				'A JSON object body is required.',
				array( 'status' => 400 )
			);
		}

		foreach ( array_keys( $data ) as $key ) {
			if ( ! in_array( $key, self::FIELDS, true ) ) {
				return new WP_Error(
					'astro_theme_policy_invalid_field',
					sprintf( 'Unknown field "%s".', sanitize_text_field( (string) $key ) ),
					array( 'status' => 400 )
				);
			}
		}

		if ( isset( $data['policy'] ) && ! $this->is_valid_policy( $data['policy'] ) ) {
			return new WP_Error(
				'astro_theme_policy_invalid_policy',
				'policy must be auto, light, dark, or brand:<slug> (slug: /^[a-z0-9-]+$/).',
				array( 'status' => 400 )
			);
		}
		if ( isset( $data['contrast'] ) && ( ! is_string( $data['contrast'] ) || ! in_array( $data['contrast'], self::CONTRASTS, true ) ) ) {
			return new WP_Error(
				'astro_theme_policy_invalid_contrast',
				'contrast must be one of: auto, standard, high.',
				array( 'status' => 400 )
			);
		}
		if ( isset( $data['defaultTheme'] ) && ( ! is_string( $data['defaultTheme'] ) || ! in_array( $data['defaultTheme'], self::DEFAULT_THEMES, true ) ) ) {
			return new WP_Error(
				'astro_theme_policy_invalid_default_theme',
				'defaultTheme must be one of: light, dark.',
				array( 'status' => 400 )
			);
		}

		$stored = get_option( Schema::OPTION_THEME_POLICY, Schema::theme_policy_defaults() );
		$stored = is_array( $stored ) ? $stored : Schema::theme_policy_defaults();
		$doc    = $this->normalize_doc( $stored );

		foreach ( self::FIELDS as $field ) {
			if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
				$doc[ $field ] = sanitize_text_field( $data[ $field ] );
			}
		}
		$doc['updatedAt'] = gmdate( 'c' );

		update_option( Schema::OPTION_THEME_POLICY, $doc );

		// The policy feeds the storefront SSR cache keys — a change must
		// trigger the debounced rebuild (via the $pending seam, never the
		// action wiring).
		RebuildWebhook::schedule_for_theme_policy();

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
	 * Validate a policy value (enum or brand:<slug> with slug ^[a-z0-9-]+$).
	 *
	 * @param mixed $policy Raw policy value.
	 * @return bool
	 */
	private function is_valid_policy( $policy ): bool {
		if ( ! is_string( $policy ) || '' === $policy ) {
			return false;
		}
		if ( in_array( $policy, self::POLICIES, true ) ) {
			return true;
		}
		if ( 0 === strpos( $policy, 'brand:' ) ) {
			return 1 === preg_match( '/^[a-z0-9-]+$/', substr( $policy, 6 ) );
		}
		return false;
	}

	/**
	 * Normalize a stored doc — every field coerced/fallbacked to the
	 * contract shape, `$extensions` always `{}` in v1.
	 *
	 * @param array<string, mixed> $doc Stored document.
	 * @return array{policy: string, contrast: string, defaultTheme: string, updatedAt: string, $extensions: array<string, mixed>}
	 */
	private function normalize_doc( array $doc ): array {
		$defaults = Schema::theme_policy_defaults();

		return array(
			'policy'       => $this->is_valid_policy( $doc['policy'] ?? null )
				? (string) $doc['policy']
				: $defaults['policy'],
			'contrast'     => ( is_string( $doc['contrast'] ?? null ) && in_array( $doc['contrast'], self::CONTRASTS, true ) )
				? $doc['contrast']
				: $defaults['contrast'],
			'defaultTheme' => ( is_string( $doc['defaultTheme'] ?? null ) && in_array( $doc['defaultTheme'], self::DEFAULT_THEMES, true ) )
				? $doc['defaultTheme']
				: $defaults['defaultTheme'],
			'updatedAt'    => ( is_string( $doc['updatedAt'] ?? null ) && '' !== $doc['updatedAt'] )
				? $doc['updatedAt']
				: gmdate( 'c' ),
			'$extensions'  => array(),
		);
	}
}
