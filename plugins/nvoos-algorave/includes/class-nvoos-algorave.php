<?php
/**
 * NV oOS Algorave — Core Class
 *
 * Handles admin notices, asset enqueuing, and shortcode registration
 * for the algorave live coding music plugin.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for NV oOS Algorave.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave {

	/**
	 * WordPress option key for plugin settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_algorave_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Check whether the plugin is enabled in settings.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( self::OPTION_KEY, array() );
		return ! isset( $settings['enabled'] ) || ! empty( $settings['enabled'] );
	}

	/**
	 * Get plugin settings.
	 *
	 * Consumer addons (for example the premium AI addon) may extend the
	 * defaults through the `nvoos_algorave/default_settings` filter.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = apply_filters(
			'nvoos_algorave/default_settings',
			array(
				'enabled'            => true,
				'strudel_cdn'        => true,
				'default_bpm'        => 120,
				'default_scale'      => 'C minor',
				'visualizer_enabled' => true,
				'guest_access'       => false,
			)
		);

		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			$defaults
		);
	}

	/**
	 * Register shortcodes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_shortcodes() {
		add_shortcode( 'algorave_live_coder', array( __CLASS__, 'shortcode_live_coder' ) );
		add_shortcode( 'algorave_pattern_library', array( __CLASS__, 'shortcode_pattern_library' ) );
	}

	/**
	 * Render the live coder shortcode.
	 *
	 * @since 1.0.0
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function shortcode_live_coder( $atts ) {
		// Capability gate (F-AI-01 / R-S-05): the live-coder can evaluate
		// user-typed JavaScript via `new Function('Tone', code)` when the
		// Tone.js engine is enabled by the site operator. Authors
		// (`edit_posts`) and above always see the surface. Lower-privileged
		// users (including guests) only see it when the plugin's
		// "Guest Access" setting is explicitly enabled by an administrator;
		// for those users the Tone.js raw-eval path is force-disabled below
		// in enqueue_algorave_assets() regardless of NVOOS_ALGORAVE_ALLOW_TONEJS_EVAL,
		// leaving only the sandboxed Strudel engine available.
		if ( ! self::current_user_can_view_live_coder() ) {
			return self::render_live_coder_login_prompt();
		}

		$atts = shortcode_atts(
			array(
				'bpm'        => 120,
				'scale'      => 'C minor',
				'visualizer' => 'true',
			),
			$atts,
			'algorave_live_coder'
		);

		// Enqueue required assets.
		self::enqueue_algorave_assets();

		ob_start();
		include NVOOS_ALGORAVE_PATH . 'templates/live-coder.php';
		return ob_get_clean();
	}

	/**
	 * Render the pattern library shortcode.
	 *
	 * @since 1.0.0
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function shortcode_pattern_library( $atts ) {
		$atts = shortcode_atts(
			array(
				'per_page' => 12,
				'genre'    => '',
			),
			$atts,
			'algorave_pattern_library'
		);

		ob_start();
		include NVOOS_ALGORAVE_PATH . 'templates/pattern-library.php';
		return ob_get_clean();
	}

	/**
	 * Enqueue algorave-specific frontend assets.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function enqueue_algorave_assets() {
		$settings = self::get_settings();

		// Strudel — bundled locally (AGPL-3.0 source included in vendor dir).
		$strudel_loaded = ! empty( $settings['strudel_cdn'] );
		if ( $strudel_loaded ) {
			wp_enqueue_script(
				'strudel-web',
				NVOOS_ALGORAVE_URL . 'assets/js/vendor/strudel/strudel-web-' . NVOOS_ALGORAVE_STRUDEL_VERSION . '.js',
				array(),
				NVOOS_ALGORAVE_STRUDEL_VERSION,
				true
			);
		}

		// Pattern engine (Tone.js wrapper).
		// Depends on strudel-web when Strudel engine is enabled so initStrudel() is available.
		wp_enqueue_script(
			'nvoos-algorave-pattern-engine',
			NVOOS_ALGORAVE_URL . 'assets/js/algorave-pattern-engine.js',
			$strudel_loaded ? array( 'strudel-web' ) : array(),
			NVOOS_ALGORAVE_VERSION,
			true
		);

		// Visualizer.
		if ( ! empty( $settings['visualizer_enabled'] ) ) {
			wp_enqueue_script(
				'nvoos-algorave-visualizer',
				NVOOS_ALGORAVE_URL . 'assets/js/algorave-visualizer.js',
				array( 'nvoos-algorave-pattern-engine' ),
				NVOOS_ALGORAVE_VERSION,
				true
			);

			wp_enqueue_style(
				'nvoos-algorave-visualizer',
				NVOOS_ALGORAVE_URL . 'assets/css/algorave-visualizer.css',
				array(),
				NVOOS_ALGORAVE_VERSION
			);
		}

		// Live coder interface.
		wp_enqueue_script(
			'nvoos-algorave-live-coder',
			NVOOS_ALGORAVE_URL . 'assets/js/algorave-live-coder.js',
			array( 'nvoos-algorave-pattern-engine' ),
			NVOOS_ALGORAVE_VERSION,
			true
		);

		wp_enqueue_style(
			'nvoos-algorave-editor',
			NVOOS_ALGORAVE_URL . 'assets/css/algorave-editor.css',
			array(),
			NVOOS_ALGORAVE_VERSION
		);

		// Pass configuration to frontend.
		// Localized on the pattern-engine handle so the data is available
		// when algorave-pattern-engine.js runs (before the live-coder script).
		$samples_base = NVOOS_ALGORAVE_URL . 'assets/samples/';
		wp_localize_script(
			'nvoos-algorave-pattern-engine',
			'nvoosAlgoraveConfig',
			array(
				'restUrl'           => esc_url_raw( rest_url( 'nvoos-algorave/v1/' ) ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'defaultBpm'        => absint( $settings['default_bpm'] ),
				'defaultScale'      => sanitize_text_field( $settings['default_scale'] ),
				'strudelEnabled'    => ! empty( $settings['strudel_cdn'] ),
				'tonejsEvalAllowed' => self::is_tonejs_eval_allowed_for_current_user(),
				'visualizer'        => ! empty( $settings['visualizer_enabled'] ),
				'samplesUrl'        => esc_url_raw( $samples_base ),
				'sampleMaps'        => array(
					'drumMachines'      => esc_url_raw( $samples_base . 'tidal-drum-machines.json' ),
					'drumMachinesAlias' => esc_url_raw( $samples_base . 'tidal-drum-machines-alias.json' ),
					'piano'             => esc_url_raw( $samples_base . 'piano.json' ),
					'vcsl'              => esc_url_raw( $samples_base . 'vcsl.json' ),
					'mridangam'         => esc_url_raw( $samples_base . 'mridangam.json' ),
					'uzuDrumkit'        => esc_url_raw( $samples_base . 'uzu-drumkit.json' ),
					'uzuWavetables'     => esc_url_raw( $samples_base . 'uzu-wavetables.json' ),
				),
			)
		);
	}

	/**
	 * Enqueue frontend assets on pages that use our shortcodes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function enqueue_frontend_assets() {
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		$has_live_coder      = has_shortcode( $post->post_content, 'algorave_live_coder' );
		$has_pattern_library = has_shortcode( $post->post_content, 'algorave_pattern_library' );

		if ( ! $has_live_coder && ! $has_pattern_library ) {
			return;
		}

		// If only the live coder is on the page and the current viewer can't
		// see it, skip enqueuing its scripts entirely.
		if ( $has_live_coder && ! $has_pattern_library
			&& ! self::current_user_can_view_live_coder() ) {
			return;
		}

		self::enqueue_algorave_assets();
	}

	/**
	 * Whether the current user is permitted to view the live coder surface.
	 *
	 * Authors (`edit_posts`) and above are always allowed. Other users
	 * (including unauthenticated guests) require the plugin's
	 * "Guest Access" setting to be explicitly enabled by an administrator.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the current user may render the live coder.
	 */
	public static function current_user_can_view_live_coder() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		$settings = self::get_settings();
		return ! empty( $settings['guest_access'] );
	}

	/**
	 * Whether the Tone.js raw `new Function` eval path may be used by the
	 * current user.
	 *
	 * Defense-in-depth: even when the site operator has opted in by defining
	 * `NVOOS_ALGORAVE_ALLOW_TONEJS_EVAL = true`, only users with `edit_posts`
	 * may trigger the unrestricted JavaScript eval. Guests and
	 * lower-privileged users are limited to the sandboxed Strudel engine.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when Tone.js eval is permitted for the current user.
	 */
	public static function is_tonejs_eval_allowed_for_current_user() {
		if ( ! defined( 'NVOOS_ALGORAVE_ALLOW_TONEJS_EVAL' ) || ! NVOOS_ALGORAVE_ALLOW_TONEJS_EVAL ) {
			return false;
		}
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Render a small login prompt shown in place of the live coder when the
	 * current visitor is not permitted to view it.
	 *
	 * @since 1.0.0
	 *
	 * @return string HTML markup.
	 */
	private static function render_live_coder_login_prompt() {
		$html = '<div class="algorave-live-coder-locked" role="status">';

		if ( is_user_logged_in() ) {
			// Logged-in but lacks edit_posts and Guest Access is off.
			$html .= '<p>' . esc_html__( 'The live coder is not available to your account. Ask a site administrator to enable Guest Access or grant you author permissions.', 'nvoos-algorave' ) . '</p>';
		} else {
			$login_url = wp_login_url( get_permalink() );
			$html     .= '<p>' . esc_html__( 'The live coder is available to authorized users.', 'nvoos-algorave' ) . '</p>';
			$html     .= '<p><a class="algorave-login-link" href="' . esc_url( $login_url ) . '">';
			$html     .= esc_html__( 'Log in to continue', 'nvoos-algorave' );
			$html     .= '</a></p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Display admin notices about plugin status.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Show activation notice.
		if ( get_transient( 'nvoos_algorave_activated' ) ) {
			delete_transient( 'nvoos_algorave_activated' );
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'NV oOS Algorave activated — add the [algorave_live_coder] shortcode to any page to start live coding.', 'nvoos-algorave' );
			echo '</p></div>';
		}
	}
}
