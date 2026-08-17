<?php
declare(strict_types=1);

namespace NvoosContentGraph\Remote\Drivers;

use NvoosContentGraph\Contracts\RemoteSource;
use NvoosContentGraph\Remote\HttpClient;
use function add_query_arg;
use function esc_url_raw;
use function is_array;
use function is_wp_error;
use function json_decode;
use function md5;
use function rawurlencode;
use function sanitize_key;
use function sanitize_text_field;

/**
 * SPARQL endpoint remote source driver.
 *
 * Imports RDF-structured nodes and edges from a SPARQL 1.1 endpoint
 * that returns results in SPARQL JSON format.
 *
 * @since 1.0.0
 */
class Sparql implements RemoteSource {

	/** @var array<string,mixed> Driver configuration. */
	private array $config = array();

	/** @var HttpClient HTTP client instance. */
	private HttpClient $http;

	public function __construct() {
		$this->http = new HttpClient( 'sparql' );
	}

	public function getDriverId(): string {
		return 'sparql';
	}

	public function getDriverLabel(): string {
		return __( 'SPARQL Endpoint (RDF)', 'nvoos-content-graph' );
	}

	public function setConfig( array $config ): void {
		$this->config = $config;
		$slug         = $config['_slug'] ?? 'sparql';
		$this->http   = new HttpClient( $slug );
	}

	public function getConfig(): array {
		return $this->config;
	}

	public function getCapabilities(): array {
		return array( 'fetch_nodes', 'fetch_edges', 'reconcile' );
	}

	public function getConfigSchema(): array {
		return array(
			'endpoint'  => array(
				'type'        => 'url',
				'label'       => __( 'SPARQL Endpoint URL', 'nvoos-content-graph' ),
				'description' => __( 'SPARQL 1.1 endpoint that accepts JSON results (e.g. https://query.wikidata.org/sparql).', 'nvoos-content-graph' ),
				'required'    => true,
			),
			'query'     => array(
				'type'        => 'textarea',
				'label'       => __( 'SPARQL Query', 'nvoos-content-graph' ),
				'description' => __( 'SPARQL SELECT query that returns ?id ?label ?type ?url and optionally ?source ?target ?relation for edges.', 'nvoos-content-graph' ),
				'required'    => true,
			),
			'node_type' => array(
				'type'        => 'text',
				'label'       => __( 'Default Node Type', 'nvoos-content-graph' ),
				'description' => __( 'Fallback node type when the result has no ?type binding.', 'nvoos-content-graph' ),
				'default'     => 'entity',
			),
			'max_items' => array(
				'type'        => 'number',
				'label'       => __( 'Max Items', 'nvoos-content-graph' ),
				'description' => __( 'Max results to ingest per sync.', 'nvoos-content-graph' ),
				'default'     => 500,
			),
		);
	}

