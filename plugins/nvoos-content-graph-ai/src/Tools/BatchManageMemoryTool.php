<?php
/**
 * Batch Manage Memory tool (D8 Cluster 2c-5 port of the base plugin's
 * WP_MCP_AI_Tool_Batch_Manage_Memory — byte-identical slug, schema,
 * error codes, envelope, and transient keys; per-mode context-manager
 * seam).
 *
 * The base tool resolves context records through
 * WP_MCP_AI_Agent_Context_Manager. Standalone, that class does not
 * exist; the port reads and writes the base-identical transients
 * ('mcp_ai_ctx_' . md5(agent_context), 'mcp_ai_ctx_index_' . md5(agent))
 * directly with the same record shape and expiry semantics, so batch
 * operations work against contexts stored by either the base manager or
 * the ecosystem's own writers.
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

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
 */
class BatchManageMemoryTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'batch_manage_memory';
	}

	public function getName(): string {
		return __( 'Batch Manage Memory', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Perform batch operations on agent memory contexts: bulk update tags/importance, bulk delete, export to JSON, import from JSON, and batch tag management. Optimized for managing large-scale memory systems.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'Batch operation to perform', 'nvoos-content-graph-ai' ),
					'enum'        => array( 'bulk_update', 'bulk_delete', 'export', 'import', 'tag_add', 'tag_remove', 'tag_replace' ),
				),
				'agent_id'    => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier', 'nvoos-content-graph-ai' ),
				),
				'context_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of context IDs to operate on (required for bulk operations)', 'nvoos-content-graph-ai' ),
					'items'       => array( 'type' => 'string' ),
				),
				'filters'     => array(
					'type'        => 'object',
					'description' => __( 'Filters to select contexts (alternative to context_ids)', 'nvoos-content-graph-ai' ),
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
					'description' => __( 'Updates to apply for bulk_update action', 'nvoos-content-graph-ai' ),
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
					'description' => __( 'Tags for tag management operations', 'nvoos-content-graph-ai' ),
					'items'       => array( 'type' => 'string' ),
				),
				'export_data' => array(
					'type'        => 'string',
					'description' => __( 'JSON data for import action', 'nvoos-content-graph-ai' ),
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
							'description' => __( 'Preview changes without applying them', 'nvoos-content-graph-ai' ),
							'default'     => false,
						),
					),
				),
			),
			'required'             => array( 'action', 'agent_id' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|\WP_Error Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		// Validate required parameters.
		if ( empty( $arguments['action'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Action is required.', 'nvoos-content-graph-ai' )
			);
		}

		if ( empty( $arguments['agent_id'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Agent ID is required.', 'nvoos-content-graph-ai' )
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
				return new \WP_Error(
					'wp_mcp_ai_error',
					__( 'Invalid action.', 'nvoos-content-graph-ai' )
				);
		}
	}

	/**
	 * Bulk update contexts.
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param array      $arguments Arguments.
	 * @param bool       $dry_run   Dry run flag.
	 * @return array|\WP_Error Result.
	 */
	private function bulk_update( $agent_id, $arguments, $dry_run ) {
		$context_ids = $this->get_context_ids( $agent_id, $arguments );

		if ( empty( $context_ids ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'No contexts found matching the criteria.', 'nvoos-content-graph-ai' )
			);
		}

		if ( empty( $arguments['updates'] ) || ! is_array( $arguments['updates'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Updates object is required for bulk_update action.', 'nvoos-content-graph-ai' )
			);
		}

		$updates       = $arguments['updates'];
		$updated_count = 0;
		$failed_count  = 0;
		$changes       = array();

		foreach ( $context_ids as $context_id ) {
			$context = $this->retrieve_context( $agent_id, $context_id, false );

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
				__( '[DRY RUN] Would update %d contexts.', 'nvoos-content-graph-ai' ),
				$updated_count
			)
			: sprintf(
				/* translators: %d: number of contexts updated */
				__( 'Updated %d contexts successfully.', 'nvoos-content-graph-ai' ),
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
	 * @return array|\WP_Error Result.
	 */
	private function bulk_delete( $agent_id, $arguments, $dry_run ) {
		$context_ids = $this->get_context_ids( $agent_id, $arguments );

		if ( empty( $context_ids ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'No contexts found matching the criteria.', 'nvoos-content-graph-ai' )
			);
		}

		$deleted_count    = 0;
		$deleted_contexts = array();

		foreach ( $context_ids as $context_id ) {
			if ( ! $dry_run ) {
				$result = $this->delete_context( $agent_id, $context_id );

				if ( is_wp_error( $result ) ) {
					continue;
				}

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
				__( '[DRY RUN] Would delete %d contexts.', 'nvoos-content-graph-ai' ),
				$deleted_count
			)
			: sprintf(
				/* translators: %d: number of contexts deleted */
				__( 'Deleted %d contexts successfully.', 'nvoos-content-graph-ai' ),
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
		$context_ids   = $this->get_context_ids( $agent_id, $arguments );
		$exported_data = array(
			'export_version' => '1.0',
			'agent_id'       => $agent_id,
			'exported_at'    => current_time( 'mysql' ),
			'contexts'       => array(),
		);

		foreach ( $context_ids as $context_id ) {
			$context = $this->retrieve_context( $agent_id, $context_id, true );
			if ( $context ) {
				$exported_data['contexts'][] = $context;
			}
		}

		return array(
			'success'       => true,
			'message'       => sprintf(
				/* translators: %d: number of contexts exported */
				__( 'Exported %d contexts successfully.', 'nvoos-content-graph-ai' ),
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
	 * @return array|\WP_Error Result.
	 */
	private function import_memories( $agent_id, $arguments, $dry_run ) {
		if ( empty( $arguments['export_data'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Export data is required for import action.', 'nvoos-content-graph-ai' )
			);
		}

		$import_data = json_decode( $arguments['export_data'], true );

		if ( ! $import_data || ! isset( $import_data['contexts'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Invalid export data format.', 'nvoos-content-graph-ai' )
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
				__( '[DRY RUN] Would import %d contexts.', 'nvoos-content-graph-ai' ),
				$imported_count
			)
			: sprintf(
				/* translators: %d: number of contexts imported */
				__( 'Imported %d contexts successfully.', 'nvoos-content-graph-ai' ),
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
	 * @return array|\WP_Error Result.
	 */
	private function manage_tags( $agent_id, $action, $arguments, $dry_run ) {
		$context_ids = $this->get_context_ids( $agent_id, $arguments );

		if ( empty( $context_ids ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'No contexts found matching the criteria.', 'nvoos-content-graph-ai' )
			);
		}

		if ( empty( $arguments['tags'] ) || ! is_array( $arguments['tags'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Tags array is required for tag operations.', 'nvoos-content-graph-ai' )
			);
		}

		$tags          = array_map( 'sanitize_text_field', $arguments['tags'] );
		$updated_count = 0;

		foreach ( $context_ids as $context_id ) {
			$context = $this->retrieve_context( $agent_id, $context_id, false );

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
				__( '[DRY RUN] Would %1$s tags on %2$d contexts.', 'nvoos-content-graph-ai' ),
				$action_label,
				$updated_count
			)
			: sprintf(
				/* translators: 1: action label, 2: number of contexts */
				__( 'Successfully %1$s tags on %2$d contexts.', 'nvoos-content-graph-ai' ),
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
		$filters  = isset( $arguments['filters'] ) ? $arguments['filters'] : array();
		$contexts = $this->search_contexts( $agent_id, $filters, 1000, false );

		if ( is_wp_error( $contexts ) ) {
			return array();
		}

		return array_map(
			function ( $context ) {
				return $context['context_id'];
			},
			$contexts
		);
	}

	public function getCapabilityFlags(): array {
		return array(
			'local-only',           // No external API calls.
			'write',                // Supports bulk update/delete/import.
			'state-changing',       // Modifies memory data.
			'requires-capability',  // Needs user authentication.
		);
	}

	/**
	 * Retrieve a context record (per-mode seam).
	 *
	 * Monolith: the base Agent_Context_Manager. Standalone: the
	 * base-identical transient with the same expiry semantics.
	 *
	 * @param int|string $agent_id        Agent ID.
	 * @param string     $context_id      Context ID.
	 * @param bool       $include_expired Whether to include expired contexts.
	 * @return array|null Context record or null if not found/expired.
	 */
	private function retrieve_context( $agent_id, $context_id, $include_expired = false ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Agent_Context_Manager' ) ) {
			$manager = \WP_MCP_AI_Agent_Context_Manager::get_instance();
			return $manager->retrieve_context( $agent_id, $context_id, $include_expired );
		}

		$transient_key  = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
		$context_record = get_transient( $transient_key );

		if ( ! $context_record ) {
			return null;
		}

		if ( ! $include_expired && isset( $context_record['expires_at'] ) ) {
			$expires_timestamp = strtotime( $context_record['expires_at'] );
			if ( $expires_timestamp && time() > $expires_timestamp ) {
				return null;
			}
		}

		return $context_record;
	}

	/**
	 * Delete a context record (per-mode seam).
	 *
	 * Monolith: the base Agent_Context_Manager. Standalone: mirrors the
	 * manager's transient delete + index bookkeeping byte-for-byte.
	 *
	 * @param int|string $agent_id   Agent ID.
	 * @param string     $context_id Context ID.
	 * @return array|\WP_Error Operation result.
	 */
	private function delete_context( $agent_id, $context_id ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Agent_Context_Manager' ) ) {
			$manager = \WP_MCP_AI_Agent_Context_Manager::get_instance();
			return $manager->delete_context( $agent_id, $context_id );
		}

		$context = $this->retrieve_context( $agent_id, $context_id, true );

		if ( ! $context ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Context not found.', 'nvoos-content-graph-ai' ) );
		}

		// Delete the context.
		$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
		$deleted       = delete_transient( $transient_key );

		// Update index.
		$index_key     = 'mcp_ai_ctx_index_' . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );

		if ( is_array( $context_index ) && isset( $context_index[ $context_id ] ) ) {
			unset( $context_index[ $context_id ] );

			if ( empty( $context_index ) ) {
				delete_transient( $index_key );
			} else {
				set_transient( $index_key, $context_index, MONTH_IN_SECONDS );
			}
		}

		return array(
			'success'    => $deleted,
			'context_id' => $context_id,
		);
	}

	/**
	 * Search contexts for an agent (per-mode seam).
	 *
	 * Monolith: the base Agent_Context_Manager. Standalone: mirrors the
	 * manager's index-walk + expansion with the same expiry semantics.
	 *
	 * @param int|string $agent_id        Agent ID.
	 * @param array      $filters         Search filters.
	 * @param int        $limit           Maximum results.
	 * @param bool       $include_expired Whether to include expired contexts.
	 * @return array Array of context records.
	 */
	private function search_contexts( $agent_id, $filters = array(), $limit = 10, $include_expired = false ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Agent_Context_Manager' ) ) {
			$manager = \WP_MCP_AI_Agent_Context_Manager::get_instance();
			return $manager->search_contexts( $agent_id, $filters, $limit, $include_expired );
		}

		$index_key     = 'mcp_ai_ctx_index_' . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );

		if ( ! is_array( $context_index ) || empty( $context_index ) ) {
			return array();
		}

		$results = array();
		foreach ( $context_index as $ctx_id => $index_entry ) {
			// Check expiration.
			if ( ! $include_expired && isset( $index_entry['expires_at'] ) ) {
				$expires_timestamp = strtotime( $index_entry['expires_at'] );
				if ( $expires_timestamp && time() > $expires_timestamp ) {
					continue;
				}
			}

			// Get full context record.
			$context_record = $this->retrieve_context( $agent_id, $ctx_id, $include_expired );
			if ( $context_record ) {
				$results[] = $context_record;
			}

			// Limit results.
			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}
}
