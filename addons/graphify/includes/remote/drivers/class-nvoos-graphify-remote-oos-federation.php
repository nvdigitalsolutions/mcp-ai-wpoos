<?php
/**
 * NV oOS Graphify — oOS Federation Remote Driver
 *
 * Federates with a remote NV oOS / MCP site to import knowledge-graph
 * nodes and perform cross-site entity reconciliation.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remote oOS/MCP site federation driver.
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_Remote_OOS_Federation implements NV_oOS_Graphify_Remote_Source_Interface {

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
		$this->http = new NV_oOS_Graphify_HTTP_Client( 'oos_federation' );
	}

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'oos_federation';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'Remote oOS / MCP Site (Federation)', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $config Driver configuration array.
	 */
	public function set_config( array $config ) {
		$this->config = $config;
		$slug         = isset( $config['_slug'] ) ? $config['_slug'] : 'oos_federation';
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
			'base_url'   => array(
				'type'        => 'url',
				'label'       => __( 'Remote Site URL', 'nvoos-graphify' ),
				'description' => __( 'WordPress site root URL of the remote oOS instance.', 'nvoos-graphify' ),
				'required'    => true,
			),
			'api_token'  => array(
				'type'        => 'password',
				'label'       => __( 'API Token', 'nvoos-graphify' ),
				'description' => __( 'NV oOS credential token (cred_xxxxx.SECRET).', 'nvoos-graphify' ),
				'required'    => true,
			),
			'post_types' => array(
				'type'        => 'text',
				'label'       => __( 'Post Types', 'nvoos-graphify' ),
				'description' => __( 'Comma-separated post types to sync (e.g. post,page).', 'nvoos-graphify' ),
				'default'     => 'post,page',
			),
			'max_nodes'  => array(
				'type'        => 'number',
				'label'       => __( 'Max Nodes', 'nvoos-graphify' ),
				'description' => __( 'Maximum nodes to fetch per sync (0 = unlimited).', 'nvoos-graphify' ),
				'default'     => 200,
			),
		);
	}

	/** {@inheritdoc} */
	public function test_connection() {
		$base_url = $this->get_base_url();
		if ( empty( $base_url ) ) {
			return array(
				'success' => false,
				'message' => __( 'No base_url configured.', 'nvoos-graphify' ),
			);
		}

		$url    = trailingslashit( $base_url ) . 'wp-json/nvoos-graphify/v1/graph';
		$result = $this->http->get( $url, array( 'headers' => $this->get_auth_headers() ) );

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
				'message' => sprintf( __( 'HTTP %d from remote site.', 'nvoos-graphify' ), $result['status'] ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Connected to remote oOS site.', 'nvoos-graphify' ),
		);
	}

	/** {@inheritdoc} */
	public function discover() {
		$base_url = $this->get_base_url();
		if ( empty( $base_url ) ) {
			return array( 'error' => 'No base_url configured.' );
		}

		$url    = trailingslashit( $base_url ) . 'wp-json/nvoos-graphify/v1/graph';
		$result = $this->http->get( $url, array( 'headers' => $this->get_auth_headers() ) );

		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}

		$data = json_decode( $result['body'], true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_nodes( array $args = array() ) {
		$base_url    = $this->get_base_url();
		$max_nodes   = isset( $this->config['max_nodes'] ) ? absint( $this->config['max_nodes'] ) : 200;
		$per_page    = min( 100, $max_nodes );
		$page        = isset( $args['page'] ) ? absint( $args['page'] ) : 1;
		$source_slug = isset( $this->config['_slug'] ) ? $this->config['_slug'] : 'oos_federation';

		if ( empty( $base_url ) ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'per_page' => $per_page,
				'page'     => $page,
			),
			trailingslashit( $base_url ) . 'wp-json/nvoos-graphify/v1/nodes'
		);

		$result = $this->http->get( $url, array( 'headers' => $this->get_auth_headers() ) );
		if ( is_wp_error( $result ) ) {
			return array();
		}

		$data  = json_decode( $result['body'], true );
		$nodes = array();

		if ( ! is_array( $data ) ) {
			return array();
		}

		foreach ( $data as $item ) {
			if ( empty( $item['node_id'] ) || empty( $item['label'] ) ) {
				continue;
			}
			$nodes[] = array(
				'node_id'     => 'remote_' . sanitize_key( $source_slug ) . '_' . sanitize_text_field( $item['node_id'] ),
				'label'       => sanitize_text_field( $item['label'] ),
				'type'        => isset( $item['type'] ) ? sanitize_text_field( $item['type'] ) : 'entity',
				'post_id'     => 0,
				'url'         => isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '',
				'properties'  => isset( $item['properties'] ) ? (array) $item['properties'] : array(),
				'source_slug' => $source_slug,
				'provenance'  => 'REMOTE',
				'external_id' => $item['node_id'],
			);
		}

		return $nodes;
	}

	/**
	 * Edges are fetched implicitly via nodes; returns empty.
	 *
	 * @param array $args Unused.
	 * @return array Empty array.
	 */
	public function fetch_edges( array $args = array() ) {
		return array();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array|object $local_node Local node to reconcile.
	 */
	public function reconcile( $local_node ) {
		$base_url = $this->get_base_url();
		$label    = is_object( $local_node ) ? $local_node->label : ( isset( $local_node['label'] ) ? $local_node['label'] : '' );
		$label    = sanitize_text_field( $label );

		if ( empty( $base_url ) || empty( $label ) ) {
			return array(
				'external_id' => '',
				'confidence'  => 0.0,
				'matched'     => false,
			);
		}

		$url    = add_query_arg(
			array(
				'q'     => rawurlencode( $label ),
				'limit' => 3,
			),
			trailingslashit( $base_url ) . 'wp-json/nvoos-graphify/v1/search'
		);
		$result = $this->http->get( $url, array( 'headers' => $this->get_auth_headers() ) );

		if ( is_wp_error( $result ) ) {
			return array(
				'external_id' => '',
				'confidence'  => 0.0,
				'matched'     => false,
			);
		}

		$data = json_decode( $result['body'], true );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return array(
				'external_id' => '',
				'confidence'  => 0.0,
				'matched'     => false,
			);
		}

		$first = reset( $data );
		if ( empty( $first['node_id'] ) ) {
			return array(
				'external_id' => '',
				'confidence'  => 0.0,
				'matched'     => false,
			);
		}

		// Simple confidence based on exact/partial match.
		$remote_label = isset( $first['label'] ) ? $first['label'] : '';
		$confidence   = 0.6;
		if ( strtolower( $label ) === strtolower( $remote_label ) ) {
			$confidence = 1.0;
		} elseif ( false !== stripos( $remote_label, $label ) ) {
			$confidence = 0.8;
		}

		return array(
			'external_id' => $first['node_id'],
			'confidence'  => $confidence,
			'matched'     => true,
		);
	}

	/**
	 * Return the configured base URL with trailing slash removed.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	private function get_base_url() {
		$url = isset( $this->config['base_url'] ) ? $this->config['base_url'] : '';
		return esc_url_raw( untrailingslashit( $url ) );
	}

	/**
	 * Build Authorization headers from config.
	 *
	 * @since 0.6.0
	 *
	 * @return array
	 */
	private function get_auth_headers() {
		$token = isset( $this->config['auth_token'] ) ? $this->config['auth_token'] : '';
		if ( empty( $token ) ) {
			return array();
		}
		return array( 'Authorization' => 'Bearer ' . $token );
	}
}
