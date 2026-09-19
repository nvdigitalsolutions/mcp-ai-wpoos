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
 *   php bin/generate-ollama-blueprint.php --bundle-url=<url>   # override ZIP discovery
 *
 * The generated file is committed; re-run after editing the seed snippet.
 *
 * @package WP_MCP_AI
 */

declare(strict_types=1);

/**
 * Resolve the Complete bundle ZIP URL for the blueprint's installPlugin step.
 *
 * Globs build/nvdigital-open-operator-system-oos-complete-*.zip and picks the
 * highest version so the demo always installs the newest Complete bundle
 * without a manual pin bump. The build-assets workflow calls the generator
 * right after rebuilding the ZIPs, keeping the committed JSON current.
 *
 * @param string $root Repo root directory.
 * @param array  $argv CLI arguments (supports --bundle-url=<url>).
 * @return string Raw bundle URL.
 */
function nvoos_ollama_resolve_bundle_url( string $root, array $argv ): string {
	foreach ( $argv as $arg ) {
		if ( 0 === strpos( $arg, '--bundle-url=' ) ) {
			return substr( $arg, strlen( '--bundle-url=' ) );
		}
	}

	$prefix = 'nvdigital-open-operator-system-oos-complete-';
	$latest = null;

	foreach ( glob( $root . '/build/' . $prefix . '*.zip' ) ?: array() as $file ) {
		$version = substr( basename( $file ), strlen( $prefix ), -4 );
		if ( null === $latest || version_compare( $version, $latest, '>' ) ) {
			$latest = $version;
		}
	}

	if ( null === $latest ) {
		fwrite( STDERR, "No Complete bundle ZIP found in build/ - run bin/rebuild-all-zips.sh first.\n" );
		exit( 1 );
	}

	printf( "Resolved bundle version: %s\n", $latest );

	return 'https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/alpha-working/build/'
		. $prefix . $latest . '.zip';
}

$root       = dirname( __DIR__ );
$seedSrc    = $root . '/blueprints/ollama-demo.php';
$outPath    = $root . '/blueprints/ollama-demo.json';
$bundleUrl  = nvoos_ollama_resolve_bundle_url( $root, $argv );

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
//
// ARCHITECTURE: the shortcode renders a placeholder DIV ONLY. The checker
// script is a proper footer script (wp_enqueue_scripts + wp_add_inline_script).
// Never inline the JS in the shortcode output: the_content's wptexturize /
// entity pass rewrites `&&` into `&#038;&#038;` — a JavaScript syntax error
// that silently kills the banner (observed on the real demo page; the
// initial "Checking..." text stays frozen forever). Footer scripts print
// via wp_footer, outside the content pipeline, so no filter can touch them.
$muPluginCode = <<<'PHP'
<?php
/**
 * Demo mu-plugin for the NV oOS Playground blueprint.
 * Adds [ollama_status] — a live banner showing whether this browser can
 * reach the user's local Ollama (http://localhost:11434). The check is an
 * async fetch with a hard timeout, so PHP never blocks on it. The shortcode
 * outputs a placeholder div; the checker script is enqueued in the footer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nvoos_ollama_status_shortcode' ) ) {
	function nvoos_ollama_status_shortcode() {
		$base = 'border:1px solid #c3c4c7;background:#f6f7f7;border-radius:6px;padding:12px 16px;margin:0 0 24px;';
		return '<div class="nvoos-ollama-status" data-nvoos-ollama-status="pending" style="' . esc_attr( $base ) . '">Checking your local Ollama...</div>' . "\n";
	}
}

add_shortcode( 'ollama_status', 'nvoos_ollama_status_shortcode' );

/**
 * Enqueue the client-side checker in the footer.
 *
 * @return void
 */
function nvoos_ollama_status_enqueue_checker() {
	$handle = 'nvoos-ollama-status-checker';
	wp_register_script( $handle, false, array(), '1.1.0', true );
	wp_enqueue_script( $handle );

	// Pure ASCII JS. Runs outside the_content (footer), so wptexturize can
	// never rewrite the operators.
	$js = '(function(){' . "\n"
		. 'if(typeof document==="undefined"){return;}' . "\n"
		. 'var base="border:1px solid #c3c4c7;background:#f6f7f7;border-radius:6px;padding:12px 16px;margin:0 0 24px;";' . "\n"
		. 'function paint(el,extra,html){el.setAttribute("style",base+extra);el.innerHTML=html;}' . "\n"
		. 'function esc(s){return String(s).replace(/[<>&]/g,function(c){return c==="<"?"&lt;":c===">"?"&gt;":"&amp;";});}' . "\n"
		. 'function check(el){' . "\n"
		. 'var ctrl=(typeof AbortController!=="undefined")?new AbortController():null;' . "\n"
		. 'var timer=setTimeout(function(){if(ctrl){ctrl.abort();}paint(el,"background:#fff8e5;border-color:#ffb900;","<strong>\u26a0 Ollama not detected.</strong> The request timed out \u2014 is Ollama running? Check the setup steps below, then refresh.");},4000);' . "\n"
		. 'fetch("http://localhost:11434/api/tags",{signal:ctrl?ctrl.signal:undefined}).then(function(r){if(!r.ok){throw new Error("HTTP "+r.status);}return r.json();}).then(function(d){' . "\n"
		. 'clearTimeout(timer);var names=[];if(d&&Array.isArray(d.models)){for(var i=0;i<Math.min(d.models.length,8);i++){if(d.models[i]&&d.models[i].name){names.push(esc(d.models[i].name));}}}' . "\n"
		. 'paint(el,"background:#edfaef;border-color:#46b450;","<strong>\u2705 Ollama connected!</strong> This browser can reach your local Ollama."+(names.length?" Models: <code>"+names.join("</code>, <code>")+"</code>.":"")+" The chat below answers on your machine \u2014 nothing leaves it.");' . "\n"
		. '}).catch(function(err){clearTimeout(timer);var msg=err&&err.message?err.message:String(err);' . "\n"
		. 'if(msg==="Failed to fetch"){paint(el,"background:#fcf0f1;border-color:#dc3232;","<strong>\u274c The browser blocked the localhost request.</strong> Private Network Access / CORS \u2014 see the browser notes below.");}' . "\n"
		. 'else if(msg==="AbortError"||msg.indexOf("abort")===0){paint(el,"background:#fff8e5;border-color:#ffb900;","<strong>\u26a0 Ollama not detected.</strong> The request timed out \u2014 is Ollama running?");}' . "\n"
		. 'else{paint(el,"background:#fcf0f1;border-color:#dc3232;","<strong>\u274c Could not reach Ollama.</strong> "+esc(msg));}' . "\n"
		. '});}' . "\n"
		. 'function boot(){var els=document.querySelectorAll(".nvoos-ollama-status");for(var i=0;i<els.length;i++){if(els[i].getAttribute("data-nvoos-ollama-status")==="pending"){els[i].setAttribute("data-nvoos-ollama-status","checking");check(els[i]);}}}' . "\n"
		. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",boot);}else{boot();}' . "\n"
		. '})();';

	wp_add_inline_script( $handle, $js );
}

add_action( 'wp_enqueue_scripts', 'nvoos_ollama_status_enqueue_checker' );
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
