<?php
/**
 * Generate the "NV oOS × Ollama" WordPress Playground blueprint.
 *
 * Reads blueprints/ollama-demo.php and emits blueprints/ollama-demo.json:
 *
 *   login → installPlugin (NV oOS Complete bundle from the repo's raw ZIP)
 *         → writeFile (mu-plugin adding the [ollama_status] shortcode)
 *         → runPHP (configure Ollama provider + demo assistant + Test Lab page)
 *         → land on /ollama-test-lab/
 *
 * Inside Playground, WordPress runs in the user's browser, so the plugin's
 * http://localhost:11434 endpoint IS the user's machine — the blueprint
 * pre-wires everything so a running local Ollama answers the chat
 * immediately. See blueprints/README.md for the CORS / browser caveats.
 *
 * Usage:
 *   php bin/generate-ollama-blueprint.php
 *
 * The generated file is committed; re-run after editing the seed snippet.
 *
 * @package WP_MCP_AI
 */

declare(strict_types=1);

$root       = dirname( __DIR__ );
$seedSrc    = $root . '/blueprints/ollama-demo.php';
$outPath    = $root . '/blueprints/ollama-demo.json';
$bundleUrl  = 'https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/alpha-working/build/nvdigital-open-operator-system-oos-complete-1.1.81.zip';

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

// ─── Mu-plugin: [ollama_status] live connectivity banner ──────────
// Written into wp-content/mu-plugins by the blueprint so the Test Lab
// page can self-diagnose whether the browser↔Ollama path is open.
$muPluginCode = <<<'PHP'
<?php
/**
 * Demo mu-plugin for the NV oOS Playground blueprint.
 * Adds [ollama_status] — a live banner showing whether this Playground
 * instance can reach the user's local Ollama (http://localhost:11434).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nvoos_ollama_status_shortcode' ) ) {
	function nvoos_ollama_status_shortcode() {
		$cached = get_transient( 'nvoos_ollama_status_banner' );
		if ( false !== $cached && is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$res = wp_remote_get( 'http://localhost:11434/api/tags', array( 'timeout' => 8 ) );

		$styles = array(
			'ok'   => 'background:#edfaef;border:1px solid #46b450;border-radius:6px;padding:12px 16px;margin:0 0 24px;',
			'warn' => 'background:#fff8e5;border:1px solid #ffb900;border-radius:6px;padding:12px 16px;margin:0 0 24px;',
			'bad'  => 'background:#fcf0f1;border:1px solid #dc3232;border-radius:6px;padding:12px 16px;margin:0 0 24px;',
		);

		if ( is_wp_error( $res ) ) {
			$html = '<div style="' . $styles['warn'] . '"><strong>⚠ Ollama not detected.</strong> The browser could not reach <code>http://localhost:11434</code> — start Ollama (and check the browser notes below), then refresh. Error: ' . esc_html( $res->get_error_message() ) . '</div>';
		} else {
			$status = (int) wp_remote_retrieve_response_code( $res );
			if ( 200 === $status ) {
				$body    = wp_remote_retrieve_body( $res );
				$decoded = json_decode( (string) $body, true );
				$names   = array();
				if ( is_array( $decoded ) && isset( $decoded['models'] ) && is_array( $decoded['models'] ) ) {
					foreach ( array_slice( $decoded['models'], 0, 8 ) as $model ) {
						if ( isset( $model['name'] ) ) {
							$names[] = esc_html( (string) $model['name'] );
						}
					}
				}
				$models = $names ? ' Models: <code>' . implode( '</code>, <code>', $names ) . '</code>.' : '';
				$html   = '<div style="' . $styles['ok'] . '"><strong>✅ Ollama connected!</strong> This browser can reach your local Ollama.' . $models . ' The chat below answers on your machine — nothing leaves it.</div>';
			} else {
				$html = '<div style="' . $styles['bad'] . '"><strong>❌ Ollama rejected this origin (HTTP ' . absint( $status ) . ').</strong> Set <code>OLLAMA_ORIGINS</code> to include <code>https://playground.wordpress.net</code> (see the setup steps below), restart Ollama, and refresh.</div>';
			}
		}

		set_transient( 'nvoos_ollama_status_banner', $html, 30 );
		return $html;
	}
}

add_shortcode( 'ollama_status', 'nvoos_ollama_status_shortcode' );
PHP;

// ─── Steps ────────────────────────────────────────────────────────
$loginStep = array(
	'step'     => 'login',
	'username' => 'admin',
	'password' => 'password',
);

$installStep = array(
	'step'       => 'installPlugin',
	'pluginData' => array(
		'resource' => 'url',
		'url'      => $bundleUrl,
	),
	'options'    => array(
		'activate' => true,
	),
);

$muPluginStep = array(
	'step' => 'writeFile',
	'path' => '/wordpress/wp-content/mu-plugins/ollama-status.php',
	'data' => $muPluginCode,
);

$seedStep = array(
	'step' => 'runPHP',
	'code' => "<?php require_once '/wordpress/wp-load.php';\n" . $seedCode . "\nnvoos_ollama_demo_seed();",
);

$blueprint = array(
	'$schema'            => 'https://playground.wordpress.net/blueprint-schema.json',
	'landingPage'        => '/ollama-test-lab/',
	'preferredVersions'  => array(
		'php' => '8.3',
		'wp'  => 'latest',
	),
	'phpExtensionBundles' => array( 'kitchen-sink' ),
	'features'           => array(
		'networking' => true,
	),
	'steps'              => array( $loginStep, $installStep, $muPluginStep, $seedStep ),
);

$dir = dirname( $outPath );
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
	fwrite( STDERR, "Could not create directory: {$dir}\n" );
	exit( 1 );
}

$json = json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( false === $json ) {
	fwrite( STDERR, 'json_encode failed: ' . json_last_error_msg() . "\n" );
	exit( 1 );
}

file_put_contents( $outPath, $json . "\n" );

// Round-trip validation.
$decoded = json_decode( (string) file_get_contents( $outPath ), true, 512, JSON_THROW_ON_ERROR );
if ( ! is_array( $decoded ) || ! isset( $decoded['steps'] ) ) {
	fwrite( STDERR, "Validation failed for generated file: {$outPath}\n" );
	exit( 1 );
}

printf( "Generated %s (%d steps, %.1f KB)\n", $outPath, count( $decoded['steps'] ), strlen( $json ) / 1024 );
echo "Done.\n";
