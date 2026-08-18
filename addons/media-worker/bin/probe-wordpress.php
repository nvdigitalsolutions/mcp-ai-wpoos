<?php
/**
 * Media Worker routing probe — verify that the site is really using the
 * connected Media Worker (https://worker.nvoos.cloud) instead of running the
 * plugin's bundled JS locally via Node.js.
 *
 * Run on the WordPress host:
 *   php probe-wordpress.php
 *
 * Options:
 *   --wp-root=/path/to/wordpress   Explicit WordPress root (also WP_PROBE_WP_ROOT env).
 *   --help                         This help text.
 *
 * What this proves (and the admin page can't):
 *   - The Settings → Media Worker "Test Connection" button only hits the
 *     PUBLIC /api/health endpoint WITHOUT the token. It proves reachability,
 *     not authentication. A rejected token makes every sidecar call fail and
 *     the plugin silently falls back to local Node.js — this probe performs
 *     the authenticated call the plugin itself sends.
 *   - It runs real service-class calls (Prettier, MJML) and reports whether
 *     local Node.js exists on this host. Local node absent + service calls
 *     succeeding = the worker did the work. That is the only possible path.
 *
 * Exit code: 0 = worker confirmed, 1 = problem (see verdict).
 *
 * WARNING: delete this file from any public-facing site after use. It is
 * CLI-only, but it must never be reachable via a web server.
 *
 * @package WP_MCP_AI
 * @since 1.1.55
 */

// phpcs:disable -- CLI diagnostic script; excluded from WPCS via phpcs.xml.dist (*/bin/*).

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "CLI only. Run: php probe-wordpress.php\n" );
	exit( 1 );
}

/**
 * Print a help block and exit.
 */
