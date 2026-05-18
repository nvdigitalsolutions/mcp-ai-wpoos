# P4 Tracking: Guest-Token Requests and Capability Evaluation

**Status:** Open  
**Phase:** 4 (follow-up item)  
**Raised:** 2026-05  
**Owner:** TBD

---

## Background

As of Phase P2b, the capability fence in
`WP_MCP_AI_REST::build_tools_payload()` filters tools based on
`current_user_can( $tool->get_required_capability() )`. Authenticated
requests (WordPress nonce, assistant credentials, Auth0 tokens) are
evaluated correctly against this fence.

**Guest-token requests bypass this fence.** When the `$context['guest_request']`
flag is set (see `WP_MCP_AI_REST`, guest-token auth handler), the payload
builder skips the `current_user_can()` check entirely. The intent is to
allow public chat surfaces to offer a curated tool set, but the current
implementation gives guests access to *all* tools registered with the
assistant rather than a specifically-scoped subset.

---

## The Question

Should guest-token requests be evaluated against a per-assistant
**guest capability set** (a list of capabilities or tool slugs configured
by the site owner on the assistant's edit screen) rather than unconditionally
bypassing the fence?

---

## Options

### Option A — Per-assistant tool allow-list (recommended)
Add a metabox/setting on the assistant CPT edit screen:
> "Tools available to guests" — multi-checkbox of registered tools.

The `build_tools_payload()` call for guest requests filters to this allow-list
instead of falling through to all tools. If the allow-list is empty, default
to a safe subset (read-only tools only, identified by the `'read-only'`
capability flag).

**Pros:** Fine-grained control, no capability-string complexity, intuitive for admins.  
**Cons:** More UI surface; site owners must explicitly configure each assistant.

### Option B — Guest capability string
Add a "minimum WordPress capability required for guest tool access" setting
per assistant (default `'read'` — subscriber-level). Feed it to
`current_user_can( $guest_cap, $tool_cap )` to gate tools.

**Pros:** Familiar WordPress capability model.  
**Cons:** WordPress capabilities are hierarchical and guest users may not be
WP users at all; using `current_user_can()` requires a WP_User object and
may not map cleanly to guest contexts.

### Option C — Read-only by default, no further configuration
Guest requests automatically receive only tools flagged `'read-only'` via
`WP_MCP_AI_Tool_Capability_Flags_Interface`. No admin configuration needed.

**Pros:** Simple, safe default.  
**Cons:** Many useful public-facing tools (e.g. `chat_with_assistant`,
`search_posts`) may not carry the `'read-only'` flag and would be excluded.

---

## Recommendation (pending discussion)

**Option A** with a sensible default: if the assistant's allow-list is empty,
the fence falls back to tools that have `'read-only'` in their capability
flags (Option C behaviour), so out-of-the-box deployments are safe.

---

## Implementation Notes (when this item is picked up)

1. Add `_wp_mcp_ai_guest_tool_allow_list` post-meta to `mcp_ai_assistant` CPT.
2. Extend `WP_MCP_AI_REST::build_tools_payload()`:
   ```php
   if ( ! empty( $context['guest_request'] ) ) {
       $allow_list = get_post_meta( $assistant_id, '_wp_mcp_ai_guest_tool_allow_list', true );
       if ( ! empty( $allow_list ) && is_array( $allow_list ) ) {
           // Filter tools to allow-list.
       } else {
           // Default: read-only tools only.
       }
   }
   ```
3. Add allow-list metabox to the assistant edit screen (admin side).
4. Regression-test the guest chat flow to confirm no tools are leaked.
5. Update `docs/rest-api.md` to document the new guest-tool-scoping behaviour.
6. Create a PHPUnit test: `tests/test-guest-capability-fence.php`.

---

## Related Files

- `includes/class-wp-mcp-ai-rest.php` — `build_tools_payload()`, guest auth handler
- `includes/class-wp-mcp-ai-tool-capability-map.php` — capability assignments
- `docs/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md` — overall plan
- `docs/proposals/audits/P2b-required-capability-assignment-2026-05.md` — P2b audit
