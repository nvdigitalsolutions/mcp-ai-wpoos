# AI Model Defaults & Strategy Guide

This document describes the default model strategy for Open Operator System (NV oOS), including the default lanes system, current recommended defaults by provider, and migration guidance.

## Overview

NV oOS uses a **four-lane default model strategy** to organize model recommendations across all providers:

| Lane | Purpose | Example Use Case |
|------|---------|-----------------|
| **Stable** | Proven production default | Day-to-day chat, tool execution |
| **Latest** | Current recommended / preview | Evaluating newest capabilities |
| **Budget** | Cost-effective / fast alternative | High-volume tasks, cost-sensitive workloads |
| **Local** | Auto-discovered from local server | Ollama, LM Studio environments |

Additional capability-specific lanes exist for vision, image generation, speech-to-text, and text-to-speech.

## Current Default Models by Provider

### OpenAI

| Capability | Default Model | Lane | Notes |
|-----------|--------------|------|-------|
| **Text/Chat (Stable)** | `gpt-4.1` | Stable | Proven production default with broad compatibility |
| **Text/Chat (Recommended)** | `gpt-5.4` | Latest | OpenAI's current recommended default for new projects |
| **Text/Chat (Budget)** | `gpt-5.4-mini` | Budget | Cost-optimized, suitable for routine tasks |
| **Image Generation** | `gpt-image-1.5` | Latest | Current state-of-the-art image model |
| **Transcription (STT)** | `gpt-4o-mini-transcribe` | Stable | Superior accuracy over legacy Whisper |
| **Text-to-Speech** | `gpt-4o-mini-tts` | Stable | Natural speech with voice presets |
| **Embeddings** | `text-embedding-3-small` | Stable | Best balance of performance and cost |

**Legacy models still supported:**
- `whisper-1` — Legacy Whisper model for transcription
- `tts-1`, `tts-1-hd` — Legacy TTS models
- `gpt-image-1` — Previous generation image model
- `dall-e-3`, `dall-e-2` — DALL-E image models
- `gpt-4o`, `gpt-4o-mini` — Previous generation chat models

### Anthropic (Claude)

| Capability | Default Model | Lane | Notes |
|-----------|--------------|------|-------|
| **Text/Chat** | `claude-sonnet-4-6` | Stable | Best balance of intelligence and speed |
| **Premium** | `claude-opus-4-6` | Latest | Flagship model for complex tasks |
| **Budget** | `claude-haiku-4-5` | Budget | Fastest and most economical Claude model |
| **Vision** | `claude-sonnet-4-6` | Stable | All Claude 4.x models support vision |

### Google Gemini

| Capability | Default Model | Lane | Notes |
|-----------|--------------|------|-------|
| **Text/Chat** | `gemini-2.5-flash` | Stable | Price-performance workhorse, production-ready |
| **Premium** | `gemini-2.5-pro` | Latest | Flagship with best overall performance |
| **Image Generation** | `gemini-2.5-flash-image` | Stable | Specialized image generation model |
| **Video Generation** | `veo-2.0-generate-001` | Stable | Stable video generation with fewer restrictions |
| **Video (Preview)** | `veo-3.1-generate-preview` | Latest | Latest with audio and 1080p support |

#### Google Gemma 4 (Open Source, Apache 2.0 — April 2026)

All Gemma 4 variants are multimodal (text, image, video) and available via Gemini API, Ollama, NVIDIA NIM, LM Studio, Hugging Face, and Cloudflare Workers AI:

| Model | Parameters | Context Window | Modalities | Target |
|-------|-----------|----------------|------------|--------|
| `gemma-4-31b-it` | 31B (dense) | 256K | Text, Image, Video | Server/workstation |
| `gemma-4-26b-it` | 26B MoE (3.8B active) | 256K | Text, Image, Video | Consumer GPU |
| `gemma-4-e4b-it` | ~4B | 128K | Text, Image, Audio, Video | Edge/mobile |
| `gemma-4-e2b-it` | ~2B | 128K | Text, Image, Audio, Video | Edge/mobile |

Fallback chain: `gemma-4-31b-it` → `gemma-4-26b-it` → `gemma-4-e4b-it` → `gemma-4-e2b-it`.

### Cloudflare Workers AI

| Capability | Default Model | Lane | Notes |
|-----------|--------------|------|-------|
| **Text/Chat** | `@cf/meta/llama-4-scout-17b-16e-instruct` | Stable | Function calling support, recommended default |
| **Budget** | `@cf/meta/llama-3.2-3b-instruct` | Budget | Smaller, faster model for simple tasks |
| **Image Generation** | `@cf/black-forest-labs/flux-2-dev` | Stable | Best balanced image quality |
| **Image (Fast)** | `@cf/black-forest-labs/flux-1-schnell` | Budget | Fastest image generation |

### Hugging Face, Ollama, LM Studio

These local/self-hosted providers use **discovery-based defaults** rather than hardcoded model names:

- **Hugging Face**: Blank until user selects a model; example suggestions shown in placeholder
- **Ollama**: Uses whatever model is locally installed (typically `llama4` or first discovered model)
- **LM Studio**: Uses the currently loaded local model

