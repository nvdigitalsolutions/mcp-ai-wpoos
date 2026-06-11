<?php
/**
 * Uninstall handler for Funiq Bridge.
 *
 * This file runs when the plugin is deleted via WordPress admin.
 * It is a standalone file — no autoloader or plugin bootstrap is loaded.
 *
 * @package FuniqBridge
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
foreach ( $product_ids as $id ) {
	wp_delete_post( $id, true );
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
foreach ( $promo_ids as $id ) {
	wp_delete_post( $id, true );
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
foreach ( $promocode_ids as $id ) {
	wp_delete_post( $id, true );
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

foreach ( $taxonomies as $tax ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $tax,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term_id ) {
			wp_delete_term( $term_id, $tax );
		}
	}
}

// ---------------------------------------------------------------------------
// Remove global options.
// ---------------------------------------------------------------------------
delete_option( 'funiq_banner' );
delete_option( 'funiq_carousel' );

// ---------------------------------------------------------------------------
// Remove capabilities from roles.
// ---------------------------------------------------------------------------
$admin = get_role( 'administrator' );
if ( $admin ) {
	$admin->remove_cap( 'manage_funiq' );
}
$editor = get_role( 'editor' );
if ( $editor ) {
	$editor->remove_cap( 'manage_funiq' );
}
