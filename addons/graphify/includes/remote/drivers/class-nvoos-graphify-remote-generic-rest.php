<?php
/**
 * NV oOS Graphify — Generic REST API Remote Driver
 *
 * Imports nodes (and optionally edges) from any REST API using a configurable
 * JSON-path mapping for label, type, ID, and URL fields.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic REST API remote source driver.
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_Remote_Generic_REST implements NV_oOS_Graphify_Remote_Source_Interface {

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
		$this->http = new NV_oOS_Graphify_HTTP_Client( 'generic_rest' );
	}

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'generic_rest';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'Generic REST API', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function set_config( array $config ) {
		$this->config = $config;
		$slug = isset( $config['_slug'] ) ? $config['_slug'] : 'generic_rest';
		$this->http = new NV_oOS_Graphify_HTTP_Client( $slug );
	}

	/** {@inheritdoc} */
	public function get_config() {
		return $this->config;
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'fetch_nodes', 'fetch_edges' );
	}

	/** {@inheritdoc} */
	public function test_connection() {
		$base_url = $this->get_base_url();
		if ( empty( $base_url ) ) {
			return array( 'success' => false, 'message' => __( 'No base_url configured.', 'nvoos-graphify' ) );
		}

		$result = $this->http->get( $base_url, array( 'headers' => $this->get_auth_headers() ) );

		if ( is_wp_error( $result ) ) {
			return array( 'success' => false, 'message' => $result->get_error_message() );
		}

		if ( $result['status'] < 200 || $result['status'] >= 300 ) {
			return array( 'success' => false, 'message' => sprintf( __( 'HTTP %d.', 'nvoos-graphify' ), $result['status'] ) );
		}

		return array( 'success' => true, 'message' => __( 'Connected.', 'nvoos-graphify' ) );
	}

	/** {@inheritdoc} */
	public function discover() {
		return array(
			'driver'       => $this->get_driver_id(),
			'label'        => $this->get_driver_label(),
			'base_url'     => $this->get_base_url(),
			'capabilities' => $this->get_capabilities(),
		);
	}

	/** {@inheritdoc} */
	public function fetch_nodes( array $args = array() ) {
		$base_url    = $this->get_base_url();
		$source_slug = isset( $this->config['_slug'] ) ? $this->config['_slug'] : 'generic_rest';

		if ( empty( $base_url ) ) {
			return array();
		}

		$result = $this->http->get( $base_url, array( 'headers' => $this->get_auth_headers() ) );
		if ( is_wp_error( $result ) ) {
			return array();
		}

		$body = json_decode( $result['body'], true );
		if ( ! is_array( $body ) ) {
			return array();
		}

		$node_path = isset( $this->config['node_path'] ) ? $this->config['node_path'] : 'data';
		$items     = $this->extract_path( $body, $node_path );
		if ( ! is_array( $items ) ) {
			return array();
		}

		$label_field    = isset( $this->config['node_label_field'] ) ? $this->config['node_label_field'] : 'name';
		$type_field     = isset( $this->config['node_type_field'] ) ? $this->config['node_type_field'] : 'type';
		$id_field       = isset( $this->config['node_id_field'] ) ? $this->config['node_id_field'] : 'id';
		$url_field      = isset( $this->config['node_url_field'] ) ? $this->config['node_url_field'] : 'url';

		$nodes = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label     = isset( $item[ $label_field ] ) ? sanitize_text_field( (string) $item[ $label_field ] ) : '';
			$remote_id = isset( $item[ $id_field ] ) ? sanitize_text_field( (string) $item[ $id_field ] ) : '';
			if ( empty( $label ) ) {
				continue;
			}
			$type    = isset( $item[ $type_field ] ) ? sanitize_text_field( (string) $item[ $type_field ] ) : 'entity';
			$url     = isset( $item[ $url_field ] ) ? esc_url_raw( (string) $item[ $url_field ] ) : '';
			$node_id = 'remote_' . sanitize_key( $source_slug ) . '_' . ( $remote_id ? sanitize_key( $remote_id ) : md5( $label ) );

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

		return $nodes;
	}

	/** {@inheritdoc} */
	public function fetch_edges( array $args = array() ) {
		$edge_path = isset( $this->config['edge_path'] ) ? $this->config['edge_path'] : '';
		if ( empty( $edge_path ) ) {
			return array();
		}

		$base_url    = $this->get_base_url();
		$source_slug = isset( $this->config['_slug'] ) ? $this->config['_slug'] : 'generic_rest';

		if ( empty( $base_url ) ) {
			return array();
		}

		$result = $this->http->get( $base_url, array( 'headers' => $this->get_auth_headers() ) );
		if ( is_wp_error( $result ) ) {
			return array();
		}

		$body  = json_decode( $result['body'], true );
		$items = $this->extract_path( $body, $edge_path );
		if ( ! is_array( $items ) ) {
			return array();
		}

		$source_field   = isset( $this->config['edge_source_field'] ) ? $this->config['edge_source_field'] : 'source';
		$target_field   = isset( $this->config['edge_target_field'] ) ? $this->config['edge_target_field'] : 'target';
		$relation_field = isset( $this->config['edge_relation_field'] ) ? $this->config['edge_relation_field'] : 'relation';

		$edges = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$src = isset( $item[ $source_field ] ) ? sanitize_text_field( (string) $item[ $source_field ] ) : '';
			$tgt = isset( $item[ $target_field ] ) ? sanitize_text_field( (string) $item[ $target_field ] ) : '';
			$rel = isset( $item[ $relation_field ] ) ? sanitize_text_field( (string) $item[ $relation_field ] ) : 'RELATED_TO';

			if ( empty( $src ) || empty( $tgt ) ) {
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
	 * Reconciliation not supported by generic REST driver.
	 *
	 * @param object $local_node Unused.
	 * @return array
	 */
	public function reconcile( $local_node ) {
		return array( 'external_id' => '', 'confidence' => 0.0, 'matched' => false );
	}

	/**
	 * Extract a nested value from an array using dot-notation path.
	 *
	 * @since 0.6.0
	 *
	 * @param array  $data Data array.
	 * @param string $path Dot-notation path (e.g. 'data.items').
	 * @return mixed Value at path or null.
	 */
	private function extract_path( $data, $path ) {
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
	 * @since 0.6.0
	 *
	 * @return string
	 */
	private function get_base_url() {
		$url = isset( $this->config['base_url'] ) ? $this->config['base_url'] : '';
		return esc_url_raw( $url );
	}

	/**
	 * Build Authorization headers from config.
	 *
	 * @since 0.6.0
	 *
	 * @return array
	 */
	private function get_auth_headers() {
		$auth_type  = isset( $this->config['auth_type'] ) ? $this->config['auth_type'] : 'none';
		$auth_value = isset( $this->config['auth_value'] ) ? $this->config['auth_value'] : '';
		$headers    = array();

		switch ( $auth_type ) {
			case 'bearer':
				if ( $auth_value ) {
					$headers['Authorization'] = 'Bearer ' . $auth_value;
				}
				break;
			case 'basic':
				if ( $auth_value ) {
					$headers['Authorization'] = 'Basic ' . base64_encode( $auth_value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				}
				break;
			case 'api_key':
				$header_name = isset( $this->config['auth_header'] ) ? $this->config['auth_header'] : 'X-Api-Key';
				$header_name = sanitize_text_field( $header_name );
				if ( $auth_value && $header_name ) {
					$headers[ $header_name ] = $auth_value;
				}
				break;
		}

		return $headers;
	}
}
