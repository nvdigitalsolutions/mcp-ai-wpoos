# GPT-4o Realtime API - December 2024 & 2025 Updates

## Overview

OpenAI released major updates to the GPT-4o Realtime API in December 2024 and continues with 2025 snapshots, bringing significant improvements in pricing, features, and capabilities. The WP oOS plugin now supports all realtime model families including the new GPT Realtime Mini.

## Model Families

### 1. GPT-4o Realtime Preview (December 2024 - June 2025)

Available snapshots:
- `gpt-4o-realtime-preview` (generic alias)
- `gpt-4o-realtime-preview-2024-12-17` (Dec 2024)
- `gpt-4o-realtime-preview-2025-01-06` (Jan 2025)
- `gpt-4o-realtime-preview-2025-06-03` (Jun 2025 - latest)

**Key Features**:
- 60% cheaper than previous versions
- WebRTC support for low-latency voice conversations
- Improved voice quality
- Extended session length (up to 30 minutes)
- Enhanced interruption handling
- Sub-second latency (~300-500ms)
- 128,000 token context window

### 2. GPT-4o Mini Realtime Preview (December 2024)

Available snapshots:
- `gpt-4o-mini-realtime-preview` (generic alias)
- `gpt-4o-mini-realtime-preview-2024-12-17` (Dec 2024)

**Key Features**:
- 10x cheaper than standard realtime models
- Cost-effective for large-scale deployments
- Surprisingly good voice quality
- Same technical capabilities as standard model
- 128,000 token context window

### 3. GPT-4o Audio Preview (December 2024)

Available snapshots:
- `gpt-4o-audio-preview` (generic alias)
- `gpt-4o-audio-preview-2024-12-17` (Dec 2024)

**Key Features**:
- Enhanced audio processing capabilities
- Better text/audio input handling
- Improved transcription accuracy
- 128,000 token context window

### 4. GPT Realtime Mini (December 2025 - NEW)

Available snapshots:
- `gpt-realtime-mini` (generic alias)
- `gpt-realtime-mini-2025-12-15` (Dec 2025)

**Key Features**:
- New naming convention (no "4o" prefix)
- 32,000 token context window (optimized for real-time)
- Dramatically cheaper cached input ($0.30 vs $2.00)
- Same pricing as 4o-mini-realtime for non-cached
- Improved instruction following (+18.6%)
- Enhanced tool calling (+12.9%)

## Pricing

### Standard Realtime Models (gpt-4o-realtime-preview)
- **Audio Input**: $100 per 1M tokens
- **Audio Output**: $200 per 1M tokens
- **Cached Audio Input**: $20 per 1M tokens (5x cheaper)

### Mini Realtime Models (gpt-4o-mini-realtime-preview)
- **Audio Input**: $10 per 1M tokens (10x cheaper)
- **Audio Output**: $20 per 1M tokens (10x cheaper)
- **Cached Audio Input**: $2 per 1M tokens

### GPT Realtime Mini (gpt-realtime-mini)
- **Audio Input**: $10 per 1M tokens
- **Audio Output**: $20 per 1M tokens
- **Cached Audio Input**: $0.30 per 1M tokens (66x cheaper than standard!)

> **Note**: Audio tokens are different from text tokens. Approximately 1 minute of audio = ~1,500 tokens.

## Technical Specifications

### Rate Limits
- **Standard Models**:
  - TPM (Tokens Per Minute): 20,000
  - RPM (Requests Per Minute): 100
  - TPD (Tokens Per Day): 1,000,000
  - RPD (Requests Per Day): 5,000

- **Mini Models**:
  - TPM: 40,000 (2x higher)
  - RPM: 200 (2x higher)
  - TPD: 2,000,000 (2x higher)
  - RPD: 10,000 (2x higher)

### Context Window
All realtime models support **128,000 tokens** context window.

### Fallback Models
- `gpt-4o-realtime-preview-2024-12-17` → `gpt-4o-realtime-preview`
- `gpt-4o-mini-realtime-preview-2024-12-17` → `gpt-4o-mini-realtime-preview`
- `gpt-4o-audio-preview-2024-12-17` → `gpt-4o-audio-preview`

