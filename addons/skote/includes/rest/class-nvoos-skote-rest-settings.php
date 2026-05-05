<?php
/**
 * NV oOS Skote — REST: Settings, Me, Apps
 *
 * Routes registered under `/wp-json/nvoos-skote/v1/`:
 *
 *   GET  /settings   — current user prefs + site-level UI defaults.
 *   POST /settings   — update prefs (per-user) + site UI defaults (admin only).
 *   GET  /me         — current user identity, role, capabilities relevant
 *                      to the SPA.
 *   GET  /apps       — enumerate enabled "apps" (Dashboard, Tasks, Calendar,
 *                      Contacts, Workflows, Tools…) gated by the active
 *                      integrations and Pro presence.
 *
 * @package NV_oOS_Skote
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings / Me / Apps controller.
 *
 * @since 0.1.0
 */
class NVOOS_Skote_REST_Settings extends NVOOS_Skote_REST_Base {

	/**
	 * Allowed keys in the per-user prefs payload.
	 *
	 * @var string[]
	 */
	const ALLOWED_USER_PREFS = array(
		'theme',
		'layout',
		'sidebarCollapsed',
		'rtl',
		'topbar',
		'language',
	);

	/**
	 * Allowed keys in the site-level settings payload.
	 *
	 * @var string[]
	 */
	const ALLOWED_SITE_SETTINGS = array(
		'defaultApp',
		'brandName',
		'brandLogoUrl',
	);

