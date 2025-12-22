# Token Counting Feature

## Overview

The WP oOS plugin now includes a `count_tokens` tool that allows AI assistants to accurately estimate token counts for text and messages before making API calls. This helps with:

- **Budget Management**: Stay within model context limits
- **Cost Estimation**: Predict API costs before making requests
- **Request Planning**: Optimize message sizes and avoid truncation

## Key Features

### Dual Counting Methods

The tool supports two counting methods:

1. **tiktoken (Accurate)** - Uses OpenAI's official Byte Pair Encoding tokenizer
   - Exact token counts matching OpenAI's models
   - Supports GPT-4, GPT-4o, GPT-3.5-turbo, and more
   - Requires `rahul900day/tiktoken-php` composer package

2. **heuristic (Fast)** - Uses ~4 characters per token estimation
   - Very fast, no dependencies
   - Good for quick estimates
   - Less accurate for non-English text

3. **auto (Default)** - Tries tiktoken first, falls back to heuristic
   - Best of both worlds
   - Always works even without tiktoken installed

## Background: Why No API Endpoint?

**OpenAI does NOT provide a dedicated token counting API endpoint.** 

While the community has proposed such an endpoint (`/v1/count_tokens`), it hasn't been implemented by OpenAI yet. Instead, OpenAI recommends:

- Using their `tiktoken` library for accurate client-side token counting
- Checking the `usage` field in API responses for actual token consumption
- For streaming calls, use `stream_options: {"include_usage": true}` (API v1.26.0+)

## Installation

### For Accurate Token Counting (tiktoken)

The tiktoken-php library is included in composer dependencies:

```bash
composer install
```

This installs `rahul900day/tiktoken-php` which provides OpenAI-compatible tokenization.

### Verify Installation

```bash
composer show rahul900day/tiktoken-php
```

## Usage Examples

### Count Tokens for Plain Text

```json
{
  "text": "This is a message to count tokens for.",
  "model": "gpt-4o-mini"
}
```

**Response:**
```json
{
  "estimated_tokens": 8,
  "counting_method": "tiktoken",
  "details": {
    "type": "text",
    "text_length": 40
  },
  "model_info": {
    "model": "gpt-4o-mini",
    "context_limit_tokens": 128000,
    "usage_percentage": 0.01
  },
  "budget_info": {
    "safe_limit_tokens": 115200,
    "remaining_tokens": 115192,
    "exceeds_safe_limit": false,
    "recommendation": "Token count is within safe limits."
  },
  "disclaimer": "Token count calculated using OpenAI's tiktoken tokenizer for accurate results."
}
```

### Count Tokens for Chat Messages

```json
{
  "messages": [
    {
      "role": "system",
      "content": "You are a helpful assistant."
    },
    {
      "role": "user",
      "content": "What is the weather like today?"
    },
    {
      "role": "assistant",
      "content": "I don't have access to real-time weather information."
    }
  ],
  "model": "gpt-4",
  "method": "tiktoken"
}
```

**Response:**
```json
{
  "estimated_tokens": 38,
  "counting_method": "tiktoken",
  "details": {
    "type": "messages",
    "message_count": 3
  },
  "model_info": {
    "model": "gpt-4",
    "context_limit_tokens": 8192,
    "usage_percentage": 0.46
  },
  "budget_info": {
    "safe_limit_tokens": 7373,
    "remaining_tokens": 7335,
    "exceeds_safe_limit": false,
    "recommendation": "Token count is within safe limits."
  },
  "disclaimer": "Token count calculated using OpenAI's tiktoken tokenizer for accurate results."
}
```

### Using Heuristic Method (Fallback)

```json
{
  "text": "Quick estimate without tiktoken.",
  "method": "heuristic"
}
```

**Response:**
```json
{
  "estimated_tokens": 8,
  "counting_method": "heuristic",
  "details": {
    "type": "text",
    "text_length": 32
  },
  "disclaimer": "This is a heuristic estimation (~4 chars per token). For more accurate counts, use method=\"tiktoken\" or ensure the tiktoken-php library is installed."
}
```

## Tool Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `text` | string | No* | Plain text to count tokens for. Mutually exclusive with `messages`. |
| `messages` | array | No* | Chat messages array with `role` and `content` fields. Mutually exclusive with `text`. |
| `model` | string | No | Model identifier (e.g., `gpt-4o`, `gpt-4o-mini`). Returns context limits and budget info when provided. |
| `method` | string | No | Counting method: `tiktoken`, `heuristic`, or `auto` (default). |

