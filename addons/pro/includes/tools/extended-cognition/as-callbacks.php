<?php
/**
 * Extended Cognition — Action Scheduler callbacks for video analysis.
 *
 * Registered when the Extended Cognition toolkit is enabled.  The
 * `wp_mcp_ai_ext_cog_analyze_video_feed` hook is dispatched by
 * WP_MCP_AI_Tool_Ext_Cog_Analyze_Video_Feed for long-running video
 * analysis jobs.  This file provides the callback that reconstructs
 * the analysis from stored job args.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Action Scheduler hook callback.
 *
 * Hooked to 'init' priority 30.  The callback must be registered on every
 * request so the Action Scheduler runner can find it.
 *
 * @since 1.8.0
 * @return void
 */
function wp_mcp_ai_ext_cog_register_as_hooks() {
	add_action(
		'wp_mcp_ai_ext_cog_analyze_video_feed',
		'wp_mcp_ai_ext_cog_as_video_analysis_callback',
		10,
		1
	);
}

/**
 * Action Scheduler callback for background video feed analysis.
 *
 * Idempotent: reloads all state from stored job arguments.  Throws on
 * failure so Action Scheduler can retry.
 *
 * @since 1.8.0
 *
 * @param array $args Job arguments.
 * @return void
 * @throws Exception On recoverable failure.
 */
function wp_mcp_ai_ext_cog_as_video_analysis_callback( $args ) {
	if ( ! is_array( $args ) ) {
		return;
	}

	$action_id = isset( $args['action_id'] ) ? absint( $args['action_id'] ) : 0;

	// Mark job as processing.
	$jobs = get_option( 'wp_mcp_ai_ext_cog_video_jobs', array() );
	$jobs = is_array( $jobs ) ? $jobs : array();

	if ( $action_id > 0 && isset( $jobs[ $action_id ] ) ) {
		$jobs[ $action_id ]['status'] = 'processing';
		update_option( 'wp_mcp_ai_ext_cog_video_jobs', $jobs, false );
	}

	// Reconstruct arguments from stored job data.
	$video_source   = isset( $args['video_source'] ) ? sanitize_text_field( $args['video_source'] ) : 'camera_stream';
	$session_id     = isset( $args['session_id'] ) ? sanitize_text_field( $args['session_id'] ) : '';
	$attachment_id  = isset( $args['attachment_id'] ) ? absint( $args['attachment_id'] ) : 0;
	$url            = isset( $args['url'] ) ? esc_url_raw( $args['url'] ) : '';
	$sample_rate    = isset( $args['sample_rate'] ) ? absint( $args['sample_rate'] ) : 1;
	$analysis_depth = isset( $args['analysis_depth'] ) ? sanitize_text_field( $args['analysis_depth'] ) : 'quick';
	$track_products = ! empty( $args['track_products'] );
	$max_frames     = isset( $args['max_frames'] ) ? absint( $args['max_frames'] ) : 60;
	$labels         = isset( $args['labels'] ) && is_array( $args['labels'] )
		? array_map( 'sanitize_text_field', $args['labels'] )
		: array();
	$user_id        = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;

	// Ensure required classes are available to the runner context.
	if ( ! class_exists( 'WP_MCP_AI_Tool_Ext_Cog_Analyze_Video_Feed' ) ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-analyze-video-feed.php';
	}
	if ( ! class_exists( 'WP_MCP_AI_HF_Vision_Inference_Service' ) ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-hf-vision-inference-service.php';
	}

	$tool      = new WP_MCP_AI_Tool_Ext_Cog_Analyze_Video_Feed();
	$reflector = new ReflectionMethod( $tool, 'run_sync_analysis' );
	$reflector->setAccessible( true );

	$context = $user_id > 0 ? array( 'user_id' => $user_id ) : array();

	try {
		$result = $reflector->invoke(
			$tool,
			$video_source,
			$session_id,
			$attachment_id,
			$url,
			$sample_rate,
			$analysis_depth,
			$track_products,
			$max_frames,
			$labels,
			60000, // 60s timeout for the analysis.
			$context
		);

		// Persist result on the session.
		if ( ! empty( $session_id ) && ! is_wp_error( $result ) && is_array( $result ) ) {
			// Update job status.
			$jobs = get_option( 'wp_mcp_ai_ext_cog_video_jobs', array() );
			$jobs = is_array( $jobs ) ? $jobs : array();

			if ( $action_id > 0 && isset( $jobs[ $action_id ] ) ) {
				$jobs[ $action_id ]['status']       = 'complete';
				$jobs[ $action_id ]['completed_at'] = time();
				$jobs[ $action_id ]['result_summary'] = array(
					'frames_analyzed' => isset( $result['frames_analyzed'] ) ? $result['frames_analyzed'] : 0,
					'unique_brands'   => isset( $result['unique_brands'] ) ? $result['unique_brands'] : array(),
				);
				update_option( 'wp_mcp_ai_ext_cog_video_jobs', $jobs, false );
			}

			// Store full result on the sensor session CPT.
			if ( class_exists( 'WP_MCP_AI_Ext_Cog_Sensor_Session' ) ) {
				$session_post_id = WP_MCP_AI_Ext_Cog_Sensor_Session::get_or_create( $session_id, $user_id );
				if ( ! is_wp_error( $session_post_id ) ) {
					$pending = get_post_meta( $session_post_id, WP_MCP_AI_Ext_Cog_Sensor_Session::META_DATA, true );
					$pending = is_array( $pending ) ? $pending : array();
					$pending[ 'video_analysis_' . $action_id ] = $result;
					update_post_meta( $session_post_id, WP_MCP_AI_Ext_Cog_Sensor_Session::META_DATA, $pending );
				}
			}
		}
	} catch ( Exception $e ) {
		// Mark job as failed and re-throw so AS can retry.
		$jobs = get_option( 'wp_mcp_ai_ext_cog_video_jobs', array() );
		$jobs = is_array( $jobs ) ? $jobs : array();

		if ( $action_id > 0 && isset( $jobs[ $action_id ] ) ) {
			$jobs[ $action_id ]['status'] = 'failed';
			$jobs[ $action_id ]['error']  = $e->getMessage();
			update_option( 'wp_mcp_ai_ext_cog_video_jobs', $jobs, false );
		}

		throw $e;
	}
}
