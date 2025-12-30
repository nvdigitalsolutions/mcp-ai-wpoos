<?php
/**
 * ChatKit integration bootstrap for NV oOS.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the NV oOS integration with ChatKit when available.
 */
class WP_MCP_AI_ChatKit_Integration {

	/**
	 * Unique identifier used when registering the integration with ChatKit.
	 */
	const ADDON_ID = 'wp-mcp-ai';

	/**
	 * Track whether the ChatKit integration has already been initialised.
	 *
	 * @var bool
	 */
	protected static $bootstrapped = false;

	/**
	 * Hook into WordPress to register the integration when ChatKit is available.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_bootstrap' ), 10 );
	}

	/**
	 * Initialise the integration unless explicitly disabled.
	 */
	public static function maybe_bootstrap() {
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
	 * Returning `false` from the filter disables the integration, allowing site
	 * owners or tests to opt-out even though the integration now ships directly with
	 * the core plugin.
	 *
	 * @return bool
	 */
	protected static function should_bootstrap() {
		/**
		 * Allow plugins/tests to force ChatKit bootstrap behaviour.
		 *
		 * @since 1.0.0 The filter now defaults to `true`, so returning `false`
		 *              disables the integration instead of enabling it.
		 *
		 * @param bool|null $available Whether the ChatKit integration should be
		 *                             bootstrapped. Default `null`.
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
	 * @param array $addons Previously registered integrations.
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
	 */
	public static function register_via_action( $manager = null ) {
		$definition = self::get_definition();

		if ( is_object( $manager ) && method_exists( $manager, 'register_addon' ) ) {
			$manager->register_addon( $definition );
			return;
		}

		/**
		 * Fires when the NV oOS ChatKit integration registers via an action.
		 *
		 * This mirrors the behaviour of ChatKit's manager objects so that tests or
		 * other integrations can react consistently when a manager is not present.
		 *
		 * @since 1.0.0
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
	 */
	public static function register_single_addon( $callback = null ) {
		$definition = self::get_definition();

		if ( is_callable( $callback ) ) {
			call_user_func( $callback, $definition );
			return;
		}

		do_action( 'wp_mcp_ai_chatkit_addon_registered', $definition, null );
	}

	/**
	 * Retrieve the integration definition passed to ChatKit.
	 *
	 * @return array
	 */
	public static function get_definition() {
		$rest_namespace = WP_MCP_AI_REST::REST_NAMESPACE;
		$capability     = wp_mcp_ai_get_required_chat_capability( 0, 'chatkit' );

		$definition = array(
			'id'             => self::ADDON_ID,
			'name'           => __( 'NV oOS', 'wp-mcp-ai' ),
			'description'    => __( 'Connect ChatKit workflows to NV oOS assistants.', 'wp-mcp-ai' ),
			'version'        => WP_MCP_AI_VERSION,
			'icon'           => WP_MCP_AI_URL . 'assets/images/ai-icon.svg',
			'rest_namespace' => $rest_namespace,
			'rest_routes'    => array(
				'chat'     => array(
					'method' => 'POST',
					'path'   => '/chat',
				),
				'tools'    => array(
					'method' => 'POST',
					'path'   => '/tools',
				),
				'download' => array(
					'method' => 'GET',
					'path'   => '/files/(?P<file_id>[^/]+)/download',
				),
			),
			'supports'       => array(
				'attachments'      => true,
				'guest_access'     => true,
				'tool_invocations' => true,
			),
			'surfaces'       => array(
				'shortcode'        => array(
					'type'        => 'shortcode',
					'label'       => __( 'Shortcode chat surface', 'wp-mcp-ai' ),
					'description' => __( 'Embed the NV oOS chat UI anywhere shortcodes render using [wp_mcp_ai_chat].', 'wp-mcp-ai' ),
					'tag'         => 'wp_mcp_ai_chat',
					'attributes'  => array(
						'assistant'       => array(
							'type'        => 'integer',
							'required'    => true,
							'label'       => __( 'Assistant ID', 'wp-mcp-ai' ),
							'description' => __( 'Numeric assistant ID passed to the shortcode when no global default is configured.', 'wp-mcp-ai' ),
						),
						'allow_guests'    => array(
							'type'        => 'boolean',
							'required'    => false,
							'label'       => __( 'Allow guests', 'wp-mcp-ai' ),
							'description' => __( 'Enable guest access and issue temporary tokens for unauthenticated visitors.', 'wp-mcp-ai' ),
							'default'     => false,
						),
						'save_transcript' => array(
							'type'        => 'boolean',
							'required'    => false,
							'label'       => __( 'Save transcript', 'wp-mcp-ai' ),
							'description' => __( 'Persist chat transcripts to the JetEngine Custom Content Type.', 'wp-mcp-ai' ),
							'default'     => true,
						),
					),
				),
				'elementor_widget' => array(
					'type'        => 'elementor_widget',
					'label'       => __( 'Elementor chat widget', 'wp-mcp-ai' ),
					'description' => __( 'Drop the NV oOS Chat widget into Elementor layouts to mirror the shortcode behaviour.', 'wp-mcp-ai' ),
					'widget'      => 'wp_mcp_ai_chat',
					'attributes'  => array(
						'assistant'       => array(
							'type'        => 'integer',
							'required'    => true,
							'label'       => __( 'Assistant control', 'wp-mcp-ai' ),
							'description' => __( 'Elementor control used to choose which assistant powers the chat surface.', 'wp-mcp-ai' ),
						),
						'allow_guests'    => array(
							'type'        => 'boolean',
							'required'    => false,
							'label'       => __( 'Allow guests control', 'wp-mcp-ai' ),
							'description' => __( 'Toggle that issues guest tokens when the assistant permits public access.', 'wp-mcp-ai' ),
							'default'     => false,
						),
						'save_transcript' => array(
							'type'        => 'boolean',
							'required'    => false,
							'label'       => __( 'Save transcript control', 'wp-mcp-ai' ),
							'description' => __( 'Control that stores chat exchanges in the ai_chat_transcripts Custom Content Type.', 'wp-mcp-ai' ),
							'default'     => true,
						),
					),
				),
			),
			'fields'         => array(
				'assistant_id'   => array(
					'type'        => 'integer',
					'required'    => true,
					'label'       => __( 'Assistant ID', 'wp-mcp-ai' ),
					'description' => __( 'ID of the AI Assistant to engage for ChatKit requests.', 'wp-mcp-ai' ),
				),
				'system_prompt'  => array(
					'type'        => 'string',
					'required'    => false,
					'label'       => __( 'System Prompt Override', 'wp-mcp-ai' ),
					'description' => __( 'Optional system prompt override applied to ChatKit initiated chats.', 'wp-mcp-ai' ),
				),
				'tool_shortcuts' => array(
					'type'        => 'array',
					'required'    => false,
					'label'       => __( 'Shortcut Presets', 'wp-mcp-ai' ),
					'description' => __( 'Tool shortcut presets exposed to ChatKit operators.', 'wp-mcp-ai' ),
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
		 * Filter the ChatKit integration definition before it is exported.
		 *
		 * @since 1.0.0
		 *
		 * @param array $definition ChatKit integration definition.
		 */
		return apply_filters( 'wp_mcp_ai_chatkit_addon_definition', $definition );
	}

	/**
	 * Reset the bootstrap state.
	 *
	 * Exposed primarily for automated tests to ensure a predictable bootstrap
	 * cycle when multiple scenarios are executed in the same process.
	 */
	public static function reset_state_for_testing() {
		self::$bootstrapped = false;

		remove_filter( 'chatkit_register_addons', array( __CLASS__, 'register_via_filter' ) );
		remove_filter( 'chatkit_addons', array( __CLASS__, 'register_via_filter' ) );
		remove_action( 'chatkit/register_addons', array( __CLASS__, 'register_via_action' ) );
		remove_action( 'chatkit/register_addon', array( __CLASS__, 'register_single_addon' ) );
	}
}

if ( ! class_exists( 'WP_MCP_AI_ChatKit_Addon' ) ) {
	class_alias( 'WP_MCP_AI_ChatKit_Integration', 'WP_MCP_AI_ChatKit_Addon' );
}
