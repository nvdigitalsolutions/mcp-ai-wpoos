<?php
/**
 * Paper repository (Wave E6, sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Paper_Repository`
 * (`includes/paper-store/class-wp-mcp-ai-paper-repository.php`):
 * byte-identical high-level CRUD for a Paper Store collection — the
 * `find`/`all`/`query`/`where`/`save`/`update`/`delete`/`truncate`/
 * `exists`/`count` surface, the `wp_mcp_ai_paper_record_before_save`
 * filter, the save-time defaults (type → collection name, author_id →
 * current user when logged in, status → `published`), the
 * `paper_missing_id` / `paper_not_found` error codes, immutable-field
 * protection on update (`id`, `created_at`), the re-read-after-write
 * normalization, and the `wp_mcp_ai_paper_record_saved` /
 * `wp_mcp_ai_paper_record_deleted` actions.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - `WP_Error` is fully qualified.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\PaperStore
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\PaperStore;

/**
 * One instance per collection. Delegates I/O to a driver, maintains
 * the index, and provides a fluent query builder.
 *
 * @since 1.1.0
 */
class PaperRepository {

	/**
	 * Collection name.
	 *
	 * @var string
	 */
	private $collection;

	/**
	 * Absolute path to the collection directory.
	 *
	 * @var string
	 */
	private $collection_dir;

	/**
	 * Format driver.
	 *
	 * @var PaperDriverInterface
	 */
	private $driver;

	/**
	 * Inverted index.
	 *
	 * @var PaperIndex
	 */
	private $index;

	/**
	 * Constructor.
	 *
	 * @param string               $collection     Collection name.
	 * @param string               $collection_dir Absolute path to the collection directory.
	 * @param PaperDriverInterface $driver         Format driver.
	 * @param PaperIndex           $index          Inverted index.
	 */
	public function __construct( $collection, $collection_dir, PaperDriverInterface $driver, PaperIndex $index ) {
		$this->collection     = \sanitize_key( $collection );
		$this->collection_dir = $collection_dir;
		$this->driver         = $driver;
		$this->index          = $index;

		// Ensure the index exists (lazy-build if missing).
		$this->index->ensure_exists( $this->driver, $this->driver->get_extension() );
	}

	/**
	 * Find a single record by ID.
	 *
	 * @param string $id Record ID.
	 * @return array|null|\WP_Error Record array, null if not found, or WP_Error on failure.
	 */
	public function find( $id ) {
		$id       = \sanitize_key( $id );
		$filepath = $this->get_record_path( $id );

		if ( ! \file_exists( $filepath ) ) {
			return null;
		}

		$record = $this->driver->read( $filepath );

		if ( \is_wp_error( $record ) ) {
			return $record;
		}

		return $record;
	}

	/**
	 * Get all records in the collection.
	 *
	 * @return array Array of record arrays.
	 */
	public function all() {
		$this->index->ensure_exists( $this->driver, $this->driver->get_extension() );
		$ids = \array_keys( $this->index->get_all_record_ids() );

		$records = array();
		foreach ( $ids as $id ) {
			$record = $this->find( $id );
			if ( null !== $record && ! \is_wp_error( $record ) ) {
				$records[] = $record;
			}
		}

		return $records;
	}

	/**
	 * Start a fluent query on this collection.
	 *
	 * @return PaperQuery
	 */
	public function query() {
		return new PaperQuery( $this->collection, $this, $this->index );
	}

	/**
	 * Shorthand: where clause on the default query.
	 *
	 * @param string $field    Field name.
	 * @param string $operator Operator.
	 * @param mixed  $value    Value.
	 * @return PaperQuery
	 */
	public function where( $field, $operator, $value ) {
		return $this->query()->where( $field, $operator, $value );
	}

