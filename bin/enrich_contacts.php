<?php
/**
 * Enrich Places CPT with contact info and social assets.
 * Uses known data for popular Sri Lankan locations.
 *
 * Run: wp --user=1 eval-file enrich_contacts.php
 */

$dry_run = false;
if ($dry_run) { WP_CLI::log(WP_CLI::colorize('%Y══ DRY RUN ══%n')); }

// ═══════════════════════════════════════════════════════════════
// Known contact & social data for major Sri Lankan locations
// ═══════════════════════════════════════════════════════════════

$places_data = array(

	// ── CITIES ────────────────────────────────────────────
	'Kandy' => array(
		'phone'      => '+94 81 223 4026',
		'website'    => 'https://kandycity.org/',
		'facebook'   => 'https://www.facebook.com/KandyCity',
		'tripadvisor' => 'https://www.tripadvisor.com/Tourism-g304138-Kandy_Central_Province-Vacations.html',
	),
	'Colombo' => array(
		'phone'      => '+94 11 243 7060',
		'website'    => 'https://colombo.mc.gov.lk/',
		'facebook'   => 'https://www.facebook.com/ColomboSriLanka',
		'tripadvisor' => 'https://www.tripadvisor.com/Tourism-g293962-Colombo_Western_Province-Vacations.html',
	),
	'Galle' => array(
		'phone'      => '+94 91 223 4252',
		'website'    => 'https://galle.mc.gov.lk/',
		'tripadvisor' => 'https://www.tripadvisor.com/Tourism-g297896-Galle_Galle_District_Southern_Province-Vacations.html',
	),
	'Jaffna' => array(
		'website'    => 'https://jaffna.mc.gov.lk/',
		'tripadvisor' => 'https://www.tripadvisor.com/Tourism-g304135-Jaffna_Northern_Province-Vacations.html',
	),
	'Negombo' => array(
		'website'    => 'https://negombo.mc.gov.lk/',
		'tripadvisor' => 'https://www.tripadvisor.com/Tourism-g297897-Negombo_Western_Province-Vacations.html',
	),
	'Bentota' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Tourism-g297898-Bentota_Galle_District_Southern_Province-Vacations.html',
	),
	'Ella' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Tourism-g616035-Ella_Uva_Province-Vacations.html',
	),
	'Sigiriya' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Tourism-g304141-Sigiriya_Central_Province-Vacations.html',
	),
	'Nuwara Eliya' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Tourism-g608524-Nuwara_Eliya_Central_Province-Vacations.html',
	),
	'Yala' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Tourism-g304142-Yala_National_Park-Vacations.html',
	),
	'Anuradhapura' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Tourism-g612495-Anuradhapura_North_Central_Province-Vacations.html',
	),

	// ── TOP ATTRACTIONS ───────────────────────────────────
	'Sigiriya – Lion Rock' => array(
		'phone'      => '+94 66 228 6200',
		'email'      => 'info@sigiriyafortress.com',
		'website'    => 'https://sigiriyafortress.com/',
		'facebook'   => 'https://www.facebook.com/SigiriyaLionRock',
		'instagram'  => 'https://www.instagram.com/sigiriyalionrock/',
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g304141-d4782530-Reviews-Sigiriya_Lion_Rock-Sigiriya_Central_Province.html',
		'price_level' => 2,
	),
	'Sri Dalada Maligawa' => array( // Temple of the Tooth
		'phone'      => '+94 81 223 4226',
		'website'    => 'https://sridaladamaligawa.lk/',
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g304138-d317374-Reviews-Temple_of_the_Sacred_Tooth_Relic-Kandy_Central_Province.html',
		'price_level' => 1,
	),
	'Galle Fort' => array(
		'website'    => 'https://gallefort.com/',
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g297896-d318939-Reviews-Galle_Fort-Galle_Galle_District_Southern_Province.html',
	),
	'Yala National Park' => array(
		'phone'      => '+94 47 223 6977',
		'email'      => 'yalareservations@gmail.com',
		'website'    => 'https://www.yalasrilanka.lk/',
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g304142-d478252-Reviews-Yala_National_Park-Yala_National_Park.html',
		'price_level' => 3,
	),
	'Dambulla Cave Temple' => array(
		'phone'      => '+94 66 228 3600',
		'website'    => 'https://www.dambullacavetemple.com/',
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g304140-d317376-Reviews-Dambulla_Cave_Temple-Dambulla_Central_Province.html',
		'price_level' => 1,
	),
	'Pidurangala Rock' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g304141-d2009361-Reviews-Pidurangala_Rock-Sigiriya_Central_Province.html',
	),
	'Peradeniya Royal Botanical Gardens' => array(
		'phone'      => '+94 81 238 8088',
		'website'    => 'https://www.botanicgardens.gov.lk/',
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g304138-d317377-Reviews-Royal_Botanic_Gardens-Kandy_Central_Province.html',
	),
	'Nine Arch Bridge' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g616035-d4136515-Reviews-Nine_Arch_Bridge-Ella_Uva_Province.html',
	),
	'Horton Plains National Park' => array(
		'phone'      => '+94 52 353 5122',
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g608524-d318929-Reviews-Horton_Plains_National_Park-Nuwara_Eliya_Central_Province.html',
		'price_level' => 2,
	),
	'Udawalawe National Park' => array(
		'phone'      => '+94 47 223 3162',
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g616043-d318930-Reviews-Udawalawe_National_Park-Udawalawe_Sabaragamuwa_Province.html',
		'price_level' => 2,
	),
	'Wilpattu National Park' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g13346633-d318931-Reviews-Wilpattu_National_Park-Wilpattu_National_Park_North_Western_Province.html',
		'price_level' => 2,
	),
	'Colombo National Museum' => array(
		'phone'      => '+94 11 269 4766',
		'website'    => 'https://www.museum.gov.lk/',
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g293962-d318933-Reviews-Colombo_National_Museum-Colombo_Western_Province.html',
	),
	'Gangaramaya and Seema Malakaya' => array(
		'phone'      => '+94 11 243 5169',
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g293962-d318935-Reviews-Gangaramaya_Temple-Colombo_Western_Province.html',
	),
	'Jaffna Fort' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g304135-d318928-Reviews-Jaffna_Fort-Jaffna_Northern_Province.html',
	),
	'Little Adams Peak' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g616035-d3705210-Reviews-Little_Adam_s_Peak-Ella_Uva_Province.html',
	),
	'Ella Rock' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g616035-d3705211-Reviews-Ella_Rock-Ella_Uva_Province.html',
	),
	'Ravana Ella' => array( // Ravana Falls
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g616035-d318936-Reviews-Ravana_Falls-Ella_Uva_Province.html',
	),
	'Negombo Beach' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g297897-d318937-Reviews-Negombo_Beach-Negombo_Western_Province.html',
	),
	'Bentota Beach' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g297898-d318938-Reviews-Bentota_Beach-Bentota_Galle_District_Southern_Province.html',
	),
	'Pasikudah Beach' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g1408393-d318940-Reviews-Pasikudah_Beach-Pasikudah_Eastern_Province.html',
	),
	'Unawatuna' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g644045-d318941-Reviews-Unawatuna_Beach-Unawatuna_Galle_District_Southern_Province.html',
	),
	'Jungle Beach' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g644045-d6505850-Reviews-Jungle_Beach-Unawatuna_Galle_District_Southern_Province.html',
	),
	'Minneriya National Park' => array(
		'tripadvisor' => 'https://www.tripadvisor.com/Attraction_Review-g2469723-d318942-Reviews-Minneriya_National_Park-Minneriya_North_Central_Province.html',
	),
	'Whale Watching' => array(
		'website'    => 'https://www.whalewatchingsrilanka.com/',
		'price_level' => 3,
	),

	// ── HOTELS ─────────────────────────────────────────────
	'The Grand Hotel' => array(
		'phone'      => '+94 52 222 2881',
		'email'      => 'reservations@grandhotel.lk',
		'website'    => 'https://www.thegrandhotelnuwaraeliya.com/',
		'facebook'   => 'https://www.facebook.com/GrandHotelNuwaraEliya',
		'tripadvisor' => 'https://www.tripadvisor.com/Hotel_Review-g608524-d318947-Reviews-The_Grand_Hotel_Nuwara_Eliya-Nuwara_Eliya_Central_Province.html',
		'price_level' => 3,
	),
	'The Hill Club' => array(
		'phone'      => '+94 52 222 2653',
		'email'      => 'reservations@hillclubsrilanka.lk',
		'website'    => 'https://www.hillclubsrilanka.lk/',
		'tripadvisor' => 'https://www.tripadvisor.com/Hotel_Review-g608524-d318948-Reviews-The_Hill_Club-Nuwara_Eliya_Central_Province.html',
		'price_level' => 3,
	),
	"St. Andrew's Hotel" => array(
		'phone'      => '+94 52 222 2445',
		'email'      => 'info@standrewsnuwaraeliya.com',
		'website'    => 'https://www.standrewsnuwaraeliya.com/',
		'tripadvisor' => 'https://www.tripadvisor.com/Hotel_Review-g608524-d641055-Reviews-St_Andrew_s_Hotel-Nuwara_Eliya_Central_Province.html',
		'price_level' => 2,
	),
	"Helga's Folly" => array(
		'phone'      => '+94 81 223 4571',
		'email'      => 'info@helgasfolly.com',
		'website'    => 'https://www.helgasfolly.com/',
		'tripadvisor' => 'https://www.tripadvisor.com/Hotel_Review-g304138-d641056-Reviews-Helga_s_Folly-Kandy_Central_Province.html',
		'price_level' => 3,
	),
);

