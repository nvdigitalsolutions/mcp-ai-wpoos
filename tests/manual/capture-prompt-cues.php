<?php
/**
 * Regenerates tests/fixtures/harness/prompt-cues.json from WordPress.
 *
 * Run inside the WordPress container:
 *
 *   docker exec -w /var/www/html/wp-content/plugins/mcp-ai-wpoos oos-wp \
 *     php tests/manual/capture-prompt-cues.php
 *
 * The corpus records the WordPress tool's response for every task-class filter,
 * including the full cue text, so the core port is measured against what the
 * plugin actually returns rather than against the catalogue it was copied from.
 *
 * Nothing in this repository hooks `wp_mcp_ai_register_prompt_cues`, and the
 * library is reset before capture, so the seeded eight cues are the whole
 * catalogue here. A site with third-party cues would capture more.
 *
 * Regenerating is a deliberate act: it asserts "this behaviour is correct", not
 * "the test is green again".
 *
 * @package WP_MCP_AI\Tests
 * @since   2.6.0
 * @license GPL-3.0-or-later
 */

// phpcs:disable WordPress.WP.GlobalVariablesProhibited, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone runner.

if ( ! defined( 'ABSPATH' ) ) {
	$wp_root = __DIR__;

	while ( ! is_file( $wp_root . '/wp-load.php' ) && dirname( $wp_root ) !== $wp_root ) {
		$wp_root = dirname( $wp_root );
	}

	if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
		fwrite( STDERR, 'Could not locate wp-load.php above ' . __DIR__ . "\n" );
		exit( 1 );
	}

	require $wp_root . '/wp-load.php';
}

require_once WP_MCP_AI_PATH . 'includes/harness/class-wp-mcp-ai-prompt-cue-library.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-list-prompt-cues.php';

$library = WP_MCP_AI_Prompt_Cue_Library::get_instance();
$library->reset();

$tool = new WP_MCP_AI_Tool_List_Prompt_Cues();

$filters = array(
	'no-argument'    => array(),
	'empty-filter'   => array( 'task_class' => '' ),
	'general'        => array( 'task_class' => 'general' ),
	'math'           => array( 'task_class' => 'math' ),
	'code'           => array( 'task_class' => 'code' ),
	'qa'             => array( 'task_class' => 'qa' ),
	'rag'            => array( 'task_class' => 'rag' ),
	'research'       => array( 'task_class' => 'research' ),
	'reasoning'      => array( 'task_class' => 'reasoning' ),
	'agentic'        => array( 'task_class' => 'agentic' ),
	'unknown-bucket' => array( 'task_class' => 'this-site' ),
	'uppercase'      => array( 'task_class' => 'Math' ),
	'punctuation'    => array( 'task_class' => 'math!@#$' ),
	'numeric-string' => array( 'task_class' => 123 ),
);

$out = array();

foreach ( $filters as $name => $args ) {
	$out[ $name ] = array(
		'args'     => $args,
		'expected' => $tool->execute( $args ),
	);
}

$target = WP_MCP_AI_PATH . 'tests/fixtures/harness/prompt-cues.json';

if ( ! is_dir( dirname( $target ) ) ) {
	mkdir( dirname( $target ), 0777, true );
}

$json = json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

file_put_contents( $target, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writer.

printf( "Wrote %d cases to %s\n", count( $out ), $target );
