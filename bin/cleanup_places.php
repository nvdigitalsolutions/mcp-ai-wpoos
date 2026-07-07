<?php
/**
 * Clean up and enrich Places CPT — fix miscategorized content,
 * remove non-places, and add missing metadata to real locations.
 *
 * Run: wp --user=1 eval-file cleanup_places.php
 */

// ── Config ──────────────────────────────────────────────────
$dry_run = false; // Set to false to apply changes

if ($dry_run) {
	WP_CLI::log(WP_CLI::colorize('%Y══ DRY RUN (no changes) ══%n'));
}

// ── Stats ───────────────────────────────────────────────────
$stats = array(
	'deleted'       => 0,
	'retyped'       => 0,
	'type_added'    => 0,
	'skipped'       => 0,
);

// ── Ensure taxonomy terms exist ─────────────────────────────
$new_types = array('guide', 'article', 'category-page');
foreach ($new_types as $type) {
	if (!term_exists($type, 'mcp_ai_place_type')) {
		if (!$dry_run) {
			wp_insert_term(ucfirst($type), 'mcp_ai_place_type', array('slug' => $type));
		}
		$stats['type_added']++;
		WP_CLI::log("+ Created place type: {$type}");
	}
}

// ── Classification rules ────────────────────────────────────
// Each rule: array of patterns → action
// Priority: first match wins

$rules = array(

	// ── DELETE: Non-place pages ──
	array(
		'name'    => 'delete_non_places',
		'action'  => 'delete',
		'titles'  => array(
			'/^About Us$/i',
			'/^Contact Us$/i',
			'/^Home$/i',
			'/^Food$/i',
			'/^Destinations$/i',
			'/^Experiences$/i',
			'/^Featured Tales$/i',
		),
	),

	// ── RE-TYPE: Category/listing pages → category-page ──
	array(
		'name'    => 'category_pages',
		'action'  => 'retype',
		'new_type' => 'category-page',
		'titles'  => array(
			'/^Hotels in /i',
			'/^Attractions in /i',
			'/^Experiences in /i',
			'/^Experiences In /i',
			'/^Budget Hotels$/i',
			'/^Luxury Hotels$/i',
			'/^Boutique Hotels$/i',
			'/^Boutique Luxury$/i',
			'/^Adventure Experience$/i',
			'/^Spiritual Experience$/i',
			'/^Cultural Experience$/i',
			'/^Beach Experience$/i',
			'/^Rest and Relaxation/i',
			'/^Cultural & Historic Experience$/i',
			'/^Colonial Heritage in /i',
			'/^Ceylon Tea Experience in /i',
			'/^Hiking Experiences in /i',
		),
	),

	// ── RE-TYPE: Travel guide pages → guide ──
	array(
		'name'    => 'travel_guides',
		'action'  => 'retype',
		'new_type' => 'guide',
		'titles'  => array(
			'/^Visiting Sri Lanka in /i',
			'/^Explore .+ with Your Family$/i',
			'/^Family Time in /i',
			'/^Family Fun in /i',
			'/^Solo Travel in /i',
			'/^Solo Time in /i',
			'/^Going [Ss]olo in /i',
			'/^Travelling Solo in /i',
			'/^Tour .+ with Your Partner$/i',
			'/^A Couple.*Guide to /i',
			'/^A Couple.*Getaway in /i',
			'/^Couple.*Time in /i',
			'/^Couples Travelling in /i',
			'/^Experience .+ with Your Partner$/i',
			'/^.+ with Your Significant Other$/i',
			'/^.+ for the Business Traveler$/i',
			'/^.+ for the Family$/i',
			'/^.+ for Solo Travelers$/i',
			'/^.+ for the Foodie$/i',
			'/^Spending time in .+ with the Family$/i',
			'/^A Kid Friendly Holiday in /i',
			'/^Can Sri Lanka be toured by Railways\?$/i',
			'/^What are the /i',
			'/^Flights to Sri Lanka$/i',
			'/^Hotels in Sri Lanka$/i',
		),
	),

	// ── RE-TYPE: City guides with "Tales of" pattern ──
	// These are already type "tale" from URL matching, keep them

	// ── RE-TYPE: "Plan Your Holiday" pages → itinerary ──
	// These should already be "itinerary" from URL matching

	// ── RE-TYPE: Historical/cultural overview pages → article ──
	array(
		'name'    => 'historical_articles',
		'action'  => 'retype',
		'new_type' => 'article',
		'titles'  => array(
			'/^The Kingdom that Set the Course/i',
			'/^The Doorway to The Southern Coast/i',
			'/^The Intriguing Past of /i',
			'/^A Vibrant & Bustling City/i',
			'/^Edifices of Wonder/i',
			'/^A Bustling Capital of /i',
			'/^A City of Ethnographic Diversity$/i',
			'/^The City that Fought for Autonomy/i',
			'/^The Home of an Ancient Relic/i',
			'/^A Journey Through the Last Citadel/i',
			'/^Religion & Culture Combine/i',
			'/^The Battle for /i',
			'/^Vestiges of .+ Colonial Past$/i',
			'/^The Legend of King Ravana/i',
			'/^Journey Through the Ancient Northern Kingdom$/i',
			'/^Wisdom of the Enlightened One/i',
			'/^Let the Ramayana Trail/i',
			'/^Sri Lanka.*Little England/i',
			'/^Fate of Angampora/i',
			'/^Angampora/i',
			'/^Garden Culture/i',
			'/^The .+ Experience$/i',
			'/^Discover White Sandy Beaches/i',
			'/^A Cultural Tour in /i',
			'/^Rumassala /i',
			'/^Galle Fort Walk/i',
			'/^Wildlife Roams Free/i',
			'/^Remnants of Early Civilisation/i',
			'/^The Magnificent Palace in the Sky$/i',
			'/^The Ancient Rock Monastery$/i',
			'/^Take a Stroll in the Mesmerizing Gardens/i',
			'/^Political Turmoil/i',
			'/^The Art, Sculpture and Poetry of /i',
		),
	),

	// ── RE-TYPE: Flight route pages → article ──
	array(
		'name'    => 'flight_routes',
		'action'  => 'retype',
		'new_type' => 'article',
		'titles'  => array(
			'/^\w+ to Colombo$/',
		),
	),

	// ── FIX: Safari/national park pages from "Experiences" type → attraction ──
	array(
		'name'    => 'safari_parks',
		'action'  => 'retype',
		'new_type' => 'attraction',
		'titles'  => array(
			'/^Yala Safaris$/',
			'/^Whale Watching$/',
			'/^Whale Watching in /',
			'/^Bird Watching in /',
			'/^Wildlife Safari$/',
			'/^Surfing in Sri Lanka$/',
			'/^Surfing in /',
			'/^Diving and Snorkelling$/',
			'/^The Best Diving/i',
			'/^Ayurveda$/',
			'/^Ayurveda Spas$/',
			'/^Ayurveda Hotels/i',
		),
		'also_check_type' => 'experience', // Only retype if currently "experience"
	),
);

