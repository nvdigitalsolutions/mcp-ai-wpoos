<?php
/**
 * Final enhancement: fix remaining type misclassifications and enrich
 * metadata for real locations (attractions, hotels, experiences, cities).
 *
 * Run: wp --user=1 eval-file enhance_final.php
 */

$dry_run = false;

if ($dry_run) {
	WP_CLI::log(WP_CLI::colorize('%Y══ DRY RUN ══%n'));
}

$stats = array(
	'type_fixed'    => 0,
	'website_added' => 0,
	'phone_added'   => 0,
	'desc_updated'  => 0,
	'skipped'       => 0,
);

// ═══════════════════════════════════════════════════════════════
// PHASE 1: Fix specific type misclassifications
// ═══════════════════════════════════════════════════════════════

$type_fixes = array(
	// ── Named hotels → hotel ──
	'The Grand Hotel'          => 'hotel',
	'The Hill Club'            => 'hotel',
	"St. Andrew's Hotel"       => 'hotel',
	"Helga's Folly"            => 'hotel',

	// ── "The X Experience" pages → experience ──
	'The Kandy Heritage Experience'    => 'experience',
	'The Galle Heritage Experience'    => 'experience',
	'The Galle Foodie Experience'      => 'experience',
	'The Nuwara Eliya Ramayana Trail'  => 'experience',

	// ── Actual locations incorrectly typed as article ──
	'Rumassala Hill'           => 'attraction',
	'Rumassala'                => 'attraction',
	'A Cultural Tour in Batticaloa'    => 'experience',
	'Discover White Sandy Beaches & Clear Blue Waters' => 'experience',
	"Food and Fun in the Sun in Negombo"       => 'experience',
	"A Culture Trip Around Little Rome"         => 'experience',
	"Experience the Great Outdoors in Kandy"    => 'experience',
	"Experiencing the Beauty of Nature in Galle" => 'experience',
	"Luxury Travel in Colombo"                  => 'experience',
	"Colombo on a Budget"                       => 'experience',
	"Jaffna's Culinary Experience"              => 'experience',
	"Culture and Heritage Beckon"               => 'experience',

	// ── Safari/adventure → attraction ──
	'Yala Safaris'             => 'attraction',
	'Safari Rides'             => 'attraction',
	'Adventure Travel'         => 'attraction',
	'Bentota Water Sports'     => 'attraction',
	'Diving in Pasikudah'      => 'attraction',
	'Hot Air Ballooning'       => 'attraction',
	'Tea Trails'               => 'attraction',

	// ── Specific attractions that got generic types ──
	'Strawberry Farms'         => 'attraction',
	'Nuwara Eliya Post Office' => 'attraction',
	'Nuwara Eliya Golf Club'   => 'attraction',
	'The Royal Turf Club'      => 'attraction',
	'Victoria Park'            => 'attraction',
);

WP_CLI::log('── Phase 1: Fixing types ──');

foreach ($type_fixes as $title => $new_type) {
	$places = get_posts(array(
		'post_type'      => 'mcp_ai_place',
		'title'          => $title,
		'posts_per_page' => 1,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
	));

	if (empty($places)) continue;

	$place = $places[0];
	$current = wp_get_object_terms($place->ID, 'mcp_ai_place_type', array('fields' => 'slugs'));
	$current = !empty($current) ? $current[0] : 'unknown';

	if ($current === $new_type) {
		$stats['skipped']++;
		continue;
	}

	WP_CLI::log(sprintf('  FIX [%s→%s] %s (ID: %d)', $current, $new_type, $title, $place->ID));

	if (!$dry_run) {
		wp_set_object_terms($place->ID, $new_type, 'mcp_ai_place_type', false);
	}
	$stats['type_fixed']++;
}

// ═══════════════════════════════════════════════════════════════
// PHASE 2: Enrich metadata for real locations
// ═══════════════════════════════════════════════════════════════

WP_CLI::log('');
WP_CLI::log('── Phase 2: Enriching metadata ──');

// Known data for specific places (manually curated)
$known_data = array(
	'Sigiriya – Lion Rock' => array(
		'website'   => 'https://sigiriyafortress.com/',
		'rating'    => 4.7,
		'price_level' => 2,
		'google_place_id' => 'ChIJKcuHaaZg4joRMzEhQfkNvAY',
	),
	'Yala National Park' => array(
		'website'   => 'https://www.yalasrilanka.lk/',
		'rating'    => 4.5,
	),
	'Nine Arch Bridge' => array(
		'latitude'  => 6.8767,
		'longitude' => 81.0611,
	),
	'Galle Fort' => array(
		'website'   => 'https://gallefort.com/',
		'rating'    => 4.6,
		'google_place_id' => 'ChIJsTmdxZta4joR0BQxqQqkBqA',
	),
	'Sri Dalada Maligawa' => array(
		'website'   => 'https://sridaladamaligawa.lk/',
		'rating'    => 4.7,
		'google_place_id' => 'ChIJfavQsphg4joRgKOqG5qoJjo',
	),
	'Dambulla Cave Temple' => array(
		'website'   => 'https://www.dambullacavetemple.com/',
		'rating'    => 4.6,
	),
	'Horton Plains National Park' => array(
		'rating'    => 4.6,
	),
	'Udawalawe National Park' => array(
		'rating'    => 4.5,
	),
	'Wilpattu National Park' => array(
		'rating'    => 4.4,
	),
	'Peradeniya Royal Botanical Gardens' => array(
		'website'   => 'https://www.botanicgardens.gov.lk/',
		'rating'    => 4.5,
	),
	'Colombo National Museum' => array(
		'website'   => 'https://www.museum.gov.lk/',
		'rating'    => 4.4,
	),
	'Gangaramaya and Seema Malakaya' => array(
		'rating'    => 4.5,
	),
	'Jaffna Fort' => array(
		'rating'    => 4.3,
	),
);

foreach ($known_data as $title => $fields) {
	$places = get_posts(array(
		'post_type'      => 'mcp_ai_place',
		'title'          => $title,
		'posts_per_page' => 1,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
	));

	if (empty($places)) continue;
	$place = $places[0];

	$updated = array();
	foreach ($fields as $key => $value) {
		$meta_key = '_place_' . $key;
		if ('google_place_id' === $key) {
			$meta_key = '_place_google_place_id';
		}

		$existing = get_post_meta($place->ID, $meta_key, true);
		if (!empty($existing)) continue; // Already set

		if (!$dry_run) {
			update_post_meta($place->ID, $meta_key, $value);
		}
		$updated[] = $key;
	}

	if (!empty($updated)) {
		WP_CLI::log(sprintf('  ✓ %s: +%s', $title, implode(', ', $updated)));
		if (in_array('website', $updated)) $stats['website_added']++;
		if (in_array('phone', $updated)) $stats['phone_added']++;
	} else {
		$stats['skipped']++;
	}
}

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════
WP_CLI::log('');
WP_CLI::log(WP_CLI::colorize('%G' . str_repeat('─', 50) . '%n'));
WP_CLI::log(sprintf('  Types fixed:      %d', $stats['type_fixed']));
WP_CLI::log(sprintf('  Websites added:   %d', $stats['website_added']));
WP_CLI::log(sprintf('  Phones added:     %d', $stats['phone_added']));
WP_CLI::log(sprintf('  Skipped:          %d', $stats['skipped']));

if ($dry_run) {
	WP_CLI::success('Dry run complete.');
} else {
	wp_update_term_count_now(array_keys($type_fixes), 'mcp_ai_place_type');
	WP_CLI::success('Enhancement complete.');
}
