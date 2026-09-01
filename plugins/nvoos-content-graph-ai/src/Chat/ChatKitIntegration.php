<?php
/**
 * ChatKit integration bootstrap for the NV oOS Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-chatkit-integration.php` (behaviour-preserving;
 * base copy is retained permanently — ecosystem port plan D-NOBASE).
 *
 * Decoupling (documented, additive):
 * - Registration is wired standalone-only by `Plugin.php` — in monolith
 *   installs the base plugin owns the same ChatKit hooks and registering
 *   here too would publish two competing addons.
 * - `ADDON_ID` is `nvoos-content-graph-ai` (the base's `mcp-ai-wpoos` ID
 *   belongs to the base plugin in ChatKit's registry; the ported addon
 *   must not impersonate it).
 * - The definition describes this addon's real surface: REST namespace
 *   `nvoos-content-graph/v1`, routes `POST /ai/chat` and
 *   `GET /ai/tools`. The base's `files/{file_id}/download` route, the
 *   shortcode/Elementor `surfaces`, the required `assistant_id` field,
 *   and `supports.guest_access` are omitted until the corresponding
 *   waves land (D2 file APIs, D-UI surfaces, D5 guest tokens) — they are
 *   restored as those subsystems are ported.
 * - The capability read goes through `get_capability()` — the base
 *   helper in monolith installs, the same `wp_mcp_ai_chat_capability`
 *   filter with the base's `edit_posts` default in standalone installs,
 *   so site-owner capability overrides behave identically.
 * - Hook names (`chatkit_register_addons`, `chatkit_addons`,
 *   `chatkit/register_addons`, `chatkit/register_addon`,
 *   `wp_mcp_ai_chatkit_is_available`, `wp_mcp_ai_chatkit_addon_registered`,
 *   `wp_mcp_ai_chatkit_addon_definition`) are byte-identical to the base —
 *   ChatKit discovers integrations through these names.
 *
 * @package NvoosContentGraphAi\Chat
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Content Graph AI integration with ChatKit when available.
 *
 * @since 1.1.0
 */
class ChatKitIntegration {

	/**
	 * Unique identifier used when registering the integration with ChatKit.
	 *
	 * @var string
	 */
	const ADDON_ID = 'nvoos-content-graph-ai';

	/**
	 * Track whether the ChatKit integration has already been initialised.
	 *
	 * @var bool
	 */
	protected static $bootstrapped = false;

	/**
	 * Hook into WordPress to register the integration when ChatKit is available.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_bootstrap' ), 10 );
	}

	/**
	 * Initialise the integration unless explicitly disabled.
	 *
	 * @return void
	 */
	public static function maybe_bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		if ( ! self::should_bootstrap() ) {
			return;
		}

		add_filter( 'chatkit_register_addons', array( __CLASS__, 'register_via_filter' ) );
		add_filter( 'chatkit_addons', array( __CLASS__, 'register_via_filter' ) );
		add_action( 'chatkit/register_addons', array( __CLASS__, 'register_via_action' ), 10, 1 );
		add_action( 'chatkit/register_addon', array( __CLASS__, 'register_single_addon' ), 10, 1 );

