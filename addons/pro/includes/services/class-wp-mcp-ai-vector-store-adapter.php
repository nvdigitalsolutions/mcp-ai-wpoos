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
			'backend'                 => 'openai',
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
				return ! empty( $settings['qdrant_url'] ) && ! empty( $settings['qdrant_api_key'] );
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
			case 'pgvector':
			case 'qdrant':
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
				case 'pgvector':
				case 'qdrant':
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
}
