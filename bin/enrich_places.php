<?php
/**
 * Batch Enrich Places CPT with Missing Data
 *
 * Fills in missing coordinates using free geocoding services.
 * Designed to be run incrementally as a WP-CLI command or cron job.
 *
 * ## USAGE
 *
 *   wp --user=1 eval-file enrich_places.php -- --dry-run
 *   wp --user=1 eval-file enrich_places.php -- --batch-size=10 --limit=50
 *   wp --user=1 eval-file enrich_places.php -- --resume --sleep=2
 *
 * ## OPTIONS
 *
 *   --batch-size=<N>    Places per run (default: 10)
 *   --limit=<N>         Max places to process (default: 50)
 *   --dry-run           Preview without updating
 *   --resume            Skip already-enriched places
 *   --sleep=<N>         Seconds between API calls (default: 1)
 *   --provider=<name>   Geocoding provider: google, nominatim, auto (default: auto)
 *
 * @since 1.4.2
 */

// ── Config loading ──────────────────────────────────────────
// Create wp-content/uploads/enrich_places_config.json to override defaults.
// Example: {"dry_run":true,"batch_size":5,"limit":20,"sleep":2,"provider":"nominatim"}
$config_file = WP_CONTENT_DIR . '/uploads/enrich_places_config.json';
$assoc_args = array();
if ( file_exists( $config_file ) ) {
	$config_json = file_get_contents( $config_file );
	$decoded     = json_decode( $config_json, true );
	if ( is_array( $decoded ) ) {
		$assoc_args = $decoded;
		WP_CLI::log( 'Loaded config from ' . $config_file );
	}
}

$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 10;
$limit      = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 50;
$dry_run    = isset( $assoc_args['dry-run'] );
$resume     = isset( $assoc_args['resume'] );
$sleep      = isset( $assoc_args['sleep'] ) ? max( 1, absint( $assoc_args['sleep'] ) ) : 1;
$provider   = isset( $assoc_args['provider'] ) ? $assoc_args['provider'] : 'auto';

if ( $dry_run ) {
	WP_CLI::log( WP_CLI::colorize( '%Y══ DRY RUN (no changes) ══%n' ) );
}

// ── Progress tracking ───────────────────────────────────────
$progress_key = '_place_enrichment_progress';
$progress     = $resume ? get_option( $progress_key, array() ) : array();
$last_id      = isset( $progress['last_id'] ) ? absint( $progress['last_id'] ) : 0;
$total_done   = isset( $progress['total'] ) ? absint( $progress['total'] ) : 0;

// ── Stats ───────────────────────────────────────────────────
$stats = array(
	'processed'       => 0,
	'enriched'        => 0,
	'coords_added'    => 0,
	'google_place_ids' => 0,
	'skipped'         => 0,
	'errors'          => 0,
	'rate_limited'    => 0,
);

// ── Determine geocoding provider ────────────────────────────
function get_geocoding_provider( $preferred ) {
	if ( 'google' === $preferred ) {
		$key = function_exists( 'wp_mcp_ai_get_api_key' )
			? wp_mcp_ai_get_api_key( 'google_maps_api_key', '' )
			: get_option( 'wp_mcp_ai_google_maps_api_key', '' );
		if ( empty( $key ) && class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			$key = WP_MCP_AI_Credential_Resolver::get_api_key( 'google_maps' ) ?? '';
		}
		return ! empty( $key ) ? 'google' : 'nominatim';
	}
	if ( 'nominatim' === $preferred ) {
		return 'nominatim';
	}
	// auto: prefer google if key exists, otherwise nominatim
	return get_geocoding_provider( 'google' );
}

$geocoder = get_geocoding_provider( $provider );
WP_CLI::log( sprintf( 'Geocoding provider: %s | Batch: %d | Limit: %d | Sleep: %ds',
	$geocoder, $batch_size, $limit, $sleep ) );

// ── Geocoding functions ─────────────────────────────────────

/**
 * Geocode an address using Nominatim (OpenStreetMap).
 *
 * @param string $address Full address string.
 * @return array|null {lat, lng, place_id, type, display_name} or null.
 */
