<?php
/**
 * NV oOS Graphify — CSV Remote Driver (base)
 *
 * Ingests rows from a CSV file (uploaded into the WordPress Media Library or
 * referenced by an absolute server-readable path) as graph nodes using the
 * NV_oOS_Graphify_Field_Mapper to translate columns to node fields.
 *
 * Configuration:
 *   attachment_id   — Media Library attachment ID (preferred)
 *   file_path       — Absolute file path inside the WordPress uploads dir
 *                     (used when attachment_id is empty)
 *   has_header_row  — bool, whether the first row contains column headers
 *   delimiter       — single character (default: ",")
 *   max_items       — max rows to ingest per sync
 *   field_map       — JSON map: { "id":"col1", "label":"col2", ... }
 *
 * For security, non-attachment file_path values are constrained to the
 * uploads directory using realpath() comparison.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV-as-a-graph-source remote driver.
 *
 * @since 0.7.0
 */
class NV_oOS_Graphify_Remote_CSV extends NV_oOS_Graphify_Remote_Source_Base {

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'csv';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'CSV File Upload', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'fetch_nodes' );
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array(
			'supports_incremental'   => false,
			'supports_webhooks'      => false,
			'supports_oauth'         => false,
			'supports_pagination'    => false,
			'supports_relationships' => false,
		);
	}

	/** {@inheritdoc} */
	public function get_config_schema() {
		return array(
			'attachment_id'  => array(
				'type'        => 'number',
				'label'       => __( 'Attachment ID', 'nvoos-graphify' ),
				'description' => __( 'WordPress Media Library attachment ID of the CSV file (preferred).', 'nvoos-graphify' ),
			),
			'file_path'      => array(
				'type'        => 'text',
				'label'       => __( 'File Path', 'nvoos-graphify' ),
				'description' => __( 'Absolute path to a CSV file inside the WordPress uploads directory. Used when no attachment ID is set.', 'nvoos-graphify' ),
			),
			'has_header_row' => array(
				'type'    => 'checkbox',
				'label'   => __( 'First row contains headers', 'nvoos-graphify' ),
				'default' => true,
			),
			'delimiter'      => array(
				'type'    => 'text',
				'label'   => __( 'Field Delimiter', 'nvoos-graphify' ),
				'default' => ',',
			),
			'max_items'      => array(
				'type'        => 'number',
				'label'       => __( 'Max Rows', 'nvoos-graphify' ),
				'description' => __( 'Maximum rows to ingest per sync (0 = unlimited).', 'nvoos-graphify' ),
				'default'     => 1000,
			),
			'field_map'      => array(
				'type'        => 'textarea',
				'label'       => __( 'Field Map (JSON)', 'nvoos-graphify' ),
				'description' => __( 'JSON map from node fields to column names: { "id": "id", "label": "name", "url": "homepage", "type": "person", "properties": { "email": "email" } }', 'nvoos-graphify' ),
			),
		);
	}

	/** {@inheritdoc} */
	public function test_connection() {
		$path = $this->resolve_file_path();
		if ( is_wp_error( $path ) ) {
			return array(
				'success' => false,
				'message' => $path->get_error_message(),
			);
		}
		if ( ! is_readable( $path ) ) {
			return array(
				'success' => false,
				'message' => __( 'CSV file is not readable.', 'nvoos-graphify' ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'CSV file accessible.', 'nvoos-graphify' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch args (limit override).
	 */
	public function fetch_nodes( array $args = array() ) {
		$path = $this->resolve_file_path();
		if ( is_wp_error( $path ) ) {
			return array();
		}
		$rows = $this->read_csv( $path );
		if ( empty( $rows ) ) {
			return array();
		}

		$max_items = $this->resolve_limit( $args );
		if ( $max_items > 0 && count( $rows ) > $max_items ) {
			$rows = array_slice( $rows, 0, $max_items );
		}

		$map = $this->resolve_field_map();
		if ( empty( $map ) || empty( $map['id'] ) || empty( $map['label'] ) ) {
			return array();
		}

		return NV_oOS_Graphify_Field_Mapper::map_collection( $rows, $map, $this->get_slug() );
	}

	/**
	 * Resolve the configured file path, validating it is inside the uploads
	 * directory (defence against path traversal / SSRF-style abuses).
	 *
	 * @return string|WP_Error Absolute path, or WP_Error.
	 */
	private function resolve_file_path() {
		$attachment_id = isset( $this->config['attachment_id'] ) ? absint( $this->config['attachment_id'] ) : 0;
		if ( $attachment_id > 0 ) {
			$path = get_attached_file( $attachment_id );
			if ( ! $path || ! file_exists( $path ) ) {
				return new WP_Error( 'csv_attachment_missing', __( 'Configured attachment was not found.', 'nvoos-graphify' ) );
			}
			return $path;
		}

		$file_path = isset( $this->config['file_path'] ) ? (string) $this->config['file_path'] : '';
		if ( '' === $file_path ) {
			return new WP_Error( 'csv_no_path', __( 'No CSV attachment_id or file_path is configured.', 'nvoos-graphify' ) );
		}

		$real         = realpath( $file_path );
		$uploads      = wp_get_upload_dir();
		$uploads_real = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : '';
		if ( false === $real || empty( $uploads_real ) || 0 !== strpos( $real, $uploads_real ) ) {
			return new WP_Error( 'csv_path_unsafe', __( 'CSV file path must be inside the WordPress uploads directory.', 'nvoos-graphify' ) );
		}
		return $real;
	}

	/**
	 * Read and parse the CSV file at the given path into an array of associative
	 * rows keyed by either header names (when has_header_row is true) or numeric
	 * column indices.
	 *
	 * @param string $path Absolute file path.
	 * @return array<int,array<string,string>>
	 */
	private function read_csv( $path ) {
		global $wp_filesystem;

		// Initialise WP_Filesystem if not already available.
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( empty( $wp_filesystem ) || ! $wp_filesystem->exists( $path ) || ! $wp_filesystem->is_readable( $path ) ) {
			return array();
		}

		$delimiter      = $this->resolve_delimiter();
		$has_header_row = ! empty( $this->config['has_header_row'] );

		$contents = $wp_filesystem->get_contents( $path );
		if ( false === $contents || '' === $contents ) {
			return array();
		}

		// Normalise line endings to LF.
		$contents = str_replace( "\r\n", "\n", $contents );
		$contents = str_replace( "\r", "\n", $contents );
		$lines    = explode( "\n", $contents );

		$rows    = array();
		$headers = array();
		$first   = true;

		foreach ( $lines as $line ) {
			// Skip completely empty lines (e.g. trailing newline).
			if ( '' === $line ) {
				continue;
			}

			$row = str_getcsv( $line, $delimiter );

			if ( $first ) {
				$first = false;
				if ( $has_header_row ) {
					$headers = array_map( 'sanitize_text_field', $row );
					continue;
				}
			}
			if ( $has_header_row && ! empty( $headers ) ) {
				$assoc = array();
				foreach ( $headers as $i => $name ) {
					$assoc[ $name ] = isset( $row[ $i ] ) ? (string) $row[ $i ] : '';
				}
				$rows[] = $assoc;
			} else {
				$assoc = array();
				foreach ( $row as $i => $val ) {
					$assoc[ (string) $i ] = (string) $val;
				}
				$rows[] = $assoc;
			}
		}

		return $rows;
	}

	/**
	 * Decode the configured field map.
	 *
	 * @return array
	 */
	private function resolve_field_map() {
		$raw = isset( $this->config['field_map'] ) ? (string) $this->config['field_map'] : '';
		if ( '' === $raw ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Resolve the per-sync row limit.
	 *
	 * @param array $args Fetch args.
	 * @return int 0 = unlimited.
	 */
	private function resolve_limit( array $args ) {
		if ( isset( $args['limit'] ) ) {
			return max( 0, absint( $args['limit'] ) );
		}
		return isset( $this->config['max_items'] ) ? max( 0, absint( $this->config['max_items'] ) ) : 1000;
	}

	/**
	 * Resolve the field delimiter, defaulting to comma.
	 *
	 * @return string Single character.
	 */
	private function resolve_delimiter() {
		$d = isset( $this->config['delimiter'] ) ? (string) $this->config['delimiter'] : ',';
		// CSV delimiter must be a single byte; fall back to comma otherwise.
		return ( 1 === strlen( $d ) ) ? $d : ',';
	}
}
