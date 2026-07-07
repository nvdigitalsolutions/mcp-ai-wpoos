<?php
/**
 * Rate-limited social link enricher for Places CPT.
 * Searches TripAdvisor, Booking.com, and Facebook for each place
 * with configurable delays to stay within API limits.
 *
 * Run: wp --user=1 eval-file enrich_social_timed.php
 *
 * Creates wp-content/uploads/enrich_social_config.json for settings.
 */

// ═══════════════════════════════════════════════════════════
// Configuration (override via wp-content/uploads/enrich_social_config.json)
// ═══════════════════════════════════════════════════════════

$config_file = WP_CONTENT_DIR . '/uploads/enrich_social_config.json';
$defaults = array(
    'batch_size'       => 5,      // Places per run
    'search_delay'     => 3,      // Seconds between individual searches
    'batch_delay'      => 10,     // Seconds between batches
    'max_searches'     => 25,     // Max searches per run
    'targets'          => array('tripadvisor', 'booking', 'facebook'),
    'dry_run'          => false,
);

$config = $defaults;
if (file_exists($config_file)) {
    $loaded = json_decode(file_get_contents($config_file), true);
    if (is_array($loaded)) {
        $config = array_merge($defaults, $loaded);
        WP_CLI::log('Loaded config from ' . $config_file);
    }
}

extract($config);

if ($dry_run) {
    WP_CLI::log(WP_CLI::colorize('%Y══ DRY RUN ══%n'));
}

// ═══════════════════════════════════════════════════════════
// Progress tracking
// ═══════════════════════════════════════════════════════════

$progress_key = '_place_social_enrich_progress';
$progress     = get_option($progress_key, array());
$done_ids     = isset($progress['done_ids']) ? $progress['done_ids'] : array();
$total_found  = isset($progress['total']) ? $progress['total'] : 0;
$searches_run = 0; // Per-run counter

// ═══════════════════════════════════════════════════════════
// Find places needing social links
// ═══════════════════════════════════════════════════════════

$missing_meta = array();
if (in_array('tripadvisor', $targets)) $missing_meta[] = array('key' => '_place_tripadvisor', 'compare' => 'NOT EXISTS');
if (in_array('booking', $targets))     $missing_meta[] = array('key' => '_place_booking', 'compare' => 'NOT EXISTS');
if (in_array('facebook', $targets))    $missing_meta[] = array('key' => '_place_facebook', 'compare' => 'NOT EXISTS');

if (count($missing_meta) > 1) {
    $missing_meta['relation'] = 'OR';
}

$query_args = array(
    'post_type'      => 'mcp_ai_place',
    'posts_per_page' => $batch_size,
    'post_status'    => 'publish',
    'post__not_in'   => $done_ids,
    'tax_query'      => array(array(
        'taxonomy' => 'mcp_ai_place_type',
        'field'    => 'slug',
        'terms'    => array('attraction', 'city', 'hotel', 'experience'),
        'operator' => 'IN',
    )),
    'meta_query'     => $missing_meta,
    'orderby'        => 'ID',
    'order'          => 'ASC',
    'no_found_rows'  => true,
);

$places = get_posts($query_args);

if (empty($places)) {
    WP_CLI::success(sprintf(
        'All done! %d social links found across all places.',
        $total_found
    ));
    delete_option($progress_key);
    exit(0);
}

WP_CLI::log(sprintf(
    "\nProcessing %d places (searches so far: %d, found: %d)\n",
    count($places), $searches_run, $total_found
));

// ═══════════════════════════════════════════════════════════
// Search helpers
// ═══════════════════════════════════════════════════════════

function get_brave_key() {
    static $key = null;
    if (null === $key) {
        $s = get_option('wp_mcp_ai_settings', array());
        $key = isset($s['brave_search_api_key']) ? $s['brave_search_api_key'] : '';
    }
    return $key;
}

function search_web($query) {
    // Use WordPress HTTP API for consistency
    $url = 'https://api.search.brave.com/res/v1/web/search?' . http_build_query(array(
        'q'     => $query,
        'count' => 2,
    ));

    $response = wp_remote_get($url, array(
        'headers' => array(
            'Accept'                    => 'application/json',
            'X-Subscription-Token'      => get_brave_key(),
            'Accept-Encoding'           => 'gzip',
        ),
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        return array();
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['web']['results'])) {
        return array();
    }

    return $data['web']['results'];
}

function extract_urls($results, $domain) {
    $urls = array();
    foreach ($results as $r) {
        if (isset($r['url']) && stripos($r['url'], $domain) !== false) {
            $urls[] = $r['url'];
        }
    }
    return $urls;
}

// ═══════════════════════════════════════════════════════════
// Process places
// ═══════════════════════════════════════════════════════════

$stats = array(
    'processed'     => 0,
    'tripadvisor'   => 0,
    'booking'       => 0,
    'facebook'      => 0,
    'not_found'     => 0,
);

$new_done_ids = array();