## Key Improvements

### WebRTC Support
The December 2024 update introduced WebRTC support, making it easier to build real-time voice applications:
- Direct browser-to-API connections
- Lower latency
- Simplified implementation (just a few lines of code)
- Better support for mobile and IoT devices

### Enhanced Features
1. **Longer Sessions**: Up to 30 minutes per session (previously limited)
2. **More Voices**: Additional voice options and audio configurations
3. **Better Interruption Handling**: More natural conversation flow
4. **Improved Quality**: Better voice synthesis and recognition
5. **Prompt Caching**: Significant cost savings for repeated contexts
6. **Deterministic Tool Calling**: JSON-based function calling
7. **Configurable Speech Rate**: Control speaking speed

## Using Realtime Models in WP oOS

### Model Selection
The new realtime models are available in:
1. **Assistant Settings** → Model dropdown
2. **Orchestration Presets** → Model configuration
3. **REST API** → Specify model parameter

### Example Use Cases

#### Voice Chat Assistant
```php
$assistant_id = 123; // Your assistant ID
$settings = array(
    'model' => 'gpt-4o-mini-realtime-preview-2024-12-17', // Cost-effective choice
    'voice_enabled' => true,
    'max_tokens' => 4096
);
// Use with voice chat widget
```

#### Audio Transcription Service
```php
$model = 'gpt-4o-audio-preview-2024-12-17'; // Best for audio processing
// Use with transcription tools
```

#### Real-time Customer Support
```php
$model = 'gpt-4o-realtime-preview-2024-12-17'; // Best quality
// Integrate with WebRTC for live support
```

## Cost Optimization Tips

### 1. Use Mini Models When Appropriate
For many use cases, the mini models provide excellent quality at 10x lower cost:
- Customer support chatbots
- Voice assistants for simple tasks
- High-volume applications
- Testing and development

**New in 2025**: `gpt-realtime-mini` offers the same input/output pricing as `gpt-4o-mini-realtime-preview` but with 85% cheaper cached input ($0.30 vs $2.00), making it ideal for applications with repeated prompts.

### 2. Leverage Prompt Caching
Repeated system prompts and contexts benefit from dramatically cheaper cached input pricing:
- **Standard models**: 5x cheaper ($20 vs $100)
- **Mini models**: 5x cheaper ($2 vs $10)
- **Realtime Mini**: 33x cheaper ($0.30 vs $10) ⭐ Best for caching

Use cases for caching:
- Define stable system instructions
- Reuse conversation context
- Cache frequently accessed data
- Long-running conversations with consistent prompts

### 3. Choose the Right Model
- **gpt-4o-realtime-preview-2025-06-03**: Latest snapshot, premium voice quality, complex interactions
- **gpt-4o-mini-realtime-preview-2024-12-17**: High volume, simpler conversations, 10x cheaper
- **gpt-realtime-mini-2025-12-15**: Best for cached prompts, 32K context, improved instruction following
- **gpt-4o-audio-preview-2024-12-17**: Transcription and audio analysis

### 4. Monitor Token Usage
Track audio token consumption using WP oOS's built-in analytics:
- Settings → Performance → Token Usage
- Cost Calculator integration
- Real-time cost tracking

## Migration Guide

### From Previous Realtime Models

If you're using older realtime models, consider upgrading to the latest snapshots:

**Benefits of 2025 models**:
- 60% cost reduction (vs pre-December 2024)
- Better voice quality
- More features (WebRTC, longer sessions)
- Improved instruction following
- Enhanced tool calling

**Recommended Upgrades**:
```php
// From generic to latest standard model
'model' => 'gpt-4o-realtime-preview-2025-06-03'  // June 2025 - latest

// For cost optimization with caching
'model' => 'gpt-realtime-mini-2025-12-15'  // 85% cheaper cached input

// For high-volume, non-cached
'model' => 'gpt-4o-mini-realtime-preview-2024-12-17'  // 10x cheaper
```

### Cost Impact Analysis

Example monthly cost for 1,000 hours of audio:
- 1,000 hours = 60,000 minutes
- ~60,000 minutes × 1,500 tokens/min = 90M tokens
- Assume 30% of input is cached (typical for conversational agents)

