<?php
/**
 * Performance Benchmark Script
 *
 * Compares performance of manual validation vs Symfony Validator.
 * This is a standalone utility script for performance testing.
 *
 * @package WP_MCP_AI
 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
 * phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
 */

// Simulate WordPress environment.
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_MCP_AI_PATH', __DIR__ . '/' );

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Mock sanitize_text_field.
 *
 * @param string $str String to sanitize.
 * @return string
 */
function sanitize_text_field( $str ) {
	return trim( wp_strip_all_tags( $str ) );
}

/**
 * Mock wp_strip_all_tags.
 *
 * @param string $str String to strip tags from.
 * @return string
 */
function wp_strip_all_tags( $str ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	return strip_tags( $str );
}

/**
 * Mock sanitize_key.
 *
 * @param string $str String to sanitize.
 * @return string
 */
function sanitize_key( $str ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $str ) );
}

/**
 * Mock absint.
 *
 * @param mixed $val Value to convert.
 * @return int
 */
function absint( $val ) {
	return abs( intval( $val ) );
}

/**
 * Mock translation function.
 *
 * @param string $text   Text to translate.
 * @param string $domain Text domain (unused in mock).
 * @return string
 */
function __( $text, $domain = 'default' ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $text;
}

/**
 * Mock WP_Error class.
 */
class WP_Error {
	/**
	 * Error storage.
	 *
	 * @var array
	 */
	public $errors = array();

	/**
	 * Constructor.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	public function __construct( $code, $message ) {
		$this->errors[ $code ] = array( $message );
	}
}

// Load validator service and validation class.
require_once __DIR__ . '/includes/validators/class-wp-mcp-ai-validator-service.php';
require_once __DIR__ . '/includes/validators/arguments/class-search-content-arguments.php';

/**
 * Benchmark: Manual Validation (Old Pattern).
 *
 * @param array $arguments  Arguments to validate.
 * @param int   $iterations Number of iterations.
 * @return float Time elapsed.
 */
function benchmark_manual_validation( $arguments, $iterations = 1000 ) {
	$start = microtime( true );

	for ( $i = 0; $i < $iterations; $i++ ) {
		$search_term = isset( $arguments['search_term'] ) ? sanitize_text_field( $arguments['search_term'] ) : '';
		$post_type   = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'any';
		$limit       = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$limit       = $limit > 0 ? min( $limit, 50 ) : 10;

		if ( '' === $search_term && empty( $arguments['taxonomy_filters'] ) && empty( $arguments['meta_filters'] ) ) {
			$error = new WP_Error( 'wp_mcp_ai_missing_criteria', 'Provide search criteria' );
			continue;
		}

		if ( $limit < 1 || $limit > 50 ) {
			$error = new WP_Error( 'wp_mcp_ai_invalid_limit', 'Limit must be between 1 and 50' );
			continue;
		}

		if ( isset( $arguments['taxonomy_filters'] ) && is_array( $arguments['taxonomy_filters'] ) ) {
			foreach ( $arguments['taxonomy_filters'] as $filter ) {
				if ( ! isset( $filter['taxonomy'] ) || ! isset( $filter['terms'] ) ) {
					$error = new WP_Error( 'invalid_filter', 'Invalid taxonomy filter' );
					continue 2;
				}
				if ( ! is_array( $filter['terms'] ) || empty( $filter['terms'] ) ) {
					$error = new WP_Error( 'invalid_terms', 'Invalid terms' );
					continue 2;
				}
			}
		}

		$validated = true;
	}

	$end = microtime( true );
	return $end - $start;
}

/**
 * Benchmark: Symfony Validator (New Pattern).
 *
 * @param array $arguments  Arguments to validate.
 * @param int   $iterations Number of iterations.
 * @return float Time elapsed.
 */
function benchmark_symfony_validation( $arguments, $iterations = 1000 ) {
	$validator = \WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service::get_instance();
	$start     = microtime( true );

	for ( $i = 0; $i < $iterations; $i++ ) {
		$validated = new \WP_MCP_AI\Tools\Arguments\SearchContentArguments();

		foreach ( $arguments as $key => $value ) {
			if ( property_exists( $validated, $key ) ) {
				$validated->$key = $value;
			}
		}

		$violations = $validator->validate( $validated );

		if ( ! $validator->is_valid( $violations ) ) {
			continue;
		}

		$result = true;
	}

	$end = microtime( true );
	return $end - $start;
}

$test_cases = array(
	'simple_search'  => array(
		'search_term' => 'test query',
		'post_type'   => 'post',
		'limit'       => 10,
	),
	'complex_search' => array(
		'search_term'      => 'wordpress development',
		'post_type'        => 'post',
		'limit'            => 25,
		'taxonomy_filters' => array(
			array(
				'taxonomy' => 'category',
				'terms'    => array( 'development', 'wordpress' ),
				'operator' => 'IN',
			),
		),
		'meta_filters'     => array(
			array(
				'key'     => 'featured',
				'value'   => '1',
				'compare' => '=',
			),
		),
	),
);

echo "Performance Benchmark: Manual Validation vs Symfony Validator\n";
echo "==============================================================\n\n";

$iterations = 1000;

foreach ( $test_cases as $name => $arguments ) {
	echo "Test Case: $name\n";
	echo str_repeat( '-', 60 ) . "\n";

	benchmark_manual_validation( $arguments, 10 );
	benchmark_symfony_validation( $arguments, 10 );

	$manual_time  = benchmark_manual_validation( $arguments, $iterations );
	$symfony_time = benchmark_symfony_validation( $arguments, $iterations );

	$manual_per_call  = ( $manual_time / $iterations ) * 1000;
	$symfony_per_call = ( $symfony_time / $iterations ) * 1000;

	printf( "Manual Validation:   %.4f seconds (%.4f ms per call)\n", $manual_time, $manual_per_call );
	printf( "Symfony Validation:  %.4f seconds (%.4f ms per call)\n", $symfony_time, $symfony_per_call );

	$diff    = $symfony_time - $manual_time;
	$percent = ( ( $symfony_time - $manual_time ) / $manual_time ) * 100;

	if ( $diff > 0 ) {
		printf( "Difference:          +%.4f seconds (+%.1f%% slower)\n", $diff, $percent );
	} else {
		printf( "Difference:          %.4f seconds (%.1f%% faster)\n", abs( $diff ), abs( $percent ) );
	}

	echo "\n";
}

echo "Summary\n";
echo str_repeat( '=', 60 ) . "\n";
echo "Iterations per test: $iterations\n";
echo "\nNotes:\n";
echo "- Manual validation is typically faster for simple cases\n";
echo "- Symfony validation provides type safety and better error messages\n";
echo "- Development time savings offset the minimal performance difference\n";
echo "- Validation is a small part of total request time (< 1ms)\n";
echo "- Code maintainability and developer experience are the main benefits\n";
