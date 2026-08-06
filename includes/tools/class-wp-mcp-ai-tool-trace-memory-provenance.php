<?php
/**
 * Tool: `trace_memory_provenance`.
 *
 * Read-only tool that returns the full origin chain for a single memory
 * record identified by its `context_id`:
 *
 *   - Audit trail (events filtered by context_id from the per-agent audit log)
 *   - Version history (rollback snapshots stored by `memory_audit_trail`)
 *   - Graph neighborhood (BFS over Graphify `RECALLS` edges, gated by
 *     `class_exists( 'NV_oOS_Graphify_Memory_Bridge' )`)
 *
 * Each section is independently include-gated and the tool degrades
 * gracefully when a data source is unavailable — Graphify being absent
 * never causes a failure.
 *
 * Phase 6 of the Memory Layer 2026 Enhancements roadmap. Powers the
 * Phase 7b Memory Drawer "Why does the agent know this?" row and
 * supports GDPR DSAR / debugging workflows.
 *
 * @package WP_MCP_AI
 * @since 1.1.20
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trace the full provenance of a single memory record.
 *
 * @since 1.1.20
 */
class WP_MCP_AI_Tool_Trace_Memory_Provenance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Hard upper bound on graph BFS depth, regardless of caller / filter.
	 */
	const ABSOLUTE_MAX_DEPTH = 20;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'trace_memory_provenance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Trace Memory Provenance', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Return the full origin chain for a single memory record: audit trail, version history, and (when Graphify is active) the graph neighbourhood reachable via RECALLS edges. Read-only; powers the Memory Drawer "Why does the agent know this?" row and GDPR DSAR workflows.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_id'         => array(
					'type'        => 'integer',
					'description' => __( 'Agent assistant ID (post ID) the memory belongs to.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'context_id'       => array(
					'type'        => 'string',
					'description' => __( 'Memory context_id (must match ctx_*).', 'mcp-ai-wpoos' ),
				),
				'max_depth'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum BFS depth for the graph section. Clamped by the wp_mcp_ai_memory_provenance_max_depth filter.', 'mcp-ai-wpoos' ),
					'default'     => 5,
					'minimum'     => 1,
					'maximum'     => self::ABSOLUTE_MAX_DEPTH,
				),
				'include_audit'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include the audit-trail section.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'include_versions' => array(
					'type'        => 'boolean',
					'description' => __( 'Include the version-history section.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'include_graph'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include the Graphify graph-neighbourhood section.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'             => array( 'agent_id', 'context_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			'profession_tags'       => array( 'system_administrator', 'compliance_officer', 'ai_researcher' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data.
			'local-only',           // No external API calls.
			'idempotent',           // Same input = same output.
			'cacheable',            // Results can be cached.
			'requires-capability',  // Needs user authentication.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Canonical success envelope or WP_Error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		unset( $context );

		// ----- Gate 1: sanitise every input at the entry point. -----
		$agent_id   = isset( $arguments['agent_id'] ) ? absint( $arguments['agent_id'] ) : 0;
		$context_id = isset( $arguments['context_id'] ) ? sanitize_text_field( (string) $arguments['context_id'] ) : '';

		if ( $agent_id <= 0 ) {
			return new WP_Error(
				'invalid_agent_id',
				__( 'agent_id is required and must be a positive integer.', 'mcp-ai-wpoos' )
			);
		}

		if ( '' === $context_id || 0 !== strpos( $context_id, 'ctx_' ) ) {
			return new WP_Error(
				'invalid_context_id',
				__( 'context_id is required and must match ctx_*.', 'mcp-ai-wpoos' )
			);
		}

		// Booleans: respect explicit `false`; otherwise fall back to the
		// filterable defaults. `isset()` is the right discriminator here —
		// PHP's `empty()` would conflate "false" with "unset".
		$include_audit_default    = (bool) apply_filters( 'wp_mcp_ai_memory_provenance_include_audit_default', true );
		$include_versions_default = (bool) apply_filters( 'wp_mcp_ai_memory_provenance_include_versions_default', true );
		$include_graph_default    = (bool) apply_filters( 'wp_mcp_ai_memory_provenance_include_graph_default', true );

		$include_audit    = isset( $arguments['include_audit'] ) ? (bool) $arguments['include_audit'] : $include_audit_default;
		$include_versions = isset( $arguments['include_versions'] ) ? (bool) $arguments['include_versions'] : $include_versions_default;
		$include_graph    = isset( $arguments['include_graph'] ) ? (bool) $arguments['include_graph'] : $include_graph_default;

		// Resolve and clamp max_depth. The filter runs against the raw
		// caller-supplied value (or the default 5), then the final clamp
		// guarantees 1..ABSOLUTE_MAX_DEPTH regardless of what the filter
		// returns.
		$raw_max_depth = isset( $arguments['max_depth'] ) ? (int) $arguments['max_depth'] : 5;
		$max_depth     = (int) apply_filters( 'wp_mcp_ai_memory_provenance_max_depth', $raw_max_depth );
		if ( $max_depth < 1 ) {
			$max_depth = 1;
		} elseif ( $max_depth > self::ABSOLUTE_MAX_DEPTH ) {
			$max_depth = self::ABSOLUTE_MAX_DEPTH;
		}

		// ----- Fetch data sources (unconditionally so we can answer
		// "does this memory exist?" even when every section is suppressed). -----
		$audit_events = $this->fetch_audit_events( $agent_id, $context_id );
		$versions     = $this->fetch_versions( $agent_id, $context_id );
		$ctx_record   = $this->fetch_current_context( $agent_id, $context_id );

		$memory_exists = ( null !== $ctx_record ) || ! empty( $audit_events ) || ! empty( $versions );

		if ( ! $memory_exists ) {
			return new WP_Error(
				'memory_not_found',
				__( 'No memory found for that context_id.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		// ----- Build sections, gated by include flags. -----
		if ( $include_audit ) {
			$audit_section = array(
				'available' => true,
				'events'    => $audit_events,
				'total'     => count( $audit_events ),
			);
		} else {
			$audit_section = array(
				'available' => false,
				'reason'    => 'suppressed by caller',
			);
		}

		if ( $include_versions ) {
			$versions_section = array(
				'available' => true,
				'versions'  => $versions,
				'total'     => count( $versions ),
			);
		} else {
			$versions_section = array(
				'available' => false,
				'reason'    => 'suppressed by caller',
			);
		}

		if ( $include_graph ) {
			$graph_section = $this->build_graph_section( $context_id, $max_depth );
		} else {
			$graph_section = array(
				'available' => false,
				'reason'    => 'suppressed by caller',
			);
		}

		$trace = array(
			'audit'    => $audit_section,
			'versions' => $versions_section,
			'graph'    => $graph_section,
		);

		$summary = $this->build_summary( $audit_events, $versions, $ctx_record );

		/**
		 * Filter the provenance summary block before it is returned.
		 *
		 * Useful for plugins that derive additional summary fields
		 * (e.g. retention-status, supersession chain) from richer
		 * back-ends than the canonical transient store.
		 *
		 * @since 1.1.20
		 *
		 * @param array  $summary    Summary block.
		 * @param string $context_id Memory context_id.
		 * @param int    $agent_id   Agent identifier.
		 */
		$summary = apply_filters( 'wp_mcp_ai_memory_provenance_summary', $summary, $context_id, $agent_id );
		if ( ! is_array( $summary ) ) {
			$summary = array();
		}

		/**
		 * Fires once per successful provenance trace.
		 *
		 * @since 1.1.20
		 *
		 * @param string $context_id Memory context_id.
		 * @param int    $agent_id   Agent identifier.
		 * @param array  $summary    Summary block.
		 */
		do_action( 'wp_mcp_ai_memory_provenance_traced', $context_id, $agent_id, $summary );

		return array(
			'success'    => true,
			'message'    => __( 'Provenance trace assembled.', 'mcp-ai-wpoos' ),
			'context_id' => $context_id,
			'agent_id'   => $agent_id,
			'trace'      => $trace,
			'summary'    => $summary,
		);
	}

	/**
	 * Pull all audit-log entries that match the requested context_id.
	 *
	 * Reads the same transient `WP_MCP_AI_Tool_Memory_Audit_Trail` writes —
	 * `mcp_ai_audit_log_<md5(agent_id)>` — and filters in-PHP because the
	 * upstream tool stores one flat list per agent without a context index.
	 *
	 * Returned events are sorted oldest-first so the chronological order
	 * matches how a human reads a forensic trail.
	 *
	 * @param int    $agent_id   Agent identifier.
	 * @param string $context_id Memory context_id.
	 * @return array<int,array<string,mixed>> Audit events, oldest first.
	 */
	private function fetch_audit_events( $agent_id, $context_id ) {
		$key = 'mcp_ai_audit_log_' . md5( (string) $agent_id );
		$log = get_transient( $key );

		if ( ! is_array( $log ) || empty( $log ) ) {
			return array();
		}

		$matched = array();
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( ! isset( $entry['context_id'] ) || (string) $entry['context_id'] !== $context_id ) {
				continue;
			}
			$matched[] = $entry;
		}

		usort(
			$matched,
			static function ( $a, $b ) {
				$ta = isset( $a['timestamp'] ) ? strtotime( (string) $a['timestamp'] ) : 0;
				$tb = isset( $b['timestamp'] ) ? strtotime( (string) $b['timestamp'] ) : 0;
				return $ta - $tb;
			}
		);

		return $matched;
	}

	/**
	 * Pull version history snapshots for a context_id.
	 *
	 * @param int    $agent_id   Agent identifier.
	 * @param string $context_id Memory context_id.
	 * @return array<int,array<string,mixed>> Versions, oldest first.
	 */
	private function fetch_versions( $agent_id, $context_id ) {
		$key     = 'mcp_ai_ctx_history_' . md5( $agent_id . '_' . $context_id );
		$history = get_transient( $key );

		if ( ! is_array( $history ) || empty( $history ) ) {
			return array();
		}

		// History is keyed by version number — ksort gives oldest first
		// without depending on the order the keys were inserted.
		ksort( $history, SORT_NUMERIC );

		return array_values( $history );
	}

	/**
	 * Pull the current (live) context record if it still exists.
	 *
	 * Used to anchor "does the memory exist?" detection when no audit /
	 * version data survives but the underlying transient does.
	 *
	 * Includes expired records so that a memory in the post-TTL grace
	 * window still produces a successful trace.
	 *
	 * @param int    $agent_id   Agent identifier.
	 * @param string $context_id Memory context_id.
	 * @return array<string,mixed>|null Context record or null when absent.
	 */
	private function fetch_current_context( $agent_id, $context_id ) {
		if ( class_exists( 'WP_MCP_AI_Agent_Context_Manager' ) ) {
			$manager  = WP_MCP_AI_Agent_Context_Manager::get_instance();
			$existing = $manager->retrieve_context( $agent_id, $context_id, true );
			if ( is_array( $existing ) ) {
				return $existing;
			}
		}

		// Fallback: direct transient read, mirrors the manager's key shape.
		$key    = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
		$record = get_transient( $key );
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Build the graph-neighbourhood section.
	 *
	 * Performs a depth-bounded BFS from the memory node `memory:<context_id>`
	 * over `RECALLS` edges via the Graphify bridge. When Graphify is not
	 * loaded, returns `available=false` with a descriptive reason instead of
	 * failing — provenance must always succeed on a Base build.
	 *
	 * @param string $context_id Memory context_id.
	 * @param int    $max_depth  Maximum BFS depth (already clamped).
	 * @return array<string,mixed> Graph section payload.
	 */
	private function build_graph_section( $context_id, $max_depth ) {
		if ( ! class_exists( 'NV_oOS_Graphify_Memory_Bridge' ) ) {
			return array(
				'available' => false,
				'reason'    => 'Graphify bridge unavailable',
			);
		}

		// Resolve the memory node prefix. Newer Graphify builds expose a
		// `NODE_PREFIX_MEMORY` constant; older builds hard-coded `'memory:'`.
		$prefix        = defined( 'NV_oOS_Graphify_Memory_Bridge::NODE_PREFIX_MEMORY' )
			? constant( 'NV_oOS_Graphify_Memory_Bridge::NODE_PREFIX_MEMORY' )
			: 'memory:';
		$start_node_id = $prefix . $context_id;

		$nodes    = array();
		$visited  = array( $start_node_id => true );
		$frontier = array( $start_node_id );

		$can_walk_edges = class_exists( 'NV_oOS_Graphify_DB' )
			&& method_exists( 'NV_oOS_Graphify_DB', 'get_neighbor_ids' );

		if ( ! $can_walk_edges ) {
			return array(
				'available' => false,
				'reason'    => 'Graphify DB walker unavailable',
			);
		}

		for ( $depth = 1; $depth <= $max_depth; $depth++ ) {
			if ( empty( $frontier ) ) {
				break;
			}

			$next_frontier = array();
			foreach ( $frontier as $node_id ) {
				$neighbours = NV_oOS_Graphify_DB::get_neighbor_ids( $node_id, 'RECALLS' );
				if ( ! is_array( $neighbours ) ) {
					continue;
				}

				foreach ( $neighbours as $nid ) {
					$nid = (string) $nid;
					if ( '' === $nid || isset( $visited[ $nid ] ) ) {
						continue;
					}
					$visited[ $nid ] = true;
					$next_frontier[] = $nid;

					// Only surface memory nodes — wing / room / agent
					// anchors are noise for a provenance trace.
					if ( 0 === strpos( $nid, $prefix ) ) {
						$nodes[] = array(
							'node_id'    => $nid,
							'context_id' => substr( $nid, strlen( $prefix ) ),
							'depth'      => $depth,
							'edge'       => 'RECALLS',
						);
					}
				}
			}

			$frontier = $next_frontier;
		}

		return array(
			'available' => true,
			'nodes'     => $nodes,
			'depth'     => $max_depth,
		);
	}

	/**
	 * Distil a small headline summary from the raw audit + version data.
	 *
	 * Lives off the audit-log timestamps for first/last seen and uses the
	 * version count for `modification_count`. When neither source has any
	 * data the structure is still well-defined with zeros / empties so
	 * callers can rely on key presence.
	 *
	 * @param array                    $audit_events Audit events (oldest first).
	 * @param array                    $versions     Version snapshots (oldest first).
	 * @param array<string,mixed>|null $ctx_record   Current context record.
	 * @return array<string,mixed>
	 */
	private function build_summary( $audit_events, $versions, $ctx_record ) {
		$first_seen    = '';
		$last_modified = '';

		// Prefer audit log for first_seen (richer event types), version
		// history for last_modified (one entry per mutation).
		if ( ! empty( $audit_events ) ) {
			$first = reset( $audit_events );
			$last  = end( $audit_events );
			if ( is_array( $first ) && isset( $first['timestamp'] ) ) {
				$first_seen = (string) $first['timestamp'];
			}
			if ( is_array( $last ) && isset( $last['timestamp'] ) ) {
				$last_modified = (string) $last['timestamp'];
			}
		}

		if ( ! empty( $versions ) ) {
			$first_v = reset( $versions );
			$last_v  = end( $versions );
			if ( '' === $first_seen && is_array( $first_v ) && isset( $first_v['timestamp'] ) ) {
				$first_seen = (string) $first_v['timestamp'];
			}
			if ( is_array( $last_v ) && isset( $last_v['timestamp'] ) ) {
				$last_modified = (string) $last_v['timestamp'];
			}
		}

		// Final fallback: the live context's stored_at, if present.
		if ( '' === $first_seen && is_array( $ctx_record ) && ! empty( $ctx_record['stored_at'] ) ) {
			$first_seen = (string) $ctx_record['stored_at'];
		}
		if ( '' === $last_modified && is_array( $ctx_record ) && ! empty( $ctx_record['stored_at'] ) ) {
			$last_modified = (string) $ctx_record['stored_at'];
		}

		// Source attribution: walk audit events looking for the earliest
		// entry that names a source ('tool', 'user', etc.). The audit-trail
		// schema stores arbitrary metadata so we extract conservatively.
		$source_types = array();
		$first_source = null;
		foreach ( $audit_events as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$type  = '';
			$value = '';
			if ( isset( $entry['metadata'] ) && is_array( $entry['metadata'] ) ) {
				if ( ! empty( $entry['metadata']['source'] ) ) {
					$type  = is_array( $entry['metadata']['source'] ) && isset( $entry['metadata']['source']['type'] )
						? (string) $entry['metadata']['source']['type']
						: (string) $entry['metadata']['source'];
					$value = is_array( $entry['metadata']['source'] ) && isset( $entry['metadata']['source']['value'] )
						? (string) $entry['metadata']['source']['value']
						: '';
				} elseif ( ! empty( $entry['metadata']['source_type'] ) ) {
					$type  = (string) $entry['metadata']['source_type'];
					$value = isset( $entry['metadata']['source_value'] ) ? (string) $entry['metadata']['source_value'] : '';
				}
			}
			if ( '' === $type && ! empty( $entry['action'] ) ) {
				$type = (string) $entry['action'];
			}
			if ( '' === $type ) {
				continue;
			}
			if ( ! in_array( $type, $source_types, true ) ) {
				$source_types[] = $type;
			}
			if ( null === $first_source ) {
				$first_source = array(
					'type'  => $type,
					'value' => $value,
				);
			}
		}

		if ( null === $first_source && is_array( $ctx_record ) && ! empty( $ctx_record['source'] ) ) {
			$first_source = array(
				'type'  => (string) $ctx_record['source'],
				'value' => '',
			);
			if ( ! in_array( (string) $ctx_record['source'], $source_types, true ) ) {
				$source_types[] = (string) $ctx_record['source'];
			}
		}

		if ( null === $first_source ) {
			$first_source = array(
				'type'  => '',
				'value' => '',
			);
		}

		return array(
			'first_seen'         => $first_seen,
			'last_modified'      => $last_modified,
			'modification_count' => count( $versions ),
			'source_count'       => count( array_unique( $source_types ) ),
			'first_source'       => $first_source,
		);
	}
}
