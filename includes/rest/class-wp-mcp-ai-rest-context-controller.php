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

class WP_MCP_AI_REST_Context_Controller {

	/** @var WP_MCP_AI_Context_Mention_Resolver */
	private $resolver;

	public function __construct() {
		$this->resolver = new WP_MCP_AI_Context_Mention_Resolver();
	}

	public function register_routes() {
		$namespace = 'mcp-ai/v1';

		register_rest_route( $namespace, '/context/suggest', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'suggest' ),
				'permission_callback' => array( $this, 'check_read' ),
				'args'                => array(
					'q'      => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'types'  => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'string' ) ),
					'limit'  => array( 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50 ),
				),
			),
		) );

		register_rest_route( $namespace, '/context/(?P<type>[a-z_]+)/(?P<id>.+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'resolve' ),
				'permission_callback' => array( $this, 'check_read' ),
			),
		) );
	}

	public function check_read() {
		return current_user_can( 'read' );
	}

	/**
	 * GET /context/suggest?q=post:hello&types[]=posts&types[]=tools
	 */
	public function suggest( $request ) {
		$query  = $request->get_param( 'q' );
		$types  = $request->get_param( 'types' );
		$limit  = absint( $request->get_param( 'limit' ) );

		$results = $this->resolver->suggest( $query, $types, $limit );

		return array(
			'success' => true,
			'message' => '',
			'data'    => $results,
		);
	}

	/**
	 * GET /context/{type}/{id}
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
