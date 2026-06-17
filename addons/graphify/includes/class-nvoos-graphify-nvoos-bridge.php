<?php
/**
 * NV oOS Graphify — NV oOS Bridge
 *
 * Connects every NV oOS data source to the Graphify knowledge graph:
 *
 * 1. **CCT label/content resolvers** — hooks the existing
 *    `nvoos_graphify_cct_label_fields` and `nvoos_graphify_cct_content_fields`
 *    filters to point at the correct schema columns for every NV oOS-owned
 *    JetEngine CCT slug. A higher-precedence resolver hook layer
 *    (`nvoos_graphify_cct_resolve_label` / `nvoos_graphify_cct_resolve_content`)
 *    handles types whose content lives inside JSON envelopes (transcripts).
 *
 * 2. **MemPalace wing/room/agent edges** — during batch CCT builds the
 *    `ai_chat_agent_memories` CCT rows carry `wing`, `room`, and `agent_id`
 *    columns. This bridge fires `nvoos_graphify_emit_cct_edges` (action) per
 *    row so the structural extractor can emit the same hierarchy edges the
 *    real-time Memory Bridge emits on `wp_mcp_ai_memory_stored`.
 *
 * 3. **Private NV oOS CPT registry** — hooks
 *    `nvoos_graphify_indexed_post_types` to surface every NV oOS CPT
 *    that is registered with `public=false` / `show_in_rest=false` (approval
 *    queue, audit, workflow runs, etc.) so they appear in the graph.
 *    Per-CPT content meta is exposed via `nvoos_graphify_post_content_resolver`.
 *
 * 4. **Custom $wpdb table registry** — populates the
 *    `nvoos_graphify_external_tables` filter with descriptors for every
 *    NV oOS-owned custom table (slash-command audit, metric events, job queue,
 *    token usage, compliance controls, evidence, audit-trail, risks). Sensitive
 *    tables default to opt-in (`default_include => false`).
 *
 * All new behaviour is **off by default for unknown content** (no behaviour
 * change for existing sites). NV oOS-owned slugs/tables are on by default
 * because those are the user's reported gap.
 *
 * @credit WP_MCP_AI_Tool_Mine_Agent_Memory by NV Digital Solutions (GPLv3)
 *   — transcript JSON-parsing logic reused from
 *     collect_from_transcripts() / build_transcript_items_from_messages().
 *
 * @package NV_oOS_Graphify
 * @since   0.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges NV oOS data sources into the Graphify knowledge graph.
 *
 * @since 0.8.0
 */
class NV_oOS_Graphify_NV_oOS_Bridge {

	// -------------------------------------------------------------------------
	// NV oOS CCT schema map
	// -------------------------------------------------------------------------

	/**
	 * Per-CCT-slug title-like field lists.
	 *
	 * Keys are JetEngine CCT slugs (sanitize_key). Values are ordered arrays
	 * of column names checked left-to-right for a non-empty scalar.
	 *
	 * @since 0.8.0
	 * @var array<string,string[]>
	 */
	const CCT_LABEL_FIELDS = array(
		'ai_chat_agent_memories' => array( 'title', 'context_id' ),
		'ai_chat_transcripts'    => array( 'session_key', '_ID' ),
		'ai_peers'               => array( 'peer_label', 'name', 'peer_id' ),
		'usage_logs'             => array( 'assistant_id', '_ID' ),
		'submissions'            => array( 'form_title', 'submission_id', '_ID' ),
		'model_rate_limits'      => array( 'model_id', '_ID' ),
		'assistants'             => array( 'name', 'assistant_name', '_ID' ),
		'webchat_messages'       => array( 'from_name', '_ID' ),
		'channel_messages'       => array( 'from_name', '_ID' ),
		'channel_contacts'       => array( 'display_name', 'name', 'email', '_ID' ),
		'vitals_log'             => array( 'measurement_type', 'recorded_at', '_ID' ),
		'task_templates'         => array( 'name', 'title', '_ID' ),
		'task_plans'             => array( 'name', 'title', '_ID' ),
		'execution_history'      => array( 'workflow_id', 'status', '_ID' ),
		'autonomous_sessions'    => array( 'session_id', 'goal', '_ID' ),
		'quizzes'                => array( 'title', 'name', '_ID' ),
	);

