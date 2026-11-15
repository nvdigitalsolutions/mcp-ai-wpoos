<?php
/**
 * Regenerates tests/fixtures/harness/scope-memory.json from WordPress.
 *
 * Run inside the WordPress container:
 *
 *   docker exec -w /var/www/html/wp-content/plugins/mcp-ai-wpoos oos-wp \
 *     php tests/manual/capture-scope-memory.php
 *
 * The tool's only external dependency is the resolved harness profile, so the
 * corpus records `(arguments, profile) → response` and both runtimes replay it:
 * WordPress injects the profile through the `wp_mcp_ai_harness_profile` filter,
 * core injects it through a provider stub. That isolates the logic being ported
 * from the storage that resolves it.
 *
 * The single "no profile" case runs with no filter *and* with the global option
 * cleared, so it records the documented `Harness_Profile::defaults()` path.
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

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-schema-helper.php';
require_once WP_MCP_AI_PATH . 'includes/harness/class-wp-mcp-ai-harness-profile.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-scope-memory.php';

$tool = new WP_MCP_AI_Tool_Scope_Memory();

/**
 * A profile that differs from the defaults only where a case cares about it.
 *
 * @param array $memory Memory slice overrides.
 * @return array
 */
$profile = static function ( array $memory = array(), bool $enabled = true ): array {
	$out            = WP_MCP_AI_Harness_Profile::defaults();
	$out['enabled'] = $enabled;

	return array_merge( $out, array( 'memory' => array_merge( $out['memory'], $memory ) ) );
};

$cases = array(
	// No filter, no stored profile: the documented defaults apply.
	'no-profile-defaults'        => array(
		'args'    => array(),
		'profile' => null,
	),

	// Tag assembly.
	'assistant-and-task'         => array(
		'args'    => array(
			'assistant_id' => 42,
			'task_class'   => 'math',
		),
		'profile' => null,
	),
	'assistant-zero-omits-tag'   => array(
		'args'    => array(
			'assistant_id' => 0,
			'task_class'   => 'code',
		),
		'profile' => null,
	),
	'assistant-negative-omits-tag' => array(
		'args'    => array(
			'assistant_id' => -5,
			'task_class'   => 'qa',
		),
		'profile' => null,
	),
	'wing-tag'                   => array(
		'args'    => array(
			'assistant_id' => 7,
			'task_class'   => 'research',
			'wing'         => 'client-acme',
		),
		'profile' => null,
	),
	'wing-sanitised'             => array(
		'args'    => array(
			'task_class' => 'rag',
			'wing'       => "  <b>Alpha</b>\n\n  Beta  ",
		),
		'profile' => null,
	),

	// task_class sanitisation and fallbacks.
	'task-missing'               => array(
		'args'    => array( 'assistant_id' => 1 ),
		'profile' => null,
	),
	'task-empty'                 => array(
		'args'    => array( 'task_class' => '' ),
		'profile' => null,
	),
	'task-upper-and-spaces'      => array(
		'args'    => array( 'task_class' => 'Math Reasoning' ),
		'profile' => null,
	),
	'task-punctuation'           => array(
		'args'    => array( 'task_class' => 'math!@#$%^&*()' ),
		'profile' => null,
	),
	'task-non-ascii'             => array(
		'args'    => array( 'task_class' => 'Māth' ),
		'profile' => null,
	),
	'task-custom-bucket'         => array(
		'args'    => array( 'task_class' => 'my-own-bucket' ),
		'profile' => null,
	),

	// pii_filter resolution.
	'pii-default-on'             => array(
		'args'    => array( 'assistant_id' => 9 ),
		'profile' => $profile(),
	),
	'pii-explicitly-off'         => array(
		'args'    => array( 'assistant_id' => 9 ),
		'profile' => $profile( array( 'pii_filter' => false ) ),
	),
	'pii-explicitly-on'          => array(
		'args'    => array( 'assistant_id' => 9 ),
		'profile' => $profile( array( 'pii_filter' => true ) ),
	),
	'pii-memory-key-missing'     => array(
		'args'    => array( 'assistant_id' => 9 ),
		'profile' => array( 'enabled' => true ),
	),
	'pii-profile-empty'          => array(
		'args'    => array( 'assistant_id' => 9 ),
		'profile' => array(),
	),
	'pii-harness-disabled'       => array(
		'args'    => array( 'assistant_id' => 9 ),
		'profile' => $profile( array( 'pii_filter' => false ), false ),
	),
);

// Every reserved bucket, so the flag matrix is recorded rather than inferred.
foreach ( WP_MCP_AI_Tool_Scope_Memory::RESERVED_BUCKETS as $bucket ) {
	$cases[ 'reserved-' . $bucket ] = array(
		'args'    => array( 'task_class' => $bucket ),
		'profile' => null,
	);
}

$option_snapshot = get_option( 'wp_mcp_ai_harness_profile_default', '' );
delete_option( 'wp_mcp_ai_harness_profile_default' );

$out = array();

foreach ( $cases as $name => $case ) {
	$case_profile = $case['profile'];
	$assistant_id = isset( $case['args']['assistant_id'] ) ? (int) $case['args']['assistant_id'] : 0;

	// A null profile means "let the real resolution run"; anything else is
	// injected at the documented filter seam so storage never interferes.
	$filter = null;

	if ( null !== $case_profile ) {
		$filter = static function ( $resolved ) use ( $case_profile ) {
			unset( $resolved );

			return $case_profile;
		};

		add_filter( 'wp_mcp_ai_harness_profile', $filter, 10, 1 );
	}

	$result = $tool->execute( $case['args'] );

	// What the tool actually saw, recorded so the core replay is honest.
	$resolved = WP_MCP_AI_Harness_Profile::get( $assistant_id );

	if ( null !== $filter ) {
		remove_filter( 'wp_mcp_ai_harness_profile', $filter, 10 );
	}

	$out[ $name ] = array(
		'args'     => $case['args'],
		'profile'  => $case_profile,
		'resolved' => $resolved,
		'expected' => $result,
	);
}

update_option( 'wp_mcp_ai_harness_profile_default', $option_snapshot );

$target = WP_MCP_AI_PATH . 'tests/fixtures/harness/scope-memory.json';

if ( ! is_dir( dirname( $target ) ) ) {
	mkdir( dirname( $target ), 0777, true );
}

$json = json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

file_put_contents( $target, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writer.

printf( "Wrote %d cases to %s\n", count( $out ), $target );
