<?php
/**
 * Example: Preventing Timeouts and Managing Large Responses
 *
 * This example demonstrates orchestration patterns to prevent HTTP timeouts
 * and manage large response sizes using capability flags.
 *
 * @package WP_MCP_AI
 */

/**
 * Example 1: Detect tools that may cause timeouts or large responses.
 */
function wp_mcp_ai_example_check_execution_risks( $tool_slug ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$flags    = $registry->get_tool_capability_flags( $tool_slug );
	
	$risks = array();
	
	// Timeout risks.
	if ( in_array( 'may-timeout', $flags, true ) ) {
		$risks[] = 'May exceed HTTP timeout - consider background execution';
	}
	
	if ( in_array( 'long-running', $flags, true ) ) {
		$risks[] = 'Long-running operation - requires background processing';
	}
	
	if ( in_array( 'background-only', $flags, true ) ) {
		$risks[] = 'Must execute in background to prevent timeout';
	}
	
	// Response size risks.
	if ( in_array( 'large-response', $flags, true ) ) {
		$risks[] = 'May return large response - consider pagination or streaming';
	}
	
	// Required mitigations.
	if ( in_array( 'deferred-result', $flags, true ) ) {
		$risks[] = 'Returns deferred result - use webhook or polling';
	}
	
	if ( in_array( 'requires-callback', $flags, true ) ) {
		$risks[] = 'Requires callback URL - webhook mandatory';
	}
	
	return $risks;
}

/**
 * Example 2: Choose execution strategy based on timeout risk.
 */
function wp_mcp_ai_example_choose_execution_strategy( $tool_slug ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$flags    = $registry->get_tool_capability_flags( $tool_slug );
	
	// Background-only tools MUST use async execution.
	if ( in_array( 'background-only', $flags, true ) ) {
		return 'background_required';
	}
	
	// Long-running tools should use background.
	if ( in_array( 'long-running', $flags, true ) ) {
		return 'background_recommended';
	}
	
	// Tools that may timeout should consider background.
	if ( in_array( 'may-timeout', $flags, true ) ) {
		return 'background_optional';
	}
	
	// Deferred results need special handling.
	if ( in_array( 'deferred-result', $flags, true ) ) {
		if ( in_array( 'supports-webhook', $flags, true ) ) {
			return 'webhook_preferred';
		}
		if ( in_array( 'requires-polling', $flags, true ) ) {
			return 'polling_required';
		}
		return 'background_recommended';
	}
	
	// Streaming capable - use for large responses.
	if ( in_array( 'streaming-capable', $flags, true ) ) {
		return 'streaming';
	}
	
	// Safe for synchronous execution.
	return 'synchronous';
}

/**
 * Example 3: Execute tool with timeout prevention.
 */
function wp_mcp_ai_example_execute_with_timeout_prevention( $tool_slug, $arguments, $context ) {
	$strategy = wp_mcp_ai_example_choose_execution_strategy( $tool_slug );
	
	switch ( $strategy ) {
		case 'background_required':
		case 'background_recommended':
			// Execute in background using Action Scheduler or WP Cron.
			return wp_mcp_ai_example_schedule_background_execution( $tool_slug, $arguments, $context );
			
		case 'webhook_preferred':
			// Execute with webhook callback.
			return wp_mcp_ai_example_execute_with_webhook( $tool_slug, $arguments, $context );
			
		case 'polling_required':
			// Execute and return job ID for polling.
			return wp_mcp_ai_example_execute_with_polling( $tool_slug, $arguments, $context );
			
		case 'streaming':
			// Use Server-Sent Events or chunked transfer.
			return wp_mcp_ai_example_execute_with_streaming( $tool_slug, $arguments, $context );
			
		default:
			// Safe for synchronous execution.
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$tool     = $registry->get_tool( $tool_slug );
			return $tool->execute( $arguments, $context );
	}
}

/**
 * Example 4: Schedule background execution using Action Scheduler.
 */
function wp_mcp_ai_example_schedule_background_execution( $tool_slug, $arguments, $context ) {
	// Generate job ID.
	$job_id = wp_generate_uuid4();
	
	// Store job data.
	$job_data = array(
		'job_id'     => $job_id,
		'tool_slug'  => $tool_slug,
		'arguments'  => $arguments,
		'context'    => $context,
		'status'     => 'queued',
		'created_at' => current_time( 'mysql' ),
	);
	
	set_transient( "wp_mcp_ai_job_{$job_id}", $job_data, DAY_IN_SECONDS );
	
	// Schedule with Action Scheduler (if available) or WP Cron.
	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action(
			time(),
			'wp_mcp_ai_execute_background_job',
			array( $job_id ),
			'wp-mcp-ai'
		);
	} else {
		wp_schedule_single_event(
			time(),
			'wp_mcp_ai_execute_background_job',
			array( $job_id )
		);
	}
	
	// Return immediately with job ID.
	return array(
		'job_id'  => $job_id,
		'status'  => 'queued',
		'message' => 'Job queued for background execution. Poll for results.',
	);
}

