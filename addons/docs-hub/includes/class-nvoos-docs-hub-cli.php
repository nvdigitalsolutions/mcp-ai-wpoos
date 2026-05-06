<?php
/**
 * NV oOS Docs Hub — WP-CLI Command
 *
 * Provides WP-CLI commands for managing the Docs Hub index.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for the Docs Hub addon.
 *
 * @since 1.0.0
 */
class NV_oOS_Docs_Hub_CLI extends WP_CLI_Command {

	/**
	 * Run a full documentation rebuild.
	 *
	 * ## OPTIONS
	 *
	 * [--strict]
	 * : Exit with a non-zero code if any broken links are found.
	 *
	 * ## EXAMPLES
	 *
	 *   # Basic rebuild
	 *   wp nvoos-docs sync
	 *
	 *   # Fail on broken links
	 *   wp nvoos-docs sync --strict
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function sync( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		WP_CLI::log( 'Starting documentation rebuild...' );

		$result = NV_oOS_Docs_Hub_Rebuild_Job::run();

		if ( ! $result['success'] ) {
			WP_CLI::error( 'Rebuild failed: ' . ( $result['error'] ?? 'Unknown error' ) );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Rebuilt in %dms. Pages: %d. Broken links: %d.',
				$result['duration_ms'],
				$result['pages'],
				$result['broken_links']
			)
		);

		$strict = ! empty( $assoc_args['strict'] );
		if ( $strict && $result['broken_links'] > 0 ) {
			WP_CLI::error(
				sprintf( '%d broken link(s) found and --strict is set.', $result['broken_links'] )
			);
		}
	}

	/**
	 * Clear the documentation cache.
	 *
	 * ## EXAMPLES
	 *
	 *   wp nvoos-docs clear
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function clear( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->clear();
		WP_CLI::success( 'Documentation cache cleared.' );
	}

	/**
	 * Show the current documentation index status.
	 *
	 * ## EXAMPLES
	 *
	 *   wp nvoos-docs status
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$cache      = new NV_oOS_Docs_Hub_Cache();
		$last_built = $cache->get_last_built();
		$manifest   = $cache->get_manifest();

		$total_pages  = is_array( $manifest ) ? ( $manifest['total_pages'] ?? 0 ) : 0;
		$broken_links = is_array( $manifest ) ? count( $manifest['broken_links'] ?? array() ) : 0;

		WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'Key'   => 'Last Built',
					'Value' => $last_built > 0 ? gmdate( 'Y-m-d H:i:s', $last_built ) . ' UTC' : 'Never',
				),
				array( 'Key' => 'Total Pages', 'Value' => $total_pages ),
				array( 'Key' => 'Broken Links', 'Value' => $broken_links ),
				array( 'Key' => 'Version', 'Value' => NVOOS_DOCS_HUB_VERSION ),
			),
			array( 'Key', 'Value' )
		);
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'nvoos-docs', 'NV_oOS_Docs_Hub_CLI' );
}
