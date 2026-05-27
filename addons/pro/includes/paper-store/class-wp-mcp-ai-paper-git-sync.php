<?php
/**
 * Paper Git Sync — Periodic git versioning of the Paper Store directory.
 *
 * Uses symfony/process to run git commands. Configurable commit interval,
 * remote URL, and branch. Respects FS_METHOD — warns when WordPress
 * credential system would interfere.
 *
 * PHP 8.1+ only (Pro addon).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

/**
 * Class WP_MCP_AI_Paper_Git_Sync
 *
 * Singleton. Call init() to register cron hooks. Auto-commit is opt-in
 * via the filter `wp_mcp_ai_paper_git_sync_enabled`.
 */
class WP_MCP_AI_Paper_Git_Sync {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	private string $cron_hook = 'wp_mcp_ai_paper_git_sync';

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize cron hooks.
	 */
	public static function init(): void {
		$instance = self::get_instance();

		/**
		 * Filter: enable Git sync for the Paper Store.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $enabled Whether Git sync is enabled. Default false.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_paper_git_sync_enabled', false ) ) {
			return;
		}

		// Respect FS_METHOD — warn if FTP credentials are in use.
		if ( defined( 'FS_METHOD' ) && 'direct' !== FS_METHOD ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging.
				error_log( 'WP_MCP_AI: Paper Store Git sync disabled — FS_METHOD is not "direct".' );
			}
			return;
		}

		if ( ! wp_next_scheduled( $instance->cron_hook ) ) {
			wp_schedule_event( time(), 'hourly', $instance->cron_hook );
		}

		add_action( $instance->cron_hook, array( $instance, 'sync' ) );
	}

	/**
	 * Run git add, commit, and push on the Paper Store directory.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function sync() {
		$manager   = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$root_path = $manager->get_root_path();

		if ( ! is_dir( $root_path ) ) {
			return new WP_Error(
				'paper_git_no_dir',
				__( 'Paper Store directory does not exist.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if git is available.
		if ( ! $this->is_git_available() ) {
			return new WP_Error(
				'paper_git_not_found',
				__( 'Git binary not found on this system.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Initialize git repo if not already.
		$git_dir = trailingslashit( $root_path ) . '.git';
		if ( ! is_dir( $git_dir ) ) {
			$result = $this->run_git( $root_path, array( 'init' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Stage all changes.
		$result = $this->run_git( $root_path, array( 'add', '-A' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Check if there's anything to commit.
		$status = $this->run_git( $root_path, array( 'status', '--porcelain' ) );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		if ( empty( trim( $status ) ) ) {
			return true; // Nothing to commit.
		}

		// Commit.
		$message = sprintf(
			'Paper Store auto-commit — %s',
			gmdate( 'Y-m-d H:i:s' )
		);
		$result = $this->run_git( $root_path, array( 'commit', '-m', $message ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Push if remote is configured.
		$remote_url = apply_filters( 'wp_mcp_ai_paper_git_remote_url', '' );
		$branch     = apply_filters( 'wp_mcp_ai_paper_git_branch', 'main' );

		if ( ! empty( $remote_url ) ) {
			// Check if origin remote exists.
			$remotes = $this->run_git( $root_path, array( 'remote' ) );
			if ( ! is_wp_error( $remotes ) && false === strpos( $remotes, 'origin' ) ) {
				$this->run_git( $root_path, array( 'remote', 'add', 'origin', $remote_url ) );
			}

			$this->run_git( $root_path, array( 'push', 'origin', $branch ) );
		}

		return true;
	}

	/**
	 * Check if the git binary is available on the system.
	 *
	 * @return bool
	 */
	private function is_git_available(): bool {
		if ( ! class_exists( 'Symfony\\Component\\Process\\Process' ) ) {
			return false;
		}

		try {
			$process = new Symfony\Component\Process\Process( array( 'git', '--version' ) );
			$process->run();
			return $process->isSuccessful();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Run a git command in the given directory.
	 *
	 * @param string   $cwd  Working directory.
	 * @param string[] $args Command arguments.
	 * @return string|WP_Error Stdout on success, WP_Error on failure.
	 */
	private function run_git( string $cwd, array $args ) {
		if ( ! class_exists( 'Symfony\\Component\\Process\\Process' ) ) {
			return new WP_Error(
				'paper_git_no_process',
				__( 'Symfony Process component not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		try {
			$command = array_merge( array( 'git' ), $args );
			$process = new Symfony\Component\Process\Process( $command, $cwd );
			$process->run();

			if ( ! $process->isSuccessful() ) {
				return new WP_Error(
					'paper_git_failed',
					sprintf(
						/* translators: %s: error output */
						__( 'Git command failed: %s', 'mcp-ai-wpoos-pro' ),
						$process->getErrorOutput()
					)
				);
			}

			return $process->getOutput();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'paper_git_exception',
				$e->getMessage()
			);
		}
	}

	/**
	 * Clear scheduled cron hook.
	 */
	public static function deactivate(): void {
		$instance = self::get_instance();
		wp_clear_scheduled_hook( $instance->cron_hook );
	}
}
