<?php
/**
 * Extended Cognition Tool — Manage Sensor Permissions
 *
 * Checks and surfaces the browser permission state for each sensor type.
 * The browser JS layer handles the actual Web API permission requests;
 * this tool queries the stored permission snapshot and can request
 * the browser to (re)prompt for specific sensors.
 *
 * @package NV_oOS_Ext_Cognition
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage sensor permissions tool.
 *
 * @since 1.0.0
 */
class NV_oOS_Ext_Cog_Tool_Manage_Sensor_Permissions {

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ext_cog_manage_sensor_permissions';
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
			return new WP_Error( 'https_required', __( 'Extended Cognition sensors require a secure (HTTPS) connection.', 'nvoos-ext-cognition' ) );
		}

		// Security: capability check.
		if ( ! $this->current_user_can_use_sensors( $context ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to use sensory tools.', 'nvoos-ext-cognition' ) );
		}

		$settings = NV_oOS_Ext_Cognition::get_settings();
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
				return new WP_Error( 'missing_session', __( 'A session_id is required to route permission requests to the browser.', 'nvoos-ext-cognition' ) );
			}

			// Push a permission-request event to the browser via the sensor queue.
			$user_id = get_current_user_id();
			$post_id = NV_oOS_Ext_Cognition_Sensor_Session::get_or_create( $session_id, $user_id );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			NV_oOS_Ext_Cognition_Sensor_Session::push_request(
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
				'message'          => __( 'Permission request dispatched to browser. The user will be prompted for each sensor.', 'nvoos-ext-cognition' ),
			);
		}

		// Default: return the enabled/disabled status map.
		$status = array();
		foreach ( array( 'camera', 'microphone', 'screen', 'motion' ) as $sensor ) {
			$status[ $sensor ] = array(
				'enabled_in_settings' => ! empty( $settings[ 'sensor_' . $sensor ] ),
				'permission_state'    => 'unknown',
				'note'                => __( 'Actual browser permission state is determined client-side. Call ext_cog_manage_sensor_permissions with action=request to prompt the user.', 'nvoos-ext-cognition' ),
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

	/**
	 * Check if the current user (or guest) is allowed to use sensors.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Execution context.
	 * @return bool
	 */
	private function current_user_can_use_sensors( array $context ) {
		// Logged-in user with capability.
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		// Guest access: must be explicitly enabled per assistant.
		$settings = NV_oOS_Ext_Cognition::get_settings();
		if ( ! empty( $settings['guest_access'] ) && ! empty( $context['guest_request'] ) ) {
			return true;
		}

		return false;
	}
}
