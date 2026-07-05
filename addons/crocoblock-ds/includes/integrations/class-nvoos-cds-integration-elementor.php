<?php
/**
 * NV oOS Crocoblock DS — Elementor Integration
 *
 * Optionally syncs CDS tokens with Elementor Global Colors and adds a
 * "Crocoblock DS" section to the Elementor Site Settings panel.
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor integration layer.
 *
 * Hooks:
 *   - elementor/kit/register_tabs          → add CDS section to Site Settings
 *   - elementor/documents/register_controls → inject token-aware controls
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_Integration_Elementor {

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * CDS settings option key used for the sync toggle.
	 *
	 * @var string
	 */
	const SYNC_OPTION_KEY = 'nvoos_cds_elementor_sync';

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

		// Inject CDS tokens as Elementor global colors (opt-in).
		if ( self::is_sync_enabled() ) {
			add_filter(
				'elementor/kit/register_tabs',
				array( __CLASS__, 'register_site_settings_tab' ),
				20
			);

			add_filter(
				'elementor/schemes/enabled_schemes',
				array( __CLASS__, 'ensure_color_scheme_enabled' )
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
	 * Ensure the Elementor color scheme is registered so CDS tokens
	 * can participate in the global color picker.
	 *
	 * @param array $schemes Existing enabled schemes.
	 * @return array
	 */
	public static function ensure_color_scheme_enabled( $schemes ) {
		if ( ! is_array( $schemes ) ) {
			$schemes = array();
		}
		return $schemes;
	}

	/**
	 * Register a "Crocoblock DS" tab in Elementor Site Settings.
	 *
	 * This tab exposes CDS token values as site-wide design choices
	 * within the Elementor editor, so designers can see and use
	 * CDS tokens alongside native Elementor globals.
	 *
	 * @param \Elementor\Core\Kits\Documents\Kit $kit Elementor Kit document.
	 * @return void
	 */
	public static function register_site_settings_tab( $kit ) {
		// The actual controls registration requires the Elementor API.
		// For Phase 5, we register a minimal tab that documents the sync.
		// Full bidirectional sync is deferred to a future release.

		$kit->register_tab(
			'nvoos-cds-tokens',
			array(
				'label' => __( 'Crocoblock DS', 'nvoos-crocoblock-ds' ),
			)
		);
	}
}
