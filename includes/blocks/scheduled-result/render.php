<?php
/**
 * Server-side render callback for the Scheduled Result block.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @var array $attributes Block attributes provided by WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Scheduled_Result_Renderer' ) ) {
	require_once dirname( __DIR__, 2 ) . '/renderers/class-wp-mcp-ai-scheduled-result-renderer.php';
}

$attrs = isset( $attributes ) && is_array( $attributes ) ? $attributes : array();

$schedule_id = isset( $attrs['scheduleId'] ) ? (string) $attrs['scheduleId'] : '';
$opts        = array(
	'render_mode'      => isset( $attrs['renderMode'] ) ? (string) $attrs['renderMode'] : 'summary-card',
	'title'            => isset( $attrs['title'] ) ? (string) $attrs['title'] : '',
	'show_last_run'    => isset( $attrs['showLastRun'] ) ? (bool) $attrs['showLastRun'] : true,
	'refresh_interval' => isset( $attrs['refreshIntervalSec'] ) ? (int) $attrs['refreshIntervalSec'] : 0,
	'truncate_chars'   => isset( $attrs['truncateChars'] ) ? (int) $attrs['truncateChars'] : 0,
);

echo WP_MCP_AI_Scheduled_Result_Renderer::render( $schedule_id, $opts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes all output internally.
