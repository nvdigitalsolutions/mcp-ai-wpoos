<?php
/**
 * Set Availability Rules Tool
 *
 * Defines availability rules and business hours for appointment scheduling.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since 2.6.0
 * @phase Phase 2.6 - Calendar Booking Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

class WP_MCP_AI_Tool_Set_Availability_Rules implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

public static function is_available() {
if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
return false;
}
$settings = get_option( 'wp_mcp_ai_settings', array() );
return ! empty( $settings['enable_calendar_booking_toolkit'] );
}

public static function get_unavailable_reason() {
return __( 'Calendar Booking toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
}

public function get_slug() {
return 'set_availability_rules';
}

public function get_name() {
return __( 'Set Availability Rules', 'mcp-ai-wpoos-pro' );
}

public function get_description() {
return __( 'Define availability rules and business hours for appointment scheduling.', 'mcp-ai-wpoos-pro' );
}

public function get_parameters_schema() {
return array(
'type'       => 'object',
'properties' => array(
'day_of_week'    => array(
'type'        => 'string',
'description' => __( 'Day of week', 'mcp-ai-wpoos-pro' ),
'enum'        => array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ),
),
'enabled'        => array(
'type'        => 'boolean',
'description' => __( 'Enable appointments on this day', 'mcp-ai-wpoos-pro' ),
'default'     => true,
),
'start_time'     => array(
'type'        => 'string',
'description' => __( 'Start time (HH:MM format)', 'mcp-ai-wpoos-pro' ),
),
'end_time'       => array(
'type'        => 'string',
'description' => __( 'End time (HH:MM format)', 'mcp-ai-wpoos-pro' ),
),
'slot_duration'  => array(
'type'        => 'integer',
'description' => __( 'Appointment slot duration in minutes', 'mcp-ai-wpoos-pro' ),
'minimum'     => 15,
'default'     => 60,
),
'buffer_time'    => array(
'type'        => 'integer',
'description' => __( 'Buffer time between appointments in minutes', 'mcp-ai-wpoos-pro' ),
'minimum'     => 0,
'default'     => 0,
),
),
'required'   => array( 'day_of_week' ),
);
}

public function get_capability_flags() {
return array( 'pro', 'database-write', 'phase-2.6' );
}

public function execute( array $arguments = array(), array $context = array() ) {
$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
}

if ( ! self::is_available() ) {
return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() );
}

$day_of_week = ! empty( $arguments['day_of_week'] ) ? sanitize_text_field( $arguments['day_of_week'] ) : '';

if ( empty( $day_of_week ) ) {
return new WP_Error( 'missing_day', __( 'Day of week is required.', 'mcp-ai-wpoos-pro' ) );
}

$business_hours = get_option( 'wp_mcp_ai_business_hours', array() );

$business_hours[ $day_of_week ] = array(
'enabled'       => isset( $arguments['enabled'] ) ? (bool) $arguments['enabled'] : true,
'start_time'    => ! empty( $arguments['start_time'] ) ? sanitize_text_field( $arguments['start_time'] ) : '09:00',
'end_time'      => ! empty( $arguments['end_time'] ) ? sanitize_text_field( $arguments['end_time'] ) : '17:00',
'slot_duration' => ! empty( $arguments['slot_duration'] ) ? absint( $arguments['slot_duration'] ) : 60,
'buffer_time'   => ! empty( $arguments['buffer_time'] ) ? absint( $arguments['buffer_time'] ) : 0,
);

update_option( 'wp_mcp_ai_business_hours', $business_hours );

return array(
'success'        => true,
'day_of_week'    => $day_of_week,
'rules'          => $business_hours[ $day_of_week ],
'message'        => __( 'Availability rules updated successfully.', 'mcp-ai-wpoos-pro' ),
);
}
}