	/**
	 * Per-CCT-slug content/body field lists.
	 *
	 * @since 0.8.0
	 * @var array<string,string[]>
	 */
	const CCT_CONTENT_FIELDS = array(
		'ai_chat_agent_memories' => array( 'content', 'summary' ),
		'ai_peers'               => array( 'description', 'capabilities', 'system_prompt' ),
		'usage_logs'             => array( 'details', 'summary' ),
		'submissions'            => array( 'content', 'form_data', 'message' ),
		'model_rate_limits'      => array( 'notes', 'description' ),
		'assistants'             => array( 'description', 'system_prompt', 'instructions' ),
		'webchat_messages'       => array( 'body', 'message', 'content', 'text' ),
		'channel_messages'       => array( 'body', 'message', 'content', 'text' ),
		'channel_contacts'       => array( 'notes', 'bio', 'description' ),
		'vitals_log'             => array( 'value', 'notes', 'raw_value' ),
		'task_templates'         => array( 'description', 'steps', 'content' ),
		'task_plans'             => array( 'description', 'goal', 'content' ),
		'execution_history'      => array( 'result_summary', 'output', 'error_message' ),
		'autonomous_sessions'    => array( 'goal', 'summary', 'context' ),
		'quizzes'                => array( 'description', 'questions', 'content' ),
	);

	// -------------------------------------------------------------------------
	// NV oOS CPT registry
	// -------------------------------------------------------------------------

	/**
	 * NV oOS CPT registry.
	 *
	 * Each entry:
	 *   'slug'            => (string) post type slug.
	 *   'default_include' => (bool)   whether to add this type to the Graphify
	 *                                 index by default. Private/sensitive CPTs
	 *                                 that site owners may not want in the graph
	 *                                 are set to false.
	 *   'label'           => (string) human-readable label for the settings UI.
	 *   'content_meta'    => (string) optional post_meta key whose value is used
	 *                                 as the primary content for semantic
	 *                                 extraction (overrides post_content).
	 *
	 * Base-plugin CPTs are listed first; Pro CPTs are appended conditionally
	 * in {@see get_cpt_registry()}.
	 *
	 * @since 0.8.0
	 * @var array[]
	 */
	const BASE_CPT_REGISTRY = array(
		array(
			'slug'            => 'mcp_ai_assistant',
			'default_include' => true,
			'label'           => 'AI Assistants',
			'content_meta'    => '_wp_mcp_ai_system_prompt',
		),
		array(
			'slug'            => 'mcp_ai_team',
			'default_include' => true,
			'label'           => 'AI Teams',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_profession',
			'default_include' => true,
			'label'           => 'Professions',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_workflow',
			'default_include' => true,
			'label'           => 'Workflows',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_workflow_run',
			'default_include' => true,
			'label'           => 'Workflow Runs',
			'content_meta'    => '_wp_mcp_ai_run_summary',
		),
		array(
			'slug'            => 'mcp_ai_trigger',
			'default_include' => true,
			'label'           => 'Workflow Triggers',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_approval',
			'default_include' => false,
			'label'           => 'HITL Approvals',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_audit',
			'default_include' => false,
			'label'           => 'Security Audit Entries',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_training',
			'default_include' => true,
			'label'           => 'Security Training Modules',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_lesson',
			'default_include' => true,
			'label'           => 'Security Lessons',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'ai_peer',
			'default_include' => true,
			'label'           => 'AI Peers',
			'content_meta'    => '_wp_mcp_ai_peer_description',
		),
		array(
			'slug'            => 'mcp_task_plan',
			'default_include' => true,
			'label'           => 'Task Plans',
			'content_meta'    => '',
		),
	);

