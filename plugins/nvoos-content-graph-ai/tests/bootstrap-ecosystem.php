<?php
/**
 * PHPUnit bootstrap for the ecosystem-port matrix of the AI addon.
 *
 * Runs alongside the addon's own suite (tests/bootstrap.php) without
 * replacing it. This bootstrap reuses the monorepo root test bootstrap so
 * the addon can be exercised in the two ecosystem matrices:
 *
 *   - monolith:   base plugin + content-graph core + AI addon
 *   - standalone: content-graph core + AI addon, base plugin ABSENT
 *                 (WP_MCP_AI_AI_STANDALONE=1 — the additive end state)
 *
 * See docs/project/plans/base-pro-ecosystem-port-plan.md (Phase 0.4).
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

$nvoos_content_graph_ai_root      = dirname( __DIR__ );
$nvoos_content_graph_ai_mono_root = dirname( __DIR__, 3 );

$nvoos_content_graph_ai_root_bootstrap = $nvoos_content_graph_ai_mono_root . '/tests/bootstrap.php';
if ( ! file_exists( $nvoos_content_graph_ai_root_bootstrap ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test bootstrap diagnostics.
	fwrite( STDERR, "Content Graph AI ecosystem tests require the monorepo root test bootstrap at {$nvoos_content_graph_ai_root_bootstrap}.\n" );
	exit( 1 );
}

// Standalone matrix: the base plugin must not load. The root bootstrap
// honours WP_MCP_AI_SKIP_BASE_PLUGIN=1 (set in-process so getenv() sees it).
if ( '1' === getenv( 'WP_MCP_AI_AI_STANDALONE' ) ) {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Matrix selection for the test bootstrap only.
	putenv( 'WP_MCP_AI_SKIP_BASE_PLUGIN=1' );
	$_SERVER['WP_MCP_AI_SKIP_BASE_PLUGIN'] = '1';
}

require_once $nvoos_content_graph_ai_root_bootstrap;

// Load the Content Graph ecosystem plugins. These files only register
// hooks/autoloaders — safe to require at bootstrap time.
$nvoos_content_graph_ai_ecosystem = array(
	$nvoos_content_graph_ai_mono_root . '/plugins/nvoos-content-graph/nvoos-content-graph.php',
	$nvoos_content_graph_ai_root . '/nvoos-content-graph-ai.php',
);
foreach ( $nvoos_content_graph_ai_ecosystem as $nvoos_content_graph_ai_plugin_file ) {
	if ( file_exists( $nvoos_content_graph_ai_plugin_file ) ) {
		require_once $nvoos_content_graph_ai_plugin_file;
	}
}
unset( $nvoos_content_graph_ai_plugin_file, $nvoos_content_graph_ai_ecosystem );
