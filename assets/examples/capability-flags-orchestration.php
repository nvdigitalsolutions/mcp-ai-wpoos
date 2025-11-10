<?php
/**
 * Example: Using Capability Flags for Agentic Workflow Orchestration
 *
 * This example demonstrates how to use capability flags to intelligently
 * select and validate tools before execution in an agentic workflow.
 *
 * @package WP_MCP_AI
 */

// Example 1: Pre-execution Validation
function wp_mcp_ai_example_validate_tool_before_execution( $tool_slug, $arguments, $context ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$tool     = $registry->get_tool( $tool_slug );
	
	if ( ! $tool ) {
		return new WP_Error( 'tool_not_found', "Tool '$tool_slug' not found" );
	}
	
	$flags = $registry->get_tool_capability_flags( $tool_slug );
	
	// Check if tool requires credentials.
	if ( in_array( 'requires-credentials', $flags, true ) ) {
		// Example: Check OpenAI credentials.
		if ( strpos( $tool_slug, 'openai' ) !== false ) {
			$api_key = get_option( 'wp_mcp_ai_openai_api_key' );
			if ( empty( $api_key ) ) {
				return new WP_Error(
					'missing_credentials',
					"Tool '$tool_slug' requires OpenAI API credentials"
				);
			}
		}
	}
	
	// Check if tool requires a plugin.
	if ( in_array( 'requires-plugin', $flags, true ) ) {
		// Example: Check WooCommerce.
		if ( strpos( $tool_slug, 'woo' ) !== false && ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error(
				'missing_plugin',
				"Tool '$tool_slug' requires WooCommerce plugin"
			);
		}
	}
	
	// Check network dependency in offline mode.
	$is_offline = defined( 'WP_MCP_AI_OFFLINE_MODE' ) && WP_MCP_AI_OFFLINE_MODE;
	if ( $is_offline && in_array( 'network-dependent', $flags, true ) ) {
		return new WP_Error(
			'network_required',
			"Tool '$tool_slug' requires network connectivity"
		);
	}
	
	// All validations passed - safe to execute.
	return $tool->execute( $arguments, $context );
}

// Example 2: Safe Operations Mode (Read-Only Tools Only)
function wp_mcp_ai_example_get_safe_tools() {
	$registry   = WP_MCP_AI_Tool_Registry::get_instance();
	$all_tools  = $registry->get_tools();
	$safe_tools = array();
	
	foreach ( $all_tools as $tool ) {
		$flags = $registry->get_tool_capability_flags( $tool->get_slug() );
		
		// Only include read-only, local-only tools.
		if ( in_array( 'read-only', $flags, true ) && in_array( 'local-only', $flags, true ) ) {
			$safe_tools[] = $tool;
		}
	}
	
	return $safe_tools;
}

// Example 3: Intelligent Caching Strategy
function wp_mcp_ai_example_execute_with_cache( $tool_slug, $arguments, $context ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$tool     = $registry->get_tool( $tool_slug );
	$flags    = $registry->get_tool_capability_flags( $tool_slug );
	
	// Only cache if tool is cacheable and read-only.
	if ( in_array( 'cacheable', $flags, true ) && in_array( 'read-only', $flags, true ) ) {
		$cache_key = 'wp_mcp_ai_tool_' . md5( $tool_slug . wp_json_encode( $arguments ) );
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_tools' );
		
		if ( false !== $cached ) {
			return $cached;
		}
		
		// Execute and cache result.
		$result = $tool->execute( $arguments, $context );
		
		if ( ! is_wp_error( $result ) ) {
			// Cache for 5 minutes.
			wp_cache_set( $cache_key, $result, 'wp_mcp_ai_tools', 300 );
		}
		
		return $result;
	}
	
	// Non-cacheable - execute directly.
	return $tool->execute( $arguments, $context );
}

// Example 4: Prioritize Tools by Characteristics
function wp_mcp_ai_example_prioritize_tools( $available_tools ) {
	$registry       = WP_MCP_AI_Tool_Registry::get_instance();
	$prioritized    = array();
	$score_map      = array();
	
	foreach ( $available_tools as $tool_slug ) {
		$flags = $registry->get_tool_capability_flags( $tool_slug );
		$score = 100; // Base score.
		
		// Prefer local-only tools (faster).
		if ( in_array( 'local-only', $flags, true ) ) {
			$score += 30;
		}
		
		// Prefer cacheable tools (efficiency).
		if ( in_array( 'cacheable', $flags, true ) ) {
			$score += 20;
		}
		
		// Deprioritize async tools (slower).
		if ( in_array( 'async', $flags, true ) ) {
			$score -= 20;
		}
		
		// Deprioritize rate-limited tools (may fail).
		if ( in_array( 'rate-limited', $flags, true ) ) {
			$score -= 15;
		}
		
		// Deprioritize tools requiring credentials (complexity).
		if ( in_array( 'requires-credentials', $flags, true ) ) {
			$score -= 10;
		}
		
		$score_map[ $tool_slug ] = $score;
	}
	
	// Sort by score descending.
	arsort( $score_map );
	
	return array_keys( $score_map );
}

