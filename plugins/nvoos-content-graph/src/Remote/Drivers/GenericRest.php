<?php
declare(strict_types=1);

namespace NvoosContentGraph\Remote\Drivers;

use NvoosContentGraph\Contracts\RemoteSource;
use NvoosContentGraph\Remote\HttpClient;
use WP_Error;
use function absint;
use function add_query_arg;
use function array_slice;
use function count;
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
 * Imports nodes and edges from any JSON REST API using configurable
 * JSON-path mapping for label, type, ID, and URL fields, optional
 * offset/page pagination, and Bearer-token or query-param auth.
 *
 * @since 1.0.0
 */
class GenericRest implements RemoteSource {

	/** @var int Hard ceiling on paginated pages per sync (safety valve). */
	private const MAX_PAGES = 100;

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
			'base_url'            => array(
				'type'        => 'url',
				'label'       => __( 'API Endpoint URL', 'nvoos-content-graph' ),
				'description' => __( 'Base URL of the JSON REST API to fetch from (GET). For paginated APIs the page number is appended automatically.', 'nvoos-content-graph' ),
				'required'    => true,
			),
			'api_token'           => array(
				'type'        => 'password',
				'label'       => __( 'API Token', 'nvoos-content-graph' ),
				'description' => __( 'Bearer token sent as "Authorization: Bearer …" (optional).', 'nvoos-content-graph' ),
			),
			'auth_query_param'    => array(
				'type'        => 'text',
				'label'       => __( 'Token Query Parameter', 'nvoos-content-graph' ),
				'description' => __( 'When your API expects the token as a query parameter (e.g. "api_key"), enter its name here. The API Token value is then appended to the URL instead of the Authorization header.', 'nvoos-content-graph' ),
			),
			'path_results'        => array(
				'type'        => 'text',
				'label'       => __( 'Results Path', 'nvoos-content-graph' ),
				'description' => __( 'Dot-notation path to the results array in the JSON response (e.g. data.items). Leave empty when the response itself is the array.', 'nvoos-content-graph' ),
				'default'     => '',
			),
			'path_id'             => array(
				'type'        => 'text',
				'label'       => __( 'ID Path', 'nvoos-content-graph' ),
				'description' => __( 'Field name holding the record ID (e.g. id).', 'nvoos-content-graph' ),
				'default'     => 'id',
			),
			'path_label'          => array(
				'type'        => 'text',
				'label'       => __( 'Label Path', 'nvoos-content-graph' ),
				'description' => __( 'Field name holding the node label (e.g. name).', 'nvoos-content-graph' ),
				'default'     => 'name',
			),
			'path_url'            => array(
				'type'        => 'text',
				'label'       => __( 'URL Path', 'nvoos-content-graph' ),
				'description' => __( 'Field name holding the item URL (optional).', 'nvoos-content-graph' ),
				'default'     => 'url',
			),
			'path_type'           => array(
				'type'        => 'text',
				'label'       => __( 'Type Path', 'nvoos-content-graph' ),
				'description' => __( 'Field name holding the node type. Leave empty to use "entity" for every item.', 'nvoos-content-graph' ),
				'default'     => '',
			),
			'max_items'           => array(
				'type'        => 'number',
				'label'       => __( 'Max Items', 'nvoos-content-graph' ),
				'description' => __( 'Maximum items to ingest per sync (0 = unlimited).', 'nvoos-content-graph' ),
				'default'     => 500,
			),
			'page_param'          => array(
				'type'        => 'text',
				'label'       => __( 'Page Number Parameter', 'nvoos-content-graph' ),
				'description' => __( 'Query parameter name for the page number (e.g. "page" or "offset"). Leave empty to fetch the endpoint once without pagination.', 'nvoos-content-graph' ),
			),
			'page_size_param'     => array(
				'type'        => 'text',
				'label'       => __( 'Page Size Parameter', 'nvoos-content-graph' ),
				'description' => __( 'Query parameter name for the per-page size (e.g. "limit" or "per_page"). Only used when pagination is enabled.', 'nvoos-content-graph' ),
				'default'     => 'limit',
			),
			'page_size'           => array(
				'type'        => 'number',
				'label'       => __( 'Page Size', 'nvoos-content-graph' ),
				'description' => __( 'Items per page when pagination is enabled (0 = use the API default).', 'nvoos-content-graph' ),
				'default'     => 100,
			),
			'edge_path'           => array(
				'type'        => 'text',
				'label'       => __( 'Edges Path', 'nvoos-content-graph' ),
				'description' => __( 'Dot-notation path to the edges array in the response (e.g. data.edges). Leave empty when the API has no relationship data.', 'nvoos-content-graph' ),
			),
			'edge_source_field'   => array(
				'type'        => 'text',
				'label'       => __( 'Edge Source Field', 'nvoos-content-graph' ),
				'description' => __( 'Field name holding the source item ID on each edge.', 'nvoos-content-graph' ),
				'default'     => 'source',
			),
			'edge_target_field'   => array(
				'type'        => 'text',
				'label'       => __( 'Edge Target Field', 'nvoos-content-graph' ),
				'description' => __( 'Field name holding the target item ID on each edge.', 'nvoos-content-graph' ),
				'default'     => 'target',
			),
			'edge_relation_field' => array(
				'type'        => 'text',
				'label'       => __( 'Edge Relation Field', 'nvoos-content-graph' ),
				'description' => __( 'Field name holding the relationship label on each edge.', 'nvoos-content-graph' ),
				'default'     => 'relation',
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

		$page = $this->fetchPage( $baseUrl, 1 );
		if ( is_wp_error( $page ) ) {
			return array(
				'success' => false,
				'message' => $page->get_error_message(),
			);
		}

		$items = $this->extractPath( $page, $this->config['path_results'] ?? '' );
		if ( ! is_array( $items ) ) {
			return array(
				'success' => false,
				'message' => __( 'Response is not a JSON object with the configured Results Path.', 'nvoos-content-graph' ),
			);
		}

		$labelField = sanitize_text_field( (string) ( $this->config['path_label'] ?? 'name' ) );
		$labelOk    = 0;
		foreach ( array_slice( $items, 0, 10 ) as $item ) {
			if ( is_array( $item ) && ! empty( $item[ $labelField ] ) ) {
				++$labelOk;
			}
		}
		if ( 0 === $labelOk && ! empty( $items ) ) {
			return array(
				'success' => false,
				/* translators: %s label field name */
				'message' => sprintf( __( 'No item has a "%s" field — check the Label Path.', 'nvoos-content-graph' ), $labelField ),
			);
		}

		$message = sprintf(
			/* translators: %d item count */
			__( 'Connected. Found %d items at the configured path.', 'nvoos-content-graph' ),
			count( $items )
		);

		$edgePath = $this->config['edge_path'] ?? '';
		if ( ! empty( $edgePath ) ) {
			$edges = $this->extractPath( $page, $edgePath );
			if ( ! is_array( $edges ) ) {
				return array(
					'success' => false,
					'message' => __( 'Edges Path does not resolve to an array in the response.', 'nvoos-content-graph' ),
				);
			}
			$message .= ' ' . sprintf(
				/* translators: %d edge count */
				__( '%d edges found.', 'nvoos-content-graph' ),
				count( $edges )
			);
		}

		return array(
			'success' => true,
			'message' => $message,
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
		$baseUrl = $this->getBaseUrl();
		if ( empty( $baseUrl ) ) {
			return array();
		}

		$limit  = $this->resolveLimit( $args );
		$items  = $this->fetchItems( $baseUrl, 'path_results', $limit );
		$result = $this->mapItemsToNodes( $items );

		return $result;
	}

	public function fetchEdges( array $args = array() ): array {
		$edgePath = $this->config['edge_path'] ?? '';
		if ( empty( $edgePath ) ) {
			return array();
		}

		$baseUrl = $this->getBaseUrl();
		if ( empty( $baseUrl ) ) {
			return array();
		}

		$limit         = $this->resolveLimit( $args );
		$items         = $this->fetchItems( $baseUrl, 'edge_path', $limit );
		$sourceSlug    = $this->config['_slug'] ?? 'generic_rest';
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
	 * Map raw item arrays into graph-node arrays.
	 *
	 * @param array<int,array<string,mixed>> $items Raw items.
	 * @return array<int,array<string,mixed>>
	 */
	private function mapItemsToNodes( array $items ): array {
		$sourceSlug = $this->config['_slug'] ?? 'generic_rest';
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

	/**
	 * Fetch (and paginate) the item array for a configured path.
	 *
	 * When `page_param` is configured the endpoint is paged with that
	 * parameter (plus the optional page-size parameter) until an empty
	 * page, the item cap, or the safety page ceiling is reached.
	 *
	 * @param string $baseUrl Endpoint URL.
	 * @param string $pathKey Config key holding the dot-notation path.
	 * @param int    $limit   Item cap (0 = unlimited).
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchItems( string $baseUrl, string $pathKey, int $limit ): array {
		$path      = (string) ( $this->config[ $pathKey ] ?? '' );
		$pageParam = sanitize_text_field( (string) ( $this->config['page_param'] ?? '' ) );

		// Single-shot mode: one request, no paging.
		if ( '' === $pageParam ) {
			$page = $this->fetchPage( $baseUrl, 0 );
			if ( is_wp_error( $page ) || ! is_array( $page ) ) {
				return array();
			}
			$items = $this->extractPath( $page, $path );
			if ( ! is_array( $items ) ) {
				return array();
			}
			return ( $limit > 0 && count( $items ) > $limit ) ? array_slice( $items, 0, $limit ) : $items;
		}

		// Paginated mode: walk pages until empty, capped, or ceiling.
		$items     = array();
		$page      = 1;
		$sizeParam = sanitize_text_field( (string) ( $this->config['page_size_param'] ?? 'limit' ) );
		$pageSize  = max( 0, absint( $this->config['page_size'] ?? 0 ) );

		while ( $page <= self::MAX_PAGES ) {
			if ( $limit > 0 && count( $items ) >= $limit ) {
				break;
			}

			$query = array( $pageParam => $page );
			if ( '' !== $sizeParam && $pageSize > 0 ) {
				$query[ $sizeParam ] = $pageSize;
			}

			$result = $this->fetchPage( $baseUrl, $query );
			if ( is_wp_error( $result ) || ! is_array( $result ) ) {
				break;
			}

			$batch = $this->extractPath( $result, $path );
			if ( ! is_array( $batch ) || empty( $batch ) ) {
				break; // Empty page ends the walk.
			}

			foreach ( $batch as $item ) {
				if ( $limit > 0 && count( $items ) >= $limit ) {
					break 2;
				}
				$items[] = $item;
			}
			++$page;
		}

		return $items;
	}

	/**
	 * GET one page of JSON from the endpoint.
	 *
	 * @param string                $baseUrl Endpoint URL.
	 * @param int|array<string,int> $paging  0/empty = no paging params; array = query args.
	 * @return array<string,mixed>|WP_Error Decoded JSON body, or WP_Error.
	 */
	private function fetchPage( string $baseUrl, $paging = 0 ) {
		$url = $baseUrl;
		if ( is_array( $paging ) && ! empty( $paging ) ) {
			$url = add_query_arg( $paging, $url );
		}

		list( $url, $headers ) = $this->applyAuth( $url );

		$result = $this->http->get( $url, array( 'headers' => $headers ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $result['status'] < 200 || $result['status'] >= 300 ) {
			return new WP_Error(
				'http_' . $result['status'],
				/* translators: %d HTTP status code */
				sprintf( __( 'HTTP %d.', 'nvoos-content-graph' ), $result['status'] )
			);
		}

		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_json', __( 'Response is not valid JSON.', 'nvoos-content-graph' ) );
		}

		return $body;
	}

	/**
	 * Apply the configured auth strategy to a URL.
	 *
	 * @param string $url Endpoint URL.
	 * @return array{0:string,1:array<string,string>} URL and headers.
	 */
	private function applyAuth( string $url ): array {
		$token = (string) ( $this->config['api_token'] ?? '' );
		$param = sanitize_text_field( (string) ( $this->config['auth_query_param'] ?? '' ) );

		if ( '' !== $token && '' !== $param ) {
			return array( add_query_arg( array( $param => $token ), $url ), array() );
		}

		$headers = array();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return array( $url, $headers );
	}

	/**
	 * Resolve the per-sync item cap.
	 *
	 * @param array<string,mixed> $args Fetch args (may carry 'limit').
	 * @return int 0 = unlimited.
	 */
	private function resolveLimit( array $args ): int {
		if ( isset( $args['limit'] ) ) {
			return max( 0, absint( $args['limit'] ) );
		}
		return max( 0, absint( $this->config['max_items'] ?? 500 ) );
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
}
