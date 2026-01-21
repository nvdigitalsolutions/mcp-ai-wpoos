<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WP_MCP_AI_Tool_Net_Worth_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
public static function is_available() {
if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) { return false; }
$settings = get_option( 'wp_mcp_ai_settings', array() );
return ! empty( $settings['enable_financial_planner_toolkit'] );
}
public static function get_unavailable_reason() {
return __( 'Financial planner toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
}
public function get_slug() { return 'net_worth_calculator'; }
public function get_name() { return __( 'Net Worth Calculator', 'mcp-ai-wpoos-pro' ); }
public function get_description() {
return __( 'Track net worth over time by calculating total assets minus liabilities. Monitor changes, set growth goals, and visualize net worth trends.', 'mcp-ai-wpoos-pro' );
}
public function get_parameters_schema() {
return array(
'type' => 'object',
'properties' => array(
'assets' => array(
'type' => 'array',
'description' => __( 'List of assets with values', 'mcp-ai-wpoos-pro' ),
'items' => array(
'type' => 'object',
'properties' => array(
'name' => array( 'type' => 'string', 'description' => __( 'Asset name', 'mcp-ai-wpoos-pro' ) ),
'value' => array( 'type' => 'number', 'minimum' => 0 ),
'category' => array( 'type' => 'string', 'enum' => array( 'cash', 'investments', 'real_estate', 'other' ) ),
),
),
),
'liabilities' => array(
'type' => 'array',
'description' => __( 'List of liabilities', 'mcp-ai-wpoos-pro' ),
'items' => array(
'type' => 'object',
'properties' => array(
'name' => array( 'type' => 'string' ),
'balance' => array( 'type' => 'number', 'minimum' => 0 ),
'category' => array( 'type' => 'string', 'enum' => array( 'mortgage', 'auto_loan', 'credit_card', 'student_loan', 'other' ) ),
),
),
),
),
'required' => array( 'assets', 'liabilities' ),
);
}
public function get_capability_flags() {
return array( 'pro', 'computation' );
}
public function execute( array $arguments = array(), array $context = array() ) {
$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
}
if ( ! self::is_available() ) {
return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
}

$assets = isset( $arguments['assets'] ) && is_array( $arguments['assets'] ) ? $arguments['assets'] : array();
$liabilities = isset( $arguments['liabilities'] ) && is_array( $arguments['liabilities'] ) ? $arguments['liabilities'] : array();

$total_assets = 0;
foreach ( $assets as $asset ) {
$total_assets += isset( $asset['value'] ) ? floatval( $asset['value'] ) : 0;
}

$total_liabilities = 0;
foreach ( $liabilities as $liability ) {
$total_liabilities += isset( $liability['balance'] ) ? floatval( $liability['balance'] ) : 0;
}

$net_worth = $total_assets - $total_liabilities;

return array(
'success' => true,
'total_assets' => round( $total_assets, 2 ),
'total_liabilities' => round( $total_liabilities, 2 ),
'net_worth' => round( $net_worth, 2 ),
'assets' => $assets,
'liabilities' => $liabilities,
'message' => sprintf( __( 'Your net worth is $%s.', 'mcp-ai-wpoos-pro' ), number_format( $net_worth, 2 ) ),
);
}
}
