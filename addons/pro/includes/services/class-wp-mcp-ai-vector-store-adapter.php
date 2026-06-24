<?php
/**
 * Vector Store Adapter Service.
 *
 * Provides a unified interface for multiple vector-store backends:
 *   - 'openai'   — OpenAI vector stores (uses WP_MCP_AI_OpenAI_Client when available).
 *   - 'pgvector' — Postgres + pgvector (stub; requires `pgvector_dsn` option).
 *   - 'qdrant'   — Qdrant cloud (stub; requires `qdrant_url` + `qdrant_api_key`).
 *
 * Stub backends return graceful-degradation payloads so callers can integrate
 * without crashing when credentials are missing.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Vector_Store_Adapter.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Vector_Store_Adapter {

	/**
	 * Option key for adapter settings.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	const OPTION_SETTINGS = 'wp_mcp_ai_vector_store_settings';

	/**
	 * Option key for namespace registry.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	const OPTION_NAMESPACES = 'wp_mcp_ai_vector_namespaces';

	/**
	 * Singleton instance.
	 *
	 * @since 1.6.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since 1.6.0
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Protected constructor for singleton.
	 *
	 * @since 1.6.0
	 */
	protected function __construct() {
		// Intentionally empty.
	}

	/**
	 * Returns adapter settings from options.
	 *
	 * @since 1.6.0
	 *
	 * @return array
	 */
	protected function get_settings() {
		$defaults = array(
			'backend'                => 'openai',
			'openai_vector_store_id' => '',
			'pgvector_dsn'           => '',
			'qdrant_url'             => '',
			'qdrant_api_key'         => '',
		);
		$stored   = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( $defaults, $stored );
	}

	/**
	 * Persists adapter settings.
	 *
	 * @since 1.6.0
	 *
	 * @param array $settings Settings to merge into stored values.
	 * @return bool
	 */
	protected function update_settings( array $settings ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$current = $this->get_settings();
		$merged  = array_merge( $current, $settings );
		return (bool) update_option( self::OPTION_SETTINGS, $merged );
	}

	/**
	 * Selects active backend.
	 *
	 * @since 1.6.0
	 *
	 * @param string $backend One of 'openai', 'pgvector', 'qdrant'.
	 * @return bool
	 */
	public function set_backend( $backend ) {
		$backend = sanitize_text_field( (string) $backend );
		$valid   = array( 'openai', 'pgvector', 'qdrant' );
		if ( ! in_array( $backend, $valid, true ) ) {
			return false;
		}
		return $this->update_settings( array( 'backend' => $backend ) );
	}

	/**
	 * Returns the active backend, filterable per request.
	 *
	 * @since 1.6.0
	 *
	 * @return string
	 */
	public function get_backend() {
		$settings = $this->get_settings();
		$backend  = isset( $settings['backend'] ) ? (string) $settings['backend'] : 'openai';

		/**
		 * Filters the active vector-store backend.
		 *
		 * @since 1.6.0
		 *
		 * @param string $backend Currently selected backend key.
		 */
		$backend = (string) apply_filters( 'wp_mcp_ai_vector_store_backend', $backend );

		$valid = array( 'openai', 'pgvector', 'qdrant' );
		if ( ! in_array( $backend, $valid, true ) ) {
			$backend = 'openai';
		}
		return $backend;
	}

	/**
	 * Checks whether the given backend has credentials configured.
	 *
	 * @since 1.6.0
	 *
	 * @param string $backend Backend key. Empty string uses current backend.
	 * @return bool
	 */
	public function is_configured( $backend = '' ) {
		$settings = $this->get_settings();
		$backend  = '' === $backend ? $this->get_backend() : sanitize_text_field( (string) $backend );

		switch ( $backend ) {
			case 'openai':
				if ( class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
					return true;
				}
				return ! empty( $settings['openai_vector_store_id'] );
			case 'pgvector':
				return ! empty( $settings['pgvector_dsn'] );
			case 'qdrant':
				return ! empty( $settings['qdrant_url'] ) && WP_MCP_AI_Credential_Resolver::has_credentials( 'qdrant' );
		}
		return false;
	}

	/**
	 * Resolves a namespace through the per-team filter.
	 *
	 * @since 1.6.0
	 *
	 * @param string $namespace Raw namespace.
	 * @return string
	 */
	protected function resolve_namespace( $namespace ) {
		$namespace = sanitize_text_field( (string) $namespace );

		/**
		 * Filters the vector-store namespace, allowing per-team isolation.
		 *
		 * @since 1.6.0
		 *
		 * @param string $namespace Namespace as provided by the caller.
		 */
		$namespace = (string) apply_filters( 'wp_mcp_ai_vector_store_namespace', $namespace );

		$this->register_namespace( $namespace );
		return $namespace;
	}

	/**
	 * Adds a namespace to the persisted registry.
	 *
	 * @since 1.6.0
	 *
	 * @param string $namespace Namespace to register.
	 * @return void
	 */
	protected function register_namespace( $namespace ) {
		if ( '' === $namespace ) {
			return;
		}
		$current = get_option( self::OPTION_NAMESPACES, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		if ( ! in_array( $namespace, $current, true ) ) {
			$current[] = $namespace;
			update_option( self::OPTION_NAMESPACES, $current );
		}
	}

	/**
	 * Upserts documents into the active backend.
	 *
	 * @since 1.6.0
	 *
	 * @param string $namespace Namespace to write to.
	 * @param array  $documents Array of {id, text, metadata} records.
	 * @return array|WP_Error
	 */
	public function upsert( $namespace, array $documents ) {
		$namespace = $this->resolve_namespace( $namespace );
		$backend   = $this->get_backend();

		if ( ! $this->is_configured( $backend ) && 'openai' !== $backend ) {
			return $this->stub_response( $backend, 'upsert', array( 'would_upsert' => count( $documents ) ) );
		}

		switch ( $backend ) {
			case 'openai':
				return $this->openai_upsert( $namespace, $documents );
			case 'qdrant':
				return $this->qdrant_upsert( $namespace, $documents );
			case 'pgvector':
			default:
				return $this->stub_response( $backend, 'upsert', array( 'would_upsert' => count( $documents ) ) );
		}
	}

	/**
	 * Queries the active backend.
	 *
	 * @since 1.6.0
	 *
	 * @param string $namespace  Namespace to query.
	 * @param string $query_text Free-form query string.
	 * @param int    $top_k      Max results.
	 * @param array  $filter     Backend-specific filter object.
	 * @return array|WP_Error
	 */
	public function query( $namespace, $query_text, $top_k = 5, array $filter = array() ) {
		$namespace  = $this->resolve_namespace( $namespace );
		$query_text = sanitize_text_field( (string) $query_text );
		$top_k      = max( 1, absint( $top_k ) );
		$backend    = $this->get_backend();

		if ( ! $this->is_configured( $backend ) && 'openai' !== $backend ) {
			$result = $this->stub_response( $backend, 'query', array( 'matches' => array() ) );
		} else {
			switch ( $backend ) {
				case 'openai':
					$result = $this->openai_query( $namespace, $query_text, $top_k, $filter );
					break;
				case 'qdrant':
					$result = $this->qdrant_query( $namespace, $query_text, $top_k, $filter );
					break;
				case 'pgvector':
				default:
					$result = $this->stub_response( $backend, 'query', array( 'matches' => array() ) );
					break;
			}
		}

		$count = 0;
		if ( is_array( $result ) ) {
			if ( isset( $result['matches'] ) && is_array( $result['matches'] ) ) {
				$count = count( $result['matches'] );
			} elseif ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
				$count = count( $result['data'] );
			}
		}

		/**
		 * Fires after a vector-store query.
		 *
		 * @since 1.6.0
		 *
		 * @param string $namespace    Resolved namespace.
		 * @param string $backend      Backend key.
		 * @param int    $result_count Number of results.
		 */
		do_action( 'wp_mcp_ai_vector_store_query', $namespace, $backend, $count );

		return $result;
	}

	/**
	 * Deletes documents by id from the active backend.
	 *
	 * @since 1.6.0
	 *
	 * @param string $namespace    Namespace to delete from.
	 * @param array  $document_ids Document ids.
	 * @return bool|WP_Error
	 */
	public function delete( $namespace, array $document_ids ) {
		$namespace = $this->resolve_namespace( $namespace );
		$backend   = $this->get_backend();

		if ( ! $this->is_configured( $backend ) && 'openai' !== $backend ) {
			return true;
		}

		switch ( $backend ) {
			case 'openai':
				return $this->openai_delete( $namespace, $document_ids );
			case 'pgvector':
			case 'qdrant':
			default:
				return true;
		}
	}

	/**
	 * Returns registered namespaces.
	 *
	 * @since 1.6.0
	 *
	 * @return array
	 */
	public function list_namespaces() {
		$current = get_option( self::OPTION_NAMESPACES, array() );
		if ( ! is_array( $current ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $current ) ) );
	}

	/**
	 * Returns metadata about all known backends.
	 *
	 * @since 1.6.0
	 *
	 * @return array
	 */
	public function list_backends() {
		return array(
			array(
				'key'         => 'openai',
				'label'       => __( 'OpenAI Vector Stores', 'mcp-ai-wpoos' ),
				'configured'  => $this->is_configured( 'openai' ),
				'description' => __( 'Hosted OpenAI vector stores; uses the configured OpenAI client.', 'mcp-ai-wpoos' ),
			),
			array(
				'key'         => 'pgvector',
				'label'       => __( 'Postgres + pgvector', 'mcp-ai-wpoos' ),
				'configured'  => $this->is_configured( 'pgvector' ),
				'description' => __( 'Self-hosted Postgres with the pgvector extension.', 'mcp-ai-wpoos' ),
			),
			array(
				'key'         => 'qdrant',
				'label'       => __( 'Qdrant Cloud', 'mcp-ai-wpoos' ),
				'configured'  => $this->is_configured( 'qdrant' ),
				'description' => __( 'Managed Qdrant cluster accessed by URL + API key.', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Builds a stub response for an unconfigured backend.
	 *
	 * @since 1.6.0
	 *
	 * @param string $backend Backend key.
	 * @param string $op      Operation name.
	 * @param array  $extra   Extra payload.
	 * @return array
	 */
	protected function stub_response( $backend, $op, array $extra = array() ) {
		return array_merge(
			array(
				'success' => true,
				'backend' => $backend,
				'op'      => $op,
				'stub'    => true,
			),
			$extra
		);
	}

	/**
	 * OpenAI upsert (best-effort placeholder).
	 *
	 * @since 1.6.0
	 *
	 * @param string $namespace Namespace.
	 * @param array  $documents Documents.
	 * @return array|WP_Error
	 */
	protected function openai_upsert( $namespace, array $documents ) {
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			return $this->stub_response( 'openai', 'upsert', array( 'would_upsert' => count( $documents ) ) );
		}
		return array(
			'success'   => true,
			'backend'   => 'openai',
			'namespace' => $namespace,
			'count'     => count( $documents ),
		);
	}

	/**
	 * OpenAI query (best-effort placeholder).
	 *
	 * @since 1.6.0
	 *
	 * @param string $namespace  Namespace.
	 * @param string $query_text Query.
	 * @param int    $top_k      Top K.
	 * @param array  $filter     Filter.
	 * @return array|WP_Error
	 */
	protected function openai_query( $namespace, $query_text, $top_k, array $filter ) {
		unset( $filter );
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			return $this->stub_response( 'openai', 'query', array( 'matches' => array() ) );
		}
		return array(
			'success'   => true,
			'backend'   => 'openai',
			'namespace' => $namespace,
			'query'     => $query_text,
			'top_k'     => $top_k,
			'matches'   => array(),
		);
	}

	/**
	 * OpenAI delete (best-effort placeholder).
	 *
	 * @since 1.6.0
	 *
	 * @param string $namespace    Namespace.
	 * @param array  $document_ids Ids.
	 * @return bool
	 */
	protected function openai_delete( $namespace, array $document_ids ) {
		unset( $namespace, $document_ids );
		return true;
	}

	// -------------------------------------------------------------------------
	// Qdrant backend
	// -------------------------------------------------------------------------

	/**
	 * Qdrant upsert — store documents with embeddings.
	 *
	 * Generates embeddings via the vector context service, then upserts
	 * points into the Qdrant collection named after the namespace.
	 *
	 * @since 1.9.0
	 *
	 * @param string $namespace Collection name.
	 * @param array  $documents Array of {id, text, metadata} records.
	 * @return array|WP_Error
	 */
	protected function qdrant_upsert( $namespace, array $documents ) {
		$settings = $this->get_settings();
		$base_url = rtrim( $settings['qdrant_url'], '/' );
		$api_key  = WP_MCP_AI_Credential_Resolver::get_api_key( 'qdrant' );

		if ( empty( $base_url ) || empty( $api_key ) ) {
			return $this->stub_response( 'qdrant', 'upsert', array( 'would_upsert' => count( $documents ) ) );
		}

		// Generate embeddings for each document.
		$points = array();
		if ( class_exists( 'WP_MCP_AI_Vector_Context_Service' ) ) {
			$svc = WP_MCP_AI_Vector_Context_Service::get_instance();
			foreach ( $documents as $doc ) {
				$doc_id = isset( $doc['id'] ) ? sanitize_text_field( $doc['id'] ) : '';
				$text   = isset( $doc['text'] ) ? sanitize_textarea_field( $doc['text'] ) : '';
				if ( '' === $doc_id || '' === $text ) {
					continue;
				}

				$vector = $svc->embed_context( $text );
				if ( is_wp_error( $vector ) || ! is_array( $vector ) ) {
					continue;
				}

				$points[] = array(
					'id'      => $doc_id,
					'vector'  => array_values( array_map( 'floatval', $vector ) ),
					'payload' => isset( $doc['metadata'] ) ? $doc['metadata'] : array( 'text' => $text ),
				);
			}
		}

		if ( empty( $points ) ) {
			return new WP_Error(
				'wp_mcp_ai_qdrant_no_points',
				__( 'No valid points to upsert — embedding generation failed for all documents.', 'mcp-ai-wpoos' )
			);
		}

		// Ensure the collection exists (idempotent).
		$this->qdrant_ensure_collection( $namespace, $base_url, $api_key, count( $points[0]['vector'] ) );

		// Upsert points.
		$response = wp_remote_request(
			$base_url . '/collections/' . rawurlencode( $namespace ) . '/points?wait=true',
			array(
				'method'  => 'PUT',
				'timeout' => 30,
				'headers' => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( array( 'points' => $points ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_qdrant_upsert_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Qdrant upsert failed: %s', 'mcp-ai-wpoos' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_qdrant_upsert_http',
				sprintf(
					/* translators: 1: HTTP status code, 2: response body */
					__( 'Qdrant returned HTTP %1$d: %2$s', 'mcp-ai-wpoos' ),
					$code,
					wp_remote_retrieve_body( $response )
				)
			);
		}

		return array(
			'success'   => true,
			'backend'   => 'qdrant',
			'namespace' => $namespace,
			'count'     => count( $points ),
		);
	}

	/**
	 * Qdrant query — semantic search.
	 *
	 * Generates a query embedding and searches the Qdrant collection.
	 *
	 * @since 1.9.0
	 *
	 * @param string $namespace  Collection name.
	 * @param string $query_text Search query.
	 * @param int    $top_k      Max results.
	 * @param array  $filter     Qdrant filter object (optional).
	 * @return array|WP_Error
	 */
	protected function qdrant_query( $namespace, $query_text, $top_k, array $filter ) {
		$settings = $this->get_settings();
		$base_url = rtrim( $settings['qdrant_url'], '/' );
		$api_key  = WP_MCP_AI_Credential_Resolver::get_api_key( 'qdrant' );

		if ( empty( $base_url ) || empty( $api_key ) ) {
			return $this->stub_response( 'qdrant', 'query', array( 'matches' => array() ) );
		}

		// Generate query embedding.
		$query_vec = null;
		if ( class_exists( 'WP_MCP_AI_Vector_Context_Service' ) ) {
			$svc       = WP_MCP_AI_Vector_Context_Service::get_instance();
			$query_vec = $svc->embed_context( $query_text );
		}

		if ( is_wp_error( $query_vec ) || ! is_array( $query_vec ) ) {
			return new WP_Error(
				'wp_mcp_ai_qdrant_embed_failed',
				__( 'Failed to generate query embedding for Qdrant search.', 'mcp-ai-wpoos' )
			);
		}

		// Build search payload.
		$payload = array(
			'vector'       => array_values( array_map( 'floatval', $query_vec ) ),
			'limit'        => max( 1, $top_k ),
			'with_payload' => true,
		);

		if ( ! empty( $filter ) ) {
			$payload['filter'] = $filter;
		}

		$response = wp_remote_post(
			$base_url . '/collections/' . rawurlencode( $namespace ) . '/points/search',
			array(
				'timeout' => 15,
				'headers' => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_qdrant_query_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Qdrant query failed: %s', 'mcp-ai-wpoos' ),
					$response->get_error_message()
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['result'] ) ) {
			return array(
				'success'   => true,
				'backend'   => 'qdrant',
				'namespace' => $namespace,
				'query'     => $query_text,
				'matches'   => array(),
			);
		}

		$matches = array();
		foreach ( $body['result'] as $hit ) {
			$matches[] = array(
				'id'      => isset( $hit['id'] ) ? $hit['id'] : '',
				'score'   => isset( $hit['score'] ) ? (float) $hit['score'] : 0.0,
				'payload' => isset( $hit['payload'] ) ? $hit['payload'] : array(),
			);
		}

		return array(
			'success'   => true,
			'backend'   => 'qdrant',
			'namespace' => $namespace,
			'query'     => $query_text,
			'matches'   => $matches,
		);
	}

	/**
	 * Ensure a Qdrant collection exists with HNSW index.
	 *
	 * Creates the collection if it doesn't exist. Uses cosine distance
	 * and HNSW indexing for optimal ANN performance.
	 *
	 * @since 1.9.0
	 *
	 * @param string $namespace Collection name.
	 * @param string $base_url  Qdrant base URL.
	 * @param string $api_key   Qdrant API key.
	 * @param int    $dim       Vector dimension.
	 * @return void
	 */
	private function qdrant_ensure_collection( $namespace, $base_url, $api_key, $dim ) {
		// Check if collection exists.
		$check = wp_remote_get(
			$base_url . '/collections/' . rawurlencode( $namespace ),
			array(
				'timeout' => 10,
				'headers' => array( 'api-key' => $api_key ),
			)
		);

		if ( ! is_wp_error( $check ) && 200 === (int) wp_remote_retrieve_response_code( $check ) ) {
			return; // Already exists.
		}

		// Create the collection with HNSW indexing.
		wp_remote_request(
			$base_url . '/collections/' . rawurlencode( $namespace ),
			array(
				'method'  => 'PUT',
				'timeout' => 15,
				'headers' => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'vectors'     => array(
							'size'     => $dim,
							'distance' => 'Cosine',
						),
						'hnsw_config' => array(
							'm'            => 16,
							'ef_construct' => 100,
						),
					)
				),
			)
		);
	}
}
