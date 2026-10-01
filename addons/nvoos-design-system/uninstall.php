<?php
/**
 * Uninstall handler.
 *
 * Removes plugin options, email template posts, and transients when the
 * plugin is deleted (not just deactivated).
 *
 * @package NV_oOS_Design_System
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Current-generation options.
delete_option( 'nvoos_nds_settings' );
delete_option( 'nvoos_nds_use_typed_properties' );
delete_option( 'nvoos_nds_elementor_sync' );
delete_option( 'nvoos_nds_legacy_aliases' );
delete_option( 'nvoos_nds_active_email_template' );
delete_option( 'nvoos_nds_email_settings' );
delete_option( 'nvoos_nds_email_seeded' );
delete_option( 'nvoos_nds_ai_provider' );
delete_option( 'nvoos_nds_wc_rebrand' );
delete_transient( 'nvoos_nds_compiled_css' );

// Legacy-generation options (Crocoblock DS era).
delete_option( 'nvoos_cds_settings' );
delete_option( 'nvoos_cds_use_typed_properties' );
delete_option( 'nvoos_cds_elementor_sync' );
delete_transient( 'nvoos_cds_compiled_css' );

// Email template posts (delete_post handles revisions automatically).
$posts = get_posts(
	array(
		'post_type'      => 'nds_email_template',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);

foreach ( $posts as $post_id ) {
	wp_delete_post( $post_id, true );
}
