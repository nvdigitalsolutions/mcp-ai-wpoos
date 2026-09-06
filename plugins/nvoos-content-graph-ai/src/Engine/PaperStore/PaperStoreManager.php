<?php
/**
 * Paper store manager (Wave E6, sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Paper_Store_Manager`
 * (`includes/paper-store/class-wp-mcp-ai-paper-store-manager.php`):
 * byte-identical lazy singleton that owns the Paper Store root — the
 * `wp_mcp_ai_paper_store_root` filter (default
 * `uploads/mcp-ai-wpoos/paper-store` so data survives mode
 * transitions), the `.htaccess` + `index.php` security-file
 * placement, the default JSON driver with the `wp_mcp_ai_paper_driver`
 * filter, per-collection repository caching, the
 * realpath-prefix `validate_path()` traversal guard
 * (`paper_path_error` / `paper_path_traversal` error codes), the
 * `list_collections()` enumeration, the `reset()` test hook, and the
 * `wp_mcp_ai_paper_store_initialized` action.
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
 * Singleton. Call get_instance() → get_repository('collection_name').
 * Repositories are cached per collection.
 *
 * @since 1.1.0
 */
class PaperStoreManager {

	/**
	 * Singleton instance.
	 *
	 * @var PaperStoreManager|null
	 */
	private static $instance = null;

	/**
	 * Absolute path to the Paper Store root directory.
	 *
	 * @var string
	 */
	private $root_path;

	/**
	 * Absolute path to the indexes directory.
	 *
	 * @var string
	 */
	private $indexes_path;

	/**
	 * Initialized flag.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Cached driver instances (keyed by extension).
	 *
	 * @var array<string, PaperDriverInterface>
	 */
	private $drivers = array();

	/**
	 * Cached repository instances (keyed by collection name).
	 *
	 * @var array<string, PaperRepository>
	 */
	private $repositories = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return PaperStoreManager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor (private — singleton).
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialisation.
	 */
	public function __wakeup() {} // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Double-underscore magic method (__wakeup) required by PHP serialization interface.