		self::$bootstrapped = true;
	}

	/**
	 * Determine whether the ChatKit integration should bootstrap.
	 *
	 * Returning `false` from the filter disables the integration, allowing
	 * site owners or tests to opt-out (filter name matches the base plugin).
	 *
	 * @return bool
	 */
	protected static function should_bootstrap(): bool {
		/**
		 * Allow plugins/tests to force ChatKit bootstrap behaviour.
		 *
		 * @since 1.1.0 The filter now defaults to `true`, so returning
		 *              `false` disables the integration instead of
		 *              enabling it.
		 *
		 * @param bool|null $available Whether the ChatKit integration
		 *                             should be bootstrapped. Default `null`.
		 */
		$available = apply_filters( 'wp_mcp_ai_chatkit_is_available', null );

		if ( null === $available ) {
			return true;
		}

		return (bool) $available;
	}

	/**
	 * Register the integration through a filter-based API.
	 *
	 * @param mixed $addons Previously registered integrations.
	 * @return array
	 */
	public static function register_via_filter( $addons ) {
		if ( ! is_array( $addons ) ) {
			$addons = array();
		}

		$addons[ self::ADDON_ID ] = self::get_definition();

		return $addons;
	}

	/**
	 * Register the integration via an action-style API.
	 *
	 * @param mixed $manager Optional add-on manager instance provided by ChatKit.
	 * @return void
	 */
	public static function register_via_action( $manager = null ): void {
		$definition = self::get_definition();

		if ( is_object( $manager ) && method_exists( $manager, 'register_addon' ) ) {
			$manager->register_addon( $definition );
			return;
		}

		/**
		 * Fires when the Content Graph AI ChatKit integration registers via
		 * an action (mirrors the base plugin's hook name).
		 *
		 * @since 1.1.0
		 *
		 * @param array $definition Registered integration definition.
		 * @param mixed $manager    Manager instance passed to the action, if any.
		 */
		do_action( 'wp_mcp_ai_chatkit_addon_registered', $definition, $manager );
	}

	/**
	 * Register a single integration using callback style semantics.
	 *
	 * @param callable|null $callback Optional callback provided by ChatKit.
	 * @return void
	 */
	public static function register_single_addon( $callback = null ): void {
		$definition = self::get_definition();

		if ( is_callable( $callback ) ) {
			call_user_func( $callback, $definition );
			return;
		}

		do_action( 'wp_mcp_ai_chatkit_addon_registered', $definition, null );
	}

	/**
	 * Resolve the capability required to use the chat surface.
	 *
	 * Monolith installs delegate to the base plugin's helper so its
	 * `wp_mcp_ai_chat_capability` filter and sanitization stay
	 * authoritative; standalone installs apply the same filter with the
	 * base's `edit_posts` default.
	 *
	 * @return string|false Capability string, `'public'`, or a falsy value.
	 */
	protected static function get_capability() {
		if ( function_exists( 'wp_mcp_ai_get_required_chat_capability' ) ) {
			return \wp_mcp_ai_get_required_chat_capability( 0, 'chatkit' );
		}

		$capability = apply_filters( 'wp_mcp_ai_chat_capability', 'edit_posts', 0, 'chatkit' );

		if ( is_string( $capability ) ) {
			$capability = sanitize_key( $capability );
		}

		return $capability;
	}

	/**
	 * Retrieve the integration definition passed to ChatKit.
	 *
	 * @return array
	 */
	public static function get_definition() {
		$capability = static::get_capability();

		$definition = array(
			'id'             => self::ADDON_ID,
			'name'           => __( 'NV oOS Content Graph AI', 'nvoos-content-graph-ai' ),
			'description'    => __( 'Connect ChatKit workflows to the NV oOS Content Graph AI chat surface.', 'nvoos-content-graph-ai' ),
			'version'        => NVOOS_CONTENT_GRAPH_AI_VERSION,
			'icon'           => NVOOS_CONTENT_GRAPH_AI_URL . 'assets/images/ai-icon.svg',
			'rest_namespace' => 'nvoos-content-graph/v1',
			'rest_routes'    => array(
				'chat'  => array(
					'method' => 'POST',
					'path'   => '/ai/chat',
				),
				'tools' => array(
					'method' => 'GET',
					'path'   => '/ai/tools',
				),
			),
			'supports'       => array(
				'attachments'      => true,
				'guest_access'     => false,
				'tool_invocations' => true,
			),
			// UI surfaces (shortcode, Elementor widget) are advertised by
			// the base plugin; the CG-AI chat UI port populates these in
			// the D-UI wave (ecosystem port plan).
			'surfaces'       => array(),
			'fields'         => array(
				'system_prompt'  => array(
					'type'        => 'string',
					'required'    => false,
					'label'       => __( 'System Prompt Override', 'nvoos-content-graph-ai' ),
					'description' => __( 'Optional system prompt override applied to ChatKit initiated chats.', 'nvoos-content-graph-ai' ),
				),
				'tool_shortcuts' => array(
					'type'        => 'array',
					'required'    => false,
					'label'       => __( 'Shortcut Presets', 'nvoos-content-graph-ai' ),
					'description' => __( 'Tool shortcut presets exposed to ChatKit operators.', 'nvoos-content-graph-ai' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'label'       => array(
								'type' => 'string',
							),
							'payload'     => array(
								'type' => 'string',
							),
							'description' => array(
								'type' => 'string',
							),
						),
						'additionalProperties' => false,
					),
					'default'     => array(),
				),
			),
		);

		if ( $capability ) {
			$definition['capability'] = $capability;
		}

		/**
		 * Filter the ChatKit integration definition before it is exported
		 * (hook name matches the base plugin).
		 *
		 * @since 1.1.0
		 *
		 * @param array $definition ChatKit integration definition.
		 */
		return apply_filters( 'wp_mcp_ai_chatkit_addon_definition', $definition );
	}

	/**
	 * Reset the bootstrap state.
	 *
	 * Exposed primarily for automated tests to ensure a predictable
	 * bootstrap cycle when multiple scenarios are executed in the same
	 * process.
	 *
	 * @return void
	 */
	public static function reset_state_for_testing(): void {
		self::$bootstrapped = false;

		remove_filter( 'chatkit_register_addons', array( __CLASS__, 'register_via_filter' ) );
		remove_filter( 'chatkit_addons', array( __CLASS__, 'register_via_filter' ) );
		remove_action( 'chatkit/register_addons', array( __CLASS__, 'register_via_action' ) );
		remove_action( 'chatkit/register_addon', array( __CLASS__, 'register_single_addon' ) );
	}
}
