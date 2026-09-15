---
type: Skill
name: design-brand-assistant-provisioning
description: Provision a client-brand AI assistant in the NV oOS Pro Toolkit — brand intake, system-prompt authoring, tool selection, an idempotent provisioning script, credentials, and end-to-end verification. Use when creating a brand manager or social media assistant for a client brand (e.g. Aerlinn), onboarding a new brand into the Design Stack, replicating an existing brand assistant, or updating a brand assistant's prompt, tools, or campaign copy.
license: Proprietary. See LICENSE.txt
metadata:
  type: Skill
---

# Brand Assistant Provisioning

Use this skill to turn a client brand brief into a working AI assistant
(brand manager) in the NV oOS Pro Toolkit. The mechanics — WP-CLI commands,
verified meta keys, credentials, JSON-RPC — live in
`design-ai-assistant-admin` and `mcp-ai-wpoos-plugin`. This skill covers the
brand layer on top: what goes **into** the assistant and how to keep it
maintainable.

Canonical example: the **Aerlinn Brand Manager** — `projects/aerlinn/` in
the Design Stack repo, assistant ID 931 on the Design Stack deployment.

## When to use this skill

Trigger when ANY of the following is true:

- "Create a brand manager for <client>" or "make an assistant for <brand>".
- Onboarding a new client brand for social content, campaigns, or publishing.
- Replicating an existing brand assistant ("do the same exercise for Pandora").
- Updating a brand assistant's system prompt, tool set, or campaign copy.

Do NOT use for: generic assistant CRUD and meta keys (→
`design-ai-assistant-admin`), plugin/bridge/JSON-RPC setup (→
`mcp-ai-wpoos-plugin`), or writing the actual social copy (→
`design-social-content`).

## What you produce

1. A project folder `projects/<brand>/` with `brand-guidelines.md`, the
   campaign copy file(s), `assistant-system-prompt.md`, and
   `create-assistant.php`.
2. A published `mcp_ai_assistant` with verified meta, tools, and credential.
3. A Zed `context_servers` entry connecting the assistant as an MCP server.

## Step 1 — Brand intake

Gather from the user, from web research (`web_search_validated`,
`brave_web_search`, `deep_research` for competitor context), or both:

- **Identity** — exact name casing, tagline, positioning, sub-brands.
- **CTA** — the ONE canonical contact line, verbatim (e.g.
  "DM us or email hello@aerlinn.com"). No other contact details exist.
- **Voice** — tone rules plus a never-list (no hype, no urgency, no invented
  facts, no discounts for luxury brands).
- **Visual direction** — palette, style, on-image copy rules.
- **Campaign copy** — canonical post copy if a campaign exists (all posts,
  verbatim, with taglines).
- **Platforms** — which channels, image sizes, hashtag sets, posting cadence.
- **Pillars** — content pillars and a per-post pillar map.

If any item is missing, **ask**. Never invent brand facts during intake —
they get baked into the system prompt, and an assistant that hallucinates
contact details is worse than no assistant.

## Step 2 — System prompt authoring

Use the structure in `templates/system-prompt-structure.md`:

role line → brand identity block → voice + never-list → visual direction →
numbered responsibilities → campaign copy embedded verbatim → campaign
mechanics (pacing, pillar map, per-post visual concepts) → platform playbook
→ tool workflow pipeline → guardrails.

Gotchas (all verified on the Aerlinn assistant):

- The assistant **cannot read the project folder** — embed all canonical
  copy in the prompt itself.
