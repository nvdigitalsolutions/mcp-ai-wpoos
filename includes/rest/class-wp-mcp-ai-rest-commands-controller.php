<?php
/**
 * REST Commands Controller — Command palette endpoint.
 *
 * @package NV_oOS
 * @since   1.7.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class WP_MCP_AI_REST_Commands_Controller {

	/** @var WP_MCP_AI_Command_Registry */
	private $registry;

	public function __construct() {
		$this->registry = new WP_MCP_AI_Command_Registry();
	}

	public function register_routes() {
		$namespace = 'mcp-ai/v1';

		register_rest_route( $namespace, '/commands', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_commands' ),
				'permission_callback' => array( $this, 'check_read' ),
			),
		) );
	}

	public function check_read() {
		return current_user_can( 'read' );
	}

	/**
	 * GET /commands
	 */
	public function list_commands() {
		$commands = $this->registry->get_commands_for_current_user();

		return array(
			'success' => true,
			'message' => '',
			'data'    => $commands,
		);
	}
}
