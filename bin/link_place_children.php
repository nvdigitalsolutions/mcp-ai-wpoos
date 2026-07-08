<?php
/**
 * Link child places (attractions, hotels, tales, etc.) to their parent cities
 * based on source URL matching.
 *
 * Run with: wp eval-file link_place_children.php
 */

// Get all city places
$cities = get_posts(array(
    'post_type'      => 'mcp_ai_place',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
    'tax_query'      => array(
        array(
            'taxonomy' => 'mcp_ai_place_type',
            'field'    => 'slug',
            'terms'    => 'city',
        ),
    ),
    'no_found_rows' => true,
));

$linked   = 0;
$skipped  = 0;
$errors   = array();

foreach ($cities as $city) {
    $city_id     = $city->ID;
    $city_name   = $city->post_title;
    $source_url  = get_post_meta($city_id, '_place_source_url', true);

    if (empty($source_url)) {
        $skipped++;
        continue;
    }

    // Extract city slug from source URL like:
    // https://www.talesofceylon.com/destinations/kandy/
    // → kandy
    $path = wp_parse_url($source_url, PHP_URL_PATH);
    $path = trim($path, '/');
    $parts = explode('/', $path);

    // Find the segment after "destinations"
    $city_slug = '';
    foreach ($parts as $i => $part) {
        if ('destinations' === strtolower($part) && isset($parts[$i + 1])) {
            $city_slug = $parts[$i + 1];
            break;
        }
    }

    if (empty($city_slug)) {
        $skipped++;
        continue;
    }

    // Build the URL prefix to match children:
    // https://www.talesofceylon.com/destinations/kandy/
    $url_prefix = "https://www.talesofceylon.com/destinations/{$city_slug}/";

    // Find all places whose source_url starts with this prefix
    // but exclude the city itself
    $children = get_posts(array(
        'post_type'      => 'mcp_ai_place',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'post__not_in'   => array($city_id),
        'meta_query'     => array(
            array(
                'key'     => '_place_source_url',
                'value'   => $url_prefix,
                'compare' => 'LIKE',
            ),
        ),
        'no_found_rows' => true,
    ));

    foreach ($children as $child) {
        $result = wp_update_post(array(
            'ID'          => $child->ID,
            'post_parent' => $city_id,
        ), true);

        if (is_wp_error($result)) {
            $errors[] = "{$child->post_title}: " . $result->get_error_message();
        } else {
            // Also set relationship type
            update_post_meta($child->ID, '_place_relationship_type', 'contains');
            $linked++;
        }
    }

    WP_CLI::log("{$city_name}: linked " . count($children) . " children");
}

WP_CLI::success("Linked {$linked} children to their parent cities. Skipped: {$skipped}. Errors: " . count($errors));

if (!empty($errors)) {
    foreach ($errors as $err) {
        WP_CLI::warning($err);
    }
}