foreach ($places as $place) {
    if ($searches_run >= $max_searches) {
        WP_CLI::log("\nReached max searches ({$max_searches}). Save and resume later.");
        break;
    }

    $stats['processed']++;
    $name = $place->post_title;
    $components = get_post_meta($place->ID, '_place_address_components', true);
    $city = is_array($components) && !empty($components['city']) ? $components['city'] : '';

    $prefix = sprintf('[%d/%d]', $stats['processed'], count($places));
    WP_CLI::log("{$prefix} {$name}");

    $found_any = false;
    $needs_ta = in_array('tripadvisor', $targets) && !get_post_meta($place->ID, '_place_tripadvisor', true);
    $needs_bk = in_array('booking', $targets)     && !get_post_meta($place->ID, '_place_booking', true);
    $needs_fb = in_array('facebook', $targets)    && !get_post_meta($place->ID, '_place_facebook', true);

    // ── TripAdvisor search ──
    if ($needs_ta && $searches_run < $max_searches) {
        $query = "site:tripadvisor.com \"{$name}\" Sri Lanka";
        if (!empty($city)) $query .= " {$city}";
        $results = search_web($query);
        $searches_run++;
        $urls = extract_urls($results, 'tripadvisor.com');
        if (!empty($urls)) {
            $ta_url = $urls[0];
            // Prefer attraction/hotel review pages over generic pages
            foreach ($urls as $u) {
                if (preg_match('#/Attraction_Review-|/Hotel_Review-#', $u)) {
                    $ta_url = $u; break;
                }
            }
            if (!$dry_run) update_post_meta($place->ID, '_place_tripadvisor', $ta_url);
            $stats['tripadvisor']++;
            $found_any = true;
            WP_CLI::log("  ✓ TripAdvisor: " . basename(dirname($ta_url)));
        }
        sleep($search_delay);
    }

    // ── Booking.com search ──
    if ($needs_bk && $searches_run < $max_searches) {
        $query = "site:booking.com \"{$name}\" Sri Lanka";
        if (!empty($city)) $query .= " {$city}";
        $results = search_web($query);
        $searches_run++;
        $urls = extract_urls($results, 'booking.com');
        if (!empty($urls)) {
            if (!$dry_run) update_post_meta($place->ID, '_place_booking', $urls[0]);
            $stats['booking']++;
            $found_any = true;
            WP_CLI::log("  ✓ Booking.com");
        }
        sleep($search_delay);
    }

    // ── Facebook search ──
    if ($needs_fb && $searches_run < $max_searches) {
        $query = "site:facebook.com \"{$name}\" Sri Lanka official page";
        $results = search_web($query);
        $searches_run++;
        $urls = extract_urls($results, 'facebook.com');
        if (!empty($urls)) {
            // Prefer page URLs over photo/post URLs
            $fb_url = $urls[0];
            foreach ($urls as $u) {
                if (!preg_match('#/posts/|/photos/|/videos/#', $u)) {
                    $fb_url = $u; break;
                }
            }
            if (!$dry_run) update_post_meta($place->ID, '_place_facebook', $fb_url);
            $stats['facebook']++;
            $found_any = true;
            WP_CLI::log("  ✓ Facebook");
        }
        sleep($search_delay);
    }

    if (!$found_any) {
        $stats['not_found']++;
    }

    $new_done_ids[] = $place->ID;

    // Flush periodically
    if (0 === $stats['processed'] % 3) {
        wp_cache_flush();
    }
}

// ═══════════════════════════════════════════════════════════
// Save progress
// ═══════════════════════════════════════════════════════════

if (!$dry_run) {
    $progress['done_ids']  = array_merge($done_ids, $new_done_ids);
    $progress['total']     = $total_found + $stats['tripadvisor'] + $stats['booking'] + $stats['facebook'];
    $progress['updated']   = gmdate('Y-m-d H:i:s');
    update_option($progress_key, $progress, false);
}

// ═══════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════

WP_CLI::log('');
WP_CLI::log(WP_CLI::colorize('%G' . str_repeat('─', 50) . '%n'));
WP_CLI::log(sprintf('  Processed:        %d', $stats['processed']));
WP_CLI::log(sprintf('  Searches run:     %d', $searches_run));
WP_CLI::log(sprintf('  TripAdvisor:      %d', $stats['tripadvisor']));
WP_CLI::log(sprintf('  Booking.com:      %d', $stats['booking']));
WP_CLI::log(sprintf('  Facebook:         %d', $stats['facebook']));
WP_CLI::log(sprintf('  Not found:        %d', $stats['not_found']));
WP_CLI::log(sprintf('  Total so far:     %d', $progress['total'] ?? $total_found));

if ($dry_run) {
    WP_CLI::success('Dry run complete. Set dry_run:false in config to apply.');
} else {
    $remaining = $max_searches - $searches_run;
    WP_CLI::success(sprintf(
        'Batch complete. %d searches remaining this run. Re-run to continue.',
        max(0, $remaining)
    ));
}
