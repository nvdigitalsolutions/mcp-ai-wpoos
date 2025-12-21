# LM Studio Integration Guide

## Overview

LM Studio is a local AI model hosting platform that provides an OpenAI-compatible API. The WP oOS plugin includes full support for LM Studio as an AI provider, allowing you to run AI models locally on your machine or private network.

## Features

- **OpenAI-Compatible API**: LM Studio implements the OpenAI API format, making integration seamless
- **Local Execution**: Run models on your own hardware for privacy and cost control
- **No API Keys Required**: No external API keys or cloud services needed
- **Network Flexibility**: Support for localhost, LAN, and VPN configurations
- **Automatic Fallback**: Part of the provider priority system with automatic failover

## Installation & Configuration

### 1. Install and Run LM Studio

1. Download LM Studio from [lmstudio.ai](https://lmstudio.ai/)
2. Install and launch LM Studio
3. Download a model (e.g., Llama 3, Mistral, etc.)
4. Load the model in LM Studio
5. Start the local server (typically runs on `http://localhost:1234`)

### 2. Configure WP oOS Plugin

Navigate to **Settings → WP oOS → Providers → LM Studio**:

1. **Enable LM Studio Provider**: ✅ Check this box
2. **LM Studio Endpoint URL**: `http://localhost:1234`
   - For same machine: `http://localhost:1234`
   - For LAN: `http://192.168.x.x:1234`
   - For tunneled/VPN: Use appropriate IP/hostname
3. **LM Studio Model**: Enter the model identifier shown in LM Studio
   - Example: `llama-3-8b-instruct`
   - Some setups accept: `local-model`
4. **Network Interface** (Optional): Leave empty for most setups

### 3. Test Connection

Click the **"Test LM Studio Connection"** button to verify:
- Server is accessible
- Model is loaded
- API is responding

### 4. Fetch Available Models

Click **"Fetch Models"** to see all available models from your LM Studio instance.

## Usage

### In Assistants

When creating or editing an assistant:

1. Set **Provider** to `LM Studio`
2. Optionally specify a specific model
3. Configure other settings (temperature, max tokens, etc.)

### In Chat Client

LM Studio will be used based on your provider priority list. To use it exclusively:

```php
$options = array(
    'provider' => 'lm_studio',
);
```

### Provider Priority

Configure provider priority at **Settings → WP oOS → Providers → Priority Order**:

Default order:
1. OpenAI
2. Anthropic
3. Gemini
4. Ollama
5. LM Studio

Drag and drop to reorder. The system tries providers in order until one succeeds.

## API Endpoints

LM Studio exposes OpenAI-compatible endpoints:

- **Models**: `GET /v1/models`
- **Chat Completions**: `POST /v1/chat/completions`
- **Completions**: `POST /v1/completions`

The plugin automatically constructs these URLs from your base endpoint.

## Network Configuration

### Same Machine (Localhost)

```
Endpoint: http://localhost:1234
```

This is the simplest setup when WordPress and LM Studio run on the same machine.

### Private Network (LAN)

```
Endpoint: http://192.168.1.100:1234
```

When WordPress is on a different machine in the same network:
1. Ensure LM Studio is configured to listen on all interfaces
2. Check firewall allows port 1234
3. Use the LAN IP of the LM Studio machine

### Remote WordPress + Local LM Studio

For remote WordPress (e.g., Cloudways) connecting to local LM Studio:

#### Option 1: Cloudflare Tunnel
```bash
# On LM Studio machine
cloudflared tunnel --url http://localhost:1234
```

Then use the provided tunnel URL in WP oOS settings.

#### Option 2: VPN
1. Set up VPN between WordPress server and LM Studio machine
2. Use VPN IP in endpoint URL

#### Option 3: Direct Tunneling
```bash
# SSH tunnel from WordPress server to LM Studio machine
ssh -L 1234:localhost:1234 user@lmstudio-machine
```

Then use `http://localhost:1234` on WordPress server.

## Troubleshooting

### Connection Refused

**Error**: `dial tcp [::1]:1234: connectex: No connection could be made`

**Solutions**:
1. Verify LM Studio server is running
2. Check the correct port (default: 1234)
3. Ensure no firewall blocking the port
4. For remote connections, verify network routing

### Double `/v1/` in URLs

**Error**: Requests going to `http://localhost:1234/v1/v1/models`

**Solution**: This was fixed in version 1.x. Ensure your endpoint URL is:
- ✅ Correct: `http://localhost:1234` (no `/v1` suffix)
- ❌ Wrong: `http://localhost:1234/v1` (includes `/v1`)

The plugin automatically appends `/v1/models`, `/v1/chat/completions`, etc.

### Model Not Found

**Error**: `No LM Studio model has been configured`

**Solutions**:
1. Load a model in LM Studio
2. Copy the exact model identifier from LM Studio UI
3. Paste it in the "LM Studio Model" field
4. Save settings

### Timeout Errors

**Error**: Request times out

**Solutions**:
1. Increase timeout in **Settings → WP oOS → Advanced → Request Timeout**
2. Local AI models take longer to respond (120+ seconds recommended)
3. Ensure sufficient hardware resources (RAM, CPU/GPU)

### Invalid JSON Response

**Error**: `The LM Studio API returned malformed JSON`

**Solutions**:
1. Verify model is properly loaded in LM Studio
2. Check LM Studio server logs for errors
3. Restart LM Studio server
4. Try a different model

## Performance Optimization

### Hardware Requirements

- **CPU**: Multi-core processor (8+ cores recommended)
- **RAM**: 16GB minimum, 32GB+ recommended
- **GPU**: CUDA-compatible GPU significantly improves performance
- **Storage**: SSD for model files

### Model Selection

- **Small models** (7B parameters): Faster, less accurate
  - Example: Llama 3 8B, Mistral 7B
- **Medium models** (13-70B): Balanced
  - Example: Llama 3 70B
- **Large models** (70B+): Most accurate, slower
  - Example: Llama 3 405B

### Request Timeout

Configure appropriate timeout based on model size:

```php
// Small models
'request_timeout' => 60, // 60 seconds

// Medium models
'request_timeout' => 120, // 2 minutes

// Large models
'request_timeout' => 300, // 5 minutes
```

### Max Tokens

Control response length to improve speed:

```php
'max_tokens' => 512, // Shorter responses = faster
```

## Security Considerations

### Private Data

- ✅ All data stays on your infrastructure
- ✅ No external API calls
- ✅ No cloud storage of prompts/responses

### Network Security

- Use firewall rules to restrict access
- For production, use VPN or secure tunnel
- Don't expose LM Studio directly to internet

### Authentication

LM Studio's OpenAI-compatible API doesn't require authentication by default. For added security:

1. Use firewall to restrict IP access
2. Run LM Studio behind a reverse proxy with auth
3. Use VPN for remote connections

## Cost Considerations

### Benefits

- **Zero API costs**: No per-token charges
- **Predictable costs**: One-time hardware investment
- **Unlimited usage**: No rate limits or quotas

### Costs

- **Hardware**: GPU, RAM, storage
- **Electricity**: Running local server
- **Maintenance**: System updates, model updates

## Comparison with Other Providers

| Feature | LM Studio | OpenAI | Ollama |
|---------|-----------|--------|--------|
| **Cost** | Hardware only | Per-token | Hardware only |
| **Privacy** | 100% local | Cloud-based | 100% local |
| **Speed** | Depends on hardware | Fast (cloud) | Depends on hardware |
| **Models** | Various (download) | OpenAI models | Various (pull) |
| **API Format** | OpenAI-compatible | Native OpenAI | Ollama format |
| **Ease of Setup** | GUI, user-friendly | API key only | CLI, technical |

## Support

### Plugin Issues

- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: `/docs/` directory

### LM Studio Issues

- Official Website: https://lmstudio.ai/
- LM Studio Documentation
- LM Studio Community Forums

## Technical Details

### URL Construction

The plugin constructs URLs as follows:

```php
$base = 'http://localhost:1234';
$models_url = $base . '/v1/models';
$chat_url = $base . '/v1/chat/completions';
```

### Request Format

Chat completion request (OpenAI-compatible):

```json
{
  "model": "llama-3-8b-instruct",
  "messages": [
    {"role": "system", "content": "You are a helpful assistant."},
    {"role": "user", "content": "Hello!"}
  ],
  "temperature": 0.7,
  "max_tokens": 512
}
```

### Response Format

LM Studio returns OpenAI-compatible responses:

```json
{
  "id": "chatcmpl-123",
  "object": "chat.completion",
  "created": 1234567890,
  "model": "llama-3-8b-instruct",
  "choices": [{
    "index": 0,
    "message": {
      "role": "assistant",
      "content": "Hello! How can I help you?"
    },
    "finish_reason": "stop"
  }],
  "usage": {
    "prompt_tokens": 10,
    "completion_tokens": 20,
    "total_tokens": 30
  }
}
```

## Examples

### Basic Usage

```php
$client = new WP_MCP_AI_LM_Studio_Client();

$messages = array(
    array('role' => 'user', 'content' => 'What is PHP?'),
);

$response = $client->create_chat_completion($messages);
```

### With Options

```php
$options = array(
    'model' => 'llama-3-8b-instruct',
    'temperature' => 0.8,
    'max_tokens' => 1024,
);

$response = $client->create_chat_completion($messages, $options);
```

### Test Connection

```php
$client = new WP_MCP_AI_LM_Studio_Client();
$result = $client->test_connection();

if (is_wp_error($result)) {
    echo 'Connection failed: ' . $result->get_error_message();
} else {
    echo 'Connection successful!';
}
```

## Changelog

### Version 1.x
- ✅ Initial LM Studio support
- ✅ OpenAI-compatible API integration
- ✅ Provider priority system
- ✅ Connection testing
- ✅ Model listing
- ✅ Chat completions
- ✅ Text completions
- ✅ Fixed endpoint URL bug (double `/v1/`)

## Future Enhancements

- [ ] Automatic model detection
- [ ] Hardware utilization monitoring
- [ ] Model switching based on context
- [ ] Streaming support
- [ ] Function calling support
- [ ] Vision model support

## References

- [LM Studio Official Website](https://lmstudio.ai/)
- [OpenAI API Documentation](https://platform.openai.com/docs/api-reference)
- [WP oOS Documentation](/docs/)
- *(Provider configuration documentation pending)*
