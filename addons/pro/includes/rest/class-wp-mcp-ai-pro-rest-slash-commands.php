<?php
/**
 * Pro SPA REST Controller — Slash Commands
 *
 * Serves registered slash commands for the Pro SPA v2 Slash Commands drawer.
 *
 * GET /mcp-ai-pro/v1/slash-commands
 *
 * @package NV_oOS_Pro
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_REST_Slash_Commands
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_REST_Slash_Commands {

	/**
	 * REST namespace.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Route base.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const ROUTE = '/slash-commands';

	/**
	 * Register REST routes.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_list' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Permission check — user must be logged in with edit_posts capability.
	 *
	 * @since 2.1.0
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public static function permission_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have sufficient permissions.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle GET /slash-commands.
	 *
	 * @since 2.1.0
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_list( $request ) {
		$search = $request->get_param( 'search' );

		$handler  = self::get_handler();
		$commands = array();

		if ( $handler ) {
			$raw = $handler->get_commands();

			foreach ( $raw as $name => $config ) {
				if ( ! isset( $config['handler'] ) || ! is_callable( $config['handler'] ) ) {
					continue;
				}

				$description = isset( $config['description'] ) ? trim( (string) $config['description'] ) : '';
				$usage       = isset( $config['usage'] ) ? trim( (string) $config['usage'] ) : '/' . $name;
				$capability  = isset( $config['capability'] ) ? trim( (string) $config['capability'] ) : 'edit_posts';
				$parameters  = isset( $config['parameters'] ) && is_array( $config['parameters'] )
					? array_map( 'strval', $config['parameters'] )
					: array();

				// Skip commands the current user cannot execute.
				if ( ! empty( $capability ) && ! current_user_can( $capability ) ) {
					continue;
				}

				// Determine category from the command name or config.
				$category = __( 'General', 'mcp-ai-wpoos' );
				if ( strpos( $name, 'debug' ) === 0 || strpos( $name, 'log' ) === 0 ) {
					$category = __( 'System', 'mcp-ai-wpoos' );
				} elseif ( strpos( $name, 'post' ) === 0 || strpos( $name, 'page' ) === 0 || strpos( $name, 'content' ) === 0 ) {
					$category = __( 'Content', 'mcp-ai-wpoos' );
				} elseif ( strpos( $name, 'tool' ) === 0 || strpos( $name, 'run' ) === 0 ) {
					$category = __( 'Tools', 'mcp-ai-wpoos' );
				} elseif ( strpos( $name, 'memory' ) === 0 || strpos( $name, 'remember' ) === 0 || strpos( $name, 'recall' ) === 0 ) {
					$category = __( 'Memory', 'mcp-ai-wpoos' );
				} elseif ( strpos( $name, 'help' ) === 0 || strpos( $name, 'clear' ) === 0 ) {
					$category = __( 'System', 'mcp-ai-wpoos' );
				}

				// Apply search filter.
				if ( ! empty( $search ) ) {
					$search_lower = mb_strtolower( $search );
					$match        = mb_strtolower( $name . ' ' . $description );
					if ( false === mb_strpos( $match, $search_lower ) ) {
						continue;
					}
				}

				$commands[] = array(
					'command'     => $name,
					'description' => $description,
					'usage'       => $usage,
					'parameters'  => $parameters,
					'category'    => $category,
				);
			}
		}

		// Collect unique categories.
		$categories = array();
		foreach ( $commands as $cmd ) {
			if ( ! in_array( $cmd['category'], $categories, true ) ) {
				$categories[] = $cmd['category'];
			}
		}
		sort( $categories );

		return new \WP_REST_Response(
			array(
				'commands'   => $commands,
				'categories' => $categories,
			),
			200
		);
	}

	/**
	 * Get the slash command handler instance.
	 *
	 * @since 2.1.0
	 * @return \WP_MCP_AI_Slash_Command_Handler|null
	 */
	private static function get_handler() {
		global $wp_mcp_ai_slash_command_handler;

		if ( $wp_mcp_ai_slash_command_handler instanceof \WP_MCP_AI_Slash_Command_Handler ) {
			return $wp_mcp_ai_slash_command_handler;
		}

		if ( class_exists( 'WP_MCP_AI_Slash_Command_Handler' ) ) {
			$wp_mcp_ai_slash_command_handler = new \WP_MCP_AI_Slash_Command_Handler();
			return $wp_mcp_ai_slash_command_handler;
		}

		return null;
	}
}
