# LM Studio Setup Guide

## Overview

This guide explains how to connect the WordPress plugin to a local or remote LM Studio instance.

## Quick Start

1. **Install and run LM Studio**
   - Download from https://lmstudio.ai
   - Start LM Studio application
   - Load at least one model

2. **Enable Local Server in LM Studio**
   - Click the server icon (bottom left)
   - Click "Start Server"
   - Default endpoint: `http://127.0.0.1:1234`
   - Note the port if you changed it

3. **Configure in WordPress**
   - Navigate to **Settings → WP oOS**
   - Find **LM Studio Configuration** section
   - Enter endpoint URL: `http://127.0.0.1:1234`
   - Click **Test Connection** (should show success)
   - Click **Fetch Models** (shows available models)
   - Click a model name to select it
   - Click **Save Changes**

4. **Set as Default Provider (Optional)**
   - In **Settings → WP oOS**
   - Find **Default AI Provider** dropdown
   - Select **LM Studio (Local AI)**
   - Click **Save Changes**

## Testing the Connection

### Method 1: Command Line Test

```bash
cd /path/to/mcp-ai-wpoos
./bin/test-lm-studio-connection.sh
```

Or test a custom endpoint:
```bash
./bin/test-lm-studio-connection.sh http://192.168.1.100:8080
```

### Method 2: WordPress Admin Test

1. Go to **Settings → WP oOS**
2. Enter LM Studio endpoint URL
3. Click **Test Connection** button
4. Look for green checkmark and success message

## Network Setup

### Local Setup (127.0.0.1)

**Default configuration** - LM Studio runs on the same machine as WordPress:
```
Endpoint: http://127.0.0.1:1234
```

**Equivalent alternatives:**
- `http://localhost:1234` (same as 127.0.0.1)
- `http://0.0.0.0:1234` (listens on all interfaces)

### Remote Setup (LAN)

LM Studio running on a different machine in your network:

1. **On LM Studio Machine:**
   - Find your local IP address:
     - Windows: `ipconfig`
     - Mac/Linux: `ifconfig` or `ip addr`
   - Example IP: `192.168.1.100`
   - Make sure LM Studio server is listening on `0.0.0.0:1234` (not just 127.0.0.1)

2. **On WordPress Machine:**
   - Use the LM Studio machine's IP address:
     ```
     Endpoint: http://192.168.1.100:1234
     ```

3. **Firewall Rules:**
   - Allow incoming connections on port 1234 (or your custom port)
   - On Windows: Windows Firewall settings
   - On Mac: System Preferences → Security & Privacy → Firewall
   - On Linux: `ufw allow 1234` or iptables rules

### Docker Setup

If WordPress is running in Docker:

1. **Docker on Same Machine as LM Studio:**
   ```
   # Use host machine's IP, not 127.0.0.1
   Endpoint: http://host.docker.internal:1234
   ```

2. **Docker Compose Network:**
   - Add LM Studio to docker-compose network
   - Use service name as hostname

## Port Configuration

LM Studio default port is **1234**, but you can change it:

1. In LM Studio → Server Settings
2. Change the port number
3. Restart the server
4. Update WordPress endpoint URL accordingly

Common alternative ports:
- `8080` - Common HTTP alternative port
- `11434` - Ollama's default (if running both)
- `5000` - Common development port

## Supported Endpoints

The plugin uses these LM Studio endpoints:

✅ **GET** `/v1/models`
- Lists available models
- Used by "Fetch Models" button

✅ **POST** `/v1/chat/completions`
- Sends chat messages
- Receives AI responses
- Supports streaming responses (if enabled)

