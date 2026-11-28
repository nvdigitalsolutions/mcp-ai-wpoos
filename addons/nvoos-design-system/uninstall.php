<?php
/**
 * Uninstall handler.
 *
 * Removes plugin options, email template posts, token metadata and transients
 * when the plugin is deleted. Runs per site on multisite.
 *
 * @package NV_oOS_Design_System
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove this site's plugin data.
 *
 * @return void
 */
$nvoos_nds_uninstall_site = static function () {
	$options = array(
		'nvoos_nds_settings',
		'nvoos_nds_token_meta',
		'nvoos_nds_use_typed_properties',
		'nvoos_nds_elementor_sync',
		'nvoos_nds_legacy_aliases',
		'nvoos_nds_active_email_template',
		'nvoos_nds_email_settings',
		'nvoos_nds_email_seeded',
		'nvoos_nds_ai_provider',
		'nvoos_nds_wc_rebrand',
		'nvoos_nds_wrap_mode',
		'nvoos_nds_email_log',
		'nvoos_nds_brand',
		'nvoos_nds_global_styles',
		'nvoos_nds_cpt_migrated',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	delete_transient( 'nvoos_nds_compiled_css' );

	// Legacy Crocoblock DS era.
	delete_option( 'nvoos_cds_settings' );
	delete_option( 'nvoos_cds_use_typed_properties' );
	delete_option( 'nvoos_cds_elementor_sync' );
	delete_transient( 'nvoos_cds_compiled_css' );

	// Email template posts (both the 1.0 type and the pre-1.0 legacy type).
	foreach ( array( 'nvoos_nds_email', 'nds_email_template' ) as $nvoos_nds_post_type ) {
		$posts = get_posts(
			array(
				'post_type'      => $nvoos_nds_post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}
};

if ( is_multisite() ) {
	$nvoos_nds_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $nvoos_nds_sites as $nvoos_nds_site_id ) {
		switch_to_blog( (int) $nvoos_nds_site_id );
		$nvoos_nds_uninstall_site();
		restore_current_blog();
	}
} else {
	$nvoos_nds_uninstall_site();
}
