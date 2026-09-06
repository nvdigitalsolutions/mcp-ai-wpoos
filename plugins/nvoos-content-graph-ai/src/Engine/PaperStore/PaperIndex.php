<?php
/**
 * Paper index (Wave E6, sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Paper_Index`
 * (`includes/paper-store/class-wp-mcp-ai-paper-index.php`):
 * byte-identical inverted index per collection — the
 * `_indexes/{collection}.idx.json` index file, the
 * `_count`/`tags`/`status`/`type`/`author_id`/`date_bucket`/`record_ids`
 * structure, the lazy `load()` with `wp_parse_args()` healing, the
 * `wp_mcp_ai_paper_index_max_tags` filter (default 1000, applied on the
 * rebuild path only — the single-record path is uncapped, a preserved
 * base quirk), the flock()-locked non-blocking 3-second save, the
 * `wp_mcp_ai_paper_index_rebuilt` action with the
 * `record_count`/`tag_count` stats payload, the date-bucket mapping
 * (year-month via `strtotime()` + `gmdate()`), and the full
 * find-by-{tag,status,type,author,date-bucket}/remove/rebuild/drop
 * surface.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - `WP_Error` is fully qualified.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\PaperStore
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\PaperStore;

/**
 * Inverted index for flat-file record collections.
 *
 * Each collection gets one index file. The index is lazily built on
 * first query and auto-updated on write/delete operations. File-level
 * locking (flock) prevents corruption during concurrent writes.
 *
 * @since 1.1.0
 */
class PaperIndex {

	/**
	 * Collection name this index serves.
	 *
	 * @var string
	 */
	private $collection;

	/**
	 * Absolute path to the index file.
	 *
	 * @var string
	 */
	private $index_path;

	/**
	 * Absolute path to the collection directory.
	 *
	 * @var string
	 */
	private $collection_dir;

	/**
	 * In-memory index data (lazy-loaded).
	 *
	 * @var array|null
	 */
	private $data = null;

	/**
	 * Whether the index has been modified in-memory and needs persisting.
	 *
	 * @var bool
	 */
	private $dirty = false;

	/**
	 * Maximum unique tags per collection (filterable).
	 *
	 * @var int
	 */
	private $max_tags;

	/**
	 * Constructor.
	 *
	 * @param string $collection     Collection name.
	 * @param string $collection_dir Absolute path to the collection directory.
	 * @param string $indexes_dir    Absolute path to the _indexes directory.
	 */
	public function __construct( $collection, $collection_dir, $indexes_dir ) {
		$this->collection     = \sanitize_key( $collection );
		$this->collection_dir = $collection_dir;
		$this->index_path     = \trailingslashit( $indexes_dir ) . $this->collection . '.idx.json';

		/**
		 * Filter the maximum number of unique tags tracked per collection index.
		 *
		 * @since 1.1.0
		 *
		 * @param int    $max_tags   Maximum unique tags. Default 1000.
		 * @param string $collection Collection name.
		 */
		$this->max_tags = \apply_filters( 'wp_mcp_ai_paper_index_max_tags', 1000, $this->collection );
	}

