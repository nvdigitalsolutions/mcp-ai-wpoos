<?php
declare(strict_types=1);

namespace NvoosContentGraph\Remote\Drivers;

use NvoosContentGraph\Contracts\RemoteSource;
use NvoosContentGraph\Remote\HttpClient;
use function esc_url_raw;
use function is_array;
use function is_wp_error;
use function json_decode;
use function md5;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;

/**
 * Generic REST API remote source driver.
 *
 * Imports nodes and edges from any REST API using configurable
 * JSON-path mapping for label, type, ID, and URL fields.
 *
 * @since 1.0.0
 */
class GenericRest implements RemoteSource {

	/** @var array<string,mixed> Driver configuration. */
	private array $config = array();

	/** @var HttpClient HTTP client instance. */
	private HttpClient $http;

	public function __construct() {
		$this->http = new HttpClient( 'generic_rest' );
	}

	public function getDriverId(): string {
		return 'generic_rest';
	}

	public function getDriverLabel(): string {
		return __( 'Generic REST API', 'nvoos-content-graph' );
	}

	public function setConfig( array $config ): void {
		$this->config = $config;
		$slug         = $config['_slug'] ?? 'generic_rest';
		$this->http   = new HttpClient( $slug );
	}

	public function getConfig(): array {
		return $this->config;
	}

	public function getCapabilities(): array {
		return array( 'fetch_nodes', 'fetch_edges' );
	}

	public function getConfigSchema(): array {
		return array(
			'base_url'     => array(
				'type'        => 'url',
				'label'       => __( 'API Endpoint URL', 'nvoos-content-graph' ),
				'description' => __( 'Base URL of the REST API to fetch from.', 'nvoos-content-graph' ),
				'required'    => true,
			),
			'api_token'    => array(
				'type'        => 'password',
				'label'       => __( 'API Token', 'nvoos-content-graph' ),
				'description' => __( 'Bearer token for authorization (optional).', 'nvoos-content-graph' ),
			),
			'path_results' => array(
				'type'        => 'text',
				'label'       => __( 'Results Path', 'nvoos-content-graph' ),
				'description' => __( 'Dot-notation path to results array (e.g. data.items).', 'nvoos-content-graph' ),
				'default'     => '',
			),
			'path_id'      => array(
				'type'    => 'text',
				'label'   => __( 'ID Path', 'nvoos-content-graph' ),
				'default' => 'id',
			),
			'path_label'   => array(
				'type'    => 'text',
				'label'   => __( 'Label Path', 'nvoos-content-graph' ),
				'default' => 'name',
			),
			'path_url'     => array(
				'type'    => 'text',
				'label'   => __( 'URL Path', 'nvoos-content-graph' ),
				'default' => 'url',
			),
			'path_type'    => array(
				'type'    => 'text',
				'label'   => __( 'Type Path', 'nvoos-content-graph' ),
				'default' => '',
			),
		);
	}

