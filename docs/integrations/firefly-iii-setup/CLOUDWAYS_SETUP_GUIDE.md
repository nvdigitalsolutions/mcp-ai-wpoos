# Firefly III Setup Guide for Cloudways

Complete instructions for setting up Firefly III integration when your WordPress site is hosted on Cloudways.

---

## Overview

This guide covers two scenarios:
1. **WordPress on Cloudways** + Firefly III on separate server (recommended)
2. **WordPress on Cloudways** + Firefly III on same Cloudways server (advanced)

---

## Scenario 1: WordPress on Cloudways + Firefly III Elsewhere (Recommended)

This is the easiest and most flexible setup.

### Prerequisites

- ✅ WordPress site running on Cloudways
- ✅ Firefly III installed elsewhere (Docker, VPS, or managed hosting)
- ✅ NV oOS plugin installed on WordPress

### Step 1: Install Firefly III on External Server

Choose one of these options:

**Option A: Docker on DigitalOcean/Linode/AWS**

```bash
# SSH into your VPS
ssh root@your-vps-ip

# Run Firefly III container
docker run -d \
  --name firefly_iii \
  --restart unless-stopped \
  -p 8080:8080 \
  -e APP_KEY=$(head /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | head -c 32) \
  -e DB_CONNECTION=sqlite \
  -e APP_URL=https://firefly.yourdomain.com \
  -v firefly_iii_data:/var/www/html/storage/database \
  fireflyiii/core:latest
```

**Option B: Managed Firefly III Hosting**
- Use a service that provides Firefly III hosting
- Some providers: PikaPods, Elestio, or similar

**Option C: Docker on Local Machine (Development Only)**
```bash
docker run -d -p 8080:8080 --name firefly_iii fireflyiii/core:latest
# Access at http://localhost:8080
```

### Step 2: Configure Domain/SSL for Firefly III

If using a VPS, set up a subdomain:

1. **Add DNS Record**:
   - Type: A
   - Name: firefly
   - Value: Your VPS IP
   - TTL: 3600

