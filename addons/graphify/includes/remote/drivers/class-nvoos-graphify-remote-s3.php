<?php
/**
 * NV oOS Graphify — S3 (and S3-compatible) Remote Driver (Pro)
 *
 * Enumerates objects in an S3 bucket via the ListObjectsV2 operation and
 * emits one node per key plus optional folder hierarchy edges. Compatible
 * with AWS S3, MinIO, Wasabi, Backblaze B2 (S3 endpoint), Cloudflare R2,
 * and other endpoints that speak the S3 REST API.
 *
 * Implements just enough Signature V4 to sign the single GET request used
 * by ListObjectsV2 — no third-party SDK is pulled in.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * S3 remote source driver.
 *
 * @since 0.7.6
 */
class NV_oOS_Graphify_Remote_S3 implements NV_oOS_Graphify_Remote_Source_Interface {

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
		$this->http = new NV_oOS_Graphify_HTTP_Client( 's3' );
	}

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 's3';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'S3 (AWS / S3-compatible)', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $config Driver configuration array.
	 */
	public function set_config( array $config ) {
		$this->config = $config;
		$slug         = isset( $config['_slug'] ) ? $config['_slug'] : 's3';
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
			'supports_pagination'    => true,
			'supports_relationships' => true,
		);
	}

	/** {@inheritdoc} */
	public function get_config_schema() {
		return array(
			'endpoint'          => array(
				'type'        => 'url',
				'label'       => __( 'Endpoint', 'nvoos-graphify' ),
				'description' => __( 'Base S3 endpoint, e.g. https://s3.us-east-1.amazonaws.com or https://minio.example.com.', 'nvoos-graphify' ),
				'required'    => true,
			),
			'region'            => array(
				'type'    => 'text',
				'label'   => __( 'Region', 'nvoos-graphify' ),
				'default' => 'us-east-1',
			),
			'bucket'            => array(
				'type'     => 'text',
				'label'    => __( 'Bucket', 'nvoos-graphify' ),
				'required' => true,
			),
			'access_key_id'     => array(
				'type'  => 'text',
				'label' => __( 'Access Key ID', 'nvoos-graphify' ),
			),
			'secret_access_key' => array(
				'type'  => 'password',
				'label' => __( 'Secret Access Key', 'nvoos-graphify' ),
			),
			'prefix'            => array(
				'type'        => 'text',
				'label'       => __( 'Key Prefix', 'nvoos-graphify' ),
				'description' => __( 'Optional prefix to scope the listing.', 'nvoos-graphify' ),
				'default'     => '',
			),
			'use_path_style'    => array(
				'type'        => 'checkbox',
				'label'       => __( 'Path-style URLs', 'nvoos-graphify' ),
				'description' => __( 'Required for MinIO and most non-AWS S3 endpoints.', 'nvoos-graphify' ),
				'default'     => true,
			),
			'page_size'         => array(
				'type'    => 'number',
				'label'   => __( 'Page Size (max 1000)', 'nvoos-graphify' ),
				'default' => 1000,
			),
			'max_pages'         => array(
				'type'        => 'number',
				'label'       => __( 'Max Pages Per Run', 'nvoos-graphify' ),
				'description' => __( 'Stops paginating after this many ListObjectsV2 calls.', 'nvoos-graphify' ),
				'default'     => 5,
			),
			'emit_folder_edges' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Emit folder hierarchy edges', 'nvoos-graphify' ),
				'description' => __( 'Adds CONTAINED_IN edges from each object to its parent prefix.', 'nvoos-graphify' ),
				'default'     => true,
			),
		);
	}

	/** {@inheritdoc} */
	public function test_connection() {
		$result = $this->list_objects_v2( '', 1 );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}
		if ( ! empty( $result['error'] ) ) {
			return array(
				'success' => false,
				'message' => (string) $result['error'],
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
			'bucket'       => isset( $this->config['bucket'] ) ? (string) $this->config['bucket'] : '',
			'capabilities' => $this->get_capabilities(),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_nodes( array $args = array() ) {
		unset( $args );
		$source_slug = isset( $this->config['_slug'] ) ? (string) $this->config['_slug'] : 's3';
		$bucket      = isset( $this->config['bucket'] ) ? (string) $this->config['bucket'] : '';
		if ( '' === $bucket ) {
			return array();
		}

		$max_pages = isset( $this->config['max_pages'] ) ? max( 1, (int) $this->config['max_pages'] ) : 5;
		$page_size = isset( $this->config['page_size'] ) ? max( 1, min( 1000, (int) $this->config['page_size'] ) ) : 1000;
		$cursor    = (string) NV_oOS_Graphify_Remote_State_Store::get( $source_slug, 'continuation_token', '' );

		$nodes = array();
		for ( $page = 0; $page < $max_pages; $page++ ) {
			$result = $this->list_objects_v2( $cursor, $page_size );
			if ( is_wp_error( $result ) || ! empty( $result['error'] ) ) {
				break;
			}
			foreach ( $result['objects'] as $obj ) {
				$key = (string) $obj['key'];
				if ( '' === $key ) {
					continue;
				}
				$label   = self::basename_of_key( $key );
				$type    = self::is_folder_key( $key ) ? 'folder' : 'object';
				$node_id = 'remote_' . sanitize_key( $source_slug ) . '_obj_' . self::hash_id( $bucket . '/' . $key );

				$nodes[] = array(
					'node_id'     => $node_id,
					'label'       => sanitize_text_field( $label ),
					'type'        => $type,
					'post_id'     => 0,
					'url'         => '',
					'properties'  => array(
						'bucket'        => $bucket,
						'key'           => $key,
						'size'          => isset( $obj['size'] ) ? (int) $obj['size'] : 0,
						'etag'          => isset( $obj['etag'] ) ? sanitize_text_field( (string) $obj['etag'] ) : '',
						'last_modified' => isset( $obj['last_modified'] ) ? sanitize_text_field( (string) $obj['last_modified'] ) : '',
						'storage_class' => isset( $obj['storage_class'] ) ? sanitize_text_field( (string) $obj['storage_class'] ) : '',
					),
					'source_slug' => $source_slug,
					'provenance'  => 'REMOTE',
					'external_id' => $key,
				);
			}

			if ( empty( $result['next_token'] ) ) {
				$cursor = '';
				break;
			}
			$cursor = (string) $result['next_token'];
		}

		// Persist the cursor for the next run.
		NV_oOS_Graphify_Remote_State_Store::set( $source_slug, 'continuation_token', $cursor );
		NV_oOS_Graphify_Remote_State_Store::mark_synced( $source_slug );

		return $nodes;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_edges( array $args = array() ) {
		unset( $args );
		if ( empty( $this->config['emit_folder_edges'] ) ) {
			return array();
		}
		$source_slug = isset( $this->config['_slug'] ) ? (string) $this->config['_slug'] : 's3';
		$bucket      = isset( $this->config['bucket'] ) ? (string) $this->config['bucket'] : '';
		if ( '' === $bucket ) {
			return array();
		}

		// Re-list a single page (cheap) to derive parent edges from the most
		// recently-seen objects. We deliberately do not re-walk the whole
		// bucket here; folder edges are best-effort companions to the nodes
		// emitted above.
		$page_size = isset( $this->config['page_size'] ) ? max( 1, min( 1000, (int) $this->config['page_size'] ) ) : 1000;
		$result    = $this->list_objects_v2( '', $page_size );
		if ( is_wp_error( $result ) || ! empty( $result['error'] ) ) {
			return array();
		}

		$edges = array();
		foreach ( $result['objects'] as $obj ) {
			$key = (string) $obj['key'];
			if ( '' === $key || self::is_folder_key( $key ) ) {
				continue;
			}
			$parent = self::parent_prefix( $key );
			if ( '' === $parent ) {
				continue;
			}
			$edges[] = array(
				'source_node_id' => 'remote_' . sanitize_key( $source_slug ) . '_obj_' . self::hash_id( $bucket . '/' . $key ),
				'target_node_id' => 'remote_' . sanitize_key( $source_slug ) . '_obj_' . self::hash_id( $bucket . '/' . $parent ),
				'relation'       => 'CONTAINED_IN',
				'confidence'     => 1.0,
				'provenance'     => 'REMOTE',
				'source_slug'    => $source_slug,
			);
		}
		return $edges;
	}

	/**
	 * Reconciliation not supported.
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
	// S3 protocol
	// -------------------------------------------------------------------------

	/**
	 * Issue one ListObjectsV2 call and parse the XML response.
	 *
	 * @param string $continuation_token Optional continuation token.
	 * @param int    $max_keys           Page size.
	 * @return array|WP_Error  array{objects:array,next_token:string,error?:string}
	 */
	private function list_objects_v2( $continuation_token = '', $max_keys = 1000 ) {
		$endpoint = isset( $this->config['endpoint'] ) ? rtrim( (string) $this->config['endpoint'], '/' ) : '';
		$bucket   = isset( $this->config['bucket'] ) ? (string) $this->config['bucket'] : '';
		$region   = isset( $this->config['region'] ) ? (string) $this->config['region'] : 'us-east-1';
		$prefix   = isset( $this->config['prefix'] ) ? (string) $this->config['prefix'] : '';
		$path_st  = ! empty( $this->config['use_path_style'] );
		$ak       = isset( $this->config['access_key_id'] ) ? (string) $this->config['access_key_id'] : '';
		$sk       = isset( $this->config['secret_access_key'] ) ? (string) $this->config['secret_access_key'] : '';

		if ( '' === $endpoint || '' === $bucket ) {
			return new WP_Error( 'invalid_config', __( 'Endpoint and bucket are required.', 'nvoos-graphify' ) );
		}

		// Build the URL — path-style or virtual-host-style.
		$endpoint_parts = wp_parse_url( $endpoint );
		if ( empty( $endpoint_parts['host'] ) || empty( $endpoint_parts['scheme'] ) ) {
			return new WP_Error( 'invalid_endpoint', __( 'Invalid endpoint.', 'nvoos-graphify' ) );
		}
		if ( $path_st ) {
			$host = $endpoint_parts['host'];
			$path = '/' . rawurlencode( $bucket ) . '/';
		} else {
			$host = rawurlencode( $bucket ) . '.' . $endpoint_parts['host'];
			$path = '/';
		}

		$query_params = array(
			'list-type' => '2',
			'max-keys'  => (string) $max_keys,
		);
		if ( '' !== $prefix ) {
			$query_params['prefix'] = $prefix;
		}
		if ( '' !== $continuation_token ) {
			$query_params['continuation-token'] = $continuation_token;
		}
		ksort( $query_params );
		$canonical_query = self::build_canonical_query( $query_params );

		$amz_date     = gmdate( 'Ymd\THis\Z' );
		$date_only    = gmdate( 'Ymd' );
		$payload_hash = hash( 'sha256', '' );

		$canonical_headers = "host:{$host}\nx-amz-content-sha256:{$payload_hash}\nx-amz-date:{$amz_date}\n";
		$signed_headers    = 'host;x-amz-content-sha256;x-amz-date';

		$canonical_request = "GET\n{$path}\n{$canonical_query}\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";
		$cred_scope        = "{$date_only}/{$region}/s3/aws4_request";
		$string_to_sign    = "AWS4-HMAC-SHA256\n{$amz_date}\n{$cred_scope}\n" . hash( 'sha256', $canonical_request );

		$k_date    = hash_hmac( 'sha256', $date_only, 'AWS4' . $sk, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		$auth = "AWS4-HMAC-SHA256 Credential={$ak}/{$cred_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

		$url     = $endpoint_parts['scheme'] . '://' . $host . $path . ( '' !== $canonical_query ? '?' . $canonical_query : '' );
		$headers = array(
			'Authorization'        => $auth,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'           => $amz_date,
		);
		// When credentials are blank we make an unsigned request so test
		// connections against public buckets still work.
		if ( '' === $ak || '' === $sk ) {
			$headers = array();
		}

		$response = $this->http->get( $url, array( 'headers' => $headers ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			return array(
				'objects'    => array(),
				'next_token' => '',
				'error'      => sprintf(
					/* translators: %d HTTP status code */
					__( 'S3 returned HTTP %d.', 'nvoos-graphify' ),
					(int) $response['status']
				),
			);
		}
		return self::parse_list_objects_xml( (string) $response['body'] );
	}

	/**
	 * Parse a ListObjectsV2 response body into objects + continuation token.
	 *
	 * @param string $xml Response body.
	 * @return array
	 */
	public static function parse_list_objects_xml( $xml ) {
		$objects    = array();
		$next_token = '';
		if ( '' === trim( $xml ) ) {
			return array(
				'objects'    => $objects,
				'next_token' => $next_token,
			);
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml );
		libxml_use_internal_errors( $prev );
		if ( false === $doc ) {
			return array(
				'objects'    => $objects,
				'next_token' => $next_token,
			);
		}
		// SimpleXML element accessors must match the S3 XML schema's
		// PascalCase element names — they are not PHP properties.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( isset( $doc->NextContinuationToken ) ) {
			$next_token = (string) $doc->NextContinuationToken;
		}
		if ( isset( $doc->Contents ) ) {
			foreach ( $doc->Contents as $entry ) {
				$objects[] = array(
					'key'           => (string) $entry->Key,
					'size'          => isset( $entry->Size ) ? (int) $entry->Size : 0,
					'etag'          => isset( $entry->ETag ) ? trim( (string) $entry->ETag, '"' ) : '',
					'last_modified' => isset( $entry->LastModified ) ? (string) $entry->LastModified : '',
					'storage_class' => isset( $entry->StorageClass ) ? (string) $entry->StorageClass : '',
				);
			}
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return array(
			'objects'    => $objects,
			'next_token' => $next_token,
		);
	}

	// -------------------------------------------------------------------------
	// Helpers (public to keep the SigV4 / key-shape logic unit-testable)
	// -------------------------------------------------------------------------

	/**
	 * Build the canonical query string per SigV4 (RFC 3986 encoding, sorted).
	 *
	 * @param array $params Already-sorted associative array.
	 * @return string
	 */
	public static function build_canonical_query( array $params ) {
		$pairs = array();
		foreach ( $params as $name => $value ) {
			$pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $value );
		}
		return implode( '&', $pairs );
	}

	/**
	 * Best-effort basename of an S3 key. Folder keys (those ending in `/`)
	 * resolve to the directory's own name.
	 *
	 * @param string $key S3 object key.
	 * @return string
	 */
	public static function basename_of_key( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return '';
		}
		if ( '/' === substr( $key, -1 ) ) {
			$trim  = rtrim( $key, '/' );
			$parts = explode( '/', $trim );
			return (string) end( $parts );
		}
		$parts = explode( '/', $key );
		return (string) end( $parts );
	}

	/**
	 * Parent prefix for a key ('' when the key is at the bucket root).
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	public static function parent_prefix( $key ) {
		$pos = strrpos( (string) $key, '/' );
		if ( false === $pos ) {
			return '';
		}
		return substr( $key, 0, $pos + 1 );
	}

	/**
	 * Folder convention used by AWS console: zero-byte object whose key
	 * ends in '/'.
	 *
	 * @param string $key Object key.
	 * @return bool
	 */
	public static function is_folder_key( $key ) {
		return '' !== $key && '/' === substr( $key, -1 );
	}

	/**
	 * Hash a long key into a sanitize_key()-safe slug fragment.
	 *
	 * @param string $value Value to hash.
	 * @return string
	 */
	public static function hash_id( $value ) {
		return substr( md5( (string) $value ), 0, 16 );
	}
}