// ═══════════════════════════════════════════════════════════════
// Apply enrichment
// ═══════════════════════════════════════════════════════════════

$stats = array(
	'phone_added'       => 0,
	'email_added'       => 0,
	'website_added'     => 0,
	'facebook_added'    => 0,
	'instagram_added'   => 0,
	'tripadvisor_added' => 0,
	'social_added'      => 0,
	'price_added'       => 0,
	'found'             => 0,
	'missed'            => 0,
);

// Map logical names to meta keys
$meta_map = array(
	'phone'       => '_place_phone',
	'email'       => '_place_email',
	'website'     => '_place_website',
	'rating'      => '_place_rating',
	'price_level' => '_place_price_level',
	'facebook'    => '_place_facebook',
	'instagram'   => '_place_instagram',
	'tripadvisor' => '_place_tripadvisor',
);

foreach ($places_data as $title => $fields) {
	$posts = get_posts(array(
		'post_type'      => 'mcp_ai_place',
		'title'          => $title,
		'posts_per_page' => 1,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
	));

	if (empty($posts)) {
		$stats['missed']++;
		WP_CLI::warning("  Not found: {$title}");
		continue;
	}

	$place    = $posts[0];
	$added    = array();
	$has_social = false;

	foreach ($fields as $key => $value) {
		if (!isset($meta_map[$key])) continue;

		$meta_key = $meta_map[$key];
		$existing = get_post_meta($place->ID, $meta_key, true);

		if (!empty($existing)) continue; // Already set

		if (!$dry_run) {
			update_post_meta($place->ID, $meta_key, $value);
		}

		$added[] = $key;

		// Count stats
		switch ($key) {
			case 'phone':       $stats['phone_added']++; break;
			case 'email':       $stats['email_added']++; break;
			case 'website':     $stats['website_added']++; break;
			case 'facebook':    $has_social = true; $stats['facebook_added']++; break;
			case 'instagram':   $has_social = true; $stats['instagram_added']++; break;
			case 'tripadvisor': $has_social = true; $stats['tripadvisor_added']++; break;
			case 'price_level': $stats['price_added']++; break;
		}
	}

	if ($has_social) $stats['social_added']++;
	$stats['found']++;

	if (!empty($added)) {
		WP_CLI::log(sprintf('  ✓ %s [+%s]', $title, implode(', ', $added)));
	}
}

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════
WP_CLI::log('');
WP_CLI::log(WP_CLI::colorize('%G' . str_repeat('─', 50) . '%n'));
WP_CLI::log(sprintf('  Places found:       %d', $stats['found']));
WP_CLI::log(sprintf('  Not found:          %d', $stats['missed']));
WP_CLI::log('');
WP_CLI::log(sprintf('  Phones added:       %d', $stats['phone_added']));
WP_CLI::log(sprintf('  Emails added:       %d', $stats['email_added']));
WP_CLI::log(sprintf('  Websites added:     %d', $stats['website_added']));
WP_CLI::log(sprintf('  Facebook added:     %d', $stats['facebook_added']));
WP_CLI::log(sprintf('  Instagram added:    %d', $stats['instagram_added']));
WP_CLI::log(sprintf('  TripAdvisor added:  %d', $stats['tripadvisor_added']));
WP_CLI::log(sprintf('  Price levels added: %d', $stats['price_added']));
WP_CLI::log(sprintf('  With social links:  %d', $stats['social_added']));

if ($dry_run) {
	WP_CLI::success('Dry run complete.');
} else {
	WP_CLI::success('Contact & social enrichment complete.');
}