	/**
	 * Pro-only CPT registry entries.
	 *
	 * Loaded only when the Pro addon is active.
	 *
	 * @since 0.8.0
	 * @var array[]
	 */
	const PRO_CPT_REGISTRY = array(
		array(
			'slug'            => 'mcp_vault_item',
			'default_include' => false,
			'label'           => 'Vault Items',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_vault_folder',
			'default_include' => false,
			'label'           => 'Vault Folders',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_service',
			'default_include' => true,
			'label'           => 'Booking Services',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_staff',
			'default_include' => true,
			'label'           => 'Staff',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_appointment',
			'default_include' => false,
			'label'           => 'Appointments',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_chan_contact',
			'default_include' => true,
			'label'           => 'Channel Contacts',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_company',
			'default_include' => true,
			'label'           => 'Companies',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_project',
			'default_include' => true,
			'label'           => 'Projects',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_event',
			'default_include' => true,
			'label'           => 'Events',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_place',
			'default_include' => true,
			'label'           => 'Places',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_eca',
			'default_include' => true,
			'label'           => 'ECA Programmes',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_student',
			'default_include' => false,
			'label'           => 'Students',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_member',
			'default_include' => false,
			'label'           => 'Members',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_policy',
			'default_include' => true,
			'label'           => 'Policies',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_med_record',
			'default_include' => false,
			'label'           => 'Medical Records',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_checkup',
			'default_include' => false,
			'label'           => 'Checkups',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_prescription',
			'default_include' => false,
			'label'           => 'Prescriptions',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_allergy',
			'default_include' => false,
			'label'           => 'Allergies',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_arch_spec',
			'default_include' => true,
			'label'           => 'Arch. Specifications',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_arch_draw',
			'default_include' => true,
			'label'           => 'Arch. Drawings',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_arch_proj',
			'default_include' => true,
			'label'           => 'Arch. Projects',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_arch_precedent',
			'default_include' => true,
			'label'           => 'Arch. Precedents',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_doc_tpl',
			'default_include' => true,
			'label'           => 'Document Templates',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_image_tpl',
			'default_include' => true,
			'label'           => 'Image Templates',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_media_tpl',
			'default_include' => true,
			'label'           => 'Media Templates',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_media_coll',
			'default_include' => true,
			'label'           => 'Media Collections',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_lf_matter',
			'default_include' => false,
			'label'           => 'Legal Matters',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_lf_client',
			'default_include' => false,
			'label'           => 'Legal Clients',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_lf_document',
			'default_include' => false,
			'label'           => 'Legal Documents',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_lf_time_entry',
			'default_include' => false,
			'label'           => 'Legal Time Entries',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_lf_trust_txn',
			'default_include' => false,
			'label'           => 'Legal Trust TXNs',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_cre_loan',
			'default_include' => false,
			'label'           => 'CRE Loans',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_cre_property',
			'default_include' => false,
			'label'           => 'CRE Properties',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_fin_account',
			'default_include' => false,
			'label'           => 'Financial Accounts',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_reg_product',
			'default_include' => false,
			'label'           => 'Reg. Products',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_registration',
			'default_include' => false,
			'label'           => 'Registrations',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_reg_document',
			'default_include' => false,
			'label'           => 'Reg. Documents',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_reg_country',
			'default_include' => false,
			'label'           => 'Reg. Countries',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_requirement',
			'default_include' => false,
			'label'           => 'Requirements',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_doc_record',
			'default_include' => false,
			'label'           => 'QMS Doc. Records',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_hc_vital_log',
			'default_include' => false,
			'label'           => 'HC Vital Logs',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_imaging_study',
			'default_include' => false,
			'label'           => 'Imaging Studies',
			'content_meta'    => '',
		),
		array(
			'slug'            => 'mcp_ai_area',
			'default_include' => true,
			'label'           => 'PARA Areas',
			'content_meta'    => '',
		),
	);

	// -------------------------------------------------------------------------
	// Initialisation
	// -------------------------------------------------------------------------

	/**
	 * Register all bridge hooks.
	 *
	 * Called from nvoos-graphify.php after the base plugin constant is verified.
	 * Safe to call multiple times (idempotent via has_filter check is not done,
	 * but the plugin entry point only calls this once).
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public static function register() {
		// CCT label/content field lists — per-slug overrides.
		add_filter( 'nvoos_graphify_cct_label_fields', array( __CLASS__, 'filter_cct_label_fields' ), 10, 3 );
		add_filter( 'nvoos_graphify_cct_content_fields', array( __CLASS__, 'filter_cct_content_fields' ), 10, 2 );

		// Higher-precedence resolver callbacks for JSON-envelope types.
		add_filter( 'nvoos_graphify_cct_resolve_label', array( __CLASS__, 'resolve_transcript_label' ), 10, 3 );
		add_filter( 'nvoos_graphify_cct_resolve_content', array( __CLASS__, 'resolve_transcript_content' ), 10, 3 );

		// MemPalace: emit wing/room/agent edges for agent_memories CCT rows.
		add_filter( 'nvoos_graphify_emit_cct_edges', array( __CLASS__, 'emit_memory_palace_edges' ), 10, 4 );

		// Private CPT inclusion.
		add_filter( 'nvoos_graphify_indexed_post_types', array( __CLASS__, 'filter_indexed_post_types' ) );

		// Post content override for meta-backed CPTs (e.g. assistant system prompt).
		add_filter( 'nvoos_graphify_post_content_resolver', array( __CLASS__, 'resolve_post_content' ), 10, 2 );

		// External table registry.
		add_filter( 'nvoos_graphify_external_tables', array( __CLASS__, 'register_external_tables' ) );
	}

	// -------------------------------------------------------------------------
	// CCT label / content field overrides
	// -------------------------------------------------------------------------

	/**
	 * Return slug-appropriate label field list for NV oOS CCT types.
	 *
	 * @since 0.8.0
	 *
	 * @param string[] $fields Default label field names.
	 * @param string   $slug   CCT slug.
	 * @param array    $item   CCT row.
	 * @return string[]
	 */
	public static function filter_cct_label_fields( $fields, $slug, $item ) {
		$slug = sanitize_key( $slug );
		if ( isset( self::CCT_LABEL_FIELDS[ $slug ] ) ) {
			return self::CCT_LABEL_FIELDS[ $slug ];
		}
		return $fields;
	}

