# Federation & Discovery System

**Version:** 1.0.0  
**Last Updated:** November 6, 2025

## Overview

The WP oOS Federation & Discovery system enables WordPress sites to publish their AI capabilities and discover other peer sites in a decentralized AI network. This creates an "npm for AI tools" ecosystem where sites can find and consume capabilities from other trusted peers.

---

## Table of Contents

- [Quick Start](#quick-start)
- [Architecture](#architecture)
- [Configuration](#configuration)
- [Well-Known Endpoints](#well-known-endpoints)
- [Directory Service](#directory-service)
- [Peer Discovery](#peer-discovery)
- [Use Cases](#use-cases)
- [API Reference](#api-reference)
- [Security](#security)
- [Troubleshooting](#troubleshooting)

---

## Quick Start

### Enable Federation on Your Site

1. Navigate to **Settings → WP oOS → Federation & Discovery**
2. Check **Enable federation**
3. Configure your regions and data tags
4. Save changes

Your site now publishes capabilities at: `https://yoursite.com/.well-known/ai-peer`

### Enable Directory Service (Optional)

If you want your site to act as a directory for discovering other peers:

1. In the same settings section, check **Enable directory service**
2. Save changes

Your directory API is now available at: `https://yoursite.com/wp-json/ai-dir/v1`

### Register a Peer

```bash
curl -X POST https://yoursite.com/wp-json/ai-dir/v1/peers/register \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"wellknown_url": "https://peer.example.com/.well-known/ai-peer"}'
```

### Search for Peers

```bash
curl "https://yoursite.com/wp-json/ai-dir/v1/search?capability=transcribe_audio&region=eu&data_tag=no_pii"
```

---

## Architecture

### Components

1. **Well-Known Endpoints** - Publish this site's capabilities
2. **AI Peer CPT** - Store registered peer information (with automatic CCT sync for JetEngine)
3. **Directory REST API** - Peer registration and discovery
4. **Peer Verifier** - Health checks and validation
5. **Settings Manager** - Configuration interface

### Storage Architecture

**CPT-First with Optional CCT Sync:**

- **Base Version:** Uses WordPress CPT (`ai_peer`) for peer storage
- **Full Version with JetEngine:** Automatically syncs CPT data to JetEngine CCT (`ai_peers`)
- **Benefit:** Faster queries for JetEngine users while maintaining base compatibility
- **Pattern:** Same as AI Assistants (CPT → CCT automatic synchronization)

```
AI Peer CPT (Primary Storage)
├── Post Type: ai_peer
├── Meta fields: site_name, site_url, mcp_url, jwks_uri, etc.
└── On save → Syncs to CCT (if JetEngine available)
    ├── Creates/updates ai_peers CCT item
    ├── Stores link via _wp_mcp_ai_peer_cct_item_id
    └── On delete → Removes CCT item
```

### Data Flow

```
Site A (Publisher)
├── Publishes /.well-known/ai-peer
├── Lists capabilities, regions, policies
└── Exposes JWKS for verification

Site B (Directory)
├── Ingests Site A's well-known doc
├── Verifies JWKS reachability
├── Stores peer data in CPT
├── Auto-syncs to CCT (if JetEngine active)
└── Provides search API

Site C (Consumer)
├── Queries directory for capability
├── Receives ranked peer list
└── Calls peer via mesh router
```

---

## Configuration

### Settings Location

**Settings → WP oOS → Federation & Discovery**

### Available Settings

#### Enable Federation
- **Default:** Disabled
- **Effect:** Enables `/.well-known/ai-peer` and `/.well-known/jwks.json` endpoints
- **Use When:** You want other sites to discover your capabilities

#### Enable Directory Service
- **Default:** Disabled
- **Effect:** Enables peer registration and search APIs
- **Use When:** You want to run a directory for peer discovery

#### Regions
- **Format:** Comma-separated list (e.g., `us, eu, ap, global`)
- **Default:** `global`
- **Purpose:** Geographic regions where your site operates

#### Data Tags
- **Format:** Comma-separated list (e.g., `no_pii, gdpr_ok, hipaa_like`)
- **Default:** Empty
- **Purpose:** Data handling policies and compliance tags

#### Queries Per Second (QPS)
- **Default:** 5
- **Purpose:** Maximum queries per second from peers

#### Burst Limit
- **Default:** 10
- **Purpose:** Maximum burst capacity for simultaneous requests

---

## Well-Known Endpoints

### `/.well-known/ai-peer`

Publishes your site's capabilities to the federation network.

**Example Response:**

```json
{
  "version": "1.0",
  "site_name": "My WordPress Site",
  "site_url": "https://mysite.com/",
  "mcp": {
    "url": "https://mysite.com/wp-json/mcp-ai/v1"
  },
  "openapi": {
    "url": "https://mysite.com/wp-json/mcp-ai/v1/openapi.json"
  },
  "jwks_uri": "https://mysite.com/.well-known/jwks.json",
  "capabilities": [
    "get_recent_posts",
    "search_content",
    "transcribe_audio",
    "generate_image"
  ],
  "regions": ["us", "eu"],
  "data_tags": ["no_pii", "gdpr_ok"],
  "quotas": {
    "qps": 5,
    "burst": 10
  },
  "price_hints": {},
  "updated_at": "2025-11-06 21:00:00"
}
```

### `/.well-known/jwks.json`

Publishes public keys for JWT verification.

**Example Response:**

```json
{
  "keys": [
    {
      "kty": "RSA",
      "kid": "key-1",
      "use": "sig",
      "n": "...",
      "e": "AQAB"
    }
  ]
}
```

---

## Directory Service

### AI Peers Admin UI

Navigate to **AI Assistants → AI Peers** to view registered peers.

**List Table Columns:**
- Health status (Healthy, Degraded, Down)
- Number of capabilities
- Regions
- Latency (P50)
- Last health check

**Actions:**
- Verify Now - Trigger immediate health check
- View Details - See full peer information

### Peer Health Checks

- **Frequency:** Every hour (WP-Cron)
- **Checks:** Well-known document fetching, JWKS reachability
- **Status:**
  - **Healthy:** All checks passing
  - **Degraded:** Well-known reachable but JWKS unavailable
  - **Down:** Well-known document unreachable

---

## Peer Discovery

### Search Algorithm

The directory ranks peers using this priority:

1. **Region Match** (+20 points) - Peer is in requested region
2. **Data Tag Match** (+15 points) - Peer has requested data policy
3. **Low Latency** (+0-20 points) - Lower latency scores higher
4. **Price** (future) - Lower price preferred

Maximum score: 100 points

### Example Search Query

```bash
# Find peers with transcribe_audio capability in EU with GDPR compliance
curl "https://directory.example.com/wp-json/ai-dir/v1/search?\
capability=transcribe_audio&\
region=eu&\
data_tag=gdpr_ok&\
max_latency_ms=500&\
limit=10"
```

**Response:**

```json
{
  "results": [
    {
      "peer": "EU Transcription Service",
      "peer_id": 123,
      "capability": "transcribe_audio",
      "endpoint": {
        "mcp_url": "https://peer.example.com/wp-json/mcp-ai/v1"
      },
      "jwks_uri": "https://peer.example.com/.well-known/jwks.json",
      "region": ["eu"],
      "latency_ms_p50": 230,
      "data_tags": ["gdpr_ok", "no_pii"],
      "score": 91.5
    }
  ],
  "query": {
    "capability": "transcribe_audio",
    "region": "eu",
    "data_tag": "gdpr_ok",
    "max_latency_ms": 500
  }
}
```

---

## Use Cases

### Private Federation

**Scenario:** Your organization runs multiple WordPress sites and wants to share AI capabilities.

**Setup:**
1. Enable federation on all sites
2. Choose one site as the directory
3. Register all peer sites
4. Sites can now discover each other's capabilities

### Public Directory

**Scenario:** You want to run a public directory for the community.

**Setup:**
1. Enable directory service
2. Allow peer registration via API
3. Implement moderation queue (manual approval)
4. Publish directory URL for community

### Consumer-Only Site

**Scenario:** You want to use peer capabilities but not publish your own.

**Setup:**
1. Don't enable federation
2. Query public directories for peers
3. Use mesh router to call discovered peers

---

## API Reference

### `POST /ai-dir/v1/peers/register`

Register a new peer by fetching its well-known document.

**Authentication:** Required (admin)

**Request:**
```json
{
  "wellknown_url": "https://peer.example.com/.well-known/ai-peer"
}
```

**Response:**
```json
{
  "success": true,
  "peer_id": 123,
  "message": "Peer registered successfully"
}
```

### `GET /ai-dir/v1/peers`

List all registered peers.

**Authentication:** Public

**Query Parameters:**
- `per_page` (int, 1-100, default: 20)
- `page` (int, min: 1, default: 1)
- `status` (string: all|healthy|degraded|down, default: healthy)

### `GET /ai-dir/v1/peers/{id}`

Get details for a specific peer.

**Authentication:** Public

### `GET /ai-dir/v1/search`

Search for peers by capability, region, and policy.

**Authentication:** Public

**Query Parameters:**
- `capability` (string) - Required capability
- `region` (string) - Preferred region
- `data_tag` (string) - Required data handling policy
- `max_latency_ms` (int) - Maximum acceptable latency
- `max_price` (float) - Maximum price threshold
- `limit` (int, 1-50, default: 10)

### `POST /ai-dir/v1/reverify/{id}`

Trigger immediate peer reverification.

**Authentication:** Required (admin)

### `POST /ai-dir/v1/report/{id}`

Report a peer issue.

**Authentication:** Public

**Request:**
```json
{
  "reason": "down",
  "details": "Peer has been unreachable for 3 days"
}
```

---

## Security

### Trust Model

The federation system uses a **web of trust** model:

1. **Directory owners** decide which peers to accept
2. **Consumers** choose which directories to trust
3. **JWKS verification** ensures peer identity
4. **Health checks** ensure availability

### Best Practices

1. **Verify peer identity** - Check JWKS before accepting peers
2. **Review data tags** - Ensure peers meet your compliance needs
3. **Monitor health** - Watch for degraded or down peers
4. **Rate limit** - Configure appropriate QPS and burst limits
5. **Rotate keys** - Update JWKS regularly
6. **Audit access** - Review who's accessing your capabilities

### Private vs Public

**Private Federation (Recommended):**
- Allowlist known peers only
- Manual approval for registration
- Shared organizational trust

**Public Federation (Advanced):**
- Accept any peer registration
- Implement moderation queue
- Stronger verification required

---

## Troubleshooting

### Peer Registration Fails

**Problem:** "Failed to fetch well-known document"

**Solutions:**
1. Verify peer URL is accessible
2. Check SSL certificate is valid
3. Ensure peer has federation enabled
4. Check firewall rules

### Peer Shows as Degraded

**Problem:** Peer health status is "degraded"

**Cause:** JWKS endpoint not reachable

**Solutions:**
1. Verify JWKS URL in peer's well-known document
2. Check peer's JWKS endpoint returns valid JSON
3. Verify SSL certificate on JWKS endpoint

### Search Returns No Results

**Problem:** `/search` endpoint returns empty results

**Causes:**
1. No peers match criteria
2. All matching peers are unhealthy
3. Capability name mismatch

**Solutions:**
1. Broaden search criteria (remove region/data_tag filters)
2. Check peer health status in admin
3. Verify capability names match exactly

### Cron Not Running

**Problem:** Peer health checks not updating

**Solutions:**
1. Verify WP-Cron is enabled
2. Check `wp_next_scheduled('wp_mcp_ai_verify_peers')`
3. Manually trigger: `wp cron event run wp_mcp_ai_verify_peers`
4. Consider using system cron instead of WP-Cron

---

## Performance

### Scalability

- **Peers:** Tested with 100+ peers
- **Search:** Sub-100ms response time for 100 peers
- **Health Checks:** ~1 second per peer (sequential)
- **Storage:** CPT-based with automatic CCT sync for JetEngine users

### Optimization Tips

1. **Enable object caching** - Speeds up peer queries
2. **Use system cron** - More reliable than WP-Cron
3. **Increase check interval** - Reduce server load (2-4 hours)
4. **JetEngine CCT** - Automatically enabled for Full Version users, provides faster queries

---

## Future Enhancements

### Planned Features (Post-MVP)

- **mTLS/DPoP** - Token binding for enhanced security
- **OPA policies** - Fine-grained access control
- **Circuit breakers** - Automatic failover for down peers
- **SLA tracking** - Monitor peer reliability
- **Price comparison** - Cost-based routing
- **OpenAPI spec** - Auto-generate from tools
- **Mesh integration** - Automatic peer selection in mesh router

---

## Support

For issues or questions:
- **GitHub Issues:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Documentation:** See `/docs` directory
- **Security:** See SECURITY.md

---

**Note:** Federation is an optional feature and must be explicitly enabled. When disabled, it adds no overhead to your WordPress installation.