❌ **Not Used** (even though LM Studio may support them):
- `/v1/responses` - Not needed (plugin doesn't use document attachments with LM Studio)
- `/v1/completions` - Legacy endpoint (plugin uses chat completions instead)
- `/v1/embeddings` - Not implemented in plugin

## Troubleshooting

### "Could not connect" Error

**Possible causes:**
1. LM Studio server is not running
   - **Solution:** Start the server in LM Studio
   
2. Wrong endpoint URL
   - **Solution:** Verify the URL matches LM Studio's configuration
   - Check port number carefully (1234 is default)
   
3. Firewall blocking connection
   - **Solution:** Add firewall rule to allow port 1234
   
4. Network configuration issue
   - **Solution:** Use `127.0.0.1` instead of `localhost` or vice versa
   - Try `http://0.0.0.0:1234` in LM Studio settings

### "No models found" Error

**Possible causes:**
1. No models loaded in LM Studio
   - **Solution:** Download and load at least one model
   
2. Models not enabled for server
   - **Solution:** Check model settings in LM Studio

### Connection Works but Chat Fails

**Possible causes:**
1. Model not specified or invalid
   - **Solution:** Use "Fetch Models" and select a valid model
   
2. Model not loaded/running
   - **Solution:** Load the model in LM Studio first
   
3. Memory issues
   - **Solution:** Use a smaller model or increase system RAM

### Slow Responses

**Possible causes:**
1. Model too large for your hardware
   - **Solution:** Use quantized or smaller models (7B instead of 70B)
   
2. CPU inference (no GPU)
   - **Solution:** Enable GPU acceleration in LM Studio if available
   
3. Network latency (remote setup)
   - **Solution:** Use local setup or faster network connection

### "WordPress timed out waiting for a response" Error

**Since version X.X.X**, the plugin automatically uses a **120-second minimum timeout** for LM Studio requests.

**Why this matters:**
- Local AI models are much slower than cloud APIs
- Complex queries can take 60-120+ seconds to generate responses
- The plugin no longer caps timeouts at PHP's `max_execution_time` setting for external HTTP requests

**If you still get timeout errors:**
1. **Increase the custom timeout** in **Settings → WP oOS → Request Timeout**
   - Set to 180 or 240 seconds for complex queries
   - This overrides the default 120-second minimum

2. **Use a smaller/faster model**
   - 7B models respond faster than 70B models
   - Quantized models (Q4, Q5) are faster than full-precision

3. **Enable GPU acceleration** in LM Studio
   - CPU-only inference is significantly slower

4. **Simplify your prompts**
   - Shorter prompts = faster responses
   - Break complex requests into smaller parts

## Performance Tips

1. **Use GPU Acceleration**
   - Significantly faster than CPU-only inference
   - Configure in LM Studio settings

2. **Choose Appropriate Model Size**
   - 7B models: Good for most tasks, runs on most hardware
   - 13B models: Better quality, needs more RAM/VRAM
   - 70B+ models: Best quality, requires powerful hardware

3. **Enable Streaming**
   - Shows responses as they're generated
   - Better user experience for long responses
   - Enable in shortcode: `[mcp_ai_chat enable_streaming="true"]`

4. **Local vs Remote**
   - Local (127.0.0.1): Best performance, no network latency
   - Remote (LAN): Slight latency, but allows multiple clients
   - Internet: Not recommended (security concerns, slow)

## Security Considerations

1. **Don't expose LM Studio to the internet**
   - Only use on local network or localhost
   - No authentication built into LM Studio

2. **Use HTTPS for WordPress** (if accessing remotely)
   - Encrypt communication between browser and WordPress
   - LM Studio connection can remain HTTP on LAN

3. **Firewall rules**
   - Only allow connections from trusted IPs
   - Use VPN if accessing remotely

4. **Network isolation**
   - Keep LM Studio on internal network
   - Don't forward port 1234 on router

## Comparison: localhost vs 127.0.0.1

Both work identically for local connections:

| Address | Description | Use Case |
|---------|-------------|----------|
| `127.0.0.1` | IPv4 loopback | Direct, no DNS needed |
| `localhost` | Hostname | May resolve to IPv6 (::1) on some systems |
| `0.0.0.0` | All interfaces | Use in LM Studio to accept remote connections |

**Recommendation:** Use `127.0.0.1` for consistency and to avoid IPv6 confusion.

## Related Documentation

- [LM Studio Testing Guide](lm-studio-testing.md) - Detailed testing procedures
- [Cloudflare Tunnel Setup](../installation-setup/cloudflare-tunnel-setup.md) - Securely expose local LM Studio to remote WordPress
- [Network Interface Binding](../../features/federation/NETWORK_INTERFACE_BINDING.md) - Direct network access configuration
- [LM Studio Endpoints Analysis](../LM-STUDIO-ENDPOINTS-ANALYSIS.md) - Technical endpoint details
- [REST API Documentation](../../reference/api/rest-api.md) - Complete REST API reference
- [Tool Reference](../../reference/tools/tool-reference.md) - Available tools and capabilities

## Support

If you encounter issues:
1. Check LM Studio logs
2. Check WordPress error logs
3. Enable debug logging in **Settings → WP oOS**
4. Test with the command-line script
5. Verify network connectivity with `curl` or `ping`