function probe_help() {
	echo <<<HELP
Media Worker routing probe — proves whether the site uses the connected
worker (https://worker.nvoos.cloud) instead of local bundled JS.

Usage:
  php probe-wordpress.php [--wp-root=/path/to/wordpress]

Environment:
  WP_PROBE_WP_ROOT   Explicit WordPress root (same as --wp-root=).

Exit codes:
  0  Worker confirmed (authenticated calls succeed).
  1  Problem detected (see verdict section).

HELP;
	exit( 0 );
}

foreach ( $argv as $arg ) {
	if ( '--help' === $arg || '-h' === $arg ) {
		probe_help();
	}
}

// ── Locate wp-load.php ────────────────────────────────────────────────
$wp_root = getenv( 'WP_PROBE_WP_ROOT' );
if ( ! $wp_root ) {
	foreach ( $argv as $arg ) {
		if ( 0 === strpos( $arg, '--wp-root=' ) ) {
			$wp_root = substr( $arg, strlen( '--wp-root=' ) );
		}
	}
}

$wp_load = null;
if ( $wp_root ) {
	$candidate = rtrim( $wp_root, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-load.php';
	if ( is_file( $candidate ) ) {
		$wp_load = $candidate;
	}
}
if ( ! $wp_load ) {
	// Walk up from this script (plugin tree) towards the site root.
	$dir = __DIR__;
	for ( $i = 0; $i < 10; $i++ ) {
		$candidate = $dir . DIRECTORY_SEPARATOR . 'wp-load.php';
		if ( is_file( $candidate ) ) {
			$wp_load = $candidate;
			break;
		}
		$parent = dirname( $dir );
		if ( $parent === $dir ) {
			break;
		}
		$dir = $parent;
	}
}
if ( ! $wp_load ) {
	// Common Docker layout fallback.
	if ( is_file( '/var/www/html/wp-load.php' ) ) {
		$wp_load = '/var/www/html/wp-load.php';
	}
}
if ( ! $wp_load ) {
	fwrite( STDERR, "ERROR: could not locate wp-load.php.\n" );
	fwrite( STDERR, "Run with: php probe-wordpress.php --wp-root=/path/to/wordpress\n" );
	exit( 1 );
}

require_once $wp_load;

// ── Tiny output helpers ───────────────────────────────────────────────
function probe_out( $text = '' ) {
	echo $text . "\n";
}

function probe_section( $title ) {
	probe_out();
	probe_out( '[' . $title . ']' );
}

function probe_result( $label, $ok, $detail = '' ) {
	$mark = $ok ? '[PASS]' : '[FAIL]';
	probe_out( '  ' . str_pad( $label . ' ', 46 ) . $mark . ( $detail ? ' — ' . $detail : '' ) );
}

function probe_warn( $label, $detail = '' ) {
	probe_out( '  ' . str_pad( $label . ' ', 46 ) . '[WARN]' . ( $detail ? ' — ' . $detail : '' ) );
}

function probe_ms( $start ) {
	return round( ( microtime( true ) - $start ) * 1000 ) . 'ms';
}

function probe_mask( $secret ) {
	if ( '' === $secret || null === $secret ) {
		return '(none)';
	}
	if ( strlen( $secret ) <= 8 ) {
		return '(set, too short to mask safely: ' . strlen( $secret ) . ' chars)';
	}
	return substr( $secret, 0, 4 ) . '...' . substr( $secret, -4 ) . ' (' . strlen( $secret ) . ' chars)';
}

/**
 * HTTP GET returning decoded JSON.
 *
 * @param string $url     Full URL.
 * @param array  $headers Headers.
 * @param int    $timeout Timeout in seconds.
 * @return array{status:int,body:mixed,ms:int,error:string}
 */
function probe_get_json( $url, $headers, $timeout ) {
	$start = microtime( true );
	$r     = wp_remote_get( $url, array( 'timeout' => $timeout, 'headers' => $headers ) );
	$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
	if ( is_wp_error( $r ) ) {
		return array( 'status' => 0, 'body' => null, 'ms' => $ms, 'error' => $r->get_error_message() );
	}
	return array(
		'status' => (int) wp_remote_retrieve_response_code( $r ),
		'body'   => json_decode( wp_remote_retrieve_body( $r ), true ),
		'ms'     => $ms,
		'error'  => '',
	);
}

// ── Banner ────────────────────────────────────────────────────────────
probe_out( str_repeat( '=', 72 ) );
probe_out( ' NV oOS Media Worker — Routing Probe' );
probe_out( str_repeat( '=', 72 ) );
probe_out( ' Site:    ' . home_url() );
probe_out( ' WP:      ' . get_bloginfo( 'version' ) );
if ( defined( 'WP_MCP_AI_VERSION' ) ) {
	probe_out( ' Plugin:  ' . WP_MCP_AI_VERSION . ( defined( 'WP_MCP_AI_PRO_PATH' ) ? ' (Pro active)' : ' (Base only)' ) );
}
probe_out( ' Time:    ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC' );

// ── [1] Configuration (what the trait would use) ──────────────────────
probe_section( '1) CONFIGURATION (source of truth for the trait)' );

$url_source   = 'none';
$token_source = 'none';
$url          = '';
$token        = '';

if ( defined( 'WP_MEDIA_WORKER_URL' ) && WP_MEDIA_WORKER_URL ) {
	$url_source = 'constant WP_MEDIA_WORKER_URL';
	$url        = WP_MEDIA_WORKER_URL;
} else {
	$opt = get_option( 'wp_mcp_ai_media_worker_url', '' );
	if ( $opt ) {
		$url_source = 'option wp_mcp_ai_media_worker_url';
		$url        = $opt;
	}
}

if ( defined( 'WP_MEDIA_WORKER_TOKEN' ) && WP_MEDIA_WORKER_TOKEN ) {
	$token_source = 'constant WP_MEDIA_WORKER_TOKEN';
	$token        = WP_MEDIA_WORKER_TOKEN;
} else {
	$opt = get_option( 'wp_mcp_ai_media_worker_token', '' );
	if ( $opt ) {
		$token_source = 'option wp_mcp_ai_media_worker_token';
		$token        = $opt;
	}
}
if ( '' === $token ) {
	$token_source = 'derived (wp_hash of home_url — WordPress salts)';
	$token        = wp_hash( home_url() );
}

// Optional probe-only overrides (never touch site settings):
//   PROBE_WORKER_URL=...   point every probe check at a different worker
//   PROBE_WORKER_TOKEN=... authenticate with a different token
// Section 6 (service-level calls) always uses the site's own config.
if ( getenv( 'PROBE_WORKER_URL' ) ) {
	$url        = rtrim( getenv( 'PROBE_WORKER_URL' ), '/' );
	$url_source = 'PROBE_WORKER_URL env override (site config untouched)';
}
if ( getenv( 'PROBE_WORKER_TOKEN' ) ) {
	$token        = getenv( 'PROBE_WORKER_TOKEN' );
	$token_source = 'PROBE_WORKER_TOKEN env override';
}

probe_out( '  URL:     ' . ( $url ? $url : '(not configured)' ) . '   [' . $url_source . ']' );
probe_out( '  Token:   ' . probe_mask( $token ) . '   [' . $token_source . ']' );

$sidecar_headers = array(
	'X-Site-Token' => $token,
	'X-Site-Url'   => home_url(),
);

if ( ! $url ) {
	probe_out( str_repeat( '-', 72 ) );
	probe_out( ' VERDICT: NOT CONFIGURED — no worker URL. The plugin runs bundled' );
	probe_out( ' JS locally via Node.js (or returns 501 errors if Node is missing).' );
	probe_out( ' Set the URL + token in Settings → Media Worker.' );
	exit( 1 );
}

// ── [2] Reachability (public health — same check as admin UI) ─────────
probe_section( '2) REACHABILITY (public /api/health — what the admin button tests)' );
$h = probe_get_json( rtrim( $url, '/' ) . '/api/health', array(), 5 );
$reachable = ( 200 === $h['status'] && isset( $h['body']['status'] ) && 'ok' === $h['body']['status'] );
probe_result(
	'GET /api/health',
	$reachable,
	$h['error'] ? $h['error'] : ( $reachable ? 'v' . ( isset( $h['body']['version'] ) ? $h['body']['version'] : '?') . ', ' . $h['ms'] . 'ms' : 'HTTP ' . $h['status'] )
);

// ── [3] Authentication (what the admin button does NOT test) ──────────
// NOTE: /api/health/full only exists on worker v2.4.0+. On older workers it
// 404s — that is NOT a token rejection. The end-to-end call in section 4 is
// the authoritative auth test; this section is informational.
probe_section( '3) AUTHENTICATION (/api/health/full WITH X-Site-Token)' );
$f = probe_get_json( rtrim( $url, '/' ) . '/api/health/full', $sidecar_headers, 10 );
$auth_ok = ( 200 === $f['status'] && isset( $f['body']['status'] ) && 'ok' === $f['body']['status'] );
if ( $auth_ok ) {
	probe_result( 'GET /api/health/full + token', true, 'HTTP ' . $f['status'] . ', ' . $f['ms'] . 'ms' );
} elseif ( 404 === $f['status'] ) {
	probe_warn( 'GET /api/health/full + token', 'HTTP 404 — endpoint absent on this worker version (pre-2.4.0). Not a token rejection; section 4 decides.' );
} else {
	probe_result( 'GET /api/health/full + token', false, $f['error'] ? $f['error'] : 'HTTP ' . $f['status'] );
}

if ( $auth_ok && isset( $f['body']['auth'], $f['body']['tenants'] ) ) {
	probe_out( '  Worker auth:    ' . ( isset( $f['body']['auth']['mode'] ) ? $f['body']['auth']['mode'] : '?' ) );
	probe_out( '  Tenancy:        ' . ( isset( $f['body']['tenants']['mode'] ) ? $f['body']['tenants']['mode'] : '?' ) );
	if ( isset( $f['body']['capabilities'] ) ) {
		$caps = $f['body']['capabilities'];
		$on   = array();
		foreach ( array(
			'code_formatting', 'pdf_generation', 'pdf_extraction', 'document_excel',
			'document_ocr', 'video_processing', 'browser_automation',
			'ai_image_generation', 'social_publishing', 'job_queue',
		) as $c ) {
			$on[] = $c . ': ' . ( ! empty( $caps[ $c ] ) ? 'yes' : 'no' );
		}
		probe_out( '  Capabilities:   ' . implode( ', ', $on ) );
	}
}

// ── [4] End-to-end worker calls (what the plugin actually sends) ─────
probe_section( '4) END-TO-END WORKER CALLS (what the plugin actually sends)' );
$start  = microtime( true );
$r      = wp_remote_post(
	rtrim( $url, '/' ) . '/api/code/format',
	array(
		'timeout' => 20,
		'headers' => array_merge(
			$sidecar_headers,
			array( 'Content-Type' => 'application/json' )
		),
		'body'    => wp_json_encode(
			array(
				'code'    => 'const x=1;',
				'options' => array( 'parser' => 'babel' ),
			)
		),
	)
);
$ms        = (int) round( ( microtime( true ) - $start ) * 1000 );
$e2e_ok    = false;
$e2e_note  = '';
$e2e_status = 0;
if ( is_wp_error( $r ) ) {
	$e2e_note = $r->get_error_message();
} else {
	$e2e_status = (int) wp_remote_retrieve_response_code( $r );
	$body   = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( 200 === $e2e_status && isset( $body['formatted'] ) ) {
		$e2e_ok   = true;
		$e2e_note = $ms . 'ms — formatted: ' . json_encode( $body['formatted'] );
	} else {
		$e2e_note = 'HTTP ' . $e2e_status . ( isset( $body['error'] ) ? ' — ' . $body['error'] : '' );
	}
}
probe_result( 'POST /api/code/format', $e2e_ok, $e2e_note );

// Second end-to-end call: MJML compile. The v2.4.0 worker route returns
// {success, html, errors}; older deployed workers return {success, errors}
// WITHOUT html — in that case the plugin's MJML service cannot use the
// sidecar and this check reports the gap explicitly.
$start = microtime( true );
$r     = wp_remote_post(
	rtrim( $url, '/' ) . '/api/email/compile-mjml',
	array(
		'timeout' => 20,
		'headers' => array_merge(
			$sidecar_headers,
			array( 'Content-Type' => 'application/json' )
		),
		'body'    => wp_json_encode(
			array(
				'mjml'    => '<mjml><mj-body><mj-section><mj-column><mj-text>Hi</mj-text></mj-column></mj-section></mj-body></mjml>',
				'options' => array(),
			)
		),
	)
);
$ms         = (int) round( ( microtime( true ) - $start ) * 1000 );
$mjml_e2e_ok    = false;
$mjml_e2e_note  = '';
if ( is_wp_error( $r ) ) {
	$mjml_e2e_note = $r->get_error_message();
} else {
	$mjml_status = (int) wp_remote_retrieve_response_code( $r );
	$body        = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( 200 === $mjml_status && isset( $body['html'] ) ) {
		$mjml_e2e_ok   = true;
		$mjml_e2e_note = $ms . 'ms — html: ' . strlen( $body['html'] ) . ' bytes';
	} elseif ( 200 === $mjml_status && is_array( $body ) ) {
		$mjml_e2e_note = 'HTTP 200 but NO html key in response (' . $ms . 'ms) — worker email route is an older build; MJML compile cannot use the sidecar until the worker is redeployed.';
	} else {
		$mjml_e2e_note = 'HTTP ' . $mjml_status . ( isset( $body['error'] ) ? ' — ' . $body['error'] : '' );
	}
}
probe_result( 'POST /api/email/compile-mjml', $mjml_e2e_ok, $mjml_e2e_note );

// Video API contract: the plugin's FFmpeg services need the current worker
// build (/api/video/info + /api/video/process ops + /api/video/download).
// These checks need no real video — they fingerprint the build via the
// routes' validation responses.
probe_out();
probe_out( '  -- Video API contract (route fingerprinting, no video needed) --' );
$video_api_ready = true;

// 1. /api/video/info must answer "No file uploaded" to a file-less POST.
$v = wp_remote_post(
	rtrim( $url, '/' ) . '/api/video/info',
	array(
		'timeout' => 10,
		'headers' => $sidecar_headers,
	)
);
$v_status = is_wp_error( $v ) ? 0 : (int) wp_remote_retrieve_response_code( $v );
$v_body   = is_wp_error( $v ) ? null : json_decode( wp_remote_retrieve_body( $v ), true );
$info_ok  = ( 400 === $v_status && isset( $v_body['error'] ) && false !== strpos( $v_body['error'], 'No file uploaded' ) );
probe_result(
	'POST /api/video/info (no file)',
	$info_ok,
	is_wp_error( $v ) ? $v->get_error_message() : 'HTTP ' . $v_status . ( isset( $v_body['error'] ) ? ' — ' . $v_body['error'] : '' )
);
$video_api_ready = $video_api_ready && $info_ok;

// 2. The new /process operations must be accepted (a dummy upload makes
// ffmpeg fail — reaching ffmpeg proves the operation exists).
$eol = "\r\n";
$check_op = function ( $op ) use ( $url, $sidecar_headers, $eol ) {
	$boundary = 'nvoos-probe-' . wp_generate_password( 16, false, false );
	$mp_body  = '';
	foreach ( array( 'operation' => $op, 'format' => 'mp3' ) as $fname => $fval ) {
		$mp_body .= '--' . $boundary . $eol;
		$mp_body .= 'Content-Disposition: form-data; name="' . $fname . '"' . $eol . $eol;
		$mp_body .= $fval . $eol;
	}
	$mp_body .= '--' . $boundary . $eol;
	$mp_body .= 'Content-Disposition: form-data; name="file"; filename="probe.bin"' . $eol;
	$mp_body .= 'Content-Type: application/octet-stream' . $eol . $eol;
	$mp_body .= 'probe-data' . $eol;
	$mp_body .= '--' . $boundary . '--' . $eol;

	$r = wp_remote_post(
		rtrim( $url, '/' ) . '/api/video/process',
		array(
			'timeout' => 60,
			'headers' => array_merge(
				$sidecar_headers,
				array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary )
			),
			'body'    => $mp_body,
		)
	);

	if ( is_wp_error( $r ) ) {
		return array( false, $r->get_error_message() );
	}
	$status = (int) wp_remote_retrieve_response_code( $r );
	$body   = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( 404 === $status ) {
		return array( false, 'HTTP 404 — /api/video/process missing' );
	}
	if ( 400 === $status && isset( $body['error'] ) && false !== strpos( $body['error'], 'Unknown operation' ) ) {
		return array( false, 'HTTP 400 — ' . $body['error'] . ' (older worker build)' );
	}
	$snippet = isset( $body['error'] ) ? substr( $body['error'], 0, 60 ) : 'accepted';
	return array( true, 'HTTP ' . $status . ' — operation accepted, ffmpeg attempted (' . $snippet . '...)' );
};

foreach ( array( 'extract_audio' => 'POST /process op=extract_audio', 'extract_frames' => 'POST /process op=extract_frames' ) as $op => $label ) {
	list( $op_ok, $op_note ) = $check_op( $op );
	probe_result( $label, $op_ok, $op_note );
	$video_api_ready = $video_api_ready && $op_ok;
}

// 3. The download route must answer with the JSON file_not_found 404.
$d = probe_get_json( rtrim( $url, '/' ) . '/api/video/download/processed_probe_check.jpg', $sidecar_headers, 10 );
$dl_ok = ( 404 === $d['status'] && isset( $d['body']['error'] ) && 'file_not_found' === $d['body']['error'] );
if ( $dl_ok ) {
	probe_result( 'GET /api/video/download (probe name)', true, 'HTTP 404 file_not_found — route present (current build)' );
} elseif ( 404 === $d['status'] ) {
	probe_result( 'GET /api/video/download (probe name)', false, 'HTTP 404 but not the JSON file_not_found response — download route missing (older worker build)' );
} else {
	probe_result( 'GET /api/video/download (probe name)', true, $d['error'] ? $d['error'] : 'HTTP ' . $d['status'] . ' — route present with validation' );
}
$video_api_ready = $video_api_ready && $dl_ok;

probe_out( '  Video API:      ' . ( $video_api_ready ? 'current build — plugin FFmpeg services can use the sidecar' : 'INCOMPLETE — FFmpeg ops need a worker redeploy (see FAILs above)' ) );

// Image API fingerprint: plugin image GENERATION does not route through
// the worker (it calls providers directly), but the worker's own image
// surface needs provider keys. These checks confirm the routes exist and
// report which providers the deployed worker has credentials for.
probe_out();
probe_out( '  -- Image API fingerprint (no keys required for these checks) --' );
$ip = probe_get_json( rtrim( $url, '/' ) . '/api/image/providers', $sidecar_headers, 10 );
$providers_ok   = ( 200 === $ip['status'] && is_array( $ip['body'] ) );
$configured_ai  = array();
if ( $providers_ok ) {
	foreach ( $ip['body'] as $pid => $pdata ) {
		if ( is_array( $pdata ) && ! empty( $pdata['configured'] ) ) {
			$configured_ai[] = $pid;
		}
	}
}
probe_result(
	'GET /api/image/providers',
	$providers_ok,
	$ip['error'] ? $ip['error'] : ( $providers_ok ? 'configured providers: ' . ( empty( $configured_ai ) ? 'NONE — set provider keys on the worker to enable generation' : implode( ', ', $configured_ai ) ) : 'HTTP ' . $ip['status'] )
);

$ig = wp_remote_post(
	rtrim( $url, '/' ) . '/api/image/generate',
	array(
		'timeout' => 10,
		'headers' => array_merge( $sidecar_headers, array( 'Content-Type' => 'application/json' ) ),
		'body'    => '{}',
	)
);
$ig_status = is_wp_error( $ig ) ? 0 : (int) wp_remote_retrieve_response_code( $ig );
$ig_body   = is_wp_error( $ig ) ? null : json_decode( wp_remote_retrieve_body( $ig ), true );
$ig_ok     = ( 400 === $ig_status && isset( $ig_body['error'] ) );
probe_result(
	'POST /api/image/generate (no prompt)',
	$ig_ok,
	is_wp_error( $ig ) ? $ig->get_error_message() : 'HTTP ' . $ig_status . ( isset( $ig_body['error'] ) ? ' — ' . $ig_body['error'] : '' )
);

// Extended contract checks for the routes fixed alongside the video API.
probe_out();
probe_out( '  -- Extended contract (OCR / email verify / chart) --' );
$oc = wp_remote_post(
	rtrim( $url, '/' ) . '/api/ocr/recognize',
	array(
		'timeout' => 10,
		'headers' => array_merge( $sidecar_headers, array( 'Content-Type' => 'application/json' ) ),
		'body'    => '{}',
	)
);
$oc_status = is_wp_error( $oc ) ? 0 : (int) wp_remote_retrieve_response_code( $oc );
$oc_body   = is_wp_error( $oc ) ? null : json_decode( wp_remote_retrieve_body( $oc ), true );
$oc_ok     = ( 400 === $oc_status && isset( $oc_body['error'] ) );
probe_result(
	'POST /api/ocr/recognize (no input)',
	$oc_ok,
	is_wp_error( $oc ) ? $oc->get_error_message() : 'HTTP ' . $oc_status . ( isset( $oc_body['error'] ) ? ' — ' . $oc_body['error'] : '' )
);

$ev = wp_remote_post(
	rtrim( $url, '/' ) . '/api/email/verify',
	array(
		'timeout' => 20,
		'headers' => array_merge( $sidecar_headers, array( 'Content-Type' => 'application/json' ) ),
		'body'    => wp_json_encode( array( 'smtp' => array( 'host' => '127.0.0.1', 'port' => 1 ) ) ),
	)
);
$ev_status = is_wp_error( $ev ) ? 0 : (int) wp_remote_retrieve_response_code( $ev );
$ev_body   = is_wp_error( $ev ) ? null : json_decode( wp_remote_retrieve_body( $ev ), true );
$ev_ok     = ( 200 === $ev_status && isset( $ev_body['connected'] ) );
if ( $ev_ok ) {
	probe_result( 'POST /api/email/verify', true, 'route present — structured connected:false result (expected for an unreachable SMTP)' );
} elseif ( 404 === $ev_status ) {
	probe_result( 'POST /api/email/verify', false, 'HTTP 404 — verify route missing (older worker build)' );
} else {
	probe_result( 'POST /api/email/verify', false, is_wp_error( $ev ) ? $ev->get_error_message() : 'HTTP ' . $ev_status );
}

$rc = wp_remote_post(
	rtrim( $url, '/' ) . '/api/data/render-chart',
	array(
		'timeout' => 10,
		'headers' => array_merge( $sidecar_headers, array( 'Content-Type' => 'application/json' ) ),
		'body'    => '{}',
	)
);
$rc_status = is_wp_error( $rc ) ? 0 : (int) wp_remote_retrieve_response_code( $rc );
$rc_body   = is_wp_error( $rc ) ? null : json_decode( wp_remote_retrieve_body( $rc ), true );
$rc_ok     = ( 400 === $rc_status && isset( $rc_body['error'] ) );
probe_result(
	'POST /api/data/render-chart (no type)',
	$rc_ok,
	is_wp_error( $rc ) ? $rc->get_error_message() : 'HTTP ' . $rc_status . ( isset( $rc_body['error'] ) ? ' — ' . $rc_body['error'] : '' )
);

// PDF extract + vectorize routes: the plugin's extract_pdf_text tool and the
// SVG vectorizer upload raster/PDF files to these routes. A file-less POST
// must answer with the route's validation error — reaching validation proves
// the route exists on the deployed worker build.
probe_out();
probe_out( '  -- PDF extract / vectorize contract (route fingerprinting) --' );

$pe = wp_remote_post(
	rtrim( $url, '/' ) . '/api/pdf/extract',
	array(
		'timeout' => 10,
		'headers' => $sidecar_headers,
	)
);
$pe_status = is_wp_error( $pe ) ? 0 : (int) wp_remote_retrieve_response_code( $pe );
$pe_body   = is_wp_error( $pe ) ? null : json_decode( wp_remote_retrieve_body( $pe ), true );
$pe_ok     = ( 400 === $pe_status && isset( $pe_body['error'] ) );
probe_result(
	'POST /api/pdf/extract (no file)',
	$pe_ok,
	is_wp_error( $pe ) ? $pe->get_error_message() : 'HTTP ' . $pe_status . ( isset( $pe_body['error'] ) ? ' — ' . $pe_body['error'] : '' )
);

$vz = wp_remote_post(
	rtrim( $url, '/' ) . '/api/image/vectorize',
	array(
		'timeout' => 10,
		'headers' => $sidecar_headers,
	)
);
$vz_status = is_wp_error( $vz ) ? 0 : (int) wp_remote_retrieve_response_code( $vz );
$vz_body   = is_wp_error( $vz ) ? null : json_decode( wp_remote_retrieve_body( $vz ), true );
$vz_ok     = ( 400 === $vz_status && isset( $vz_body['error'] ) );
probe_result(
	'POST /api/image/vectorize (no file)',
	$vz_ok,
	is_wp_error( $vz ) ? $vz->get_error_message() : 'HTTP ' . $vz_status . ( isset( $vz_body['error'] ) ? ' — ' . $vz_body['error'] : '' )
);

// Document generation routes: the pro-pdf/pro-word/pro-excel tools send
// JSON payloads; the merge/watermark tools upload PDFs. A file-less POST
// must answer with the route's validation error — reaching validation
// proves the route exists on the deployed worker build.
probe_out();
probe_out( '  -- Document generation contract (route fingerprinting) --' );

$doc_checks = array(
	'POST /api/document/excel (no sheets)' => array( '/api/document/excel', '{}' ),
	'POST /api/document/word (no content)' => array( '/api/document/word', '{}' ),
	'POST /api/pdf/generate (no html)'     => array( '/api/pdf/generate', '{}' ),
);
foreach ( $doc_checks as $label => $doc_check ) {
	list( $doc_route, $doc_body ) = $doc_check;
	$dc = wp_remote_post(
		rtrim( $url, '/' ) . $doc_route,
		array(
			'timeout' => 10,
			'headers' => array_merge( $sidecar_headers, array( 'Content-Type' => 'application/json' ) ),
			'body'    => $doc_body,
		)
	);
	$dc_status = is_wp_error( $dc ) ? 0 : (int) wp_remote_retrieve_response_code( $dc );
	$dc_body   = is_wp_error( $dc ) ? null : json_decode( wp_remote_retrieve_body( $dc ), true );
	$dc_ok     = ( 400 === $dc_status && isset( $dc_body['error'] ) );
	probe_result(
		$label,
		$dc_ok,
		is_wp_error( $dc ) ? $dc->get_error_message() : 'HTTP ' . $dc_status . ( isset( $dc_body['error'] ) ? ' — ' . $dc_body['error'] : '' )
	);
}

// Merge route must accept uploads — a file-less POST answers with the
// multipart validation error (older builds answer with the sources-path
// error; both prove the route exists, upload support needs the live test).
$mg = wp_remote_post(
	rtrim( $url, '/' ) . '/api/pdf/merge',
	array(
		'timeout' => 10,
		'headers' => $sidecar_headers,
	)
);
$mg_status = is_wp_error( $mg ) ? 0 : (int) wp_remote_retrieve_response_code( $mg );
$mg_body   = is_wp_error( $mg ) ? null : json_decode( wp_remote_retrieve_body( $mg ), true );
$mg_ok     = ( 400 === $mg_status && isset( $mg_body['error'] ) );
probe_result(
	'POST /api/pdf/merge (no input)',
	$mg_ok,
	is_wp_error( $mg ) ? $mg->get_error_message() : 'HTTP ' . $mg_status . ( isset( $mg_body['error'] ) ? ' — ' . $mg_body['error'] : '' )
);

$wm = wp_remote_post(
	rtrim( $url, '/' ) . '/api/pdf/watermark',
	array(
		'timeout' => 10,
		'headers' => $sidecar_headers,
	)
);
$wm_status = is_wp_error( $wm ) ? 0 : (int) wp_remote_retrieve_response_code( $wm );
$wm_body   = is_wp_error( $wm ) ? null : json_decode( wp_remote_retrieve_body( $wm ), true );
$wm_ok     = ( 400 === $wm_status && isset( $wm_body['error'] ) );
probe_result(
	'POST /api/pdf/watermark (no input)',
	$wm_ok,
	is_wp_error( $wm ) ? $wm->get_error_message() : 'HTTP ' . $wm_status . ( isset( $wm_body['error'] ) ? ' — ' . $wm_body['error'] : '' )
);

// ── [4B] Crawl routes + Crawl4AI facade ─────────────────────────────
// v3.1.0+ workers expose the native crawl endpoints and the
// Crawl4AI-compatible facade (used when WP_MCP_AI_CRAWL4AI_BASE_URL points
// at the worker). A file-less/invalid POST must answer with the route's
// validation error — reaching validation proves the route exists on the
// deployed worker build. Set PROBE_CRAWL_LIVE=1 for a live round-trip.
probe_section( '4B) CRAWL ROUTES + CRAWL4AI FACADE (v3.1.0+)' );

$cr = wp_remote_post(
	rtrim( $url, '/' ) . '/api/crawl/markdown',
	array(
		'timeout' => 10,
		'headers' => array_merge( $sidecar_headers, array( 'Content-Type' => 'application/json' ) ),
		'body'    => '{}',
	)
);
$cr_status = is_wp_error( $cr ) ? 0 : (int) wp_remote_retrieve_response_code( $cr );
$cr_body   = is_wp_error( $cr ) ? null : json_decode( wp_remote_retrieve_body( $cr ), true );
$cr_ok     = ( 400 === $cr_status && isset( $cr_body['error'] ) );
probe_result(
	'POST /api/crawl/markdown (no url)',
	$cr_ok,
	is_wp_error( $cr ) ? $cr->get_error_message() : 'HTTP ' . $cr_status . ( isset( $cr_body['error'] ) ? ' — ' . $cr_body['error'] : '' )
);

$c4 = wp_remote_post(
	rtrim( $url, '/' ) . '/api/crawl4ai/crawl',
	array(
		'timeout' => 10,
		'headers' => array_merge( $sidecar_headers, array( 'Content-Type' => 'application/json' ) ),
		'body'    => '{}',
	)
);
$c4_status = is_wp_error( $c4 ) ? 0 : (int) wp_remote_retrieve_response_code( $c4 );
$c4_body   = is_wp_error( $c4 ) ? null : json_decode( wp_remote_retrieve_body( $c4 ), true );
$c4_ok     = ( 400 === $c4_status && isset( $c4_body['error'] ) );
probe_result(
	'POST /api/crawl4ai/crawl (no urls)',
	$c4_ok,
	is_wp_error( $c4 ) ? $c4->get_error_message() : 'HTTP ' . $c4_status . ( isset( $c4_body['error'] ) ? ' — ' . $c4_body['error'] : '' )
);

if ( '1' === getenv( 'PROBE_CRAWL_LIVE' ) ) {
	$live = wp_remote_post(
		rtrim( $url, '/' ) . '/api/crawl4ai/crawl',
		array(
			'timeout' => 15,
			'headers' => array_merge( $sidecar_headers, array( 'Content-Type' => 'application/json' ) ),
			'body'    => wp_json_encode( array( 'urls' => array( 'https://example.com' ) ) ),
		)
	);
	$live_status = is_wp_error( $live ) ? 0 : (int) wp_remote_retrieve_response_code( $live );
	$live_body   = is_wp_error( $live ) ? null : json_decode( wp_remote_retrieve_body( $live ), true );
	$live_task   = isset( $live_body['task_id'] ) ? $live_body['task_id'] : '';
	$live_note   = is_wp_error( $live ) ? $live->get_error_message() : 'HTTP ' . $live_status;
	$live_ok     = false;

	if ( $live_task ) {
		sleep( 3 );
		$poll = probe_get_json( rtrim( $url, '/' ) . '/api/crawl4ai/task/' . rawurlencode( $live_task ), $sidecar_headers, 15 );
		if ( 200 === $poll['status'] && isset( $poll['body']['status'] ) && 'completed' === $poll['body']['status'] ) {
			$live_ok = true;
			$bytes   = 0;
			if ( ! empty( $poll['body']['results'][0]['markdown'] ) ) {
				$bytes = strlen( $poll['body']['results'][0]['markdown'] );
			}
			$live_note = $live_task . ' — completed, markdown ' . $bytes . ' bytes';
		} else {
			$live_note = $live_task . ' — poll ' . ( isset( $poll['body']['status'] ) ? $poll['body']['status'] : 'error' );
		}
	}
	probe_result( 'Crawl4AI facade live round-trip', $live_ok, $live_note );
} else {
	probe_out( '  (live round-trip skipped — set PROBE_CRAWL_LIVE=1 to enable)' );
}

// ── [5] Local JS fallback presence ────────────────────────────────────
probe_section( '5) LOCAL JS FALLBACK PRESENCE (what would run if sidecar fails)' );

$node_path = false;
$node_err  = '';
try {
	$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
	$node_path       = $process_service->get_command_path( 'node' );
} catch ( \Exception $e ) {
	$node_err = $e->getMessage();
}
probe_out( '  node on PATH:  ' . ( $node_path ? $node_path : ( $node_err ? 'check failed (' . $node_err . ')' : 'NOT FOUND (or process functions disabled)' ) ) );

if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	$vendor_prettier = WP_MCP_AI_PRO_PATH . 'assets/vendor/prettier/standalone.js';
	$npm_prettier    = WP_MCP_AI_PRO_PATH . 'node_modules/prettier/index.js';
	$local_prettier  = file_exists( $vendor_prettier ) || file_exists( $npm_prettier );
	probe_out( '  bundled JS:   ' . ( $local_prettier ? 'present (local fallback possible)' : 'absent (no local fallback)' ) );
} else {
	probe_out( '  bundled JS:   n/a (Pro addon not active)' );
}

