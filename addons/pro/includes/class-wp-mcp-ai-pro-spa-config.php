<?php
/**
 * Pro SPA v2 Config — Shared runtime-config builder for the admin SPA page
 * and the [nvoos_pro_spa] front-end shortcode.
 *
 * Single source of truth for the `NVOOS_PRO_SPA` runtime shape consumed by
 * `addons/pro/assets/spa-v2/src/api/config.ts` (readProSpaConfig). The admin
 * loader and the shortcode both call `build()` so the two surfaces can never
 * drift apart.
 *
 * @package NV_oOS_Pro
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_SPA_Config
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_SPA_Config {

	/**
	 * Script handle for the esbuild IIFE bundle.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const SCRIPT_HANDLE = 'wp-mcp-ai-pro-spa-v2';

	/**
	 * Style handle for the extracted stylesheet.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const STYLE_HANDLE = 'wp-mcp-ai-pro-spa-v2';

	/**
	 * JS global name used by wp_localize_script.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const CONFIG_GLOBAL = 'NVOOS_PRO_SPA';

	/**
	 * Per-instance mode values accepted by the SPA.
	 *
	 * @since 2.1.0
	 * @var array
	 */
	const ALLOWED_MODES = array( 'admin', 'embedded' );

	/**
	 * Per-instance theme values accepted by the SPA.
	 *
	 * @since 2.1.0
	 * @var array
	 */
	const ALLOWED_THEMES = array( 'auto', 'light', 'dark' );

	/**
	 * Build the full NVOOS_PRO_SPA runtime array.
	 *
	 * @since 2.1.0
	 *
	 * @param array $per_instance {
	 *     Optional. Per-instance (shortcode) overrides.
	 *
	 *     @type int    $assistant_id          Assistant post ID (0 = server default).
	 *     @type string $mode                  'admin' or 'embedded'.
	 *     @type string $theme                 'auto', 'light', or 'dark'.
	 *     @type string $height                Optional CSS height for embedded instances.
	 *     @type bool   $guest                 Whether to enable guest access.
	 *     @type bool   $allow_sensitive_tools Whether sensitive tools are permitted.
	 *     @type bool   $show_sidebar          Whether embedded mode renders the transcripts sidebar.
	 *     @type array  $routes                Route allowlist for the instance.
	 * }
	 * @return array Runtime array for wp_localize_script.
	 */
	public static function build( array $per_instance = array() ) {
		$user     = wp_get_current_user();
		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'manage_options' );

		$assistant_id = isset( $per_instance['assistant_id'] ) ? absint( $per_instance['assistant_id'] ) : 0;

		// Fall back to the server-side default assistant when none requested.
		if ( 0 === $assistant_id && class_exists( 'WP_MCP_AI_Assistant_Manager' ) ) {
			$default = WP_MCP_AI_Assistant_Manager::get_default_assistant( $user_id );
			if ( $default ) {
				$assistant_id = absint( $default );
			}
		}

		// ---- guest access -------------------------------------------------
		$guest       = ! empty( $per_instance['guest'] );
		$guest_token = '';
		if ( $guest ) {
			$guest_token = self::mint_guest_token( $assistant_id );
			if ( '' === $guest_token ) {
				// Guest mode requested but unavailable — degrade to the
				// authenticated surface (guests will simply see no token).
				$guest = false;
			}
		}

		// ---- theme / mode clamps -------------------------------------------
		$theme = isset( $per_instance['theme'] ) ? sanitize_key( (string) $per_instance['theme'] ) : 'auto';
		if ( ! in_array( $theme, self::ALLOWED_THEMES, true ) ) {
			$theme = 'auto';
		}

		$mode = isset( $per_instance['mode'] ) ? sanitize_key( (string) $per_instance['mode'] ) : 'admin';
		if ( ! in_array( $mode, self::ALLOWED_MODES, true ) ) {
			$mode = 'embedded';
		}
		// The full admin surface (settings/tools/assistants/workflows) is
		// admin tooling — never expose it to non-admins, even on the front end.
		if ( 'admin' === $mode && ! $is_admin ) {
			$mode = 'embedded';
		}

		$routes = isset( $per_instance['routes'] ) && is_array( $per_instance['routes'] )
			? array_values( array_filter( array_map( 'sanitize_key', $per_instance['routes'] ) ) )
			: array( 'chat' );
		$routes = array_values( array_unique( $routes ) );

		// ---- per-instance SPA config ----------------------------------------
		$config = array(
			'assistantId'         => $assistant_id,
			'theme'               => $theme,
			'allowSensitiveTools' => ! empty( $per_instance['allow_sensitive_tools'] ),
			'mode'                => $mode,
			'height'              => isset( $per_instance['height'] ) ? sanitize_text_field( $per_instance['height'] ) : '',
			'showSidebar'         => ! isset( $per_instance['show_sidebar'] ) || ! empty( $per_instance['show_sidebar'] ),
			'routes'              => $routes,
		);

		if ( $guest ) {
			$config['guest']      = true;
			$config['guestToken'] = $guest_token;
		}

		// ---- assistants pre-load (skip for guests — no auth surface) -------
		$assistants = array();
		if ( ! $guest && post_type_exists( 'mcp_ai_assistant' ) && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);

			foreach ( $query->posts as $post ) {
				$assistant_cfg = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post->ID );
				$provider      = isset( $assistant_cfg['provider'] ) ? sanitize_key( $assistant_cfg['provider'] ) : '';
				$model         = isset( $assistant_cfg['model'] ) ? (string) $assistant_cfg['model'] : '';

				$assistants[] = array(
					'id'       => $post->ID,
					'title'    => get_the_title( $post ),
					'provider' => $provider,
					'model'    => $model,
				);
			}
		}

		// ---- runtime --------------------------------------------------------
		$runtime = array(
			'apiUrl'       => esc_url_raw( rest_url( 'mcp-ai/v1' ) ),
			'proApi'       => esc_url_raw( rest_url( 'mcp-ai-pro/v1' ) ),
			'nonce'        => $guest ? '' : wp_create_nonce( 'wp_rest' ),
			'config'       => $config,
			'endpoints'    => array(
				// Core chat endpoints (mcp-ai/v1).
				'chat'          => esc_url_raw( rest_url( 'mcp-ai/v1/chat' ) ),
				'chatClient'    => esc_url_raw( rest_url( 'mcp-ai/v1/chat-client' ) ),
				'transcripts'   => $guest ? '' : esc_url_raw( rest_url( 'mcp-ai/v1/chat-transcripts' ) ),
				'memory'        => $guest ? '' : esc_url_raw( rest_url( 'mcp-ai/v1/chat-memory' ) ),
				'threads'       => $guest ? '' : esc_url_raw( rest_url( 'mcp-ai/v1/threads' ) ),
				'tools'         => esc_url_raw( rest_url( 'mcp-ai/v1/tools' ) ),
				'assistants'    => $guest ? '' : esc_url_raw( rest_url( 'mcp-ai/v1/assistants' ) ),
				'settings'      => $guest ? '' : esc_url_raw( rest_url( 'mcp-ai/v1/settings' ) ),

				// WordPress media upload endpoint (matches legacy chat-spa).
				'upload'        => esc_url_raw( rest_url( 'wp/v2/media' ) ),

				// Pro endpoints (mcp-ai-pro/v1).
				'workflows'     => $is_admin
					? esc_url_raw( rest_url( 'mcp-ai-pro/v1/workflows' ) )
					: '',
				'analytics'     => $is_admin
					? esc_url_raw( rest_url( 'mcp-ai-pro/v1/analytics' ) )
					: '',
				'approvals'     => $is_admin && ! $guest
					? esc_url_raw( rest_url( 'mcp-ai/v1/approvals' ) )
					: '',
				'shortcuts'     => esc_url_raw( rest_url( 'mcp-ai-pro/v1/tool-shortcuts' ) ),
				'slashCommands' => esc_url_raw( rest_url( 'mcp-ai-pro/v1/slash-commands' ) ),
				'okf'           => class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' )
					? esc_url_raw( rest_url( 'mcp-ai-pro/v1/okf' ) )
					: '',
			),
			'user'         => array(
				'id'           => $guest ? 0 : $user_id,
				'login'        => $guest ? '' : $user->user_login,
				'displayName'  => $guest ? '' : $user->display_name,
				'capabilities' => $guest ? array() : array_keys( $user->allcaps ),
				'assistant_id' => $assistant_id,
			),
			'mentionTypes' => array(),
			'assistants'   => $assistants,
		);

		// Populate mention types if the resolver is available.
		if ( class_exists( 'WP_MCP_AI_Context_Mention_Resolver' ) ) {
			$resolver                = new WP_MCP_AI_Context_Mention_Resolver();
			$types                   = $resolver->get_registered_types();
			$runtime['mentionTypes'] = is_array( $types ) ? $types : array();
		}

		/**
		 * Filter the runtime config before it is localized.
		 *
		 * @since 2.1.0
		 *
		 * @param array $runtime      Runtime array for the NVOOS_PRO_SPA global.
		 * @param array $per_instance Per-instance overrides passed to build().
		 */
		return apply_filters( 'wp_mcp_ai_pro_spa_runtime', $runtime, $per_instance );
	}

	/**
	 * Register the SPA v2 assets. Shared by the admin loader and the shortcode.
	 *
	 * @since 2.1.0
	 *
	 * @return bool True when the JS bundle exists and assets were registered.
	 */
	public static function register_assets() {
		$dist_dir = WP_MCP_AI_PRO_PATH . 'assets/spa-v2/assets/dist/';
		$dist_url = WP_MCP_AI_PRO_URL . 'assets/spa-v2/assets/dist/';

		$js_path  = $dist_dir . 'pro-spa.js';
		$css_path = $dist_dir . 'pro-spa.css';

		if ( ! file_exists( $js_path ) ) {
			if ( is_admin() ) {
				add_action(
					'admin_notices',
					function () {
						printf(
							'<div class="notice notice-warning"><p>%s</p></div>',
							esc_html__( 'NV oOS Pro SPA v2 assets not found. Run `npm run build` in addons/pro/assets/spa-v2/.', 'mcp-ai-wpoos' )
						);
					}
				);
			}
			return false;
		}

		// Use file modification time for cache-busting so browsers pick up
		// new builds immediately, even on sites where WP_MCP_AI_PRO_VERSION
		// hasn't been bumped.
		$version = defined( 'WP_MCP_AI_PRO_VERSION' ) ? WP_MCP_AI_PRO_VERSION : '2.0.0';
		$js_ver  = filemtime( $js_path ) ? filemtime( $js_path ) : $version;
		$css_ver = file_exists( $css_path ) ? filemtime( $css_path ) : $version;

		wp_register_script(
			self::SCRIPT_HANDLE,
			$dist_url . 'pro-spa.js',
			array( 'wp-i18n' ),
			$js_ver,
			true
		);

		wp_register_style(
			self::STYLE_HANDLE,
			$dist_url . 'pro-spa.css',
			array(),
			$css_ver
		);

		return true;
	}

	/**
	 * Enqueue the SPA assets and localize the runtime config.
	 *
	 * @since 2.1.0
	 *
	 * @param array $runtime Runtime array from build().
	 * @return void
	 */
	public static function enqueue( array $runtime ) {
		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'nvoos-pro-spa',
			WP_MCP_AI_PRO_PATH . 'languages'
		);

		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_style( self::STYLE_HANDLE );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			self::CONFIG_GLOBAL,
			$runtime
		);
	}

	/**
	 * Mint a guest access token for the given assistant, respecting the
	 * global guest-access security setting.
	 *
	 * @since 2.1.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return string Guest token or empty string when unavailable/disabled.
	 */
	public static function mint_guest_token( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		if ( ! $assistant_id ) {
			return '';
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		// The security section defaults this to true; treat a missing key as
		// enabled so pre-existing installs behave the same as before.
		if ( isset( $settings['allow_guest_access'] ) && empty( $settings['allow_guest_access'] ) ) {
			return '';
		}

		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			return '';
		}

		$token = WP_MCP_AI_Shortcode::generate_guest_token( $assistant_id );

		return is_string( $token ) ? $token : '';
	}
}