## Default Lanes API

The plugin provides a programmatic API for accessing default models by lane:

```php
// Get the model service instance.
$model_service = new WP_MCP_AI_Model_Service();

// Get stable default for a provider.
$default = $model_service->get_default_model_for_provider( 'openai' );
// Returns: 'gpt-4.1'

// Get all lanes for a provider.
$lanes = $model_service->get_provider_default_models_by_lane( 'openai' );
// Returns: array(
//     'stable'    => 'gpt-4.1',
//     'latest'    => 'gpt-5.4',
//     'budget'    => 'gpt-5.4-mini',
//     'vision'    => 'gpt-4.1',
//     'image'     => 'gpt-image-1.5',
//     'audio_in'  => 'gpt-4o-mini-transcribe',
//     'audio_out' => 'gpt-4o-mini-tts',
// )
```

### Filtering Defaults

Both methods support WordPress filters for customization:

```php
// Override the stable default for a provider.
add_filter( 'wp_mcp_ai_default_model_for_provider', function( $default, $provider ) {
    if ( 'openai' === $provider ) {
        return 'gpt-5.4'; // Use latest as default.
    }
    return $default;
}, 10, 2 );

// Override lane defaults for a provider.
add_filter( 'wp_mcp_ai_provider_default_models_by_lane', function( $lanes, $provider ) {
    if ( 'cloudflare' === $provider ) {
        $lanes['stable'] = '@cf/openai/gpt-oss-120b';
    }
    return $lanes;
}, 10, 2 );
```

## Configuration

### Selecting Default Models

1. Navigate to **Settings → NV oOS → AI Provider Configuration**
2. Under each provider tab, select your preferred default model
3. Save changes

### Per-Assistant Overrides

Override the default model for individual assistants:

1. Edit an assistant (Custom Post Type)
2. Find the model selection field
3. Choose a specific model for that assistant
4. Save the assistant

### Provider Priority

The plugin supports automatic failover between providers:

1. Go to **Settings → NV oOS → Provider Priority Order**
2. Drag and drop providers to set priority
3. System will try providers in order if one fails

## Migration & Upgrade Behavior

### New Installs

New installations receive the latest recommended defaults automatically.

### Existing Installs

- **Existing saved settings are never overwritten on upgrade**
- Admin notices may suggest newer recommended models if the saved one is deprecated
- If a default model disappears from a provider, the fallback chain is: stable → budget → first available

### Upgrading from Older Defaults

If you were using older defaults, consider updating:

| Old Default | New Recommended | Why |
|-------------|----------------|-----|
| `whisper-1` | `gpt-4o-mini-transcribe` | Superior accuracy, actively maintained |
| `tts-1` | `gpt-4o-mini-tts` | More natural speech, better voice presets |
| `gpt-image-1` | `gpt-image-1.5` | Current state-of-the-art for image generation |
| `@cf/meta/llama-3.2-3b-instruct` | `@cf/meta/llama-4-scout-17b-16e-instruct` | Better quality, function calling support |
| `@cf/stabilityai/stable-diffusion-xl-base-1.0` | `@cf/black-forest-labs/flux-2-dev` | Significantly improved image quality |

## Cost Considerations

### Approximate Cost Tiers

| Tier | Models | Relative Cost |
|------|--------|--------------|
| **Budget** | `gpt-5.4-mini`, `claude-haiku-4-5`, `gemini-2.5-flash` | $ |
| **Standard** | `gpt-4.1`, `claude-sonnet-4-6`, `gemini-2.5-flash` | $$ |
| **Premium** | `gpt-5.4`, `claude-opus-4-6`, `gemini-2.5-pro` | $$$ |
| **Local** | Ollama, LM Studio models | Free (hardware cost only) |

### Optimization Tips

1. Use budget-lane models for routine tasks
2. Reserve premium models for complex reasoning
3. Set per-tool model preferences for automatic optimization
4. Monitor token usage in the admin dashboard
5. Enable high-token fallback to prevent overages

## Technical Details

### Model Recognition

The plugin recognizes models by their identifier strings:
- `gpt-*`: OpenAI routing
- `o1-*`, `o3-*`: OpenAI reasoning models
- `gemini-*`: Google Gemini routing
- `claude-*`: Anthropic routing
- `@cf/*`: Cloudflare Workers AI routing
- Other patterns: Routed based on provider configuration

### Fallback Behavior

When token limits are exceeded:
1. System checks for configured fallback model
2. Falls back to high-capacity model (default: `gemini-2.5-flash`)
3. Logs fallback event for monitoring
4. Continues request with fallback model

## References

- [OpenAI Models Documentation](https://developers.openai.com/api/docs/models)
- [Anthropic Claude Models](https://platform.claude.com/docs/en/about-claude/models/overview)
- [Google Gemini Models](https://ai.google.dev/models/gemini)
- [Cloudflare Workers AI Models](https://developers.cloudflare.com/workers-ai/models/)
- [NV oOS Model Service](../../../includes/services/class-wp-mcp-ai-model-service.php)
- [Provider Configuration](../../../includes/admin/sections/class-wp-mcp-ai-section-providers.php)
