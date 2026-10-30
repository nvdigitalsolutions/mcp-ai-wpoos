<?php
/**
 * Uninstall handler for Funiq Bridge.
 *
 * This file runs when the plugin is deleted via WordPress admin.
 * It is a standalone file — no autoloader or plugin bootstrap is loaded.
 *
 * @package NvoosAstroBridge
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Remove all funiq_product posts (and their meta).
// ---------------------------------------------------------------------------
$product_ids = get_posts(
	array(
		'post_type'      => 'funiq_product',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $product_ids as $product_id ) {
	wp_delete_post( $product_id, true );
}

// ---------------------------------------------------------------------------
// Remove all funiq_promotion posts.
// ---------------------------------------------------------------------------
$promo_ids = get_posts(
	array(
		'post_type'      => 'funiq_promotion',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $promo_ids as $promo_id ) {
	wp_delete_post( $promo_id, true );
}

// ---------------------------------------------------------------------------
// Remove all funiq_promocode posts.
// ---------------------------------------------------------------------------
$promocode_ids = get_posts(
	array(
		'post_type'      => 'funiq_promocode',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $promocode_ids as $promocode_id ) {
	wp_delete_post( $promocode_id, true );
}

// ---------------------------------------------------------------------------
// Remove taxonomy terms.
// ---------------------------------------------------------------------------
$taxonomies = array(
	'funiq_category',
	'funiq_brand',
	'funiq_color',
	'funiq_status',
);

foreach ( $taxonomies as $taxonomy_slug ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy_slug,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term_identifier ) {
			wp_delete_term( $term_identifier, $taxonomy_slug );
		}
	}
}

// ---------------------------------------------------------------------------
// Remove global options.
// ---------------------------------------------------------------------------
delete_option( 'funiq_banner' );
delete_option( 'funiq_carousel' );

// ---------------------------------------------------------------------------
// Remove capabilities from roles (both the current and legacy-brand slugs).
// ---------------------------------------------------------------------------
$admin = get_role( 'administrator' );
if ( $admin ) {
	$admin->remove_cap( 'manage_astro' );
	$admin->remove_cap( 'manage_funiq' );
}
$editor = get_role( 'editor' );
if ( $editor ) {
	$editor->remove_cap( 'manage_astro' );
	$editor->remove_cap( 'manage_funiq' );
}
