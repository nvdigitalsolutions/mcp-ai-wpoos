<?php
/**
 * Filesystem Service
 *
 * Provides Symfony Filesystem integration for safe file operations.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Filesystem;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/**
 * Class WP_MCP_AI_Filesystem_Service
 *
 * Wraps Symfony Filesystem for WordPress plugin use with atomic operations.
 */
class WP_MCP_AI_Filesystem_Service {

	/**
	 * Filesystem instance.
	 *
	 * @var Filesystem
	 */
	private $filesystem;

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Filesystem_Service|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Filesystem_Service
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->filesystem = new Filesystem();
	}

	/**
	 * Write content to a file atomically.
	 *
	 * @param string $filename File path.
	 * @param string $content  Content to write.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function write_file( $filename, $content ) {
		try {
			$this->filesystem->dumpFile( $filename, $content );
			return true;
		} catch ( IOExceptionInterface $e ) {
			return new \WP_Error(
				'filesystem_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to write file: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Append content to a file.
	 *
	 * @param string $filename File path.
	 * @param string $content  Content to append.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function append_to_file( $filename, $content ) {
		try {
			$this->filesystem->appendToFile( $filename, $content );
			return true;
		} catch ( IOExceptionInterface $e ) {
			return new \WP_Error(
				'filesystem_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to append to file: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Create a directory (including parent directories).
	 *
	 * @param string|array $dirs Directory path(s).
	 * @param int          $mode Directory permissions.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function mkdir( $dirs, $mode = 0755 ) {
		try {
			$this->filesystem->mkdir( $dirs, $mode );
			return true;
		} catch ( IOExceptionInterface $e ) {
			return new \WP_Error(
				'filesystem_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to create directory: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Check if a file or directory exists.
	 *
	 * @param string|array $files File path(s).
	 * @return bool True if exists.
	 */
	public function exists( $files ) {
		return $this->filesystem->exists( $files );
	}

	/**
	 * Remove files or directories.
	 *
	 * @param string|array $files File path(s).
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function remove( $files ) {
		try {
			$this->filesystem->remove( $files );
			return true;
		} catch ( IOExceptionInterface $e ) {
			return new \WP_Error(
				'filesystem_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to remove file: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Copy a file.
	 *
	 * @param string $origin_file  Origin file path.
	 * @param string $target_file  Target file path.
	 * @param bool   $override_newer_files Override newer files.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function copy( $origin_file, $target_file, $override_newer_files = false ) {
		try {
			$this->filesystem->copy( $origin_file, $target_file, $override_newer_files );
			return true;
		} catch ( IOExceptionInterface $e ) {
			return new \WP_Error(
				'filesystem_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to copy file: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Rename a file or directory.
	 *
	 * @param string $origin Origin path.
	 * @param string $target Target path.
	 * @param bool   $overwrite Overwrite target if exists.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function rename( $origin, $target, $overwrite = false ) {
		try {
			$this->filesystem->rename( $origin, $target, $overwrite );
			return true;
		} catch ( IOExceptionInterface $e ) {
			return new \WP_Error(
				'filesystem_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to rename file: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Change file permissions.
	 *
	 * @param string|array $files     File path(s).
	 * @param int          $mode      Permission mode.
	 * @param int          $umask     Umask.
	 * @param bool         $recursive Recursive for directories.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function chmod( $files, $mode, $umask = 0000, $recursive = false ) {
		try {
			$this->filesystem->chmod( $files, $mode, $umask, $recursive );
			return true;
		} catch ( IOExceptionInterface $e ) {
			return new \WP_Error(
				'filesystem_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to change permissions: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Get the underlying Symfony Filesystem instance.
	 *
	 * For advanced operations not covered by wrapper methods.
	 *
	 * @return Filesystem
	 */
	public function get_filesystem() {
		return $this->filesystem;
	}
}
