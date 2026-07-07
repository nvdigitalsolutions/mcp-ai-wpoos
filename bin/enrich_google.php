<?php
/**
 * Auto-enrich Places CPT using Google Places API.
 * Fills ratings, place IDs, phones, websites, coordinates.
 *
 * Run: wp --user=1 eval-file enrich_google.php
 */

$dry_run = false;
if ($dry_run) { WP_CLI::log(WP_CLI::colorize('%Y══ DRY RUN ══%n')); }

$batch_size = 10;
$sleep      = 1; // seconds between API calls

// Progress tracking
$progress_key = '_place_google_enrich_progress';
$progress     = get_option($progress_key, array());
$enriched_ids = isset($progress['enriched_ids']) ? $progress['enriched_ids'] : array();
$total_done   = isset($progress['total']) ? absint($progress['total']) : 0;

// ═══════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════

if (!class_exists('WP_MCP_AI_Google_Maps_Client')) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-google-maps-client.php';
}

function get_api_key() {
	static $key = null;
	if (null === $key) {
		$maps = new WP_MCP_AI_Google_Maps_Client();
		$key  = $maps->get_api_key();
	}
	return $key;
}

function places_text_search($query) {
	$maps = new WP_MCP_AI_Google_Maps_Client();
	$result = $maps->text_search($query, array('language' => 'en'));
	if (is_wp_error($result) || empty($result['results'])) {
		return null;
	}
	return $result['results'][0];
}

function places_details($place_id) {
	$api_key = get_api_key();
	if (empty($api_key)) return null;

	$url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query(array(
		'place_id' => $place_id,
		'fields'   => 'name,formatted_phone_number,website,rating,price_level,international_phone_number',
		'key'      => $api_key,
		'language' => 'en',
	));

	$response = wp_remote_get($url, array('timeout' => 10));
	if (is_wp_error($response)) return null;

	$body = json_decode(wp_remote_retrieve_body($response), true);
	if (empty($body['result'])) return null;

	return $body['result'];
}

// ═══════════════════════════════════════════════════════════
// Find places needing enrichment (real locations only)
// ═══════════════════════════════════════════════════════════

$real_types = array('attraction', 'city', 'hotel', 'experience');
$places = get_posts(array(
	'post_type'      => 'mcp_ai_place',
	'posts_per_page' => $batch_size,
	'post_status'    => 'publish',
	'tax_query'      => array(array(
		'taxonomy' => 'mcp_ai_place_type',
		'field'    => 'slug',
		'terms'    => $real_types,
		'operator' => 'IN',
	)),
	'meta_query'     => array(
		'relation' => 'OR',
		array('key' => '_place_google_place_id', 'compare' => 'NOT EXISTS'),
		array('key' => '_place_rating', 'compare' => 'NOT EXISTS'),
		array('key' => '_place_phone', 'compare' => 'NOT EXISTS'),
	),
	'post__not_in'   => $enriched_ids,
	'orderby'        => 'ID',
	'order'          => 'ASC',
	'no_found_rows'  => true,
));

if (empty($places)) {
	WP_CLI::success('All real locations enriched!');
	exit(0);
}

WP_CLI::log(sprintf("Found %d places to enrich.\n", count($places)));

// ═══════════════════════════════════════════════════════════
// Process
// ═══════════════════════════════════════════════════════════

$stats = array(
	'processed'     => 0,
	'enriched'      => 0,
	'ratings'       => 0,
	'place_ids'     => 0,
	'coords'        => 0,
	'phones'        => 0,
	'websites'      => 0,
	'updated'       => 0,
	'not_found'     => 0,
	'errors'        => 0,
);

