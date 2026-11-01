<?php
/**
 * Plugin bootstrap — hooks, REST registration, admin surface.
 *
 * @package NvoosForgeBridge
 */

namespace NvoosForgeBridge;

/**
 * Composition root. Idempotent and late-load immune (the fleet's bundle
 * loader can include this plugin after init/rest_api_init have fired).
 */
final class Plugin {

	/**
	 * Whether init() has run in this request.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register hooks. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Content-change → rebuild webhook (no-ops until configured).
		Rebuild_Webhook::init();

		// REST surface.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		if ( did_action( 'rest_api_init' ) ) {
			self::register_rest();
		}

		// Admin settings.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
			add_action( 'admin_post_nvoos_forge_bridge_save', array( 'NvoosForgeBridge\Settings', 'handle_save' ) );
			if ( did_action( 'admin_menu' ) ) {
				self::register_admin_page();
			}
		}
	}

	/**
	 * Register the REST controllers.
	 *
	 * @return void
	 */
	public static function register_rest(): void {
		$rebuild = new Rest\Rebuild();
		$rebuild->register_routes();
		$health = new Rest\Health();
		$health->register_routes();
	}

	/**
	 * Register the settings page under Settings → Forge Bridge.
	 *
	 * @return void
	 */
	public static function register_admin_page(): void {
		add_options_page(
			__( 'Forge Bridge', 'nvoos-forge-bridge' ),
			__( 'Forge Bridge', 'nvoos-forge-bridge' ),
			'manage_options',
			'nvoos-forge-bridge',
			array( 'NvoosForgeBridge\\Settings', 'render_page' )
		);
	}
}