if ( function_exists( 'wp_mcp_ai_is_nodejs_available' ) ) {
	$avail = wp_mcp_ai_is_nodejs_available();
	if ( 'sidecar' === $avail ) {
		probe_out( '  plugin check: "sidecar" — plugin admin notice shows "Media Worker Sidecar Active".' );
	} elseif ( true === $avail ) {
		probe_out( '  plugin check: "local node" — plugin treats Node.js as locally available.' );
	} else {
		probe_out( '  plugin check: "none" — no local Node, sidecar is the only path.' );
	}
}

// ── [6] Real service-class calls ──────────────────────────────────────
// The plugin require_once's these files on demand (inside the tools), so
// they are not loaded in plain CLI — load them the same way here.
probe_section( '6) SERVICE-LEVEL CALLS (real plugin service classes)' );

if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	$svc_prettier = WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-prettier-service.php';
	$svc_mjml     = WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-mjml-service.php';
	if ( is_file( $svc_prettier ) ) {
		require_once $svc_prettier;
	}
	if ( is_file( $svc_mjml ) ) {
		require_once $svc_mjml;
	}
}

$prettier_ok   = false;
$prettier_note = '';
if ( class_exists( 'WP_MCP_AI_Prettier_Service' ) ) {
	$svc = new WP_MCP_AI_Prettier_Service();
	$res = $svc->format_code( 'const x=1;' );
	if ( is_wp_error( $res ) ) {
		$prettier_note = 'error: ' . $res->get_error_message();
	} else {
		$prettier_ok   = true;
		$prettier_note = json_encode( $res );
	}
} else {
	$prettier_note = 'skipped — Pro service class not loadable';
}
probe_result( 'PrettierService::format_code()', $prettier_ok, $prettier_note );

