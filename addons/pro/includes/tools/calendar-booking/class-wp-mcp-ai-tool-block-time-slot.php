<?php
/** Block Time Slot Tool - Phase 2.6 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WP_MCP_AI_Tool_Block_Time_Slot implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
public static function is_available() {
if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) { return false; }
$settings = get_option( 'wp_mcp_ai_settings', array() );
return ! empty( $settings['enable_calendar_booking_toolkit'] );
}
public static function get_unavailable_reason() { return __( 'Calendar Booking toolkit is not enabled.', 'mcp-ai-wpoos-pro' ); }
public function get_slug() { return 'block_time_slot'; }
public function get_name() { return __( 'Block Time Slot', 'mcp-ai-wpoos-pro' ); }
public function get_description() { return __( 'Block specific time slots to prevent appointments.', 'mcp-ai-wpoos-pro' ); }
public function get_parameters_schema() {
return array( 'type' => 'object', 'properties' => array(
'start_time' => array( 'type' => 'string', 'description' => __( 'Start time (Y-m-d H:i:s)', 'mcp-ai-wpoos-pro' ) ),
'end_time' => array( 'type' => 'string', 'description' => __( 'End time (Y-m-d H:i:s)', 'mcp-ai-wpoos-pro' ) ),
'reason' => array( 'type' => 'string', 'description' => __( 'Reason for blocking', 'mcp-ai-wpoos-pro' ) ),
), 'required' => array( 'start_time', 'end_time' ) );
}
public function get_capability_flags() { return array( 'pro', 'database-write', 'phase-2.6' ); }
public function execute( array $arguments = array(), array $context = array() ) {
$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
}
if ( ! self::is_available() ) { return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() ); }
$start_time = ! empty( $arguments['start_time'] ) ? sanitize_text_field( $arguments['start_time'] ) : '';
$end_time = ! empty( $arguments['end_time'] ) ? sanitize_text_field( $arguments['end_time'] ) : '';
if ( empty( $start_time ) || empty( $end_time ) ) {
return new WP_Error( 'missing_time', __( 'Start and end times are required.', 'mcp-ai-wpoos-pro' ) );
}
$blocked_id = wp_insert_post( array(
'post_type' => 'mcp_blocked_time', 'post_title' => sprintf( __( 'Blocked: %s to %s', 'mcp-ai-wpoos-pro' ), $start_time, $end_time ),
'post_status' => 'publish', 'meta_input' => array(
'_start_time' => $start_time, '_end_time' => $end_time,
'_reason' => ! empty( $arguments['reason'] ) ? sanitize_textarea_field( $arguments['reason'] ) : '',
'_blocked_by' => $current_user_id,
)
), true );
if ( is_wp_error( $blocked_id ) ) { return $blocked_id; }
return array( 'success' => true, 'blocked_id' => $blocked_id, 'start_time' => $start_time, 'end_time' => $end_time, 'message' => __( 'Time slot blocked successfully.', 'mcp-ai-wpoos-pro' ) );
}
}