	/**
	 * Return slug-appropriate content field list for NV oOS CCT types.
	 *
	 * @since 0.8.0
	 *
	 * @param string[] $fields Default content field names.
	 * @param array    $item   CCT row (associative array).
	 * @return string[]
	 */
	public static function filter_cct_content_fields( $fields, $item ) {
		// The slug is not passed to this filter in the current structural
		// extractor, so we detect it from item columns present in the row.
		$slug = self::detect_cct_slug_from_item( $item );
		if ( '' !== $slug && isset( self::CCT_CONTENT_FIELDS[ $slug ] ) ) {
			return self::CCT_CONTENT_FIELDS[ $slug ];
		}
		return $fields;
	}

	// -------------------------------------------------------------------------
	// Resolver callbacks — JSON-envelope types (transcripts)
	// -------------------------------------------------------------------------

	/**
	 * Synthesize a label for `ai_chat_transcripts` CCT rows.
	 *
	 * Returns non-empty string only when the slug matches; returning an empty
	 * string signals the structural extractor to fall back to its field-list
	 * logic.
	 *
	 * @since 0.8.0
	 *
	 * @param string $label     Current label (may be empty string on first pass).
	 * @param string $slug      CCT slug.
	 * @param array  $item      CCT row.
	 * @return string
	 */
	public static function resolve_transcript_label( $label, $slug, array $item ) {
		if ( 'ai_chat_transcripts' !== sanitize_key( $slug ) ) {
			return $label;
		}

		$parts    = array();
		$asst_id  = ! empty( $item['assistant_id'] ) ? (string) $item['assistant_id'] : '';
		$sess_key = ! empty( $item['session_key'] ) ? (string) $item['session_key'] : '';
		$date     = ! empty( $item['cct_created'] ) ? substr( (string) $item['cct_created'], 0, 10 ) : '';

		if ( '' !== $asst_id ) {
			$parts[] = 'Assistant ' . $asst_id;
		}
		if ( '' !== $sess_key ) {
			// Only show the last 8 chars of the session key to keep the label short.
			$parts[] = '…' . substr( $sess_key, -8 );
		}
		if ( '' !== $date ) {
			$parts[] = $date;
		}
		if ( empty( $parts ) ) {
			$item_id = isset( $item['_ID'] ) ? absint( $item['_ID'] ) : 0;
			/* translators: %d: numeric transcript ID */
			return sprintf( __( 'Transcript #%d', 'nvoos-graphify' ), $item_id );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Extract plain-text content from a `ai_chat_transcripts` CCT row.
	 *
	 * The transcript CCT stores messages as JSON in `request_payload` and
	 * `response_payload` columns. This method decodes those envelopes and
	 * extracts the text of each message, concatenated to a single string.
	 *
	 * The decoding logic is inspired by
	 * WP_MCP_AI_Tool_Mine_Agent_Memory::build_transcript_items_from_messages().
	 *
	 * @since 0.8.0
	 *
	 * @param string $content   Current content (may be empty string on first pass).
	 * @param string $slug      CCT slug.
	 * @param array  $item      CCT row.
	 * @return string
	 */
	public static function resolve_transcript_content( $content, $slug, array $item ) {
		if ( 'ai_chat_transcripts' !== sanitize_key( $slug ) ) {
			return $content;
		}

		$messages = array();

		// request_payload: {"messages": [...]} or raw array of messages.
		if ( ! empty( $item['request_payload'] ) ) {
			$messages = array_merge(
				$messages,
				self::extract_messages_from_json( $item['request_payload'], 'user' )
			);
		}

		// response_payload: may be the completion response or a messages array.
		if ( ! empty( $item['response_payload'] ) ) {
			$messages = array_merge(
				$messages,
				self::extract_messages_from_json( $item['response_payload'], 'assistant' )
			);
		}

		if ( empty( $messages ) ) {
			return $content;
		}

		// Truncate to 800 words for the semantic extractor budget.
		$full_text = implode( "\n", $messages );
		return wp_trim_words( $full_text, 800 );
	}

	/**
	 * Decode a JSON payload column and extract text content from message objects.
	 *
	 * Handles the two common shapes:
	 *   - Direct array of messages: `[{"role":"user","content":"..."}]`
	 *   - OpenAI chat-completion envelope: `{"messages":[...]}`
	 *   - OpenAI completion response: `{"choices":[{"message":{"content":"..."}}]}`
	 *
	 * @since 0.8.0
	 *
	 * @param string $json         Raw JSON column value.
	 * @param string $default_role Role name to use when the message lacks one.
	 * @return string[]
	 */
	private static function extract_messages_from_json( $json, $default_role = '' ) {
		$texts = array();

		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return $texts;
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			// Probably a plain-text column — return as-is.
			if ( is_string( $decoded ) && '' !== $decoded ) {
				$texts[] = $decoded;
			}
			return $texts;
		}

		// OpenAI completion response shape.
		if ( isset( $decoded['choices'] ) && is_array( $decoded['choices'] ) ) {
			foreach ( $decoded['choices'] as $choice ) {
				if ( isset( $choice['message']['content'] ) && is_scalar( $choice['message']['content'] ) ) {
					$texts[] = wp_strip_all_tags( (string) $choice['message']['content'] );
				} elseif ( isset( $choice['text'] ) && is_scalar( $choice['text'] ) ) {
					$texts[] = wp_strip_all_tags( (string) $choice['text'] );
				}
			}
			return $texts;
		}

		// Messages envelope shape.
		$messages_list = null;
		if ( isset( $decoded['messages'] ) && is_array( $decoded['messages'] ) ) {
			$messages_list = $decoded['messages'];
		} elseif ( isset( $decoded[0] ) ) {
			// Direct array of messages.
			$messages_list = $decoded;
		}

		if ( is_array( $messages_list ) ) {
			foreach ( $messages_list as $msg ) {
				if ( ! is_array( $msg ) ) {
					continue;
				}
				$text_parts = array();
				if ( isset( $msg['content'] ) ) {
					if ( is_string( $msg['content'] ) ) {
						$text_parts[] = $msg['content'];
					} elseif ( is_array( $msg['content'] ) ) {
						// Multi-part content (vision/text blocks).
						foreach ( $msg['content'] as $part ) {
							if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
								$text_parts[] = $part['text'];
							}
						}
					}
				}
				if ( ! empty( $text_parts ) ) {
					$role    = isset( $msg['role'] ) ? sanitize_text_field( (string) $msg['role'] ) : $default_role;
					$texts[] = ( '' !== $role ? "[$role] " : '' ) . wp_strip_all_tags( implode( ' ', $text_parts ) );
				}
			}
		}

		return $texts;
	}

