# Tool Description Guidelines

How to write model-facing descriptions for NV oOS tools. These rules codify
industry best practices (Block's MCP playbook, AWS tool-design guidance,
Anthropic tool-use docs) for a ~1,585-tool surface.

Reference proposal: [`docs/project/proposals/tool-description-engineering-proposal.md`](../project/proposals/tool-description-engineering-proposal.md)

## The two-layer model

Tool descriptions serve two audiences. Keep them separate:

| Layer | Method | Audience | Rule |
|---|---|---|---|
| Short description | `get_description()` | Admin UI (tool metabox, presets, chips) | One to two sentences. State what the tool does, not how to choose it. |
| Usage guidance | `get_usage_guidance()` (via `WP_MCP_AI_Tool_Usage_Guidance_Interface`) | The LLM only | When to use, when NOT to use, related tools, notes. |

`WP_MCP_AI_Tool_Registry::get_model_facing_description()` assembles both into
the payload the model sees. The registry appends the guidance as a compact
`[Usage: ...]` suffix — the same mechanism as the existing
`[Data contract: ...]` suffix — so OpenAI strict schemas stay valid.

## Guidance shape

```php
class WP_MCP_AI_Tool_Get_Post implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	public function get_usage_guidance() {
		return array(
			'when_to_use'     => 'Read a single known post by ID.',
			'when_not_to_use' => 'Listing, searching, or discovering posts.',
			'related_tools'   => array( 'get_recent_posts', 'search_content', 'create_post', 'update_post' ),
			'notes'           => 'Returns full content and meta by default; set include_meta=false for leaner output.',
		);
	}
}
```

## Rules

1. **Always write `when_not_to_use`.** Negative guidance is the single
   highest-leverage description signal (halves bad invocations in the
   field). Name the alternative tool if one exists.
2. **Keep guidance total under ~250 characters.** Rich descriptions help
   accuracy but every token repeats on every request. Guidance is a
   selection aid, not documentation.
3. **Use enums + defaults instead of prose for valid values.** A parameter
   `enum` lets the schema itself tell the model what is valid; a `default`
   removes guesswork. Keep parameter counts at eight or fewer where
   possible (AWS guidance); split rarely used parameters into a detail tool.
4. **Do not mix read and write in one tool** (Block's permission-model
   rule). Bundle related *read-only* operations with an enum parameter;
   keep state-changing operations as single-intent tools so HITL approvals
   and the destructive-ops gate stay meaningful.
5. **Name parameters for the LLM's domain, not the database.**
   `resource_class` → `subject`; list synonym mappings in `notes` when the
   LLM's phrasing differs from accepted values ("quiz" → `Assessment`).
6. **Action-enum tools** (one tool, multiple operations) are acceptable
   only when the operations form one workflow surface and share one
   capability + risk level. Exemplars: `remote_wp_connection`,
   `toolkit_cpt`. A tool with 15+ unrelated actions is an anti-pattern
   ("God Tool") — split it.

## Enforcement

`WPMCPAI.Tools.ToolDescriptionGuidance` warns when a tool class implements
`WP_MCP_AI_Tool_Interface` but neither implements
`WP_MCP_AI_Tool_Usage_Guidance_Interface` nor embeds usage guidance in its
`get_description()`. Severity is 0 (advisory) in the main ruleset while the
sweep rolls out cluster-by-cluster; run explicitly with:

```bash
vendor/bin/phpcs --standard=phpcs/WPMCPAI/ruleset.xml --severity=5 includes/tools addons/pro/includes/tools
```

## Example before/after

Before:

```php
public function get_description() {
	return __( 'Retrieves a single WordPress post by ID, including its content, metadata, and taxonomy terms.', 'mcp-ai-wpoos' );
}
```

After (short description unchanged; guidance added):

```php
public function get_usage_guidance() {
	return array(
		'when_to_use'     => __( 'Fetch one known post to read, edit, or chain into SEO/translation tools.', 'mcp-ai-wpoos' ),
		'when_not_to_use' => __( 'Listing posts, searching by keyword, or creating content.', 'mcp-ai-wpoos' ),
		'related_tools'   => array( 'get_recent_posts', 'search_content', 'create_post', 'update_post' ),
	);
}
```
