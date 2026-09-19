<?php
/**
 * Build a probe variant of the Ollama demo blueprint: same steps, plus a
 * final runPHP step that renders the Test Lab page and writes the HTML and
 * a diagnostic report into /verify-out (mounted from ./verify-out).
 *
 * Usage:
 *   php bin/make-probe-blueprint.php
 *   MSYS_NO_PATHCONV=1 npx -y @wp-playground/cli@3.1.54 run-blueprint \
 *     --blueprint=blueprints/ollama-demo.probe.json \
 *     --mount-before-install=<abs>/verify-out:/verify-out
 *
 * @package WP_MCP_AI
 */

declare( strict_types=1 );

$root       = dirname( __DIR__ );
$basePath   = $root . '/blueprints/ollama-demo.json';
$snippetSrc = $root . '/bin/probe-render-snippet.php';
$outPath    = $root . '/blueprints/ollama-demo.probe.json';

if ( ! is_file( $basePath ) ) {
	fwrite( STDERR, "Missing base blueprint: {$basePath}\nRun: curl the raw URL into blueprints/served-ollama-demo.json\n" );
	exit( 1 );
}

$base = json_decode( (string) file_get_contents( $basePath ), true, 512, JSON_THROW_ON_ERROR );

$code = (string) file_get_contents( $snippetSrc );
$code = preg_replace( '/^<\?php\s*/', '', $code, 1 );
$code = trim( $code );

$probeStep = array(
	'step' => 'runPHP',
	'code' => "<?php require_once '/wordpress/wp-load.php';\n" . $code,
);

$base['steps'][] = $probeStep;
unset( $base['landingPage'] );

file_put_contents(
	$outPath,
	json_encode( $base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
);

echo 'Wrote ' . $outPath . "\n";
