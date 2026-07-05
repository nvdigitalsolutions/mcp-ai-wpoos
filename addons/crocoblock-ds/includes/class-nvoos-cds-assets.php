<?php
/**
 * NV oOS Crocoblock DS — Assets
 *
 * Handles enqueuing of CSS and JS assets for the design system.
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset manager for the Crocoblock Design System.
 *
 * Enqueues the compiled token CSS and optional component stylesheet
 * that maps tokens to actual Crocoblock widget selectors.
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_Assets {

	/**
	 * Whether the component stylesheet has been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register component CSS with WordPress.
	 *
	 * Called once; subsequent calls are no-ops.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		wp_register_style(
			'nvoos-cds-components',
			NVOOS_CROCOBLOCK_DS_URL . 'assets/css/components.css',
			array(),
			NVOOS_CROCOBLOCK_DS_VERSION
		);
	}

	/**
	 * Enqueue the component stylesheet on the front end.
	 *
	 * The token CSS is handled by NV_oOS_Crocoblock_DS_Plugin::enqueue_frontend_styles().
	 * This method adds the optional component layer that maps tokens to widget selectors.
	 *
	 * @return void
	 */
	public static function enqueue_components() {
		self::register();
		wp_enqueue_style( 'nvoos-cds-components' );
	}
}
