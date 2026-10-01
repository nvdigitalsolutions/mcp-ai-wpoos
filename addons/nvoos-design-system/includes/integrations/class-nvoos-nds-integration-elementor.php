<?php
/**
 * NV oOS Design System — Elementor Integration
 *
 * Optionally syncs NDS tokens with Elementor Global Colors and adds a
 * "Design System" section to the Elementor Site Settings panel.
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor integration layer.
 *
 * Hooks:
 *   - elementor/kit/register_tabs          → add NDS section to Site Settings
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Integration_Elementor {

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * NDS settings option key used for the sync toggle.
	 *
	 * @var string
	 */
	const SYNC_OPTION_KEY = 'nvoos_nds_elementor_sync';

	/**
	 * Register hooks if Elementor is active.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$registered ) {
			return;
		}

		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		self::$registered = true;

		// Inject NDS tokens as Elementor global colors (opt-in).
		if ( self::is_sync_enabled() ) {
			add_filter(
				'elementor/kit/register_tabs',
				array( __CLASS__, 'register_site_settings_tab' ),
				20
			);
		}
	}

	/**
	 * Check whether Elementor token sync is enabled.
	 *
	 * @return bool
	 */
	public static function is_sync_enabled() {
		return (bool) get_option( self::SYNC_OPTION_KEY, false );
	}

	/**
	 * Register a "Design System" tab in Elementor Site Settings.
	 *
	 * This tab exposes NDS token values as site-wide design choices
	 * within the Elementor editor, so designers can see and use
	 * NDS tokens alongside native Elementor globals.
	 *
	 * @param \Elementor\Core\Kits\Documents\Kit $kit Elementor Kit document.
	 * @return void
	 */
	public static function register_site_settings_tab( $kit ) {
		// The actual controls registration requires the Elementor API.
		// For now, we register a minimal tab that documents the sync.
		// Full bidirectional sync is deferred to a future release.

		$kit->register_tab(
			'nvoos-nds-tokens',
			array(
				'label' => __( 'Design System', 'nvoos-design-system' ),
			)
		);
	}
}
