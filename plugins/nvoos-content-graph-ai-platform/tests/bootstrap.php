<?php
/**
 * PHPUnit bootstrap for the NV oOS Content Graph Platform addon.
 *
 * Monorepo context: reuses the root plugin's test bootstrap, which sets up
 * the WordPress test environment and loads the base plugin (mcp-ai-wpoos) —
 * producing the "monolith + platform" matrix. The Content Graph ecosystem
 * plugins are then loaded so platform tests can reference core/AI classes.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

$nvoos_content_graph_ai_platform_root      = dirname( __DIR__ );
$nvoos_content_graph_ai_platform_mono_root = dirname( __DIR__, 3 );

$nvoos_content_graph_ai_platform_root_bootstrap = $nvoos_content_graph_ai_platform_mono_root . '/tests/bootstrap.php';
if ( ! file_exists( $nvoos_content_graph_ai_platform_root_bootstrap ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test bootstrap diagnostics.
	fwrite( STDERR, "Platform tests require the monorepo root test bootstrap at {$nvoos_content_graph_ai_platform_root_bootstrap}.\n" );
	exit( 1 );
}

// Standalone matrix: the base plugin must not load. The root bootstrap
// honours WP_MCP_AI_SKIP_BASE_PLUGIN=1 (set in-process so getenv() sees it).
if ( '1' === getenv( 'WP_MCP_AI_PLATFORM_STANDALONE' ) ) {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Matrix selection for the test bootstrap only.
	putenv( 'WP_MCP_AI_SKIP_BASE_PLUGIN=1' );
	$_SERVER['WP_MCP_AI_SKIP_BASE_PLUGIN'] = '1';
}

require_once $nvoos_content_graph_ai_platform_root_bootstrap;

// Load the Content Graph ecosystem plugins. These files only register
// hooks/autoloaders — safe to require at bootstrap time.
$nvoos_content_graph_ai_platform_ecosystem = array(
	$nvoos_content_graph_ai_platform_mono_root . '/plugins/nvoos-content-graph/nvoos-content-graph.php',
	$nvoos_content_graph_ai_platform_mono_root . '/plugins/nvoos-content-graph-ai/nvoos-content-graph-ai.php',
	$nvoos_content_graph_ai_platform_root . '/nvoos-content-graph-ai-platform.php',
);
foreach ( $nvoos_content_graph_ai_platform_ecosystem as $nvoos_content_graph_ai_platform_plugin_file ) {
	if ( file_exists( $nvoos_content_graph_ai_platform_plugin_file ) ) {
		require_once $nvoos_content_graph_ai_platform_plugin_file;
	}
}
unset( $nvoos_content_graph_ai_platform_plugin_file, $nvoos_content_graph_ai_platform_ecosystem );
