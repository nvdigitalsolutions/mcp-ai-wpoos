<?php
/**
 * NV oOS Skote — Asset Registration & Localization
 *
 * Registers and conditionally enqueues the compiled React SPA, plus the
 * single namespaced `window.nvoosSkote` object the SPA reads instead of the
 * legacy global `wpApiSettings`.
 *
 * The build pipeline (`bin/generate-asset-php.js`) emits
 * `dist/index.asset.php` returning `array( 'dependencies' => [...], 'version'
 * => '<hash>' )`, mirroring the `@wordpress/scripts` convention. When that
 * file is missing (e.g. before the first build), enqueueing is silently
 * skipped so the host plugin still activates cleanly.
 *
 * @package NV_oOS_Skote
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset registration and localization.
 *
 * @since 0.1.0
 */
class NVOOS_Skote_Assets {

	/**
	 * Script handle for the built SPA bundle.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'nvoos-skote-app';

	/**
	 * Style handle for the built SPA stylesheet.
	 *
	 * @var string
	 */
	const STYLE_HANDLE = 'nvoos-skote-app';

	/**
	 * Whether assets have already been registered in this request.
	 *
	 * @var bool
	 */
	protected static $registered = false;

	/**
	 * Register (but do not enqueue) the SPA assets.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the bundle was found and registered.
	 */
	public static function register() {
		if ( self::$registered ) {
			return wp_script_is( self::SCRIPT_HANDLE, 'registered' );
		}
		self::$registered = true;

		$asset_file = NVOOS_SKOTE_DIST . 'index.asset.php';
		$script_url = NVOOS_SKOTE_URL . 'dist/index.js';
		$style_url  = NVOOS_SKOTE_URL . 'dist/index.css';

		if ( ! file_exists( NVOOS_SKOTE_DIST . 'index.js' ) ) {
			return false;
		}

		$asset = array(
			'dependencies' => array(),
			'version'      => NVOOS_SKOTE_VERSION,
		);
		if ( file_exists( $asset_file ) ) {
			$loaded = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			if ( is_array( $loaded ) ) {
				$asset = wp_parse_args( $loaded, $asset );
			}
		}

		wp_register_script(
			self::SCRIPT_HANDLE,
			$script_url,
			(array) $asset['dependencies'],
			(string) $asset['version'],
			true
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'nvoos-skote', NVOOS_SKOTE_PATH . 'languages' );

		if ( file_exists( NVOOS_SKOTE_DIST . 'index.css' ) ) {
			wp_register_style(
				self::STYLE_HANDLE,
				$style_url,
				array(),
				(string) $asset['version']
			);
		}

		return true;
	}

	/**
	 * Build the localized payload exposed at `window.nvoosSkote`.
	 *
	 * @since 0.1.0
	 *
	 * @param array $context Optional. Extra context (e.g. `app` for shortcode mounts).
	 *
	 * @return array
	 */
	public static function get_localized_payload( array $context = array() ) {
		$user_id    = get_current_user_id();
		$user       = $user_id ? wp_get_current_user() : null;
		$user_caps  = ( $user && isset( $user->allcaps ) && is_array( $user->allcaps ) )
			? array_keys( array_filter( $user->allcaps ) )
			: array();
		$rest_url   = esc_url_raw( rest_url( NVOOS_SKOTE_REST_NAMESPACE . '/' ) );
		$rest_root  = esc_url_raw( rest_url() );
		$rest_nonce = wp_create_nonce( 'wp_rest' );
		$locale     = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		$payload = array(
			'version'           => NVOOS_SKOTE_VERSION,
			'restUrl'           => $rest_url,
			'restRoot'          => $rest_root,
			'restNonce'         => $rest_nonce,
			'userId'            => (int) $user_id,
			'userDisplayName'   => $user ? (string) $user->display_name : '',
			'capabilities'      => $user_caps,
			'proEnabled'        => NV_oOS_Skote::is_pro_active(),
			'woocommerceEnabled' => NV_oOS_Skote::is_woocommerce_active(),
			'jetengineEnabled'  => NV_oOS_Skote::is_jetengine_active(),
			'i18nLocale'        => (string) $locale,
			'rootElementId'     => 'nvoos-skote-root',
			'context'           => $context,
			'endpoints'         => array(
				'settings'  => $rest_url . 'settings',
				'me'        => $rest_url . 'me',
				'apps'      => $rest_url . 'apps',
				'users'     => $rest_url . 'bridge/wp/users',
				'cpt'       => $rest_url . 'bridge/cpt',
				'jet'       => $rest_url . 'bridge/jet/cct',
				'wc'        => $rest_url . 'bridge/wc',
				'workflows' => $rest_url . 'workflows',
				'tools'     => $rest_url . 'tools',
			),
		);

		/**
		 * Filters the payload exposed at `window.nvoosSkote`.
		 *
		 * Use this filter to add Pro-only fields, feature flags, or
		 * customisations. Returned data MUST be JSON-serialisable.
		 *
		 * @since 0.1.0
		 *
		 * @param array $payload Default payload.
		 * @param array $context Mount context.
		 */
		return (array) apply_filters( 'nvoos_skote_localized_payload', $payload, $context );
	}

	/**
	 * Enqueue the SPA bundle and inject the namespaced global.
	 *
	 * @since 0.1.0
	 *
	 * @param array $context Optional. Mount context (e.g. `array( 'app' => 'tasks' )`).
	 *
	 * @return bool True when assets were enqueued.
	 */
	public static function enqueue( array $context = array() ) {
		if ( ! self::register() ) {
			return false;
		}

		$payload = self::get_localized_payload( $context );
		$json    = wp_json_encode( $payload );
		if ( false === $json ) {
			$json = '{}';
		}
		// Inject before the SPA so the global is set when React boots.
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.nvoosSkote = ' . $json . ';',
			'before'
		);

		wp_enqueue_script( self::SCRIPT_HANDLE );
		if ( wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::STYLE_HANDLE );
		}

		return true;
	}

	/**
	 * Conditional admin enqueue — only on the addon admin screen.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_admin( $hook_suffix ) {
		if ( ! is_string( $hook_suffix ) ) {
			return;
		}
		if ( false === strpos( $hook_suffix, 'nvoos-skote' ) ) {
			return;
		}
		self::enqueue( array( 'surface' => 'admin' ) );
	}

	/**
	 * Conditional frontend enqueue — only on pages that include the shortcode.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function maybe_enqueue_frontend() {
		if ( is_admin() ) {
			return;
		}
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! has_shortcode( (string) $post->post_content, 'nvoos_skote' ) ) {
			return;
		}
		self::enqueue( array( 'surface' => 'shortcode' ) );
	}
}
