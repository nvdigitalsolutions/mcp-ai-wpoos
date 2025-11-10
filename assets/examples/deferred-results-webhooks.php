<?php
/**
 * Example: Handling Deferred Results with Webhooks and Polling
 *
 * This example demonstrates how to handle tools that schedule work (like cron jobs)
 * and need to wait for results using webhooks or polling mechanisms.
 *
 * @package WP_MCP_AI
 */

/**
 * Example 1: Detect if a tool requires deferred result handling.
 */
function wp_mcp_ai_example_requires_deferred_handling( $tool_slug ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$flags    = $registry->get_tool_capability_flags( $tool_slug );
	
	return in_array( 'deferred-result', $flags, true ) 
		|| in_array( 'requires-polling', $flags, true )
		|| in_array( 'requires-callback', $flags, true );
}

/**
 * Example 2: Execute tool with webhook callback support.
 */
function wp_mcp_ai_example_execute_with_webhook( $tool_slug, $arguments, $context ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$tool     = $registry->get_tool( $tool_slug );
	$flags    = $registry->get_tool_capability_flags( $tool_slug );
	
	// Check if tool supports webhooks.
	if ( in_array( 'supports-webhook', $flags, true ) || in_array( 'requires-callback', $flags, true ) ) {
		// Generate a unique job ID.
		$job_id = wp_generate_uuid4();
		
		// Create webhook endpoint for this job.
		$webhook_url = rest_url( "mcp-ai/v1/webhook/job/{$job_id}" );
		
		// Add webhook URL to arguments.
		$arguments['callback_url'] = $webhook_url;
		$arguments['job_id']       = $job_id;
		
		// Store job metadata for tracking.
		$job_data = array(
			'job_id'      => $job_id,
			'tool_slug'   => $tool_slug,
			'status'      => 'pending',
			'started_at'  => current_time( 'mysql' ),
			'arguments'   => $arguments,
			'context'     => $context,
			'webhook_url' => $webhook_url,
		);
		
		set_transient( "wp_mcp_ai_job_{$job_id}", $job_data, DAY_IN_SECONDS );
		
		// Execute tool.
		$result = $tool->execute( $arguments, $context );
		
		// Return job ID for tracking.
		return array(
			'job_id'      => $job_id,
			'status'      => 'scheduled',
			'webhook_url' => $webhook_url,
			'message'     => 'Job scheduled. Results will be sent to webhook when complete.',
			'result'      => $result,
		);
	}
	
	// No webhook support - execute normally.
	return $tool->execute( $arguments, $context );
}

/**
 * Example 3: Webhook endpoint handler for job completion.
 *
 * Register this in your REST API routes.
 */
function wp_mcp_ai_example_webhook_handler( WP_REST_Request $request ) {
	$job_id = $request->get_param( 'job_id' );
	$result = $request->get_json_params();
	
	// Retrieve job metadata.
	$job_data = get_transient( "wp_mcp_ai_job_{$job_id}" );
	
	if ( ! $job_data ) {
		return new WP_Error( 'job_not_found', 'Job not found or expired', array( 'status' => 404 ) );
	}
	
	// Update job status.
	$job_data['status']       = 'completed';
	$job_data['completed_at'] = current_time( 'mysql' );
	$job_data['result']       = $result;
	
	set_transient( "wp_mcp_ai_job_{$job_id}", $job_data, DAY_IN_SECONDS );
	
	// Trigger action for other systems to respond.
	do_action( 'wp_mcp_ai_job_completed', $job_id, $result, $job_data );
	
	// If this is part of an agentic workflow, resume it.
	if ( ! empty( $job_data['workflow_id'] ) ) {
		wp_mcp_ai_example_resume_workflow( $job_data['workflow_id'], $job_id, $result );
	}
	
	return array(
		'success' => true,
		'job_id'  => $job_id,
		'message' => 'Job result received',
	);
}

/**
 * Example 4: Polling mechanism for tools that don't support webhooks.
 */
