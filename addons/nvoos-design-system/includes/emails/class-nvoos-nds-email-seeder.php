<?php
/**
 * NV oOS Design System — Email Template Seeder
 *
 * Materialises the five built-in email templates as `nds_email_template`
 * posts. Idempotent: existing posts are left untouched (content updates
 * ship with the plugin, but user edits to built-ins are respected).
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds built-in email templates.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Email_Seeder {

	/**
	 * Seed built-in templates if not already seeded.
	 *
	 * Hooked to `init` (priority 10, after the CPT registers at priority 5).
	 *
	 * @return void
	 */
	public static function maybe_seed() {
		if ( (bool) get_option( NV_oOS_Design_System_Plugin::EMAIL_SEEDED_KEY, false ) ) {
			return;
		}

		if ( ! post_type_exists( NV_oOS_Design_System_Email_Template_CPT::POST_TYPE ) ) {
			return;
		}

		self::seed();

		update_option( NV_oOS_Design_System_Plugin::EMAIL_SEEDED_KEY, 1, false );
	}

	/**
	 * Run the seeding pass.
	 *
	 * @return int Number of templates materialised.
	 */
	public static function seed() {
		$seeded = 0;

		foreach ( NV_oOS_Design_System_Email_Template_Registry::get_builtins() as $slug => $meta ) {
			$html = NV_oOS_Design_System_Email_Template_Registry::get_builtin_html( $slug );
			if ( '' === trim( $html ) ) {
				continue;
			}

			$result = NV_oOS_Design_System_Email_Template_CPT::upsert(
				$slug,
				$meta['title'],
				$html,
				'builtin',
				$meta['scope'],
				array(),
				'publish'
			);

			if ( ! is_wp_error( $result ) ) {
				++$seeded;
			}
		}

		return $seeded;
	}
}