	public function testConnection(): array {
		$endpoint = $this->getEndpoint();
		if ( empty( $endpoint ) ) {
			return array(
				'success' => false,
				'message' => __( 'No endpoint configured.', 'nvoos-content-graph' ),
			);
		}

		$query = $this->config['query'] ?? '';
		if ( empty( $query ) ) {
			return array(
				'success' => false,
				'message' => __( 'No SPARQL query configured.', 'nvoos-content-graph' ),
			);
		}

		// Test with a simple LIMIT 1 query.
		$testQuery = $query . ' LIMIT 1';
		$result    = $this->executeQuery( $testQuery );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'SPARQL endpoint accessible.', 'nvoos-content-graph' ),
		);
	}

	public function discover(): array {
		return array(
			'driver'       => $this->getDriverId(),
			'label'        => $this->getDriverLabel(),
			'endpoint'     => $this->getEndpoint(),
			'capabilities' => $this->getCapabilities(),
		);
	}

	public function fetchNodes( array $args = array() ): array {
		$query      = $this->config['query'] ?? '';
		$sourceSlug = $this->config['_slug'] ?? 'sparql';

		if ( empty( $query ) ) {
			return array();
		}

		$maxItems = absint( $this->config['max_items'] ?? 500 );
		if ( isset( $args['limit'] ) ) {
			$maxItems = absint( $args['limit'] );
		}

		$limitedQuery = $maxItems > 0 ? $query . ' LIMIT ' . $maxItems : $query;
		$results      = $this->executeQuery( $limitedQuery );
		if ( is_wp_error( $results ) || ! is_array( $results ) ) {
			return array();
		}

		$nodes = array();
		foreach ( $results as $binding ) {
			$label = sanitize_text_field( (string) ( $binding['label']['value'] ?? '' ) );
			$id    = sanitize_text_field( (string) ( $binding['id']['value'] ?? '' ) );
			if ( empty( $label ) && empty( $id ) ) {
				continue;
			}
			$label    = $label ?: $id;
			$type     = sanitize_text_field( (string) ( $binding['type']['value'] ?? $this->config['node_type'] ?? 'entity' ) );
			$url      = esc_url_raw( (string) ( $binding['url']['value'] ?? '' ) );
			$remoteId = $id ?: md5( $label );
			$nodeId   = 'remote_' . sanitize_key( $sourceSlug ) . '_' . sanitize_key( $remoteId );

			$nodes[] = array(
				'node_id'     => $nodeId,
				'label'       => $label,
				'type'        => $type,
				'post_id'     => 0,
				'url'         => $url,
				'source_slug' => $sourceSlug,
				'provenance'  => 'REMOTE',
				'external_id' => $remoteId,
			);
		}

		return $nodes;
	}

	public function fetchEdges( array $args = array() ): array {
		$query      = $this->config['query'] ?? '';
		$sourceSlug = $this->config['_slug'] ?? 'sparql';

		if ( empty( $query ) ) {
			return array();
		}

		$maxItems = absint( $this->config['max_items'] ?? 500 );

		$limitedQuery = $maxItems > 0 ? $query . ' LIMIT ' . $maxItems : $query;
		$results      = $this->executeQuery( $limitedQuery );
		if ( is_wp_error( $results ) || ! is_array( $results ) ) {
			return array();
		}

		$edges = array();
		foreach ( $results as $binding ) {
			$source   = sanitize_text_field( (string) ( $binding['source']['value'] ?? '' ) );
			$target   = sanitize_text_field( (string) ( $binding['target']['value'] ?? '' ) );
			$relation = sanitize_text_field( (string) ( $binding['relation']['value'] ?? 'RELATED_TO' ) );

			if ( empty( $source ) || empty( $target ) ) {
				continue;
			}

			$edges[] = array(
				'source_node_id' => 'remote_' . sanitize_key( $sourceSlug ) . '_' . sanitize_key( $source ),
				'target_node_id' => 'remote_' . sanitize_key( $sourceSlug ) . '_' . sanitize_key( $target ),
				'relation'       => strtoupper( $relation ),
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
	 * Execute a SPARQL query against the configured endpoint.
	 *
	 * @param string $query SPARQL query string.
	 * @return array|WP_Error Bindings array or WP_Error.
	 */
	private function executeQuery( string $query ) {
		$endpoint = $this->getEndpoint();
		if ( empty( $endpoint ) ) {
			return new \WP_Error( 'no_endpoint', __( 'No SPARQL endpoint configured.', 'nvoos-content-graph' ) );
		}

		$url = add_query_arg(
			array(
				'query'  => rawurlencode( $query ),
				'format' => 'json',
			),
			$endpoint
		);

		$headers = array(
			'Accept' => 'application/sparql-results+json',
		);
		$result  = $this->http->get( $url, array( 'headers' => $headers ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['body'], true );
		if ( ! is_array( $data ) || ! isset( $data['results']['bindings'] ) ) {
			return array();
		}

		return $data['results']['bindings'];
	}

	/**
	 * Return the configured endpoint URL.
	 *
	 * @return string
	 */
	private function getEndpoint(): string {
		return esc_url_raw( (string) ( $this->config['endpoint'] ?? '' ) );
	}
}
