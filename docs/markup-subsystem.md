# Markup Subsystem

> **Status:** Foundation (PR 1) ✅ · Chat canvas widget (PR 2) ✅ · First
> markup-aware tools — `edit_openai_image` (PR 4), `crop_image`
> (PR 4b), `edit_gemini_image` (PR 4c) ✅ · `document_pdf` mode (PR 3)
> and Settings UI (PR 5) deferred to follow-up PRs.

The Markup Subsystem lets tools hand back an **editable canvas** in the
chat surface so the user can visually explain what they want
(*"move the logo here"*, *"redact this paragraph"*, *"inpaint **this**
region"*) and resume tool execution with structured spatial / textual
markup data on the next turn.

It is built on three converging open standards so we don't invent a
private protocol:

| Standard | Used for |
|----------|----------|
| **MCP Elicitation** (spec 2025-06-18 / 2025-11-25) | Cross-host pattern for *"tool needs structured input"*. We emit `elicitation/create` envelopes to external MCP clients and surface URL-mode fallback for hosts that can't render our chat-bubble widget. |
| **W3C Web Annotation Data Model** | Canonical interchange envelope (`@context: http://www.w3.org/ns/anno.jsonld`) with `SvgSelector`, `FragmentSelector`, `TextQuoteSelector`, `TextPositionSelector`. |
| **Image-mask conventions used by OpenAI / Gemini / Stability** | RGBA PNG, alpha=0 inside the edit region, alpha=255 outside. Existing inpainting tools accept the result with **zero parameter changes**. |

## Configuration

The markup subsystem is **enabled by default**. Site administrators
can toggle it from **NV oOS Settings → Tools & Features → Enable
Markup Subsystem**, which writes to `wp_mcp_ai_settings.markup_enabled`.

When disabled:

- `WP_MCP_AI_Markup_Loop_Interceptor::is_enabled()` returns `false`.
- The pre-execution filter (`wp_mcp_ai_pre_execute_tool`) returns
  `null` and tools execute with whatever arguments the model already
  supplied — no canvas elicitation is ever surfaced.
- Already-issued URL-mode fallback links still resolve via the REST
  controller until they expire from the store.

The toggle reads from `get_option( 'wp_mcp_ai_settings' )` directly
(not the merged-defaults accessor) so installations that have never
visited the settings screen continue to use the
`wp_mcp_ai_markup_enabled` filter default (`true`) until the option
is materialized by saving any settings tab.

## Architecture

```
┌─────────────────┐   needs_markup()    ┌──────────────────┐
│  Markup-aware   │──────────────────▶ │  Loop Interceptor │
│  tool (Pro)     │                    │  (Base subsystem) │
└─────────────────┘                    └────────┬─────────┘
       ▲                                        │ widget payload
       │ consume_markup()                       ▼
┌─────────────────┐    POST /submit    ┌──────────────────┐
│  REST Controller│ ◀──────────────── │  Chat client      │
│  (Base)         │                    │  canvas widget    │
└─────────┬───────┘                    └──────────────────┘
          │ rasterize → mask / rect / vector / redactions
          ▼
        tool result (resumes original chat thread)
```

### Files

| File | Role |
|------|------|
| `includes/markup/interface-wp-mcp-ai-markup-aware-tool.php` | Optional interface a tool implements to opt in. |
| `includes/markup/class-wp-mcp-ai-markup-request.php` | Immutable request value object. |
| `includes/markup/class-wp-mcp-ai-markup-result.php` | Validated submission result wrapper. |
| `includes/markup/class-wp-mcp-ai-markup-elicitation.php` | Encodes MCP `elicitation/create` and chat-bubble widget envelopes. |
| `includes/markup/class-wp-mcp-ai-markup-store.php` | Transient-backed pending-request store with TTL and per-assistant cap. |
| `includes/markup/class-wp-mcp-ai-markup-validator.php` | Server-side W3C-annotation validator with capability and DoS guards. |
| `includes/markup/class-wp-mcp-ai-markup-rasterizer.php` | Converts validated annotations into mask PNGs / rects / vectors / PDF redaction lists. |
| `includes/markup/class-wp-mcp-ai-markup-loop-interceptor.php` | Hooks the agentic-loop short-circuit filter. |
| `includes/markup/class-wp-mcp-ai-markup-rest-controller.php` | REST routes under `/mcp-ai/v1/markup/…`. |
| `includes/markup-init.php` | Bootstrap (loaded from `bootstrap/loader.php`). |

## Interaction modes

| Mode | Use case | Rasterizer output |
|------|----------|-------------------|
| `mask` | "Inpaint *this* region" | `mask_attachment_id` (RGBA PNG) |
| `region` | "Look at *this* area" | `region_rect` `{x,y,width,height}` |
| `crop` | "Crop like this" | `crop_rect` |
| `position` | "Move *here*" | `position_vector` `{from,to,normalized}` |
| `redact` | PDF/document redaction boxes | `redaction_rects` (page-keyed) |
| `annotate` | Notes + arrows + labels | `shapes`, `comments` |
| `text_range` | "Rewrite *this* paragraph" | `text_ranges` (W3C `TextQuoteSelector` + `TextPositionSelector`) |

## Tool authoring

Implement `WP_MCP_AI_Markup_Aware_Tool_Interface` alongside the
existing tool interface. The first first-party adopters are:

| Tool | Mode | Opt-in argument | Result artifact |
|------|------|-----------------|-----------------|
| `edit_openai_image` (`includes/tools/class-wp-mcp-ai-tool-edit-openai-image.php`) | `mask` | `request_user_mask` | `mask_attachment_id` → injected as `mask_id` |
| `crop_image` (`includes/tools/class-wp-mcp-ai-tool-crop-image.php`) | `crop` | `request_user_crop` | `crop_rect` → denormalized to pixel `x/y/width/height` |
| `edit_gemini_image` (`includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php`) | `region` | `request_user_region` | `region_rect` → denormalized + appended as a prompt directive (Gemini has no native mask channel) and persisted on `target_region` |

Both follow the same lifecycle: when the opt-in flag is set and no
deterministic alternative is supplied, `needs_markup()` returns a
`WP_MCP_AI_Markup_Request`; the agentic loop short-circuits and shows
the canvas widget; `consume_markup()` merges the rasterized artifact
into the original arguments, clears the opt-in flag (loop-safety), and
re-invokes `execute()`.

Other markup-aware tools follow the same pattern:

```php
class WP_MCP_AI_Tool_Image_Inpainting
    extends WP_MCP_AI_Tool_Base
    implements WP_MCP_AI_Markup_Aware_Tool_Interface {

    public function needs_markup( array $arguments, array $context ) {
        // Already have a mask? Proceed normally.
        if ( ! empty( $arguments['mask'] ) ) {
            return null;
        }
        if ( empty( $arguments['attachment_id'] ) || empty( $arguments['prompt'] ) ) {
            return null; // Bad args — let execute() emit the usual error.
        }
        $meta = wp_get_attachment_metadata( (int) $arguments['attachment_id'] );
        return new WP_MCP_AI_Markup_Request(
            array(
                'tool_slug'      => 'image_inpainting',
                'target'         => array(
                    'attachment_id' => (int) $arguments['attachment_id'],
                    'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
                    'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
                ),
                'target_type'    => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
                'mode'           => WP_MCP_AI_Markup_Request::MODE_MASK,
                'instructions'   => __( 'Paint over the area you want regenerated.', 'mcp-ai-wpoos' ),
                'tool_arguments' => $arguments,
                'tool_context'   => $context,
                'assistant_id'   => isset( $context['assistant_id'] ) ? (int) $context['assistant_id'] : 0,
            )
        );
    }

    public function consume_markup( array $arguments, WP_MCP_AI_Markup_Result $result, array $context ) {
        $arguments['mask'] = $result->get_artifact( 'mask_attachment_id' );
        return $this->execute( $arguments, $context );
    }
}
```

The agentic loop will:

1. Call `needs_markup()` before `execute()`.
2. If a `WP_MCP_AI_Markup_Request` is returned, persist it in the
   markup store, stream a `markup_elicitation` chat bubble, and stop
   the iteration.
3. Resume `consume_markup()` once the user submits annotations via
   the REST `/markup/{request_id}/submit` endpoint.

## REST API

| Route | Method | Purpose |
|-------|--------|---------|
| `/mcp-ai/v1/markup/{request_id}` | `GET` | Fetch the schema for the canvas widget. |
| `/mcp-ai/v1/markup/{request_id}` | `DELETE` | Cancel a pending request. |
| `/mcp-ai/v1/markup/{request_id}/submit` | `POST` | Submit a W3C annotation; rasterize and resume the tool. |

Authentication uses the same tiers as the chat endpoint (REST nonce,
assistant credentials, Auth0, guest token).

## Hooks

| Hook | Type | When |
|------|------|------|
| `wp_mcp_ai_pre_execute_tool` | filter | Right before `$tool->execute()` (Base hook added by this PR). Returning a non-null value short-circuits the tool. |
| `wp_mcp_ai_markup_request_created` | action | A markup request has been persisted and is about to be streamed to the client. |
| `wp_mcp_ai_markup_submitted` | action | The user submitted annotations; payload not yet validated. |
| `wp_mcp_ai_markup_validated` | action | Annotations passed validation. |
| `wp_mcp_ai_markup_resolved` | action | Request finished (`completed`, `cancelled`, `invalid`, or `tool_error`). |
| `wp_mcp_ai_markup_widget_payload` | filter | Mutate the chat-bubble widget payload before streaming. |
| `wp_mcp_ai_markup_mcp_elicitation` | filter | Mutate the MCP `elicitation/create` envelope. |
| `wp_mcp_ai_markup_rasterized_artifacts` | filter | Mutate the rasterizer output before it reaches the tool. |
| `wp_mcp_ai_markup_enabled` | filter | Override the default-enabled state when no setting is saved. |
| `wp_mcp_ai_markup_cleanup` | cron | Daily cleanup of expired requests and orphan masks. |

## Settings

The subsystem is enabled by default. Site admins can disable it via
the `wp_mcp_ai_settings['markup_enabled']` option (toggle to `false`),
or via the `wp_mcp_ai_markup_enabled` filter when no setting is saved.

## Observability

`WP_MCP_AI_Markup_Telemetry` (auto-registered from
`includes/markup-init.php`) subscribes to the four lifecycle actions
and aggregates outcome / per-tool / per-mode counters into the
`wp_mcp_ai_markup_telemetry` option (non-autoloaded). Each event also
flows through `WP_MCP_AI_Logger::log_event()` under the `markup_*`
event-type family (`markup_created`, `markup_submitted`,
`markup_validated`, `markup_completed`, `markup_cancelled`,
`markup_invalid`, `markup_tool_error`) so the existing recent-activity
buffer surfaces them when logging is enabled.

Per-tool and per-mode buckets are capped (100 / 32 distinct keys) and
overflow into a single `_other` bucket so the option cannot grow
unbounded.

```php
$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
// $summary['counts']['completed']    -> int
// $summary['tools']['edit_openai_image']['completed'] -> int
// $summary['modes']['mask']['completed']              -> int
// $summary['last_seen']['completed']                  -> unix timestamp
```

### CLI / chat surface

A `/markup-stats` slash command (alias `/markup`) renders the summary
as a Markdown table inside any chat surface that supports slash
commands. Flags:

| Flag | Effect |
|------|--------|
| `--verbose` / `-v` | Show every per-tool / per-mode row instead of the top 5. |
| `--json` | Return the raw summary as JSON in the message field (still includes the parsed array under `data`). |
| `--reset` | Clear the counters (requires `manage_options`). |

Required capability for read access: `edit_posts`.

### Admin dashboard

A read-only **NV oOS → Markup Telemetry** submenu page renders the
same summary as a server-rendered HTML dashboard:

- Top row: **Created**, **Completed**, **Cancelled**, and a colour-coded
  **Completion rate** card.
- All seven outcome buckets in a single sortable-style table with
  per-bucket "last seen" relative timestamps.
- **By tool** and **By mode** breakdown tables sorted by `created`
  (descending), tie-broken by `completed`.
- A `Reset counters` form using `admin_post_wp_mcp_ai_reset_markup_telemetry`
  with a nonce. The same `manage_options` check is enforced inside the
  handler.

The page slug is `wp-mcp-ai-markup-telemetry`. It is registered from
`includes/markup-init.php` via `WP_MCP_AI_Admin_Markup_Telemetry_Page`
and only mounts when `is_admin()` is true.

## Security model

* **Capability gate**: `edit_posts` for staff submissions; guest
  submissions only proceed when the targeted attachment has no owner
  and the request was created in a guest-token session.
* **Replay protection**: `submit` consumes the request on read.
* **DoS limits**: max 64 shapes, max 8 KB per `SvgSelector`, max
  4096×4096 mask, max 256 KB total annotation payload, max 16 pending
  requests per assistant.
* **HTML/SVG sanitisation**: `wp_kses` allowlist for SVG selectors
  (only geometric primitives, no `<script>`, no event handlers).
* **Private mask attachments**: rasterized masks are written to a
  hardened uploads subdirectory (`uploads/wp-mcp-ai-markup/`) with
  `.htaccess` and `index.php` guards.
* **Audit log**: every request creation writes to
  `wp_mcp_ai_recent_activity`.
