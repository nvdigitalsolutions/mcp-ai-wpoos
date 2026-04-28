<?php
/**
 * NV oOS Graphify — Generic GraphQL API Remote Driver (Pro)
 *
 * Imports nodes (and optionally edges) from any GraphQL endpoint by
 * POSTing a configurable query and walking the response with the same
 * dot-notation path mapping used by the Generic REST driver.
 *
 * Supports the same auth modes (bearer / basic / api_key / none),
 * arbitrary `variables` (JSON) and `extra_headers` (JSON), plus the
 * standard incremental `since` variable substituted from the per-source
 * state store when `incremental_var` is set.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic GraphQL API remote source driver.
 *
 * @since 0.7.4
 */
class NV_oOS_Graphify_Remote_Generic_GraphQL implements NV_oOS_Graphify_Remote_Source_Interface {

	/**
	 * Driver configuration.
	 *
	 * @var array
	 */
	private $config = array();

	/**
	 * HTTP client instance.
	 *
	 * @var NV_oOS_Graphify_HTTP_Client
	 */
	private $http;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->http = new NV_oOS_Graphify_HTTP_Client( 'generic_graphql' );
	}

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'generic_graphql';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'Generic GraphQL API', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $config Driver configuration array.
	 */
	public function set_config( array $config ) {
		$this->config = $config;
		$slug         = isset( $config['_slug'] ) ? $config['_slug'] : 'generic_graphql';
		$this->http   = new NV_oOS_Graphify_HTTP_Client( $slug );
	}

	/** {@inheritdoc} */
	public function get_config() {
		return $this->config;
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'fetch_nodes', 'fetch_edges' );
	}

	/**
	 * Capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'supports_incremental'   => true,
			'supports_webhooks'      => false,
			'supports_oauth'         => false,
			'supports_pagination'    => false,
			'supports_relationships' => true,
		);
	}

	/** {@inheritdoc} */
	public function get_config_schema() {
		return array(
			'endpoint_url'        => array(
				'type'        => 'url',
				'label'       => __( 'GraphQL Endpoint URL', 'nvoos-graphify' ),
				'description' => __( 'POST URL of the GraphQL endpoint.', 'nvoos-graphify' ),
				'required'    => true,
			),
			'query'               => array(
				'type'        => 'textarea',
				'label'       => __( 'GraphQL Query', 'nvoos-graphify' ),
				'description' => __( 'Query or operation document to POST.', 'nvoos-graphify' ),
				'required'    => true,
			),
			'variables_json'      => array(
				'type'        => 'textarea',
				'label'       => __( 'Variables (JSON)', 'nvoos-graphify' ),
				'description' => __( 'Optional JSON object passed as `variables`.', 'nvoos-graphify' ),
				'default'     => '',
			),
			'extra_headers_json'  => array(
				'type'        => 'textarea',
				'label'       => __( 'Extra Headers (JSON)', 'nvoos-graphify' ),
				'description' => __( 'Optional JSON object of additional request headers.', 'nvoos-graphify' ),
				'default'     => '',
			),
			'auth_type'           => array(
				'type'    => 'text',
				'label'   => __( 'Auth Type', 'nvoos-graphify' ),
				'default' => 'none',
			),
			'auth_value'          => array(
				'type'  => 'password',
				'label' => __( 'Auth Value', 'nvoos-graphify' ),
			),
			'auth_header'         => array(
				'type'    => 'text',
				'label'   => __( 'API Key Header Name', 'nvoos-graphify' ),
				'default' => 'X-Api-Key',
			),
			'node_path'           => array(
				'type'        => 'text',
				'label'       => __( 'Node Path', 'nvoos-graphify' ),
				'description' => __( 'Dot-notation path inside the response (e.g. data.items.edges).', 'nvoos-graphify' ),
				'default'     => 'data',
			),
			'edge_path'           => array(
				'type'    => 'text',
				'label'   => __( 'Edge Path', 'nvoos-graphify' ),
				'default' => '',
			),
			'node_id_field'       => array(
				'type'    => 'text',
				'label'   => __( 'ID Field', 'nvoos-graphify' ),
				'default' => 'id',
			),
			'node_label_field'    => array(
				'type'    => 'text',
				'label'   => __( 'Label Field', 'nvoos-graphify' ),
				'default' => 'name',
			),
			'node_url_field'      => array(
				'type'    => 'text',
				'label'   => __( 'URL Field', 'nvoos-graphify' ),
				'default' => 'url',
			),
			'node_type_field'     => array(
				'type'    => 'text',
				'label'   => __( 'Type Field', 'nvoos-graphify' ),
				'default' => 'type',
			),
			'edge_source_field'   => array(
				'type'    => 'text',
				'label'   => __( 'Edge Source Field', 'nvoos-graphify' ),
				'default' => 'source',
			),
			'edge_target_field'   => array(
				'type'    => 'text',
				'label'   => __( 'Edge Target Field', 'nvoos-graphify' ),
				'default' => 'target',
			),
			'edge_relation_field' => array(
				'type'    => 'text',
				'label'   => __( 'Edge Relation Field', 'nvoos-graphify' ),
				'default' => 'relation',
			),
			'incremental_var'     => array(
				'type'        => 'text',
				'label'       => __( 'Incremental Variable', 'nvoos-graphify' ),
				'description' => __( 'When set, the cursor from the state store is injected into `variables` under this key (e.g. "updatedSince").', 'nvoos-graphify' ),
				'default'     => '',
			),
		);
	}

	/** {@inheritdoc} */
	public function test_connection() {
		$endpoint = $this->get_endpoint_url();
		if ( empty( $endpoint ) ) {
			return array(
				'success' => false,
				'message' => __( 'No endpoint_url configured.', 'nvoos-graphify' ),
			);
		}
		$query = isset( $this->config['query'] ) ? trim( (string) $this->config['query'] ) : '';
		if ( '' === $query ) {
			// Probe with an introspection-light query if no operation has been
			// configured yet — most servers will accept it.
			$query = '{ __typename }';
		}

		$result = $this->post_graphql( $endpoint, $query, array() );
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
				'message' => sprintf( __( 'HTTP %d.', 'nvoos-graphify' ), (int) $result['status'] ),
			);
		}
		$body = json_decode( (string) $result['body'], true );
		if ( is_array( $body ) && ! empty( $body['errors'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'GraphQL endpoint returned errors.', 'nvoos-graphify' ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Connected.', 'nvoos-graphify' ),
		);
	}

	/** {@inheritdoc} */
	public function discover() {
		return array(
			'driver'       => $this->get_driver_id(),
			'label'        => $this->get_driver_label(),
			'endpoint_url' => $this->get_endpoint_url(),
			'capabilities' => $this->get_capabilities(),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_nodes( array $args = array() ) {
		$endpoint    = $this->get_endpoint_url();
		$source_slug = isset( $this->config['_slug'] ) ? $this->config['_slug'] : 'generic_graphql';
		$query       = isset( $this->config['query'] ) ? (string) $this->config['query'] : '';

		if ( '' === $endpoint || '' === trim( $query ) ) {
			return array();
		}

		$variables = $this->resolve_variables( $source_slug );
		$result    = $this->post_graphql( $endpoint, $query, $variables );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}

		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) ) {
			return array();
		}

		$node_path = isset( $this->config['node_path'] ) ? (string) $this->config['node_path'] : 'data';
		$items     = $this->extract_path( $body, $node_path );
		if ( ! is_array( $items ) ) {
			return array();
		}

		$label_field = isset( $this->config['node_label_field'] ) ? (string) $this->config['node_label_field'] : 'name';
		$type_field  = isset( $this->config['node_type_field'] ) ? (string) $this->config['node_type_field'] : 'type';
		$id_field    = isset( $this->config['node_id_field'] ) ? (string) $this->config['node_id_field'] : 'id';
		$url_field   = isset( $this->config['node_url_field'] ) ? (string) $this->config['node_url_field'] : 'url';

		$nodes = array();
		foreach ( $items as $item ) {
			// GraphQL Connections often wrap entries in `{ node: {...} }`.
			if ( is_array( $item ) && isset( $item['node'] ) && is_array( $item['node'] ) ) {
				$item = $item['node'];
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label     = isset( $item[ $label_field ] ) ? sanitize_text_field( (string) $item[ $label_field ] ) : '';
			$remote_id = isset( $item[ $id_field ] ) ? sanitize_text_field( (string) $item[ $id_field ] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$type    = isset( $item[ $type_field ] ) ? sanitize_text_field( (string) $item[ $type_field ] ) : 'entity';
			$url     = isset( $item[ $url_field ] ) ? esc_url_raw( (string) $item[ $url_field ] ) : '';
			$node_id = 'remote_' . sanitize_key( $source_slug ) . '_' . ( '' !== $remote_id ? sanitize_key( $remote_id ) : md5( $label ) );

			$nodes[] = array(
				'node_id'     => $node_id,
				'label'       => $label,
				'type'        => $type,
				'post_id'     => 0,
				'url'         => $url,
				'properties'  => $item,
				'source_slug' => $source_slug,
				'provenance'  => 'REMOTE',
				'external_id' => $remote_id,
			);
		}

		// Stamp the cursor for the next incremental run.
		NV_oOS_Graphify_Remote_State_Store::set( $source_slug, 'last_run_iso', gmdate( 'c' ) );
		NV_oOS_Graphify_Remote_State_Store::mark_synced( $source_slug );
		return $nodes;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_edges( array $args = array() ) {
		$edge_path = isset( $this->config['edge_path'] ) ? (string) $this->config['edge_path'] : '';
		if ( '' === $edge_path ) {
			return array();
		}

		$endpoint    = $this->get_endpoint_url();
		$source_slug = isset( $this->config['_slug'] ) ? $this->config['_slug'] : 'generic_graphql';
		$query       = isset( $this->config['query'] ) ? (string) $this->config['query'] : '';

		if ( '' === $endpoint || '' === trim( $query ) ) {
			return array();
		}

		$result = $this->post_graphql( $endpoint, $query, $this->resolve_variables( $source_slug ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body  = json_decode( (string) $result['body'], true );
		$items = $this->extract_path( $body, $edge_path );
		if ( ! is_array( $items ) ) {
			return array();
		}

		$source_field   = isset( $this->config['edge_source_field'] ) ? (string) $this->config['edge_source_field'] : 'source';
		$target_field   = isset( $this->config['edge_target_field'] ) ? (string) $this->config['edge_target_field'] : 'target';
		$relation_field = isset( $this->config['edge_relation_field'] ) ? (string) $this->config['edge_relation_field'] : 'relation';

		$edges = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['node'] ) && is_array( $item['node'] ) ) {
				$item = $item['node'];
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$src = isset( $item[ $source_field ] ) ? sanitize_text_field( (string) $item[ $source_field ] ) : '';
			$tgt = isset( $item[ $target_field ] ) ? sanitize_text_field( (string) $item[ $target_field ] ) : '';
			$rel = isset( $item[ $relation_field ] ) ? sanitize_text_field( (string) $item[ $relation_field ] ) : 'RELATED_TO';
			if ( '' === $src || '' === $tgt ) {
				continue;
			}
			$edges[] = array(
				'source_node_id' => 'remote_' . sanitize_key( $source_slug ) . '_' . sanitize_key( $src ),
				'target_node_id' => 'remote_' . sanitize_key( $source_slug ) . '_' . sanitize_key( $tgt ),
				'relation'       => strtoupper( $rel ),
				'confidence'     => 1.0,
				'provenance'     => 'REMOTE',
				'source_slug'    => $source_slug,
			);
		}
		return $edges;
	}

	/**
	 * Reconciliation not supported by generic GraphQL driver.
	 *
	 * @param object $local_node Unused.
	 * @return array
	 */
	public function reconcile( $local_node ) {
		unset( $local_node );
		return array(
			'external_id' => '',
			'confidence'  => 0.0,
			'matched'     => false,
		);
	}

	// -------------------------------------------------------------------------
	// HTTP / helpers
	// -------------------------------------------------------------------------

	/**
	 * POST a GraphQL operation with merged headers.
	 *
	 * @param string $endpoint  Endpoint URL.
	 * @param string $query     GraphQL document.
	 * @param array  $variables Variables payload.
	 * @return array|WP_Error
	 */
	private function post_graphql( $endpoint, $query, array $variables ) {
		$headers = array_merge(
			array( 'Content-Type' => 'application/json' ),
			$this->get_auth_headers(),
			$this->get_extra_headers()
		);
		$payload = array( 'query' => $query );
		if ( ! empty( $variables ) ) {
			$payload['variables'] = $variables;
		}
		return $this->http->post(
			$endpoint,
			wp_json_encode( $payload ),
			array( 'headers' => $headers )
		);
	}

	/**
	 * Resolve the variables payload — base config + optional incremental
	 * cursor injection.
	 *
	 * @param string $source_slug Source slug.
	 * @return array
	 */
	private function resolve_variables( $source_slug ) {
		$variables = array();
		if ( ! empty( $this->config['variables_json'] ) ) {
			$decoded = json_decode( (string) $this->config['variables_json'], true );
			if ( is_array( $decoded ) ) {
				$variables = $decoded;
			}
		}
		$incremental_var = isset( $this->config['incremental_var'] ) ? trim( (string) $this->config['incremental_var'] ) : '';
		if ( '' !== $incremental_var ) {
			$cursor = (string) NV_oOS_Graphify_Remote_State_Store::get( $source_slug, 'last_run_iso', '' );
			if ( '' !== $cursor ) {
				$variables[ $incremental_var ] = $cursor;
			}
		}
		return $variables;
	}

	/**
	 * Reads `extra_headers_json` from config and decodes it; only string
	 * values are accepted.
	 *
	 * @return array
	 */
	private function get_extra_headers() {
		if ( empty( $this->config['extra_headers_json'] ) ) {
			return array();
		}
		$decoded = json_decode( (string) $this->config['extra_headers_json'], true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $name => $value ) {
			if ( is_string( $name ) && is_scalar( $value ) ) {
				$out[ sanitize_text_field( $name ) ] = sanitize_text_field( (string) $value );
			}
		}
		return $out;
	}

	/**
	 * Build Authorization headers from config (mirrors generic_rest).
	 *
	 * @return array
	 */
	private function get_auth_headers() {
		$auth_type  = isset( $this->config['auth_type'] ) ? (string) $this->config['auth_type'] : 'none';
		$auth_value = isset( $this->config['auth_value'] ) ? (string) $this->config['auth_value'] : '';
		$headers    = array();

		switch ( $auth_type ) {
			case 'bearer':
				if ( '' !== $auth_value ) {
					$headers['Authorization'] = 'Bearer ' . $auth_value;
				}
				break;
			case 'basic':
				if ( '' !== $auth_value ) {
					$headers['Authorization'] = 'Basic ' . base64_encode( $auth_value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				}
				break;
			case 'api_key':
				$header_name = isset( $this->config['auth_header'] ) ? sanitize_text_field( (string) $this->config['auth_header'] ) : 'X-Api-Key';
				if ( '' !== $auth_value && '' !== $header_name ) {
					$headers[ $header_name ] = $auth_value;
				}
				break;
		}
		return $headers;
	}

	/**
	 * Extract a nested value from an array using dot-notation path.
	 *
	 * @param array  $data Data array.
	 * @param string $path Dot-notation path.
	 * @return mixed
	 */
	private function extract_path( $data, $path ) {
		if ( '' === $path || ! is_array( $data ) ) {
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
	 * Resolve the configured endpoint URL.
	 *
	 * @return string
	 */
	private function get_endpoint_url() {
		$url = isset( $this->config['endpoint_url'] ) ? (string) $this->config['endpoint_url'] : '';
		return esc_url_raw( $url );
	}
}
