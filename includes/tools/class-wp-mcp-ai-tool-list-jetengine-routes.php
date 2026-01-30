<?php
/**
 * Tool exposing JetEngine REST API route metadata.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provide reference details for JetEngine REST API routes.
 */
class WP_MCP_AI_Tool_List_JetEngine_Routes implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * JetEngine REST API namespace.
	 *
	 * @var string
	 */
	const JETENGINE_NAMESPACE = 'jet-engine/v2';

	/**
	 * Determine whether JetEngine is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The JetEngine REST routes tool is disabled because JetEngine is not active.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_jetengine_rest_routes';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List JetEngine REST Routes', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns metadata about the REST API routes bundled with JetEngine.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'route' => array(
					'type'        => 'string',
					'description' => __( 'Optional path fragment (for example, "get-item") to filter the results.', 'mcp-ai-wpoos' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_jetengine_missing', __( 'JetEngine is not active on this site.', 'mcp-ai-wpoos' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view JetEngine REST API details.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}
		$routes = $this->get_routes();

		if ( ! empty( $arguments['route'] ) ) {
			$needle = sanitize_text_field( $arguments['route'] );
			$routes = array_filter(
				$routes,
				function ( $route ) use ( $needle ) {
					return false !== strpos( $route['path'], $needle );
				}
			);
		}

		// Format routes into human-readable text for display.
		$message = $this->format_routes_as_text( $routes, self::JETENGINE_NAMESPACE );

		return array(
			'namespace' => self::JETENGINE_NAMESPACE,
			'routes'    => array_values( $routes ),
			'message'   => $message,
		);
	}

	/**
	 * Format routes array into human-readable text.
	 *
	 * @param array  $routes    Array of route definitions.
	 * @param string $namespace API namespace.
	 * @return string Formatted text representation.
	 */
	private function format_routes_as_text( array $routes, $namespace ) {
		if ( empty( $routes ) ) {
			return __( 'No JetEngine REST routes found.', 'mcp-ai-wpoos' );
		}

		$lines = array();
		/* translators: %s: API namespace */
		$lines[] = sprintf( __( 'Available JetEngine REST API Routes (%s):', 'mcp-ai-wpoos' ), $namespace );
		$lines[] = '';

		foreach ( $routes as $index => $route ) {
			if ( ! is_array( $route ) ) {
				continue;
			}

			$path        = isset( $route['path'] ) ? $route['path'] : '';
			$methods     = isset( $route['methods'] ) && is_array( $route['methods'] ) ? implode( ', ', $route['methods'] ) : '';
			$description = isset( $route['description'] ) ? $route['description'] : '';

			// Format: 1. GET /search-posts/
			$lines[] = ( $index + 1 ) . '. ' . $methods . ' ' . $path;

			// Add description if available.
			if ( $description ) {
				$lines[] = '   ' . $description;
			}

			// Add additional requirements if available.
			if ( isset( $route['additional_requirements'] ) && is_array( $route['additional_requirements'] ) ) {
				foreach ( $route['additional_requirements'] as $req ) {
					$lines[] = '   • ' . $req;
				}
			}

			// Add spacing between routes (except for the last one).
			if ( $index < count( $routes ) - 1 ) {
				$lines[] = '';
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Provide the JetEngine REST route definitions.
	 *
	 * @return array
	 */
	private function get_routes() {
		return array(
			array(
				'path'                    => '/search-posts/',
				'methods'                 => array( 'GET' ),
				'callback'                => 'Jet_Engine_Rest_Search_Posts::callback',
				'permission_callback'     => 'Jet_Engine_Rest_Search_Posts::permission_callback',
				'description'             => __( 'Searches published posts or taxonomy terms registered with JetEngine. Accepts optional "query", "ids", "post_type", "tax", "search_terms", and "query_context" parameters.', 'mcp-ai-wpoos' ),
				'additional_requirements' => array(
					__( 'Requires a user who can manage options.', 'mcp-ai-wpoos' ),
				),
			),
			array(
				'path'                    => '/add-item/',
				'methods'                 => array( 'POST' ),
				'callback'                => 'Jet_Engine_Rest_Add_Item::callback',
				'permission_callback'     => 'Jet_Engine_Rest_Add_Item::permission_callback',
				'description'             => __( 'Creates a new JetEngine item through filters hooked to "jet-engine/rest-api/add-item/{instance}".', 'mcp-ai-wpoos' ),
				'additional_requirements' => array(
					__( 'Requests must provide an "instance" parameter so instance-specific filters can persist the item.', 'mcp-ai-wpoos' ),
					__( 'Requires a user who can manage options.', 'mcp-ai-wpoos' ),
				),
			),
			array(
				'path'                    => '/edit-item/(?P<id>[a-z\-\d]+)/',
				'methods'                 => array( 'POST' ),
				'callback'                => 'Jet_Engine_Rest_Edit_Item::callback',
				'permission_callback'     => 'Jet_Engine_Rest_Edit_Item::permission_callback',
				'description'             => __( 'Updates an existing JetEngine item by forwarding data to "jet-engine/rest-api/edit-item/{instance}" filters.', 'mcp-ai-wpoos' ),
				'additional_requirements' => array(
					__( 'Provide the item ID in the request URL.', 'mcp-ai-wpoos' ),
					__( 'Requests must include an "instance" parameter.', 'mcp-ai-wpoos' ),
					__( 'Requires a user who can manage options.', 'mcp-ai-wpoos' ),
				),
			),
			array(
				'path'                    => '/delete-item/(?P<id>[a-z\-_\d]+)/',
				'methods'                 => array( 'DELETE' ),
				'callback'                => 'Jet_Engine_Rest_Delete_Item::callback',
				'permission_callback'     => 'Jet_Engine_Rest_Delete_Item::permission_callback',
				'description'             => __( 'Deletes an existing JetEngine item through "jet-engine/rest-api/delete-item/{instance}" filters.', 'mcp-ai-wpoos' ),
				'additional_requirements' => array(
					__( 'Provide the item ID in the request URL.', 'mcp-ai-wpoos' ),
					__( 'Requests must include an "instance" parameter.', 'mcp-ai-wpoos' ),
					__( 'Requires a user who can manage options.', 'mcp-ai-wpoos' ),
				),
			),
			array(
				'path'                    => '/get-item/(?P<id>[a-z\-\d]+)/',
				'methods'                 => array( 'GET' ),
				'callback'                => 'Jet_Engine_Rest_Get_Item::callback',
				'permission_callback'     => 'Jet_Engine_Rest_Get_Item::permission_callback',
				'description'             => __( 'Fetches a single JetEngine item using the "jet-engine/rest-api/get-item/{instance}" filter.', 'mcp-ai-wpoos' ),
				'additional_requirements' => array(
					__( 'Provide the item ID in the request URL.', 'mcp-ai-wpoos' ),
					__( 'Requests must include an "instance" parameter.', 'mcp-ai-wpoos' ),
					__( 'Requires a user who can manage options.', 'mcp-ai-wpoos' ),
				),
			),
			array(
				'path'                    => '/get-items/',
				'methods'                 => array( 'GET' ),
				'callback'                => 'Jet_Engine_Rest_Get_Items::callback',
				'permission_callback'     => 'Jet_Engine_Rest_Get_Items::permission_callback',
				'description'             => __( 'Retrieves JetEngine items via "jet-engine/rest-api/get-items/{instance}" filters and normalises stored arguments.', 'mcp-ai-wpoos' ),
				'additional_requirements' => array(
					__( 'Requests must include an "instance" parameter.', 'mcp-ai-wpoos' ),
					__( 'Requires a user who can manage options.', 'mcp-ai-wpoos' ),
				),
			),
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.

	 *

	 * @since 1.1.0

	 *

	 * @return array Tool definition with metadata.

	 */

	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'integration_external',

			'pattern_compatibility' => array( 'skill_router' ),

			'profession_tags'       => array( 'web_developer', 'api_developer' ),

			'risk_level'            => 'info',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
