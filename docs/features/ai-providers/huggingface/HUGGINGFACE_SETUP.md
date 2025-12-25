# Hugging Face Provider Setup Guide

This guide explains how to configure and use Hugging Face Inference API as an AI provider in WP oOS.

## Overview

Hugging Face Inference API provides access to thousands of open-source language models through an OpenAI-compatible interface. WP oOS integrates seamlessly with Hugging Face, allowing you to use models like:

- **Llama models**: `meta-llama/Llama-3.3-70B-Instruct`, `meta-llama/Llama-3.1-8B-Instruct`
- **Mistral models**: `mistralai/Mistral-7B-Instruct-v0.3`, `mistralai/Mixtral-8x7B-Instruct-v0.1`
- **Phi models**: `microsoft/Phi-3-mini-4k-instruct`
- **Qwen models**: `Qwen/Qwen2.5-72B-Instruct`
- And thousands more from the Hugging Face Hub

## Getting Started

### Step 1: Get a Hugging Face API Token

1. **Create an account** at [huggingface.co](https://huggingface.co/join)
2. **Navigate to Settings** → [Access Tokens](https://huggingface.co/settings/tokens)
3. **Create a new token**:
   - Click "New token"
   - Give it a descriptive name (e.g., "WP oOS")
   - Select "Inference" permission
   - Click "Generate token"
4. **Copy the token** - it will start with `hf_`

### Step 2: Configure WP oOS

1. **Navigate to** WordPress Admin → **WP oOS** → **Providers**
2. **Click on the "Hugging Face" subtab**
3. **Configure the settings**:

```
Enable Hugging Face Provider: ✓ Checked
Hugging Face API Key: hf_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Hugging Face Endpoint URL: https://api-inference.huggingface.co/v1
Hugging Face Model: meta-llama/Llama-3.3-70B-Instruct
```

4. **Save Changes**

### Step 3: Test the Connection

After saving your settings:
1. The system will automatically test the connection
2. You should see a success message if everything is configured correctly
3. If there are errors, check:
   - API token is valid and has "Inference" permissions
   - Endpoint URL is correct
   - Model identifier is valid

## Configuration Options

### API Key
- **Required**: Yes
- **Format**: Token starting with `hf_`
- **Where to get**: https://huggingface.co/settings/tokens
- **Permissions needed**: "Inference"

### Endpoint URL
- **Required**: Yes
- **Default**: `https://api-inference.huggingface.co/v1`
- **Custom options**:
  - Use default for public Inference API
  - Provide custom endpoint for Inference Endpoints
  - Provide private deployment URL for enterprise

### Model
- **Required**: Yes
- **Format**: `organization/model-name`
- **Examples**:
  - `meta-llama/Llama-3.3-70B-Instruct` (recommended)
  - `mistralai/Mistral-7B-Instruct-v0.3`
  - `microsoft/Phi-3-mini-4k-instruct`
- **Requirements**: Model must:
  - Support chat/instruction format
  - Have a `chat_template` defined in its tokenizer config
  - Be publicly accessible or you must have access

## Choosing a Model

### Recommended Models

**For General Use (Best Balance)**:
- `meta-llama/Llama-3.3-70B-Instruct` - Excellent quality, good speed
- `mistralai/Mixtral-8x7B-Instruct-v0.1` - Fast and capable

**For Speed (Lower Cost)**:
- `meta-llama/Llama-3.1-8B-Instruct` - Fast, economical
- `mistralai/Mistral-7B-Instruct-v0.3` - Very fast
- `microsoft/Phi-3-mini-4k-instruct` - Compact and efficient

**For Maximum Quality**:
- `meta-llama/Llama-3.3-70B-Instruct` - Currently recommended
- `Qwen/Qwen2.5-72B-Instruct` - Excellent reasoning

### Finding Models

Browse available models at:
- https://huggingface.co/models?pipeline_tag=text-generation&library=transformers&sort=trending
- Filter by "Text Generation" task
- Look for models with chat/instruct variants
- Check model cards for API compatibility

## Provider Priority

Hugging Face can be used as:

1. **Primary Provider**: Set it first in the priority list
2. **Fallback Provider**: Place it after other providers for redundancy
3. **Cost-Effective Alternative**: Use when you need open-source models

To configure priority:
1. Go to **WP oOS** → **Providers** → **Priority Order**
2. Drag and drop providers to reorder
3. The system tries providers in order when one fails

## Usage in Assistants

### Selecting Hugging Face for an Assistant

When creating or editing an assistant:

1. **Set Provider**: Select "Hugging Face" from the provider dropdown
2. **Optional Model Override**: Specify a different model than the default
3. **Configure Settings**: Temperature, max tokens, etc.

### Example Assistant Configuration

```
Name: Code Helper
Provider: Hugging Face
Model: meta-llama/Llama-3.3-70B-Instruct
Temperature: 0.7
System Prompt: You are an expert programmer who helps with code questions.
```

## Inference Endpoints (Advanced)

For production workloads, consider using Hugging Face Inference Endpoints:

### Benefits
- **Dedicated Resources**: Not shared with other users
- **Auto-scaling**: Handles traffic spikes automatically
- **Better Performance**: Faster response times
- **Private Models**: Use your own fine-tuned models
- **SLA Guarantees**: Enterprise-grade reliability

### Setup
1. **Deploy an endpoint** at https://ui.endpoints.huggingface.co/
2. **Get the endpoint URL** (e.g., `https://xxxxx.us-east-1.aws.endpoints.huggingface.cloud/v1`)
3. **Update WP oOS settings**:
   - Endpoint URL: Your custom endpoint URL
   - Model: Your deployed model name
   - API Key: Same Hugging Face token

## Troubleshooting

### Common Issues

**"No Hugging Face API key has been configured"**
- Ensure you've entered your API token in the settings
- Token should start with `hf_`
- Check that you saved the settings

**"No Hugging Face model has been configured"**
- Enter a valid model identifier (format: `organization/model-name`)
- Check that the model exists on Hugging Face Hub
- Ensure model supports chat/instruct format

**"The Hugging Face API returned malformed JSON"**
- Model may still be loading (try again in a moment)
- Check that model supports the Inference API
- Verify endpoint URL is correct

**"Model is currently loading"**
- Some models need to "wake up" on first request
- Wait 30-60 seconds and try again
- Consider using Inference Endpoints for instant availability

**Rate Limiting**
- Free tier has rate limits
- Consider upgrading to Pro for higher limits
- Or use Inference Endpoints for dedicated capacity

### Checking Logs

Enable logging in **WP oOS** → **Settings** → **Enable Logging**, then:

```bash
# View recent errors
wp option get wp_mcp_ai_recent_errors --format=json

# View recent activity
wp option get wp_mcp_ai_recent_activity --format=json

# Filter for Hugging Face events
wp option get wp_mcp_ai_recent_activity --format=json | jq '.[] | select(.event | contains("huggingface"))'
```

## API Limits and Pricing

### Free Tier
- Rate limited to prevent abuse
- Shared inference infrastructure
- Models may have cold starts
- Best for development and testing

### Pro Subscription
- Higher rate limits
- Priority inference
- Early access to new features
- ~$9/month

### Inference Endpoints
- Pay-per-use pricing
- Starts at ~$0.06/hour for small models
- Scales with usage
- No cold starts
- [Pricing calculator](https://huggingface.co/pricing#endpoints)

## Best Practices

### Performance
1. **Use appropriate models**: Smaller models for simple tasks, larger for complex reasoning
2. **Set reasonable max_tokens**: Don't request more than you need
3. **Cache responses**: Enable caching when possible
4. **Consider Inference Endpoints**: For production workloads

### Cost Optimization
1. **Start with smaller models**: Test with 7B-8B models before using 70B+
2. **Use fallback providers**: Configure provider priority for cost management
3. **Monitor usage**: Track token consumption
4. **Batch requests**: When possible, combine multiple operations

### Security
1. **Keep API tokens secret**: Never commit tokens to version control
2. **Use environment variables**: For production deployments
3. **Rotate tokens regularly**: Generate new tokens periodically
4. **Limit token permissions**: Only grant "Inference" permission
5. **Monitor for abuse**: Check logs for unusual activity

## Support and Resources

### Documentation
- **Hugging Face Docs**: https://huggingface.co/docs/inference-endpoints
- **Messages API**: https://huggingface.co/docs/text-generation-inference/messages_api
- **Model Hub**: https://huggingface.co/models

### Community
- **Hugging Face Forums**: https://discuss.huggingface.co/
- **Discord**: https://hf.co/join/discord
- **WP oOS Support**: Via GitHub issues

### Getting Help

If you encounter issues:
1. Check this documentation
2. Review the troubleshooting section
3. Enable logging and check error messages
4. Search the WP oOS GitHub issues
5. Open a new issue with:
   - Error messages from logs
   - Model identifier used
   - Endpoint URL (without sensitive data)
   - Steps to reproduce

## Comparison with Other Providers

| Feature | Hugging Face | OpenAI | Anthropic |
|---------|-------------|---------|-----------|
| **Cost** | Low (open-source) | Medium-High | Medium-High |
| **Model Variety** | Thousands | Limited | Limited |
| **Response Quality** | Varies by model | Excellent | Excellent |
| **Latency** | Varies | Fast | Fast |
| **Privacy** | Good (can self-host) | Cloud only | Cloud only |
| **Tool Calling** | Limited support | Full support | Full support |
| **Customization** | High (fine-tuning) | Limited | None |

### When to Use Hugging Face

✅ **Good for**:
- Cost-conscious deployments
- Open-source model requirements
- Experimentation with different models
- Fine-tuning custom models
- Privacy-sensitive applications (with self-hosting)

❌ **Not ideal for**:
- Applications requiring guaranteed uptime (use Inference Endpoints instead)
- Complex function calling (limited model support)
- Consistent quality across requests (model-dependent)

## Next Steps

1. **Configure your first assistant** with Hugging Face
2. **Test different models** to find the best fit
3. **Set up provider fallback** for reliability
4. **Monitor usage** and adjust as needed
5. **Consider Inference Endpoints** for production

For more information, see the main WP oOS documentation at `/docs/README.md`.
