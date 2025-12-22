# Network Interface Binding for Local AI Providers

## Overview

This feature adds support for binding HTTP requests to specific network interfaces when connecting to local AI providers (Ollama and LM Studio). This is particularly useful when WordPress is hosted on a remote server (e.g., Cloudways) that needs to route requests through a specific network interface to reach AI providers on private network addresses.

## Use Case

**Problem**: WordPress hosted on Cloudways needs to connect to Ollama running on a local network machine at `192.168.2.222`. The remote server cannot reach the private IP through default routing.

**Solution**: Configure a network interface (e.g., `eth0`, `wlan0`, or a specific IP like `192.168.1.100`) that the server should use for Ollama/LM Studio requests.

## Architecture (Separation of Concerns)

The implementation follows strict SoC principles:

### 1. Settings Layer (`class-wp-mcp-ai-section-providers.php`)
- Handles UI presentation of network interface settings
- Stores configuration in WordPress options
- Two new optional fields:
  - `ollama_network_interface`
  - `lm_studio_network_interface`

### 2. HTTP Layer (`class-wp-mcp-ai-http-helper.php`)
- Centralized HTTP filtering logic
- `apply_network_interface_binding()`: Applies CURLOPT_INTERFACE to matching requests
- `register_network_interface_binding()`: Registers the `http_api_curl` filter
- **Scoped filtering**: Only applies to configured Ollama/LM Studio endpoints

### 3. Client Layer (`class-wp-mcp-ai-ollama-client.php`, `class-wp-mcp-ai-lm-studio-client.php`)
- Clients retrieve settings via `get_network_interface()`
- No HTTP filtering logic in clients (clean separation)
- Standard `wp_remote_get()` and `wp_remote_post()` calls

## Configuration

### Admin UI

**Location**: Settings → WP oOS → Providers → Ollama (or LM Studio)

**Fields**:
- **Ollama Network Interface (Optional)**: Text field for interface name or IP
  - Examples: `eth0`, `wlan0`, `192.168.1.100`
- **LM Studio Network Interface (Optional)**: Text field for interface name or IP
  - Examples: `eth0`, `wlan0`, `192.168.1.100`

### How It Works

1. User configures Ollama endpoint: `http://192.168.2.222:11434`
2. User configures network interface: `eth0`
3. When WordPress makes HTTP requests to `http://192.168.2.222:11434/*`:
   - `WP_MCP_AI_HTTP_Helper::apply_network_interface_binding()` is called
   - Checks if URL matches Ollama endpoint
   - If yes and interface is set, applies `curl_setopt($handle, CURLOPT_INTERFACE, 'eth0')`
4. cURL binds the request to the `eth0` network interface
5. Request reaches the local AI provider

## Provider Isolation

The implementation ensures **other providers are NOT affected**:

### ✅ Unaffected Providers
- **OpenAI (ChatGPT)**: Requests to `api.openai.com` → No binding applied
- **Anthropic (Claude)**: Requests to `api.anthropic.com` → No binding applied
- **Google Gemini**: Requests to Google APIs → No binding applied
- **Any other HTTP request**: → No binding applied

### ✅ Only Affected When Configured
- **Ollama**: Only if `ollama_network_interface` is set AND URL matches `ollama_endpoint_url`
- **LM Studio**: Only if `lm_studio_network_interface` is set AND URL matches `lm_studio_endpoint_url`

## Testing

### Unit Tests

**File**: `tests/test-http-helper-network-interface.php`

Tests verify:
- ✅ Network interface binding is applied to Ollama requests
- ✅ Network interface binding is applied to LM Studio requests
- ✅ Network interface binding is NOT applied to OpenAI requests
- ✅ Network interface binding is NOT applied to Anthropic requests
- ✅ Network interface binding is NOT applied when interface is empty
- ✅ Network interface binding is NOT applied when endpoint is empty

**File**: `tests/test-ollama-client.php`

Tests verify:
- ✅ `get_network_interface()` retrieves configured value
- ✅ `get_network_interface()` returns empty string when not configured

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test file
vendor/bin/phpunit tests/test-http-helper-network-interface.php
vendor/bin/phpunit tests/test-ollama-client.php
```

## Code Examples

### Example 1: Configure Ollama with Network Interface

```php
$settings = WP_MCP_AI_Admin_Settings::get_settings();
$settings['ollama_endpoint_url'] = 'http://192.168.2.222:11434';
$settings['ollama_model'] = 'llama3';
$settings['ollama_network_interface'] = 'eth0'; // Bind to eth0 interface