**Previous pricing** (pre-December 2024, estimated):
- Input: 90M × $0.25/1M = $22,500
- Output: 90M × $0.50/1M = $45,000
- **Total**: ~$67,500

**December 2024 standard pricing** (gpt-4o-realtime-preview-2024-12-17):
- Input: 90M × $0.10/1M = $9,000
- Output: 90M × $0.20/1M = $18,000
- **Total**: $27,000 (60% savings)

**December 2024 mini pricing** (gpt-4o-mini-realtime-preview-2024-12-17):
- Input: 90M × $0.01/1M = $900
- Output: 90M × $0.02/1M = $1,800
- **Total**: $2,700 (96% savings)

**December 2025 realtime mini with caching** (gpt-realtime-mini-2025-12-15):
- Fresh input (70%): 63M × $0.01/1M = $630
- Cached input (30%): 27M × $0.0003/1M = $8.10
- Output: 90M × $0.02/1M = $1,800
- **Total**: $2,438 (96.4% savings)


**Mini model pricing**:
- Input: 90M × $0.01/1M = $900
- Output: 90M × $0.02/1M = $1,800
- **Total**: $2,700 (96% savings vs. previous, 90% savings vs. new standard)

## WebRTC Integration (Future)

WebRTC support is a major addition to the Realtime API. While WP oOS doesn't yet have native WebRTC integration, we're planning to add this capability.

### Planned Features
- [ ] WebRTC client library integration
- [ ] Low-latency voice chat widget
- [ ] Direct browser-to-API connections
- [ ] Mobile app support via WebRTC
- [ ] Real-time collaboration features

### Current Workarounds
Until native WebRTC support is added, you can:
1. Use the existing REST API with polling
2. Implement custom WebRTC in your theme/plugin
3. Use OpenAI's official WebRTC examples as reference

## Troubleshooting

### Common Issues

#### High Token Usage
- Audio tokens are consumed faster than text tokens
- Monitor usage in Settings → Performance
- Use mini models for high-volume scenarios
- Implement audio compression if possible

#### Rate Limiting
- Realtime models have different rate limits than text models
- Standard: 20,000 TPM
- Mini: 40,000 TPM
- Distribute load across multiple API keys if needed

#### Quality Concerns
- Mini models provide excellent quality for most use cases
- Standard models offer premium quality for critical applications
- Test both to find the right balance

## Additional Resources

- [OpenAI Realtime API Documentation](https://platform.openai.com/docs/models/gpt-4o-realtime-preview)
- [OpenAI WebRTC Guide](https://platform.openai.com/docs/guides/realtime-webrtc)
- [OpenAI Community Forum - Realtime API Updates](https://community.openai.com/t/realtime-api-updates-webrtc-cheaper-prices-4o-mini-and-more/1059962)
- [WP oOS Audio Tools Documentation](../../tools/audio-tools.md)

## Version History

### December 2025 - New Model Family
- Added `gpt-realtime-mini` family (new naming convention)
- Added `gpt-realtime-mini-2025-12-15` snapshot
- 32K context window (optimized for real-time)
- Dramatically cheaper cached input ($0.30 per 1M tokens)
- Improved instruction following (+18.6%)
- Enhanced tool calling (+12.9%)

### 2025 Snapshots - Continuous Improvements
- Added `gpt-4o-realtime-preview-2025-01-06` (January 2025)
- Added `gpt-4o-realtime-preview-2025-06-03` (June 2025 - latest)
- Ongoing voice quality improvements
- Enhanced feature support
- Performance optimizations

### December 2024 - Major Update
- Added `gpt-4o-realtime-preview-2024-12-17`
- Added `gpt-4o-mini-realtime-preview-2024-12-17`
- Added `gpt-4o-audio-preview-2024-12-17`
- Updated pricing (60% reduction vs previous)
- Documented WebRTC support
- Enhanced model configurations
- Extended session length (30 minutes)

### Support
For questions or issues related to GPT-4o Realtime API:
- Check WP oOS documentation
- Visit OpenAI's support resources
- Report bugs via GitHub issues

---

Last updated: December 27, 2024