	// -------------------------------------------------------------------------
	// MemPalace: wing / room / agent edges from agent_memories CCT rows
	// -------------------------------------------------------------------------

	/**
	 * Emit MemPalace hierarchy edges for `ai_chat_agent_memories` CCT rows.
	 *
	 * Hooked on `nvoos_graphify_emit_cct_edges` (fired by the structural
	 * extractor for every CCT row). Returns edges that should be upserted
	 * alongside the node for this row.
	 *
	 * Mirrors the real-time edge projection in
	 * {@see NV_oOS_Graphify_Memory_Bridge::project_memory()} so that batch
	 * graph builds produce the same wing/room topology as the live write path.
	 *
	 * @since 0.8.0
	 *
	 * @param array  $edges_out Accumulator array to append edges to.
	 * @param string $slug      CCT slug.
	 * @param array  $item      CCT row (associative array).
	 * @param string $node_id   Node ID for this CCT item (e.g. `cct_ai_chat_agent_memories_42`).
	 * @return array Updated edges accumulator.
	 */
	public static function emit_memory_palace_edges( $edges_out, $slug, array $item, $node_id ) {
		if ( 'ai_chat_agent_memories' !== sanitize_key( $slug ) ) {
			return $edges_out;
		}

		$wing     = ! empty( $item['wing'] ) ? sanitize_text_field( (string) $item['wing'] ) : '';
		$room     = ! empty( $item['room'] ) ? sanitize_text_field( (string) $item['room'] ) : '';
		$agent_id = ! empty( $item['agent_id'] ) ? sanitize_text_field( (string) $item['agent_id'] ) : '';

		// Wing edge.
		if ( '' !== $wing ) {
			$wing_node_id = NV_oOS_Graphify_Memory_Bridge::NODE_PREFIX_WING . sanitize_title_with_dashes( $wing );
			$edges_out[]  = array(
				'source_node_id' => $node_id,
				'target_node_id' => $wing_node_id,
				'relation'       => 'MEMBER_OF',
				'confidence'     => 1.0,
				'provenance'     => 'EXTRACTED',
				'properties'     => array( 'wing' => $wing ),
			);

			// Room edge (only meaningful inside a wing).
			if ( '' !== $room ) {
				$room_node_id = NV_oOS_Graphify_Memory_Bridge::NODE_PREFIX_ROOM
					. sanitize_title_with_dashes( $wing ) . ':' . sanitize_title_with_dashes( $room );
				$edges_out[]  = array(
					'source_node_id' => $node_id,
					'target_node_id' => $room_node_id,
					'relation'       => 'MEMBER_OF',
					'confidence'     => 1.0,
					'provenance'     => 'EXTRACTED',
					'properties'     => array(
						'wing' => $wing,
						'room' => $room,
					),
				);
				// Room → Wing containment.
				$edges_out[] = array(
					'source_node_id' => $room_node_id,
					'target_node_id' => $wing_node_id,
					'relation'       => 'CONTAINED_IN',
					'confidence'     => 1.0,
					'provenance'     => 'EXTRACTED',
				);
			}
		}

		// Agent edge.
		if ( '' !== $agent_id ) {
			$agent_node_id = NV_oOS_Graphify_Memory_Bridge::NODE_PREFIX_AGENT . sanitize_title_with_dashes( $agent_id );
			$edges_out[]   = array(
				'source_node_id' => $node_id,
				'target_node_id' => $agent_node_id,
				'relation'       => 'OBSERVED_BY',
				'confidence'     => 1.0,
				'provenance'     => 'EXTRACTED',
				'properties'     => array( 'agent_id' => $agent_id ),
			);
		}

		// memory_tier as property edge label (optional — enrich the node, not a
		// separate target).  We propagate it via a self-referential 'HAS_TIER'
		// edge so the tier shows up in edge queries.
		if ( ! empty( $item['memory_tier'] ) ) {
			$tier_label   = sanitize_text_field( (string) $item['memory_tier'] );
			$tier_node_id = 'tier:' . $tier_label;
			$edges_out[]  = array(
				'source_node_id' => $node_id,
				'target_node_id' => $tier_node_id,
				'relation'       => 'HAS_TIER',
				'confidence'     => 1.0,
				'provenance'     => 'EXTRACTED',
				'properties'     => array( 'tier' => $tier_label ),
			);
		}

		return $edges_out;
	}