update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

// Now all Ollama requests will use eth0 interface
$client = new WP_MCP_AI_Ollama_Client();
$response = $client->test_connection();
```

### Example 2: Configure LM Studio with IP Address Binding

```php
$settings = WP_MCP_AI_Admin_Settings::get_settings();
$settings['lm_studio_endpoint_url'] = 'http://10.0.0.50:1234/v1';
$settings['lm_studio_model'] = 'local-model';
$settings['lm_studio_network_interface'] = '192.168.1.100'; // Bind to specific IP

update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

// Now all LM Studio requests will use 192.168.1.100 as source IP
$client = new WP_MCP_AI_LM_Studio_Client();
$messages = array( array( 'role' => 'user', 'content' => 'Hello' ) );
$response = $client->create_chat_completion( $messages );
```

## Security Considerations

- ✅ All interface names are sanitized with `sanitize_text_field()`
- ✅ Only applies to configured endpoints (no wildcards)
- ✅ Does not affect other HTTP requests
- ✅ No credentials or sensitive data in interface configuration
- ✅ WordPress capability checks still apply to all API requests

### Loopback and Private Network Security Settings

When using network interface binding with private network addresses, you should also configure the security settings:

**Settings → WP oOS → Security**

1. **Enable Loopback/Private Network SSL Bypass** (Default: Enabled)
   - Automatically disables SSL verification for localhost and private network addresses
   - Required for most local AI services without SSL certificates
   - If your local services have valid SSL certificates, you can disable this

2. **Allow Private Network Requests** (Default: Enabled)
   - Allows WordPress to make HTTP requests to private network addresses
   - Required for connecting to local AI services on your network
   - WordPress blocks private network requests by default for security

**Recommended Configuration for Local AI Services:**
```
✅ Enable Loopback/Private Network SSL Bypass
✅ Allow Private Network Requests
```

See `docs/SECURITY_HARDENING.md` for more details on these security settings.

## Limitations

1. **cURL Only**: Only works when WordPress uses cURL transport (default for most systems)
2. **Server Capability**: Server must have network interfaces configured and accessible
3. **Private Networks**: Most useful for routing to private network addresses
4. **No Validation**: Plugin doesn't validate interface names (rely on system configuration)

## Troubleshooting

### Connection Still Fails

1. **Verify interface exists**: Run `ip a` or `ifconfig` on server to check interfaces
2. **Check routing**: Ensure the interface can reach the target IP
3. **Test manually**: Use `curl --interface eth0 http://192.168.2.222:11434/api/tags`
4. **Check logs**: Enable WP oOS logging to see HTTP request details
5. **Verify firewall**: Ensure server firewall allows outbound connections on the interface

### Wrong Interface Applied

1. **Check endpoint URL**: Ensure it matches exactly (including trailing slash)
2. **Clear caching**: Some caching plugins may cache DNS lookups
3. **Verify settings**: Check that interface name is correctly saved

### Other Providers Affected

This should NOT happen. If it does:
1. **Check settings**: Ensure only Ollama/LM Studio interface fields are set
2. **Report bug**: This indicates a bug in the URL matching logic

## Future Enhancements

Potential improvements for future versions:

1. **Interface Validation**: Validate interface exists before saving
2. **Auto-Detection**: Suggest available interfaces in dropdown
3. **Connection Test**: Test with interface before saving
4. **Per-Route**: Support different interfaces for different endpoints
5. **IPv6 Support**: Explicit IPv6 interface binding

## Related Documentation

- [Cloudflare Tunnel Setup](../../getting-started/installation-setup/cloudflare-tunnel-setup.md) - Secure alternative for remote access to local AI services
- [LM Studio Setup Guide](../../getting-started/quick-starts/lm-studio-setup.md) - Complete LM Studio configuration
- [Ollama Documentation](https://github.com/ollama/ollama/blob/main/docs/api.md)
- [LM Studio Documentation](https://lmstudio.ai/docs)
- [cURL CURLOPT_INTERFACE](https://curl.se/libcurl/c/CURLOPT_INTERFACE.html)
- [WordPress HTTP API](https://developer.wordpress.org/plugins/http-api/)

## Changelog

### v1.0.0 (2025-11-15)
- ✅ Added network interface binding for Ollama
- ✅ Added network interface binding for LM Studio
- ✅ Implemented SoC architecture
- ✅ Added comprehensive unit tests
- ✅ Verified other providers unaffected
