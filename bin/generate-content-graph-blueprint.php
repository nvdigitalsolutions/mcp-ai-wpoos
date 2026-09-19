<?php
/**
 * Generate the WordPress Playground blueprints for the NV oOS Content Graph
 * "Project Asteria" demo.
 *
 * Reads plugins/nvoos-content-graph/blueprints/seed-content.php and emits:
 *
 *   1. plugins/nvoos-content-graph/.wordpress-org/blueprints/blueprint.json
 *      The wp.org Live Preview blueprint. The plugin is pre-installed by the
 *      preview loader, so this file intentionally does NOT install it.
 *      Mirrors the SVN path assets/blueprints/blueprint.json.
 *
 *   2. plugins/nvoos-content-graph/blueprints/demo.json
 *      The standalone demo blueprint for shareable links. Includes the
 *      installPlugin step (newest built ZIP from the repo) so the link is
 *      self-contained and always current.
 *
 * Usage:
 *   php bin/generate-content-graph-blueprint.php
 *   php bin/generate-content-graph-blueprint.php --plugin-url=<url>   # override ZIP discovery
 *
 * The generated files are committed, so this script is optional tooling for
 * content updates — not a build dependency.
 *
 * @package NvoosContentGraph
 */

declare(strict_types=1);

/**
 * Resolve the plugin ZIP URL for the standalone demo's installPlugin step.
 *
 * Globs build/nvoos-content-graph-v*.zip and picks the highest version so
 * the demo always installs the newest Content Graph build instead of
 * whatever wordpress.org currently serves. The build-assets workflow calls
 * the generator right after rebuilding the ZIPs, keeping the committed
 * demo.json current.
 *
 * @param string $root Repo root directory.
 * @param array  $argv CLI arguments (supports --plugin-url=<url>).
 * @return string Raw plugin ZIP URL.
 */
function nvoos_cg_resolve_plugin_url( string $root, array $argv ): string {
	foreach ( $argv as $arg ) {
		if ( 0 === strpos( $arg, '--plugin-url=' ) ) {
			return substr( $arg, strlen( '--plugin-url=' ) );
		}
	}

	$prefix = 'nvoos-content-graph-v';
	$latest = null;

	foreach ( glob( $root . '/build/' . $prefix . '*.zip' ) ?: array() as $file ) {
		$version = substr( basename( $file ), strlen( $prefix ), -4 );
		if ( null === $latest || version_compare( $version, $latest, '>' ) ) {
			$latest = $version;
		}
	}

	if ( null === $latest ) {
		fwrite( STDERR, "No Content Graph ZIP found in build/ - run bin/rebuild-all-zips.sh first.\n" );
		exit( 1 );
	}

	printf( "Resolved plugin version: %s\n", $latest );

	return 'https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/alpha-working/build/'
		. $prefix . $latest . '.zip';
}

$root    = dirname( __DIR__ );
$seedSrc = $root . '/plugins/nvoos-content-graph/blueprints/seed-content.php';
$previewOut = $root . '/plugins/nvoos-content-graph/.wordpress-org/blueprints/blueprint.json';
$demoOut    = $root . '/plugins/nvoos-content-graph/blueprints/demo.json';

if ( ! is_file( $seedSrc ) ) {
	fwrite( STDERR, "Missing seed file: {$seedSrc}\n" );
	exit( 1 );
}

$seedCode = file_get_contents( $seedSrc );
if ( false === $seedCode ) {
	fwrite( STDERR, "Could not read seed file: {$seedSrc}\n" );
	exit( 1 );
}

// Strip the opening <?php tag so the snippet can be embedded mid-file.
$seedCode = preg_replace( '/^<\?php\s*/', '', $seedCode, 1 );
if ( null === $seedCode ) {
	fwrite( STDERR, "Could not strip the opening PHP tag from the seed file.\n" );
	exit( 1 );
}
$seedCode = trim( $seedCode );