function geocode_nominatim( $address ) {
	$url = 'https://nominatim.openstreetmap.org/search?' . http_build_query( array(
		'q'              => $address,
		'format'         => 'json',
		'limit'          => 1,
		'addressdetails' => 1,
	) );

	$response = wp_remote_get( $url, array(
		'headers' => array(
			'User-Agent' => 'NVoOS-Places-Enrichment/1.0',
			'Accept'     => 'application/json',
		),
		'timeout' => 15,
	) );

	if ( is_wp_error( $response ) ) {
		return null;
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( empty( $data ) || ! is_array( $data ) ) {
		return null;
	}

	$result = $data[0];
	return array(
		'latitude'    => floatval( $result['lat'] ),
		'longitude'   => floatval( $result['lon'] ),
		'place_id'    => isset( $result['place_id'] ) ? $result['place_id'] : '',
		'type'        => isset( $result['type'] ) ? $result['type'] : '',
		'display_name' => isset( $result['display_name'] ) ? $result['display_name'] : '',
	);
}

/**
 * Geocode using Google Maps.
 *
 * @param string $address Full address string.
 * @return array|null {lat, lng} or null.
 */
function geocode_google( $address ) {
	static $client = null;
	if ( ! class_exists( 'WP_MCP_AI_Google_Maps_Client' ) ) {
		return null;
	}
	if ( null === $client ) {
		$client = new WP_MCP_AI_Google_Maps_Client();
	}

	$result = $client->geocode( $address );
	if ( is_wp_error( $result ) ) {
		return null;
	}

	if ( ! empty( $result['latitude'] ) && ! empty( $result['longitude'] ) ) {
		return array(
			'latitude'  => floatval( $result['latitude'] ),
			'longitude' => floatval( $result['longitude'] ),
		);
	}

	return null;
}

/**
 * Geocode a place by its name + city + country.
 *
 * @param string  $provider 'google' or 'nominatim'.
 * @param WP_Post $place    Place post object.
 * @return array|null Coordinates + optional place_id.
 */
/**
 * Check if a title is too generic to geocode.
 */
function is_generic_title( $title ) {
	$generic_patterns = array(
		'/^Contact Us$/i', '/^About Us$/i', '/^Home$/i',
		'/^Food$/i', '/^Destinations$/i', '/^Experiences$/i',
		'/^Hotels in/i', '/^Budget Hotels/i', '/^Luxury Hotels/i',
		'/^Boutique Hotels/i', '/^Boutique Luxury/i',
		'/^Adventure Experience/i', '/^Spiritual Experience/i',
		'/^Cultural Experience/i', '/^Beach Experience/i',
		'/^Rest and Relaxation/i',
	);
	foreach ( $generic_patterns as $pattern ) {
		if ( preg_match( $pattern, $title ) ) {
			return true;
		}
	}
	return false;
}

function geocode_place( $provider, $place ) {
	$name    = $place->post_title;
	$city    = '';
	$country = '';

	$components = get_post_meta( $place->ID, '_place_address_components', true );
	if ( is_array( $components ) ) {
		$city    = isset( $components['city'] ) ? $components['city'] : '';
		$country = isset( $components['country'] ) ? $components['country'] : '';
	}

	// Build address: "Place Name, City, Country"
	$address = $name;
	if ( ! empty( $city ) && false === stripos( $address, $city ) ) {
		$address .= ', ' . $city;
	}
	if ( ! empty( $country ) ) {
		$address .= ', ' . $country;
	}

	if ( 'google' === $provider ) {
		return geocode_google( $address );
	}

	return geocode_nominatim( $address );
}

// ── Build query ─────────────────────────────────────────────
$meta_query = array(
	'relation' => 'OR',
	array(
		'key'     => '_place_latitude',
		'compare' => 'NOT EXISTS',
	),
);
// Also include places with empty/zero coordinates
$meta_query[] = array(
	'key'     => '_place_latitude',
	'value'   => '0',
	'compare' => '=',
);

$query_args = array(
	'post_type'      => 'mcp_ai_place',
	'posts_per_page' => min( $batch_size, $limit ),
	'post_status'    => 'publish',
	'meta_query'     => $meta_query,
	'orderby'        => 'ID',
	'order'          => 'ASC',
	'no_found_rows'  => true,
);

	// Resume from last ID — skip already-processed places.
	// Uses both ID ordering and an explicit exclude list for
	// places where geocoding permanently failed.
	$failed_ids = isset( $progress['failed_ids'] ) ? $progress['failed_ids'] : array();

	if ( $resume && $last_id > 0 ) {
		add_filter( 'posts_where', function( $where ) use ( $last_id ) {
			global $wpdb;
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $last_id );
		}, 10, 1 );
	}

	// Exclude places that already failed geocoding
	if ( ! empty( $failed_ids ) ) {
		$query_args['post__not_in'] = $failed_ids;
	}

	$places = get_posts( $query_args );

	// Clean up the filter
	if ( $resume && $last_id > 0 ) {
		remove_all_filters( 'posts_where' );
	}

if ( empty( $places ) ) {
	WP_CLI::success( 'All places have coordinates! Nothing to enrich.' );
	delete_option( $progress_key );
	exit( 0 );
}

