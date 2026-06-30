<?php
declare(strict_types=1);

namespace NvoosGraphify\Remote\Drivers;

use NvoosGraphify\Contracts\RemoteSource;
use WP_Error;
use function absint;
use function array_slice;
use function file_exists;
use function get_attached_file;
use function is_array;
use function is_wp_error;
use function json_decode;
use function max;
use function md5;
use function realpath;
use function sanitize_key;
use function sanitize_text_field;
use function wp_get_upload_dir;

/**
 * CSV File Upload remote source driver.
 *
 * Ingests rows from a CSV file (uploaded into the WordPress Media Library
 * or referenced by a server-readable path) as graph nodes.
 *
 * @since 1.0.0
 */
class Csv implements RemoteSource {

	/** @var array<string,mixed> Driver configuration. */
	private array $config = array();

	public function getDriverId(): string {
		return 'csv';
	}

	public function getDriverLabel(): string {
		return __( 'CSV File Upload', 'nvoos-graphify' );
	}

	public function setConfig( array $config ): void {
		$this->config = $config;
	}

	public function getConfig(): array {
		return $this->config;
	}

	public function getCapabilities(): array {
		return array( 'fetch_nodes' );
	}

	public function getConfigSchema(): array {
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
				'description' => __( 'JSON map from node fields to column names: { "id": "id", "label": "name", "url": "homepage", "type": "person" }', 'nvoos-graphify' ),
			),
		);
	}

	public function testConnection(): array {
		$path = $this->resolveFilePath();
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

	public function discover(): array {
		return array(
			'driver'       => $this->getDriverId(),
			'label'        => $this->getDriverLabel(),
			'capabilities' => $this->getCapabilities(),
		);
	}

	public function fetchNodes( array $args = array() ): array {
		$path = $this->resolveFilePath();
		if ( is_wp_error( $path ) ) {
			return array();
		}

		$rows = $this->readCsv( $path );
		if ( empty( $rows ) ) {
			return array();
		}

		$maxItems = $this->resolveLimit( $args );
		if ( $maxItems > 0 && count( $rows ) > $maxItems ) {
			$rows = array_slice( $rows, 0, $maxItems );
		}

		$fieldMap = $this->resolveFieldMap();
		if ( empty( $fieldMap ) || empty( $fieldMap['label'] ) ) {
			return array();
		}

		$sourceSlug = $this->config['_slug'] ?? 'csv';
		$type       = sanitize_text_field( (string) ( $fieldMap['type'] ?? 'entity' ) );
		$urlField   = $fieldMap['url'] ?? 'url';
		$idField    = $fieldMap['id'] ?? null;
		$labelField = $fieldMap['label'];

		$nodes = array();
		foreach ( $rows as $row ) {
			$label    = sanitize_text_field( (string) ( $row[ $labelField ] ?? '' ) );
			$remoteId = $idField ? sanitize_text_field( (string) ( $row[ $idField ] ?? '' ) ) : '';
			if ( empty( $label ) ) {
				continue;
			}

			$url    = isset( $row[ $urlField ] ) ? \esc_url_raw( (string) $row[ $urlField ] ) : '';
			$nodeId = 'remote_' . sanitize_key( $sourceSlug ) . '_' . ( $remoteId ? sanitize_key( $remoteId ) : md5( $label ) );

			$nodes[] = array(
				'node_id'     => $nodeId,
				'label'       => $label,
				'type'        => $type,
				'post_id'     => 0,
				'url'         => $url,
				'properties'  => $row,
				'source_slug' => $sourceSlug,
				'provenance'  => 'REMOTE',
				'external_id' => $remoteId,
			);
		}

		return $nodes;
	}

	public function fetchEdges( array $args = array() ): array {
		return array();
	}

	public function reconcile( $localNode ): array {
		return array(
			'external_id' => '',
			'confidence'  => 0.0,
			'matched'     => false,
		);
	}

	/**
	 * Resolve the configured file path, validating it is inside the uploads directory.
	 *
	 * @return string|WP_Error Absolute path, or WP_Error.
	 */
	private function resolveFilePath() {
		$attachmentId = absint( $this->config['attachment_id'] ?? 0 );
		if ( $attachmentId > 0 ) {
			$path = get_attached_file( $attachmentId );
			if ( ! $path || ! file_exists( $path ) ) {
				return new WP_Error( 'csv_attachment_missing', __( 'Configured attachment was not found.', 'nvoos-graphify' ) );
			}
			return $path;
		}

		$filePath = (string) ( $this->config['file_path'] ?? '' );
		if ( '' === $filePath ) {
			return new WP_Error( 'csv_no_path', __( 'No CSV attachment_id or file_path is configured.', 'nvoos-graphify' ) );
		}

		$real        = realpath( $filePath );
		$uploads     = wp_get_upload_dir();
		$uploadsReal = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : '';
		if ( false === $real || empty( $uploadsReal ) || 0 !== strpos( $real, $uploadsReal ) ) {
			return new WP_Error( 'csv_path_unsafe', __( 'CSV file path must be inside the WordPress uploads directory.', 'nvoos-graphify' ) );
		}
		return $real;
	}

	/**
	 * Read and parse the CSV file into associative rows.
	 *
	 * Uses WP_Filesystem for safe, WordPress-compliant file reading.
	 *
	 * @param string $path Absolute file path.
	 * @return array<int,array<string,string>>
	 */
	private function readCsv( string $path ): array {
		global $wp_filesystem;

		// Initialise WP_Filesystem if not already available.
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( empty( $wp_filesystem ) || ! $wp_filesystem->exists( $path ) || ! $wp_filesystem->is_readable( $path ) ) {
			return array();
		}

		$delimiter    = $this->resolveDelimiter();
		$hasHeaderRow = ! empty( $this->config['has_header_row'] );

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
				if ( $hasHeaderRow ) {
					$headers = array_map( 'sanitize_text_field', $row );
					continue;
				}
			}
			if ( $hasHeaderRow && ! empty( $headers ) ) {
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
	 * @return array<string,string>
	 */
	private function resolveFieldMap(): array {
		$raw = $this->config['field_map'] ?? '';
		if ( '' === $raw ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Resolve the per-sync row limit.
	 *
	 * @param array $args Fetch args.
	 * @return int 0 = unlimited.
	 */
	private function resolveLimit( array $args ): int {
		if ( isset( $args['limit'] ) ) {
			return max( 0, absint( $args['limit'] ) );
		}
		return max( 0, absint( $this->config['max_items'] ?? 1000 ) );
	}

	/**
	 * Resolve the field delimiter, defaulting to comma.
	 *
	 * @return string Single character.
	 */
	private function resolveDelimiter(): string {
		$d = (string) ( $this->config['delimiter'] ?? ',' );
		return ( 1 === strlen( $d ) ) ? $d : ',';
	}
}