function wp_mcp_ai_example_poll_for_result( $job_id, $max_attempts = 30, $interval = 2 ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	
	$job_data = get_transient( "wp_mcp_ai_job_{$job_id}" );
	
	if ( ! $job_data ) {
		return new WP_Error( 'job_not_found', 'Job not found' );
	}
	
	$tool_slug = $job_data['tool_slug'];
	$flags     = $registry->get_tool_capability_flags( $tool_slug );
	
	// If tool requires polling, implement polling logic.
	if ( in_array( 'requires-polling', $flags, true ) ) {
		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			// Check job status (example: check cron job completion).
			$status = wp_mcp_ai_example_check_job_status( $job_id, $job_data );
			
			if ( 'completed' === $status['status'] ) {
				return $status['result'];
			}
			
			if ( 'failed' === $status['status'] ) {
				return new WP_Error( 'job_failed', $status['error'] ?? 'Job failed' );
			}
			
			// Wait before next poll.
			sleep( $interval );
		}
		
		return new WP_Error( 'polling_timeout', 'Job did not complete within timeout' );
	}
	
	// Not a polling tool - return current status.
	return $job_data;
}

/**
 * Example 5: Check job status (implementation depends on job type).
 */
function wp_mcp_ai_example_check_job_status( $job_id, $job_data ) {
	// Example: Check if cron job has completed.
	if ( 'create_cron_job' === $job_data['tool_slug'] ) {
		$hook = $job_data['arguments']['hook'] ?? '';
		
		// Check if cron job has run by checking custom tracking.
		$execution_log = get_option( "wp_mcp_ai_cron_log_{$hook}", array() );
		
		foreach ( $execution_log as $execution ) {
			if ( $execution['job_id'] === $job_id ) {
				return array(
					'status' => 'completed',
					'result' => $execution['result'],
				);
			}
		}
	}
	
	// Still pending.
	return array( 'status' => 'pending' );
}

/**
 * Example 6: Orchestrate agentic workflow with deferred results.
 */
function wp_mcp_ai_example_orchestrate_with_deferred_tools( $workflow_id, $steps ) {
	$workflow_state = array(
		'workflow_id'   => $workflow_id,
		'steps'         => $steps,
		'current_step'  => 0,
		'pending_jobs'  => array(),
		'status'        => 'running',
		'started_at'    => current_time( 'mysql' ),
	);
	
	set_transient( "wp_mcp_ai_workflow_{$workflow_id}", $workflow_state, DAY_IN_SECONDS );
	
	// Execute first step.
	wp_mcp_ai_example_execute_workflow_step( $workflow_id, 0 );
	
	return array(
		'workflow_id' => $workflow_id,
		'status'      => 'running',
		'message'     => 'Workflow started',
	);
}

/**
 * Example 7: Execute a workflow step.
 */
function wp_mcp_ai_example_execute_workflow_step( $workflow_id, $step_index ) {
	$workflow_state = get_transient( "wp_mcp_ai_workflow_{$workflow_id}" );
	
	if ( ! $workflow_state || $step_index >= count( $workflow_state['steps'] ) ) {
		return;
	}
	
	$step     = $workflow_state['steps'][ $step_index ];
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$flags    = $registry->get_tool_capability_flags( $step['tool'] );
	
	// Check if this tool has deferred results.
	if ( in_array( 'deferred-result', $flags, true ) ) {
		// Execute with webhook.
		$step['arguments']['workflow_id'] = $workflow_id;
		$result = wp_mcp_ai_example_execute_with_webhook( 
			$step['tool'], 
			$step['arguments'], 
			$step['context'] ?? array() 
		);
		
		// Add to pending jobs.
		$workflow_state['pending_jobs'][ $result['job_id'] ] = array(
			'step_index' => $step_index,
			'job_id'     => $result['job_id'],
			'status'     => 'pending',
		);
		
		// Update workflow state.
		set_transient( "wp_mcp_ai_workflow_{$workflow_id}", $workflow_state, DAY_IN_SECONDS );
		
		// Don't proceed to next step - wait for webhook.
		return;
	}
	
	// Synchronous tool - execute immediately.
	$tool   = $registry->get_tool( $step['tool'] );
	$result = $tool->execute( $step['arguments'], $step['context'] ?? array() );
	
	// Store result and proceed to next step.
	$workflow_state['steps'][ $step_index ]['result'] = $result;
	$workflow_state['current_step']                   = $step_index + 1;
	
	set_transient( "wp_mcp_ai_workflow_{$workflow_id}", $workflow_state, DAY_IN_SECONDS );
	
	// Execute next step.
	wp_mcp_ai_example_execute_workflow_step( $workflow_id, $step_index + 1 );
}

