<?php
/**
 * REST Checkpoints Controller.
 *
 * @package NV_oOS
 * @since   1.7.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class WP_MCP_AI_REST_Checkpoints_Controller {

	/** @var WP_MCP_AI_Checkpoint_Manager */
	private $checkpoint_manager;

	public function __construct() {
		$this->checkpoint_manager = new WP_MCP_AI_Checkpoint_Manager();
	}

	public function register_routes() {
		$namespace = 'mcp-ai/v1';

		register_rest_route( $namespace, '/threads/(?P<thread_id>\d+)/checkpoints', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_checkpoints' ),
				'permission_callback' => array( $this, 'check_read' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_checkpoint' ),
				'permission_callback' => array( $this, 'check_edit' ),
			),
		) );

		register_rest_route( $namespace, '/threads/(?P<thread_id>\d+)/checkpoints/(?P<cp_id>\d+)/restore', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_checkpoint' ),
				'permission_callback' => array( $this, 'check_edit' ),
			),
		) );

		register_rest_route( $namespace, '/threads/(?P<thread_id>\d+)/checkpoints/(?P<cp_id>\d+)/diff', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'diff' ),
				'permission_callback' => array( $this, 'check_read' ),
			),
		) );
	}

	public function check_read() {
		return current_user_can( 'read' );
	}

	public function check_edit() {
		return current_user_can( 'edit_posts' );
	}

	public function list_checkpoints( $request ) {
		$thread_id = absint( $request->get_param( 'thread_id' ) );
		return $this->checkpoint_manager->list_checkpoints( $thread_id );
	}

	public function create_checkpoint( $request ) {
		$thread_id    = absint( $request->get_param( 'thread_id' ) );
		$affected_ids = $request->get_param( 'affected_ids' );
		$label        = sanitize_text_field( $request->get_param( 'label' ) );

		if ( ! is_array( $affected_ids ) ) {
			$affected_ids = array();
		}

		return $this->checkpoint_manager->create_checkpoint( $thread_id, 0, $affected_ids, $label );
	}

	public function restore_checkpoint( $request ) {
		$thread_id     = absint( $request->get_param( 'thread_id' ) );
		$checkpoint_id = absint( $request->get_param( 'cp_id' ) );

		return $this->checkpoint_manager->restore_checkpoint( $thread_id, $checkpoint_id );
	}

	public function diff( $request ) {
		$thread_id     = absint( $request->get_param( 'thread_id' ) );
		$checkpoint_id = absint( $request->get_param( 'cp_id' ) );

		return $this->checkpoint_manager->diff( $thread_id, $checkpoint_id );
	}
}