foreach ($places as $place) {
	$stats['processed']++;
	$name = $place->post_title;

	// Get city for better search context
	$components = get_post_meta($place->ID, '_place_address_components', true);
	$city = is_array($components) && !empty($components['city']) ? $components['city'] : '';

	// Build search query
	$query = $name;
	if (!empty($city) && false === stripos($query, $city)) {
		$query .= ' ' . $city;
	}
	$query .= ' Sri Lanka';

	WP_CLI::log(sprintf('[%d/%d] %s', $stats['processed'], count($places), $name));

	if ($dry_run) {
		WP_CLI::log('  • Would search: ' . $query);
		$stats['enriched']++;
		continue;
	}

	// ── Step 1: Text Search ──
	$result = places_text_search($query);

	if (!$result) {
		$stats['not_found']++;
		WP_CLI::warning('  ✗ Not found in Places API');
		sleep(1);
		continue;
	}

	$added = array();
	$id = $place->ID;

	// Place ID
	if (empty(get_post_meta($id, '_place_google_place_id', true)) && !empty($result['place_id'])) {
		update_post_meta($id, '_place_google_place_id', $result['place_id']);
		$stats['place_ids']++;
		$added[] = 'place_id';
	}

	// Rating
	if (empty(get_post_meta($id, '_place_rating', true)) && !empty($result['rating'])) {
		update_post_meta($id, '_place_rating', floatval($result['rating']));
		$stats['ratings']++;
		$added[] = 'rating:' . $result['rating'];
	}

	// Coordinates from search result
	if (empty(get_post_meta($id, '_place_latitude', true)) && !empty($result['geometry']['location'])) {
		update_post_meta($id, '_place_latitude', $result['geometry']['location']['lat']);
		update_post_meta($id, '_place_longitude', $result['geometry']['location']['lng']);
		$stats['coords']++;
		$added[] = 'coords';
	}

	// ── Step 2: Place Details (phone, website) ──
	if (!empty($result['place_id'])) {
		sleep($sleep); // Rate limit

		$details = places_details($result['place_id']);
		if ($details) {
			if (empty(get_post_meta($id, '_place_phone', true)) && !empty($details['formatted_phone_number'])) {
				update_post_meta($id, '_place_phone', $details['formatted_phone_number']);
				$stats['phones']++;
				$added[] = 'phone';
			}
			if (empty(get_post_meta($id, '_place_website', true)) && !empty($details['website'])) {
				update_post_meta($id, '_place_website', $details['website']);
				$stats['websites']++;
				$added[] = 'website';
			}
			if (empty(get_post_meta($id, '_place_price_level', true)) && isset($details['price_level'])) {
				update_post_meta($id, '_place_price_level', absint($details['price_level']));
				$added[] = 'price:' . $details['price_level'];
			}
		}
	}

	if (!empty($added)) {
		$stats['enriched']++;
		WP_CLI::log('  ✓ +' . implode(', ', $added));
	} else {
		$stats['updated']++;
	}

	// Periodic cleanup
	if (0 === $stats['processed'] % 5) {
		wp_cache_flush();
	}

	sleep($sleep);
}

// ═══════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════
WP_CLI::log('');
WP_CLI::log(WP_CLI::colorize('%G' . str_repeat('─', 50) . '%n'));
WP_CLI::log(sprintf('  Processed:    %d', $stats['processed']));
WP_CLI::log(sprintf('  Enriched:     %d', $stats['enriched']));
WP_CLI::log(sprintf('  Not found:    %d', $stats['not_found']));
WP_CLI::log(sprintf('  Errors:       %d', $stats['errors']));
WP_CLI::log('');
WP_CLI::log(sprintf('  Place IDs:    %d', $stats['place_ids']));
WP_CLI::log(sprintf('  Ratings:      %d', $stats['ratings']));
WP_CLI::log(sprintf('  Coordinates:  %d', $stats['coords']));
WP_CLI::log(sprintf('  Phones:       %d', $stats['phones']));
WP_CLI::log(sprintf('  Websites:     %d', $stats['websites']));

if ($dry_run) {
	WP_CLI::success('Dry run complete.');
} else {
	WP_CLI::success(sprintf('Enriched %d places.', $stats['enriched']));
}
