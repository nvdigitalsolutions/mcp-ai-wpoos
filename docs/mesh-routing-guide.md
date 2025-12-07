# Mesh Compute Routing Guide

**Version:** 1.0.0  
**Last Updated:** November 6, 2025

## Overview

WP oOS now includes **intelligent mesh compute routing** that automatically distributes AI workload across multiple sites OR multiple providers using AI-powered decision-making. This feature works in two modes:

1. **Multi-Site Mesh**: Distribute load across multiple WordPress installations
2. **Single-Site Multi-Provider**: Balance load across OpenAI, Gemini, and Ollama on one site

Both modes use the same AI-powered routing engine to optimize for cost, performance, and reliability.

---

## Table of Contents

- [Quick Start](#quick-start)
- [Single-Site Setup (No Mesh Required)](#single-site-setup-no-mesh-required)
- [Multi-Site Mesh Setup](#multi-site-mesh-setup)
- [Routing Strategies](#routing-strategies)
- [Compute Hub Configuration](#compute-hub-configuration)
- [Use Cases by Hosting](#use-cases-by-hosting)
- [Monitoring and Health Metrics](#monitoring-and-health-metrics)
- [Troubleshooting](#troubleshooting)

---

## Quick Start

### Single-Site with Multi-Provider Routing

**Perfect for**: One WordPress site using multiple AI providers for cost optimization and resilience.

1. Configure multiple AI providers in **Settings → WP oOS**:
   - Add OpenAI API key
   - Add Gemini API key
   - Configure Ollama endpoint (optional, for local AI)

2. Edit your assistant → **Mesh Compute Routing** section:
   - Set routing strategy to **AI Optimized**
   - Enable **Retry** for automatic failover
   - Save assistant

3. Done! The AI will now automatically route:
   - Simple questions → GPT-4o-mini (cost-effective)
   - Complex analysis → GPT-4o (powerful)
   - Private data → Ollama (local, secure)
   - Automatic failover if one provider is down or rate-limited

### Multi-Site Mesh Routing

**Perfect for**: Multiple WordPress sites sharing compute resources across Cloudways, SiteGround, or mixed hosting.

1. Enable mesh on all sites: **Settings → WP oOS → Mesh Network**
   - Check **Enable mesh networking**
   - Copy the generated **Inbound API Key**

2. Add peer sites on each site:
   - Click **Add Peer Site**
   - Enter peer name, URL, and their inbound API key
   - Save changes

3. Configure assistant routing:
   - Edit assistant → **Mesh Compute Routing**
   - Set routing strategy (AI Optimized recommended)
   - Mark compute hubs (sites with larger models)
   - Enable retry and save

4. Add the **Query Mesh (Intelligent Routing)** tool to your assistant

---

## Single-Site Setup (No Mesh Required)

### Why Use Single-Site Routing?

Even without a mesh network, AI routing provides significant benefits:

- **Cost Optimization**: Use GPT-4o-mini for 90% of queries, reserve GPT-4o for complex tasks
- **Rate Limit Management**: Auto-switch to Gemini when hitting OpenAI rate limits
- **Privacy Control**: Route sensitive data to local Ollama, general queries to cloud
- **Resilience**: Automatic failover to backup providers

### Configuration Steps

#### 1. Configure Multiple Providers

Navigate to **Settings → WP oOS** and configure at least two providers:

**OpenAI** (Cloud - Fast, Most Capable):
```
API Key: sk-...
Default Model: gpt-4o-mini
```

**Google Gemini** (Cloud - Alternative):
```
API Key: ...
Default Model: gemini-1.5-flash
```

**Ollama** (Local - Private, Free):
```
Endpoint URL: http://localhost:11434
Model: llama3
```

#### 2. Configure Assistant Routing

Edit your assistant and scroll to **Mesh Compute Routing**:

```
Routing Strategy: AI Optimized
☑ Enable Retry
Max Retries: 3
```

**AI Optimized Strategy** analyzes each request and automatically:
- Estimates task complexity (1-10 scale)
- Checks provider rate limits
- Considers response times
- Routes to optimal provider

#### 3. How It Works

**Example 1: Simple Question**
```
User: "What is 2+2?"
AI Router: 
  - Complexity: 2/10
  - Routes to: GPT-4o-mini (fast, cheap)
  - Cost: $0.00015
```

**Example 2: Complex Analysis**
```
User: "Analyze the quarterly financial report and provide detailed insights..."
AI Router:
  - Complexity: 9/10
  - Routes to: GPT-4o (powerful, thorough)
  - Cost: $0.015
```

**Example 3: Private Data**
```
User: "Summarize this customer PII data..."
AI Router:
  - Detects sensitive data
  - Routes to: Ollama (local, no cloud transmission)
  - Cost: $0 (free)
```

**Example 4: Rate Limited**
```
User: "Generate 10 blog posts..."
AI Router:
  - Starts with: OpenAI GPT-4o-mini
  - After 5 posts: Hits rate limit
  - Auto-switches to: Gemini
  - Completes remaining 5 posts
  - No user interruption
```

### Single-Site Cost Savings

**Without AI Routing** (using GPT-4o for everything):
- 1000 simple queries/day = $15/day = $450/month
- 100 complex queries/day = $1.50/day = $45/month
- **Total: $495/month**

**With AI Routing** (intelligent distribution):
- 900 simple queries → GPT-4o-mini = $0.14/day = $4.20/month
- 100 simple queries → Ollama = $0/day = $0/month
- 100 complex queries → GPT-4o = $1.50/day = $45/month
- **Total: $49.20/month (90% savings!)**

---

## Multi-Site Mesh Setup

### Use Cases by Hosting

#### Cloudways (Containerized Sites)

**Scenario**: 50 containerized WordPress sites sharing compute across servers.

**Configuration**:
1. Designate 3 servers as "compute hubs" with larger VMs
2. On hub servers, configure Ollama with llama3-70b for complex tasks
3. On all sites, enable mesh and add compute hubs as peers
4. Configure assistants with:
   ```
   Routing Strategy: AI Optimized
   Compute Hubs: hub-1, hub-2, hub-3
   Enable Retry: Yes
   ```

**Result**: 
- Simple queries stay local (fast)
- Complex queries routed to compute hubs (powerful)
- Load balanced across 3 hubs (resilient)
- Automatic failover if hub goes down

#### SiteGround (Shared/Multi-Site)

**Scenario**: 20 client sites on SiteGround shared hosting + 1 dedicated server.

**Configuration**:
1. Set up dedicated server as compute hub with Ollama
2. On shared hosting sites:
   - Enable mesh
   - Add dedicated server as only peer
   - Configure routing: "Preferred with Fallback"
   - Preferred: Dedicated server
   - Fallback: Local OpenAI
3. On dedicated server:
   - Enable mesh (accept incoming)
   - Large Ollama models (e.g., mixtral-8x7b)

**Result**:
- Most queries go to dedicated server (cost-free)
- Fallback to OpenAI if dedicated server is busy
- Shared hosting sites don't need powerful resources

#### Local Development

**Scenario**: Local dev environment with Ollama + staging sites in cloud.

**Configuration**:
1. Local machine:
   ```
   Ollama: llama3, mistral, codellama
   Enable mesh, generate inbound key
   ```

2. Staging sites:
   ```
   Add local machine as peer
   Routing: Preferred with Fallback
   Preferred: Local machine (dev)
   Fallback: OpenAI (when local is off)
   ```

**Result**:
- Free local AI during development
- Auto-switch to OpenAI when laptop is closed
- Same code works in both environments

#### Mixed Hosting (Cloudways + SiteGround + VPS)

**Scenario**: Sites across different hosts, want centralized compute.

**Configuration**:
1. VPS Server (compute hub):
   ```
   Ollama: llama3-70b, mixtral-8x7b
   OpenAI: gpt-4o (for hardest tasks)
   Static IP or domain
   ```

2. All sites (any host):
   ```
   Enable mesh
   Add VPS as compute hub
   Routing: AI Optimized
   ```

**Result**:
- Centralized compute management
- Mixed local/cloud routing
- Host-independent architecture
- Easy to add/remove sites

---

## Routing Strategies

### AI Optimized (Recommended)

**Best for**: Most use cases. Intelligent, automatic decisions.

**How it works**:
1. Analyzes prompt complexity (1-10 scale)
2. Scores each peer/provider on:
   - Response time (30% weight)
   - Current load (25% weight)
   - Success rate (25% weight)
   - Compute hub priority (20% weight)
3. Selects highest-scoring option
4. Retries with next-best on failure

**Example scoring**:
```
Prompt: "Explain quantum mechanics in detail"
Complexity: 8/10

Peer A (Regular Site):
  - Response time: 2s = 80 points
  - Load: 5 requests = 75 points
  - Success rate: 95% = 95 points
  - Not compute hub = 0 points
  TOTAL: 250 points

Peer B (Compute Hub):
  - Response time: 3s = 70 points
  - Load: 10 requests = 50 points
  - Success rate: 98% = 98 points
  - Is compute hub + complex = 20 points
  TOTAL: 238 points

Winner: Peer A (higher score)
```

### Round Robin

**Best for**: Even distribution when all peers are equal.

**How it works**:
- Cycles through peers sequentially
- Ignores complexity and load
- Simple, predictable

**Use when**:
- All sites have identical capacity
- Want perfectly even distribution
- Don't need optimization

### Least Loaded

**Best for**: Balancing current load only.

**How it works**:
- Tracks active requests per peer
- Always selects peer with fewest active requests
- Ignores complexity and response times

**Use when**:
- Peers have similar capabilities
- Current load is your main concern
- Simple load balancing sufficient

### Preferred with Fallback

**Best for**: Explicit control, specific site preferences.

**How it works**:
1. Tries preferred peers in order
2. Falls back to any healthy peer if preferred are down
3. No scoring, just priority order

**Use when**:
- Have specific performance requirements
- Know your sites' capabilities
- Want manual control over routing
- Testing specific configurations

---

## Compute Hub Configuration

### What is a Compute Hub?

A **compute hub** is a peer site designated for heavy workloads:
- Larger VM or dedicated server
- More powerful models (Ollama with 70B+ params)
- Higher rate limits
- Better GPUs for local AI

### When to Use Compute Hubs

**Designate as compute hub if**:
- Site has 4+ CPU cores
- Site has 16GB+ RAM
- Site runs Ollama with large models
- Site has dedicated GPU
- Site has higher OpenAI rate limits

**Don't designate as compute hub if**:
- Shared hosting
- 2GB RAM or less
- No local AI models
- General WordPress site

### Configuration Example

**3 Servers, 50 Sites Setup**:

**Server 1** (Compute Hub):
```
Specs: 8 cores, 32GB RAM, GPU
Ollama: llama3-70b, mixtral-8x7b
Sites: 5 sites
Role: Compute hub for complex tasks
```

**Server 2** (Compute Hub):
```
Specs: 8 cores, 32GB RAM
OpenAI: High rate limit tier
Sites: 10 sites
Role: Cloud compute hub
```

**Server 3** (Regular):
```
Specs: 4 cores, 8GB RAM
Sites: 35 sites
Role: Handle local simple queries
```

**Routing Configuration** (all 50 sites):
```
Routing Strategy: AI Optimized
Compute Hubs: server-1, server-2
Preferred Peers: [leave empty for auto]
Enable Retry: Yes
Max Retries: 3
```

**Result**:
- Simple queries: Stay on local site (fast)
- Complex queries: Route to Server 1 or 2 (powerful)
- Failover: If hub is down, try other hub or local
- Load: Automatically balanced across hubs

---

## Monitoring and Health Metrics

### Health Metrics Tracked

For each peer/provider, the system tracks:

- **Response Time**: Average response time (rolling average)
- **Success Rate**: Percentage of successful requests (last 100)
- **Current Load**: Estimated active requests
- **Status**: healthy, degraded, or down

### Viewing Metrics

**Via WP-CLI**:
```bash
# View all health metrics
wp option get wp_mcp_ai_mesh_health_metrics --format=json

# View routing statistics
wp option get wp_mcp_ai_mesh_routing_stats --format=json
```

**Via Code**:
```php
$metrics = get_option( 'wp_mcp_ai_mesh_health_metrics', array() );
foreach ( $metrics as $peer_name => $health ) {
    echo "$peer_name: " . $health['status'] . " (" . $health['success_rate'] . "% success)\n";
}
```

### Health Status Meanings

- **healthy**: Success rate ≥ 80%, accepting requests
- **degraded**: Success rate 50-80%, may be slow
- **down**: Success rate < 50%, excluded from routing

### Automatic Cleanup

- Metrics older than 5 minutes are automatically removed
- Prevents stale data from affecting routing
- Fresh health checks on every request

---

## Troubleshooting

### Issue: All peers show as "down"

**Cause**: Health metrics indicate all peers are failing requests.

**Solutions**:
1. Check mesh API keys are correct
2. Verify peer sites are accessible (not firewalled)
3. Test manually: `curl -X POST https://peer-site.com/wp-json/mcp-ai/v1/chat -H "X-WP-MCP-AI-Mesh-Key: YOUR_KEY"`
4. Clear health metrics: `wp option delete wp_mcp_ai_mesh_health_metrics`
5. Check peer site error logs

### Issue: Routing always picks same peer

**Cause 1**: Only one peer is healthy.
- **Solution**: Fix unhealthy peers or add more peers.

**Cause 2**: Using "Preferred with Fallback" strategy.
- **Solution**: Switch to "AI Optimized" for distribution.

**Cause 3**: One peer has significantly better metrics.
- **Solution**: This is expected! The peer is legitimately better.

### Issue: Single-site routing not working

**Cause 1**: Only one provider configured.
- **Solution**: Add at least two providers (OpenAI + Gemini or OpenAI + Ollama).

**Cause 2**: Routing strategy is "Preferred with Fallback" but no peers exist.
- **Solution**: Switch to "AI Optimized" for single-site multi-provider routing.

**Cause 3**: All providers failing.
- **Solution**: Check API keys, rate limits, and connectivity.

### Issue: High costs despite AI routing

**Cause 1**: Not using cheaper models for simple queries.
- **Solution**: Verify GPT-4o-mini is configured as default light model.

**Cause 2**: Ollama not working, falling back to cloud.
- **Solution**: Test Ollama: `curl http://localhost:11434/api/generate`

**Cause 3**: All queries classified as complex.
- **Solution**: Check prompts - may genuinely be complex.

### Issue: Slow response times

**Cause 1**: Routing to distant peers.
- **Solution**: Use geographically closer peers or local Ollama.

**Cause 2**: Compute hubs overloaded.
- **Solution**: Add more compute hubs or use "Least Loaded" strategy.

**Cause 3**: Retry attempts accumulating.
- **Solution**: Fix underlying peer issues causing retries.

---

## Advanced Configuration

### Custom Routing Filters

```php
// Customize AI routing behavior
add_filter( 'wp_mcp_ai_mesh_router_peer_score', function( $score, $peer, $health, $complexity ) {
    // Boost score for peers in same datacenter
    if ( strpos( $peer['url'], '.local' ) !== false ) {
        $score += 50;
    }
    
    // Penalize peers during maintenance window
    $hour = (int) date( 'H' );
    if ( $hour >= 2 && $hour <= 4 ) { // 2-4 AM
        $score -= 100;
    }
    
    return $score;
}, 10, 4 );
```

### Provider-Specific Routing

```php
// Route sensitive data to Ollama only
add_filter( 'wp_mcp_ai_mesh_router_provider_priority', function( $providers, $prompt ) {
    $sensitive_keywords = array( 'pii', 'ssn', 'password', 'credit card' );
    
    foreach ( $sensitive_keywords as $keyword ) {
        if ( stripos( $prompt, $keyword ) !== false ) {
            // Force Ollama for sensitive data
            return array( 'ollama' );
        }
    }
    
    return $providers;
}, 10, 2 );
```

### Cost-Based Routing

```php
// Prefer free/cheap providers
add_filter( 'wp_mcp_ai_mesh_router_cost_factor', function( $score, $provider ) {
    $costs = array(
        'ollama'  => 0,     // Free
        'gemini'  => 0.001, // Cheap
        'openai'  => 0.01,  // Moderate
    );
    
    $cost = isset( $costs[ $provider ] ) ? $costs[ $provider ] : 0.01;
    
    // Boost free providers
    if ( $cost === 0 ) {
        $score += 100;
    }
    
    return $score;
}, 10, 2 );
```

---

## Performance Tips

### 1. Use Local Ollama for Cost Savings

**Recommended setup**:
- Install Ollama on one compute hub
- Use llama3 or mistral for 80% of queries
- Reserve OpenAI for queries that need latest knowledge

**Savings**: Can reduce costs by 70-90%

### 2. Configure Multiple Compute Hubs

**Don't**: Put all complex load on one server

**Do**: Spread across 2-3 compute hubs for:
- Better load distribution
- Redundancy (if one fails)
- Different model specializations

### 3. Set Appropriate Retry Limits

**Low retries (1-2)**: When speed is critical, tolerate failures
**Medium retries (3-4)**: Balanced resilience and speed (recommended)
**High retries (5+)**: When reliability is critical, accept slower responses

### 4. Monitor and Adjust

**Check monthly**:
- Which peers handle most load?
- What's the cost per provider?
- Any consistently failing peers?
- Average response times?

**Adjust**:
- Add/remove peers as needed
- Upgrade compute hubs if bottlenecked
- Tune routing strategy based on patterns

---

## Related Documentation

- [Mesh Compute Pooling](mesh-compute-pooling.md) - Authentication and architecture
- [Tool Reference](tool-reference.md) - All 65+ tools including mesh tools
- [REST API](rest-api.md) - Mesh API endpoints
- [Best Practices](BEST_PRACTICES.md) - Usage recommendations

---

**Maintained by:** NV Digital Solutions  
**Repository:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos  
**License:** GPLv3 or later  
**Support:** https://nvdigitalsolutions.com/support
