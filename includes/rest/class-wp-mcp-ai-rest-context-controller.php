<?php
/**
 * REST Context Controller — @-mention autocomplete endpoint.
 *
 * @package NV_oOS
 * @since   1.7.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class WP_MCP_AI_REST_Context_Controller
 *
 * @since 1.7.0
 * @package NV_oOS
 */
class WP_MCP_AI_REST_Context_Controller {

	/**
	 * The context mention resolver instance.
	 *
	 * @since 1.7.0
	 * @var WP_MCP_AI_Context_Mention_Resolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		$this->resolver = new WP_MCP_AI_Context_Mention_Resolver();
	}

	/**
	 * Register routes.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function register_routes() {
		$namespace = 'mcp-ai/v1';

		register_rest_route(
			$namespace,
			'/context/suggest',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'suggest' ),
					'permission_callback' => array( $this, 'check_read' ),
					'args'                => array(
						'q'     => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'types' => array(
							'type'    => 'array',
							'default' => array(),
							'items'   => array( 'type' => 'string' ),
						),
						'limit' => array(
							'type'    => 'integer',
							'default' => 10,
							'minimum' => 1,
							'maximum' => 50,
						),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/context/(?P<type>[a-z_]+)/(?P<id>.+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'resolve' ),
					'permission_callback' => array( $this, 'check_read' ),
				),
			)
		);
	}

	/**
	 * Check read permission for context endpoints.
	 *
	 * @since 1.7.0
	 * @return bool|WP_Error
	 */
	public function check_read() {
		return current_user_can( 'read' );
	}

	/**
	 * GET /context/suggest?q=post:hello&types[]=posts&types[]=tools
	 *
	 * @since 1.7.0
	 * @param WP_REST_Request $request The request object.
	 * @return array
	 */
	public function suggest( $request ) {
		$query = $request->get_param( 'q' );
		$types = $request->get_param( 'types' );
		$limit = absint( $request->get_param( 'limit' ) );

		$results = $this->resolver->suggest( $query, $types, $limit );

		return array(
			'success' => true,
			'message' => '',
			'data'    => $results,
		);
	}

	/**
	 * GET /context/{type}/{id}
	 *
	 * @since 1.7.0
	 * @param WP_REST_Request $request The request object.
	 * @return array|WP_Error
	 */
	public function resolve( $request ) {
		$type = sanitize_key( $request->get_param( 'type' ) );
		$id   = $request->get_param( 'id' );

		$context = $this->resolver->resolve_context( $type, $id );

		if ( null === $context ) {
			return new WP_Error( 'not_found', __( 'Context not found for this mention.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}

		return array(
			'success' => true,
			'message' => '',
			'data'    => array(
				'type'    => esc_html( $type ),
				'id'      => is_numeric( $id ) ? absint( $id ) : sanitize_text_field( $id ),
				'context' => $context,
			),
		);
	}
}
