<?php
/**
 * Agent Harness Bootstrap — Cross-session harness state persistence.
 *
 * Manages saving and loading evolved harness state across sessions,
 * enabling the agent to carry forward learned improvements. Each
 * "bootstrap bundle" captures the agent's prompt, roles, skills, and
 * memory configuration so it can be restored in future sessions.
 *
 * Part of the Continual Harness framework (Karten et al., 2026).
 *
 * @package    WP_MCP_AI
 * @subpackage NvoosContentGraphAiPlatform/src/Agents
 * @since      1.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions
 * @license    GPL-3.0-or-later
 *
 * @reference  Karten, S., Agrawal, S., Buddharaju, D., et al. (2026).
 *   "Continual Harness: A Continual Learning System for General-purpose
 *   AI Agent Self-Improvement." arXiv:2603.04586.
 *
 * @see AgentHarnessEvolver Core evolution engine.
 * @see WP_MCP_AI_Tool_Evolve_Harness   Tool that exposes evolution to the agent.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Harness Bootstrap class.
 *
 * Provides a fully static API for saving and loading evolved harness
 * state as named bootstrap bundles. Bundles are stored as WordPress
 * options and pruned to a configurable maximum per assistant.
 *
 * Bundle shape:
 * ```
 * array(
 *     'bundle_id'        => string,   // Unique identifier (uuid-style).
 *     'assistant_id'     => int,      // Assistant post ID.
 *     'session_id'       => string,   // Session that produced this bundle.
 *     'created_at'       => string,   // MySQL datetime (UTC).
 *     'prompt'           => string,   // Evolved system prompt.
 *     'roles'            => array,    // Evolved role dispositions.
 *     'skills'           => array,    // Evolved tool selection preferences.
 *     'memory_summary'   => string,   // Evolved memory strategy summary.
 *     'generation_count' => int,      // How many evolutions produced this.
 * )
 * ```
 *
 * @since 1.2.0
 */
class AgentHarnessBootstrap {

