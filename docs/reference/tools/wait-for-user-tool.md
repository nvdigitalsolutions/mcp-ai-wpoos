# wait_for_user Tool

**Version:** 1.1.34  
**Last Updated:** June 27, 2026  
**Slug:** `wait_for_user`  
**Capability:** `read`  
**Tool Class:** `WP_MCP_AI_Tool_Wait_For_User`

## Overview

The `wait_for_user` tool is a **no-op tool** designed specifically for voice
sessions powered by GPT-Realtime-2. It provides the AI model with a deliberate
mechanism to handle periods of silence, background noise, or non-addressed
audio without generating spurious responses or hallucinating user intent.

## Purpose

Voice agents face three common failure modes that this tool prevents:

1. **Silence misinterpretation** — The model may treat extended silence as an
   implicit prompt to continue speaking, leading to monologue loops.
2. **Background noise injection** — Ambient sounds (keyboard typing, room
   noise, other conversations) can be misinterpreted as user speech.
3. **Non-addressed audio** — The agent hears conversation not directed at it
   and attempts to interject.

When the model calls `wait_for_user`, it signals: *"I've heard audio, but it
isn't a request for me. I'll wait for a clear user prompt."*

## Behavior

- Accepts no parameters
- Returns a minimal success envelope
- Does not modify any WordPress state
- Does not consume tokens beyond the tool call overhead
- Compatible with parallel tool calling (enabled by default in GPT-Realtime-2)

### Return envelope

```json
{
    "success": true,
    "message": "Waiting for user to speak."
}
```

## Use Cases

### 1. Silence handling

After responding to a user question, the model enters a listening state. If
the user remains silent for an extended period, the model calls `wait_for_user`
to extend the listening window without generating unprompted speech.

### 2. Background noise filtering

When ambient noise (e.g., keyboard typing) is detected on the audio stream,
the model calls `wait_for_user` to indicate the audio was processed but
determined to be non-addressed.

### 3. Multi-speaker environments

In a room with multiple people, the model may hear conversation that isn't
directed at it. `wait_for_user` prevents the agent from responding to
overheard speech.

## GPT-Realtime-2 Prompt Integration

The 12-section structured prompt template includes guidance for the
`wait_for_user` tool in the **Unclear Audio** section (filterable via
`wp_mcp_ai_realtime_prompt_unclear`):

> "When you hear audio that is unclear, background noise, or not addressed to
> you, call the `wait_for_user` tool instead of guessing. Only respond when
> you have received a clear, actionable request."

## Capability Requirement

The tool requires `read` capability — the lowest WordPress capability tier.
This ensures the tool is available to all authenticated users and guest
tokens in voice sessions without granting elevated access.

## Related Documentation

- [GPT-Realtime-2 Voice Models](../../features/voice/gpt-realtime-2-voice-models.md)
- [GPT-Realtime-2 Upgrade Proposal](../../project/proposals/GPT-REALTIME-2-UPGRADE-PROPOSAL.md)
- [Voice Feature README](../../features/voice/README.md)
