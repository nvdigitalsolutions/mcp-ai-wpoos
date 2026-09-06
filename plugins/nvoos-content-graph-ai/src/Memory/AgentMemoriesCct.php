<?php
/**
 * JetEngine Agent Memories CCT registration for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `WP_MCP_AI_JetEngine_Agent_Memories_CCT` (behaviour-preserving; base
 * copies retained permanently — ecosystem port plan D-NOBASE). The CCT
 * slug (`ai_agent_memories`), field-ID base (30000), field set, and
 * JetEngine registration request keep their base names and semantics so
 * monolith and standalone installs produce the same durable schema.
 *
 * Decoupling (documented, additive):
 * - `bootstrap()` is called standalone-only by `Plugin.php` — the base
 *   plugin registers the same CCT on `init` in monolith installs and a
 *   second registration would race JetEngine's CCT manager.
 * - The base class bootstraps itself at file load; CG-AI follows the
 *   plugin's explicit `Plugin::register()` composition instead.
 *
 * @package NvoosContentGraphAi\Memory
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Memory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and provisions the `ai_agent_memories` JetEngine CCT.
 *
 * @since 1.1.0
 */
class AgentMemoriesCct {

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
	 * Registration runs at `init` priority 11 (after JetEngine's CCT
	 * manager hydrates its table cache in priorities 1–10) while
	 * `maybe_enable_data_stores` stays at priority 0 (byte-identical to
	 * the base).
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
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
	 * @return object|null
	 */
	public static function get_item_handler() {
		$module = self::get_cct_module();

		if ( ! $module || empty( $module->manager ) ) {
			// CCT module didn't load — try once more so a late call can
			// still recover and write a row.
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
				} catch ( \Exception $e ) {
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
	 *
	 * @return void
	 */
	public static function maybe_enable_data_stores(): void {
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
	 *
	 * @return void
	 */
	public static function maybe_register_cct(): void {
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
		$label = __( 'AI Agent Memories', 'nvoos-content-graph-ai' );

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
	 * REST write endpoints are intentionally disabled (byte-identical to
	 * the base) — agent memories are created exclusively through the
	 * memory pipeline so the verbatim discipline and event hooks cannot
	 * be bypassed by direct API calls.
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
				__( 'Context ID', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Stable memory identifier (e.g. ctx_*). Maps to mem0 memory_id / Letta block_id.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'agent_id',
				__( 'Agent ID', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Owning agent identifier (post ID or virtual slug).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'memory_tier',
				__( 'Memory Tier', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'working | episodic | semantic | procedural (Letta/Cognee tiering).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'context_type',
				__( 'Context Type', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'Sanitized type slug (fact, learning, decision, etc.).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'wing',
				__( 'Wing', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'Hierarchical scope (Phase 4a MemPalace wing).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'room',
				__( 'Room', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'Sub-scope within a wing (Phase 4a MemPalace room).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'title',
				__( 'Title', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'Human-readable title for this memory.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'content',
				__( 'Content', 'nvoos-content-graph-ai' ),
				'textarea',
				array(
					'description' => __( 'Memory content body (post-transform unless verbatim).', 'nvoos-content-graph-ai' ),
					'rows'        => 8,
				)
			),
			self::build_field(
				++$base_id,
				'tags',
				__( 'Tags', 'nvoos-content-graph-ai' ),
				'textarea',
				array(
					'description' => __( 'JSON-encoded array of tag strings.', 'nvoos-content-graph-ai' ),
					'rows'        => 2,
				)
			),
			self::build_field(
				++$base_id,
				'importance',
				__( 'Importance', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'low | medium | high | critical (mem0 relevance).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'verbatim',
				__( 'Verbatim', 'nvoos-content-graph-ai' ),
				'number',
				array(
					'min'         => 0,
					'max'         => 1,
					'step'        => 1,
					'description' => __( 'Immutability flag — 1 forbids any pre-store transformation (mem0 verbatim).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'transaction_time',
				__( 'Transaction Time', 'nvoos-content-graph-ai' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'When the memory was recorded (Zep transaction time).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'valid_from',
				__( 'Valid From', 'nvoos-content-graph-ai' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'Bi-temporal validity start (Zep valid time).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'valid_until',
				__( 'Valid Until', 'nvoos-content-graph-ai' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'Bi-temporal validity end (Zep valid time, may equal expires_at).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'expires_at',
				__( 'Expires At', 'nvoos-content-graph-ai' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'TTL anchor matching the transient expiry (Letta block TTL).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'ttl_seconds',
				__( 'TTL (seconds)', 'nvoos-content-graph-ai' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 1,
					'description' => __( 'Originally requested time-to-live in seconds.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'source',
				__( 'Source', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'Provenance — tool slug, user ID, or session origin (mem0/Letta).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'source_post_id',
				__( 'Source Post ID', 'nvoos-content-graph-ai' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 1,
					'description' => __( 'WordPress post ID when ingested from a post (0 otherwise).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'source_url',
				__( 'Source URL', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'Source URL when ingested from the web (empty otherwise).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'source_type',
				__( 'Source Type', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'vector_store | post | url | "" — content_source classification.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'embedding_id',
				__( 'Embedding ID', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'Optional FK to a vector store row (mem0 vector ref). Reserved for Phase 4c.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'graph_node_id',
				__( 'Graph Node ID', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'Optional FK to a Graphify node (Zep/Cognee graph ref). Reserved for Phase 4c.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'metadata',
				__( 'Metadata', 'nvoos-content-graph-ai' ),
				'textarea',
				array(
					'description' => __( 'JSON-encoded auxiliary metadata (audit trail, transform notes, etc.).', 'nvoos-content-graph-ai' ),
					'rows'        => 4,
				)
			),
			// MemPalace Capture Framework Phase A — privacy / consent envelope.
			self::build_field(
				++$base_id,
				'sensitivity',
				__( 'Sensitivity', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'Sensitivity classification (e.g. public | internal | confidential | phi | pii | privileged). Drives redaction + retention ceilings.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'consent_basis',
				__( 'Consent Basis', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'Lawful basis for storage (e.g. consent | contract | legitimate-interest | legal-obligation | vital-interest | public-task). GDPR Art. 6 mapping.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'subject_refs',
				__( 'Subject Refs', 'nvoos-content-graph-ai' ),
				'textarea',
				array(
					'description' => __( 'JSON-encoded list of data-subject references (member IDs, account IDs, matter IDs) the memory is about. Used for per-subject right-to-be-forgotten.', 'nvoos-content-graph-ai' ),
					'rows'        => 2,
				)
			),
			self::build_field(
				++$base_id,
				'attachments',
				__( 'Attachments', 'nvoos-content-graph-ai' ),
				'textarea',
				array(
					'description' => __( 'JSON-encoded list of attachment refs (attachment_id, sha256, mime). Stored alongside the memory record without duplicating binary content.', 'nvoos-content-graph-ai' ),
					'rows'        => 3,
				)
			),
			// Memory Layer 2026 Enhancements Phase 2 — schema v2 fields.
			self::build_field(
				++$base_id,
				'content_hash',
				__( 'Content Hash', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'SHA-256 of normalised content used for auto-capture dedup. Empty for pre-v2 rows (recomputed lazily on first read).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'confidence_score',
				__( 'Confidence Score', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'Decay-aware retrieval signal in [0.0, 1.0]. Decays on an Ebbinghaus curve; strengthens on retrieval access. Empty / unset defaults to 1.0.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'last_accessed_at',
				__( 'Last Accessed At', 'nvoos-content-graph-ai' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'Most recent retrieval timestamp. Empty / unset falls back to transaction_time for legacy rows.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'superseded_by',
				__( 'Superseded By', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'context_id of the record that supersedes this one (contradiction resolution chain). Empty when not superseded.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'auto_captured',
				__( 'Auto Captured', 'nvoos-content-graph-ai' ),
				'number',
				array(
					'min'         => 0,
					'max'         => 1,
					'step'        => 1,
					'description' => __( '1 when the record was written by the Phase 3 auto-capture service; 0 (or unset) for explicit writes.', 'nvoos-content-graph-ai' ),
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
