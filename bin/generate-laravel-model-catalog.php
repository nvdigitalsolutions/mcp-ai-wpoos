<?php
/**
 * Generate the Laravel runtime's model catalogue from the WordPress source.
 *
 * The canonical catalogue lives at `includes/data/model-catalog.json` and is
 * GPL-3.0-or-later. The Laravel app is MIT and must not link to the WordPress
 * tree at runtime, so this script derives a self-contained document: model
 * identifiers, limits and prices are factual reference data, and the `_license`
 * field records the provenance. `docker/**` is outside `check-license-boundary.sh`,
 * which is why the note is carried in the file rather than enforced by a gate.
 *
 * Two transformations matter:
 *
 *  - **Cost is stored per 1M tokens.** Providers advertise per 1M; the source
 *    stores per 1K. Multiplying here keeps the UI and the database in the unit
 *    an operator reads, and avoids a per-render conversion.
 *  - **A display name is derived from `notes`.** The source has no separate
 *    name; the WordPress layer derives one the same way (split on " - ", else
 *    the first sentence), so the two runtimes show the same label.
 *
 * Usage:
 *   php bin/generate-laravel-model-catalog.php
 *
 * @package WP_MCP_AI
 * @since   2.7.0
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

$source = $root . '/includes/data/model-catalog.json';
$target = $root . '/docker/laravel/app-skeleton/resources/model-catalog.json';

if ( ! is_readable( $source ) ) {
	fwrite( STDERR, "Source catalogue not found: {$source}\n" );
	exit( 1 );
}

$document = json_decode( (string) file_get_contents( $source ), true );

if ( ! is_array( $document ) || ! isset( $document['models'] ) || ! is_array( $document['models'] ) ) {
	fwrite( STDERR, "Source catalogue is not a {models: []} document.\n" );
	exit( 1 );
}

/**
 * Derive a display name from the source's notes, mirroring the WordPress layer.
 */
$display_name = static function ( string $model_id, string $notes ): string {
	$notes = trim( $notes );

	if ( '' === $notes ) {
		return $model_id;
	}

	if ( false !== strpos( $notes, ' - ' ) ) {
		$parts = explode( ' - ', $notes, 2 );

		if ( '' !== trim( $parts[0] ) ) {
			return trim( $parts[0] );
		}
	}

	if ( preg_match( '/^(.+?)\.\s+[^0-9]/', $notes, $match ) && '' !== trim( $match[1] ) ) {
		return trim( $match[1] );
	}

	return rtrim( $notes, '.' );
};

$statuses = array( 'active', 'preview', 'deprecated', 'retired' );
$models   = array();

foreach ( $document['models'] as $model ) {
	if ( ! is_array( $model ) || ! isset( $model['model_name'], $model['provider'] ) ) {
		continue;
	}

	$model_id = (string) $model['model_name'];
	$status   = (string) ( $model['status'] ?? 'active' );
	$fallback = (string) ( $model['fallback_model'] ?? '' );

	$models[] = array(
		'model_id'                   => $model_id,
		'name'                       => $display_name( $model_id, (string) ( $model['notes'] ?? '' ) ),
		'provider'                   => (string) $model['provider'],
		'context_window'             => max( 0, (int) ( $model['context_window'] ?? 0 ) ),
		'max_output_tokens'          => max( 0, (int) ( $model['max_output_tokens'] ?? 0 ) ),
		'tpm_limit'                  => max( 0, (int) ( $model['tpm_limit'] ?? 0 ) ),
		'rpm_limit'                  => max( 0, (int) ( $model['rpm_limit'] ?? 0 ) ),
		'tpd_limit'                  => max( 0, (int) ( $model['tpd_limit'] ?? 0 ) ),
		'rpd_limit'                  => max( 0, (int) ( $model['rpd_limit'] ?? 0 ) ),
		// The source is per 1K tokens; the runtime stores and displays per 1M.
		'input_cost_per_million'     => round( ( (float) ( $model['cost_per_1k_input_tokens'] ?? 0 ) ) * 1000, 6 ),
		'output_cost_per_million'    => round( ( (float) ( $model['cost_per_1k_output_tokens'] ?? 0 ) ) * 1000, 6 ),
		'fallback_model_id'          => '' !== $fallback ? $fallback : null,
		'status'                     => in_array( $status, $statuses, true ) ? $status : 'active',
		'sunset_date'                => '' !== (string) ( $model['sunset_date'] ?? '' ) ? (string) $model['sunset_date'] : null,
		'supports_streaming'         => (bool) ( $model['supports_streaming'] ?? false ),
		'supports_function_calling'  => (bool) ( $model['supports_function_calling'] ?? false ),
		'supports_vision'            => (bool) ( $model['supports_vision'] ?? false ),
		'tier'                       => '' !== (string) ( $model['tier'] ?? '' ) ? (string) $model['tier'] : null,
		'notes'                      => '' !== (string) ( $model['notes'] ?? '' ) ? (string) $model['notes'] : null,
	);
}

usort(
	$models,
	static function ( array $a, array $b ): int {
		return array( $a['provider'], $a['model_id'] ) <=> array( $b['provider'], $b['model_id'] );
	}
);

$output = array(
	'_license'          => 'Model identifiers, limits and prices are factual reference data derived from the NV oOS WordPress catalogue (GPL-3.0-or-later, includes/data/model-catalog.json). Generated for the MIT Laravel runtime; regenerate with bin/generate-laravel-model-catalog.php.',
	'source_version'    => (string) ( $document['version'] ?? '' ),
	'source_updated_at' => (string) ( $document['updated_at'] ?? '' ),
	'models'            => $models,
);

$json = json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

if ( false === $json ) {
	fwrite( STDERR, "Could not encode the catalogue.\n" );
	exit( 1 );
}

file_put_contents( $target, $json . "\n" );

$providers = array_count_values( array_column( $models, 'provider' ) );
ksort( $providers );

printf( "Wrote %d models to %s\n", count( $models ), $target );

foreach ( $providers as $provider => $count ) {
	printf( "  %-14s %d\n", $provider, $count );
}
