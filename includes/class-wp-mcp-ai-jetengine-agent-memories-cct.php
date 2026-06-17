<?php
/**
 * JetEngine Custom Content Type registration for durable agent memories.
 *
 * Phase 4b-1: provides the persistent backing store for agent memory that
 * complements the existing transient-based fast path in
 * `WP_MCP_AI_Agent_Context_Manager`. Transients remain the primary read path;
 * this CCT exists so that agent memory survives object-cache evictions
 * (`wp cache flush`, Redis restart, host eviction), is visible to the
 * JetEngine UI, queryable through standard JetEngine REST endpoints, and
 * exportable through ordinary WordPress tooling.
 *
 * Schema is aligned with industry-standard agent-memory architectures:
 *   - Letta / MemGPT: memory_tier (working/episodic/semantic/procedural),
 *     verbatim immutability flag, expires_at TTL anchor.
 *   - Zep: bi-temporal validity (valid_from / valid_until / transaction_time)
 *     and explicit provenance via `source`.
 *   - mem0: importance (relevance), verbatim discipline, source tracking.
 *   - Cognee: hierarchical scope via wing/room (already present in Phase 4a).
 *   - MemPalace (https://github.com/MemPalace/mempalace): wings/rooms naming
 *     and verbatim-storage discipline applied throughout this CCT schema.
 *
 * Vector and graph references (`embedding_id`, `graph_node_id`) are nullable
 * forward-compatibility hooks for the deferred Phase 4c work.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure the AI agent memories CCT exists and expose helper accessors.
 */
class WP_MCP_AI_JetEngine_Agent_Memories_CCT {
	const SLUG = 'ai_agent_memories';

	/**
	 * Base ID for meta field identifiers. The 30000 range avoids collisions
	 * with other CCT field IDs (transcripts use 10000, assistants 20000,
	 * peers 40000+, submissions 50000+).
	 */
	const FIELD_ID_BASE = 30000;

