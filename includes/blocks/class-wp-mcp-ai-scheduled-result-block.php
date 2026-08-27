<?php
/**
 * Scheduled Result block registration.
 *
 * Loads `includes/blocks/scheduled-result/block.json` via
 * register_block_type() and points it at the shared PHP renderer. The block
 * ships in the base plugin so it can appear in the editor regardless of Pro,
 * and degrades gracefully when Pro is unavailable.
 *
 * @package WP_MCP_AI
 * @since   1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Scheduled_Result_Block' ) ) {
	/**
	 * Bootstrap class for the Scheduled Result Gutenberg block.
	 */
	class WP_MCP_AI_Scheduled_Result_Block {

		/**
		 * Register block.
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'register_block' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_refresh_script' ) );
		}

		/**
		 * Register the dynamic block with WordPress.
		 */
		public static function register_block() {
			if ( ! function_exists( 'register_block_type' ) ) {
				return;
			}

			// register_block_type() is not idempotent — re-registering the same
			// block name raises a _doing_it_wrong notice. Guard against repeated
			// 'init' fires (which can happen on hosts that re-run init and in the
			// test harness) so the block is only registered once per request.
			$registry = WP_Block_Type_Registry::get_instance();
			if ( $registry->is_registered( 'mcp-ai-wpoos/scheduled-result' ) ) {
				return;
			}

			register_block_type(
				__DIR__ . '/scheduled-result',
				array(
					'render_callback' => array( __CLASS__, 'render' ),
				)
			);
		}

		/**
		 * Render callback — delegates to the shared renderer.
		 *
		 * @param array $attributes Block attributes.
		 * @return string HTML.
		 */
		public static function render( $attributes ) {
			if ( ! class_exists( 'WP_MCP_AI_Scheduled_Result_Renderer' ) ) {
				require_once dirname( __DIR__ ) . '/renderers/class-wp-mcp-ai-scheduled-result-renderer.php';
			}
			$schedule_id = isset( $attributes['scheduleId'] ) ? (string) $attributes['scheduleId'] : '';
			return WP_MCP_AI_Scheduled_Result_Renderer::render(
				$schedule_id,
				array(
					'render_mode'      => isset( $attributes['renderMode'] ) ? (string) $attributes['renderMode'] : 'summary-card',
					'title'            => isset( $attributes['title'] ) ? (string) $attributes['title'] : '',
					'show_last_run'    => isset( $attributes['showLastRun'] ) ? (bool) $attributes['showLastRun'] : true,
					'refresh_interval' => isset( $attributes['refreshIntervalSec'] ) ? (int) $attributes['refreshIntervalSec'] : 0,
					'truncate_chars'   => isset( $attributes['truncateChars'] ) ? (int) $attributes['truncateChars'] : 0,
				)
			);
		}

		/**
		 * Enqueue the lightweight refresh enhancer on front-end pages that include the block.
		 */
		public static function enqueue_refresh_script() {
			// Use the same conditional pattern as performance-blocks: only enqueue
			// when the block actually appears on the page.
			if ( ! is_singular() ) {
				return;
			}
			$post = get_post();
			if ( ! $post || ! has_block( 'mcp-ai-wpoos/scheduled-result', $post ) ) {
				return;
			}
			wp_enqueue_script(
				'mcp-ai-scheduled-result-refresh',
				plugins_url( 'assets/js/scheduled-result-refresh.js', dirname( __DIR__, 2 ) . '/mcp-ai-wpoos.php' ),
				array(),
				defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0',
				true
			);
		}
	}

	WP_MCP_AI_Scheduled_Result_Block::init();
}
