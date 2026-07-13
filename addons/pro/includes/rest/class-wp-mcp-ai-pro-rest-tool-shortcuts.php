<?php
/**
 * Pro SPA REST Controller — Tool Shortcuts
 *
 * Serves assistant-scoped tool shortcuts for the Pro SPA v2 Tool Shortcuts drawer.
 *
 * GET /mcp-ai-pro/v1/tool-shortcuts?assistant_id=N
 *
 * @package NV_oOS_Pro
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_REST_Tool_Shortcuts
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_REST_Tool_Shortcuts {

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
	const ROUTE = '/tool-shortcuts';

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
					'assistant_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'search'       => array(
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
	 * Handle GET /tool-shortcuts.
	 *
	 * @since 2.1.0
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_list( $request ) {
		$assistant_id = $request->get_param( 'assistant_id' );
		$search       = $request->get_param( 'search' );

		// If no assistant_id provided, use the current user's default.
		if ( empty( $assistant_id ) ) {
			$user_id = get_current_user_id();
			if ( class_exists( 'WP_MCP_AI_Assistant_Manager' ) ) {
				$assistant_id = \WP_MCP_AI_Assistant_Manager::get_default_assistant( $user_id );
			}
		}

		$assistant_id = absint( $assistant_id );

		if ( ! $assistant_id ) {
			return new \WP_REST_Response(
				array(
					'shortcuts'    => array(),
					'categories'   => array(),
					'assistant_id' => 0,
				),
				200
			);
		}

		// Delegate to the existing shortcut builder.
		$raw_shortcuts = array();
		if ( method_exists( 'WP_MCP_AI_Shortcode', 'get_assistant_tool_shortcuts' ) ) {
			$raw_shortcuts = \WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );
		}

		// Normalize shortcuts to the expected shape.
		$shortcuts  = array();
		$categories = array();

		foreach ( $raw_shortcuts as $shortcut ) {
			if ( ! is_array( $shortcut ) ) {
				continue;
			}

			$label       = isset( $shortcut['label'] ) ? trim( (string) $shortcut['label'] ) : '';
			$payload     = isset( $shortcut['payload'] ) ? trim( (string) $shortcut['payload'] ) : '';
			$tool        = isset( $shortcut['tool'] ) ? trim( (string) $shortcut['tool'] ) : '';
			$description = isset( $shortcut['description'] ) ? trim( (string) $shortcut['description'] ) : '';
			$category    = isset( $shortcut['category'] ) ? trim( (string) $shortcut['category'] ) : '';
			$icon        = isset( $shortcut['icon'] ) ? trim( (string) $shortcut['icon'] ) : '';

			// Use tool name as label if no label provided.
			if ( empty( $label ) && ! empty( $tool ) ) {
				$label = $tool;
			}

			// Use label/tool as payload if no payload provided.
			if ( empty( $payload ) ) {
				$payload = ! empty( $tool ) ? $tool : $label;
			}

			// Skip empty entries.
			if ( empty( $label ) && empty( $payload ) ) {
				continue;
			}

			// Fallback category.
			if ( empty( $category ) ) {
				$category = __( 'General', 'mcp-ai-wpoos' );
			}

			// Fallback icon.
			if ( empty( $icon ) ) {
				$icon = '⚡';
			}

			// Apply search filter.
			if ( ! empty( $search ) ) {
				$search_lower = mb_strtolower( $search );
				$match        = mb_strtolower( $label . ' ' . $description . ' ' . $tool );
				if ( false === mb_strpos( $match, $search_lower ) ) {
					continue;
				}
			}

			$id = ! empty( $tool ) ? sanitize_key( $tool ) : sanitize_key( $label );
			// Ensure unique IDs by appending a suffix if needed.
			$suffix = 1;
			$base   = $id;
			while ( isset( $shortcuts[ $id ] ) ) {
				$id = $base . '-' . ( ++$suffix );
			}

			$shortcuts[ $id ] = array(
				'id'          => $id,
				'label'       => $label,
				'payload'     => $payload,
				'tool'        => $tool,
				'description' => $description,
				'category'    => $category,
				'icon'        => $icon,
			);

			if ( ! in_array( $category, $categories, true ) ) {
				$categories[] = $category;
			}
		}

		sort( $categories );

		return new \WP_REST_Response(
			array(
				'shortcuts'    => array_values( $shortcuts ),
				'categories'   => $categories,
				'assistant_id' => $assistant_id,
			),
			200
		);
	}
}