	/**
	 * Load index data from disk (lazy).
	 *
	 * @return array Index data array.
	 */
	private function load() {
		if ( null !== $this->data ) {
			return $this->data;
		}

		if ( ! \file_exists( $this->index_path ) ) {
			$this->data = $this->empty_structure();
			return $this->data;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin-managed index file.
		$raw = \file_get_contents( $this->index_path );

		if ( false === $raw ) {
			$this->data = $this->empty_structure();
			return $this->data;
		}

		$data = \json_decode( $raw, true );

		if ( ! \is_array( $data ) ) {
			$this->data = $this->empty_structure();
			return $this->data;
		}

		$this->data            = \wp_parse_args( $data, $this->empty_structure() );
		$this->data['_count']  = isset( $data['_count'] ) ? (int) $data['_count'] : 0;

		return $this->data;
	}

	/**
	 * Return an empty index structure.
	 *
	 * @return array
	 */
	private function empty_structure() {
		return array(
			'_count'      => 0,
			'tags'        => array(),
			'status'      => array(),
			'type'        => array(),
			'author_id'   => array(),
			'date_bucket' => array(),
			'record_ids'  => array(),
		);
	}

	/**
	 * Persist index data to disk with file locking.
	 *
	 * @return bool True on success.
	 */
	private function save() {
		if ( ! $this->dirty ) {
			return true;
		}

		// Ensure parent directory exists.
		$indexes_dir = \dirname( $this->index_path );
		if ( ! \is_dir( $indexes_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Managed flat-file store directory.
			if ( ! \mkdir( $indexes_dir, 0755, true ) && ! \is_dir( $indexes_dir ) ) {
				return false;
			}
		}

		$json = \wp_json_encode( $this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Managed flat-file index with flock() for concurrency safety.
		$handle = \fopen( $this->index_path, 'c+' );
		if ( false === $handle ) {
			// Try creating the file first.
			$handle = \fopen( $this->index_path, 'w+' );
			if ( false === $handle ) {
				return false;
			}
		}

		// Non-blocking lock with 3-second timeout.
		$locked   = false;
		$deadline = \microtime( true ) + 3.0;
		while ( ! $locked && \microtime( true ) < $deadline ) {
			$locked = \flock( $handle, LOCK_EX | LOCK_NB );
			if ( ! $locked ) {
				\usleep( 50000 ); // 50ms.
			}
		}

		if ( ! $locked ) {
			\fclose( $handle );
			return false;
		}

		// Truncate and write.
		\ftruncate( $handle, 0 );
		\rewind( $handle );
		\fwrite( $handle, $json );
		\fflush( $handle );
		\flock( $handle, LOCK_UN );
		\fclose( $handle );

		$this->dirty = false;

		/**
		 * Fires after a Paper Store index has been rebuilt.
		 *
		 * @since 1.1.0
		 *
		 * @param string $collection  Collection name.
		 * @param array  $index_stats Associative array with record_count and tag_count.
		 */
		\do_action(
			'wp_mcp_ai_paper_index_rebuilt',
			$this->collection,
			array(
				'record_count' => \count( $this->data['record_ids'] ),
				'tag_count'    => \count( $this->data['tags'] ),
			)
		);

		return true;
	}

	/**
	 * Index a single record (add or update).
	 *
	 * @param array $record Normalized record array.
	 * @return bool True on success.
	 */
	public function index_record( array $record ) {
		$this->load();

		$id    = isset( $record['id'] ) ? \sanitize_key( $record['id'] ) : '';
		$title = isset( $record['title'] ) ? \sanitize_text_field( $record['title'] ) : '';

		if ( empty( $id ) ) {
			return false;
		}

		// Add/update record in the master list.
		$this->data['record_ids'][ $id ] = $title;

		// Index tags.
		if ( isset( $record['tags'] ) && \is_array( $record['tags'] ) ) {
			foreach ( $record['tags'] as $tag ) {
				$tag = \sanitize_text_field( $tag );
				if ( empty( $tag ) ) {
					continue;
				}
				if ( ! isset( $this->data['tags'][ $tag ] ) ) {
					$this->data['tags'][ $tag ] = array();
				}
				if ( ! \in_array( $id, $this->data['tags'][ $tag ], true ) ) {
					$this->data['tags'][ $tag ][] = $id;
				}
			}
		}

		// Index status.
		if ( isset( $record['status'] ) && ! empty( $record['status'] ) ) {
			$status = \sanitize_key( $record['status'] );
			if ( ! isset( $this->data['status'][ $status ] ) ) {
				$this->data['status'][ $status ] = array();
			}
			if ( ! \in_array( $id, $this->data['status'][ $status ], true ) ) {
				$this->data['status'][ $status ][] = $id;
			}
		}

		// Index type.
		if ( isset( $record['type'] ) && ! empty( $record['type'] ) ) {
			$type = \sanitize_key( $record['type'] );
			if ( ! isset( $this->data['type'][ $type ] ) ) {
				$this->data['type'][ $type ] = array();
			}
			if ( ! \in_array( $id, $this->data['type'][ $type ], true ) ) {
				$this->data['type'][ $type ][] = $id;
			}
		}

		// Index author_id.
		if ( isset( $record['author_id'] ) ) {
			$author_id = \absint( $record['author_id'] );
			if ( ! isset( $this->data['author_id'][ $author_id ] ) ) {
				$this->data['author_id'][ $author_id ] = array();
			}
			if ( ! \in_array( $id, $this->data['author_id'][ $author_id ], true ) ) {
				$this->data['author_id'][ $author_id ][] = $id;
			}
		}

		// Index date bucket (year-month).
		$date_field = isset( $record['created_at'] ) ? $record['created_at'] : null;
		if ( null === $date_field && isset( $record['updated_at'] ) ) {
			$date_field = $record['updated_at'];
		}
		if ( null !== $date_field ) {
			$ts = \strtotime( $date_field );
			if ( false !== $ts ) {
				$bucket = \gmdate( 'Y-m', $ts );
				if ( ! isset( $this->data['date_bucket'][ $bucket ] ) ) {
					$this->data['date_bucket'][ $bucket ] = array();
				}
				if ( ! \in_array( $id, $this->data['date_bucket'][ $bucket ], true ) ) {
					$this->data['date_bucket'][ $bucket ][] = $id;
				}
			}
		}

		$this->data['_count'] = \count( $this->data['record_ids'] );
		$this->dirty          = true;

		return $this->save();
	}

	/**
	 * Remove a record from the index.
	 *
	 * @param string $id Record ID.
	 * @return bool True on success.
	 */
	public function remove_record( $id ) {
		$this->load();

		$id = \sanitize_key( $id );
		if ( empty( $id ) ) {
			return false;
		}

		unset( $this->data['record_ids'][ $id ] );

		// Remove from tag index.
		foreach ( $this->data['tags'] as $tag => &$ids ) {
			$ids = \array_values( \array_diff( $ids, array( $id ) ) );
			if ( empty( $ids ) ) {
				unset( $this->data['tags'][ $tag ] );
			}
		}
		unset( $ids );

		// Remove from status index.
		foreach ( $this->data['status'] as $status => &$ids ) {
			$ids = \array_values( \array_diff( $ids, array( $id ) ) );
			if ( empty( $ids ) ) {
				unset( $this->data['status'][ $status ] );
			}
		}
		unset( $ids );

		// Remove from type index.
		foreach ( $this->data['type'] as $type => &$ids ) {
			$ids = \array_values( \array_diff( $ids, array( $id ) ) );
			if ( empty( $ids ) ) {
				unset( $this->data['type'][ $type ] );
			}
		}
		unset( $ids );

		// Remove from author_id index.
		foreach ( $this->data['author_id'] as $author_id => &$ids ) {
			$ids = \array_values( \array_diff( $ids, array( $id ) ) );
			if ( empty( $ids ) ) {
				unset( $this->data['author_id'][ $author_id ] );
			}
		}
		unset( $ids );

		// Remove from date_bucket index.
		foreach ( $this->data['date_bucket'] as $bucket => &$ids ) {
			$ids = \array_values( \array_diff( $ids, array( $id ) ) );
			if ( empty( $ids ) ) {
				unset( $this->data['date_bucket'][ $bucket ] );
			}
		}
		unset( $ids );

		$this->data['_count'] = \count( $this->data['record_ids'] );
		$this->dirty          = true;

		return $this->save();
	}

	/**
	 * Rebuild the entire index from scratch by scanning all records in the collection.
	 *
	 * @param PaperDriverInterface $driver    The format driver for this collection.
	 * @param string               $extension File extension to scan (e.g. '.json').
	 * @return bool True on success.
	 */
	public function rebuild( PaperDriverInterface $driver, $extension ) {
		$this->data  = $this->empty_structure();
		$this->dirty = true;

		if ( ! \is_dir( $this->collection_dir ) ) {
			return $this->save();
		}

		$pattern = \trailingslashit( $this->collection_dir ) . '*' . $extension;
		$files   = \glob( $pattern );

		if ( ! \is_array( $files ) || empty( $files ) ) {
			return $this->save();
		}

		foreach ( $files as $file ) {
			if ( ! \is_file( $file ) ) {
				continue;
			}

			// Skip index files and dotfiles.
			$basename = \basename( $file );
			if ( '.' === $basename[0] || false !== \strpos( $basename, '.idx.' ) ) {
				continue;
			}

			$record = $driver->read( $file );
			if ( \is_wp_error( $record ) ) {
				continue;
			}

			// Index without persisting each time (batch save at end).
			$this->index_record_in_memory( $record );
		}

		$this->data['_count'] = \count( $this->data['record_ids'] );
		$this->dirty          = true;

		return $this->save();
	}

	/**
	 * Index a record in-memory without persisting (for batch rebuilds).
	 *
	 * @param array $record Normalized record array.
	 * @return void
	 */
	private function index_record_in_memory( array $record ) {
		$id    = isset( $record['id'] ) ? \sanitize_key( $record['id'] ) : '';
		$title = isset( $record['title'] ) ? \sanitize_text_field( $record['title'] ) : '';

		if ( empty( $id ) ) {
			return;
		}

		$this->data['record_ids'][ $id ] = $title;

		if ( isset( $record['tags'] ) && \is_array( $record['tags'] ) ) {
			foreach ( $record['tags'] as $tag ) {
				$tag = \sanitize_text_field( $tag );
				if ( empty( $tag ) ) {
					continue;
				}
				if ( ! isset( $this->data['tags'][ $tag ] ) ) {
					$this->data['tags'][ $tag ] = array();
				}
				if ( ! \in_array( $id, $this->data['tags'][ $tag ], true ) && \count( $this->data['tags'][ $tag ] ) < $this->max_tags ) {
					$this->data['tags'][ $tag ][] = $id;
				}
			}
		}

		if ( isset( $record['status'] ) && ! empty( $record['status'] ) ) {
			$status = \sanitize_key( $record['status'] );
			if ( ! isset( $this->data['status'][ $status ] ) ) {
				$this->data['status'][ $status ] = array();
			}
			if ( ! \in_array( $id, $this->data['status'][ $status ], true ) ) {
				$this->data['status'][ $status ][] = $id;
			}
		}

		if ( isset( $record['type'] ) && ! empty( $record['type'] ) ) {
			$type = \sanitize_key( $record['type'] );
			if ( ! isset( $this->data['type'][ $type ] ) ) {
				$this->data['type'][ $type ] = array();
			}
			if ( ! \in_array( $id, $this->data['type'][ $type ], true ) ) {
				$this->data['type'][ $type ][] = $id;
			}
		}

		if ( isset( $record['author_id'] ) ) {
			$author_id = \absint( $record['author_id'] );
			if ( ! isset( $this->data['author_id'][ $author_id ] ) ) {
				$this->data['author_id'][ $author_id ] = array();
			}
			if ( ! \in_array( $id, $this->data['author_id'][ $author_id ], true ) ) {
				$this->data['author_id'][ $author_id ][] = $id;
			}
		}

		$date_field = isset( $record['created_at'] ) ? $record['created_at'] : null;
		if ( null === $date_field && isset( $record['updated_at'] ) ) {
			$date_field = $record['updated_at'];
		}
		if ( null !== $date_field ) {
			$ts = \strtotime( $date_field );
			if ( false !== $ts ) {
				$bucket = \gmdate( 'Y-m', $ts );
				if ( ! isset( $this->data['date_bucket'][ $bucket ] ) ) {
					$this->data['date_bucket'][ $bucket ] = array();
				}
				if ( ! \in_array( $id, $this->data['date_bucket'][ $bucket ], true ) ) {
					$this->data['date_bucket'][ $bucket ][] = $id;
				}
			}
		}
	}

	/**
	 * Find record IDs by tag.
	 *
	 * @param string $tag Tag value.
	 * @return string[] Record IDs.
	 */
	public function find_by_tag( $tag ) {
		$this->load();
		$tag = \sanitize_text_field( $tag );
		return isset( $this->data['tags'][ $tag ] ) ? $this->data['tags'][ $tag ] : array();
	}

	/**
	 * Find record IDs by status.
	 *
	 * @param string $status Status value.
	 * @return string[] Record IDs.
	 */
	public function find_by_status( $status ) {
		$this->load();
		$status = \sanitize_key( $status );
		return isset( $this->data['status'][ $status ] ) ? $this->data['status'][ $status ] : array();
	}

	/**
	 * Find record IDs by type.
	 *
	 * @param string $type Type value.
	 * @return string[] Record IDs.
	 */
	public function find_by_type( $type ) {
		$this->load();
		$type = \sanitize_key( $type );
		return isset( $this->data['type'][ $type ] ) ? $this->data['type'][ $type ] : array();
	}

	/**
	 * Find record IDs by author ID.
	 *
	 * @param int $author_id Author user ID.
	 * @return string[] Record IDs.
	 */
	public function find_by_author( $author_id ) {
		$this->load();
		$author_id = \absint( $author_id );
		return isset( $this->data['author_id'][ $author_id ] ) ? $this->data['author_id'][ $author_id ] : array();
	}

	/**
	 * Find record IDs by date bucket (year-month).
	 *
	 * @param string $bucket Year-month string (e.g. "2026-05").
	 * @return string[] Record IDs.
	 */
	public function find_by_date_bucket( $bucket ) {
		$this->load();
		$bucket = \sanitize_text_field( $bucket );
		return isset( $this->data['date_bucket'][ $bucket ] ) ? $this->data['date_bucket'][ $bucket ] : array();
	}

	/**
	 * Get all indexed record IDs.
	 *
	 * @return array Associative array of id => title.
	 */
	public function get_all_record_ids() {
		$this->load();
		return $this->data['record_ids'];
	}

	/**
	 * Get the total record count.
	 *
	 * @return int
	 */
	public function get_count() {
		$this->load();
		return (int) $this->data['_count'];
	}

	/**
	 * Get all indexed tags.
	 *
	 * @return array Associative array of tag => count.
	 */
	public function get_all_tags() {
		$this->load();
		$tags = array();
		foreach ( $this->data['tags'] as $tag => $ids ) {
			$tags[ $tag ] = \count( $ids );
		}
		return $tags;
	}

	/**
	 * Ensure the index exists (build if missing).
	 *
	 * @param PaperDriverInterface $driver    Format driver.
	 * @param string               $extension File extension.
	 * @return bool True if index is ready.
	 */
	public function ensure_exists( PaperDriverInterface $driver, $extension ) {
		if ( \file_exists( $this->index_path ) ) {
			return true;
		}
		return $this->rebuild( $driver, $extension );
	}

	/**
	 * Drop the index file.
	 *
	 * @return bool True on success.
	 */
	public function drop() {
		$this->data  = null;
		$this->dirty = false;
		if ( \file_exists( $this->index_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Managed flat-file index deletion.
			return \unlink( $this->index_path );
		}
		return true;
	}
}
