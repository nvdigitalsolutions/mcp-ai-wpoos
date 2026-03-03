<?php
/**
 * Tool for batch memory management operations.
 *
 * Implements industry best practices for batch operations on agent memory.
 * Supports bulk update, delete, export, import, and tag management.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch manage agent memory contexts.
 *
 * This tool enables efficient bulk operations on multiple contexts:
 * - Batch update (tags, importance, metadata)
 * - Batch delete
 * - Export memories to JSON
 * - Import memories from JSON
 * - Batch tag management
 *
 * Follows RAG industry best practices for production-scale memory management.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Batch_Manage_Memory implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'batch_manage_memory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Batch Manage Memory', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Perform batch operations on agent memory contexts: bulk update tags/importance, bulk delete, export to JSON, import from JSON, and batch tag management. Optimized for managing large-scale memory systems.', 'mcp-ai-wpoos' );
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
					'description' => __( 'Batch operation to perform', 'mcp-ai-wpoos' ),
					'enum'        => array( 'bulk_update', 'bulk_delete', 'export', 'import', 'tag_add', 'tag_remove', 'tag_replace' ),
				),
				'agent_id'    => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier', 'mcp-ai-wpoos' ),
				),
				'context_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of context IDs to operate on (required for bulk operations)', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
				'filters'     => array(
					'type'        => 'object',
					'description' => __( 'Filters to select contexts (alternative to context_ids)', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'context_types' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'tags'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'importance'    => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
								'enum' => array( 'low', 'medium', 'high', 'critical' ),
							),
						),
					),
				),
				'updates'     => array(
					'type'        => 'object',
					'description' => __( 'Updates to apply for bulk_update action', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'importance' => array(
							'type' => 'string',
							'enum' => array( 'low', 'medium', 'high', 'critical' ),
						),
						'add_tags'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'metadata'   => array(
							'type' => 'object',
						),
					),
				),
				'tags'        => array(
					'type'        => 'array',
					'description' => __( 'Tags for tag management operations', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
				'export_data' => array(
					'type'        => 'string',
					'description' => __( 'JSON data for import action', 'mcp-ai-wpoos' ),
				),
				'options'     => array(
					'type'       => 'object',
					'properties' => array(
						'include_expired' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'dry_run'         => array(
							'type'        => 'boolean',
							'description' => __( 'Preview changes without applying them', 'mcp-ai-wpoos' ),
							'default'     => false,
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

		$action   = sanitize_key( $arguments['action'] );
		$agent_id = is_numeric( $arguments['agent_id'] ) ? absint( $arguments['agent_id'] ) : sanitize_text_field( $arguments['agent_id'] );
		$options  = isset( $arguments['options'] ) && is_array( $arguments['options'] ) ? $arguments['options'] : array();
		$dry_run  = isset( $options['dry_run'] ) && $options['dry_run'];

		// Route to action handler.
		switch ( $action ) {
			case 'bulk_update':
				return $this->bulk_update( $agent_id, $arguments, $dry_run );

			case 'bulk_delete':
				return $this->bulk_delete( $agent_id, $arguments, $dry_run );

			case 'export':
				return $this->export_memories( $agent_id, $arguments );

			case 'import':
				return $this->import_memories( $agent_id, $arguments, $dry_run );

			case 'tag_add':
			case 'tag_remove':
			case 'tag_replace':
				return $this->manage_tags( $agent_id, $action, $arguments, $dry_run );

			default:
				return array(
					'success' => false,
					'message' => __( 'Invalid action.', 'mcp-ai-wpoos' ),
				);
		}
	}

	/**
	 * Bulk update contexts.
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param array      $arguments Arguments.
	 * @param bool       $dry_run   Dry run flag.
	 * @return array Result.
	 */
	private function bulk_update( $agent_id, $arguments, $dry_run ) {
		$context_ids = $this->get_context_ids( $agent_id, $arguments );

		if ( empty( $context_ids ) ) {
			return array(
				'success' => false,
				'message' => __( 'No contexts found matching the criteria.', 'mcp-ai-wpoos' ),
			);
		}

		if ( empty( $arguments['updates'] ) || ! is_array( $arguments['updates'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Updates object is required for bulk_update action.', 'mcp-ai-wpoos' ),
			);
		}

		$updates         = $arguments['updates'];
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$updated_count   = 0;
		$failed_count    = 0;
		$changes         = array();

		foreach ( $context_ids as $context_id ) {
			$context = $context_manager->retrieve_context( $agent_id, $context_id, false );

			if ( ! $context ) {
				++$failed_count;
				continue;
			}

			$context_changes = array();

			// Apply updates.
			if ( isset( $updates['importance'] ) ) {
				$old_importance                = isset( $context['data']['importance'] ) ? $context['data']['importance'] : 'medium';
				$context['data']['importance'] = $updates['importance'];
				$context_changes['importance'] = array(
					'from' => $old_importance,
					'to'   => $updates['importance'],
				);
			}

			if ( isset( $updates['add_tags'] ) && is_array( $updates['add_tags'] ) ) {
				$existing_tags                 = isset( $context['data']['tags'] ) ? $context['data']['tags'] : array();
				$context['data']['tags']       = array_unique( array_merge( $existing_tags, $updates['add_tags'] ) );
				$context_changes['tags_added'] = $updates['add_tags'];
			}

			if ( isset( $updates['metadata'] ) && is_array( $updates['metadata'] ) ) {
				$existing_metadata                   = isset( $context['data']['metadata'] ) ? $context['data']['metadata'] : array();
				$context['data']['metadata']         = array_merge( $existing_metadata, $updates['metadata'] );
				$context_changes['metadata_updated'] = array_keys( $updates['metadata'] );
			}

			if ( empty( $context_changes ) ) {
				continue;
			}

			// Add batch update metadata.
			if ( ! isset( $context['data']['metadata'] ) ) {
				$context['data']['metadata'] = array();
			}
			$context['data']['metadata']['batch_updated_at'] = current_time( 'mysql' );

			if ( ! $dry_run ) {
				// Save updated context.
				$remaining_ttl = strtotime( $context['expires_at'] ) - time();
				if ( $remaining_ttl > 0 ) {
					$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
					set_transient( $transient_key, $context, $remaining_ttl );
					++$updated_count;
				} else {
					++$failed_count;
				}
			} else {
				++$updated_count;
			}

			$changes[ $context_id ] = $context_changes;
		}

		$message = $dry_run
			? sprintf(
				/* translators: %d: number of contexts that would be updated */
				__( '[DRY RUN] Would update %d contexts.', 'mcp-ai-wpoos' ),
				$updated_count
			)
			: sprintf(
				/* translators: %d: number of contexts updated */
				__( 'Updated %d contexts successfully.', 'mcp-ai-wpoos' ),
				$updated_count
			);

		return array(
			'success'       => true,
			'message'       => $message,
			'updated_count' => $updated_count,
			'failed_count'  => $failed_count,
			'dry_run'       => $dry_run,
			'changes'       => $changes,
		);
	}

	/**
	 * Bulk delete contexts.
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param array      $arguments Arguments.
	 * @param bool       $dry_run   Dry run flag.
	 * @return array Result.
	 */
	private function bulk_delete( $agent_id, $arguments, $dry_run ) {
		$context_ids = $this->get_context_ids( $agent_id, $arguments );

		if ( empty( $context_ids ) ) {
			return array(
				'success' => false,
				'message' => __( 'No contexts found matching the criteria.', 'mcp-ai-wpoos' ),
			);
		}

		$deleted_count    = 0;
		$deleted_contexts = array();

		foreach ( $context_ids as $context_id ) {
			if ( ! $dry_run ) {
				$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
				$result          = $context_manager->delete_context( $agent_id, $context_id );

				if ( isset( $result['success'] ) && $result['success'] ) {
					++$deleted_count;
					$deleted_contexts[] = $context_id;
				}
			} else {
				++$deleted_count;
				$deleted_contexts[] = $context_id;
			}
		}

		$message = $dry_run
			? sprintf(
				/* translators: %d: number of contexts that would be deleted */
				__( '[DRY RUN] Would delete %d contexts.', 'mcp-ai-wpoos' ),
				$deleted_count
			)
			: sprintf(
				/* translators: %d: number of contexts deleted */
				__( 'Deleted %d contexts successfully.', 'mcp-ai-wpoos' ),
				$deleted_count
			);

		return array(
			'success'          => true,
			'message'          => $message,
			'deleted_count'    => $deleted_count,
			'deleted_contexts' => $deleted_contexts,
			'dry_run'          => $dry_run,
		);
	}

	/**
	 * Export memories to JSON.
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param array      $arguments Arguments.
	 * @return array Result.
	 */
	private function export_memories( $agent_id, $arguments ) {
		$context_ids     = $this->get_context_ids( $agent_id, $arguments );
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$exported_data   = array(
			'export_version' => '1.0',
			'agent_id'       => $agent_id,
			'exported_at'    => current_time( 'mysql' ),
			'contexts'       => array(),
		);

		foreach ( $context_ids as $context_id ) {
			$context = $context_manager->retrieve_context( $agent_id, $context_id, true );
			if ( $context ) {
				$exported_data['contexts'][] = $context;
			}
		}

		return array(
			'success'       => true,
			'message'       => sprintf(
				/* translators: %d: number of contexts exported */
				__( 'Exported %d contexts successfully.', 'mcp-ai-wpoos' ),
				count( $exported_data['contexts'] )
			),
			'export_data'   => wp_json_encode( $exported_data, JSON_PRETTY_PRINT ),
			'context_count' => count( $exported_data['contexts'] ),
		);
	}

	/**
	 * Import memories from JSON.
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param array      $arguments Arguments.
	 * @param bool       $dry_run   Dry run flag.
	 * @return array Result.
	 */
	private function import_memories( $agent_id, $arguments, $dry_run ) {
		if ( empty( $arguments['export_data'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Export data is required for import action.', 'mcp-ai-wpoos' ),
			);
		}

		$import_data = json_decode( $arguments['export_data'], true );

		if ( ! $import_data || ! isset( $import_data['contexts'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid export data format.', 'mcp-ai-wpoos' ),
			);
		}

		$imported_count = 0;
		$failed_count   = 0;

		foreach ( $import_data['contexts'] as $context ) {
			if ( ! $dry_run ) {
				$context_id    = isset( $context['context_id'] ) ? $context['context_id'] : 'ctx_' . wp_generate_password( 12, false );
				$ttl           = isset( $context['ttl'] ) ? absint( $context['ttl'] ) : DAY_IN_SECONDS * 30;
				$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );

				// Update context metadata.
				if ( ! isset( $context['data']['metadata'] ) ) {
					$context['data']['metadata'] = array();
				}
				$context['data']['metadata']['imported_at'] = current_time( 'mysql' );
				$context['agent_id']                        = $agent_id;

				set_transient( $transient_key, $context, $ttl );
				++$imported_count;
			} else {
				++$imported_count;
			}
		}

		$message = $dry_run
			? sprintf(
				/* translators: %d: number of contexts that would be imported */
				__( '[DRY RUN] Would import %d contexts.', 'mcp-ai-wpoos' ),
				$imported_count
			)
			: sprintf(
				/* translators: %d: number of contexts imported */
				__( 'Imported %d contexts successfully.', 'mcp-ai-wpoos' ),
				$imported_count
			);

		return array(
			'success'        => true,
			'message'        => $message,
			'imported_count' => $imported_count,
			'failed_count'   => $failed_count,
			'dry_run'        => $dry_run,
		);
	}

	/**
	 * Manage tags on multiple contexts.
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param string     $action    Tag action.
	 * @param array      $arguments Arguments.
	 * @param bool       $dry_run   Dry run flag.
	 * @return array Result.
	 */
	private function manage_tags( $agent_id, $action, $arguments, $dry_run ) {
		$context_ids = $this->get_context_ids( $agent_id, $arguments );

		if ( empty( $context_ids ) ) {
			return array(
				'success' => false,
				'message' => __( 'No contexts found matching the criteria.', 'mcp-ai-wpoos' ),
			);
		}

		if ( empty( $arguments['tags'] ) || ! is_array( $arguments['tags'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Tags array is required for tag operations.', 'mcp-ai-wpoos' ),
			);
		}

		$tags            = array_map( 'sanitize_text_field', $arguments['tags'] );
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$updated_count   = 0;

		foreach ( $context_ids as $context_id ) {
			$context = $context_manager->retrieve_context( $agent_id, $context_id, false );

			if ( ! $context ) {
				continue;
			}

			$existing_tags = isset( $context['data']['tags'] ) ? $context['data']['tags'] : array();

			switch ( $action ) {
				case 'tag_add':
					$context['data']['tags'] = array_unique( array_merge( $existing_tags, $tags ) );
					break;

				case 'tag_remove':
					$context['data']['tags'] = array_diff( $existing_tags, $tags );
					break;

				case 'tag_replace':
					$context['data']['tags'] = $tags;
					break;
			}

			if ( ! $dry_run ) {
				$remaining_ttl = strtotime( $context['expires_at'] ) - time();
				if ( $remaining_ttl > 0 ) {
					$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
					set_transient( $transient_key, $context, $remaining_ttl );
					++$updated_count;
				}
			} else {
				++$updated_count;
			}
		}

		$action_label = str_replace( 'tag_', '', $action );
		$message      = $dry_run
			? sprintf(
				/* translators: 1: action label, 2: number of contexts */
				__( '[DRY RUN] Would %1$s tags on %2$d contexts.', 'mcp-ai-wpoos' ),
				$action_label,
				$updated_count
			)
			: sprintf(
				/* translators: 1: action label, 2: number of contexts */
				__( 'Successfully %1$s tags on %2$d contexts.', 'mcp-ai-wpoos' ),
				$action_label,
				$updated_count
			);

		return array(
			'success'       => true,
			'message'       => $message,
			'updated_count' => $updated_count,
			'action'        => $action,
			'tags'          => $tags,
			'dry_run'       => $dry_run,
		);
	}

	/**
	 * Get context IDs based on arguments.
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param array      $arguments Arguments.
	 * @return array Context IDs.
	 */
	private function get_context_ids( $agent_id, $arguments ) {
		// If context_ids provided directly, use them.
		if ( ! empty( $arguments['context_ids'] ) && is_array( $arguments['context_ids'] ) ) {
			return array_map( 'sanitize_text_field', $arguments['context_ids'] );
		}

		// Otherwise, use filters to find contexts.
		$filters         = isset( $arguments['filters'] ) ? $arguments['filters'] : array();
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$contexts        = $context_manager->search_contexts( $agent_id, $filters, 1000, false );

		return array_map(
			function ( $context ) {
				return $context['context_id'];
			},
			$contexts
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
			'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer', 'system_administrator' ),
			'risk_level'            => 'elevated',
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