*Either `text` or `messages` must be provided.

## Message Format for Token Counting

When counting tokens for chat messages, each message should have:

```json
{
  "role": "system|user|assistant|tool",
  "content": "Message text content"
}
```

The tool automatically accounts for:
- Message formatting tokens (3 tokens per message for `<im_start>`, `<im_end>`)
- Role tokens
- Content tokens
- Priming tokens (3 tokens for assistant reply)

## Model Support

The tiktoken method supports common OpenAI models:

| Model Family | Encoding | Supported |
|--------------|----------|-----------|
| gpt-4o, gpt-4o-mini | o200k_base | ✅ Yes |
| gpt-4, gpt-4-turbo | cl100k_base | ✅ Yes |
| gpt-3.5-turbo | cl100k_base | ✅ Yes |
| text-davinci-* | p50k_base | ✅ Yes |

For models not explicitly mapped, the tool defaults to `cl100k_base` encoding.

## Budget Management Features

When a model is specified, the tool provides:

### Context Limits
- Total context window in tokens
- TPM (Tokens Per Minute) rate limit (if configured)
- RPM (Requests Per Minute) rate limit (if configured)

### Safety Recommendations
- Safe limit with 10% safety margin
- Remaining tokens available
- Warning if count exceeds safe limit
- Actionable recommendations for token reduction

## Use Cases

### 1. Pre-Flight Token Checks
Before making an expensive API call, count tokens to ensure you're within limits:

```javascript
// Check token count first
const tokenCheck = await executeToolCountTokens({
  messages: conversationHistory,
  model: "gpt-4o-mini"
});

if (tokenCheck.budget_info.exceeds_safe_limit) {
  // Truncate messages or switch to larger model
  console.warn(tokenCheck.budget_info.recommendation);
}
```

### 2. Dynamic Model Selection
Choose the appropriate model based on input size:

```javascript
const tokenCount = await countTokens({ text: userInput });

const model = tokenCount.estimated_tokens > 50000 
  ? "gpt-4o"  // Large context window
  : "gpt-4o-mini";  // Cost-effective for smaller inputs
```

### 3. Batch Processing
Estimate costs before processing large document batches:

```javascript
const documents = [...]; // Array of documents
let totalTokens = 0;

for (const doc of documents) {
  const result = await countTokens({ text: doc });
  totalTokens += result.estimated_tokens;
}

const estimatedCost = (totalTokens / 1000) * modelPricePerK;
console.log(`Estimated cost: $${estimatedCost.toFixed(2)}`);
```

## Performance Considerations

### Tiktoken Method
- Fast: ~1-2ms for short texts, ~10-20ms for long documents
- Memory efficient
- One-time library load, then cached

### Heuristic Method
- Very fast: <1ms
- No dependencies
- Good for quick estimates

## Error Handling

The tool returns `WP_Error` objects for:

- **Missing authentication**: User must be logged in
- **Invalid arguments**: Neither text nor messages provided, or both provided
- **Tiktoken unavailable**: When method="tiktoken" but library not installed
- **Tiktoken errors**: Exception during tokenization

## Security

- **Authentication required**: Only logged-in users can access the tool
- **No capability restrictions**: All authenticated users can count tokens (it's a read-only utility)
- **Input sanitization**: All text input is sanitized using WordPress functions

## References

- [OpenAI Cookbook: Token Counting](https://cookbook.openai.com/examples/how_to_count_tokens_with_tiktoken)
- [tiktoken-php GitHub](https://github.com/RahulDey12/tiktoken-php)
- [OpenAI API Documentation](https://platform.openai.com/docs/models)
- [Community Discussion: Token Counting Endpoint Proposal](https://community.openai.com/t/proposal-introducing-an-api-endpoint-for-token-count-and-cost-estimation/664585)

## Limitations

1. **No API endpoint from OpenAI**: Token counting happens client-side
2. **Model updates**: New models may require library updates for accurate encoding
3. **Multimodal tokens**: Image and audio tokens are not counted by this tool
4. **Special tokens**: Some special tokens may not be counted identically to OpenAI's servers

## Future Enhancements

Potential improvements:
- [ ] Cache token counts for repeated text
- [ ] Batch token counting for multiple texts
- [ ] Support for image/audio token estimation
- [ ] Integration with WP_MCP_AI_Token_Budget_Manager for automatic truncation
- [ ] Token count visualization in the admin UI