2. **Set up SSL** (using Let's Encrypt):
   ```bash
   # Install Caddy (automatic HTTPS)
   sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
   curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
   curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
   sudo apt update
   sudo apt install caddy
   
   # Create Caddyfile
   sudo nano /etc/caddy/Caddyfile
   ```

   Add this configuration:
   ```
   firefly.yourdomain.com {
       reverse_proxy localhost:8080
   }
   ```

   ```bash
   # Restart Caddy
   sudo systemctl restart caddy
   ```

3. **Access Firefly III**:
   - URL: `https://firefly.yourdomain.com`
   - Complete setup wizard
   - Create admin account

### Step 3: Get Personal Access Token from Firefly III

1. Log into Firefly III at `https://firefly.yourdomain.com`
2. Go to **Options** → **Profile** → **OAuth**
3. Under "Personal Access Tokens", click **Create New Token**
4. Name: `Cloudways WordPress Integration`
5. Click **Create**
6. **Copy the token** (shown only once!)

### Step 4: Configure WordPress on Cloudways

1. **Access WordPress Admin**:
   - URL: `https://yoursite.com/wp-admin`

2. **Navigate to Settings**:
   - Go to **Settings** → **NV oOS**

3. **Enter Firefly III Configuration**:
   - **API URL**: `https://firefly.yourdomain.com`
   - **Personal Access Token**: (paste token from Step 3)

4. **Test Connection**:
   - Click **Test Connection** button
   - Should show: ✅ "Successfully connected to Firefly III"

5. **Save Settings**

### Step 5: Verify Integration

Test with your AI assistant:

```
User: "Show me all my Firefly III accounts"

Expected: AI lists your accounts with balances
```

---

## Scenario 2: Both on Same Cloudways Server (Advanced)

⚠️ **Not officially supported by Cloudways**, but possible with SSH access.

### Prerequisites

- ✅ Cloudways application with SSH access enabled
- ✅ Docker installed on Cloudways server (requires Platform Beta features)
- ✅ Advanced Linux knowledge

### Limitations

- Cloudways doesn't officially support Docker containers
- May violate Cloudways Terms of Service
- Server resources shared between WordPress and Firefly III
- More complex maintenance

### Steps (For Advanced Users Only)

1. **Enable SSH Access**:
   - Cloudways Dashboard → Select Server
   - **Server Management** → **SSH & SFTP**
   - Enable SSH access
   - Note your SSH credentials

2. **SSH into Cloudways Server**:
   ```bash
   ssh master@your-server-ip -p port
   ```

3. **Install Docker** (if not present):
   ```bash
   # This may not work on all Cloudways servers
   # Contact Cloudways support first
   curl -fsSL https://get.docker.com -o get-docker.sh
   sudo sh get-docker.sh
   ```

4. **Run Firefly III Container**:
   ```bash
   docker run -d \
     --name firefly_iii \
     --restart unless-stopped \
     -p 8080:8080 \
     -e APP_KEY=$(openssl rand -base64 32 | head -c 32) \
     -e DB_CONNECTION=sqlite \
     -e APP_URL=https://yoursite.com:8080 \
     -v /home/master/applications/firefly_data:/var/www/html/storage/database \
     fireflyiii/core:latest
   ```

5. **Configure Cloudways Firewall**:
   - Cloudways Dashboard → Select Server
   - **Security** → **Firewall Management**
   - Add rule: Allow port 8080 (or use Cloudways application URL with port)

6. **Access Firefly III**:
   - URL: `https://your-server-ip:8080`
   - Or: `https://yoursite.com:8080` (if domain points to server)

7. **Get Access Token** (same as Scenario 1, Step 3)

8. **Configure WordPress**:
   - API URL: `http://localhost:8080` or `https://yoursite.com:8080`
   - Personal Access Token: (from Firefly III)

### Recommended Alternative for Cloudways Users

Instead of running Firefly III on Cloudways, consider:

**Best Practice**: Use a separate $5-10/month VPS for Firefly III
- DigitalOcean Droplet ($6/mo)
- Linode Nanode ($5/mo)
- Vultr Cloud Compute ($6/mo)

**Advantages**:
- ✅ Isolated resources
- ✅ Full Docker support
- ✅ Easier maintenance
- ✅ No Cloudways ToS concerns
- ✅ Dedicated database
- ✅ Better performance

---

## Cloudways-Specific Considerations

### 1. Firewall Configuration

Cloudways has built-in firewalls. Ensure:
- Port 443 (HTTPS) is open for API calls
- If Firefly III is on different server, no special rules needed
- If same server, open port 8080 (not recommended)

### 2. SSL Certificates

**WordPress on Cloudways**:
- Cloudways provides free Let's Encrypt SSL
- Automatically renewed
- ✅ No action needed

**Firefly III on External Server**:
- Use Let's Encrypt with Certbot or Caddy
- See Step 2 in Scenario 1 above

### 3. PHP Version Compatibility

- **WordPress (Cloudways)**: PHP 7.4+ (works fine)
- **Firefly III (External)**: PHP 8.1+ (separate server, no conflict)
- No version conflicts since they're separate applications

### 4. Database Considerations

- **WordPress DB**: Managed by Cloudways (MySQL/MariaDB)
- **Firefly III DB**: SQLite (Docker) or separate MySQL (if self-hosted)
- No shared database required or recommended

### 5. Backups

**WordPress (Cloudways)**:
- Use Cloudways built-in backup system
- Automatic daily backups included

**Firefly III (External)**:
- Docker volume backups:
  ```bash
  docker run --rm \
    -v firefly_iii_data:/data \
    -v $(pwd):/backup \
    ubuntu tar czf /backup/firefly-backup-$(date +%Y%m%d).tar.gz /data
  ```
- Or use your VPS backup solution

### 6. Performance Optimization

**Cloudways Settings**:
- Enable **Redis** (Cloudways → Application Settings → Redis)
- Enable **Varnish** (if available on your plan)
- Use **Cloudways CDN** for static assets

**Firefly III**:
- SQLite for small datasets (< 10,000 transactions)
- PostgreSQL for large datasets
- Regular database maintenance

---

## Troubleshooting Cloudways-Specific Issues

### Issue: "Connection Timeout" from Cloudways to Firefly III

**Possible Causes**:
1. Firefly III server not accessible
2. Cloudways firewall blocking outbound connections
3. Firefly III firewall blocking Cloudways IP

**Solutions**:
1. Test Firefly III accessibility:
   ```bash
   # SSH into Cloudways
   curl -I https://firefly.yourdomain.com
   ```

2. Whitelist Cloudways server IP in Firefly III firewall

3. Contact Cloudways support if outbound connections blocked

### Issue: "API returned error (status 401)"

**Cause**: Invalid or expired Personal Access Token

**Solution**:
1. Generate new token in Firefly III
2. Update WordPress settings
3. Clear WordPress cache:
   - Cloudways Dashboard → Application → Cache → Clear All Cache

### Issue: SSL Certificate Errors

**Cause**: Self-signed certificate or SSL verification issues

**Solutions**:
1. Ensure Firefly III has valid SSL certificate
2. Use Let's Encrypt (free, valid)
3. Don't use self-signed certificates for production

### Issue: Slow API Responses

**Causes**:
- Geographic distance between servers
- Slow Firefly III server
- Network latency

**Solutions**:
1. Choose Firefly III VPS in same region as Cloudways
2. Upgrade Firefly III server resources
3. Implement caching (Pro addon feature)

---

## Recommended Cloudways + Firefly III Architecture

```
┌─────────────────────────────────────────────────────────┐
│  Cloudways Managed Hosting                              │
│  ┌───────────────────────────────────────┐              │
│  │  WordPress + NV oOS Plugin            │              │
│  │  Location: North America / Europe     │              │
│  │  PHP: 8.1+, MySQL, Redis, Varnish     │              │
│  └───────────────────────────────────────┘              │
└─────────────────────────────────────────────────────────┘
                          │
                          │ HTTPS API Calls
                          │ (Firefly III Personal Access Token)
                          ▼
┌─────────────────────────────────────────────────────────┐
│  DigitalOcean / Linode / AWS VPS ($5-10/mo)            │
│  ┌───────────────────────────────────────┐              │
│  │  Firefly III (Docker Container)       │              │
│  │  Location: Same region as Cloudways   │              │
│  │  Port: 8080 → 443 (via Caddy proxy)   │              │
│  │  Database: SQLite or PostgreSQL       │              │
│  │  SSL: Let's Encrypt (automatic)       │              │
│  └───────────────────────────────────────┘              │
└─────────────────────────────────────────────────────────┘
```

**Why This Setup?**
- ✅ Cloudways handles WordPress (what they're good at)
- ✅ Separate VPS handles Firefly III (full control, Docker support)
- ✅ Geographic proximity = low latency
- ✅ SSL on both = secure communication
- ✅ Total cost: Cloudways plan + $5-10/mo VPS

---

## Cost Breakdown

### Option 1: Cloudways + Separate VPS (Recommended)

**Monthly Costs**:
- Cloudways WordPress: $11-80/mo (depending on plan)
- DigitalOcean VPS for Firefly III: $6/mo
- **Total**: $17-86/mo

**Benefits**:
- Full Docker support
- Dedicated resources
- Cleaner architecture
- Better performance

### Option 2: Cloudways Only (Not Recommended)

**Monthly Costs**:
- Cloudways WordPress: $11-80/mo
- **Total**: $11-80/mo

**Drawbacks**:
- Limited Docker support
- Shared resources
- Potential ToS violations
- Harder maintenance

---

## Migration Guide

If you already have Firefly III elsewhere and WordPress on Cloudways:

1. **No WordPress changes needed** - just update API URL in settings
2. **Firefly III stays where it is** - no migration required
3. **Update configuration**:
   - Old API URL → New API URL (if Firefly III moved)
   - Generate new token (if needed)
4. **Test connection** in WordPress settings

---

## Support & Resources

**Cloudways Support**:
- Live Chat: 24/7
- Tickets: Cloudways Dashboard → Support
- Documentation: https://support.cloudways.com

**Firefly III Support**:
- Documentation: https://docs.firefly-iii.org/
- GitHub Issues: https://github.com/firefly-iii/firefly-iii/issues
- Community: Firefly III subreddit

**Plugin Support**:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Plugin Documentation

---

## Quick Start Checklist

- [ ] WordPress running on Cloudways
- [ ] NV oOS plugin installed and activated
- [ ] Firefly III installed on external VPS/Docker
- [ ] DNS configured for Firefly III subdomain
- [ ] SSL certificate active on Firefly III
- [ ] Personal Access Token generated
- [ ] API URL and token configured in WordPress
- [ ] Connection tested successfully
- [ ] First AI assistant query works

**Estimated Setup Time**: 45-60 minutes

---

## Next Steps

After successful setup:

1. **Test All Tools**: Try the 7 Firefly III integration tools
2. **Create Workflows**: Set up common financial queries
3. **Monitor Performance**: Check API response times
4. **Set Up Backups**: Ensure both systems are backed up
5. **Review Security**: Verify SSL, tokens, and access controls

---

**Last Updated**: January 2026  
**Cloudways Compatibility**: All Cloudways Plans  
**Firefly III Tested**: v6.1.x
