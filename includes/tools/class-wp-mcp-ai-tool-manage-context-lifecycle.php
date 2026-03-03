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
		return __( 'Advanced context lifecycle management: refresh TTL, apply compression, merge related contexts, update memory content, delete specific contexts, and manage retention policies. Implements RAG best practices for memory lifecycle.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'Lifecycle action to perform', 'mcp-ai-wpoos' ),
					'enum'        => array( 'refresh', 'compress', 'merge', 'analyze', 'prune', 'update', 'delete' ),
				),
				'agent_id'    => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier', 'mcp-ai-wpoos' ),
				),
				'context_id'  => array(
					'type'        => 'string',
					'description' => __( 'Context ID for single-context actions (refresh, compress, update, delete)', 'mcp-ai-wpoos' ),
				),
				'context_ids' => array(
					'type'        => 'array',
					'description' => __( 'Multiple context IDs for merge action', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
				'options'     => array(
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
						'update_data'     => array(
							'type'        => 'object',
							'description' => __( 'Updated context data for update action', 'mcp-ai-wpoos' ),
							'properties'  => array(
								'title'      => array(
									'type'        => 'string',
									'description' => __( 'Updated title', 'mcp-ai-wpoos' ),
								),
								'content'    => array(
									'type'        => 'string',
									'description' => __( 'Updated content', 'mcp-ai-wpoos' ),
								),
								'metadata'   => array(
									'type'        => 'object',
									'description' => __( 'Updated metadata', 'mcp-ai-wpoos' ),
								),
								'tags'       => array(
									'type'        => 'array',
									'description' => __( 'Updated tags', 'mcp-ai-wpoos' ),
									'items'       => array( 'type' => 'string' ),
								),
								'importance' => array(
									'type'        => 'string',
									'description' => __( 'Updated importance level', 'mcp-ai-wpoos' ),
									'enum'        => array( 'low', 'medium', 'high', 'critical' ),
								),
							),
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

			case 'update':
				return $this->update_context( $agent_id, $arguments, $options );

			case 'delete':
				return $this->delete_context( $agent_id, $arguments );

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
				'success'        => true,
				'message'        => __( 'Contexts merged successfully.', 'mcp-ai-wpoos' ),
				'new_context_id' => $result['context_id'],
				'merged_count'   => count( $contexts ),
				'original_ids'   => $context_ids,
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
			'success'        => true,
			'message'        => sprintf(
				/* translators: %d: number of pruned contexts */
				__( 'Pruned %d unused contexts.', 'mcp-ai-wpoos' ),
				$pruned_count
			),
			'pruned_count'   => $pruned_count,
			'threshold_days' => $threshold_days,
		);
	}

	/**
	 * Update context data.
	 *
	 * @param int|string $agent_id   Agent ID.
	 * @param array      $arguments  Arguments.
	 * @param array      $options    Options.
	 * @return array Result.
	 */
	private function update_context( $agent_id, $arguments, $options ) {
		if ( empty( $arguments['context_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Context ID is required for update action.', 'mcp-ai-wpoos' ),
			);
		}

		if ( empty( $options['update_data'] ) || ! is_array( $options['update_data'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Update data is required for update action.', 'mcp-ai-wpoos' ),
			);
		}

		$context_id  = sanitize_text_field( $arguments['context_id'] );
		$update_data = $options['update_data'];

		// Get existing context.
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$context         = $context_manager->retrieve_context( $agent_id, $context_id, false );

		if ( ! $context ) {
			return array(
				'success' => false,
				'message' => __( 'Context not found or has expired.', 'mcp-ai-wpoos' ),
			);
		}

		// Update fields if provided.
		$updated_fields = array();

		if ( isset( $update_data['title'] ) ) {
			$context['data']['title'] = sanitize_text_field( $update_data['title'] );
			$updated_fields[]         = 'title';
		}

		if ( isset( $update_data['content'] ) ) {
			$context['data']['content'] = wp_kses_post( $update_data['content'] );
			$updated_fields[]           = 'content';
		}

		if ( isset( $update_data['metadata'] ) && is_array( $update_data['metadata'] ) ) {
			// Merge new metadata with existing.
			$existing_metadata           = isset( $context['data']['metadata'] ) ? $context['data']['metadata'] : array();
			$context['data']['metadata'] = array_merge( $existing_metadata, $update_data['metadata'] );
			$updated_fields[]            = 'metadata';
		}

		if ( isset( $update_data['tags'] ) && is_array( $update_data['tags'] ) ) {
			$context['data']['tags'] = array_map( 'sanitize_text_field', $update_data['tags'] );
			$updated_fields[]        = 'tags';
		}

		if ( isset( $update_data['importance'] ) ) {
			$valid_importance = array( 'low', 'medium', 'high', 'critical' );
			if ( in_array( $update_data['importance'], $valid_importance, true ) ) {
				$context['data']['importance'] = $update_data['importance'];
				$updated_fields[]              = 'importance';
			}
		}

		if ( empty( $updated_fields ) ) {
			return array(
				'success' => false,
				'message' => __( 'No valid fields provided for update.', 'mcp-ai-wpoos' ),
			);
		}

		// Add update metadata.
		if ( ! isset( $context['data']['metadata'] ) ) {
			$context['data']['metadata'] = array();
		}
		$context['data']['metadata']['last_updated']   = current_time( 'mysql' );
		$context['data']['metadata']['updated_fields'] = $updated_fields;
		$context['data']['metadata']['update_count']   = isset( $context['data']['metadata']['update_count'] ) ? $context['data']['metadata']['update_count'] + 1 : 1;

		// Re-store updated context.
		$remaining_ttl = strtotime( $context['expires_at'] ) - time();
		if ( $remaining_ttl > 0 ) {
			$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
			set_transient( $transient_key, $context, $remaining_ttl );

			// Update index.
			$index_key     = 'mcp_ai_ctx_index_' . md5( (string) $agent_id );
			$context_index = get_transient( $index_key );
			if ( is_array( $context_index ) && isset( $context_index[ $context_id ] ) ) {
				$context_index[ $context_id ]['title']      = $context['data']['title'];
				$context_index[ $context_id ]['importance'] = isset( $context['data']['importance'] ) ? $context['data']['importance'] : 'medium';
				$context_index[ $context_id ]['tags']       = isset( $context['data']['tags'] ) ? $context['data']['tags'] : array();
				set_transient( $index_key, $context_index, $remaining_ttl );

				// Invalidate dashboard memory stats cache to show updated data immediately.
				delete_transient( 'wp_mcp_ai_agent_memory_stats' );
			}

			return array(
				'success'        => true,
				'message'        => __( 'Context updated successfully.', 'mcp-ai-wpoos' ),
				'context_id'     => $context_id,
				'updated_fields' => $updated_fields,
				'updated_at'     => $context['data']['metadata']['last_updated'],
				'update_count'   => $context['data']['metadata']['update_count'],
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Context has expired and cannot be updated.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Delete a specific context.
	 *
	 * @param int|string $agent_id   Agent ID.
	 * @param array      $arguments  Arguments.
	 * @return array Result.
	 */
	private function delete_context( $agent_id, $arguments ) {
		if ( empty( $arguments['context_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Context ID is required for delete action.', 'mcp-ai-wpoos' ),
			);
		}

		$context_id = sanitize_text_field( $arguments['context_id'] );

		// Verify context exists before deletion.
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$context         = $context_manager->retrieve_context( $agent_id, $context_id, true );

		if ( ! $context ) {
			return array(
				'success' => false,
				'message' => __( 'Context not found.', 'mcp-ai-wpoos' ),
			);
		}

		// Delete context transient.
		$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
		$deleted       = delete_transient( $transient_key );

		// Update index to remove this context.
		$index_key     = 'mcp_ai_ctx_index_' . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );

		if ( is_array( $context_index ) && isset( $context_index[ $context_id ] ) ) {
			unset( $context_index[ $context_id ] );

			if ( empty( $context_index ) ) {
				delete_transient( $index_key );
			} else {
				// Keep the index with remaining TTL.
				set_transient( $index_key, $context_index, MONTH_IN_SECONDS );
			}

			// Invalidate dashboard memory stats cache to show updated data immediately.
			delete_transient( 'wp_mcp_ai_agent_memory_stats' );
		}

		if ( $deleted ) {
			return array(
				'success'      => true,
				'message'      => __( 'Context deleted successfully.', 'mcp-ai-wpoos' ),
				'context_id'   => $context_id,
				'deleted_at'   => current_time( 'mysql' ),
				'context_type' => $context['context_type'],
				'title'        => isset( $context['data']['title'] ) ? $context['data']['title'] : '',
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Failed to delete context. It may have already been removed.', 'mcp-ai-wpoos' ),
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