/**
 * Example 8: Resume workflow when deferred job completes.
 */
function wp_mcp_ai_example_resume_workflow( $workflow_id, $job_id, $result ) {
	$workflow_state = get_transient( "wp_mcp_ai_workflow_{$workflow_id}" );
	
	if ( ! $workflow_state ) {
		return;
	}
	
	// Update job status.
	if ( isset( $workflow_state['pending_jobs'][ $job_id ] ) ) {
		$step_index = $workflow_state['pending_jobs'][ $job_id ]['step_index'];
		
		// Store result.
		$workflow_state['steps'][ $step_index ]['result'] = $result;
		$workflow_state['pending_jobs'][ $job_id ]['status'] = 'completed';
		
		// Remove from pending.
		unset( $workflow_state['pending_jobs'][ $job_id ] );
		
		// If no more pending jobs for this step, proceed to next.
		if ( empty( $workflow_state['pending_jobs'] ) ) {
			$workflow_state['current_step'] = $step_index + 1;
			set_transient( "wp_mcp_ai_workflow_{$workflow_id}", $workflow_state, DAY_IN_SECONDS );
			
			// Execute next step.
			wp_mcp_ai_example_execute_workflow_step( $workflow_id, $step_index + 1 );
		} else {
			// Still waiting for other jobs.
			set_transient( "wp_mcp_ai_workflow_{$workflow_id}", $workflow_state, DAY_IN_SECONDS );
		}
	}
}

/**
 * Example 9: Get orchestration strategy based on capability flags.
 */
function wp_mcp_ai_example_get_orchestration_strategy( $tool_slug ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$flags    = $registry->get_tool_capability_flags( $tool_slug );
	
	if ( in_array( 'supports-webhook', $flags, true ) ) {
		return 'webhook'; // Best option - event-driven.
	}
	
	if ( in_array( 'requires-callback', $flags, true ) ) {
		return 'webhook_required'; // Must use webhook.
	}
	
	if ( in_array( 'requires-polling', $flags, true ) ) {
		return 'polling'; // Must poll for results.
	}
	
	if ( in_array( 'deferred-result', $flags, true ) ) {
		return 'polling_fallback'; // Deferred but no specific mechanism.
	}
	
	if ( in_array( 'async', $flags, true ) ) {
		return 'async_wait'; // Async but returns result eventually.
	}
	
	return 'synchronous'; // Returns immediately.
}

/**
 * Example 10: Register webhook REST endpoint.
 *
 * Add this to your plugin's REST API registration.
 */
function wp_mcp_ai_example_register_webhook_endpoint() {
	register_rest_route(
		'mcp-ai/v1',
		'/webhook/job/(?P<job_id>[a-f0-9\-]+)',
		array(
			'methods'             => 'POST',
			'callback'            => 'wp_mcp_ai_example_webhook_handler',
			'permission_callback' => function( $request ) {
				// Validate webhook signature or token.
				$token = $request->get_header( 'X-Webhook-Token' );
				$job_id = $request->get_param( 'job_id' );
				$expected_token = get_transient( "wp_mcp_ai_webhook_token_{$job_id}" );
				
				return $token === $expected_token;
			},
			'args'                => array(
				'job_id' => array(
					'required'          => true,
					'validate_callback' => function( $param ) {
						return wp_is_uuid( $param );
					},
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'wp_mcp_ai_example_register_webhook_endpoint' );
