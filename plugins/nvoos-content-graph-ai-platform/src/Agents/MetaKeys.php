<?php
/**
 * Agent meta key constants — canonical reference for agent post meta.
 *
 * These constants mirror the base plugin's `WP_MCP_AI_Assistant_CPT::META_*`
 * constants so that Platform code can reference them without importing
 * the full CPT class. At runtime, they delegate to the base plugin's
 * constants when available.
 *
 * Extracted from `includes/assistants/class-wp-mcp-ai-assistant-cpt.php`.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Agents
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

/**
 * Canonical meta key constants for agent post type.
 *
 * Always use these constants instead of bare string literals.
 * They delegate to the base plugin's `WP_MCP_AI_Assistant_CPT::META_*`
 * when available, ensuring consistency.
 */
final class MetaKeys {

	// ─── Core configuration ────────────────────────────────────
	public const TOOLS               = '_wp_mcp_ai_tools';
	public const PROVIDER            = '_wp_mcp_ai_provider';
	public const MODEL               = '_wp_mcp_ai_model';
	public const TEMPERATURE         = '_wp_mcp_ai_temperature';
	public const SYSTEM_PROMPT       = '_wp_mcp_ai_system_prompt';
	public const REQUIRED_CAPABILITY = 'mcp_ai_required_capability';

	// ─── Agent identity ────────────────────────────────────────
	public const PRIMARY_ROLES      = '_wp_mcp_ai_primary_roles';
	public const SKILLS             = '_wp_mcp_ai_skills';
	public const SKILLS_PROGRESSIVE = '_wp_mcp_ai_skills_progressive';
	public const PREFERRED_DATASETS = '_wp_mcp_ai_preferred_datasets';

	// ─── Knowledge & memory ────────────────────────────────────
	public const MEMORY_FILES    = '_wp_mcp_ai_memory_files';
	public const VECTOR_STORE_ID = '_wp_mcp_ai_vector_store_id';
	public const CORPUS_NAME     = '_wp_mcp_ai_corpus_name';

	// ─── Tool shortcuts ────────────────────────────────────────
	public const TOOL_SHORTCUTS          = '_wp_mcp_ai_tool_shortcuts';
	public const TOOL_PREBUILT_SHORTCUTS = '_wp_mcp_ai_tool_prebuilt_shortcuts';
	public const DISABLE_TOOL_SHORTCUTS  = '_wp_mcp_ai_disable_tool_shortcuts';
	public const TOOL_ROLE_RULES         = '_wp_mcp_ai_tool_role_rules';

	// ─── Credentials (delegates to WP_MCP_AI_Credentials) ─────
	public const CREDENTIALS = '_wp_mcp_ai_assistant_credential';

	// ─── Integrations ──────────────────────────────────────────
	public const EXTERNAL_ACTION_ID   = '_wp_mcp_ai_external_action_id';
	public const EXTERNAL_ACTION_TYPE = '_wp_mcp_ai_external_action_type';
	public const MCP_APPS             = '_wp_mcp_ai_mcp_apps';
	public const PROMPT_CACHING       = '_wp_mcp_ai_prompt_caching';

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