	/**
	 * Save a record (create or update).
	 *
	 * If a record with the same ID exists, it will be overwritten.
	 * Timestamps are auto-managed. Returns the saved record.
	 *
	 * @param array $record Record data.
	 * @return array|\WP_Error Saved record array or WP_Error.
	 */
	public function save( array $record ) {
		// Allow pre-save modification.
		/**
		 * Filter a Paper Store record before it is saved.
		 *
		 * @since 1.1.0
		 *
		 * @param array  $record     Record data.
		 * @param string $collection Collection name.
		 */
		$record = \apply_filters( 'wp_mcp_ai_paper_record_before_save', $record, $this->collection );

		if ( empty( $record['id'] ) ) {
			return new \WP_Error(
				'paper_missing_id',
				__( 'Record must have an "id" field.', 'nvoos-content-graph-ai' )
			);
		}

		// Ensure type field is set to the collection name if not specified.
		if ( empty( $record['type'] ) ) {
			$record['type'] = $this->collection;
		}

		// Ensure author_id if user is logged in.
		if ( ! isset( $record['author_id'] ) && \is_user_logged_in() ) {
			$record['author_id'] = \get_current_user_id();
		}

		// Ensure status default.
		if ( empty( $record['status'] ) ) {
			$record['status'] = 'published';
		}

		// Write through driver.
		$id        = \sanitize_key( $record['id'] );
		$file_path = $this->get_record_path( $id );

		$result = $this->driver->write( $file_path, $record );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		// Update index.
		$this->index->index_record( $record );

		// Re-read to get the normalized record with timestamps.
		$saved = $this->driver->read( $file_path );

		if ( \is_wp_error( $saved ) ) {
			return $record; // Return what we have if re-read fails.
		}

		/**
		 * Fires after a Paper Store record has been saved.
		 *
		 * @since 1.1.0
		 *
		 * @param string $collection Collection name.
		 * @param string $record_id  Record ID.
		 * @param array  $record     Saved record data.
		 */
		\do_action( 'wp_mcp_ai_paper_record_saved', $this->collection, $id, $saved );

		return $saved;
	}

	/**
	 * Update specific fields on an existing record.
	 *
	 * @param string $id   Record ID.
	 * @param array  $data Fields to update.
	 * @return array|\WP_Error Updated record or WP_Error.
	 */
	public function update( $id, array $data ) {
		$id = \sanitize_key( $id );

		$existing = $this->find( $id );

		if ( null === $existing ) {
			return new \WP_Error(
				'paper_not_found',
				\sprintf(
					/* translators: %s: record ID */
					__( 'Record "%s" not found.', 'nvoos-content-graph-ai' ),
					$id
				)
			);
		}

		if ( \is_wp_error( $existing ) ) {
			return $existing;
		}

		// Protect immutable fields.
		unset( $data['id'] );
		unset( $data['created_at'] );

		// Merge data.
		$merged = \array_merge( $existing, $data );

		return $this->save( $merged );
	}

	/**
	 * Delete a record by ID.
	 *
	 * @param string $id Record ID.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function delete( $id ) {
		$id        = \sanitize_key( $id );
		$file_path = $this->get_record_path( $id );

		if ( ! \file_exists( $file_path ) ) {
			return new \WP_Error(
				'paper_not_found',
				\sprintf(
					/* translators: %s: record ID */
					__( 'Record "%s" not found.', 'nvoos-content-graph-ai' ),
					$id
				)
			);
		}

		$result = $this->driver->delete( $file_path );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		// Remove from index.
		$this->index->remove_record( $id );

		/**
		 * Fires after a Paper Store record has been deleted.
		 *
		 * @since 1.1.0
		 *
		 * @param string $collection Collection name.
		 * @param string $record_id  Deleted record ID.
		 */
		\do_action( 'wp_mcp_ai_paper_record_deleted', $this->collection, $id );

		return true;
	}

	/**
	 * Delete all records in the collection.
	 *
	 * @return int|\WP_Error Number of deleted records or WP_Error.
	 */
	public function truncate() {
		$ids     = \array_keys( $this->index->get_all_record_ids() );
		$deleted = 0;

		foreach ( $ids as $id ) {
			$result = $this->delete( $id );
			if ( true === $result ) {
				++$deleted;
			}
		}

		// Rebuild empty index.
		$this->index->drop();
		$this->index->ensure_exists( $this->driver, $this->driver->get_extension() );

		return $deleted;
	}

	/**
	 * Check if a record exists.
	 *
	 * @param string $id Record ID.
	 * @return bool
	 */
	public function exists( $id ) {
		$id       = \sanitize_key( $id );
		$filepath = $this->get_record_path( $id );
		return \file_exists( $filepath );
	}

	/**
	 * Get the total record count (from index).
	 *
	 * @return int
	 */
	public function count() {
		return $this->index->get_count();
	}

	/**
	 * Get the index instance.
	 *
	 * @return PaperIndex
	 */
	public function get_index() {
		return $this->index;
	}

	/**
	 * Get the driver instance.
	 *
	 * @return PaperDriverInterface
	 */
	public function get_driver() {
		return $this->driver;
	}

	/**
	 * Get the collection name.
	 *
	 * @return string
	 */
	public function get_collection_name() {
		return $this->collection;
	}

	/**
	 * Build the absolute file path for a record ID.
	 *
	 * @param string $id Record ID.
	 * @return string Absolute file path.
	 */
	private function get_record_path( $id ) {
		$id       = \sanitize_key( $id );
		$ext      = $this->driver->get_extension();
		$filename = $id . $ext;
		return \trailingslashit( $this->collection_dir ) . $filename;
	}
}