	// -------------------------------------------------------------------------
	// Private CPT inclusion
	// -------------------------------------------------------------------------

	/**
	 * Add every NV oOS CPT to the Graphify indexed post-type list.
	 *
	 * Respects per-CPT `default_include` flags and the site owner's opt-out
	 * choices stored in the `nvoos_graphify_settings` option
	 * (`excluded_post_types` key).
	 *
	 * @since 0.8.0
	 *
	 * @param string[] $post_types Current indexed post type slugs.
	 * @return string[]
	 */
	public static function filter_indexed_post_types( $post_types ) {
		$post_types = (array) $post_types;

		// Load the exclusion list from settings so admins can opt specific
		// types out via the "Content Sources" tab.
		$settings          = NV_oOS_Graphify::get_settings();
		$excluded_from_opt = isset( $settings['excluded_post_types'] ) && is_array( $settings['excluded_post_types'] )
			? array_map( 'sanitize_key', $settings['excluded_post_types'] )
			: array();

		foreach ( self::get_cpt_registry() as $entry ) {
			$slug = sanitize_key( $entry['slug'] );
			if ( in_array( $slug, $excluded_from_opt, true ) ) {
				// Admin has explicitly opted this type out.
				continue;
			}
			if ( ! $entry['default_include'] ) {
				// Sensitive / opt-in type: only include if admin has explicitly
				// opted in via the settings.
				$opted_in = isset( $settings['extra_post_types'] ) && is_array( $settings['extra_post_types'] )
					? in_array( $slug, array_map( 'sanitize_key', $settings['extra_post_types'] ), true )
					: false;
				if ( ! $opted_in ) {
					continue;
				}
			}
			if ( ! in_array( $slug, $post_types, true ) ) {
				$post_types[] = $slug;
			}
		}

		return array_values( $post_types );
	}

	/**
	 * Override post content with post_meta for CPTs where the canonical text
	 * lives in meta (e.g. assistant system prompt, workflow run summary).
	 *
	 * Called via the `nvoos_graphify_post_content_resolver` filter in the
	 * structural extractor.
	 *
	 * @since 0.8.0
	 *
	 * @param string  $content Current post content.
	 * @param WP_Post $post    The post.
	 * @return string
	 */
	public static function resolve_post_content( $content, $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return $content;
		}

		foreach ( self::get_cpt_registry() as $entry ) {
			if ( $entry['slug'] !== $post->post_type ) {
				continue;
			}
			if ( empty( $entry['content_meta'] ) ) {
				break;
			}
			$meta_value = get_post_meta( $post->ID, $entry['content_meta'], true );
			if ( ! empty( $meta_value ) && is_string( $meta_value ) ) {
				$content = $meta_value;
			}
			break;
		}