// Example 5: Enforce Security Policy (No PII Data)
function wp_mcp_ai_example_filter_pii_safe_tools() {
	$registry  = WP_MCP_AI_Tool_Registry::get_instance();
	$all_tools = $registry->get_tools();
	$pii_safe  = array();
	
	foreach ( $all_tools as $tool ) {
		$flags = $registry->get_tool_capability_flags( $tool->get_slug() );
		
		// Exclude tools that return PII data.
		if ( ! in_array( 'pii-data', $flags, true ) ) {
			$pii_safe[] = $tool;
		}
	}
	
	return $pii_safe;
}

// Example 6: Network Resilience (Offline Fallback)
function wp_mcp_ai_example_get_offline_compatible_tools() {
	$registry      = WP_MCP_AI_Tool_Registry::get_instance();
	$all_tools     = $registry->get_tools();
	$offline_tools = array();
	
	foreach ( $all_tools as $tool ) {
		$flags = $registry->get_tool_capability_flags( $tool->get_slug() );
		
		// Include only tools that don't require network.
		if ( ! in_array( 'external-api', $flags, true ) 
			&& ! in_array( 'network-dependent', $flags, true ) ) {
			$offline_tools[] = $tool;
		}
	}
	
	return $offline_tools;
}

// Example 7: Report Tool Capabilities
function wp_mcp_ai_example_get_tool_report() {
	$registry  = WP_MCP_AI_Tool_Registry::get_instance();
	$all_tools = $registry->get_tools();
	$report    = array();
	
	foreach ( $all_tools as $tool ) {
		$slug  = $tool->get_slug();
		$flags = $registry->get_tool_capability_flags( $slug );
		
		$report[ $slug ] = array(
			'name'         => $tool->get_name(),
			'description'  => $tool->get_description(),
			'flags'        => $flags,
			'capabilities' => array(
				'is_read_only'          => in_array( 'read-only', $flags, true ),
				'modifies_state'        => in_array( 'state-changing', $flags, true ),
				'requires_credentials'  => in_array( 'requires-credentials', $flags, true ),
				'requires_network'      => in_array( 'network-dependent', $flags, true ) || in_array( 'external-api', $flags, true ),
				'is_cacheable'          => in_array( 'cacheable', $flags, true ),
				'handles_pii'           => in_array( 'pii-data', $flags, true ),
			),
		);
	}
	
	return $report;
}

// Example 8: Workflow Orchestration Decision
function wp_mcp_ai_example_should_execute_tool( $tool_slug, $workflow_config ) {
	$registry = WP_MCP_AI_Tool_Registry::get_instance();
	$flags    = $registry->get_tool_capability_flags( $tool_slug );
	
	// Check workflow configuration constraints.
	if ( ! empty( $workflow_config['read_only_mode'] ) ) {
		// In read-only mode, reject write tools.
		if ( in_array( 'write', $flags, true ) || in_array( 'state-changing', $flags, true ) ) {
			return new WP_Error( 'readonly_mode', 'Workflow is in read-only mode' );
		}
	}
	
	if ( ! empty( $workflow_config['offline_mode'] ) ) {
		// In offline mode, reject network-dependent tools.
		if ( in_array( 'network-dependent', $flags, true ) || in_array( 'external-api', $flags, true ) ) {
			return new WP_Error( 'offline_mode', 'Workflow is in offline mode' );
		}
	}
	
	if ( ! empty( $workflow_config['no_pii'] ) ) {
		// If PII is prohibited, reject tools that handle PII.
		if ( in_array( 'pii-data', $flags, true ) ) {
			return new WP_Error( 'pii_prohibited', 'Workflow prohibits PII data' );
		}
	}
	
	if ( ! empty( $workflow_config['max_execution_time'] ) ) {
		// If execution time is constrained, reject async tools.
		if ( in_array( 'async', $flags, true ) ) {
			return new WP_Error( 'time_constrained', 'Tool may exceed execution time limit' );
		}
	}
	
	// All checks passed.
	return true;
}
