<?php
/**
 * Extended Cognition Tool — Manage Sensor Permissions
 *
 * Checks and surfaces the browser permission state for each sensor type.
 * The browser JS layer handles the actual Web API permission requests;
 * this tool queries the stored permission snapshot and can request
 * the browser to (re)prompt for specific sensors.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-wp-mcp-ai-ext-cog-tool.php';
require_once __DIR__ . '/trait-wp-mcp-ai-ext-cog-sensor-access.php';
/**
 * Manage sensor permissions tool.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Ext_Cog_Manage_Sensor_Permissions implements WP_MCP_AI_Ext_Cog_Tool_Interface {

	use WP_MCP_AI_Ext_Cog_Sensor_Access;

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ext_cog_manage_sensor_permissions';
	}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Manage Sensor Permissions (Extended Cognition)', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Check and manage browser permissions for Extended Cognition sensors (camera, microphone, screen, motion).', 'mcp-ai-wpoos' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'ext_cog_manage_sensor_permissions',
			'description'         => 'Check and manage browser permissions for extended cognition sensors (camera, microphone, screen, motion). Use this first to understand which sensors are available before attempting captures. Returns the current permission state for each sensor type: granted, prompt, denied, or not-supported. Can also trigger a browser permission request for specific sensors.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'action'     => array(
						'type'        => 'string',
						'enum'        => array( 'check', 'request' ),
						'description' => 'check: return current permission states. request: ask browser to prompt the user for permission.',
						'default'     => 'check',
					),
					'sensors'    => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => array( 'camera', 'microphone', 'screen', 'motion' ),
						),
						'description' => 'Sensors to check or request permission for. Omit to check all enabled sensors.',
					),
					'session_id' => array(
						'type'        => 'string',
						'description' => 'Active chat session ID used to route the permission request to the correct browser tab.',
					),
				),
				'required'   => array( 'action' ),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'extended-cognition', 'sensors' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Security: enforce HTTPS outside debug mode.
		if ( ! is_ssl() && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return new WP_Error( 'https_required', __( 'Extended Cognition sensors require a secure (HTTPS) connection.', 'mcp-ai-wpoos' ) );
		}

		// Security: capability check.
		if ( ! $this->current_user_can_use_sensors( $context ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to use sensory tools.', 'mcp-ai-wpoos' ) );
		}

		$settings = wp_mcp_ai_ext_cog_get_settings();
		$action   = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'check';
		$sensors  = isset( $arguments['sensors'] ) && is_array( $arguments['sensors'] )
			? array_map( 'sanitize_text_field', $arguments['sensors'] )
			: array( 'camera', 'microphone', 'screen', 'motion' );

		// Filter to only enabled sensors.
		$enabled = array();
		foreach ( $sensors as $sensor ) {
			$key = 'sensor_' . $sensor;
			if ( ! empty( $settings[ $key ] ) ) {
				$enabled[] = $sensor;
			}
		}

		if ( 'request' === $action ) {
			$session_id = isset( $arguments['session_id'] ) ? sanitize_text_field( $arguments['session_id'] ) : '';

			if ( empty( $session_id ) ) {
				return new WP_Error( 'missing_session', __( 'A session_id is required to route permission requests to the browser.', 'mcp-ai-wpoos' ) );
			}

			// Push a permission-request event to the browser via the sensor queue.
			$user_id = get_current_user_id();
			$post_id = WP_MCP_AI_Ext_Cog_Sensor_Session::get_or_create( $session_id, $user_id );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			WP_MCP_AI_Ext_Cog_Sensor_Session::push_request(
				$post_id,
				array(
					'type'    => 'permission_request',
					'sensors' => $enabled,
				)
			);

			return array(
				'success'          => true,
				'action'           => 'request',
				'sensors_targeted' => $enabled,
				'message'          => __( 'Permission request dispatched to browser. The user will be prompted for each sensor.', 'mcp-ai-wpoos' ),
			);
		}

		// Default: return the enabled/disabled status map.
		$status = array();
		foreach ( array( 'camera', 'microphone', 'screen', 'motion' ) as $sensor ) {
			$status[ $sensor ] = array(
				'enabled_in_settings' => ! empty( $settings[ 'sensor_' . $sensor ] ),
				'permission_state'    => 'unknown',
				'note'                => __( 'Actual browser permission state is determined client-side. Call ext_cog_manage_sensor_permissions with action=request to prompt the user.', 'mcp-ai-wpoos' ),
			);
		}

		return array(
			'success'       => true,
			'action'        => 'check',
			'sensor_status' => $status,
			'guest_access'  => ! empty( $settings['guest_access'] ),
			'gdpr_consent'  => ! empty( $settings['gdpr_consent'] ),
		);
	}
}
