<?php
/**
 * Paper Store Manager — Singleton that owns the Paper Store root and wiring.
 *
 * Creates driver instances, repository instances, and validates all paths
 * against traversal attacks. The single entry point for all Paper Store
 * access.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Paper_Store_Manager
 *
 * Singleton. Call get_instance() → get_repository('collection_name').
 * Repositories are cached per collection.
 */
class WP_MCP_AI_Paper_Store_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Paper_Store_Manager|null
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
	 * @var array<string, WP_MCP_AI_Paper_Driver_Interface>
	 */
	private $drivers = array();

	/**
	 * Cached repository instances (keyed by collection name).
	 *
	 * @var array<string, WP_MCP_AI_Paper_Repository>
	 */
	private $repositories = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return WP_MCP_AI_Paper_Store_Manager
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

		$uploads = wp_upload_dir();

		/**
		 * Filter the Paper Store root directory path.
		 *
		 * @since 1.3.0
		 *
		 * @param string $root_path Absolute path to the paper-store directory.
		 */
		$this->root_path = apply_filters(
			'wp_mcp_ai_paper_store_root',
			trailingslashit( $uploads['basedir'] ) . 'mcp-ai-wpoos/paper-store'
		);

		$this->indexes_path = trailingslashit( $this->root_path ) . '_indexes';

		// Ensure root directory exists.
		if ( ! is_dir( $this->root_path ) ) {
			wp_mkdir_p( $this->root_path );
		}

		// Only create security files if the root directory was created.
		if ( is_dir( $this->root_path ) ) {
			// Place .htaccess to deny direct HTTP access.
			$htaccess = trailingslashit( $this->root_path ) . '.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Managed security file creation.
				file_put_contents( $htaccess, "Deny from all\n" );
			}

			// Place index.php for silence.
			$index_php = trailingslashit( $this->root_path ) . 'index.php';
			if ( ! file_exists( $index_php ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Managed security file creation.
				file_put_contents( $index_php, "<?php\n// Silence is golden.\n" );
			}
		}

		// Register default JSON driver.
		$this->drivers['.json'] = new WP_MCP_AI_Paper_Json_Driver();

		$this->initialized = true;

		/**
		 * Fires after the Paper Store manager has been initialized.
		 *
		 * @since 1.3.0
		 *
		 * @param string $root_path Absolute path to the paper-store root.
		 */
		do_action( 'wp_mcp_ai_paper_store_initialized', $this->root_path );
	}

	/**
	 * Get (or create) a repository for a named collection.
	 *
	 * @param string $collection Collection name.
	 * @param string $extension  File extension (default '.json').
	 * @return WP_MCP_AI_Paper_Repository
	 */
	public function get_repository( $collection, $extension = '.json' ) {
		$this->init();

		$collection = sanitize_key( $collection );

		if ( isset( $this->repositories[ $collection ] ) ) {
			return $this->repositories[ $collection ];
		}

		$driver = $this->get_driver( $extension );

		$collection_dir = trailingslashit( $this->root_path ) . $collection;

		// Ensure collection directory exists.
		if ( ! is_dir( $collection_dir ) ) {
			wp_mkdir_p( $collection_dir );
		}

		$index = new WP_MCP_AI_Paper_Index( $collection, $collection_dir, $this->indexes_path );

		$repository = new WP_MCP_AI_Paper_Repository( $collection, $collection_dir, $driver, $index );

		$this->repositories[ $collection ] = $repository;

		return $repository;
	}

	/**
	 * Get (or create) a driver for a file extension.
	 *
	 * @param string $extension File extension including dot (e.g. '.json').
	 * @return WP_MCP_AI_Paper_Driver_Interface
	 */
	public function get_driver( $extension ) {
		$this->init();

		if ( isset( $this->drivers[ $extension ] ) ) {
			return $this->drivers[ $extension ];
		}

		// Default fallback to JSON driver.
		$driver = new WP_MCP_AI_Paper_Json_Driver();

		/**
		 * Filter to register additional Paper Store drivers.
		 *
		 * @since 1.3.0
		 *
		 * @param WP_MCP_AI_Paper_Driver_Interface $driver    Default driver for this extension.
		 * @param string                           $extension File extension.
		 * @return WP_MCP_AI_Paper_Driver_Interface
		 */
		$driver = apply_filters( 'wp_mcp_ai_paper_driver', $driver, $extension );

		$this->drivers[ $extension ] = $driver;

		return $driver;
	}

	/**
	 * Register a custom driver for a file extension.
	 *
	 * @param string                           $extension File extension including dot.
	 * @param WP_MCP_AI_Paper_Driver_Interface $driver    Driver instance.
	 * @return void
	 */
	public function register_driver( $extension, WP_MCP_AI_Paper_Driver_Interface $driver ) {
		$this->init();
		$this->drivers[ $extension ] = $driver;
	}

	/**
	 * Validate that a path is within the Paper Store root (anti-traversal).
	 *
	 * @param string $path Absolute path to validate.
	 * @return bool|WP_Error True if safe, WP_Error if traversal detected.
	 */
	public function validate_path( $path ) {
		$this->init();

		$real_path = realpath( $path );
		$real_root = realpath( $this->root_path );

		if ( false === $real_path || false === $real_root ) {
			return new WP_Error(
				'paper_path_error',
				__( 'Invalid path.', 'mcp-ai-wpoos' )
			);
		}

		if ( 0 !== strpos( $real_path, $real_root . DIRECTORY_SEPARATOR )
			&& $real_path !== $real_root ) {
			return new WP_Error(
				'paper_path_traversal',
				__( 'Path traversal detected.', 'mcp-ai-wpoos' )
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

		if ( ! is_dir( $this->root_path ) ) {
			return array();
		}

		$collections = array();
		$items       = scandir( $this->root_path );

		if ( ! is_array( $items ) ) {
			return array();
		}

		foreach ( $items as $item ) {
			if ( '.' === $item[0] ) {
				continue;
			}
			$full_path = trailingslashit( $this->root_path ) . $item;
			if ( is_dir( $full_path ) && '_indexes' !== $item ) {
				$collections[] = $item;
			}
		}

		sort( $collections );
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
