<?php
/**
 * Manual verification script for auto aspect ratio functionality.
 * This simulates the normalise_aspect_ratio method behavior.
 *
 * Run with: php tests/manual-verify-auto-aspect-ratio.php
 */

echo "Manual Verification of Auto Aspect Ratio Functionality\n";
echo "======================================================\n\n";

// Simulate the normalise_aspect_ratio method.
function test_normalise_aspect_ratio( $aspect_ratio ) {
	$aspect_ratio = strtolower( (string) $aspect_ratio );
	$aspect_ratio = str_replace( ' ', '', $aspect_ratio );

	// Special case: "auto" means let the AI decide (no aspectRatio sent to API).
	if ( 'auto' === $aspect_ratio ) {
		return 'auto';
	}

	$aspect_ratio = strtoupper( $aspect_ratio );

	if ( preg_match( '/^(\d+):(\d+)$/', $aspect_ratio, $matches ) ) {
		$left  = ltrim( $matches[1], '0' );
		$right = ltrim( $matches[2], '0' );

		if ( '' === $left ) {
			$left = '0';
		}

		if ( '' === $right ) {
			$right = '0';
		}

		return $left . ':' . $right;
	}

	$allowed = array( '1:1', '2:3', '3:2', '3:4', '4:3', '9:16', '16:9', '21:9' );

	if ( in_array( $aspect_ratio, $allowed, true ) ) {
		return $aspect_ratio;
	}

	return '';
}

// Test cases.
$test_cases = array(
	'auto'  => 'auto',
	'Auto'  => 'auto',
	'AUTO'  => 'auto',
	'AuTo'  => 'auto',
	'16:9'  => '16:9',
	'1:1'   => '1:1',
	'4:3'   => '4:3',
	'9:16'  => '9:16',
	'3:4'   => '3:4',
	'16 :9' => '16:9',
	'bad'   => '',
);

echo "Testing normalise_aspect_ratio method:\n";
echo "--------------------------------------\n";

$all_passed = true;
foreach ( $test_cases as $input => $expected ) {
	$result = test_normalise_aspect_ratio( $input );
	$status = ( $result === $expected ) ? '✓ PASS' : '✗ FAIL';
	printf( "%-15s => %-10s (expected: %-10s) %s\n", "'$input'", "'$result'", "'$expected'", $status );

	if ( $result !== $expected ) {
		$all_passed = false;
	}
}

echo "\n";

// Test API payload generation logic.
echo "Testing API payload generation:\n";
echo "-------------------------------\n";

function test_should_include_aspect_ratio( $aspect_ratio ) {
	// This simulates: if ( '' !== $aspect_ratio && 'auto' !== $aspect_ratio ).
	return ( '' !== $aspect_ratio && 'auto' !== $aspect_ratio );
}

$payload_tests = array(
	'auto' => false,
	'16:9' => true,
	'1:1'  => true,
	'4:3'  => true,
	''     => false,
	'9:16' => true,
);

foreach ( $payload_tests as $ratio => $should_include ) {
	$will_include = test_should_include_aspect_ratio( $ratio );
	$status       = ( $will_include === $should_include ) ? '✓ PASS' : '✗ FAIL';
	$action       = $will_include ? 'INCLUDE' : 'OMIT   ';
	printf( "%-10s => %s in payload (expected: %s) %s\n", "'$ratio'", $action, $should_include ? 'INCLUDE' : 'OMIT   ', $status );

	if ( $will_include !== $should_include ) {
		$all_passed = false;
	}
}

echo "\n";
echo "======================================================\n";
if ( $all_passed ) {
	echo "✓ All tests PASSED!\n";
	exit( 0 );
} else {
	echo "✗ Some tests FAILED!\n";
	exit( 1 );
}