$mjml_ok   = false;
$mjml_note = '';
if ( class_exists( 'WP_MCP_AI_MJML_Service' ) ) {
	$svc = new WP_MCP_AI_MJML_Service();
	$res = $svc->compile( '<mjml><mj-body><mj-section><mj-column><mj-text>Hi</mj-text></mj-column></mj-section></mj-body></mjml>' );
	if ( is_wp_error( $res ) ) {
		$mjml_note = 'error: ' . $res->get_error_message();
	} else {
		$mjml_ok   = true;
		$mjml_note = strlen( $res ) . ' bytes of HTML';
	}
} else {
	$mjml_note = 'skipped — Pro service class not loadable';
}
probe_result( 'MJMLService::compile()', $mjml_ok, $mjml_note );

// ── Verdict ───────────────────────────────────────────────────────────
// The end-to-end call is the authoritative signal: it authenticates (401 on
// a bad token) and exercises a real worker route, exactly like the plugin.
probe_out( str_repeat( '-', 72 ) );
if ( $e2e_ok ) {
	probe_out( ' VERDICT: WORKER CONFIRMED — the authenticated formatting call' );
	probe_out( ' succeeded against ' . $url . ', so sidecar-first services route' );
	probe_out( ' there, not to local JS.' );
	if ( $node_path ) {
		probe_out( ' NOTE: local Node.js IS present on this host. If the worker goes' );
		probe_out( ' down, operations silently fall back to local JS. To prove the' );
		probe_out( ' worker is doing the work, run the negative control below.' );
	} else {
		probe_out( ' NOTE: local Node.js is absent on this host — the worker is the' );
		probe_out( ' ONLY path. Successful service calls can only have used it.' );
	}
	if ( ! $prettier_ok ) {
		probe_out( ' NOTE: the service-level call failed while the direct sidecar call' );
		probe_out( ' succeeded. Some Pro services run legacy local-Node filters BEFORE' );
		probe_out( ' the sidecar attempt (addons/pro/includes/npm-integration-filters.' );
		probe_out( ' php) — here the filter errored out first. The worker itself is' );
		probe_out( ' healthy; the plugin routing on this site may need attention.' );
	}
	if ( ! $video_api_ready ) {
		probe_out( ' NOTE: the worker video API is an older build — FFmpeg ops fall' );
		probe_out( ' back to local Node until the worker is redeployed from the' );
		probe_out( ' latest media-worker build.' );
	}
	probe_out();
	probe_out( ' NEGATIVE CONTROL (optional, 2 min): temporarily set the URL to' );
	probe_out( ' https://worker.nvoos.cloud.invalid in Settings → Media Worker,' );
	probe_out( ' rerun this probe. Section 6 must now FAIL. Restore the URL after.' );
	probe_out( ' Zero-touch alternative: PROBE_WORKER_URL=https://worker.nvoos.' );
	probe_out( ' cloud.invalid php probe-wordpress.php (checks only — section 6' );
	probe_out( ' keeps using site config).' );
	exit( 0 );
}

if ( 401 === $e2e_status ) {
	probe_out( ' VERDICT: TOKEN REJECTED — the worker answered 401 on the' );
	probe_out( ' formatting call. The admin "Test Connection" cannot catch this' );
	probe_out( ' (it sends no token). Every sidecar call fails and the plugin' );
	probe_out( ' silently falls back to local JS. Fix the token in Settings →' );
	probe_out( ' Media Worker so it matches the worker\'s WORKER_API_TOKEN.' );
} elseif ( ! $reachable ) {
	probe_out( ' VERDICT: WORKER UNREACHABLE — public health check failed. Sidecar' );
	probe_out( ' calls fail, plugin falls back to local JS (if Node present) or' );
	probe_out( ' returns 501 errors. Check the URL and worker deployment.' );
} else {
	probe_out( ' VERDICT: AUTH OK BUT CALLS FAIL — the formatting call failed' );
	probe_out( ' (' . $e2e_note . '). Worker routes may be degraded; check worker logs.' );
}
exit( 1 );