/**
 * Example 5: Background job executor (hooked to cron/action scheduler).
 */
function wp_mcp_ai_example_background_job_executor( $job_id ) {
	$job_data = get_transient( "wp_mcp_ai_job_{$job_id}" );
	
	if ( ! $job_data ) {
		return;
	}
	
	// Update status.
	$job_data['status']     = 'processing';
	$job_data['started_at'] = current_time( 'mysql' );
	set_transient( "wp_mcp_ai_job_{$job_id}", $job_data, DAY_IN_SECONDS );
	
	// Execute tool (no timeout limits in background).
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$tool     = $registry->get_tool( $job_data['tool_slug'] );
	
	if ( ! $tool ) {
		$job_data['status'] = 'failed';
		$job_data['error']  = 'Tool not found';
		set_transient( "wp_mcp_ai_job_{$job_id}", $job_data, DAY_IN_SECONDS );
		return;
	}
	
	try {
		$result = $tool->execute( $job_data['arguments'], $job_data['context'] );
		
		$job_data['status']       = 'completed';
		$job_data['result']       = $result;
		$job_data['completed_at'] = current_time( 'mysql' );
		
	} catch ( Exception $e ) {
		$job_data['status'] = 'failed';
		$job_data['error']  = $e->getMessage();
	}
	
	set_transient( "wp_mcp_ai_job_{$job_id}", $job_data, DAY_IN_SECONDS );
	
	// Trigger completion action.
	do_action( 'wp_mcp_ai_job_completed', $job_id, $job_data );
}
add_action( 'wp_mcp_ai_execute_background_job', 'wp_mcp_ai_example_background_job_executor' );

/**
 * Example 6: Handle large responses with pagination.
 */
function wp_mcp_ai_example_execute_with_pagination( $tool_slug, $arguments, $context ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$flags    = $registry->get_tool_capability_flags( $tool_slug );
	
	// Check if tool supports pagination.
	if ( ! in_array( 'paginated', $flags, true ) ) {
		// Tool doesn't support pagination - may return large response.
		if ( in_array( 'large-response', $flags, true ) ) {
			return new WP_Error(
				'response_too_large',
				'Tool may return large response but does not support pagination. Consider background execution.'
			);
		}
	}
	
	// Add pagination parameters.
	$page     = $arguments['page'] ?? 1;
	$per_page = $arguments['per_page'] ?? 10;
	
	$arguments['page']     = $page;
	$arguments['per_page'] = min( $per_page, 100 ); // Cap at 100 items.
	
	$tool   = $registry->get_tool( $tool_slug );
	$result = $tool->execute( $arguments, $context );
	
	// Add pagination metadata.
	if ( ! is_wp_error( $result ) && is_array( $result ) ) {
		$total = $result['total'] ?? count( $result );
		
		return array(
			'data'       => $result,
			'pagination' => array(
				'page'       => $page,
				'per_page'   => $per_page,
				'total'      => $total,
				'has_more'   => ( $page * $per_page ) < $total,
				'next_page'  => ( ( $page * $per_page ) < $total ) ? $page + 1 : null,
			),
		);
	}
	
	return $result;
}

/**
 * Example 7: Handle large responses with compression.
 */
function wp_mcp_ai_example_execute_with_compression( $tool_slug, $arguments, $context ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$flags    = $registry->get_tool_capability_flags( $tool_slug );
	$tool     = $registry->get_tool( $tool_slug );
	
	$result = $tool->execute( $arguments, $context );
	
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	
	// Check if tool supports compression or if response is large.
	if ( in_array( 'supports-compression', $flags, true ) || in_array( 'large-response', $flags, true ) ) {
		$serialized = wp_json_encode( $result );
		$size       = strlen( $serialized );
		
		// If response is > 100KB, compress it.
		if ( $size > 102400 ) {
			$compressed = gzencode( $serialized, 6 );
			
			// Only use compression if it saves >20%.
			if ( strlen( $compressed ) < ( $size * 0.8 ) ) {
				return array(
					'compressed' => true,
					'data'       => base64_encode( $compressed ),
					'original_size' => $size,
					'compressed_size' => strlen( $compressed ),
					'encoding'   => 'gzip+base64',
				);
			}
		}
	}
	
	return $result;
}

/**
 * Example 8: Stream large responses using Server-Sent Events.
 */
