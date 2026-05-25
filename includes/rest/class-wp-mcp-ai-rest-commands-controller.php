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

/**
 * Class WP_MCP_AI_REST_Commands_Controller
 *
 * @since 1.7.0
 * @package NV_oOS
 */
class WP_MCP_AI_REST_Commands_Controller {

	/**
	 * The command registry instance.
	 *
	 * @since 1.7.0
	 * @var WP_MCP_AI_Command_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		$this->registry = new WP_MCP_AI_Command_Registry();
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
			'/commands',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_commands' ),
					'permission_callback' => array( $this, 'check_read' ),
				),
			)
		);
	}

	/**
	 * Check read permission for command endpoints.
	 *
	 * @since 1.7.0
	 * @return bool|WP_Error
	 */
	public function check_read() {
		return current_user_can( 'read' );
	}

	/**
	 * GET /commands
	 *
	 * @since 1.7.0
	 * @return array
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