	/**
	 * Hook into JetEngine to provision the agent memories content type.
	 *
	 * Registration runs at `init` priority **11** so it lands *after*
	 * JetEngine's CCT manager has hydrated its internal table cache during
	 * priorities 1–10 (see PR #4816). Registering at priority 0 races
	 * JetEngine's bootstrap and leaves `get_item_handler()` returning null
	 * for the rest of the request — which silently empties the
	 * `ai_chat_agent_memories` CCT.
	 *
	 * `maybe_enable_data_stores` stays at priority 0 because the data-stores
	 * module must be activated *before* JetEngine's own bootstrap so the
	 * CCT module loads at all.
	 */
	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 11 );
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 0 );
	}

	/**
	 * Retrieve the agent memories CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Retrieve the JetEngine item handler for the agent memories content type.
	 *
	 * Mirrors the lazy-load behaviour from `WP_MCP_AI_JetEngine_CCT::get_item_handler()`
	 * so that callers running before `init` priority 0 (or in environments
	 * where the manager hasn't loaded content types yet) still receive a
	 * usable handler.
	 *
	 * @return object|null
	 */
	public static function get_item_handler() {
		$module = self::get_cct_module();

		if ( ! $module || empty( $module->manager ) ) {
			// CCT module didn't load. Most likely the data-stores module
			// hasn't been activated yet — try once more so a late call
			// (e.g. on shutdown after a transcript-mining tick) can still
			// recover and write a row.
			self::maybe_enable_data_stores();
			$module = self::get_cct_module();
			if ( ! $module || empty( $module->manager ) ) {
				return null;
			}
		}

		// Ensure CCT is registered before retrieving its handler.
		if ( ! self::cct_exists( $module ) ) {
			self::maybe_register_cct();
		}

		$instance = $module->manager->get_content_types( self::SLUG );

		if ( ! $instance ) {
			// Force a reload of post types in case the manager cache is stale.
			if ( ! empty( $module->manager->data ) && ! empty( $module->manager->data->db ) && method_exists( $module->manager->data->db, 'query_raw' ) ) {
				try {
					$module->manager->data->db->query_raw( 'post_types' );
				} catch ( Exception $e ) {
					// Non-fatal — handler will simply be null below.
					unset( $e );
				}
			}
			$instance = $module->manager->get_content_types( self::SLUG );
		}

		if ( ! $instance ) {
			return null;
		}

		return $instance->get_item_handler();
	}

	/**
	 * Automatically enable the JetEngine data stores module if it's not already active.
	 */
	public static function maybe_enable_data_stores() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return;
		}

		if ( $engine->modules->is_module_active( 'data-stores' ) ) {
			return;
		}

		if ( ! method_exists( $engine->modules, 'get_module' ) ) {
			return;
		}

		$module = $engine->modules->get_module( 'data-stores' );

		if ( ! $module ) {
			return;
		}

		if ( method_exists( $engine->modules, 'activate_module' ) ) {
			$engine->modules->activate_module( 'data-stores' );
		}
	}

	/**
	 * Register the agent memories CCT if it is missing.
	 */
	public static function maybe_register_cct() {
		$module = self::get_cct_module();

		if ( ! $module || empty( $module->manager ) || empty( $module->manager->data ) ) {
			return;
		}

		if ( self::cct_exists( $module ) ) {
			return;
		}

		$data    = $module->manager->data;
		$request = self::get_registration_request();

		$data->set_request( $request );

		if ( method_exists( $data, 'sanitize_item_request' ) && ! $data->sanitize_item_request() ) {
			return;
		}

		$item = $data->sanitize_item_from_request();

		if ( empty( $item ) || ! is_array( $item ) ) {
			return;
		}

		$data->before_item_update( $item, true );

		$item_id = $data->update_item_in_db( $item );

		if ( ! $item_id ) {
			return;
		}

		$item['id'] = $item_id;

		$data->after_item_update( $item, true );

		if ( ! empty( $data->db ) && method_exists( $data->db, 'query_raw' ) ) {
			$data->db->query_raw( 'post_types' );
		}
	}

	/**
	 * Determine whether the agent memories CCT already exists.
	 *
	 * @param \Jet_Engine\Modules\Custom_Content_Types\Module $module Module instance.
	 * @return bool
	 */
	protected static function cct_exists( $module ) {
		$data = $module->manager->data;

		if ( empty( $data->db ) ) {
			return false;
		}

		$records = $data->db->query(
			'post_types',
			array(
				'slug'   => self::SLUG,
				'status' => 'content-type',
			),
			null,
			false
		);

		return ! empty( $records );
	}

	/**
	 * Retrieve the JetEngine Custom Content Types module instance.
	 *
	 * @return \Jet_Engine\Modules\Custom_Content_Types\Module|null
	 */
	protected static function get_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return null;
		}

		if ( ! $engine->modules->is_module_active( 'custom-content-types' ) ) {
			return null;
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );

		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			return null;
		}

		return $module_wrapper->instance;
	}

	/**
	 * Build the request payload used to register the content type.
	 *
	 * @return array
	 */
	protected static function get_registration_request() {
		$label = __( 'AI Agent Memories', 'mcp-ai-wpoos' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_meta_fields(),
		);
	}

	/**
	 * Assemble the JetEngine arguments for the agent memories CCT.
	 *
	 * REST write endpoints are intentionally disabled — agent memories are
	 * created exclusively through the `store_agent_context` /
	 * `mine_agent_memory` tools so the verbatim discipline, importance
	 * normalisation, and event hooks (`wp_mcp_ai_memory_pre_store_transform`,
	 * `wp_mcp_ai_memory_stored`) cannot be bypassed by direct API calls.
	 *
	 * @param string $label Human-readable label for the content type.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-database-view',
			'capability'          => 'manage_options',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => false,
			'rest_post_enabled'   => false,
			'rest_delete_enabled' => false,
			'rest_get_access'     => 'manage_options',
			'rest_put_access'     => 'manage_options',
			'rest_post_access'    => 'manage_options',
			'rest_delete_access'  => 'manage_options',
			'admin_columns'       => array(
				'_ID'          => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'context_id'   => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'agent_id'     => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'memory_tier'  => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'context_type' => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'wing'         => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'importance'   => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'expires_at'   => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'cct_created'  => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Define the agent memories meta field configuration.
	 *
	 * @return array
	 */
	protected static function get_meta_fields() {
		$base_id = self::FIELD_ID_BASE;

		$fields = array(
			self::build_field(
				++$base_id,
				'context_id',
				__( 'Context ID', 'mcp-ai-wpoos' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Stable memory identifier (e.g. ctx_*). Maps to mem0 memory_id / Letta block_id.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'agent_id',
				__( 'Agent ID', 'mcp-ai-wpoos' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Owning agent identifier (post ID or virtual slug).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'memory_tier',
				__( 'Memory Tier', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'working | episodic | semantic | procedural (Letta/Cognee tiering).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'context_type',
				__( 'Context Type', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Sanitized type slug (fact, learning, decision, etc.).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'wing',
				__( 'Wing', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Hierarchical scope (Phase 4a MemPalace wing).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'room',
				__( 'Room', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Sub-scope within a wing (Phase 4a MemPalace room).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'title',
				__( 'Title', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Human-readable title for this memory.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'content',
				__( 'Content', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'Memory content body (post-transform unless verbatim).', 'mcp-ai-wpoos' ),
					'rows'        => 8,
				)
			),
			self::build_field(
				++$base_id,
				'tags',
				__( 'Tags', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'JSON-encoded array of tag strings.', 'mcp-ai-wpoos' ),
					'rows'        => 2,
				)
			),
			self::build_field(
				++$base_id,
				'importance',
				__( 'Importance', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'low | medium | high | critical (mem0 relevance).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'verbatim',
				__( 'Verbatim', 'mcp-ai-wpoos' ),
				'number',
				array(
					'min'         => 0,
					'max'         => 1,
					'step'        => 1,
					'description' => __( 'Immutability flag — 1 forbids any pre-store transformation (mem0 verbatim).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'transaction_time',
				__( 'Transaction Time', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'When the memory was recorded (Zep transaction time).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'valid_from',
				__( 'Valid From', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'Bi-temporal validity start (Zep valid time).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'valid_until',
				__( 'Valid Until', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'Bi-temporal validity end (Zep valid time, may equal expires_at).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'expires_at',
				__( 'Expires At', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'TTL anchor matching the transient expiry (Letta block TTL).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'ttl_seconds',
				__( 'TTL (seconds)', 'mcp-ai-wpoos' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 1,
					'description' => __( 'Originally requested time-to-live in seconds.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'source',
				__( 'Source', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Provenance — tool slug, user ID, or session origin (mem0/Letta).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'source_post_id',
				__( 'Source Post ID', 'mcp-ai-wpoos' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 1,
					'description' => __( 'WordPress post ID when ingested from a post (0 otherwise).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'source_url',
				__( 'Source URL', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Source URL when ingested from the web (empty otherwise).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'source_type',
				__( 'Source Type', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'vector_store | post | url | "" — content_source classification.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'embedding_id',
				__( 'Embedding ID', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Optional FK to a vector store row (mem0 vector ref). Reserved for Phase 4c.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'graph_node_id',
				__( 'Graph Node ID', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Optional FK to a Graphify node (Zep/Cognee graph ref). Reserved for Phase 4c.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'metadata',
				__( 'Metadata', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'JSON-encoded auxiliary metadata (audit trail, transform notes, etc.).', 'mcp-ai-wpoos' ),
					'rows'        => 4,
				)
			),
			// MemPalace Capture Framework Phase A — privacy / consent envelope.
			self::build_field(
				++$base_id,
				'sensitivity',
				__( 'Sensitivity', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Sensitivity classification (e.g. public | internal | confidential | phi | pii | privileged). Drives redaction + retention ceilings.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'consent_basis',
				__( 'Consent Basis', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Lawful basis for storage (e.g. consent | contract | legitimate-interest | legal-obligation | vital-interest | public-task). GDPR Art. 6 mapping.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'subject_refs',
				__( 'Subject Refs', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'JSON-encoded list of data-subject references (member IDs, account IDs, matter IDs) the memory is about. Used for per-subject right-to-be-forgotten.', 'mcp-ai-wpoos' ),
					'rows'        => 2,
				)
			),
			self::build_field(
				++$base_id,
				'attachments',
				__( 'Attachments', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'JSON-encoded list of attachment refs (attachment_id, sha256, mime). Stored alongside the memory record without duplicating binary content.', 'mcp-ai-wpoos' ),
					'rows'        => 3,
				)
			),
			// Memory Layer 2026 Enhancements Phase 2 — schema v2 fields.
			// These power Phases 3 (auto-capture dedup), 5 (decay + contradiction),
			// and 7 (Memory Health diagnostics). Each field is forward-compatible:
			// existing rows without these fields read as the documented defaults.
			self::build_field(
				++$base_id,
				'content_hash',
				__( 'Content Hash', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'SHA-256 of normalised content used for auto-capture dedup. Empty for pre-v2 rows (recomputed lazily on first read).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'confidence_score',
				__( 'Confidence Score', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Decay-aware retrieval signal in [0.0, 1.0]. Decays on an Ebbinghaus curve; strengthens on retrieval access. Empty / unset defaults to 1.0.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'last_accessed_at',
				__( 'Last Accessed At', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'Most recent retrieval timestamp. Empty / unset falls back to transaction_time for legacy rows.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'superseded_by',
				__( 'Superseded By', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'context_id of the record that supersedes this one (contradiction resolution chain). Empty when not superseded.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				++$base_id,
				'auto_captured',
				__( 'Auto Captured', 'mcp-ai-wpoos' ),
				'number',
				array(
					'min'         => 0,
					'max'         => 1,
					'step'        => 1,
					'description' => __( '1 when the record was written by the Phase 3 auto-capture service; 0 (or unset) for explicit writes.', 'mcp-ai-wpoos' ),
				)
			),
		);

		foreach ( $fields as &$field ) {
			$field['show_in_rest'] = true;
		}

		return $fields;
	}

	/**
	 * Utility to construct a JetEngine meta field definition.
	 *
	 * @param int    $id        Deterministic field identifier.
	 * @param string $name      Field slug.
	 * @param string $label     Field label.
	 * @param string $type      JetEngine field type.
	 * @param array  $overrides Optional overrides for the base configuration.
	 * @return array
	 */
	protected static function build_field( $id, $name, $label, $type, $overrides = array() ) {
		$field = array(
			'id'          => absint( $id ),
			'name'        => sanitize_key( $name ),
			'title'       => $label,
			'object_type' => 'field',
			'type'        => $type,
			'width'       => '100%',
			'isNested'    => false,
			'options'     => array(),
		);

		return array_merge( $field, $overrides );
	}
}

WP_MCP_AI_JetEngine_Agent_Memories_CCT::bootstrap();
