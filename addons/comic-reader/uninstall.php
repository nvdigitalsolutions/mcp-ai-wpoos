<?php
/**
 * NV oOS Comic Reader — Uninstall
 *
 * Cleans up plugin-specific data when the addon is uninstalled:
 * settings, per-user reading progress, and the series/collection terms.
 * Comic files themselves live in the Media Library and are left untouched.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Settings.
delete_option( 'nvoos_comic_reader_settings' );

// Per-user reading progress.
$user_ids = get_users(
	array(
		'fields' => 'ID',
		'number' => 0,
	)
);
if ( ! empty( $user_ids ) ) {
	foreach ( $user_ids as $user_id ) {
		delete_user_meta( $user_id, 'nvoos_comic_reader_progress' );
	}
}

// Series and collection terms.
$taxonomies = array( 'nvoos_comic_series', 'nvoos_comic_collection' );
foreach ( $taxonomies as $taxonomy ) {
	$term_ids = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	if ( ! is_wp_error( $term_ids ) ) {
		foreach ( $term_ids as $term_id ) {
			wp_delete_term( $term_id, $taxonomy );
		}
	}
}
