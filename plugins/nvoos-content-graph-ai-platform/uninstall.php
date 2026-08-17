<?php
/**
 * Uninstall handler for NV oOS Content Graph — Platform.
 *
 * Removes all plugin data when the plugin is deleted via the
 * WordPress admin Plugins screen. This file is executed only
 * when the WP_UNINSTALL_PLUGIN constant is defined.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ─── Settings ───────────────────────────────────────────────────
delete_option( 'ai_platform_settings' );

// ─── CPT posts ───────────────────────────────────────────────────
$nvoos_content_graph_ai_platform_post_types = array(
	'ai_platform_project',
	'ai_platform_resource',
	'ai_platform_template',
);

foreach ( $nvoos_content_graph_ai_platform_post_types as $nvoos_content_graph_ai_platform_cpt ) {
	$nvoos_content_graph_ai_platform_posts = get_posts(
		array(
			'post_type'      => $nvoos_content_graph_ai_platform_cpt,
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $nvoos_content_graph_ai_platform_posts ) ) {
		foreach ( $nvoos_content_graph_ai_platform_posts as $nvoos_content_graph_ai_platform_post_id ) {
			wp_delete_post( (int) $nvoos_content_graph_ai_platform_post_id, true );
		}
	}
}

// ─── Transients ──────────────────────────────────────────────────
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_ai_platform_%',
		'_transient_timeout_ai_platform_%'
	)
);