		return $content;
	}

	// -------------------------------------------------------------------------
	// External $wpdb table registry
	// -------------------------------------------------------------------------

	/**
	 * Return the default row limit for each external table.
	 *
	 * @since 0.8.0
	 * @var int
	 */
	const DEFAULT_EXTERNAL_TABLE_LIMIT = 1000;

	/**
	 * Append NV oOS-owned custom $wpdb table descriptors to the external-table
	 * registry consumed by `NV_oOS_Graphify_Detector::detect_external_rows()`.
	 *
	 * Each descriptor array:
	 * ```php
	 * array(
	 *   'table'           => string   // Raw table suffix (no prefix); the
	 *                                 // detector prepends $wpdb->prefix.
	 *   'primary_key'     => string   // Column name of the integer PK.
	 *   'label_field'     => string   // Column whose value becomes node label
	 *                                 // (or empty to use PK).
	 *   'label_callback'  => callable|null  // Alternative to label_field.
	 *   'content_field'   => string   // Column whose value feeds semantic
	 *                                 // extraction (empty = no content).
	 *   'content_callback'=> callable|null  // Alternative to content_field.
	 *   'modified_field'  => string   // DateTime column for incremental builds.
	 *   'default_include' => bool     // Index by default (false = admin opt-in).
	 *   'label'           => string   // Human-readable name for the settings UI.
	 *   'node_type'       => string   // Graphify node type string (e.g. ext_*).
	 *   'foreign_keys'    => array[]  // FK descriptors for cross-node edges.
	 * )
	 * ```
	 *
	 * @since 0.8.0
	 *
	 * @param array[] $tables Existing table descriptors.
	 * @return array[]
	 */
	public static function register_external_tables( $tables ) {
		global $wpdb;

		$settings       = NV_oOS_Graphify::get_settings();
		$enabled_tables = isset( $settings['external_tables'] ) && is_array( $settings['external_tables'] )
			? array_map( 'sanitize_key', $settings['external_tables'] )
			: array();

		$new_tables = array(
			// --- Base-plugin tables (non-sensitive, on by default) ---

			array(
				'table'            => 'mcp_ai_slash_command_audit',
				'primary_key'      => 'id',
				'label_field'      => 'command',
				'label_callback'   => null,
				'content_field'    => 'result',
				'content_callback' => null,
				'modified_field'   => 'timestamp',
				'default_include'  => false,
				'label'            => __( 'Slash Command Audit', 'nvoos-graphify' ),
				'node_type'        => 'ext_slash_cmd_audit',
				'foreign_keys'     => array(),
			),
			array(
				'table'            => 'mcp_ai_metric_events',
				'primary_key'      => 'id',
				'label_field'      => 'event_name',
				'label_callback'   => null,
				'content_field'    => 'context',
				'content_callback' => null,
				'modified_field'   => 'occurred_at',
				'default_include'  => false,
				'label'            => __( 'Metric Events', 'nvoos-graphify' ),
				'node_type'        => 'ext_metric_events',
				'foreign_keys'     => array(),
			),
			array(
				'table'            => 'mcp_ai_hourly_token_usage',
				'primary_key'      => 'id',
				'label_field'      => 'model',
				'label_callback'   => null,
				'content_field'    => '',
				'content_callback' => null,
				'modified_field'   => 'period_start',
				'default_include'  => false,
				'label'            => __( 'Hourly Token Usage', 'nvoos-graphify' ),
				'node_type'        => 'ext_token_usage',
				'foreign_keys'     => array(),
			),
			array(
				'table'            => 'mcp_ai_job_queue',
				'primary_key'      => 'id',
				'label_field'      => 'job_type',
				'label_callback'   => null,
				'content_field'    => 'payload',
				'content_callback' => null,
				'modified_field'   => 'created_at',
				'default_include'  => false,
				'label'            => __( 'Job Queue', 'nvoos-graphify' ),
				'node_type'        => 'ext_job_queue',
				'foreign_keys'     => array(),
			),

			// --- Pro / compliance tables (sensitive, off by default) ---

			array(
				'table'            => 'mcp_ai_controls',
				'primary_key'      => 'id',
				'label_field'      => 'control_name',
				'label_callback'   => null,
				'content_field'    => 'description',
				'content_callback' => null,
				'modified_field'   => 'updated_at',
				'default_include'  => false,
				'label'            => __( 'Compliance Controls', 'nvoos-graphify' ),
				'node_type'        => 'ext_compliance_controls',
				'foreign_keys'     => array(),
			),
			array(
				'table'            => 'mcp_ai_evidence',
				'primary_key'      => 'id',
				'label_field'      => 'title',
				'label_callback'   => null,
				'content_field'    => 'description',
				'content_callback' => null,
				'modified_field'   => 'created_at',
				'default_include'  => false,
				'label'            => __( 'Compliance Evidence', 'nvoos-graphify' ),
				'node_type'        => 'ext_compliance_evidence',
				'foreign_keys'     => array(
					array(
						'local_column' => 'control_id',
						'target_type'  => 'ext_compliance_controls',
						'target_table' => 'mcp_ai_controls',
						'target_pk'    => 'id',
						'relation'     => 'EVIDENCE_FOR',
					),
				),
			),
			array(
				'table'            => 'mcp_ai_risks',
				'primary_key'      => 'id',
				'label_field'      => 'risk_name',
				'label_callback'   => null,
				'content_field'    => 'description',
				'content_callback' => null,
				'modified_field'   => 'updated_at',
				'default_include'  => false,
				'label'            => __( 'Risk Register', 'nvoos-graphify' ),
				'node_type'        => 'ext_risk_register',
				'foreign_keys'     => array(),
			),
			array(
				'table'            => 'mcp_ai_audit_trail',
				'primary_key'      => 'id',
				'label_field'      => 'action',
				'label_callback'   => null,
				'content_field'    => 'details',
				'content_callback' => null,
				'modified_field'   => 'created_at',
				'default_include'  => false,
				'label'            => __( 'Audit Trail', 'nvoos-graphify' ),
				'node_type'        => 'ext_audit_trail',
				'foreign_keys'     => array(),
			),
			array(
				'table'            => 'mcp_ai_compliance_checks',
				'primary_key'      => 'id',
				'label_field'      => 'check_name',
				'label_callback'   => null,
				'content_field'    => 'result_details',
				'content_callback' => null,
				'modified_field'   => 'checked_at',
				'default_include'  => false,
				'label'            => __( 'Compliance Checks', 'nvoos-graphify' ),
				'node_type'        => 'ext_compliance_checks',
				'foreign_keys'     => array(
					array(
						'local_column' => 'control_id',
						'target_type'  => 'ext_compliance_controls',
						'target_table' => 'mcp_ai_controls',
						'target_pk'    => 'id',
						'relation'     => 'CHECKS',
					),
				),
			),
			array(
				'table'            => 'mcp_ai_custom_metrics',
				'primary_key'      => 'id',
				'label_field'      => 'metric_name',
				'label_callback'   => null,
				'content_field'    => 'description',
				'content_callback' => null,
				'modified_field'   => 'created_at',
				'default_include'  => false,
				'label'            => __( 'Custom Metrics', 'nvoos-graphify' ),
				'node_type'        => 'ext_custom_metrics',
				'foreign_keys'     => array(),
			),
			array(
				'table'            => 'mcp_ai_events',
				'primary_key'      => 'id',
				'label_field'      => 'event_type',
				'label_callback'   => null,
				'content_field'    => 'payload',
				'content_callback' => null,
				'modified_field'   => 'created_at',
				'default_include'  => false,
				'label'            => __( 'NV oOS Events', 'nvoos-graphify' ),
				'node_type'        => 'ext_nvoos_events',
				'foreign_keys'     => array(),
			),
		);

		foreach ( $new_tables as $descriptor ) {
			$table_key = sanitize_key( $descriptor['table'] );

			// Check admin opt-in/out via settings.
			if ( ! $descriptor['default_include'] ) {
				if ( ! in_array( $table_key, $enabled_tables, true ) ) {
					// Not yet opted in — skip.
					continue;
				}
			} else {
				// Default-on; check if admin has opted out.
				$disabled = isset( $settings['disabled_external_tables'] )
					&& is_array( $settings['disabled_external_tables'] )
					&& in_array( $table_key, array_map( 'sanitize_key', $settings['disabled_external_tables'] ), true );
				if ( $disabled ) {
					continue;
				}
			}

			$tables[] = $descriptor;
		}

		return $tables;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the full CPT registry (base + pro when active).
	 *
	 * @since 0.8.0
	 *
	 * @return array[]
	 */
	public static function get_cpt_registry() {
		$registry = self::BASE_CPT_REGISTRY;
		if ( function_exists( 'wp_mcp_ai_is_pro_addon_available' ) && wp_mcp_ai_is_pro_addon_available() ) {
			$registry = array_merge( $registry, self::PRO_CPT_REGISTRY );
		}
		return $registry;
	}

	/**
	 * Guess the CCT slug from the column footprint of a CCT item row.
	 *
	 * Used as a fallback in the `nvoos_graphify_cct_content_fields` filter
	 * which does not receive the slug parameter in the current structural
	 * extractor implementation.
	 *
	 * @since 0.8.0
	 *
	 * @param array $item CCT row (associative array).
	 * @return string Slug or empty string when undetected.
	 */
	private static function detect_cct_slug_from_item( array $item ) {
		if ( array_key_exists( 'session_key', $item ) && array_key_exists( 'request_payload', $item ) ) {
			return 'ai_chat_transcripts';
		}
		if ( array_key_exists( 'context_id', $item ) && array_key_exists( 'memory_tier', $item ) ) {
			return 'ai_chat_agent_memories';
		}
		if ( array_key_exists( 'from_name', $item ) && array_key_exists( 'channel_id', $item ) ) {
			return 'channel_messages';
		}
		if ( array_key_exists( 'from_name', $item ) ) {
			return 'webchat_messages';
		}
		if ( array_key_exists( 'measurement_type', $item ) && array_key_exists( 'recorded_at', $item ) ) {
			return 'vitals_log';
		}
		return '';
	}
}
