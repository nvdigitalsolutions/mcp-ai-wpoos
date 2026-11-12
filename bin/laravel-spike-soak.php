<?php
/**
 * NV oOS Laravel spike soak test.
 *
 * Opens N concurrent SSE streams and re-opens them until the duration
 * elapses. Intended for the week-5 worker-stability check.
 *
 * Usage:
 *   SPIKE_TOKEN=... php bin/laravel-spike-soak.php --streams=10 --seconds=60
 *   SPIKE_TOKEN=... php bin/laravel-spike-soak.php --streams=10 --seconds=1800
 */

declare(strict_types=1);

$options = getopt('', array( 'streams::', 'seconds::', 'url::' ));
$streams = max( 1, (int) ( $options['streams'] ?? 10 ) );
$seconds = max( 1, (int) ( $options['seconds'] ?? 60 ) );
$url     = (string) ( $options['url'] ?? 'http://localhost:8001/chat/stream' );
$token   = (string) getenv( 'SPIKE_TOKEN' );

if ( '' === $token ) {
	fwrite( STDERR, "SPIKE_TOKEN is required.\n" );
	exit( 1 );
}

$payload = json_encode(
	array(
		'messages'  => array( array( 'role' => 'user', 'content' => 'soak' ) ),
		'assistant' => array( 'provider' => 'spike', 'model' => 'spike-1', 'tools' => array() ),
		'options'   => array(),
	),
	JSON_UNESCAPED_SLASHES,
);

$multi = curl_multi_init();
$stats = array( 'started' => 0, 'completed' => 0, 'errors' => 0, 'bytes' => 0, 'done_markers' => 0 );
$live  = array();

/**
 * Create one SSE request handle.
 *
 * @param resource|\CurlMultiHandle $multi
 * @return array{0: \CurlHandle, 1: array<string, int>}
 */
function add_stream( $multi, string $url, string $token, string $payload, array &$stats ): array {
	$local = array( 'bytes' => 0, 'done' => 0 );
	$handle = curl_init( $url );

	curl_setopt_array( $handle, array(
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => $payload,
		CURLOPT_HTTPHEADER     => array(
			'Authorization: Bearer ' . $token,
			'Content-Type: application/json',
			'Accept: text/event-stream',
		),
		CURLOPT_TIMEOUT        => 60,
		CURLOPT_WRITEFUNCTION  => static function ( $ch, string $chunk ) use ( &$local, &$stats ): int {
			$local['bytes'] += strlen( $chunk );
			$stats['bytes'] += strlen( $chunk );
			if ( false !== strpos( $chunk, '[DONE]' ) ) {
				++$local['done'];
				++$stats['done_markers'];
			}
			return strlen( $chunk );
		},
	) );

	curl_multi_add_handle( $multi, $handle );
	++$stats['started'];

	return array( $handle, $local );
}

for ( $i = 0; $i < $streams; ++$i ) {
	$live[] = add_stream( $multi, $url, $token, (string) $payload, $stats );
}

$deadline = microtime( true ) + $seconds;
$lastReport = 0.0;

while ( microtime( true ) < $deadline ) {
	do {
		$status = curl_multi_exec( $multi, $running );
	} while ( CURLM_CALL_MULTI_PERFORM === $status );

	if ( $running > 0 ) {
		curl_multi_select( $multi, 1.0 );
	}

	while ( false !== ( $info = curl_multi_info_read( $multi ) ) ) {
		$handle = $info['handle'];
		$code   = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );

		if ( CURLMSG_DONE === $info['msg'] && 200 !== $code ) {
			++$stats['errors'];
		} else {
			++$stats['completed'];
		}

		foreach ( $live as $index => $entry ) {
			if ( $entry[0] === $handle ) {
				curl_multi_remove_handle( $multi, $handle );
				curl_close( $handle );
				unset( $live[ $index ] );
				break;
			}
		}

		$live[] = add_stream( $multi, $url, $token, (string) $payload, $stats );
	}

	$now = microtime( true );
	if ( $now - $lastReport >= 5.0 ) {
		$lastReport = $now;
		printf(
			"[%5.1fs] started=%d completed=%d errors=%d bytes=%d done=%d\n",
			$seconds - max( 0, $deadline - $now ),
			$stats['started'],
			$stats['completed'],
			$stats['errors'],
			$stats['bytes'],
			$stats['done_markers'],
		);
	}
}

foreach ( $live as $entry ) {
	curl_multi_remove_handle( $multi, $entry[0] );
	curl_close( $entry[0] );
}

curl_multi_close( $multi );

echo "\n";
echo "Soak complete\n";
echo '  streams:   ' . $streams . "\n";
echo '  seconds:   ' . $seconds . "\n";
echo '  started:   ' . $stats['started'] . "\n";
echo '  completed: ' . $stats['completed'] . "\n";
echo '  errors:    ' . $stats['errors'] . "\n";
echo '  bytes:     ' . $stats['bytes'] . "\n";
echo '  done:      ' . $stats['done_markers'] . "\n";

exit( $stats['errors'] > 0 ? 1 : 0 );
