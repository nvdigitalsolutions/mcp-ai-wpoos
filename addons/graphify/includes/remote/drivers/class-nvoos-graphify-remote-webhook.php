<?php
/**
 * NV oOS Graphify — Webhook Receiver Driver (base)
 *
 * Pure-receiver driver that ingests records POSTed to the
 * `/wp-json/nvoos-graphify/v1/webhooks/{slug}` endpoint. No outbound
 * fetch_nodes() — the REST controller calls ingest_payload() directly when
 * a verified webhook arrives.
 *
 * Configuration:
 *   webhook_secret — shared secret for HMAC-SHA256 signature verification
 *                    (sent by the producer in the X-NVOOS-Signature header)
 *   field_map      — JSON map applied to incoming records via the Field Mapper
 *
 * Producer signature format: lower-case hex sha256 hmac of the raw request
 * body, optionally prefixed with "sha256=" (GitHub / Stripe convention).
 *
 * @package NV_oOS_Graphify
 * @since   0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook-receiver remote driver.
 *
 * @since 0.7.0
 */
class NV_oOS_Graphify_Remote_Webhook extends NV_oOS_Graphify_Remote_Source_Base {

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'webhook';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'Webhook Receiver', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'webhooks' );
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array(
			'supports_incremental'   => true,
			'supports_webhooks'      => true,
			'supports_oauth'         => false,
			'supports_pagination'    => false,
			'supports_relationships' => false,
		);
	}

	/** {@inheritdoc} */
	public function get_config_schema() {
		return array(
			'webhook_secret' => array(
				'type'        => 'password',
				'label'       => __( 'Webhook Secret', 'nvoos-graphify' ),
				'description' => __( 'Shared secret used to verify the X-NVOOS-Signature header (HMAC-SHA256 of the raw request body). Required.', 'nvoos-graphify' ),
				'required'    => true,
			),
			'field_map'      => array(
				'type'        => 'textarea',
				'label'       => __( 'Field Map (JSON)', 'nvoos-graphify' ),
				'description' => __( 'JSON map from incoming record fields to node properties.', 'nvoos-graphify' ),
			),
			'records_path'   => array(
				'type'        => 'text',
				'label'       => __( 'Records Path', 'nvoos-graphify' ),
				'description' => __( 'Optional dotted path to the array of records inside the JSON body (e.g. "data" or "items"). Leave empty if the body is itself an array of records.', 'nvoos-graphify' ),
			),
		);
	}

	/** {@inheritdoc} */
	public function test_connection() {
		$secret = isset( $this->config['webhook_secret'] ) ? (string) $this->config['webhook_secret'] : '';
		if ( '' === $secret ) {
			return array(
				'success' => false,
				'message' => __( 'No webhook_secret configured.', 'nvoos-graphify' ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Webhook receiver is configured. Producers should POST to the source webhook URL.', 'nvoos-graphify' ),
		);
	}

	/**
	 * No outbound fetch — webhook driver is push-only.
	 *
	 * @param array $args Unused.
	 * @return array
	 */
	public function fetch_nodes( array $args = array() ) {
		return array();
	}

	/**
	 * Verify a request signature against the configured webhook_secret.
	 *
	 * @since 0.7.0
	 *
	 * @param string $raw_body  Raw request body bytes.
	 * @param string $signature Signature header value (with or without "sha256=" prefix).
	 * @return bool
	 */
	public function verify_signature( $raw_body, $signature ) {
		$secret = isset( $this->config['webhook_secret'] ) ? (string) $this->config['webhook_secret'] : '';
		if ( '' === $secret || '' === (string) $signature ) {
			return false;
		}
		$signature = (string) $signature;
		if ( 0 === strpos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}
		$expected = hash_hmac( 'sha256', (string) $raw_body, $secret );
		return is_string( $expected ) && hash_equals( $expected, strtolower( $signature ) );
	}

	/**
	 * Convert a verified webhook payload into a list of node arrays.
	 *
	 * Does NOT persist — caller is responsible for upserting the returned
	 * nodes. This separation makes the method trivially unit-testable.
	 *
	 * @since 0.7.0
	 *
	 * @param array $payload Decoded JSON payload.
	 * @return array Array of node arrays.
	 */
	public function payload_to_nodes( array $payload ) {
		$records_path = isset( $this->config['records_path'] ) ? (string) $this->config['records_path'] : '';
		if ( '' !== $records_path ) {
			$resolved = NV_oOS_Graphify_Field_Mapper::resolve( $payload, $records_path, array() );
			$records  = is_array( $resolved ) ? $resolved : array();
		} else {
			// Body is either an indexed list of records, or a single record.
			$records = ( ! empty( $payload ) && array_keys( $payload ) === range( 0, count( $payload ) - 1 ) )
				? $payload
				: array( $payload );
		}

		$map = $this->resolve_field_map();
		if ( empty( $map ) || empty( $map['id'] ) || empty( $map['label'] ) ) {
			return array();
		}

		return NV_oOS_Graphify_Field_Mapper::map_collection( $records, $map, $this->get_slug() );
	}

	/**
	 * Decode the configured field map.
	 *
	 * @return array
	 */
	private function resolve_field_map() {
		$raw = isset( $this->config['field_map'] ) ? (string) $this->config['field_map'] : '';
		if ( '' === $raw ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