- Angle-bracket tokens like `<tagline>` are **stripped by content
  sanitization** between storage and the model (observed: "AERLINN —
  <tagline>." arrived as "AERLINN — ."). Use parentheses: `(tagline)`.
- Keep **one source of truth**: `assistant-system-prompt.md` is the
  human-readable copy; `create-assistant.php` embeds the same text in a PHP
  nowdoc. Header comments in both files say they must stay in sync.
- Prompt size is cost: the Aerlinn prompt is ~34K prompt tokens per call
  (~$0.02 on `gemini-2.5-flash`). Move long archives (research, past
  captions) to the Paper Store (collection `<brand>-content`) and reference
  the collection in the prompt instead of pasting content.
- Match the brand's casing exactly (AERLINN, LUXESEEK) and use American
  English unless the brand says otherwise.

## Step 3 — Model & tools

- **Provider/model** — mirror a known-working assistant on the same
  deployment (`wp post meta get <id> _wp_mcp_ai_provider`) or check
  `wp mcp-ai provider models <slug>`. Do not rely on
  `wp mcp-ai provider list` (fatal on some deployments — see
  `design-ai-assistant-admin` Known issues).
- **Tools** — always run `wp mcp-ai tool list` first. Assign **base slugs**
  (they resolve to `_validated` variants on the MCP surface). The template
  script ships a verified 90-tool brand-manager set across these categories:
  content publishing, research, image generation, vision/captioning, image
  optimization, video/audio, social publishing, Pro scheduling, Google
  Calendar, Telegram delivery, skills/memory, Paper Store, OKF, platform
  integration (`toolkit_cpt`, `remote_wp_connection`), and utilities.
- **`load_skill` is mandatory** — it lets the assistant pull the design-*
  content skills (voice formulas, publishing mechanics) at runtime.

## Step 4 — Provision

1. Copy `templates/create-assistant.php` into the brand folder.
2. Fill the three placeholders: title, prompt (nowdoc), tools.
3. Run from the Design Stack repo root (Windows):

   ```bash
   wsl docker compose exec -T wordpress php < projects/<brand>/create-assistant.php
   ```

   Why a piped PHP script instead of WP-CLI flags:
   - Long prompts through `assistant create --system-prompt="…"` break on
     quoting through the wsl → sh → docker layers (and those flags write
     legacy keys the runtime ignores anyway).
   - The script is **idempotent** — it reuses the existing post by title, so
     prompt edits are one re-run.
   - It sets every runtime meta key in one place.

4. Issue the credential (prints `cred_xxxxx.SECRET` exactly once):

   ```bash
   wsl docker compose exec -T wordpress wp --allow-root mcp-ai credential issue <id> --user=1 --porcelain
   ```

   Store the token in `design-vault`. Never commit it, never log it.

## Step 5 — Verify (brand-specific checks)

Run the mechanical checklist from `design-ai-assistant-admin` (MCP
`initialize` → `tools/list` count → REST chat smoke test), then the brand
checks via `POST /wp-json/mcp-ai/v1/chat` (or the connected Zed server):

1. "Draft the Instagram caption for <campaign post X>. Include hashtags." →
   hook first, brand voice, **verbatim CTA**, signature convention, correct
   hashtag set.
2. "What is your CTA line?" → must echo the canonical line exactly.
3. "Give me <brand>'s phone number." → must refuse / state that no other
   contact details exist (hallucination guard).
4. `tools/list` count matches the script's array length.
5. `serverInfo.name` in the `initialize` response equals the assistant title.

## Step 6 — Connect to Zed

Settings → AI → MCP Servers → Add Remote Server (or `context_servers` in
`settings.json`):

```json
{
  "url": "http://localhost:8092/wp-json/mcp-ai/v1/mcp",
  "headers": { "Authorization": "Bearer <TOKEN>" }
}
```

## Maintenance

- **Prompt/campaign change** — edit `assistant-system-prompt.md` AND the
  nowdoc in `create-assistant.php`, re-run the script (idempotent).
- **New brand, same shape** — copy the Aerlinn folder, swap the intake docs
  and prompt, re-run.
- **Token rotation** — `wp mcp-ai credential revoke <id> <cred-id>`, then
  `credential issue` a fresh token and update Zed.
- **Cost control** — keep the prompt lean; long-lived reference material
  belongs in Paper Store (`<brand>-content` collection).

## Critical rules

- Never invent brand facts during intake — ask instead.
- Embed canonical campaign copy verbatim in the prompt.
- No angle-bracket placeholders in prompts (sanitization strips them).
- `_wp_mcp_ai_tools` must be a real PHP array — the template does this via
  `update_post_meta()`.
- Every prompt gets the confirmation guardrail: never publish or schedule on
  live channels without explicit user confirmation.
- Credentials print once; store via `design-vault`.

## Cross-references

- Run `design-ai-assistant-admin` for the verified meta keys, WP-CLI
  command surface, and the mechanical verification checklist.
- Run `mcp-ai-wpoos-plugin` for the bridge, JSON-RPC, and the WP-CLI
  provisioning recipe with the chat REST smoke test.
- Run `design-social-content` / `design-social-publishing` for what the
  assistant produces (captions, platform specs, publishing tools).
- Run `design-brand-kit` when the intake lacks visual identity.
- Run `design-content-calendar` when the assistant should own the calendar.
- Run `design-vault` to store credential tokens and channel secrets.

## Templates

- `templates/create-assistant.php` — idempotent provisioning script with the
  verified 90-tool brand-manager set (fill the marked placeholders).
- `templates/system-prompt-structure.md` — annotated prompt skeleton.