	/**
	 * Register routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			NVOOS_SKOTE_REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => self::require_cap( 'read' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_settings' ),
					'permission_callback' => self::require_cap( 'read' ),
					'args'                => array(
						'user' => array(
							'type'        => 'object',
							'required'    => false,
							'description' => __( 'Per-user preferences.', 'nvoos-skote' ),
						),
						'site' => array(
							'type'        => 'object',
							'required'    => false,
							'description' => __( 'Site-level defaults (admin only).', 'nvoos-skote' ),
						),
					),
				),
			)
		);

		register_rest_route(
			NVOOS_SKOTE_REST_NAMESPACE,
			'/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_me' ),
				'permission_callback' => self::require_cap( 'read' ),
			)
		);

		register_rest_route(
			NVOOS_SKOTE_REST_NAMESPACE,
			'/apps',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_apps' ),
				'permission_callback' => self::require_cap( 'read' ),
			)
		);
	}

	/**
	 * GET /settings.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings( $request ) {
		unset( $request );
		$user_id = get_current_user_id();
		$user    = $user_id ? get_user_meta( $user_id, NV_oOS_Skote::USER_META_PREFS, true ) : array();
		if ( ! is_array( $user ) ) {
			$user = array();
		}
		$site = get_option( NV_oOS_Skote::OPTION_SETTINGS, array() );
		if ( ! is_array( $site ) ) {
			$site = array();
		}

		return self::success(
			array(
				'user' => self::whitelist( $user, self::ALLOWED_USER_PREFS ),
				'site' => self::whitelist( $site, self::ALLOWED_SITE_SETTINGS ),
			)
		);
	}

	/**
	 * POST /settings.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_settings( $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return self::error( 'nvoos_skote_no_user', __( 'No authenticated user.', 'nvoos-skote' ), 401 );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		// Per-user prefs.
		if ( isset( $body['user'] ) && is_array( $body['user'] ) ) {
			$current = get_user_meta( $user_id, NV_oOS_Skote::USER_META_PREFS, true );
			if ( ! is_array( $current ) ) {
				$current = array();
			}
			$incoming  = self::whitelist( $body['user'], self::ALLOWED_USER_PREFS );
			$sanitized = array();
			foreach ( $incoming as $key => $value ) {
				if ( is_bool( $value ) ) {
					$sanitized[ $key ] = (bool) $value;
				} elseif ( is_scalar( $value ) ) {
					$sanitized[ $key ] = self::sanitize_text( $value );
				}
			}
			update_user_meta( $user_id, NV_oOS_Skote::USER_META_PREFS, array_merge( $current, $sanitized ) );
		}

		// Site-level settings — admin only.
		if ( isset( $body['site'] ) && is_array( $body['site'] ) ) {
			if ( ! current_user_can( NV_oOS_Skote::get_admin_capability() ) ) {
				return self::error(
					'nvoos_skote_site_forbidden',
					__( 'You cannot edit site-wide settings.', 'nvoos-skote' ),
					rest_authorization_required_code()
				);
			}
			$current = get_option( NV_oOS_Skote::OPTION_SETTINGS, array() );
			if ( ! is_array( $current ) ) {
				$current = array();
			}
			$incoming  = self::whitelist( $body['site'], self::ALLOWED_SITE_SETTINGS );
			$sanitized = array();
			foreach ( $incoming as $key => $value ) {
				if ( 'brandLogoUrl' === $key ) {
					$sanitized[ $key ] = esc_url_raw( (string) $value );
				} elseif ( is_scalar( $value ) ) {
					$sanitized[ $key ] = self::sanitize_text( $value );
				}
			}
			update_option( NV_oOS_Skote::OPTION_SETTINGS, array_merge( $current, $sanitized ) );
		}

		return self::get_settings( $request );
	}

	/**
	 * GET /me.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_me( $request ) {
		unset( $request );
		$user = wp_get_current_user();

		$caps = array();
		if ( $user && isset( $user->allcaps ) && is_array( $user->allcaps ) ) {
			$caps = array_keys( array_filter( $user->allcaps ) );
		}

		return self::success(
			array(
				'id'           => (int) $user->ID,
				'displayName'  => (string) $user->display_name,
				'email'        => (string) $user->user_email,
				'roles'        => is_array( $user->roles ) ? array_values( $user->roles ) : array(),
				'capabilities' => $caps,
				'avatar'       => get_avatar_url( $user->ID ),
				'locale'       => get_user_locale( $user ),
			)
		);
	}

	/**
	 * GET /apps.
	 *
	 * Returns the catalogue of "apps" the SPA should expose, filtered by the
	 * site's active integrations and current-user caps.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_apps( $request ) {
		unset( $request );

		$apps = array(
			array(
				'slug'        => 'dashboard',
				'label'       => __( 'Dashboard', 'nvoos-skote' ),
				'icon'        => 'home',
				'route'       => '#/dashboard',
				'enabled'     => true,
				'capability'  => 'read',
			),
			array(
				'slug'        => 'tasks',
				'label'       => __( 'Tasks', 'nvoos-skote' ),
				'icon'        => 'kanban',
				'route'       => '#/apps/tasks',
				'enabled'     => true,
				'capability'  => 'edit_posts',
			),
			array(
				'slug'        => 'calendar',
				'label'       => __( 'Calendar', 'nvoos-skote' ),
				'icon'        => 'calendar',
				'route'       => '#/apps/calendar',
				'enabled'     => true,
				'capability'  => 'edit_posts',
			),
			array(
				'slug'        => 'contacts',
				'label'       => __( 'Contacts', 'nvoos-skote' ),
				'icon'        => 'users',
				'route'       => '#/apps/contacts',
				'enabled'     => true,
				'capability'  => 'list_users',
			),
			array(
				'slug'        => 'ecommerce',
				'label'       => __( 'Ecommerce', 'nvoos-skote' ),
				'icon'        => 'cart',
				'route'       => '#/apps/ecommerce',
				'enabled'     => NV_oOS_Skote::is_woocommerce_active(),
				'capability'  => 'manage_woocommerce',
				'requires'    => 'woocommerce',
			),
			array(
				'slug'        => 'workflows',
				'label'       => __( 'Workflows', 'nvoos-skote' ),
				'icon'        => 'sitemap',
				'route'       => '#/apps/workflows',
				'enabled'     => NV_oOS_Skote::is_pro_active(),
				'capability'  => 'manage_options',
				'requires'    => 'pro',
			),
			array(
				'slug'        => 'tools',
				'label'       => __( 'Tools', 'nvoos-skote' ),
				'icon'        => 'tools',
				'route'       => '#/apps/tools',
				'enabled'     => NV_oOS_Skote::is_pro_active(),
				'capability'  => 'manage_options',
				'requires'    => 'pro',
			),
			array(
				'slug'        => 'approvals',
				'label'       => __( 'Approvals', 'nvoos-skote' ),
				'icon'        => 'shield',
				'route'       => '#/apps/approvals',
				'enabled'     => NV_oOS_Skote::is_pro_active(),
				'capability'  => 'manage_options',
				'requires'    => 'pro',
			),
		);

		// Filter to those the current user can use.
		$visible = array();
		foreach ( $apps as $app ) {
			if ( ! empty( $app['capability'] ) && ! current_user_can( $app['capability'] ) ) {
				continue;
			}
			$visible[] = $app;
		}

		/**
		 * Filters the catalogue of Skote apps surfaced to the SPA.
		 *
		 * @since 0.1.0
		 *
		 * @param array $visible Apps the current user is permitted to see.
		 * @param array $apps    The full pre-filter catalogue.
		 */
		$visible = (array) apply_filters( 'nvoos_skote_apps_catalogue', $visible, $apps );

		return self::success( $visible );
	}
}