// ── Fetch all places ────────────────────────────────────────
$all_places = get_posts(array(
	'post_type'      => 'mcp_ai_place',
	'posts_per_page' => -1,
	'post_status'    => 'publish',
	'orderby'        => 'ID',
	'order'          => 'ASC',
	'no_found_rows'  => true,
));

WP_CLI::log(sprintf("Found %d places. Classifying...\n", count($all_places)));

// ── Apply rules ─────────────────────────────────────────────
foreach ($all_places as $place) {
	$title      = $place->post_title;
	$current_type = wp_get_object_terms($place->ID, 'mcp_ai_place_type', array('fields' => 'slugs'));
	$current_type = !empty($current_type) ? $current_type[0] : 'attraction';
	$source_url = get_post_meta($place->ID, '_place_source_url', true);

	$matched = false;

	foreach ($rules as $rule) {
		// Check title patterns
		$title_match = false;
		foreach ($rule['titles'] as $pattern) {
			if (preg_match($pattern, $title)) {
				$title_match = true;
				break;
			}
		}

		if (!$title_match) {
			continue;
		}

		// Check also_check_type constraint
		if (isset($rule['also_check_type']) && $current_type !== $rule['also_check_type']) {
			continue;
		}

		// Apply action
		switch ($rule['action']) {
			case 'delete':
				WP_CLI::log(sprintf("  DELETE [%s] %s (ID: %d)", $current_type, $title, $place->ID));
				if (!$dry_run) {
					wp_delete_post($place->ID, true);
				}
				$stats['deleted']++;
				break;

			case 'retype':
				if ($current_type !== $rule['new_type']) {
					WP_CLI::log(sprintf("  RETYPE [%s→%s] %s (ID: %d)", $current_type, $rule['new_type'], $title, $place->ID));
					if (!$dry_run) {
						wp_set_object_terms($place->ID, $rule['new_type'], 'mcp_ai_place_type', false);
					}
					$stats['retyped']++;
				} else {
					WP_CLI::log(sprintf("  OK [%s] %s", $current_type, $title));
				}
				break;
		}

		$matched = true;
		break;
	}

	if (!$matched) {
		$stats['skipped']++;
	}
}

// ── Summary ─────────────────────────────────────────────────
WP_CLI::log('');
WP_CLI::log(WP_CLI::colorize('%G' . str_repeat('─', 50) . '%n'));
WP_CLI::log(sprintf('  New types created: %d', $stats['type_added']));
WP_CLI::log(sprintf('  Deleted:           %d', $stats['deleted']));
WP_CLI::log(sprintf('  Retyped:           %d', $stats['retyped']));
WP_CLI::log(sprintf('  Skipped:           %d', $stats['skipped']));

if ($dry_run) {
	WP_CLI::success('Dry run complete. Set $dry_run = false to apply.');
} else {
	// Flush term counts
	wp_update_term_count_now(wp_get_object_terms(
		wp_list_pluck($all_places, 'ID'), 'mcp_ai_place_type', array('fields' => 'ids')
	), 'mcp_ai_place_type');
	WP_CLI::success('Cleanup complete. Run again to verify.');
}
