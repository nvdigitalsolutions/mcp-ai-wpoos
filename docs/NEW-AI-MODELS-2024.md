# New AI Models Support (2024 Update)

This document outlines the new AI models added to WP Open Operator System in response to user requests for expanded model support.

## Overview

WP oOS now supports the latest AI models from OpenAI and Google Gemini, including advanced reasoning models ("thinking models") and experimental next-generation models.

## OpenAI Models

### Reasoning Models (o-series)

These models are designed for complex problem-solving, multi-step planning, and advanced reasoning tasks. They "think" through problems more deliberately than standard models.

| Model | Context Tokens | Output Tokens | Use Cases |
|-------|---------------|---------------|-----------|
| `o1-2024-12-17` | 200,000 | 100,000 | Latest o1 model with improved reasoning capabilities. Best for complex analysis, multi-step problem solving, and tasks requiring deep logical reasoning. |
| `o1-preview` | 128,000 | 32,768 | Preview version of the o1 reasoning model. Suitable for testing advanced reasoning capabilities. |
| `o1-mini` | 128,000 | 32,768 | Lighter, faster version of o1. Good balance between reasoning capability and response speed. |
| `o3-mini` | TBD | TBD | Upcoming compact reasoning model (placeholder for future release). |

#### When to Use Reasoning Models

- Complex problem-solving requiring multiple steps
- Code review and architectural decisions
- Research and analysis tasks
- Planning and strategy formulation
- Mathematical or logical puzzles
- Technical documentation analysis

#### Performance Considerations

- Reasoning models take longer to respond (they "think" before answering)
- Higher token costs compared to standard models
- Best used when quality of reasoning is more important than speed
- Not recommended for simple conversational tasks

### Standard Models (GPT-4o series)

| Model | Context Tokens | Output Tokens | Use Cases |
|-------|---------------|---------------|-----------|
| `gpt-4o` | 128,000 | 16,384 | Current flagship multimodal model. Best for production use. |
| `gpt-4o-mini` | 128,000 | 16,384 | Cost-optimized version. Recommended for day-to-day tasks. |

### Future Models (Placeholders)

The following models are included as placeholders for upcoming releases:

- `gpt-4.5-preview` - Next-generation flagship model
- `gpt-4.5-turbo` - Turbo variant of GPT-4.5
- `gpt-5` - Future major version
- `gpt-5-mini` - Cost-optimized GPT-5 variant

These will become available automatically when OpenAI releases them.

## Google Gemini Models

### Gemini 2.x Series (Latest)

| Model | Features | Use Cases |
|-------|----------|-----------|
| `gemini-2.0-flash-exp` | Latest experimental model from Google. Enhanced multimodal capabilities. | Testing cutting-edge features, multimodal tasks, high-performance requirements. |
| `gemini-exp-1206` | December 2024 experimental release. | Experimental features and improvements. |

### Gemini 1.5 Series (Stable)

| Model | Features | Use Cases |
|-------|----------|-----------|
| `gemini-1.5-pro` | Stable, proven performance. Large context window. | Production deployments, reliable performance requirements. |
| `gemini-1.5-flash` | Faster responses, lower cost. | High-throughput applications, cost-sensitive deployments. |
| `gemini-pro` | Original Gemini model. | Legacy compatibility. |

### Experimental vs Stable Models

- **Experimental models** (marked with `-exp`):
  - Latest features and capabilities
  - May have occasional breaking changes
  - Best for testing and development
  - Higher performance but less predictable

- **Stable models** (1.5 series):
  - Proven reliability
  - Consistent behavior
  - Recommended for production
  - Lower risk, predictable costs

## Configuration

### Selecting Default Models

1. Navigate to **Settings → WP oOS → AI Provider Configuration**
2. Under **OpenAI**, select your preferred default model
3. Under **Google Gemini**, select your preferred Gemini model
4. Save changes

### Per-Assistant Overrides

You can override the default model for individual assistants:

1. Edit an assistant (Custom Post Type)
2. Find the model selection field
3. Choose a specific model for that assistant
4. Save the assistant

### Provider Priority

The plugin supports automatic failover between providers:

1. Go to **Settings → WP oOS → Provider Priority Order**
2. Drag and drop providers to set priority
3. System will try providers in order if one fails

## Cost Considerations

### Token Costs (Approximate)

- **o1-series reasoning models**: 3-5x more expensive than GPT-4o
- **gpt-4o**: Standard pricing
- **gpt-4o-mini**: ~10x cheaper than GPT-4o
- **Gemini 2.0**: Competitive with GPT-4o
- **Gemini 1.5 Flash**: Most cost-effective option

### Optimization Tips

1. Use `gpt-4o-mini` or `gemini-1.5-flash` for routine tasks
2. Reserve reasoning models (`o1-*`) for complex problems
3. Set per-tool model preferences for automatic optimization
4. Monitor token usage in the admin dashboard

## Migration Guide

### Upgrading from Previous Versions

No migration is required. The plugin automatically includes new models:

1. Update WP oOS to latest version
2. New models appear in dropdowns automatically
3. Existing assistants continue working with current settings
4. Test new models before changing defaults

### Best Practices

1. **Start with stable models**: Use `gpt-4o` or `gemini-1.5-pro` for production
2. **Test experimental models separately**: Create dedicated test assistants
3. **Monitor costs**: Track token usage when trying new models
4. **Document changes**: Note which models work best for your use cases

## Technical Details

### Model Recognition

The plugin recognizes models by their identifier strings:
- `o1-*`: Routing to OpenAI with reasoning optimization
- `gpt-*`: Standard OpenAI routing
- `gemini-*`: Google Gemini routing
- `claude-*`: Anthropic routing (if configured)

### Fallback Behavior

When token limits are exceeded:
1. System checks for configured fallback model
2. Falls back to high-capacity model (default: `gemini-2.0-flash-exp`)
3. Logs fallback event for monitoring
4. Continues request with fallback model

### API Compatibility

All new models use the same API endpoints:
- OpenAI: Chat Completions API or Responses API (auto-selected)
- Gemini: generateContent endpoint

No code changes required for new models.

## Troubleshooting

### "Model not found" Error

**Solution**: Ensure your API key has access to the model. Some models (like o1-2024-12-17) require specific API access tiers.

### Slow Responses with Reasoning Models

**Expected**: Reasoning models take longer to respond. This is by design - they're "thinking" through the problem.

**Solution**: Use reasoning models only when needed. For faster responses, use standard models.

### Unexpected Costs

**Solution**: 
- Check which model is being used (admin logs)
- Set per-tool model preferences to control costs
- Use token budget management features
- Enable high-token fallback to prevent overages

## Future Updates

The plugin is designed to automatically support new models as they're released:

- New model identifiers work without code changes
- CCT (Custom Content Type) integration allows dynamic model management
- Filter hooks available for custom model routing

## Support

For issues or questions about new models:

1. Check this documentation
2. Review admin error logs
3. Test with known-working models
4. Open issue on GitHub with model identifier and error details

## References

- [OpenAI Models Documentation](https://platform.openai.com/docs/models)
- [Google Gemini Models](https://ai.google.dev/models/gemini)
- [WP oOS Model Selector Code](../includes/class-wp-mcp-ai-model-selector.php)
- [Provider Configuration](../includes/admin/sections/class-wp-mcp-ai-section-providers.php)
