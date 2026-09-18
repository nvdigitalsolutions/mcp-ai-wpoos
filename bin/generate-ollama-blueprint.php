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
//
// IMPORTANT: the check runs CLIENT-SIDE (async fetch + AbortController
// timeout). A synchronous PHP-side wp_remote_get() to localhost would
// block the Playground worker when the browser's Private Network Access
// policy hangs the request — the worker then times out, re-preloads the
// SQLite integration, fatals on the duplicate class, and takes the whole
// Playground instance down. Never fetch localhost from PHP render paths.
$muPluginCode = <<<'PHP'
<?php
/**
 * Demo mu-plugin for the NV oOS Playground blueprint.
 * Adds [ollama_status] — a live banner showing whether this browser can
 * reach the user's local Ollama (http://localhost:11434). The check is
 * an async fetch with a hard timeout, so PHP never blocks on it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nvoos_ollama_status_shortcode' ) ) {
	function nvoos_ollama_status_shortcode() {
		$id   = 'nvoos-ollama-status-' . wp_rand( 100000, 999999 );
		$base = 'border:1px solid #c3c4c7;background:#f6f7f7;border-radius:6px;padding:12px 16px;margin:0 0 24px;';
		$html = '<div id="' . esc_attr( $id ) . '" style="' . esc_attr( $base ) . '">Checking your local Ollama...</div>' . "\n";
		// NOTE: the entire mu-plugin output is deliberately pure ASCII.
		// Multi-byte UTF-8 anywhere in the rendered page invites
		// "Invalid or unexpected token" errors when Playground's worker
		// truncates a streamed response mid-byte-sequence.
		$html .= '<script>(function(){' . "\n"
			. 'var el=document.getElementById(' . wp_json_encode( $id ) . ');if(!el){return;}' . "\n"
			. 'var base=' . wp_json_encode( $base ) . ';' . "\n"
			. 'function paint(extra,html){el.setAttribute("style",base+extra);el.innerHTML=html;}' . "\n"
			. 'function esc(s){return String(s).replace(/[<>&]/g,function(c){return c==="<"?"&lt;":c===">"?"&gt;":"&amp;";});}' . "\n"
			. 'var ctrl=(typeof AbortController!=="undefined")?new AbortController():null;' . "\n"
			. 'var timer=setTimeout(function(){if(ctrl){ctrl.abort();}paint("background:#fff8e5;border-color:#ffb900;","<strong>\u26a0 Ollama not detected.</strong> The request timed out \u2014 is Ollama running? Check the setup steps below, then refresh.");},4000);' . "\n"
			. 'fetch("http://localhost:11434/api/tags",{signal:ctrl?ctrl.signal:undefined}).then(function(r){if(!r.ok){throw new Error("HTTP "+r.status);}return r.json();}).then(function(d){' . "\n"
			. 'clearTimeout(timer);var names=[];if(d&&Array.isArray(d.models)){for(var i=0;i<Math.min(d.models.length,8);i++){if(d.models[i]&&d.models[i].name){names.push(esc(d.models[i].name));}}}' . "\n"
			. 'paint("background:#edfaef;border-color:#46b450;","<strong>\u2705 Ollama connected!</strong> This browser can reach your local Ollama."+(names.length?" Models: <code>"+names.join("</code>, <code>")+"</code>.":"")+" The chat below answers on your machine \u2014 nothing leaves it.");' . "\n"
			. '}).catch(function(err){clearTimeout(timer);var msg=err&&err.message?err.message:String(err);' . "\n"
			. 'if(msg==="Failed to fetch"){paint("background:#fcf0f1;border-color:#dc3232;","<strong>\u274c The browser blocked the localhost request.</strong> Private Network Access / CORS \u2014 see the browser notes below.");}' . "\n"
			. 'else if(msg==="AbortError"||msg.indexOf("abort")===0){paint("background:#fff8e5;border-color:#ffb900;","<strong>\u26a0 Ollama not detected.</strong> The request timed out \u2014 is Ollama running?");}' . "\n"
			. 'else{paint("background:#fcf0f1;border-color:#dc3232;","<strong>\u274c Could not reach Ollama.</strong> "+esc(msg));}' . "\n"
			. '});' . "\n"
			. '})();</script>' . "\n";
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