function wp_mcp_ai_example_execute_with_streaming( $tool_slug, $arguments, $context ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$flags    = $registry->get_tool_capability_flags( $tool_slug );
	
	if ( ! in_array( 'streaming-capable', $flags, true ) ) {
		return new WP_Error( 'not_streamable', 'Tool does not support streaming' );
	}
	
	// Set headers for SSE.
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' ); // Disable nginx buffering.
	}
	
	// Generate job ID for tracking.
	$job_id = wp_generate_uuid4();
	
	// Send initial event.
	echo "event: start\n";
	echo 'data: ' . wp_json_encode( array( 'job_id' => $job_id, 'status' => 'started' ) ) . "\n\n";
	
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	} else {
		flush();
	}
	
	// Execute tool and stream results.
	$tool = $registry->get_tool( $tool_slug );
	
	// Add streaming callback to context.
	$context['stream_callback'] = function( $data ) {
		echo "event: data\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";
		flush();
	};
	
	$result = $tool->execute( $arguments, $context );
	
	// Send completion event.
	echo "event: complete\n";
	echo 'data: ' . wp_json_encode( array( 'status' => 'completed', 'result' => $result ) ) . "\n\n";
	flush();
	
	exit; // End SSE stream.
}

/**
 * Example 9: Smart orchestration considering both timeout and size.
 */
function wp_mcp_ai_example_smart_execute( $tool_slug, $arguments, $context ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$flags    = $registry->get_tool_capability_flags( $tool_slug );
	
	// Risk assessment.
	$has_timeout_risk = in_array( 'may-timeout', $flags, true ) 
		|| in_array( 'long-running', $flags, true )
		|| in_array( 'background-only', $flags, true );
		
	$has_size_risk = in_array( 'large-response', $flags, true );
	
	$supports_pagination = in_array( 'paginated', $flags, true );
	$supports_streaming  = in_array( 'streaming-capable', $flags, true );
	$supports_compression = in_array( 'supports-compression', $flags, true );
	
	// Decision matrix.
	if ( $has_timeout_risk && $has_size_risk ) {
		// Both risks - use background execution.
		return wp_mcp_ai_example_schedule_background_execution( $tool_slug, $arguments, $context );
	}
	
	if ( $has_timeout_risk ) {
		// Timeout risk only.
		if ( $supports_streaming ) {
			return wp_mcp_ai_example_execute_with_streaming( $tool_slug, $arguments, $context );
		}
		return wp_mcp_ai_example_schedule_background_execution( $tool_slug, $arguments, $context );
	}
	
	if ( $has_size_risk ) {
		// Size risk only.
		if ( $supports_pagination ) {
			return wp_mcp_ai_example_execute_with_pagination( $tool_slug, $arguments, $context );
		}
		if ( $supports_streaming ) {
			return wp_mcp_ai_example_execute_with_streaming( $tool_slug, $arguments, $context );
		}
		if ( $supports_compression ) {
			return wp_mcp_ai_example_execute_with_compression( $tool_slug, $arguments, $context );
		}
		// No mitigation available - warn but proceed.
		error_log( "Warning: Tool {$tool_slug} may return large response without mitigation" );
	}
	
	// No significant risks - execute normally.
	$tool = $registry->get_tool( $tool_slug );
	return $tool->execute( $arguments, $context );
}

/**
 * Example 10: Validate tool can be executed within constraints.
 */
function wp_mcp_ai_example_validate_execution_constraints( $tool_slug, $constraints = array() ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$flags    = $registry->get_tool_capability_flags( $tool_slug );
	
	$max_timeout = $constraints['max_timeout'] ?? 30; // 30 seconds default.
	$max_response_size = $constraints['max_response_size'] ?? 1048576; // 1MB default.
	$allow_background = $constraints['allow_background'] ?? true;
	
	$errors = array();
	
	// Check timeout constraints.
	if ( in_array( 'background-only', $flags, true ) && ! $allow_background ) {
		$errors[] = 'Tool requires background execution but it is not allowed';
	}
	
	if ( in_array( 'may-timeout', $flags, true ) && $max_timeout < 60 && ! $allow_background ) {
		$errors[] = 'Tool may timeout with current timeout limit';
	}
	
	if ( in_array( 'long-running', $flags, true ) && ! $allow_background ) {
		$errors[] = 'Tool is long-running and requires background execution';
	}
	
	// Check response size constraints.
	if ( in_array( 'large-response', $flags, true ) ) {
		if ( ! in_array( 'paginated', $flags, true ) 
			&& ! in_array( 'supports-compression', $flags, true )
			&& ! in_array( 'streaming-capable', $flags, true ) ) {
			$errors[] = 'Tool may return large response without size mitigation';
		}
	}
	
	if ( ! empty( $errors ) ) {
		return new WP_Error( 'constraint_violation', implode( '; ', $errors ), array( 'errors' => $errors ) );
	}
	
	return true;
}

/**
 * Example 11: Register cron hook for background jobs.
 */
if ( ! wp_next_scheduled( 'wp_mcp_ai_execute_background_job' ) ) {
	// This hook will be triggered by wp_schedule_single_event.
	add_action( 'wp_mcp_ai_execute_background_job', 'wp_mcp_ai_example_background_job_executor' );
}
