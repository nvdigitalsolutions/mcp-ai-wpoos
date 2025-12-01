# Cloudflare Tunnel Setup for Local AI Services

## Overview

This guide explains how to use Cloudflare Tunnel (cloudflared) to securely expose local AI services (Ollama and LM Studio) to a remote WordPress installation. This is particularly useful when:

- WordPress is hosted on a cloud server (e.g., Cloudways, WP Engine, AWS)
- Ollama or LM Studio runs on your local machine or private network
- You want secure, encrypted access without exposing services directly to the internet
- You need a production-ready solution with SSL/TLS encryption

**Security Note:** Cloudflare Tunnel provides a secure alternative to port forwarding or VPNs by creating an encrypted tunnel from your local machine to Cloudflare's edge network.

## Prerequisites

1. **Cloudflare Account**: Free account at [cloudflare.com](https://www.cloudflare.com)
2. **Domain on Cloudflare**: Your domain's DNS managed by Cloudflare
3. **cloudflared CLI**: Installed on the machine running Ollama/LM Studio
4. **Local AI Service**: Ollama or LM Studio running locally

## Installation

### Windows

Download from [Cloudflare's releases page](https://github.com/cloudflare/cloudflared/releases) or use:

```powershell
# Download and install latest version
winget install --id Cloudflare.cloudflared
```

### macOS

```bash
# Using Homebrew
brew install cloudflare/cloudflare/cloudflared
```

### Linux

```bash
# Debian/Ubuntu
wget -q https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb
sudo dpkg -i cloudflared-linux-amd64.deb

# RHEL/CentOS
wget -q https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-x86_64.rpm
sudo rpm -i cloudflared-linux-x86_64.rpm
```

### Verify Installation

```bash
cloudflared --version
# Output: cloudflared version 2025.X.X (built YYYY-MM-DD-HHMM UTC)
```

## Setup Process

### Step 1: Authenticate with Cloudflare

```bash
cloudflared tunnel login
```

This command:
1. Opens your browser to Cloudflare dashboard
2. Asks you to authorize cloudflared
3. Downloads credentials to your machine

**Credential Location:**
- Windows: `C:\Users\<username>\.cloudflared\cert.pem`
- macOS/Linux: `~/.cloudflared/cert.pem`

**Output:**
```
A browser window should have opened at the following URL:
https://dash.cloudflare.com/argotunnel?aud=...

Waiting for login...
You have successfully logged in.
If you wish to copy your credentials to a server, they have been saved to:
/path/to/.cloudflared/cert.pem
```

### Step 2: Create a Tunnel

Choose a descriptive name for your tunnel (e.g., `ollama-wp`, `ai-services`, `local-ai`):

```bash
cloudflared tunnel create <tunnel-name>
```

**Example:**
```bash
cloudflared tunnel create ollama-wp
```

**Output:**
```
Tunnel credentials written to /path/to/.cloudflared/<tunnel-id>.json.
cloudflared chose this file based on where your origin certificate was found.
Keep this file secret. To revoke these credentials, delete the tunnel.

Created tunnel <tunnel-name> with id <tunnel-id>
```

**Important:** Save the tunnel ID and keep the credentials file secure.

### Step 3: Update cloudflared (Optional but Recommended)

```bash
cloudflared update
```

**Output:**
```
cloudflared has been updated to version 2025.X.X
```

### Step 4: Configure DNS Routes

Create DNS records that route traffic to your tunnel. You can add multiple subdomains to the same tunnel.

#### For Ollama

```bash
cloudflared tunnel route dns <tunnel-name> <subdomain.yourdomain.com>
```

**Example:**
```bash
cloudflared tunnel route dns ollama-wp ollama.yourdomain.com
```

#### For LM Studio (Optional)

```bash
cloudflared tunnel route dns <tunnel-name> <subdomain.yourdomain.com>
```

**Example:**
```bash
cloudflared tunnel route dns ollama-wp lmstudio.yourdomain.com
```

**Output:**
```
Added CNAME <subdomain.yourdomain.com> which will route to this tunnel tunnelID=<tunnel-id>
```

### Step 5: Create Configuration File

Create a configuration file to define how traffic should be routed.

**Location:**
- Windows: `C:\Users\<username>\.cloudflared\config.yml`
- macOS/Linux: `~/.cloudflared/config.yml`

**Configuration Example:**

```yaml
tunnel: ollama-wp
credentials-file: /path/to/.cloudflared/<tunnel-id>.json

ingress:
  # Ollama endpoint
  - hostname: ollama.yourdomain.com
    service: http://localhost:11434

  # LM Studio endpoint (optional)
  - hostname: lmstudio.yourdomain.com
    service: http://localhost:1234

  # Required catch-all rule (must be last)
  - service: http_status:404
```

**Configuration Notes:**
- Replace `/path/to/.cloudflared/<tunnel-id>.json` with your actual credentials file path
- Replace `ollama.yourdomain.com` with your actual subdomain
- Replace `lmstudio.yourdomain.com` with your actual subdomain (if using)
- Change port `1234` to match your LM Studio configuration
- The catch-all rule (`http_status:404`) must be the last ingress rule

### Step 6: Run the Tunnel

#### Test Run (Foreground)

Test the tunnel in the foreground first:

```bash
cloudflared tunnel run <tunnel-name>
```

**Example:**
```bash
cloudflared tunnel run ollama-wp
```

**Expected Output:**
```
2025-XX-XXTXX:XX:XXZ INF Starting tunnel tunnelID=<tunnel-id>
2025-XX-XXTXX:XX:XXZ INF Version 2025.X.X
2025-XX-XXTXX:XX:XXZ INF GOOS: linux, GOVersion: go1.21.5, GoArch: amd64
2025-XX-XXTXX:XX:XXZ INF Settings: map[ha-connections:4]
2025-XX-XXTXX:XX:XXZ INF cloudflared will not automatically update if installed by a package manager.
2025-XX-XXTXX:XX:XXZ INF Registered tunnel connection
```

**Test the connection:**
1. Ensure Ollama is running: `ollama serve`
2. Test tunnel: `curl https://ollama.yourdomain.com/api/tags`
3. Should see Ollama's API response

#### Install as Service (Production)

For production use, install cloudflared as a system service:

**Windows:**
```powershell
cloudflared service install
cloudflared service start
```

**macOS:**
```bash
sudo cloudflared service install
sudo launchctl start com.cloudflare.cloudflared
```

**Linux (systemd):**
```bash
sudo cloudflared service install
sudo systemctl start cloudflared
sudo systemctl enable cloudflared
```

**Verify Service Status:**
```bash
# Windows
sc query cloudflared

# macOS
sudo launchctl list | grep cloudflared

# Linux
sudo systemctl status cloudflared
```

## WordPress Configuration

Once your tunnel is running, configure WordPress to use the tunnel endpoints.

### Option 1: Using Ollama via Tunnel

**Navigate to:** Settings → WP oOS → Providers → Ollama

1. **Ollama Endpoint URL:** `https://ollama.yourdomain.com`
2. **Ollama Model:** Select or enter model name
3. Click **Test Connection** (should show success)
4. Click **Save Changes**

### Option 2: Using LM Studio via Tunnel

**Navigate to:** Settings → WP oOS → Providers → LM Studio

1. **LM Studio Endpoint URL:** `https://lmstudio.yourdomain.com/v1`
2. **LM Studio Model:** Select or enter model name
3. Click **Test Connection** (should show success)
4. Click **Save Changes**

### Network Interface Binding

When using Cloudflare Tunnel, you **do NOT need** network interface binding because:
- WordPress connects to public HTTPS endpoints (e.g., `https://ollama.yourdomain.com`)
- Cloudflare handles the routing to your local service
- No private IP addresses involved from WordPress perspective

**Network Interface Binding is only needed when:**
- WordPress connects directly to private IPs (e.g., `http://192.168.1.100:11434`)
- Both services are on the same network
- No tunnel or proxy is used

See [NETWORK_INTERFACE_BINDING.md](NETWORK_INTERFACE_BINDING.md) for direct network access scenarios.

## Security Considerations

### ✅ Advantages of Cloudflare Tunnel

1. **Encrypted Traffic**: All traffic is encrypted via TLS/SSL
2. **No Port Forwarding**: No need to open ports on your router
3. **DDoS Protection**: Cloudflare's edge network provides DDoS mitigation
4. **Access Control**: Use Cloudflare Access for additional authentication
5. **No Public IP Required**: Works behind NAT or dynamic IPs
6. **Free SSL Certificates**: Cloudflare provides SSL automatically

### 🔒 Best Practices

1. **Keep Credentials Secure**
   - Never commit `.cloudflared/*.json` or `cert.pem` to git
   - Restrict file permissions: `chmod 600 ~/.cloudflared/*.json`

2. **Use Cloudflare Access (Optional)**
   - Add authentication layer before tunnel
   - Restrict access to specific emails or groups
   - See [Cloudflare Access documentation](https://developers.cloudflare.com/cloudflare-one/applications/configure-apps/)

3. **Monitor Access Logs**
   - Review Cloudflare Analytics for unusual traffic
   - Enable logging in cloudflared configuration

4. **Update Regularly**
   ```bash
   cloudflared update
   ```

5. **Backup Configuration**
   - Save `config.yml` and tunnel credentials
   - Document tunnel names and routes

### ⚠️ Considerations

1. **Latency**: Adds network hop (request → Cloudflare → your machine → AI service)
2. **Cloudflare Rate Limits**: Free plan has rate limits (usually sufficient)
3. **Service Dependency**: Requires Cloudflare availability
4. **Data Privacy**: Traffic passes through Cloudflare (encrypted but visible to CF)

## Troubleshooting

### Tunnel Connection Issues

**Problem:** Tunnel won't start

**Solutions:**
1. **Check credentials:**
   ```bash
   ls -la ~/.cloudflared/
   # Should see cert.pem and <tunnel-id>.json
   ```

2. **Verify configuration:**
   ```bash
   cloudflared tunnel info <tunnel-name>
   ```

3. **Check logs:**
   ```bash
   # Foreground run to see logs
   cloudflared tunnel run <tunnel-name>
   ```

### DNS Not Resolving

**Problem:** `https://ollama.yourdomain.com` doesn't resolve

**Solutions:**
1. **Verify DNS record created:**
   - Go to Cloudflare Dashboard → DNS
   - Should see CNAME record pointing to `<tunnel-id>.cfargotunnel.com`

2. **Wait for DNS propagation:**
   ```bash
   dig ollama.yourdomain.com
   # Or
   nslookup ollama.yourdomain.com
   ```

3. **Check tunnel route:**
   ```bash
   cloudflared tunnel route dns <tunnel-name> ollama.yourdomain.com
   ```

### WordPress Connection Fails

**Problem:** WordPress can't connect to tunnel endpoint

**Solutions:**
1. **Test tunnel manually:**
   ```bash
   curl https://ollama.yourdomain.com/api/tags
   ```

2. **Verify Ollama is running:**
   ```bash
   curl http://localhost:11434/api/tags
   ```

3. **Check WordPress SSL settings:**
   - Settings → WP oOS → Security
   - **Disable** "Enable Loopback/Private Network SSL Bypass" (not needed for public HTTPS)

4. **Review cloudflared logs:**
   ```bash
   # Linux/macOS
   journalctl -u cloudflared -f
   
   # Windows Event Viewer
   # Look for cloudflared service logs
   ```

### 502 Bad Gateway Error

**Problem:** Cloudflare returns 502 error

**Solutions:**
1. **Verify local service is running:**
   ```bash
   # Test Ollama locally
   curl http://localhost:11434/api/tags
   
   # Test LM Studio locally
   curl http://localhost:1234/v1/models
   ```

2. **Check config.yml service URLs:**
   - Ensure `service: http://localhost:11434` matches actual port
   - For LM Studio, verify port 1234 (or your configured port)

3. **Restart tunnel:**
   ```bash
   # If running as service
   sudo systemctl restart cloudflared
   
   # If running manually
   # Stop (Ctrl+C) and run again
   cloudflared tunnel run <tunnel-name>
   ```

### Slow Response Times

**Problem:** Responses are slower than direct local access

**Expected:** Some latency is normal due to tunnel overhead

**Optimize:**
1. **Use nearest Cloudflare data center** (automatic, but verify location)
2. **Enable HTTP/2** in WordPress (some hosts disable it)
3. **Monitor Cloudflare Analytics** for routing issues
4. **Consider local network binding** if both services are on same network (see [NETWORK_INTERFACE_BINDING.md](NETWORK_INTERFACE_BINDING.md))

## Advanced Configuration

### Multiple Services per Tunnel

You can route multiple services through one tunnel:

```yaml
tunnel: ai-services
credentials-file: /path/to/.cloudflared/<tunnel-id>.json

ingress:
  - hostname: ollama.yourdomain.com
    service: http://localhost:11434
  
  - hostname: lmstudio.yourdomain.com
    service: http://localhost:1234
  
  - hostname: stable-diffusion.yourdomain.com
    service: http://localhost:7860
  
  - service: http_status:404
```

### Custom Origin Server Name

For services expecting specific hostnames:

```yaml
ingress:
  - hostname: ollama.yourdomain.com
    service: http://localhost:11434
    originRequest:
      noTLSVerify: true
      httpHostHeader: localhost
```

### Access Control with Cloudflare Access

Add authentication before tunnel (requires Cloudflare Zero Trust):

```yaml
ingress:
  - hostname: ollama.yourdomain.com
    service: http://localhost:11434
    originRequest:
      access:
        required: true
        teamName: your-team
        audTag: your-aud-tag
```

### Logging Configuration

Enable detailed logging:

```yaml
tunnel: ollama-wp
credentials-file: /path/to/.cloudflared/<tunnel-id>.json
logfile: /var/log/cloudflared.log
loglevel: debug

ingress:
  - hostname: ollama.yourdomain.com
    service: http://localhost:11434
  - service: http_status:404
```

## Comparison: Tunnel vs Direct Access

| Feature | Cloudflare Tunnel | Direct Network Access | VPN |
|---------|-------------------|----------------------|-----|
| **SSL/TLS** | ✅ Automatic | ❌ Manual setup required | ✅ Encrypted |
| **Setup Complexity** | Medium | Low | High |
| **Latency** | +50-200ms | Minimal | +20-100ms |
| **Security** | High | Medium | High |
| **Port Forwarding** | ❌ Not needed | ✅ Required | ❌ Not needed |
| **Public IP Required** | ❌ No | ⚠️ Recommended | ❌ No |
| **Cost** | Free (basic) | Free | Varies |
| **DDoS Protection** | ✅ Yes | ❌ No | ⚠️ Depends |
| **Access Control** | ✅ Optional | ❌ No | ✅ Yes |
| **Dynamic IP Support** | ✅ Yes | ❌ Challenging | ✅ Yes |

**When to Use Each:**

- **Cloudflare Tunnel**: Best for production deployments, remote WordPress, or when security is critical
- **Direct Network Access**: Best for same-network deployments with minimal latency requirements
- **VPN**: Best for accessing multiple services or full network access

## Management Commands

### List Tunnels

```bash
cloudflared tunnel list
```

### Tunnel Information

```bash
cloudflared tunnel info <tunnel-name>
```

### Delete Tunnel

```bash
# Stop service first
cloudflared service uninstall  # or sudo systemctl stop cloudflared

# Delete tunnel
cloudflared tunnel delete <tunnel-name>
```

### Revoke Tunnel Credentials

```bash
cloudflared tunnel cleanup <tunnel-name>
```

### View Tunnel Routes

```bash
cloudflared tunnel route list
```

## Related Documentation

- [LM Studio Setup Guide](lm-studio-setup.md) - Local LM Studio configuration
- [Network Interface Binding](NETWORK_INTERFACE_BINDING.md) - Direct network access setup
- [Security Hardening](SECURITY_HARDENING.md) - Security best practices
- [Remote Client Setup](remote-client-setup.md) - Remote client configuration
- [Deployment Troubleshooting](deployment-troubleshooting.md) - General troubleshooting

## External Resources

- [Cloudflare Tunnel Documentation](https://developers.cloudflare.com/cloudflare-one/connections/connect-apps/)
- [cloudflared GitHub Repository](https://github.com/cloudflare/cloudflared)
- [Cloudflare Zero Trust](https://developers.cloudflare.com/cloudflare-one/)
- [Ollama API Documentation](https://github.com/ollama/ollama/blob/main/docs/api.md)
- [LM Studio Documentation](https://lmstudio.ai/docs)

## Support

If you encounter issues:

1. **Test tunnel independently** of WordPress
2. **Check Cloudflare Dashboard** for tunnel status
3. **Review cloudflared logs** for error messages
4. **Enable WordPress debug logging** in Settings → WP oOS
5. **Compare with direct local access** to isolate tunnel issues
6. **Consult Cloudflare Community** for tunnel-specific problems

## Changelog

### v1.0.0 (Initial Documentation)
- ✅ Complete Cloudflare Tunnel setup guide
- ✅ Configuration examples for Ollama and LM Studio
- ✅ Troubleshooting procedures
- ✅ Security best practices
- ✅ Comparison with alternative approaches
