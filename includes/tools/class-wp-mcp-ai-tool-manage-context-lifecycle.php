<?php
/**
 * Tool for managing context lifecycle and refresh cycles.
 *
 * Implements RAG best practices for context refresh and lifecycle management.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage context lifecycle and refresh cycles.
 *
 * This tool provides advanced memory management features:
 * - Context refresh to update TTL
 * - Automatic compression for aging contexts
 * - Context merging for related memories
 * - Lifecycle policy management
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Manage_Context_Lifecycle implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_context_lifecycle';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Context Lifecycle', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Advanced context lifecycle management: refresh TTL, apply compression, merge related contexts, and manage retention policies. Implements RAG best practices for memory lifecycle.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'     => array(
					'type'        => 'string',
					'description' => __( 'Lifecycle action to perform', 'mcp-ai-wpoos' ),
					'enum'        => array( 'refresh', 'compress', 'merge', 'analyze', 'prune' ),
				),
				'agent_id'   => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier', 'mcp-ai-wpoos' ),
				),
				'context_id' => array(
					'type'        => 'string',
					'description' => __( 'Context ID for single-context actions (refresh, compress)', 'mcp-ai-wpoos' ),
				),
				'context_ids' => array(
					'type'        => 'array',
					'description' => __( 'Multiple context IDs for merge action', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
				'options'    => array(
					'type'        => 'object',
					'description' => __( 'Action-specific options', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'new_ttl'         => array(
							'type'        => 'integer',
							'description' => __( 'New TTL in seconds for refresh action', 'mcp-ai-wpoos' ),
						),
						'target_ratio'    => array(
							'type'        => 'number',
							'description' => __( 'Compression ratio (0.3-1.0) for compress action', 'mcp-ai-wpoos' ),
						),
						'preserve_facts'  => array(
							'type'        => 'boolean',
							'description' => __( 'Preserve key facts during compression', 'mcp-ai-wpoos' ),
							'default'     => true,
						),
						'merge_title'     => array(
							'type'        => 'string',
							'description' => __( 'Title for merged context', 'mcp-ai-wpoos' ),
						),
						'prune_threshold' => array(
							'type'        => 'integer',
							'description' => __( 'Days threshold for pruning unused contexts', 'mcp-ai-wpoos' ),
							'default'     => 30,
						),
					),
				),
			),
			'required'             => array( 'action', 'agent_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['action'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Action is required.', 'mcp-ai-wpoos' ),
			);
		}

		if ( empty( $arguments['agent_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Agent ID is required.', 'mcp-ai-wpoos' ),
			);
		}

		// Sanitize inputs.
		$action   = sanitize_key( $arguments['action'] );
		$agent_id = is_numeric( $arguments['agent_id'] ) ? absint( $arguments['agent_id'] ) : sanitize_text_field( $arguments['agent_id'] );
		$options  = isset( $arguments['options'] ) && is_array( $arguments['options'] ) ? $arguments['options'] : array();

		// Route to action handler.
		switch ( $action ) {
			case 'refresh':
				return $this->refresh_context( $agent_id, $arguments, $options );

			case 'compress':
				return $this->compress_context( $agent_id, $arguments, $options );

			case 'merge':
				return $this->merge_contexts( $agent_id, $arguments, $options );

			case 'analyze':
				return $this->analyze_lifecycle( $agent_id );

			case 'prune':
				return $this->prune_unused_contexts( $agent_id, $options );

			default:
				return array(
					'success' => false,
					'message' => __( 'Invalid action.', 'mcp-ai-wpoos' ),
				);
		}
	}

	/**
	 * Refresh context TTL.
	 *
	 * @param int|string $agent_id   Agent ID.
	 * @param array      $arguments  Arguments.
	 * @param array      $options    Options.
	 * @return array Result.
	 */
	private function refresh_context( $agent_id, $arguments, $options ) {
		if ( empty( $arguments['context_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Context ID is required for refresh action.', 'mcp-ai-wpoos' ),
			);
		}

		$context_id = sanitize_text_field( $arguments['context_id'] );
		$new_ttl    = isset( $options['new_ttl'] ) ? absint( $options['new_ttl'] ) : DAY_IN_SECONDS * 30;

		// Validate TTL.
		$new_ttl = max( 3600, min( 31536000, $new_ttl ) );

		// Get context.
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$context         = $context_manager->retrieve_context( $agent_id, $context_id, false );

		if ( ! $context ) {
			return array(
				'success' => false,
				'message' => __( 'Context not found or has expired.', 'mcp-ai-wpoos' ),
			);
		}

		// Update expiration.
		$context['expires_at'] = gmdate( 'Y-m-d H:i:s', time() + $new_ttl );
		$context['ttl']        = $new_ttl;

		// Re-store context.
		$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
		set_transient( $transient_key, $context, $new_ttl );

		return array(
			'success'    => true,
			'message'    => __( 'Context TTL refreshed successfully.', 'mcp-ai-wpoos' ),
			'context_id' => $context_id,
			'new_ttl'    => $new_ttl,
			'expires_at' => $context['expires_at'],
		);
	}

	/**
	 * Compress context content.
	 *
	 * @param int|string $agent_id   Agent ID.
	 * @param array      $arguments  Arguments.
	 * @param array      $options    Options.
	 * @return array Result.
	 */
	private function compress_context( $agent_id, $arguments, $options ) {
		if ( empty( $arguments['context_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Context ID is required for compress action.', 'mcp-ai-wpoos' ),
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Context_Compression_Service' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Compression service is not available.', 'mcp-ai-wpoos' ),
			);
		}

		$context_id = sanitize_text_field( $arguments['context_id'] );

		// Get context.
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$context         = $context_manager->retrieve_context( $agent_id, $context_id, false );

		if ( ! $context ) {
			return array(
				'success' => false,
				'message' => __( 'Context not found or has expired.', 'mcp-ai-wpoos' ),
			);
		}

		// Apply compression.
		$compression_service = WP_MCP_AI_Context_Compression_Service::get_instance();
		$compressed_context  = $compression_service->apply_compression_policy( $context );

		// Re-store compressed context.
		$remaining_ttl = strtotime( $compressed_context['expires_at'] ) - time();
		if ( $remaining_ttl > 0 ) {
			$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
			set_transient( $transient_key, $compressed_context, $remaining_ttl );

			return array(
				'success'     => true,
				'message'     => __( 'Context compressed successfully.', 'mcp-ai-wpoos' ),
				'context_id'  => $context_id,
				'compression' => isset( $compressed_context['data']['compression'] ) ? $compressed_context['data']['compression'] : null,
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Context has expired.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Merge multiple contexts.
	 *
	 * @param int|string $agent_id   Agent ID.
	 * @param array      $arguments  Arguments.
	 * @param array      $options    Options.
	 * @return array Result.
	 */
	private function merge_contexts( $agent_id, $arguments, $options ) {
		if ( empty( $arguments['context_ids'] ) || ! is_array( $arguments['context_ids'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Context IDs array is required for merge action.', 'mcp-ai-wpoos' ),
			);
		}

		$context_ids = array_map( 'sanitize_text_field', $arguments['context_ids'] );

		if ( count( $context_ids ) < 2 ) {
			return array(
				'success' => false,
				'message' => __( 'At least 2 context IDs are required for merge.', 'mcp-ai-wpoos' ),
			);
		}

		// Get all contexts.
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$contexts        = array();
		$all_tags        = array();

		foreach ( $context_ids as $ctx_id ) {
			$context = $context_manager->retrieve_context( $agent_id, $ctx_id, false );
			if ( $context ) {
				$contexts[] = $context;
				if ( isset( $context['data']['tags'] ) ) {
					$all_tags = array_merge( $all_tags, $context['data']['tags'] );
				}
			}
		}

		if ( count( $contexts ) < 2 ) {
			return array(
				'success' => false,
				'message' => __( 'Not enough valid contexts found to merge.', 'mcp-ai-wpoos' ),
			);
		}

		// Merge content.
		$merged_content = '';
		foreach ( $contexts as $context ) {
			if ( isset( $context['data']['title'] ) ) {
				$merged_content .= '## ' . $context['data']['title'] . "\n\n";
			}
			if ( isset( $context['data']['content'] ) ) {
				$merged_content .= $context['data']['content'] . "\n\n---\n\n";
			}
		}

		// Create merged context.
		$merged_title = isset( $options['merge_title'] ) ? sanitize_text_field( $options['merge_title'] ) : __( 'Merged Context', 'mcp-ai-wpoos' );
		$merged_data  = array(
			'title'       => $merged_title,
			'content'     => trim( $merged_content ),
			'importance'  => 'high',
			'tags'        => array_unique( $all_tags ),
			'merged_from' => $context_ids,
			'merged_at'   => current_time( 'mysql' ),
		);

		// Store merged context.
		$result = $context_manager->store_context( $agent_id, 'merged', $merged_data, DAY_IN_SECONDS * 30 );

		if ( $result['success'] ) {
			// Delete original contexts.
			foreach ( $context_ids as $ctx_id ) {
				$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $ctx_id );
				delete_transient( $transient_key );
			}

			return array(
				'success'         => true,
				'message'         => __( 'Contexts merged successfully.', 'mcp-ai-wpoos' ),
				'new_context_id'  => $result['context_id'],
				'merged_count'    => count( $contexts ),
				'original_ids'    => $context_ids,
			);
		}

		return $result;
	}

	/**
	 * Analyze context lifecycle.
	 *
	 * @param int|string $agent_id Agent ID.
	 * @return array Analysis results.
	 */
	private function analyze_lifecycle( $agent_id ) {
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$health_metrics  = $context_manager->get_context_health_metrics( $agent_id );

		return array(
			'success'        => true,
			'message'        => __( 'Lifecycle analysis complete.', 'mcp-ai-wpoos' ),
			'health_metrics' => $health_metrics,
		);
	}

	/**
	 * Prune unused contexts.
	 *
	 * @param int|string $agent_id Agent ID.
	 * @param array      $options  Options.
	 * @return array Result.
	 */
	private function prune_unused_contexts( $agent_id, $options ) {
		$threshold_days = isset( $options['prune_threshold'] ) ? absint( $options['prune_threshold'] ) : 30;
		$threshold_time = time() - ( $threshold_days * DAY_IN_SECONDS );

		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$contexts        = $context_manager->search_contexts( $agent_id, array(), 100, false );

		$pruned_count = 0;

		foreach ( $contexts as $context ) {
			$access_count = isset( $context['access_count'] ) ? $context['access_count'] : 0;
			$stored_time  = strtotime( $context['stored_at'] );

			// Prune if never accessed and older than threshold.
			if ( 0 === $access_count && $stored_time < $threshold_time ) {
				$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context['context_id'] );
				delete_transient( $transient_key );
				++$pruned_count;
			}
		}

		return array(
			'success'       => true,
			'message'       => sprintf(
				/* translators: %d: number of pruned contexts */
				__( 'Pruned %d unused contexts.', 'mcp-ai-wpoos' ),
				$pruned_count
			),
			'pruned_count'  => $pruned_count,
			'threshold_days' => $threshold_days,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'ai_model_management',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'              => true,
			'local-only'        => true,
			'read-only'         => false,
			'idempotent'        => false,
			'cacheable'         => false,
			'requires-auth'     => true,
			'blocking'          => false,
			'uses-network'      => false,
			'modifies-wp'       => true,
			'expensive'         => false,
			'requires-approval' => false,
		);
	}
}
