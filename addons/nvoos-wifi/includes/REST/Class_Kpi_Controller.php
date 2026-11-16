<?php
/**
 * REST surface: `nvoos-wifi/v1`.
 *
 * Phase 0 exposes a single read route whose payload comes straight out of
 * `Class_Kpi_Service::for_venue()`. That is intentional: the route must add no
 * logic of its own, because the Laravel host has to be able to produce the same
 * bytes from the same service call (Proposal 150 implementation plan T-0.11).
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\REST;

use NvoosWifi\Domain\Result;
use NvoosWifi\Domain\Window;
use NvoosWifi\Infrastructure\Wp\Class_Result_Mapper;
use NvoosWifi\Infrastructure\Wp\Class_Venue_Repository;
use NvoosWifi\Plugin;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KPI read endpoints.
 *
 * @since 0.1.0
 */
final class Class_Kpi_Controller {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'nvoos-wifi/v1';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/venues/(?P<id>[A-Za-z0-9_\-]+)/kpis',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_kpis' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'id'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'from' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'to'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Authorise a KPI read.
	 *
	 * Reads expose venue-level operational data, so this stays behind a real
	 * capability. Phase 1 replaces it with a per-venue capability once venue
	 * ownership exists.
	 *
	 * @return bool
	 */
	public function can_read(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Handle `GET /venues/{id}/kpis`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function get_kpis( WP_REST_Request $request ) {
		$venue_id = (string) $request->get_param( 'id' );
		$from     = (int) $request->get_param( 'from' );
		$to       = (int) $request->get_param( 'to' );

		$venue = ( new Class_Venue_Repository() )->find( $venue_id );

		if ( $venue->is_error() ) {
			return Class_Result_Mapper::to_wp_error( $venue );
		}

		$window = Window::make( $from, $to );

		if ( $window->is_error() ) {
			return Class_Result_Mapper::to_wp_error( $window );
		}

		$result = Plugin::kpi_service()->for_venue( $venue->value(), $window->value() );

		if ( $result->is_error() ) {
			return Class_Result_Mapper::to_wp_error( $result );
		}

		return new WP_REST_Response( $result->value(), 200 );
	}
}