	/**
	 * Initialize the manager (lazy — called on first use).
	 *
	 * @return void
	 */
	private function init() {
		if ( $this->initialized ) {
			return;
		}

		$uploads = \wp_upload_dir();

		/**
		 * Filter the Paper Store root directory path.
		 *
		 * @since 1.1.0
		 *
		 * @param string $root_path Absolute path to the paper-store directory.
		 */
		$this->root_path = \apply_filters(
			'wp_mcp_ai_paper_store_root',
			\trailingslashit( $uploads['basedir'] ) . 'mcp-ai-wpoos/paper-store'
		);

		$this->indexes_path = \trailingslashit( $this->root_path ) . '_indexes';

		// Ensure root directory exists.
		if ( ! \is_dir( $this->root_path ) ) {
			\wp_mkdir_p( $this->root_path );
		}

		// Only create security files if the root directory was created.
		if ( \is_dir( $this->root_path ) ) {
			// Place .htaccess to deny direct HTTP access.
			$htaccess = \trailingslashit( $this->root_path ) . '.htaccess';
			if ( ! \file_exists( $htaccess ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Managed security file creation.
				\file_put_contents( $htaccess, "Deny from all\n" );
			}

			// Place index.php for silence.
			$index_php = \trailingslashit( $this->root_path ) . 'index.php';
			if ( ! \file_exists( $index_php ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Managed security file creation.
				\file_put_contents( $index_php, "<?php\n// Silence is golden.\n" );
			}
		}

		// Register default JSON driver.
		$this->drivers['.json'] = new PaperJsonDriver();

		$this->initialized = true;

		/**
		 * Fires after the Paper Store manager has been initialized.
		 *
		 * @since 1.1.0
		 *
		 * @param string $root_path Absolute path to the paper-store root.
		 */
		\do_action( 'wp_mcp_ai_paper_store_initialized', $this->root_path );
	}

	/**
	 * Get (or create) a repository for a named collection.
	 *
	 * @param string $collection Collection name.
	 * @param string $extension  File extension (default '.json').
	 * @return PaperRepository
	 */
	public function get_repository( $collection, $extension = '.json' ) {
		$this->init();

		$collection = \sanitize_key( $collection );

		if ( isset( $this->repositories[ $collection ] ) ) {
			return $this->repositories[ $collection ];
		}

		$driver = $this->get_driver( $extension );

		$collection_dir = \trailingslashit( $this->root_path ) . $collection;

		// Ensure collection directory exists.
		if ( ! \is_dir( $collection_dir ) ) {
			\wp_mkdir_p( $collection_dir );
		}

		$index = new PaperIndex( $collection, $collection_dir, $this->indexes_path );

		$repository = new PaperRepository( $collection, $collection_dir, $driver, $index );

		$this->repositories[ $collection ] = $repository;

		return $repository;
	}

	/**
	 * Get (or create) a driver for a file extension.
	 *
	 * @param string $extension File extension including dot (e.g. '.json').
	 * @return PaperDriverInterface
	 */
	public function get_driver( $extension ) {
		$this->init();

		if ( isset( $this->drivers[ $extension ] ) ) {
			return $this->drivers[ $extension ];
		}

		// Default fallback to JSON driver.
		$driver = new PaperJsonDriver();

		/**
		 * Filter to register additional Paper Store drivers.
		 *
		 * @since 1.1.0
		 *
		 * @param PaperDriverInterface $driver    Default driver for this extension.
		 * @param string               $extension File extension.
		 * @return PaperDriverInterface
		 */
		$driver = \apply_filters( 'wp_mcp_ai_paper_driver', $driver, $extension );

		$this->drivers[ $extension ] = $driver;

		return $driver;
	}

	/**
	 * Register a custom driver for a file extension.
	 *
	 * @param string               $extension File extension including dot.
	 * @param PaperDriverInterface $driver    Driver instance.
	 * @return void
	 */
	public function register_driver( $extension, PaperDriverInterface $driver ) {
		$this->init();
		$this->drivers[ $extension ] = $driver;
	}

	/**
	 * Validate that a path is within the Paper Store root (anti-traversal).
	 *
	 * @param string $path Absolute path to validate.
	 * @return bool|\WP_Error True if safe, WP_Error if traversal detected.
	 */
	public function validate_path( $path ) {
		$this->init();

		$real_path = \realpath( $path );
		$real_root = \realpath( $this->root_path );

		if ( false === $real_path || false === $real_root ) {
			return new \WP_Error(
				'paper_path_error',
				__( 'Invalid path.', 'nvoos-content-graph-ai' )
			);
		}

		if ( 0 !== \strpos( $real_path, $real_root . DIRECTORY_SEPARATOR )
			&& $real_path !== $real_root ) {
			return new \WP_Error(
				'paper_path_traversal',
				__( 'Path traversal detected.', 'nvoos-content-graph-ai' )
			);
		}

		return true;
	}

	/**
	 * Get the root path.
	 *
	 * @return string
	 */
	public function get_root_path() {
		$this->init();
		return $this->root_path;
	}

	/**
	 * Get the indexes path.
	 *
	 * @return string
	 */
	public function get_indexes_path() {
		$this->init();
		return $this->indexes_path;
	}

	/**
	 * List all available collections (subdirectories in the paper-store root).
	 *
	 * @return string[] Collection names.
	 */
	public function list_collections() {
		$this->init();

		if ( ! \is_dir( $this->root_path ) ) {
			return array();
		}

		$collections = array();
		$items       = \scandir( $this->root_path );

		if ( ! \is_array( $items ) ) {
			return array();
		}

		foreach ( $items as $item ) {
			if ( '.' === $item[0] ) {
				continue;
			}
			$full_path = \trailingslashit( $this->root_path ) . $item;
			if ( \is_dir( $full_path ) && '_indexes' !== $item ) {
				$collections[] = $item;
			}
		}

		\sort( $collections );
		return $collections;
	}

	/**
	 * Drop all repositories from cache (useful for testing).
	 *
	 * @return void
	 */
	public function reset() {
		$this->initialized  = false;
		$this->drivers      = array();
		$this->repositories = array();
	}
}