$remaining = count( $places );
WP_CLI::log( sprintf( "Found %d places to geocode.\n", $remaining ) );

// ── Geocode helper ──────────────────────────────────────────
if ( ! class_exists( 'WP_MCP_AI_Place_Helper' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/helpers/class-wp-mcp-ai-place-helper.php';
}

// ── Process ─────────────────────────────────────────────────
$max_id = 0;

foreach ( $places as $place ) {
	$stats['processed']++;
	$max_id    = max( $max_id, $place->ID );
	$has_coords = (bool) get_post_meta( $place->ID, '_place_latitude', true );

	$prefix = sprintf( '[%d/%d]', $stats['processed'], $remaining );

	if ( $has_coords ) {
		$stats['skipped']++;
		WP_CLI::log( "$prefix SKIP {$place->post_title} (has coords)" );
		continue;
	}

	WP_CLI::log( "$prefix {$place->post_title} (ID: {$place->ID})" );

	if ( ! $dry_run ) {
		$result = geocode_place( $geocoder, $place );

		if ( ! empty( $result['latitude'] ) && ! empty( $result['longitude'] ) ) {
			update_post_meta( $place->ID, '_place_latitude', $result['latitude'] );
			update_post_meta( $place->ID, '_place_longitude', $result['longitude'] );

			// Store Nominatim place_id as a reference
			if ( ! empty( $result['place_id'] ) && empty( get_post_meta( $place->ID, '_place_google_place_id', true ) ) ) {
				update_post_meta( $place->ID, '_place_osm_place_id', $result['place_id'] );
			}

			$stats['coords_added']++;
			$stats['enriched']++;
			WP_CLI::log( "  \xE2\x9C\x93 Lat: {$result['latitude']}, Lng: {$result['longitude']}" );

			if ( ! empty( $result['display_name'] ) ) {
				WP_CLI::log( "    {$result['display_name']}" );
			}
		} else {
			$stats['errors']++;
			$failed_ids[] = $place->ID; // Don't retry on next run
			WP_CLI::warning( "  \xE2\x9C\x97 Geocoding failed for: {$place->post_title}" );
		}
	} else {
		$stats['coords_added']++;
		WP_CLI::log( "  \xE2\x80\xA2 Would geocode (dry-run)" );
	}

	// Rate limiting
	if ( $sleep > 0 && ! $has_coords ) {
		sleep( $sleep );
	}

	// Periodic cleanup
	if ( 0 === $stats['processed'] % 5 ) {
		wp_cache_flush();
		if ( function_exists( 'wp_suspend_cache_addition' ) ) {
			wp_suspend_cache_addition( false );
		}
	}
}

// ── Save progress ───────────────────────────────────────────
	if ( ! $dry_run ) {
		$progress['last_id']    = $max_id;
		$progress['total']      = $total_done + $stats['coords_added'];
		$progress['updated']    = gmdate( 'Y-m-d H:i:s' );
		$progress['provider']   = $geocoder;
		$progress['failed_ids'] = array_unique( $failed_ids );
		update_option( $progress_key, $progress, false );
	}

// ── Summary ─────────────────────────────────────────────────
WP_CLI::log( '' );
WP_CLI::log( WP_CLI::colorize( '%G' . str_repeat( '─', 50 ) . '%n' ) );
WP_CLI::log( WP_CLI::colorize( '%G── Enrichment Complete ──%n' ) );
WP_CLI::log( sprintf( '  Processed:       %d', $stats['processed'] ) );
WP_CLI::log( sprintf( '  Enriched:        %d', $stats['enriched'] ) );
WP_CLI::log( sprintf( '  Coordinates:     %d', $stats['coords_added'] ) );
WP_CLI::log( sprintf( '  Skipped:         %d', $stats['skipped'] ) );
WP_CLI::log( sprintf( '  Errors:          %d', $stats['errors'] ) );
WP_CLI::log( sprintf( '  Provider:        %s', $geocoder ) );

if ( $total_done > 0 || $stats['enriched'] > 0 ) {
	$grand_total = $total_done + $stats['enriched'];
	WP_CLI::log( sprintf( '  Grand total:     %d', $grand_total ) );
}

if ( ! $dry_run && $stats['enriched'] > 0 ) {
	WP_CLI::log( '' );
	WP_CLI::log( sprintf(
		'  Next: wp --user=1 eval-file enrich_places.php -- --resume --batch-size=%d',
		$batch_size
	) );
}

if ( $dry_run ) {
	WP_CLI::success( 'Dry run complete. Remove --dry-run to apply.' );
} else {
	WP_CLI::success( sprintf( 'Enriched %d places.', $stats['enriched'] ) );
}
