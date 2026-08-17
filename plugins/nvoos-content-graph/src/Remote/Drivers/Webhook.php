<?php
declare(strict_types=1);

namespace NvoosContentGraph\Remote\Drivers;

use NvoosContentGraph\Contracts\RemoteSource;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function json_decode;
use function md5;
use function sanitize_key;
use function sanitize_text_field;

/**
 * Webhook Receiver remote source driver.
 *
 * Pure-receiver driver that ingests records POSTed to the
 * `/wp-json/nvoos-content-graph/v1/webhooks/{slug}` endpoint. No outbound
 * fetchNodes() — the REST controller calls ingestPayload() directly when
 * a verified webhook arrives.
 *
 * @since 1.0.0
 */
class Webhook implements RemoteSource {

	/** @var array<string,mixed> Driver configuration. */
	private array $config = array();

	public function getDriverId(): string {
		return 'webhook';
	}

	public function getDriverLabel(): string {
		return __( 'Webhook Receiver', 'nvoos-content-graph' );
	}

	public function setConfig( array $config ): void {
		$this->config = $config;
	}

	public function getConfig(): array {
		return $this->config;
	}

	public function getCapabilities(): array {
		return array( 'webhooks' );
	}

	public function getConfigSchema(): array {
		return array(
			'webhook_secret' => array(
				'type'        => 'password',
				'label'       => __( 'Webhook Secret', 'nvoos-content-graph' ),
				'description' => __( 'Shared secret used to verify the X-NVOOS-Signature header (HMAC-SHA256 of the raw request body). Required.', 'nvoos-content-graph' ),
				'required'    => true,
			),
			'field_map'      => array(
				'type'        => 'textarea',
				'label'       => __( 'Field Map (JSON)', 'nvoos-content-graph' ),
				'description' => __( 'JSON map from incoming record fields to node properties.', 'nvoos-content-graph' ),
			),
			'records_path'   => array(
				'type'        => 'text',
				'label'       => __( 'Records Path', 'nvoos-content-graph' ),
				'description' => __( 'Optional dotted path to the array of records inside the JSON body (e.g. "data" or "items"). Leave empty if the body is itself an array of records.', 'nvoos-content-graph' ),
			),
		);
	}

	public function testConnection(): array {
		$secret = (string) ( $this->config['webhook_secret'] ?? '' );
		if ( '' === $secret ) {
			return array(
				'success' => false,
				'message' => __( 'No webhook_secret configured.', 'nvoos-content-graph' ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Webhook receiver is configured. Producers should POST to the source webhook URL.', 'nvoos-content-graph' ),
		);
	}

	public function discover(): array {
		return array(
			'driver'       => $this->getDriverId(),
			'label'        => $this->getDriverLabel(),
			'capabilities' => $this->getCapabilities(),
		);
	}

	public function fetchNodes( array $args = array() ): array {
		return array();
	}

	public function fetchEdges( array $args = array() ): array {
		return array();
	}

	public function reconcile( $localNode ): array {
		return array(
			'external_id' => '',
			'confidence'  => 0.0,
			'matched'     => false,
		);
	}

	/**
	 * Verify the HMAC-SHA256 signature on an incoming webhook request.
	 *
	 * @param string $rawBody   Raw request body string.
	 * @param string $signature Signature from X-NVOOS-Signature header.
	 * @return bool True if valid.
	 */
	public function verifySignature( string $rawBody, string $signature ): bool {
		$secret = (string) ( $this->config['webhook_secret'] ?? '' );
		if ( '' === $secret ) {
			return false;
		}

		// Support "sha256=<hex>" prefix convention (GitHub / Stripe style).
		if ( 0 === strpos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}

		$expected = hash_hmac( 'sha256', $rawBody, $secret, false );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Ingest a verified webhook payload into node arrays.
	 *
	 * @param string $rawBody Raw JSON request body.
	 * @return array<int,array<string,mixed>> Node arrays ready for upsert.
	 */
	public function ingestPayload( string $rawBody ): array {
		$sourceSlug = $this->config['_slug'] ?? 'webhook';
		$data       = json_decode( $rawBody, true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		// Navigate to records if a path is configured.
		$recordsPath = (string) ( $this->config['records_path'] ?? '' );
		$records     = $data;
		if ( '' !== $recordsPath ) {
			$records = $this->extractPath( $data, $recordsPath );
		}

		if ( ! is_array( $records ) ) {
			return array();
		}

		// If data is an associative map, wrap it as a single record.
		if ( ! isset( $records[0] ) ) {
			$records = array( $records );
		}

		$fieldMap = $this->resolveFieldMap();
		$type     = sanitize_text_field( (string) ( $fieldMap['type'] ?? 'entity' ) );
		$labelKey = $fieldMap['label'] ?? 'name';
		$urlKey   = $fieldMap['url'] ?? 'url';
		$idKey    = $fieldMap['id'] ?? null;

		$nodes = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$label    = sanitize_text_field( (string) ( $record[ $labelKey ] ?? '' ) );
			$remoteId = $idKey ? sanitize_text_field( (string) ( $record[ $idKey ] ?? '' ) ) : '';
			if ( empty( $label ) && empty( $remoteId ) ) {
				continue;
			}

			$label  = $label ?: $remoteId;
			$url    = isset( $record[ $urlKey ] ) ? \esc_url_raw( (string) $record[ $urlKey ] ) : '';
			$nodeId = 'remote_' . sanitize_key( $sourceSlug ) . '_' . ( $remoteId ? sanitize_key( $remoteId ) : md5( $label ) );

			$nodes[] = array(
				'node_id'     => $nodeId,
				'label'       => $label,
				'type'        => $type,
				'post_id'     => 0,
				'url'         => $url,
				'properties'  => $record,
				'source_slug' => $sourceSlug,
				'provenance'  => 'REMOTE',
				'external_id' => $remoteId,
			);
		}

		return $nodes;
	}

	/**
	 * Extract a nested value from an array using dot-notation path.
	 *
	 * @param array  $data Data array.
	 * @param string $path Dot-notation path.
	 * @return mixed
	 */
	private function extractPath( array $data, string $path ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$parts   = explode( '.', $path );
		$current = $data;
		foreach ( $parts as $part ) {
			if ( ! is_array( $current ) || ! isset( $current[ $part ] ) ) {
				return null;
			}
			$current = $current[ $part ];
		}
		return $current;
	}

	/**
	 * Decode the configured field map.
	 *
	 * @return array<string,string>
	 */
	private function resolveFieldMap(): array {
		$raw = $this->config['field_map'] ?? '';
		if ( '' === $raw ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
