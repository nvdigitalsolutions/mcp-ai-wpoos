<?php
/**
 * NV oOS Laravel spike latency benchmark.
 *
 * Usage:
 *   php bin/laravel-spike-bench.php --url=http://localhost:8001/health --requests=200
 */

declare(strict_types=1);

$options  = getopt('', array( 'url::', 'requests::' ));
$url      = (string) ( $options['url'] ?? 'http://localhost:8001/health' );
$requests = max( 1, (int) ( $options['requests'] ?? 200 ) );
$times    = array();
$errors   = 0;

for ( $i = 0; $i < $requests; ++$i ) {
	$handle = curl_init( $url );
	curl_setopt_array( $handle, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 30,
	) );

	$start = microtime( true );
	curl_exec( $handle );
	$elapsed = ( microtime( true ) - $start ) * 1000;
	$code    = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
	curl_close( $handle );

	if ( 200 !== $code ) {
		++$errors;
		continue;
	}

	$times[] = $elapsed;
}

sort( $times );
$count = count( $times );

if ( 0 === $count ) {
	fwrite( STDERR, "No successful requests.\n" );
	exit( 1 );
}

$percentile = static function ( array $values, float $p ): float {
	$index = (int) floor( ( $p / 100 ) * ( count( $values ) - 1 ) );
	return $values[ $index ];
};

printf( "URL: %s\n", $url );
printf( "Requests: %d (errors: %d)\n", $requests, $errors );
printf( "min: %.2f ms\n", $times[0] );
printf( "p50: %.2f ms\n", $percentile( $times, 50 ) );
printf( "p95: %.2f ms\n", $percentile( $times, 95 ) );
printf( "p99: %.2f ms\n", $percentile( $times, 99 ) );
printf( "max: %.2f ms\n", $times[ $count - 1 ] );
printf( "avg: %.2f ms\n", array_sum( $times ) / $count );

exit( $errors > 0 ? 1 : 0 );
