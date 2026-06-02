# Build Your First Workflow in 10 Minutes

> **Skill level:** Beginner  
> **Time required:** ~10 minutes  
> **What you'll build:** An assistant that drafts a WordPress post, asks for your approval before publishing, and completes the job automatically once you give the green light.

Welcome to NV oOS. By the end of this guide you'll have a working Human-in-the-Loop (HITL) workflow where an AI assistant can take real action on your site — but only after *you* say it's OK. It's a great way to get a feel for how orchestration, tools, and approval flows work together.

---

## Prerequisites

Before you start, make sure you have:

- **NV oOS installed and activated** on a WordPress site (base plugin is sufficient — no Pro add-on required for this tutorial).
- **At least one LLM provider configured** — go to **NV oOS → Settings → AI Providers** and enter an API key for OpenAI, Anthropic, or any of the other supported providers.
- **`manage_options` capability** on your WordPress user account (i.e. you are a site Administrator).

That's it. No coding required.

---

## Step 1 — Create an Assistant

1. In your WordPress admin sidebar, navigate to **NV oOS → Assistants**.
2. Click **Add New**.
3. In the title field at the top of the edit screen, enter:

   ```
   My Workflow Bot
   ```

4. Scroll down to the **Provider & Model** metabox and select your configured provider and a capable model (e.g. `gpt-4o` or `claude-3-5-sonnet`).
5. Leave all other settings at their defaults for now.

> **Tip:** The assistant is a WordPress Custom Post Type (`mcp_ai_assistant`). Everything about it — tools, system prompt, provider, temperature — lives on this edit screen.

---

## Step 2 — Enable the Required Tools

Still on the assistant edit screen, scroll down to the **Tools** metabox.

Enable the following two tools by ticking their checkboxes:

| Tool slug | What it does |
|---|---|
| `write_post` | Creates or updates a WordPress post. Accepts a title, content, status, and optional category/tag assignments. |
| `request_user_approval` | Pauses the agentic loop and sends an approval request to the **NV oOS → Approvals** admin page. The loop resumes only after a human approves or rejects the request. |

> **Why `request_user_approval`?**  
> Publishing content is a *destructive* action in the sense that it's visible to the world and may be hard to undo cleanly. By wiring `request_user_approval` before `write_post`, you're telling the assistant: "Draft the post, show it to me, and only publish after I approve." That's the HITL pattern in one sentence.

---

## Step 3 — Write a System Prompt

In the **System Prompt** metabox, paste the following prompt and feel free to adjust the tone to match your site's voice:

```
You are My Workflow Bot, an AI assistant that helps draft and publish WordPress posts.

When a user asks you to create or publish a post:
1. Draft the post content (title + body).
2. Call request_user_approval with a clear action_label and include the draft title and a brief summary in the context field.
3. Only call write_post AFTER the user approves.
4. If the user rejects, acknowledge their feedback and offer to revise.

Always be concise. Always ask for approval before publishing.
```

Click **Update** (or **Publish**) to save the assistant.

---

## Step 4 — Enable HITL in the Harness Metabox

For extra safety — and to see the harness in action — you can also turn on the Structured Output Guardrail so the assistant's approval context is always well-formed.

1. Still on the assistant edit screen, scroll to the **LLM Harness** metabox.
2. Tick **Enable harness for this assistant**.
3. Under harness options, tick **Require approval for destructive actions** (maps to `injection_detector.enabled = true` in the harness profile).
4. Click **Update** to save.

> **What this does:** The harness layer inspects every tool call for injection patterns before execution. It's off by default to preserve existing behaviour; enabling it per-assistant gives you a targeted safety net without affecting other assistants. See [`docs/llm-harness.md`](llm-harness.md) for the full harness profile schema.

---

## Step 5 — Test via the Chat Surface

1. Navigate to **NV oOS → Chat** (or open any page on your site that embeds the `[mcp_ai_chat]` shortcode with this assistant selected).
2. Make sure **My Workflow Bot** is the active assistant in the assistant selector.
3. Type the following message and press **Enter** (or click **Send**):

   ```
   Draft and publish a post about AI orchestration on WordPress.
   ```

4. Watch the assistant think. It will:
   - Draft a title and post body.
   - Call `request_user_approval` — **the loop pauses here**.

You'll see a message in the chat UI similar to this:

```
⏸ Approval requested

Action: Publish post "AI Orchestration on WordPress: A Practical Guide"

Summary: A 400-word introductory post covering what AI orchestration means
in the WordPress context, why it matters for site owners, and three ways to
get started with NV oOS today.

Waiting for your approval in NV oOS → Approvals…
```

The assistant is now suspended. It won't call `write_post` — or do anything else — until you act.

---

## Step 6 — Review the Approval Request

Open a new browser tab and navigate to:

**WordPress admin → NV oOS → Approvals**

You'll see a row in the **Pending** table:

| Field | Value |
|---|---|
| **Action label** | Publish post "AI Orchestration on WordPress: A Practical Guide" |
| **Requested by** | My Workflow Bot (assistant #…) |
| **Time** | a few seconds ago |
| **Expires in** | 4 min 52 sec (default timeout is 5 minutes) |

Click the row to expand the full context panel. You'll see the draft title, the model's summary, and the tool arguments it intends to pass to `write_post`. This is your chance to review exactly what will happen before it does.

---

## Step 7 — Approve and Watch the Post Publish

1. Click the green **Approve** button.
2. Optionally, add a reviewer note (e.g. `"Looks good — go ahead"`).
3. Click **Confirm Approval**.

Switch back to the chat tab. Within a second or two you'll see the assistant's agentic loop resume:

```
✅ Approval received from admin.

Publishing the post now…

Done! "AI Orchestration on WordPress: A Practical Guide" has been published.
View it here: https://yoursite.com/ai-orchestration-on-wordpress/
```

Navigate to **Posts** in your WordPress admin and confirm the post is live. 🎉

> **What if you reject?** Click **Reject** instead, optionally add a reason, and the assistant will receive `{ "approved": false, "reason": "..." }` as the tool response. The system prompt above instructs it to acknowledge your feedback and offer to revise.

---

## Step 8 — Next Steps

You've just built and run a Human-in-the-Loop workflow entirely inside WordPress. Here's where to go from here:

### Deepen your orchestration knowledge

- **[Orchestration Documentation Hub](orchestration-reference.md)** — the canonical index for all orchestration features including the full HITL API reference, OTel span exporter, prompt injection detection, and the Phase 3–6 roadmap.
- **[LLM Harness Layers](llm-harness.md)** — seven opt-in per-assistant layers for better reasoning, retrieval-with-provenance, self-critique loops, and structured output enforcement.
- **[Hooks Reference](hooks-reference.md)** — all 60+ action and filter hooks; use `wp_mcp_ai_before_tool_execution` to build custom approval logic in PHP.

### Extend the workflow

- **Add more tools.** Enable `send_slack_message` or `send_email` on the assistant and update the system prompt to notify your team after publishing.
- **Try multi-agent delegation.** Configure a second assistant (e.g. an SEO auditor) and have My Workflow Bot delegate to it via `delegate_to_agent` before requesting approval.
- **Compare platforms.** Wondering how NV oOS stacks up against LangGraph or n8n? See [docs/orchestration-platform-comparison.md](orchestration-platform-comparison.md).

### Get help

- **[Getting Started guides](getting-started/)** — installation, first assistant, and provider setup.
- **[Troubleshooting](troubleshooting/)** — common issues and their fixes.
- **[CONTRIBUTING.md](../CONTRIBUTING.md)** — if you want to extend NV oOS or contribute a skill or tool.
