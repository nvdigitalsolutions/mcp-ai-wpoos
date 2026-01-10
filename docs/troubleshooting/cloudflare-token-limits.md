# Cloudflare Worker AI Token Limit Configuration

## Overview

This guide explains how token limits work for Cloudflare Worker AI in the chat-client endpoint and how to configure them to meet your needs.

## Understanding Token Limits

### What is `max_tokens`?

The `max_tokens` parameter controls the maximum number of tokens the AI model can generate in a single response. This is different from the context window (total tokens including input + output).

### Default Behavior

When you use the Cloudflare Worker AI chat-client endpoint **without** specifying an explicit `max_tokens` parameter:

1. The system uses the Resource Manager to determine the limit
2. Resource Manager checks for orchestration preset configuration
3. If no preset is configured, it falls back to built-in defaults based on server workload tier

## Workload Tiers

The system automatically detects your server's workload tier based on PHP memory limit:

| Tier | Memory Requirement | Default max_tokens |
|------|-------------------|-------------------|
| Low | < 128 MB | 2,000 tokens |
| Medium | 128 MB - 512 MB | 8,000 tokens |
| High | ≥ 512 MB | 32,000 tokens |

## Orchestration Presets

Orchestration presets allow you to configure token limits system-wide. Each preset defines different values for each workload tier.

### Available Presets

#### Conservative Preset
**Best for:** Resource-constrained environments, shared hosting

| Tier | max_tokens |
|------|------------|
| Low | 1,000 |
| Medium | 4,000 |
| High | **16,000** |

#### Balanced Preset (Default)
**Best for:** Most production sites with moderate traffic

| Tier | max_tokens |
|------|------------|
| Low | 2,000 |
| Medium | 8,000 |
| High | **32,000** |

#### Aggressive (Performance) Preset
**Best for:** Dedicated servers with ample resources

| Tier | max_tokens |
|------|------------|
| Low | 4,000 |
| Medium | 16,000 |
| High | **64,000** |

#### Development Preset
**Best for:** Development and testing environments

| Tier | max_tokens |
|------|------------|
| Low | 4,000 |
| Medium | 16,000 |
| High | **64,000** |

### Applying Orchestration Presets

1. Navigate to **WordPress Admin → Settings → NV oOS → Orchestration Layer**
2. Select your desired preset from the dropdown
3. Click "Apply Preset"
4. Click "Save Changes"

## Troubleshooting Token Limits

### Issue: Getting ~6k tokens instead of expected 16k

**Possible Causes:**

1. **Server is on Medium Tier (Most Common)**
   - Medium tier defaults to 8,000 tokens
   - After overhead, this results in ~6-7k usable tokens for responses
   - **Solution:** Increase PHP memory_limit to ≥512MB to reach High tier

2. **Orchestration Preset Not Applied**
   - If preset wasn't saved properly, system uses defaults
   - **Solution:** Reapply the preset in Settings → Orchestration Layer

3. **Model Context Window Limitation**
   - Standard Llama 3.1 8B Instruct has 8,000 token context window
   - **Solution:** Use Llama 3.1 8B Instruct **Fast** (128K context) or 70B (128K context)

4. **Request Includes Explicit max_tokens**
   - If the request explicitly sets `max_tokens`, it overrides orchestration settings
   - **Solution:** Remove explicit max_tokens from request to use orchestration settings

### Checking Current Configuration

Enable logging to see actual token limit resolution:

1. Go to **Settings → NV oOS → General**
2. Enable "Enable Logging"
3. Make a chat request
4. Check logs at **Settings → NV oOS → Recent Activity**

Look for these log events:
- `resource_manager_max_tokens` - Shows resolved max_tokens and source
- `cloudflare_default_max_tokens` - Shows max_tokens used by Cloudflare client

### Example Log Output

```json
{
  "event": "resource_manager_max_tokens",
  "message": "Resource Manager resolved max_tokens",
  "data": {
    "max_tokens": 8000,
    "workload_tier": "medium",
    "source": "default_fallback",
    "setting_key": "medium_tier_max_tokens",
    "configured_value": null,
    "memory_limit": 268435456
  }
}
```

**Analysis:** This shows:
- Medium tier detected (256MB memory)
- No orchestration preset configured (`source: default_fallback`)
- Using 8,000 token default
- To get 16k, need to either:
  - Apply Conservative preset, OR
  - Increase memory to 512MB+ for High tier

## Model Selection

Different Cloudflare Worker AI models have different context window sizes:

| Model | Context Window | Recommendation |
|-------|---------------|----------------|
| Llama 3.1 8B Instruct | 8,000 tokens | ⚠️ Limited for long responses |
| Llama 3.1 8B Instruct **Fast** | **128,000 tokens** | ✅ Best for long responses |
| Llama 3.1 70B Instruct | **128,000 tokens** | ✅ Best quality + long responses |
| Llama 3.2 1B Instruct | 128,000 tokens | ✅ Fast, efficient |
| Llama 3.2 3B Instruct | 128,000 tokens | ✅ Good balance |

**Note:** Even with a 128K context window, you still need to configure appropriate `max_tokens` via orchestration presets.

## Explicit Token Override

You can override orchestration settings per-request by including `max_tokens` in your request options:

```javascript
// JavaScript example
fetch('/wp-json/mcp-ai/v1/chat-client', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpApiSettings.nonce
  },
  body: JSON.stringify({
    assistant_id: 123,
    messages: [
      { role: 'user', content: 'Your message here' }
    ],
    options: {
      max_tokens: 25000  // Override orchestration settings
    }
  })
});
```

**Caution:** Setting `max_tokens` too high may:
- Exceed model's context window
- Cause slower responses
- Use more API credits/quota

## Best Practices

1. **Start with Balanced Preset:** Works well for most use cases
2. **Monitor Token Usage:** Check logs to see actual token consumption
3. **Use Fast Models for Long Responses:** Llama 3.1 8B Fast has 128K context
4. **Adjust Based on Needs:** 
   - Chatbots: 4,000-8,000 tokens usually sufficient
   - Content generation: 16,000-32,000 tokens recommended
   - Code generation: 32,000+ tokens for large files
5. **Consider Server Resources:** Don't set limits your server can't handle

## Summary

To increase Cloudflare Worker AI response token limits from ~6k to ~16k:

1. **Check your workload tier:** Need High tier (≥512MB memory)
2. **Apply orchestration preset:** Conservative gives 16k on High tier
3. **Use appropriate model:** Llama 3.1 Fast/70B for long responses
4. **Enable logging:** Verify settings are applied correctly

## Related Documentation

- [Orchestration Layer Configuration](../guides/admin/settings/orchestration-layer.md)
- [Resource Manager](../architecture/core/resource-manager.md)
- [Model Configuration](../reference/models/model-configuration.md)
