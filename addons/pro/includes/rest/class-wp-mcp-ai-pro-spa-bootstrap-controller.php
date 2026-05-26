<?php
/**
 * Pro SPA Bootstrap Controller — Single-request data endpoint for SPA initial load.
 *
 * Aggregates threads, profiles, tools catalogue, settings, commands, and
 * user capabilities into one response to minimize HTTP round-trips on SPA load.
 *
 * @package NV_oOS_Pro
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_SPA_Bootstrap_Controller
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Pro_SPA_Bootstrap_Controller {

	/**
	 * Register the bootstrap REST route.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'mcp-ai-pro/v1',
			'/spa/bootstrap',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'bootstrap' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission check — requires at minimum the 'read' capability.
	 *
	 * @since 1.7.0
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You do not have permission to access this resource.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Bootstrap endpoint — returns all data needed for SPA initial render.
	 *
	 * Note: $request is unused but required by WP_REST_Server::READABLE callback signature.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function bootstrap( $request ) {
		// $request is unused but required by WP_REST_Server::READABLE callback signature.
		unset( $request );
		$user_id = get_current_user_id();
		$data    = array();

		// 1. Active threads.
		if ( class_exists( 'WP_MCP_AI_Thread_Manager' ) ) {
			$thread_manager  = new WP_MCP_AI_Thread_Manager();
			$threads_result  = $thread_manager->list_threads( $user_id, 'active', 1, 50 );
			$data['threads'] = isset( $threads_result['data'] ) ? $threads_result['data'] : array(
				'threads' => array(),
				'total'   => 0,
			);
		} else {
			$data['threads'] = array(
				'threads' => array(),
				'total'   => 0,
			);
		}

		// 2. Profiles.
		if ( class_exists( 'WP_MCP_AI_Profile_Manager' ) ) {
			$profile_manager  = new WP_MCP_AI_Profile_Manager();
			$data['profiles'] = $profile_manager->list_profiles( $user_id );
		} else {
			$data['profiles'] = array();
		}

		// 3. Tools catalogue (names + slugs only, not full definitions).
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry  = WP_MCP_AI_Tool_Registry::get_instance();
			$all_tools = $registry->get_tools();
			$tools     = array();

			foreach ( $all_tools as $slug => $tool ) {
				$tools[] = array(
					'slug'        => $slug,
					'name'        => isset( $tool['name'] ) ? $tool['name'] : $slug,
					'description' => isset( $tool['description'] ) ? $tool['description'] : '',
					'capability'  => isset( $tool['required_capability'] ) ? $tool['required_capability'] : 'read',
				);
			}

			$data['tools'] = $tools;
		} else {
			$data['tools'] = array();
		}

		// 4. Commands.
		if ( class_exists( 'WP_MCP_AI_Command_Registry' ) ) {
			$registry         = new WP_MCP_AI_Command_Registry();
			$data['commands'] = $registry->get_commands_for_current_user();
		} else {
			$data['commands'] = array();
		}

		// 5. Plugin settings (non-sensitive).
		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$data['settings'] = array(
			'enable_logging'       => ! empty( $settings['enable_logging'] ),
			'enable_cost_tracking' => ! empty( $settings['enable_cost_tracking'] ),
		);

		// 6. Mention types.
		if ( class_exists( 'WP_MCP_AI_Context_Mention_Resolver' ) ) {
			$resolver              = new WP_MCP_AI_Context_Mention_Resolver();
			$data['mention_types'] = $resolver->get_registered_types();
		} else {
			$data['mention_types'] = array();
		}

		// 7. User capabilities.
		$data['user'] = array(
			'id'           => $user_id,
			'display_name' => wp_get_current_user()->display_name,
			'can_manage'   => current_user_can( 'manage_options' ),
			'can_edit'     => current_user_can( 'edit_posts' ),
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}
}