	/**
	 * WordPress option key prefix for bootstrap bundles.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	const OPTION_PREFIX = 'wp_mcp_ai_harness_bootstrap_';

	/**
	 * Option key for the per-assistant bundle index.
	 *
	 * Maps assistant_id to an ordered array of bundle_ids
	 * (most recent first).
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	const INDEX_OPTION = 'wp_mcp_ai_harness_bootstrap_index';

	/**
	 * Default maximum bundles per assistant.
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	const DEFAULT_MAX_BUNDLES = 10;

	/**
	 * Save current evolved harness state as a named bootstrap bundle.
	 *
	 * Captures the assistant's current prompt, role dispositions,
	 * skill tool-sets, and memory configuration into a bundle that
	 * can be loaded in a future session.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $session_id   Session identifier that produced this state.
	 * @return string|WP_Error Bundle ID on success, WP_Error on failure.
	 */
	public static function save_state( $assistant_id, $session_id ) {
		$assistant_id = absint( $assistant_id );
		if ( $assistant_id <= 0 ) {
			return new \WP_Error(
				'wp_mcp_ai_bootstrap_invalid_assistant',
				__( 'A valid assistant ID is required to save harness state.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $session_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_bootstrap_invalid_session',
				__( 'A valid session ID is required to save harness state.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		// Gather current harness state from the assistant.
		$prompt         = self::get_current_prompt( $assistant_id );
		$roles          = self::get_current_roles( $assistant_id );
		$skills         = self::get_current_skills( $assistant_id );
		$memory_summary = self::get_current_memory_summary( $assistant_id );
		$gen_count      = self::get_current_generation_count( $assistant_id );

		$bundle_id = self::generate_bundle_id( $assistant_id, $session_id );

		$bundle = array(
			'bundle_id'        => $bundle_id,
			'assistant_id'     => $assistant_id,
			'session_id'       => sanitize_text_field( $session_id ),
			'created_at'       => current_time( 'mysql', true ),
			'prompt'           => $prompt,
			'roles'            => $roles,
			'skills'           => $skills,
			'memory_summary'   => $memory_summary,
			'generation_count' => $gen_count,
		);

		// Persist the bundle.
		$option_key = self::OPTION_PREFIX . $bundle_id;
		$saved      = update_option( $option_key, $bundle, false );

		if ( ! $saved ) {
			return new \WP_Error(
				'wp_mcp_ai_bootstrap_save_failed',
				__( 'Failed to save harness bootstrap bundle.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 500 )
			);
		}

		// Update the index.
		self::add_to_index( $assistant_id, $bundle_id );

		// Prune old bundles.
		self::prune_bundles( $assistant_id );

		/**
		 * Fires after a harness bootstrap bundle is saved.
		 *
		 * @since 1.2.0
		 *
		 * @param string $bundle_id    The saved bundle identifier.
		 * @param int    $assistant_id The assistant post ID.
		 * @param array  $bundle       The full bundle data.
		 */
		do_action( 'wp_mcp_ai_harness_bootstrap_saved', $bundle_id, $assistant_id, $bundle );

		return $bundle_id;
	}

	/**
	 * Load a saved bootstrap bundle and apply it to an assistant.
	 *
	 * Restores the evolved prompt, roles, skills, and memory configuration
	 * from a previously saved bundle.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $bundle_id    Bundle identifier to load.
	 * @return array|WP_Error Summary of restored state on success, WP_Error on failure.
	 */
	public static function load_state( $assistant_id, $bundle_id ) {
		$assistant_id = absint( $assistant_id );
		if ( $assistant_id <= 0 ) {
			return new \WP_Error(
				'wp_mcp_ai_bootstrap_invalid_assistant',
				__( 'A valid assistant ID is required to load harness state.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$bundle = self::get_bundle( $bundle_id );

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		// Verify the bundle belongs to this assistant.
		$bundle_assistant = isset( $bundle['assistant_id'] ) ? absint( $bundle['assistant_id'] ) : 0;
		if ( $bundle_assistant !== $assistant_id ) {
			return new \WP_Error(
				'wp_mcp_ai_bootstrap_wrong_assistant',
				__( 'This bootstrap bundle does not belong to the specified assistant.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 403 )
			);
		}

		$restored = array();

		// Restore prompt if present.
		if ( ! empty( $bundle['prompt'] ) ) {
			self::apply_prompt( $assistant_id, $bundle['prompt'] );
			$restored[] = 'prompt';
		}

		// Restore roles if present.
		if ( ! empty( $bundle['roles'] ) && is_array( $bundle['roles'] ) ) {
			self::apply_roles( $assistant_id, $bundle['roles'] );
			$restored[] = 'roles';
		}

		// Restore skills if present.
		if ( ! empty( $bundle['skills'] ) && is_array( $bundle['skills'] ) ) {
			self::apply_skills( $assistant_id, $bundle['skills'] );
			$restored[] = 'skills';
		}

		// Restore memory summary if present.
		if ( ! empty( $bundle['memory_summary'] ) ) {
			self::apply_memory_summary( $assistant_id, $bundle['memory_summary'] );
			$restored[] = 'memory';
		}

		// Increment generation count.
		$new_gen = absint( $bundle['generation_count'] ) + 1;
		self::set_generation_count( $assistant_id, $new_gen );

		$summary = array(
			'bundle_id'        => $bundle_id,
			'from_session'     => isset( $bundle['session_id'] ) ? $bundle['session_id'] : '',
			'created_at'       => isset( $bundle['created_at'] ) ? $bundle['created_at'] : '',
			'restored'         => $restored,
			'generation_count' => $new_gen,
		);

		/**
		 * Fires after a harness bootstrap bundle is loaded.
		 *
		 * @since 1.2.0
		 *
		 * @param string $bundle_id    The loaded bundle identifier.
		 * @param int    $assistant_id The assistant post ID.
		 * @param array  $summary      What was restored.
		 */
		do_action( 'wp_mcp_ai_harness_bootstrap_loaded', $bundle_id, $assistant_id, $summary );

		return $summary;
	}

	/**
	 * List all saved bootstrap bundles for an assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Array of bundle metadata, most recent first.
	 */
	public static function list_bundles( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		if ( $assistant_id <= 0 ) {
			return array();
		}

		$bundle_ids = self::get_index( $assistant_id );
		$bundles    = array();

		foreach ( $bundle_ids as $bundle_id ) {
			$bundle = self::get_bundle( $bundle_id );
			if ( ! is_wp_error( $bundle ) ) {
				// Return metadata only (no full prompt/roles/skills payload).
				$bundles[] = array(
					'bundle_id'        => $bundle['bundle_id'],
					'assistant_id'     => $bundle['assistant_id'],
					'session_id'       => $bundle['session_id'],
					'created_at'       => $bundle['created_at'],
					'generation_count' => isset( $bundle['generation_count'] ) ? absint( $bundle['generation_count'] ) : 0,
					'components'       => array(
						'prompt' => ! empty( $bundle['prompt'] ),
						'roles'  => ! empty( $bundle['roles'] ),
						'skills' => ! empty( $bundle['skills'] ),
						'memory' => ! empty( $bundle['memory_summary'] ),
					),
				);
			}
		}

		return $bundles;
	}

	/**
	 * Delete a saved bootstrap bundle.
	 *
	 * @since 1.2.0
	 *
	 * @param string $bundle_id Bundle identifier to delete.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_bundle( $bundle_id ) {
		if ( empty( $bundle_id ) ) {
			return false;
		}

		$bundle = self::get_bundle( $bundle_id );

		if ( is_wp_error( $bundle ) ) {
			return false;
		}

		$assistant_id = isset( $bundle['assistant_id'] ) ? absint( $bundle['assistant_id'] ) : 0;

		// Remove from index.
		if ( $assistant_id > 0 ) {
			self::remove_from_index( $assistant_id, $bundle_id );
		}

		// Delete the option.
		$option_key = self::OPTION_PREFIX . $bundle_id;
		$deleted    = delete_option( $option_key );

		if ( $deleted ) {
			/**
			 * Fires after a harness bootstrap bundle is deleted.
			 *
			 * @since 1.2.0
			 *
			 * @param string $bundle_id    The deleted bundle identifier.
			 * @param int    $assistant_id The assistant post ID.
			 */
			do_action( 'wp_mcp_ai_harness_bootstrap_deleted', $bundle_id, $assistant_id );
		}

		return $deleted;
	}

	/**
	 * Get the most recent bootstrap bundle for an assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array|null Bundle data or null if none exist.
	 */
	public static function get_latest_bundle( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		if ( $assistant_id <= 0 ) {
			return null;
		}

		$bundle_ids = self::get_index( $assistant_id );

		if ( empty( $bundle_ids ) ) {
			return null;
		}

		// Index is ordered most-recent-first.
		$latest_id = reset( $bundle_ids );
		$bundle    = self::get_bundle( $latest_id );

		if ( is_wp_error( $bundle ) ) {
			return null;
		}

		return $bundle;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a single bundle by ID.
	 *
	 * @since 1.2.0
	 *
	 * @param string $bundle_id Bundle identifier.
	 * @return array|WP_Error Bundle data or error.
	 */
	protected static function get_bundle( $bundle_id ) {
		if ( empty( $bundle_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_bootstrap_missing_id',
				__( 'Bundle ID is required.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$option_key = self::OPTION_PREFIX . $bundle_id;
		$bundle     = get_option( $option_key, null );

		if ( null === $bundle || ! is_array( $bundle ) ) {
			return new \WP_Error(
				'wp_mcp_ai_bootstrap_not_found',
				sprintf(
					/* translators: %s: bundle ID */
					__( 'Bootstrap bundle "%s" not found.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $bundle_id )
				),
				array( 'status' => 404 )
			);
		}

		return $bundle;
	}

	/**
	 * Generate a unique bundle ID.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $session_id   Session identifier.
	 * @return string Unique bundle ID.
	 */
	protected static function generate_bundle_id( $assistant_id, $session_id ) {
		$seed = $assistant_id . '|' . $session_id . '|' . microtime( true ) . '|' . wp_rand( 1000, 9999 );
		return 'bundle_' . wp_hash( $seed );
	}

	/**
	 * Get the per-assistant bundle ID index.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Ordered array of bundle IDs (most recent first).
	 */
	protected static function get_index( $assistant_id ) {
		$index = get_option( self::INDEX_OPTION, array() );

		if ( ! is_array( $index ) ) {
			return array();
		}

		$key = 'assistant_' . $assistant_id;

		return isset( $index[ $key ] ) && is_array( $index[ $key ] )
			? $index[ $key ]
			: array();
	}

	/**
	 * Add a bundle ID to the per-assistant index.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $bundle_id    Bundle identifier to add.
	 */
	protected static function add_to_index( $assistant_id, $bundle_id ) {
		$index = get_option( self::INDEX_OPTION, array() );

		if ( ! is_array( $index ) ) {
			$index = array();
		}

		$key = 'assistant_' . $assistant_id;

		if ( ! isset( $index[ $key ] ) || ! is_array( $index[ $key ] ) ) {
			$index[ $key ] = array();
		}

		// Prepend to keep most recent first.
		array_unshift( $index[ $key ], $bundle_id );

		// Deduplicate.
		$index[ $key ] = array_unique( $index[ $key ] );

		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Remove a bundle ID from the per-assistant index.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $bundle_id    Bundle identifier to remove.
	 */
	protected static function remove_from_index( $assistant_id, $bundle_id ) {
		$index = get_option( self::INDEX_OPTION, array() );

		if ( ! is_array( $index ) ) {
			return;
		}

		$key = 'assistant_' . $assistant_id;

		if ( isset( $index[ $key ] ) && is_array( $index[ $key ] ) ) {
			$index[ $key ] = array_values(
				array_filter(
					$index[ $key ],
					function ( $id ) use ( $bundle_id ) {
						return $id !== $bundle_id;
					}
				)
			);
			update_option( self::INDEX_OPTION, $index, false );
		}
	}

	/**
	 * Prune bundles to the configured maximum per assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 */
	protected static function prune_bundles( $assistant_id ) {
		/**
		 * Filters the maximum number of bootstrap bundles per assistant.
		 *
		 * When exceeded, the oldest bundles are automatically pruned.
		 *
		 * @since 1.2.0
		 *
		 * @param int $max_bundles Maximum bundles to retain. Default 10.
		 * @param int $assistant_id Assistant post ID.
		 */
		$max_bundles = apply_filters( 'wp_mcp_ai_harness_bootstrap_max_bundles', self::DEFAULT_MAX_BUNDLES, $assistant_id );
		$max_bundles = absint( $max_bundles );

		if ( $max_bundles < 1 ) {
			$max_bundles = self::DEFAULT_MAX_BUNDLES;
		}

		$bundle_ids = self::get_index( $assistant_id );

		if ( count( $bundle_ids ) <= $max_bundles ) {
			return;
		}

		// Index is most-recent-first; prune from the end (oldest).
		$to_prune = array_slice( $bundle_ids, $max_bundles );

		foreach ( $to_prune as $bundle_id ) {
			$option_key = self::OPTION_PREFIX . $bundle_id;
			delete_option( $option_key );
		}

		// Update the index to only keep retained bundles.
		$retained = array_slice( $bundle_ids, 0, $max_bundles );

		$index = get_option( self::INDEX_OPTION, array() );

		if ( ! is_array( $index ) ) {
			$index = array();
		}

		$key           = 'assistant_' . $assistant_id;
		$index[ $key ] = $retained;

		update_option( self::INDEX_OPTION, $index, false );

		/**
		 * Fires after old bootstrap bundles are pruned.
		 *
		 * @since 1.2.0
		 *
		 * @param int   $assistant_id Assistant post ID.
		 * @param array $pruned_ids   Bundle IDs that were pruned.
		 */
		do_action( 'wp_mcp_ai_harness_bootstrap_pruned', $assistant_id, $to_prune );
	}

	// -------------------------------------------------------------------------
	// State gatherers — read current harness state from assistant meta
	// -------------------------------------------------------------------------

	/**
	 * Get the current system prompt for an assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return string Current system prompt.
	 */
	protected static function get_current_prompt( $assistant_id ) {
		$prompt = get_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', true );

		if ( empty( $prompt ) ) {
			// Fall back to the assistant post content.
			$post = get_post( $assistant_id );
			if ( $post instanceof \WP_Post ) {
				$prompt = $post->post_content;
			}
		}

		return is_string( $prompt ) ? $prompt : '';
	}

	/**
	 * Get the current role disposition for an assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Current role configuration.
	 */
	protected static function get_current_roles( $assistant_id ) {
		$role_type = get_post_meta( $assistant_id, '_wp_mcp_ai_agent_role', true );

		if ( empty( $role_type ) ) {
			return array();
		}

		if ( function_exists( 'wp_mcp_ai_get_agent_role' ) ) {
			$role = wp_mcp_ai_get_agent_role( sanitize_key( $role_type ) );
		} else {
			// Standalone mode: the base plugin owns the role registry, so
			// resolve against the ported role classes instead.
			$role_map = array(
				'planner'  => AgentRolePlanner::class,
				'executor' => AgentRoleExecutor::class,
				'critic'   => AgentRoleCritic::class,
			);
			$key      = sanitize_key( $role_type );
			$role     = isset( $role_map[ $key ] ) ? new $role_map[ $key ]() : null;
		}

		if ( ! $role ) {
			return array();
		}

		return array(
			'type'         => $role_type,
			'name'         => $role->get_role_name(),
			'capabilities' => $role->get_capabilities(),
		);
	}

	/**
	 * Get the current skill (tool) preferences for an assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Current skill configuration.
	 */
	protected static function get_current_skills( $assistant_id ) {
		$enabled_tools = get_post_meta( $assistant_id, '_wp_mcp_ai_enabled_tools', true );

		if ( ! is_array( $enabled_tools ) ) {
			$enabled_tools = array();
		}

		$tool_weights = get_post_meta( $assistant_id, '_wp_mcp_ai_tool_weights', true );

		if ( ! is_array( $tool_weights ) ) {
			$tool_weights = array();
		}

		return array(
			'enabled_tools' => array_map( 'sanitize_key', $enabled_tools ),
			'tool_weights'  => $tool_weights,
		);
	}

	/**
	 * Get the current memory strategy summary for an assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return string Memory strategy summary.
	 */
	protected static function get_current_memory_summary( $assistant_id ) {
		$profile = get_post_meta( $assistant_id, '_wp_mcp_ai_harness_profile', true );

		if ( ! is_array( $profile ) || ! isset( $profile['memory'] ) ) {
			return '';
		}

		$memory = $profile['memory'];

		$parts = array();

		if ( ! empty( $memory['pii_filter'] ) ) {
			$parts[] = __( 'PII filtering enabled', 'nvoos-content-graph-ai-platform' );
		}

		if ( ! empty( $memory['reflection_enabled'] ) ) {
			$parts[] = __( 'Reflection persistence enabled', 'nvoos-content-graph-ai-platform' );
		}

		if ( ! empty( $memory['retrieval_strategy'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: retrieval strategy identifier */
				__( 'Retrieval: %s', 'nvoos-content-graph-ai-platform' ),
				sanitize_text_field( $memory['retrieval_strategy'] )
			);
		}

		if ( ! empty( $memory['scoping_enabled'] ) ) {
			$parts[] = __( 'Task-class scoping active', 'nvoos-content-graph-ai-platform' );
		}

		return implode( '; ', $parts );
	}

	/**
	 * Get the current generation count for an assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return int Generation count.
	 */
	protected static function get_current_generation_count( $assistant_id ) {
		$count = get_post_meta( $assistant_id, '_wp_mcp_ai_harness_generation_count', true );
		return absint( $count );
	}

	// -------------------------------------------------------------------------
	// State appliers — write harness state to assistant meta
	// -------------------------------------------------------------------------

	/**
	 * Apply an evolved system prompt to an assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $prompt       Evolved system prompt.
	 */
	protected static function apply_prompt( $assistant_id, $prompt ) {
		update_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', wp_kses_post( $prompt ) );
	}

	/**
	 * Apply evolved role dispositions to an assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $roles        Role configuration.
	 */
	protected static function apply_roles( $assistant_id, $roles ) {
		if ( ! empty( $roles['type'] ) ) {
			$role_type = sanitize_key( $roles['type'] );
			if ( function_exists( 'wp_mcp_ai_set_assistant_role' ) ) {
				wp_mcp_ai_set_assistant_role( $assistant_id, $role_type );
				return;
			}

			// Standalone mode: replicate the base function's behaviour —
			// validate against the known ported roles, then write the meta.
			if ( in_array( $role_type, array( 'planner', 'executor', 'critic' ), true ) ) {
				update_post_meta( $assistant_id, '_wp_mcp_ai_agent_role', $role_type );
			}
		}
	}

	/**
	 * Apply evolved skill preferences to an assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $skills       Skill configuration.
	 */
	protected static function apply_skills( $assistant_id, $skills ) {
		if ( isset( $skills['enabled_tools'] ) && is_array( $skills['enabled_tools'] ) ) {
			$enabled = array_map( 'sanitize_key', $skills['enabled_tools'] );
			update_post_meta( $assistant_id, '_wp_mcp_ai_enabled_tools', $enabled );
		}

		if ( isset( $skills['tool_weights'] ) && is_array( $skills['tool_weights'] ) ) {
			update_post_meta( $assistant_id, '_wp_mcp_ai_tool_weights', $skills['tool_weights'] );
		}
	}

	/**
	 * Apply evolved memory strategy to an assistant's harness profile.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $assistant_id   Assistant post ID.
	 * @param string $memory_summary Memory strategy summary string.
	 */
	protected static function apply_memory_summary( $assistant_id, $memory_summary ) {
		// Store as a reference; the actual profile changes are handled
		// by the evolver. This just records the summary for retrieval.
		update_post_meta(
			$assistant_id,
			'_wp_mcp_ai_harness_memory_summary',
			sanitize_text_field( $memory_summary )
		);
	}

	/**
	 * Set the generation count for an assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @param int $count        New generation count.
	 */
	protected static function set_generation_count( $assistant_id, $count ) {
		update_post_meta( $assistant_id, '_wp_mcp_ai_harness_generation_count', absint( $count ) );
	}
}