if ( '' === $seedCode ) {
	fwrite( STDERR, "Seed file is empty after stripping the PHP tag.\n" );
	exit( 1 );
}

// ─── runPHP step: seed the demo content ─────────────────────────
$seedStep = array(
	'step' => 'runPHP',
	'code' => "<?php require_once '/wordpress/wp-load.php';\n" . $seedCode . "\nnvoos_cg_demo_seed();",
);

// ─── runPHP step: configure and build the graph ─────────────────
$buildCode = <<<'PHP'
<?php require_once '/wordpress/wp-load.php';
$settings = get_option( 'nvoos_content_graph_settings', array() );
if ( ! is_array( $settings ) ) {
	$settings = array();
}
// Re-enable live graph updates now that the bulk seed is done.
$settings['auto_rebuild'] = 1;
update_option( 'nvoos_content_graph_settings', $settings );

// Make the Asteria page the front page so / shows the [nvoos_graph] embed.
$home = get_page_by_path( 'asteria', OBJECT, 'page' );
if ( $home instanceof WP_Post ) {
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', (int) $home->ID );
}

// Deterministic full build via the plugin's public hook (the activation
// one-shot cron is non-deterministic inside the Playground runtime).
do_action( 'nvoos_content_graph/initial_build' );
PHP;

$buildStep = array(
	'step' => 'runPHP',
	'code' => $buildCode,
);

// ─── Shared steps ───────────────────────────────────────────────
$loginStep = array(
	'step'     => 'login',
	'username' => 'admin',
	'password' => 'password',
);

$optionsStep = array(
	'step'    => 'setSiteOptions',
	'options' => array(
		'blogname'        => 'Project Asteria — Content Graph Demo',
		'blogdescription' => 'A fictional sci-fi universe mapped into a knowledge graph by NV oOS Content Graph.',
	),
);

$installStep = array(
	'step'       => 'installPlugin',
	'pluginData' => array(
		'resource' => 'url',
		'url'      => nvoos_cg_resolve_plugin_url( $root, $argv ),
	),
	'options'    => array(
		'activate' => true,
	),
);

// ─── Blueprint metadata ─────────────────────────────────────────
$meta = array(
	'$schema'            => 'https://playground.wordpress.net/blueprint-schema.json',
	'landingPage'        => '/wp-admin/admin.php?page=nvoos-content-graph',
	'preferredVersions'  => array(
		'php' => '8.3',
		'wp'  => 'latest',
	),
	'phpExtensionBundles' => array( 'kitchen-sink' ),
	'features'           => array(
		'networking' => true,
	),
);

$previewBlueprint = array_merge(
	$meta,
	array(
		'steps' => array( $loginStep, $optionsStep, $seedStep, $buildStep ),
	)
);

$demoBlueprint = array_merge(
	$meta,
	array(
		'steps' => array( $loginStep, $installStep, $optionsStep, $seedStep, $buildStep ),
	)
);

// ─── Write + validate ───────────────────────────────────────────
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

$targets = array(
	$previewOut => $previewBlueprint,
	$demoOut    => $demoBlueprint,
);

foreach ( $targets as $path => $blueprint ) {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
		fwrite( STDERR, "Could not create directory: {$dir}\n" );
		exit( 1 );
	}

	$json = json_encode( $blueprint, $flags );
	if ( false === $json ) {
		fwrite( STDERR, 'json_encode failed: ' . json_last_error_msg() . "\n" );
		exit( 1 );
	}

	file_put_contents( $path, $json . "\n" );

	// Round-trip validation.
	$decoded = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $decoded ) || ! isset( $decoded['steps'] ) ) {
		fwrite( STDERR, "Validation failed for generated file: {$path}\n" );
		exit( 1 );
	}

	printf(
		"Generated %s (%d steps, %.1f KB)\n",
		$path,
		count( $decoded['steps'] ),
		strlen( $json ) / 1024
	);
}

echo "Done.\n";
