<?php
/**
 * Per-suite bootstrap for the Graphify addon tests.
 *
 * The Graphify addon is not loaded by the main test bootstrap (loading it
 * globally would register its tools and skew suites that assert on the exact
 * set of registered tools, e.g. test-assistant-tool-presets.php). The PHPUnit
 * suite scans every .php file in this directory before running tests, so this
 * file loads the addon exactly for the graphify suites — mirroring the
 * per-addon bootstrap pattern used by addons/saas-controller/tests/.
 *
 * @package NV_oOS_Graphify
 */

if ( ! defined( 'NVOOS_GRAPHIFY_VERSION' ) ) {
	$graphify_entry = dirname( __DIR__, 2 ) . '/addons/graphify/nvoos-graphify.php';
	if ( file_exists( $graphify_entry ) ) {
		require_once $graphify_entry;
	}
}

// The addon entry file does not require the tool classes (they are
// discovered by the tool registry from their filenames); the tests below
// instantiate them directly, so load them here.
$tool_files = glob( dirname( __DIR__, 2 ) . '/addons/graphify/includes/tools/class-nvoos-graphify-tool-*.php' );
if ( false === $tool_files ) {
	$tool_files = array();
}
foreach ( $tool_files as $tool_file ) {
	require_once $tool_file;
}