	public function testConnection(): array {
		$baseUrl = $this->getBaseUrl();
		if ( empty( $baseUrl ) ) {
			return array(
				'success' => false,
				'message' => __( 'No base_url configured.', 'nvoos-content-graph' ),
			);
		}

		$result = $this->http->get( $baseUrl, array( 'headers' => $this->getAuthHeaders() ) );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		if ( $result['status'] < 200 || $result['status'] >= 300 ) {
			return array(
				'success' => false,
				/* translators: %d HTTP status code */
				'message' => sprintf( __( 'HTTP %d.', 'nvoos-content-graph' ), $result['status'] ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Connected.', 'nvoos-content-graph' ),
		);
	}

	public function discover(): array {
		return array(
			'driver'       => $this->getDriverId(),
			'label'        => $this->getDriverLabel(),
			'base_url'     => $this->getBaseUrl(),
			'capabilities' => $this->getCapabilities(),
		);
	}

	public function fetchNodes( array $args = array() ): array {
		$baseUrl    = $this->getBaseUrl();
		$sourceSlug = $this->config['_slug'] ?? 'generic_rest';

		if ( empty( $baseUrl ) ) {
			return array();
		}

		$result = $this->http->get( $baseUrl, array( 'headers' => $this->getAuthHeaders() ) );
		if ( is_wp_error( $result ) ) {
			return array();
		}

		$body = json_decode( $result['body'], true );
		if ( ! is_array( $body ) ) {
			return array();
		}

		$resultsPath = $this->config['path_results'] ?? '';
		$items       = $this->extractPath( $body, $resultsPath );
		if ( ! is_array( $items ) ) {
			return array();
		}

		$idField    = $this->config['path_id'] ?? 'id';
		$labelField = $this->config['path_label'] ?? 'name';
		$urlField   = $this->config['path_url'] ?? 'url';
		$typeField  = $this->config['path_type'] ?? '';

		$nodes = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label    = sanitize_text_field( (string) ( $item[ $labelField ] ?? '' ) );
			$remoteId = sanitize_text_field( (string) ( $item[ $idField ] ?? '' ) );
			if ( empty( $label ) ) {
				continue;
			}
			$type   = $typeField ? sanitize_text_field( (string) ( $item[ $typeField ] ?? 'entity' ) ) : 'entity';
			$url    = esc_url_raw( (string) ( $item[ $urlField ] ?? '' ) );
			$nodeId = 'remote_' . sanitize_key( $sourceSlug ) . '_' . ( $remoteId ? sanitize_key( $remoteId ) : md5( $label ) );

			$nodes[] = array(
				'node_id'     => $nodeId,
				'label'       => $label,
				'type'        => $type,
				'post_id'     => 0,
				'url'         => $url,
				'properties'  => $item,
				'source_slug' => $sourceSlug,
				'provenance'  => 'REMOTE',
				'external_id' => $remoteId,
			);
		}

		return $nodes;
	}

	public function fetchEdges( array $args = array() ): array {
		$edgePath = $this->config['edge_path'] ?? '';
		if ( empty( $edgePath ) ) {
			return array();
		}

		$baseUrl    = $this->getBaseUrl();
		$sourceSlug = $this->config['_slug'] ?? 'generic_rest';

		if ( empty( $baseUrl ) ) {
			return array();
		}

		$result = $this->http->get( $baseUrl, array( 'headers' => $this->getAuthHeaders() ) );
		if ( is_wp_error( $result ) ) {
			return array();
		}

		$body  = json_decode( $result['body'], true );
		$items = $this->extractPath( $body, $edgePath );
		if ( ! is_array( $items ) ) {
			return array();
		}

		$sourceField   = $this->config['edge_source_field'] ?? 'source';
		$targetField   = $this->config['edge_target_field'] ?? 'target';
		$relationField = $this->config['edge_relation_field'] ?? 'relation';

		$edges = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$src = sanitize_text_field( (string) ( $item[ $sourceField ] ?? '' ) );
			$tgt = sanitize_text_field( (string) ( $item[ $targetField ] ?? '' ) );
			$rel = sanitize_text_field( (string) ( $item[ $relationField ] ?? 'RELATED_TO' ) );

			if ( empty( $src ) || empty( $tgt ) ) {
				continue;
			}

			$edges[] = array(
				'source_node_id' => 'remote_' . sanitize_key( $sourceSlug ) . '_' . sanitize_key( $src ),
				'target_node_id' => 'remote_' . sanitize_key( $sourceSlug ) . '_' . sanitize_key( $tgt ),
				'relation'       => strtoupper( $rel ),
				'confidence'     => 1.0,
				'provenance'     => 'REMOTE',
				'source_slug'    => $sourceSlug,
			);
		}

		return $edges;
	}

	public function reconcile( $localNode ): array {
		return array(
			'external_id' => '',
			'confidence'  => 0.0,
			'matched'     => false,
		);
	}

	/**
	 * Extract a nested value from an array using dot-notation path.
	 *
	 * @param array  $data Data array.
	 * @param string $path Dot-notation path (e.g. 'data.items').
	 * @return mixed Value at path or null.
	 */
	private function extractPath( array $data, string $path ) {
		if ( empty( $path ) || ! is_array( $data ) ) {
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
	 * Return the configured base URL.
	 *
	 * @return string
	 */
	private function getBaseUrl(): string {
		return esc_url_raw( (string) ( $this->config['base_url'] ?? '' ) );
	}

	/**
	 * Build Authorization headers from config.
	 *
	 * @return array<string,string>
	 */
	private function getAuthHeaders(): array {
		$token   = isset( $this->config['api_token'] ) ? (string) $this->config['api_token'] : '';
		$headers = array();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
	}
}
