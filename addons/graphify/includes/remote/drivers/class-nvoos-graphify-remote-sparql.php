<?php
/**
 * NV oOS Graphify — SPARQL Endpoint Remote Driver
 *
 * Imports RDF-structured nodes and edges from a SPARQL 1.1 endpoint that
 * returns results in SPARQL JSON format.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SPARQL endpoint remote source driver.
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_Remote_SPARQL implements NV_oOS_Graphify_Remote_Source_Interface {

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
		$this->http = new NV_oOS_Graphify_HTTP_Client( 'sparql' );
	}

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'sparql';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'SPARQL Endpoint (RDF)', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $config Driver configuration array.
	 */
	public function set_config( array $config ) {
		$this->config = $config;
		$slug         = isset( $config['_slug'] ) ? $config['_slug'] : 'sparql';
		$this->http   = new NV_oOS_Graphify_HTTP_Client( $slug );
	}

	/** {@inheritdoc} */
	public function get_config() {
		return $this->config;
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'fetch_nodes', 'fetch_edges', 'reconcile' );
	}

	/** {@inheritdoc} */
	public function get_config_schema() {
		return array(
			'endpoint'  => array(
				'type'        => 'url',
				'label'       => __( 'SPARQL Endpoint URL', 'nvoos-graphify' ),
				'description' => __( 'SPARQL 1.1 SELECT endpoint URL.', 'nvoos-graphify' ),
				'required'    => true,
			),
			'query'     => array(
				'type'        => 'textarea',
				'label'       => __( 'SPARQL Query', 'nvoos-graphify' ),
				'description' => __( 'SELECT query returning ?id ?label ?url variables.', 'nvoos-graphify' ),
			),
			'var_id'    => array(
				'type'    => 'text',
				'label'   => __( 'ID Variable', 'nvoos-graphify' ),
				'default' => 'id',
			),
			'var_label' => array(
				'type'    => 'text',
				'label'   => __( 'Label Variable', 'nvoos-graphify' ),
				'default' => 'label',
			),
			'var_url'   => array(
				'type'    => 'text',
				'label'   => __( 'URL Variable', 'nvoos-graphify' ),
				'default' => 'url',
			),
			'api_token' => array(
				'type'  => 'password',
				'label' => __( 'Bearer Token (optional)', 'nvoos-graphify' ),
			),
		);
	}

	/** {@inheritdoc} */
	public function test_connection() {
		$endpoint = $this->get_endpoint();
		if ( empty( $endpoint ) ) {
			return array(
				'success' => false,
				'message' => __( 'No endpoint_url configured.', 'nvoos-graphify' ),
			);
		}

		$result = $this->execute_query( 'ASK { ?s ?p ?o }', 'ask' );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'SPARQL endpoint accessible.', 'nvoos-graphify' ),
		);
	}

	/** {@inheritdoc} */
	public function discover() {
		return array(
			'driver'       => $this->get_driver_id(),
			'label'        => $this->get_driver_label(),
			'endpoint_url' => $this->get_endpoint(),
			'capabilities' => $this->get_capabilities(),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_nodes( array $args = array() ) {
		$node_query  = isset( $this->config['node_query'] ) ? $this->config['node_query'] : '';
		$source_slug = isset( $this->config['_slug'] ) ? $this->config['_slug'] : 'sparql';

		if ( empty( $node_query ) ) {
			return array();
		}

		$query  = $this->prepend_prefixes( $node_query );
		$result = $this->execute_query( $query, 'select' );

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return array();
		}

		$nodes = array();
		foreach ( $result as $binding ) {
			$id    = isset( $binding['id']['value'] ) ? sanitize_text_field( $binding['id']['value'] ) : '';
			$label = isset( $binding['label']['value'] ) ? sanitize_text_field( $binding['label']['value'] ) : '';
			$type  = isset( $binding['type']['value'] ) ? sanitize_text_field( $binding['type']['value'] ) : 'entity';
			$url   = isset( $binding['url']['value'] ) ? esc_url_raw( $binding['url']['value'] ) : '';

			if ( empty( $label ) ) {
				continue;
			}

			$node_id = 'remote_' . sanitize_key( $source_slug ) . '_' . ( $id ? md5( $id ) : md5( $label ) );

			$nodes[] = array(
				'node_id'     => $node_id,
				'label'       => $label,
				'type'        => $type,
				'post_id'     => 0,
				'url'         => $url,
				'properties'  => array( 'rdf_id' => $id ),
				'source_slug' => $source_slug,
				'provenance'  => 'REMOTE',
				'external_id' => $id,
			);
		}

		return $nodes;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_edges( array $args = array() ) {
		$edge_query  = isset( $this->config['edge_query'] ) ? $this->config['edge_query'] : '';
		$source_slug = isset( $this->config['_slug'] ) ? $this->config['_slug'] : 'sparql';

		if ( empty( $edge_query ) ) {
			return array();
		}

		$query  = $this->prepend_prefixes( $edge_query );
		$result = $this->execute_query( $query, 'select' );

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return array();
		}

		$edges = array();
		foreach ( $result as $binding ) {
			$src        = isset( $binding['source']['value'] ) ? sanitize_text_field( $binding['source']['value'] ) : '';
			$tgt        = isset( $binding['target']['value'] ) ? sanitize_text_field( $binding['target']['value'] ) : '';
			$relation   = isset( $binding['relation']['value'] ) ? sanitize_text_field( $binding['relation']['value'] ) : 'RELATED_TO';
			$confidence = isset( $binding['confidence']['value'] ) ? floatval( $binding['confidence']['value'] ) : 1.0;

			if ( empty( $src ) || empty( $tgt ) ) {
				continue;
			}

			$edges[] = array(
				'source_node_id' => 'remote_' . sanitize_key( $source_slug ) . '_' . md5( $src ),
				'target_node_id' => 'remote_' . sanitize_key( $source_slug ) . '_' . md5( $tgt ),
				'relation'       => strtoupper( $relation ),
				'confidence'     => max( 0.0, min( 1.0, $confidence ) ),
				'provenance'     => 'REMOTE',
				'source_slug'    => $source_slug,
			);
		}

		return $edges;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array|object $local_node Local node to reconcile.
	 */
	public function reconcile( $local_node ) {
		$label       = is_object( $local_node ) ? $local_node->label : ( isset( $local_node['label'] ) ? $local_node['label'] : '' );
		$label       = sanitize_text_field( $label );
		$source_slug = isset( $this->config['_slug'] ) ? $this->config['_slug'] : 'sparql';

		if ( empty( $label ) ) {
			return array(
				'external_id' => '',
				'confidence'  => 0.0,
				'matched'     => false,
			);
		}

		// Build a lookup query for this label.
		$label_escaped = str_replace( array( '"', '\\' ), array( '\\"', '\\\\' ), $label );
		$query         = $this->prepend_prefixes(
			"SELECT ?id ?label WHERE { ?id rdfs:label ?label . FILTER(LCASE(STR(?label)) = LCASE(\"{$label_escaped}\")) } LIMIT 3"
		);
		$result        = $this->execute_query( $query, 'select' );

		if ( is_wp_error( $result ) || empty( $result ) ) {
			return array(
				'external_id' => '',
				'confidence'  => 0.0,
				'matched'     => false,
			);
		}

		$first = reset( $result );
		$id    = isset( $first['id']['value'] ) ? sanitize_text_field( $first['id']['value'] ) : '';

		if ( empty( $id ) ) {
			return array(
				'external_id' => '',
				'confidence'  => 0.0,
				'matched'     => false,
			);
		}

		$remote_label = isset( $first['label']['value'] ) ? $first['label']['value'] : '';
		$confidence   = ( strtolower( $label ) === strtolower( $remote_label ) ) ? 1.0 : 0.8;

		return array(
			'external_id' => $id,
			'confidence'  => $confidence,
			'matched'     => true,
		);
	}

	/**
	 * Execute a SPARQL query against the endpoint.
	 *
	 * @since 0.6.0
	 *
	 * @param string $query     SPARQL query string.
	 * @param string $type      'select' or 'ask'.
	 * @return array|WP_Error Bindings array or WP_Error.
	 */
	private function execute_query( $query, $type = 'select' ) {
		$endpoint = $this->get_endpoint();
		if ( empty( $endpoint ) ) {
			return new WP_Error( 'no_endpoint', __( 'No SPARQL endpoint configured.', 'nvoos-graphify' ) );
		}

		$headers = array_merge(
			array(
				'Accept'       => 'application/sparql-results+json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			$this->get_auth_headers()
		);

		$body   = 'query=' . rawurlencode( $query ) . '&format=json';
		$result = $this->http->post(
			$endpoint,
			array(),
			array(
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['body'], true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid SPARQL JSON response.', 'nvoos-graphify' ) );
		}

		if ( 'ask' === $type ) {
			return isset( $data['boolean'] ) ? array( array( 'boolean' => $data['boolean'] ) ) : array();
		}

		return isset( $data['results']['bindings'] ) ? $data['results']['bindings'] : array();
	}

	/**
	 * Prepend configured PREFIX declarations to a query.
	 *
	 * @since 0.6.0
	 *
	 * @param string $query SPARQL query.
	 * @return string Query with prefixes.
	 */
	private function prepend_prefixes( $query ) {
		$default_prefixes = "PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>\nPREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>\nPREFIX owl: <http://www.w3.org/2002/07/owl#>\n";
		$user_prefixes    = isset( $this->config['prefixes'] ) ? $this->config['prefixes'] : '';
		return $default_prefixes . $user_prefixes . "\n" . $query;
	}

	/**
	 * Return the configured endpoint URL.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	private function get_endpoint() {
		$url = isset( $this->config['endpoint_url'] ) ? $this->config['endpoint_url'] : '';
		return esc_url_raw( $url );
	}

	/**
	 * Build auth headers from config.
	 *
	 * @since 0.6.0
	 *
	 * @return array
	 */
	private function get_auth_headers() {
		$auth_type  = isset( $this->config['auth_type'] ) ? $this->config['auth_type'] : 'none';
		$auth_value = isset( $this->config['auth_value'] ) ? $this->config['auth_value'] : '';

		if ( 'bearer' === $auth_type && $auth_value ) {
			return array( 'Authorization' => 'Bearer ' . $auth_value );
		}
		if ( 'basic' === $auth_type && $auth_value ) {
			return array( 'Authorization' => 'Basic ' . base64_encode( $auth_value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		return array();
	}
}
