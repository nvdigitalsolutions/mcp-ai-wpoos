# Multi-Site Gateway — Comprehensive Implementation Plan

> **Status:** Draft · **Version:** 0.1.0 · **Date:** 2026-08-11
> **Target:** mcp-ai-wpoos v1.5.0+ (Phase 8 of Toolkit MCP Server Fleet — ADR 002)
>
> **Key Insight:** The plugin already has 80% of the architecture needed for
> multi-site federation. This plan closes the remaining 20%.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Research & Industry Standards](#2-research--industry-standards)
3. [Current State Audit](#3-current-state-audit)
4. [Target Architecture](#4-target-architecture)
5. [Gap Analysis](#5-gap-analysis)
6. [Implementation Phases](#6-implementation-phases)
7. [File Manifest](#7-file-manifest)
8. [Testing Strategy](#8-testing-strategy)
9. [Risk Register](#9-risk-register)

---

## 1. Executive Summary

The Design Stack currently operates as a **single-tenant** platform — one
WordPress instance, one mcp-ai-wpoos installation, one MCP endpoint. This plan
defines the transformation into a **hub-and-spoke federation** where one central
"Hub" site orchestrates multiple "Spoke" sites, each running their own
mcp-ai-wpoos instance.

### Why This Matters

- **Agencies** managing 5–200 client WordPress sites need a single AI control plane
- **Multi-brand e-commerce** (like the existing Parfumerie↔POS setup) needs
  cross-site workflows
- **Enterprise** deployments want centralized governance, auditing, and tool distribution
- **The plugin's own roadmap** already signals this direction: ADR 002's
  "Toolkit MCP Server Fleet" with 29 per-toolkit servers is a fragmentation
  problem that a Gateway solves

### Key Numbers

| Metric | Current | Target |
|--------|---------|--------|
| Managed sites per Hub | 1 (itself) | 50+ verified |
| MCP endpoints to configure in clients | 2+ (one per site) | 1 (Gateway) |
| Time to provision a new spoke | Manual (hours) | < 5 min (one command) |
| Cross-site tool calls | Not possible | < 500ms overhead |
| Site health visibility | None | < 5 min detection |

---

## 2. Research & Industry Standards

### 2.1 MCP Protocol Evolution

The Model Context Protocol has undergone three major specification milestones
relevant to this plan:

| Spec Version | Key Features for Multi-Site |
|---|---|
| **2024-11-25** (initial) | Client-host-server architecture; one host → many clients; JSON-RPC transport |
| **2025-11-25** | Streamable HTTP transport; single `/mcp` endpoint; origin header validation for DNS rebinding protection |
| **2026-07-28** (RC) | **Stateless protocol core** — any request can land on any instance behind round-robin LB; self-describing requests with optional discovery call; authorization iss parameter validation per RFC 9207; improved OAuth 2.0/OIDC alignment |

**Relevance to this plan:**
- The 2026-07-28 stateless core is the ideal target for the Gateway — it means
  the Gateway itself can be scaled horizontally without sticky sessions.
- The `iss` parameter validation (SEP-2468) is directly applicable to the
  "single-client, many-server" pattern that this plan implements.

> **Sources:** [MCP Specification — Architecture][mcp-arch], [2026-07-28 RC Blog Post][mcp-rc],
> [The New Stack — MCP Roadmap 2026][mcp-roadmap]

[mcp-arch]: https://modelcontextprotocol.io/specification/2025-06-18/architecture
[mcp-rc]: https://blog.modelcontextprotocol.io/posts/2026-07-28-release-candidate/
[mcp-roadmap]: https://thenewstack.io/model-context-protocol-roadmap-2026/

### 2.2 MCP Gateway Pattern (Industry Standard)

The MCP Gateway is the emerging standard pattern for managing multi-server MCP
deployments. Key implementations:

| Vendor | Approach | Relevant Features |
|--------|----------|-------------------|
| **Kong** | Centralized gateway | Auth, rate limiting, tool routing, observability across MCP servers |
| **Cloudflare** | Edge-deployed workers | Separation of AI client from corporate credentials; OAuth bridging |
| **Azure API Management** | Managed gateway service | MCP server integration, policy enforcement, Azure AD auth |
| **Automattic (WordPress/mcp-adapter)** | Abilities API bridge | Plugin-level ability registration → MCP protocol mapping |

**Common patterns across all implementations:**

1. **Centralized auth** — Single token validated at Gateway, forwarded or re-minted per backend
2. **Tool namespacing** — `{source}::{tool_name}` to avoid collisions
3. **Health-aware routing** — Circuit breaker pattern for unhealthy backends
4. **Config sync** — Gateway pushes configuration to backends
5. **Observability** — Unified logging, metrics, tracing across all backends

**Relevance to this plan:**
The built-in Graphify addon (`addons/graphify/` — bundled with mcp-ai-wpoos
base+pro) already implements patterns 1, 2, 3, and 5 via its
`Remote_Source_Interface` and `oos_federation` driver. This plan extends those
patterns into the mcp-ai-wpoos Gateway core.

> **Sources:** [Kong — What is an MCP Gateway?][kong-gateway],
> [Cloudflare — Enterprise MCP Reference Architecture][cf-mcp],
> [Azure — MCP Servers in API Management][azure-mcp]

[kong-gateway]: https://konghq.com/blog/learning-center/what-is-a-mcp-gateway
[cf-mcp]: https://blog.cloudflare.com/enterprise-mcp/
[azure-mcp]: https://learn.microsoft.com/en-us/azure/api-management/mcp-server-overview

### 2.3 WordPress Multi-Tenant Architecture

Three proven models for multi-site WordPress, ordered by isolation level:

| Model | Isolation | Complexity | Best For |
|-------|-----------|------------|----------|
| **WordPress Multisite (WPMS)** | Shared code, shared DB, per-site tables | Low | Homogeneous sites, same team |
| **Container-per-site (Docker/K8s)** | Process-level, separate DBs | Medium-High | Heterogeneous sites, agencies |
| **Headless + API Gateway** | Full separation | High | Enterprise, microservices |

This plan uses **Model 2 (Container-per-site)** for the Docker Compose
development environment and supports **Model 3 (API Gateway)** for production
spoke sites that live on external hosts (e.g., theparfumerie.lk).

**Enterprise WordPress scaling best practices that inform this plan:**

- **Stateless architecture** (Pantheon) — Application logic separated from
  persistent storage; WordPress can run across multiple servers
- **White-label multi-tenant** (WPCS) — Kubernetes-managed isolated instances
  with centralized provisioning, updating, and monitoring
- **Plugin management at scale** — `DISALLOW_FILE_MODS` constant prevents
  dashboard plugin edits; all management through CLI/API

> **Sources:** [WP VIP — WordPress Scalability][wpvip],
> [Webmastered — White-Label WordPress Architecture][webmastered],
> [WPPoland — Multisite Enterprise Architecture 2026][wppoland],
> [Smackcoders — Multisite Best Practices 2026][smackcoders]

[wpvip]: https://wpvip.com/blog/wordpress-scalability/
[webmastered]: https://www.webmastered.com/blog/white-label-wordpress-scalable-architecture/
[wppoland]: https://wppoland.com/en/wordpress-multisite-enterprise-architecture-2026/
[smackcoders]: https://www.smackcoders.com/blog/wordpress-multisite-best-practices-the-essential-2026-admin-guide.html

### 2.4 WordPress MCP Ecosystem (2026 Landscape)

The WordPress MCP ecosystem is rapidly maturing. Key projects:

| Project | Maintainer | Approach |
|---------|-----------|----------|
| **mcp-ai-wpoos** (NV oOS) | NV Digital Solutions | Full-stack MCP plugin: 1,000+ tools, token auth, remote connections, 29 toolkit MCP servers |
| **mcp-adapter** | Automattic / WordPress.org | Abilities API → MCP bridge; canonical WordPress MCP standard |
| **WebMCP Bridge** | Community (.org) | Lightweight MCP server; no backend required |
| **WPVibe** | Community (.org) | WordPress content management via MCP |

**Key insight:** The mcp-ai-wpoos plugin's `remote_wp_connection` tool and
the built-in Graphify addon's `oos_federation` driver (in `addons/graphify/`)
are **years ahead** of the ecosystem in multi-site federation capability. This plan brings that capability into the
plugin core with a unified architecture.

> **Sources:** [Responsive Menu — 10 Best MCP Servers][best-mcp],
> [MiniOrange — Best MCP Server Plugins][mini-mcp],
> [Seahawk Media — How to Integrate MCP with WordPress][seahawk]

[best-mcp]: https://responsive.menu/10-best-wordpress-mcp-servers/
[mini-mcp]: https://www.miniorange.com/blog/best-mcp-plugins-wordpress/
[seahawk]: https://seahawkmedia.com/wordpress/integrate-mcp-with-wordpress/

### 2.5 Tool Federation & Multi-Server Composition

When one AI client connects to multiple MCP servers, tool naming collisions are
the primary issue. Industry patterns:

```
# Namespacing pattern (adopted by this plan):
hub::search_content        # Hub site's content search
kaya::search_content       # Kaya spoke's content search
myyco::create_woo_product  # Myyco spoke's WooCommerce
parfum::get_wc_orders      # Parfumerie's orders

# Discovery pattern (adopted by this plan):
tools/list returns ALL tools from ALL sites, namespaced
tools/call routes to the correct spoke based on prefix
```

**Performance considerations:**
- Tool list caching (Redis, 5-min TTL) to avoid N+1 calls to spokes on every
  `tools/list`
- Lazy tool discovery — only fetch tools for a site when first requested
- Circuit breaker — stop routing to failed sites after 3 consecutive errors

> **Source:** [GetKnit — Scaling AI with Multiple MCP Servers][getknit]

[getknit]: https://www.getknit.dev/blog/scaling-ai-capabilities-using-multiple-mcp-servers-with-one-agent

---

## 3. Current State Audit

### 3.1 What Already Exists (80% Done)

The plugin and its ecosystem already contain substantial multi-site
infrastructure. This plan is about **unifying and elevating** existing
capabilities, not building from scratch.

#### A. `remote_wp_connection` Tool (mcp-ai-wpoos core)

```php
// Already working — I confirmed this with live data from theparfumerie.lk
// Supports: list_connections, test_connection, get_posts, get_media,
//           get_wc_products, get_wc_orders, create_post, update_post, etc.
```

| Capability | Status | Notes |
|---|---|---|
| Connection discovery | ✅ `list_connections` | Returns 2 connections currently (POS + Parfumerie) |
| Content queries | ✅ `get_posts`, `get_media` | Tested successfully against Parfumerie (48K+ media items) |
| WooCommerce ops | ✅ `get_wc_products`, `get_wc_orders` | WooCommerce-aware |
| Write operations | ✅ `create_post`, `create_wc_product` | Requires connection-level permission enablement |
| JetEngine CCT | ✅ `list_jetengine_ccts`, CRUD | Full CCT support |
| Tool discovery | ❌ Missing | No way to list tools available on remote site |
| MCP-level federation | ❌ Missing | Connections are data-only, not MCP-tool-aware |

#### B. Graphify Addon — Remote Sources Architecture (`addons/graphify/`)

```php
// Bundled with mcp-ai-wpoos base+pro — production-grade federation blueprint
NV_oOS_Graphify_Remote_Registry::get_instance()        // Singleton registry
  → register_driver( NV_oOS_Graphify_Remote_Source_Interface $driver )
  → get_active_sources()                               // Loads from DB with encryption

NV_oOS_Graphify_Remote_OOS_Federation                   // oOS/MCP site driver
  → test_connection()                                   // REST API health check
  → discover()                                          // Available content metadata
  → fetch_nodes( $args )                                // Paginated content import
  → reconcile( $local_node )                            // Cross-site entity matching
```

| Pattern | Status | Reusable for Gateway? |
|---------|--------|----------------------|
| Driver interface | ✅ `NV_oOS_Graphify_Remote_Source_Interface` | Yes — identical contract |
| Registry + DB persistence | ✅ Custom table `nvoos_graph_remote_sources` | Yes — adapt schema |
| Encrypted credentials | ✅ `NV_oOS_Graphify_Crypto` with `is_sensitive_key()` | Yes — reuse directly |
| Circuit breaker | ✅ `circuit_state` field (closed/half_open/open) | Yes — inherit |
| Rate limiting | ✅ `rate_limit` field | Yes — inherit |
| Admin UI | ✅ `NV_oOS_Graphify_Remote_Admin` | Yes — adapt |
| REST API | ✅ CRUD endpoints | Yes — extend |
| Tool integration | ✅ `graphify_list_remote_sources`, `graphify_sync_remote_source` | Yes — add MCP-tool variants |
| **MCP tool discovery** | ❌ Missing | Key new capability needed |
| **MCP tools/call routing** | ❌ Missing | Key new capability needed |

> **Note:** Graphify is bundled inside mcp-ai-wpoos at `addons/graphify/`.
> When the Pro toolkit is enabled, Graphify loads as a first-party addon via
> the plugin's addon autoloader. The standalone `plugins/nvoos-graphify/` is
> a distribution copy only.

#### C. Toolkit MCP Server Fleet (ADR 002)

```yaml
# Already deployed: 29 toolkit MCP servers, each with:
#   - JSON-RPC endpoint: /wp-json/mcp-ai-pro/v1/mcp/{slug}
#   - Discovery descriptor
#   - Per-server admin governance
```

This is proof that the plugin's infrastructure supports per-endpoint MCP
servers. The Gateway extends this from toolkit-level to site-level.

#### D. Sophie Agent Tools

The `nv_oos_sophie_agent_*` tools (web_search, search_content, search_gmail,
search_drive, generate_gemini_image, etc.) are functional within the current
WordPress instance. These demonstrate agent-scoped toolkits — a pattern that
maps directly to per-spoke agents.

#### E. Docker Compose Infrastructure

```yaml
# design-stack/docker-compose.yml already has:
#   - WordPress + MySQL + Redis + Media Worker
#   - bind-mount volumes for plugin development
#   - .env-based configuration
#   - seed container for automated setup
#   - on-demand wp-cli profile
```

### 3.2 What's Missing (The 20% Gap)

| Gap | Priority | Description |
|-----|----------|-------------|
| **1. Site Registry** | P0 | No central registry of spoke sites with connection status, tool catalogs, and health |
| **2. MCP Tool Namespace** | P0 | No prefix-based tool routing across sites |
| **3. Gateway MCP Endpoint** | P0 | No single endpoint that federates all sites' tools |
| **4. Cross-Site Token Scoping** | P1 | Tokens are site-local; no site-scoped credential model |
| **5. Spoke Provisioning** | P1 | Manual setup; no automated provision script |
| **6. Config Sync** | P1 | No push-based config distribution from Hub to spokes |
| **7. Multi-Site Docker Compose** | P2 | Single WP instance; no profile-based multi-site |
| **8. Health Dashboard** | P2 | No unified health view across sites |
| **9. Plugin Version Tracking** | P3 | No awareness of spoke plugin versions |
| **10. Observability** | P3 | No cross-site logging or metrics |

---

## 4. Target Architecture

```
┌──────────────────────────────────────────────────────────┐
│                    AI CLIENT (Zed / Claude / Cursor)      │
│                    One MCP connection → Gateway           │
└───────────────────────────┬──────────────────────────────┘
                            │ MCP JSON-RPC over HTTP
                            │ Bearer cred_xxxxx.HUB_MASTER
┌───────────────────────────▼──────────────────────────────┐
│                    DESIGN HUB (Central WordPress)         │
│                                                          │
│  ┌────────────────────────────────────────────────────┐  │
│  │              MCP GATEWAY LAYER                      │  │
│  │  ┌──────────┐ ┌──────────┐ ┌────────────────────┐  │  │
│  │  │  Site    │ │  Tool    │ │  Credential         │  │  │
│  │  │ Registry │ │  Catalog │ │  Broker             │  │  │
│  │  │          │ │          │ │                     │  │  │
│  │  │ • CRUD   │ │ • Merge  │ │ • Issue site tokens │  │  │
│  │  │ • Health │ │ • Cache  │ │ • Validate scopes   │  │  │
│  │  │ • Status │ │ • Route  │ │ • Auto-rotate       │  │  │
│  │  └──────────┘ └──────────┘ └────────────────────┘  │  │
│  │  ┌──────────┐ ┌──────────┐ ┌────────────────────┐  │  │
│  │  │  Config  │ │  Circuit │ │  Observability      │  │  │
│  │  │  Sync    │ │  Breaker │ │                     │  │  │
│  │  │          │ │          │ │ • Unified logs      │  │  │
│  │  │ • Push   │ │ • Fail   │ │ • Cross-site timing │  │  │
│  │  │ • Pull   │ │ • Retry  │ │ • Alert rules       │  │  │
│  │  │ • Diff   │ │ • Drain  │ │                     │  │  │
│  │  └──────────┘ └──────────┘ └────────────────────┘  │  │
│  └────────────────────────────────────────────────────┘  │
│                                                          │
│  WordPress Core + mcp-ai-wpoos + Design Hub Gateway       │
│  ┌────────────┐ ┌──────────────┐ ┌───────────────────┐  │
│  │ Hub Local  │ │ Site Manager │ │ Cross-Site         │  │
│  │ Tools      │ │ Admin UI     │ │ Workflow Tools     │  │
│  │ (325+)     │ │              │ │ (sync, migrate)    │  │
│  └────────────┘ └──────────────┘ └───────────────────┘  │
└───────────────────────────┬──────────────────────────────┘
                            │ REST API (per spoke)
                            │ Bearer cred_xxxxx.SITE_TOKEN
        ┌───────────────────┼───────────────────┐
        │                   │                   │
┌───────▼──────┐   ┌────────▼──────┐   ┌───────▼──────┐
│ SITE A: Kaya │   │ SITE B: Myyco │   │ SITE C: Parf │
│ ──────────── │   │ ───────────── │   │ ──────────── │
│ WP + mcp-ai  │   │ WP + mcp-ai   │   │ WP + mcp-ai  │
│ Own DB       │   │ Own DB        │   │ Own DB       │
│ Own uploads  │   │ Own uploads    │   │ Own uploads  │
│ Site-scoped  │   │ Site-scoped    │   │ Site-scoped  │
│ assistants   │   │ assistants     │   │ assistants   │
│              │   │ + WooCommerce  │   │ + WooCommerce│
└──────────────┘   └───────────────┘   └──────────────┘
   localhost:8093     localhost:8094      theparfumerie.lk
   (Docker profile)   (Docker profile)    (external host)
```

### 4.1 Key Architectural Decisions

**AD-1: Hub as spoke.** The Hub runs its own WordPress instance with local tools.
It registers itself as site `hub` in the registry. This means `tools/list`
returns both `hub::search_content` (local) and `kaya::search_content` (remote).

**AD-2: Namespaced tools.** All tools from spoke sites are namespaced with the
site slug prefix. Hub tools are available both namespaced (`hub::search_content`)
and bare (`search_content`) for backward compatibility.

**AD-3: Tool catalog caching.** The merged tool catalog is cached in Redis with a
5-minute TTL. On cache miss, the Gateway queries all connected spokes in parallel
(WordPress cron or Action Scheduler batch).

**AD-4: Circuit breaker per site.** After 3 consecutive failures, a spoke is
marked `circuit_state=open`. After 60 seconds, it transitions to `half_open` and
allows one probe request. Success resets to `closed`; failure re-opens the
circuit.

**AD-5: Hub-managed credentials.** Spoke site tokens are issued and managed by
the Hub. Spokes never need to expose their own credential management. The Hub
holds the spoke's `cred_xxxxx.SECRET` token (encrypted in DB) and uses it to
make authenticated calls on behalf of the AI client.

**AD-6: Profile-based Docker Compose.** Spoke sites use Docker Compose profiles
to avoid resource contention during development. Default profile (`docker compose
up -d`) starts only the Hub. Add `--profile kaya` to start Kaya's containers.

### 4.2 Data Flow: tools/call Example

```
1. AI Client → Gateway: tools/call { name: "kaya::search_content", args: {...} }
2. Gateway parses "kaya::" prefix → looks up site "kaya" in Registry
3. Gateway checks circuit state → closed ✅
4. Gateway retrieves encrypted site token → decrypts
5. Gateway → Spoke Kaya: POST /wp-json/mcp-ai/v1/mcp
      Authorization: Bearer cred_site_kaya.SECRET
      { method: "tools/call", params: { name: "search_content", ... } }
6. Spoke Kaya executes tool, returns result
7. Gateway → AI Client: forwards result
8. Gateway records: site=kaya, tool=search_content, duration=234ms, status=success
```

---

## 5. Gap Analysis

Mapping each gap to its solution and effort:

| # | Gap | Solution | Reuses | Estimated Effort |
|---|-----|----------|--------|-----------------|
| 1 | Site Registry | New `wp_mcp_ai_site_registry` table + `WP_MCP_AI_Site_Registry` class | Graphify's `nvoos_graph_remote_sources` schema | 8-12 hours |
| 2 | Tool Namespace | `Site_Tool_Catalog::namespace_tool( $site, $tool )` — prefix with `{site_slug}::` | n/a (new pattern) | 4-6 hours |
| 3 | Gateway Endpoint | New REST route `/wp-json/design-hub/v1/mcp` that wraps the MCP endpoint with routing logic | Existing MCP endpoint + Site Registry | 12-16 hours |
| 4 | Token Scoping | Extend `WP_MCP_AI_Credentials` with site-scoped token tier | Existing token infrastructure | 8-10 hours |
| 5 | Spoke Provisioning | `scripts/provision-site.sh` — Docker Compose profiles + auto-registration | `seed-toolkits.sh` + `create-assistant.php` | 6-8 hours |
| 6 | Config Sync | `WP_MCP_AI_Config_Sync` class — push toolkits/settings/keys to spokes via REST | `bridge-env-keys.php` pattern | 8-10 hours |
| 7 | Docker Compose | `docker-compose.sites.yml` with x-spoke-site YAML anchor | Existing `docker-compose.yml` | 6-8 hours |
| 8 | Health Dashboard | Admin page with site status grid, health metrics, latency graph | Graphify remote admin UI | 8-12 hours |
| 9 | Version Tracking | `site_registry.wp_version`, `plugin_version`, `php_version` columns | Existing `get_environment_status` tool | 2-4 hours |
| 10 | Observability | Activity log table, timing metrics, Redis-backed counters | Action Scheduler logs | 6-8 hours |

**Total estimated effort:** 68-94 hours (~3-5 weeks for one developer)

---

## 6. Implementation Phases

### Phase 1: Foundation — Site Registry & Spoke Infrastructure (Week 1-2)

**Goal:** Hub can register, discover, and health-check spoke sites. Spoke sites
can be provisioned with one command.

#### 1.1 Site Registry Database Table

```sql
CREATE TABLE wp_mcp_ai_site_registry (
    id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    site_slug       VARCHAR(64)         NOT NULL,
    site_name       VARCHAR(255)        NOT NULL DEFAULT '',
    site_url        VARCHAR(512)        NOT NULL,        -- Docker network URL
    public_url      VARCHAR(512)        DEFAULT NULL,    -- External/public URL
    rest_url        VARCHAR(512)        NOT NULL,        -- MCP endpoint
    site_token      TEXT                DEFAULT NULL,    -- Encrypted cred_ token
    wp_version      VARCHAR(16)         DEFAULT NULL,
    plugin_version  VARCHAR(16)         DEFAULT NULL,
    php_version     VARCHAR(16)         DEFAULT NULL,
    tool_count      INT(11)             NOT NULL DEFAULT 0,
    tool_list_cache LONGTEXT            DEFAULT NULL,    -- JSON cached tool list
    tool_cache_ttl  DATETIME            DEFAULT NULL,
    enabled         TINYINT(1)          NOT NULL DEFAULT 1,
    connection_status VARCHAR(16)       NOT NULL DEFAULT 'pending',
    circuit_state   VARCHAR(16)         NOT NULL DEFAULT 'closed',
    failure_count   INT(11)             NOT NULL DEFAULT 0,
    last_heartbeat  DATETIME            DEFAULT NULL,
    last_error      TEXT                DEFAULT NULL,
    rate_limit      INT(11)             NOT NULL DEFAULT 60,
    config_json     LONGTEXT            DEFAULT NULL,    -- Site-specific config overrides
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY site_slug (site_slug)
);
```

**Key design decisions:**
- `site_token` stored encrypted (reuse Graphify's `NV_oOS_Graphify_Crypto` from `addons/graphify/` or implement standalone)
- `tool_list_cache` + `tool_cache_ttl` for cache-before-network pattern
- `circuit_state` + `failure_count` from Graphify's proven pattern
- `config_json` for per-site overrides (toolkits, provider, model)

#### 1.2 Site Registry PHP Class

```php
class WP_MCP_AI_Site_Registry {

    // Singleton access
    public static function get_instance(): self;

    // CRUD
    public function register_site( array $data ): int|WP_Error;
    public function update_site( string $slug, array $data ): bool;
    public function delete_site( string $slug ): bool;
    public function get_site( string $slug ): ?object;
    public function list_sites( array $args = [] ): array;

    // Health & Status
    public function heartbeat( string $slug ): array;         // Calls remote /status
    public function heartbeat_all(): array;                   // Parallel via Action Scheduler
    public function get_connection_status( string $slug ): string;

    // Circuit Breaker
    public function is_circuit_open( string $slug ): bool;
    public function record_success( string $slug ): void;
    public function record_failure( string $slug, string $error ): void;

    // Tool Cache
    public function get_cached_tools( string $slug ): ?array;
    public function cache_tools( string $slug, array $tools ): void;
    public function invalidate_tool_cache( string $slug ): void;

    // Token Management
    public function get_site_token( string $slug ): ?string;  // Decrypts on read
    public function set_site_token( string $slug, string $token ): void;

    // Version Tracking
    public function update_version_info( string $slug, array $info ): void;
}
```

**Implementation notes:**
- Store as custom table (not CPT) for query performance at scale
- Use `wp_mkdir_p()` pattern for DB table creation (activation hook)
- Reuse the Graphify addon's crypto module (`addons/graphify/includes/class-nvoos-graphify-crypto.php`) or implement standalone AES-256-GCM encryption

#### 1.3 Spoke Registration REST Endpoint

```php
// POST /wp-json/design-hub/v1/sites/register
// Called by spoke site's seed script to auto-register
register_rest_route( 'design-hub/v1', '/sites/register', [
    'methods'             => 'POST',
    'permission_callback' => function( $request ) {
        // Shared registration secret (env var or option)
        $secret = defined( 'DESIGN_HUB_REGISTRATION_SECRET' )
            ? DESIGN_HUB_REGISTRATION_SECRET
            : get_option( 'design_hub_registration_secret', '' );
        $provided = $request->get_header( 'X-Registration-Secret' );
        return hash_equals( $secret, $provided );
    },
    'callback' => function( $request ) {
        $data = [
            'site_slug'  => sanitize_key( $request->get_param( 'site_slug' ) ),
            'site_name'  => sanitize_text_field( $request->get_param( 'site_name' ) ),
            'site_url'   => esc_url_raw( $request->get_param( 'site_url' ) ),
            'public_url' => esc_url_raw( $request->get_param( 'public_url' ) ),
        ];
        $result = WP_MCP_AI_Site_Registry::get_instance()->register_site( $data );
        // Also: auto-issue a site token and return it
    },
] );
```

#### 1.4 Docker Compose Spoke Template

New file: `docker-compose.sites.yml`

```yaml
# Spoke site service template using Docker Compose profiles
# Usage: docker compose --profile kaya up -d

x-spoke-wp: &spoke-wp
  build:
    context: .
    dockerfile: Dockerfile.wordpress
  image: design-stack-wordpress:local
  restart: unless-stopped
  environment:
    WORDPRESS_CONFIG_EXTRA: |
      define( 'WP_DEBUG_LOG', true );
      define( 'WP_DEBUG_DISPLAY', true );
      define( 'WP_ENVIRONMENT_TYPE', 'development' );
      define( 'WP_MEMORY_LIMIT', '512M' );
      define( 'WP_MEDIA_WORKER_URL', 'http://media-worker:3100' );
      define( 'DESIGN_HUB_URL', 'http://design-wp:80' );
      define( 'DESIGN_HUB_REGISTRATION_SECRET', '${DESIGN_HUB_REGISTRATION_SECRET}' );
      define( 'WP_REDIS_HOST', 'redis' );
      define( 'WP_REDIS_PORT', 6379 );
  volumes:
    - ${MCP_PLUGIN_PATH:-/mnt/f/GITHUB/mcp-ai-wpoos}:/var/www/html/wp-content/plugins/mcp-ai-wpoos
    - ${WP_PLUGIN_PATH:-./plugins}:/var/www/html/wp-content/plugins
  extra_hosts:
    - "host.docker.internal:host-gateway"

x-spoke-db: &spoke-db
  image: mysql:8.0
  restart: unless-stopped
  command: --default-authentication-plugin=mysql_native_password --max_allowed_packet=256M
  environment:
    MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:-design_root_2026}
    MYSQL_USER: wordpress
    MYSQL_PASSWORD: ${DB_PASSWORD:-design_pass_2026}
  healthcheck:
    test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-p${DB_ROOT_PASSWORD:-design_root_2026}", "--ssl-mode=DISABLED"]
    interval: 5s
    timeout: 10s
    retries: 20
    start_period: 30s

services:
  # ── Spoke: Kaya ──────────────────────────────────────
  wp-kaya:
    <<: *spoke-wp
    container_name: design-wp-kaya
    profiles: ["kaya", "all-sites"]
    ports: ["${KAYA_PORT:-8093}:80"]
    environment:
      WORDPRESS_DB_HOST: db-kaya:3306
      WORDPRESS_DB_NAME: kaya_wordpress
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: ${DB_PASSWORD:-design_pass_2026}
      WORDPRESS_SITE_NAME: "Kaya Wellness"
      WORDPRESS_SITE_SLUG: "kaya"
    volumes:
      - wp_core_kaya:/var/www/html
      - ./config/sites/kaya/uploads:/var/www/html/wp-content/uploads
      - ./config/sites/kaya/themes:/var/www/html/wp-content/themes
    depends_on:
      db-kaya:
        condition: service_healthy
      redis:
        condition: service_started

  db-kaya:
    <<: *spoke-db
    container_name: design-db-kaya
    profiles: ["kaya", "all-sites"]
    ports: ["${KAYA_DB_PORT:-3310}:3306"]
    environment:
      MYSQL_DATABASE: kaya_wordpress
    volumes:
      - wp_db_kaya:/var/lib/mysql

  seed-kaya:
    image: wordpress:cli-php8.2
    container_name: design-seed-kaya
    profiles: ["kaya"]
    user: "33:33"
    depends_on:
      db-kaya:
        condition: service_healthy
      wp-kaya:
        condition: service_started
    volumes:
      - wp_core_kaya:/var/www/html
      - ${MCP_PLUGIN_PATH:-/mnt/f/GITHUB/mcp-ai-wpoos}:/var/www/html/wp-content/plugins/mcp-ai-wpoos:ro
    entrypoint: ["sh", "-c"]
    command:
      - |
        # (Same as existing wp-seed but with site-specific config)
        # After WordPress install, runs auto-registration with Hub

  # ── Spoke: Myyco (WooCommerce) ───────────────────────
  wp-myyco:
    <<: *spoke-wp
    # ... same pattern with WooCommerce-specific seed ...

  # ── Spoke: Parfumerie (external host) ────────────────
  # NO Docker container — registered as external site
  # Registration happens via admin UI or WP-CLI:
  #   wp hub site add parfum --url=https://theparfumerie.lk --external

volumes:
  wp_core_kaya:
  wp_db_kaya:
  wp_core_myyco:
  wp_db_myyco:
  # ... etc
```

#### 1.5 Spoke Provisioning Script

New file: `scripts/provision-site.sh`

```bash
#!/bin/bash
# Usage: ./scripts/provision-site.sh <slug> <name> <port> <db_port> [--woocommerce]
# Example: ./scripts/provision-site.sh kaya "Kaya Wellness" 8093 3310

set -eu

SLUG="$1"
NAME="$2"
PORT="$3"
DB_PORT="$4"
WOO="${5:-}"

echo "=== Provisioning spoke site: $NAME ($SLUG) ==="

# 1. Create config directories
mkdir -p "config/sites/$SLUG/uploads"
mkdir -p "config/sites/$SLUG/themes"

# 2. Add env vars to .env
cat >> .env <<EOF

# Spoke: $NAME
${SLUG^^}_PORT=$PORT
${SLUG^^}_DB_PORT=$DB_PORT
EOF

# 3. Start spoke containers
wsl docker compose --profile "$SLUG" up -d

# 4. Wait for WP to be ready
echo "Waiting for $SLUG WordPress to be ready..."
until wsl docker compose exec -T "wp-$SLUG" curl -sf http://localhost:80; do
    sleep 2
done

# 5. Wait for seed to complete
wsl docker compose logs -f "seed-$SLUG" | grep -q "=== Spoke setup complete ==="

# 6. Verify registration with Hub
echo "Verifying Hub registration..."
wsl docker compose exec -T wordpress wp hub site list | grep "$SLUG"

echo "=== Spoke $NAME ($SLUG) provisioned ==="
echo "  WordPress: http://localhost:$PORT"
echo "  Admin:     http://localhost:$PORT/wp-admin"
echo "  Database:  localhost:$DB_PORT"
```

---

### Phase 2: Gateway Core — Tool Federation & Routing (Weeks 2-3)

**Goal:** AI clients connect to ONE MCP endpoint and transparently access tools
from all registered sites.

#### 2.1 Tool Catalog with Namespacing

```php
class WP_MCP_AI_Site_Tool_Catalog {

    /**
     * Merge and namespace tools from all connected sites.
     *
     * @return array MCP-compatible tool list with namespaced names.
     */
    public function get_all_tools_namespaced(): array {
        $sites  = WP_MCP_AI_Site_Registry::get_instance()->list_sites( ['enabled' => 1] );
        $merged = [];

        // Hub tools (local) — available both namespaced and bare
        foreach ( $this->get_hub_tools() as $tool ) {
            $merged[] = $tool;                              // bare: "search_content"
            $merged[] = $this->namespace_tool( 'hub', $tool ); // namespaced: "hub::search_content"
        }

        // Spoke tools — namespaced only
        foreach ( $sites as $site ) {
            if ( $site->site_slug === 'hub' ) continue;
            if ( WP_MCP_AI_Site_Registry::get_instance()->is_circuit_open( $site->site_slug ) ) {
                continue; // Skip unhealthy sites
            }
            $tools = $this->get_site_tools( $site );
            foreach ( $tools as $tool ) {
                $merged[] = $this->namespace_tool( $site->site_slug, $tool );
            }
        }

        return $merged;
    }

    /**
     * Fetch tools from a spoke site, with caching.
     */
    public function get_site_tools( object $site ): array {
        // Check cache first
        $cached = WP_MCP_AI_Site_Registry::get_instance()->get_cached_tools( $site->site_slug );
        if ( $cached !== null ) {
            return $cached;
        }

        // Fetch from remote: POST {rest_url} with method=tools/list
        $token    = WP_MCP_AI_Site_Registry::get_instance()->get_site_token( $site->site_slug );
        $response = $this->call_remote( $site->rest_url, $token, 'tools/list', [] );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Site_Registry::get_instance()->record_failure(
                $site->site_slug, $response->get_error_message()
            );
            return [];
        }

        $tools = $response['result']['tools'] ?? [];

        // Cache for 5 minutes
        WP_MCP_AI_Site_Registry::get_instance()->cache_tools( $site->site_slug, $tools );

        return $tools;
    }

    private function namespace_tool( string $site_slug, array $tool ): array {
        $tool['name'] = $site_slug . '::' . $tool['name'];
        return $tool;
    }
}
```

#### 2.2 Gateway MCP Endpoint

```php
// New REST route: /wp-json/design-hub/v1/mcp
// This is the single entry point that AI clients connect to.

class WP_MCP_AI_Gateway_Controller {

    public function handle_mcp_request( WP_REST_Request $request ) {
        $body    = json_decode( $request->get_body(), true );
        $method  = $body['method'] ?? '';
        $params  = $body['params'] ?? [];
        $token   = $this->extract_bearer_token( $request );

        // Validate token
        $valid = WP_MCP_AI_Credentials::validate_token( $token );
        if ( is_wp_error( $valid ) ) {
            return $this->jsonrpc_error( -32001, 'Unauthorized' );
        }

        switch ( $method ) {
            case 'initialize':
                return $this->handle_initialize( $params );

            case 'tools/list':
                return $this->handle_tools_list( $params );

            case 'tools/call':
                return $this->handle_tools_call( $params );

            default:
                return $this->jsonrpc_error( -32601, 'Method not found' );
        }
    }

    private function handle_tools_call( array $params ): array {
        $tool_name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        // Parse "site_slug::tool_name" or default to hub
        $parts = explode( '::', $tool_name, 2 );
        if ( count( $parts ) === 2 ) {
            $site_slug  = $parts[0];
            $local_tool = $parts[1];
        } else {
            $site_slug  = 'hub';
            $local_tool = $parts[0];
        }

        if ( $site_slug === 'hub' ) {
            // Execute locally
            return $this->execute_local_tool( $local_tool, $arguments );
        }

        // Route to spoke
        return $this->execute_remote_tool( $site_slug, $local_tool, $arguments );
    }

    private function execute_remote_tool(
        string $site_slug, string $tool_name, array $arguments
    ): array {
        $registry = WP_MCP_AI_Site_Registry::get_instance();

        // Circuit breaker check
        if ( $registry->is_circuit_open( $site_slug ) ) {
            return $this->jsonrpc_error( -32002, "Site '$site_slug' is temporarily unavailable" );
        }

        $site  = $registry->get_site( $site_slug );
        if ( ! $site ) {
            return $this->jsonrpc_error( -32003, "Site '$site_slug' not found" );
        }

        $token    = $registry->get_site_token( $site_slug );
        $start    = microtime( true );
        $response = $this->call_remote( $site->rest_url, $token, 'tools/call', [
            'name'      => $tool_name,
            'arguments' => $arguments,
        ] );
        $duration = ( microtime( true ) - $start ) * 1000;

        if ( is_wp_error( $response ) ) {
            $registry->record_failure( $site_slug, $response->get_error_message() );
            $this->log_activity( $site_slug, $tool_name, $duration, 'error' );
            return $this->jsonrpc_error( -32000, $response->get_error_message() );
        }

        $registry->record_success( $site_slug );
        $this->log_activity( $site_slug, $tool_name, $duration, 'success' );

        return $response;
    }
}
```

#### 2.3 Hub Self-Registration

On plugin activation, the Hub registers itself:

```php
public static function register_hub_site() {
    $registry = WP_MCP_AI_Site_Registry::get_instance();

    if ( $registry->get_site( 'hub' ) ) {
        return; // Already registered
    }

    $registry->register_site( [
        'site_slug'        => 'hub',
        'site_name'        => get_bloginfo( 'name' ) . ' (Hub)',
        'site_url'         => home_url(),
        'public_url'       => home_url(),
        'rest_url'         => rest_url( 'mcp-ai/v1/mcp' ),
        'connection_status' => 'connected',
        'enabled'          => true,
    ] );
}

// Hook: register_activation_hook + on plugins_loaded if not yet registered
```

---

### Phase 3: Configuration & Management (Weeks 3-4)

**Goal:** Administrators can manage all sites from the Hub, push configurations,
and monitor health.

#### 3.1 Site Manager Admin Page

```php
// New admin page under "NV oOS" menu
class WP_MCP_AI_Site_Manager_Admin {

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        $sites = WP_MCP_AI_Site_Registry::get_instance()->list_sites();
        ?>
        <div class="wrap">
            <h1>Multi-Site Gateway — Site Manager</h1>

            <!-- Add Site Form -->
            <div class="card">
                <h2>Add New Site</h2>
                <form method="post" action="">
                    <?php wp_nonce_field( 'add_site' ); ?>
                    <!-- fields: slug, name, url, public_url, is_external -->
                </form>
            </div>

            <!-- Site List -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Site</th>
                        <th>Status</th>
                        <th>Tools</th>
                        <th>WP Version</th>
                        <th>Plugin Version</th>
                        <th>Last Heartbeat</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $sites as $site ): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $site->site_name ); ?></strong>
                            <br><code><?php echo esc_html( $site->site_slug ); ?></code>
                        </td>
                        <td>
                            <?php echo $this->status_badge( $site->connection_status ); ?>
                        </td>
                        <td><?php echo (int) $site->tool_count; ?></td>
                        <td><?php echo esc_html( $site->wp_version ?: '—' ); ?></td>
                        <td><?php echo esc_html( $site->plugin_version ?: '—' ); ?></td>
                        <td>
                            <?php echo $site->last_heartbeat
                                ? human_time_diff( strtotime( $site->last_heartbeat ) ) . ' ago'
                                : 'Never';
                            ?>
                        </td>
                        <td>
                            [Health Check] [Sync Tools] [Push Config] [Remove]
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function status_badge( string $status ): string {
        $map = [
            'connected'    => '<span style="color:green">🟢 Connected</span>',
            'disconnected' => '<span style="color:red">🔴 Disconnected</span>',
            'degraded'     => '<span style="color:orange">🟡 Degraded</span>',
            'pending'      => '<span style="color:gray">⚪ Pending</span>',
        ];
        return $map[ $status ] ?? $status;
    }
}
```

#### 3.2 Configuration Sync Engine

```php
class WP_MCP_AI_Config_Sync {

    /**
     * Push toolkit configuration to a spoke site.
     */
    public function push_toolkits( string $site_slug, array $toolkit_keys ): array {
        $site  = WP_MCP_AI_Site_Registry::get_instance()->get_site( $site_slug );
        $token = WP_MCP_AI_Site_Registry::get_instance()->get_site_token( $site_slug );

        // POST {site}/wp-json/design-hub/v1/config/toolkits
        return $this->call_site_endpoint( $site, $token, 'config/toolkits', 'POST', [
            'toolkits' => $toolkit_keys,
        ] );
    }

    /**
     * Push API keys to a spoke site (encrypted in transit).
     */
    public function push_api_keys( string $site_slug, array $keys ): array {
        // Keys: ['gemini_api_key' => '...', 'openai_api_key' => 'sk-...']
        // Only push keys that are not already configured on the spoke
        $site  = WP_MCP_AI_Site_Registry::get_instance()->get_site( $site_slug );
        $token = WP_MCP_AI_Site_Registry::get_instance()->get_site_token( $site_slug );

        return $this->call_site_endpoint( $site, $token, 'config/keys', 'POST', [
            'keys' => $keys,
        ] );
    }

    /**
     * Pull current configuration from a spoke for comparison.
     */
    public function pull_config( string $site_slug ): array {
        $site  = WP_MCP_AI_Site_Registry::get_instance()->get_site( $site_slug );
        $token = WP_MCP_AI_Site_Registry::get_instance()->get_site_token( $site_slug );

        return $this->call_site_endpoint( $site, $token, 'config', 'GET', [] );
    }

    /**
     * Diff Hub config vs spoke config — show what would change.
     */
    public function diff_config( string $site_slug ): array {
        $hub_config   = $this->get_hub_config();
        $spoke_config = $this->pull_config( $site_slug );
        return $this->array_diff_recursive( $hub_config, $spoke_config );
    }
}
```

#### 3.3 Spoke-Side Config Receiver

The spoke site exposes endpoints that the Hub's Config Sync calls:

```php
// POST /wp-json/design-hub/v1/config/toolkits
// Receives toolkit enablement push from Hub
register_rest_route( 'design-hub/v1', '/config/toolkits', [
    'methods'             => 'POST',
    'permission_callback' => function( $request ) {
        return $this->validate_hub_token( $request );
    },
    'callback' => function( $request ) {
        $toolkits = $request->get_param( 'toolkits' ) ?? [];
        $settings = get_option( 'wp_mcp_ai_settings', [] );
        foreach ( $toolkits as $key ) {
            $settings[ $key ] = '1';
        }
        update_option( 'wp_mcp_ai_settings', $settings );
        return [ 'success' => true, 'enabled' => $toolkits ];
    },
] );

// POST /wp-json/design-hub/v1/config/keys
// Receives API key push from Hub (keys encrypted in transit over HTTPS)
```

#### 3.4 WP-CLI Commands

```php
// New WP-CLI commands: wp hub site <action>
class WP_MCP_AI_Hub_CLI {

    /**
     * List all registered sites.
     *
     * ## OPTIONS
     * [--format=<format>]  table, json, csv, yaml (default: table)
     *
     * @when after_wp_load
     */
    public function site_list( $args, $assoc_args ) {
        $sites = WP_MCP_AI_Site_Registry::get_instance()->list_sites();
        WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $sites, [
            'site_slug', 'site_name', 'connection_status', 'tool_count', 'wp_version'
        ] );
    }

    /**
     * Add a new site to the registry.
     *
     * ## OPTIONS
     * <slug>        Site slug (e.g. "kaya")
     * --name=<name> Human-readable site name
     * --url=<url>   Site URL (Docker network or public)
     * [--external]   Site is externally hosted (not Docker)
     * [--public-url=<url>] Public-facing URL
     */
    public function site_add( $args, $assoc_args ) { /* ... */ }

    /**
     * Run health check on all sites.
     *
     * @when after_wp_load
     */
    public function health() {
        $results = WP_MCP_AI_Site_Registry::get_instance()->heartbeat_all();
        WP_CLI\Utils\format_items( 'table', $results, [
            'site_slug', 'status', 'latency_ms', 'tool_count', 'error'
        ] );
    }

    /**
     * Push configuration from Hub to spoke(s).
     *
     * ## OPTIONS
     * [<site>]      Site slug or "all" (default: all)
     * [--toolkits]   Push toolkit settings
     * [--keys]       Push API keys
     * [--dry-run]    Show what would change without applying
     */
    public function config_push( $args, $assoc_args ) { /* ... */ }

    /**
     * Generate a site-scoped credential token.
     *
     * ## OPTIONS
     * <site>        Site slug
     * [--assistant=<id>] Assistant ID on the spoke
     */
    public function token_create( $args, $assoc_args ) { /* ... */ }
}

WP_CLI::add_command( 'hub', 'WP_MCP_AI_Hub_CLI' );
```

---

### Phase 4: Advanced Features (Weeks 4-5)

#### 4.1 Observability & Activity Log

```sql
CREATE TABLE wp_mcp_ai_gateway_activity (
    id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    site_slug     VARCHAR(64)         NOT NULL,
    tool_name     VARCHAR(255)        NOT NULL,
    duration_ms   DECIMAL(10,2)       NOT NULL DEFAULT 0,
    status        VARCHAR(16)         NOT NULL DEFAULT 'success',
    error_message TEXT                DEFAULT NULL,
    token_user_id BIGINT(20)          DEFAULT NULL,
    created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY site_slug (site_slug),
    KEY status (status),
    KEY created_at (created_at)
);
```

**Features:**
- Log every cross-site tool call with timing
- Admin dashboard widget: "Last 50 Gateway Operations"
- Redis counters for real-time metrics: `gateway:count:{site}:{status}`
- Alert when error rate exceeds threshold per site

#### 4.2 Cross-Site Workflow Tools

```php
// New tool: hub::cross_site_sync
// Syncs content between two registered sites
'parameters' => [
    'source_site'  => [ 'type' => 'string', 'description' => 'Site slug to pull from' ],
    'target_site'  => [ 'type' => 'string', 'description' => 'Site slug to push to' ],
    'content_type' => [ 'type' => 'string', 'enum' => ['posts', 'products', 'media'] ],
    'filters'      => [ 'type' => 'object', 'description' => 'Optional WP_Query filters' ],
    'dry_run'      => [ 'type' => 'boolean', 'default' => true ],
]

// New tool: hub::cross_site_search
// Search across all connected sites simultaneously
'parameters' => [
    'query'       => [ 'type' => 'string' ],
    'content_type' => [ 'type' => 'string' ],
    'sites'       => [ 'type' => 'array', 'items' => ['type' => 'string'] ],
]
```

#### 4.3 Shared Media Worker Routing

```php
// The media worker serves all sites. Route per-site output to subdirectories.
define( 'WP_MEDIA_WORKER_URL', 'http://media-worker:3100' );

// Worker routes per-site output:
//   /api/image/generate  →  saves to /app/output/{site_slug}/...
//   /api/video/generate  →  saves to /app/output/{site_slug}/...

// WordPress media URLs point to the correct subdirectory
add_filter( 'wp_get_attachment_url', function( $url, $attachment_id ) {
    $site_slug = defined( 'WP_MCP_AI_SITE_SLUG' ) ? WP_MCP_AI_SITE_SLUG : 'hub';
    return str_replace( '/uploads/', "/uploads/sites/{$site_slug}/", $url );
}, 10, 2 );
```

---

### Phase 5: Production Hardening (Week 5+)

#### 5.1 Security Hardening

- **Token rotation:** Auto-rotate site tokens every 30 days via cron
- **IP allowlisting:** Optional per-site IP restriction for Hub-to-spoke calls
- **Request signing:** HMAC-SHA256 signature on Hub→spoke requests (defense-in-depth beyond TLS)
- **Audit trail:** Immutable activity log for compliance

#### 5.2 Performance Optimization

- **Parallel tool discovery:** Use `wp_remote_post()` with async (non-blocking) to fetch tools from all sites in parallel on cache miss
- **Lazy site loading:** Only connect to a site when a tool from that site is requested
- **Connection pooling:** Reuse HTTP connections to spoke sites (keep-alive)

#### 5.3 Disaster Recovery

- **Site backup aggregation:** Backup cron collects DB dumps from all spoke containers
- **Failover:** If a spoke is unreachable, Gateway returns degraded response with available sites listed
- **Migration tool:** `wp hub site migrate --from=kaya --to=new-kaya` for moving sites between hosts

---

## 7. File Manifest

### New Files

| File | Purpose |
|------|---------|
| `includes/gateway/class-wp-mcp-ai-site-registry.php` | Site Registry class |
| `includes/gateway/class-wp-mcp-ai-site-tool-catalog.php` | Tool catalog with namespacing |
| `includes/gateway/class-wp-mcp-ai-gateway-controller.php` | Gateway MCP endpoint |
| `includes/gateway/class-wp-mcp-ai-config-sync.php` | Config sync engine |
| `includes/gateway/class-wp-mcp-ai-site-manager-admin.php` | Admin UI |
| `includes/gateway/class-wp-mcp-ai-hub-cli.php` | WP-CLI commands |
| `includes/gateway/class-wp-mcp-ai-gateway-activator.php` | Activation hooks, DB table creation |
| `includes/gateway/class-wp-mcp-ai-gateway-logger.php` | Activity logging |
| `includes/gateway/rest/class-wp-mcp-ai-rest-sites.php` | REST endpoints for site management |
| `includes/gateway/rest/class-wp-mcp-ai-rest-config.php` | Config push/pull endpoints |
| `src/wordpress/admin/components/SiteManager.tsx` | React admin component (if SPA UI) |
| `docker-compose.sites.yml` | Multi-site Docker Compose template |
| `scripts/provision-site.sh` | One-command spoke provisioning |
| `scripts/spoke-seed.sh` | Spoke site auto-setup script |
| `docs/project/plans/multi-site-gateway-plan.md` | This document |

### Modified Files

| File | Change |
|------|--------|
| `mcp-ai-wpoos.php` | Load gateway module; hook `register_hub_site()` |
| `docker-compose.yml` | No changes (sites in separate compose file) |
| `.env.example` | Add `DESIGN_HUB_REGISTRATION_SECRET`, site-specific ports |
| `includes/bootstrap/loader.php` | Require gateway files |
| `AGENTS.md` | Add gateway architecture section |
| `CLAUDE.md` | Add multi-site section |
| `docs/ROADMAP.md` | Add Phase 8: Multi-Site Gateway to v1.5.0 |

### Existing Files to Reference (Do Not Modify)

These live inside the mcp-ai-wpoos plugin directory:

| File | Pattern to Reuse |
|------|-----------------|
| `addons/graphify/includes/remote/class-nvoos-graphify-remote-registry.php` | Singleton registry pattern |
| `addons/graphify/includes/remote/drivers/class-nvoos-graphify-remote-oos-federation.php` | oOS federation driver (cred_ token auth, REST API communication) |
| `addons/graphify/includes/remote/interface-nvoos-graphify-remote-source.php` | Remote source interface contract |
| `addons/graphify/includes/class-nvoos-graphify-db.php` | DB table schema, CRUD, encryption pattern |
| `addons/graphify/includes/class-nvoos-graphify-crypto.php` | AES-256-GCM encryption for credentials |
| `addons/graphify/includes/admin/class-nvoos-graphify-remote-admin.php` | Admin UI pattern for remote sources |
| `addons/pro/` | All Pro toolkit addons (toolkit-shell, media-worker, document-editor, etc.) |
| `scripts/seed-toolkits.sh` | Spoke auto-setup pattern |
| `scripts/create-assistant.php` | Assistant creation + token generation pattern |
| `scripts/bridge-env-keys.php` | API key bridging pattern |

---

## 8. Testing Strategy

### 8.1 Unit Tests (PHPUnit)

```php
// Test: Site Registry CRUD
public function test_register_and_retrieve_site() {
    $registry = WP_MCP_AI_Site_Registry::get_instance();
    $id = $registry->register_site( [ /* ... */ ] );
    $this->assertIsInt( $id );

    $site = $registry->get_site( 'kaya' );
    $this->assertEquals( 'Kaya Wellness', $site->site_name );
}

// Test: Token encryption/decryption
public function test_site_token_encryption() {
    $registry = WP_MCP_AI_Site_Registry::get_instance();
    $registry->set_site_token( 'kaya', 'cred_xxxxx.SECRET' );

    $raw = $registry->get_site( 'kaya' );
    $this->assertStringNotContainsString( 'cred_', $raw->site_token ); // Encrypted in DB

    $decrypted = $registry->get_site_token( 'kaya' );
    $this->assertEquals( 'cred_xxxxx.SECRET', $decrypted );
}

// Test: Tool namespacing
public function test_tool_namespacing() {
    $catalog = new WP_MCP_AI_Site_Tool_Catalog();
    $tool = [ 'name' => 'search_content', 'description' => '...' ];
    $namespaced = $this->invoke_private( $catalog, 'namespace_tool', [ 'kaya', $tool ] );
    $this->assertEquals( 'kaya::search_content', $namespaced['name'] );
}

// Test: Circuit breaker transitions
public function test_circuit_breaker_opens_after_failures() {
    $registry = WP_MCP_AI_Site_Registry::get_instance();
    $registry->record_failure( 'kaya', 'Timeout' );
    $registry->record_failure( 'kaya', 'Timeout' );
    $this->assertFalse( $registry->is_circuit_open( 'kaya' ) );
    $registry->record_failure( 'kaya', 'Timeout' ); // 3rd failure
    $this->assertTrue( $registry->is_circuit_open( 'kaya' ) );
}
```

### 8.2 Integration Tests

- **Docker Compose smoke test:** `docker compose --profile kaya up -d` → verify WordPress is accessible
- **Hub-spoke registration:** Spoke starts → calls `/sites/register` → Hub has site record
- **Tool discovery:** Hub calls `tools/list` → returns Hub + Spoke tools (namespaced)
- **Tool routing:** AI client calls `kaya::search_content` → Gateway routes to Kaya → returns Kaya's content
- **Circuit breaker:** Kill spoke container → 3 failed calls → Gateway returns "temporarily unavailable"
- **Config sync:** Push toolkit from Hub to spoke → spoke has toolkit enabled

### 8.3 Manual QA Checklist

- [ ] Provision 2 spoke sites via `provision-site.sh`
- [ ] Verify `tools/list` returns tools from all 3 sites (hub + 2 spokes)
- [ ] Call a hub tool: `search_content` (bare name, backward compat)
- [ ] Call a spoke tool: `kaya::search_content` (namespaced)
- [ ] Stop a spoke container, verify circuit breaker engages
- [ ] Restart spoke, verify health check restores connection
- [ ] Push config from Hub to spoke, verify on spoke
- [ ] Create cross-site workflow: search both sites, compare results
- [ ] Check activity log records all operations
- [ ] Verify token scoping: hub master token works, spoke-only token works only for that spoke

---

## 9. Risk Register

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **Token sprawl** — too many tokens to manage | Medium | Medium | Centralized token UI; auto-rotation; token inventory screen |
| **Network latency** — slow spoke responses | Low (same Docker network) | Medium | Timeout per call (5s default); circuit breaker; lazy discovery |
| **Tool name collisions** — two sites have same tool | Low (namespacing prevents) | Low | `{site_slug}::` prefix guarantees uniqueness |
| **Plugin version skew** — Hub on v1.5, spoke on v1.4 | Medium | Medium | Version tracking in registry; compatibility matrix; alert on skew |
| **Docker resource exhaustion** — too many containers | Medium | High | Profile-based startup; `--profile` flag; resource limits per container |
| **Data leakage** — wrong site accesses another's data | Low | Critical | Strict token scoping; per-site DB isolation; audit logging; never share DB credentials |
| **Breaking changes** in mcp-ai-wpoos MCP protocol | Low | High | Semantic versioning; canary deployment (one spoke first); backward-compat Gateway adapter |
| **External spoke unreliability** — hosted site goes down | Medium | Medium | Circuit breaker; graceful degradation; alerting |
| **Encryption key loss** — can't decrypt site tokens | Low | Critical | Backup the WP `wp_mcp_ai_gateway_key` option; key rotation procedure |

---

## Appendix A: Glossary

| Term | Definition |
|------|-----------|
| **Hub** | The central WordPress site running the Gateway. Manages all spokes. |
| **Spoke** | A WordPress site running mcp-ai-wpoos, registered with the Hub. Can be local (Docker) or remote (external host). |
| **Gateway** | The MCP endpoint layer in the Hub that federates tools across all sites. |
| **Site Registry** | Database table + PHP class tracking all spoke sites. |
| **Tool Catalog** | Merged, cached list of all tools from all sites. |
| **Circuit Breaker** | Pattern that stops routing to unhealthy sites after N failures. |
| **Config Sync** | Hub-pushed configuration distribution to spokes. |
| **Token Scoping** | Credential tokens that grant access to specific sites or tools. |

## Appendix B: Alignment with Existing Roadmap

This plan maps to the existing ROADMAP.md items:

| ROADMAP Item | This Plan Phase | Status |
|---|---|---|
| Phase 8 — Toolkit MCP Server Fleet expansion | Phase 1-2 | This IS Phase 8 |
| v1.4.0 — Pro Toolkit Performance Hardening | Pre-work for Phase 3 | Config sync leverages hardened toolkits |
| v1.4.0 — Documentation & Developer Experience | Phase 5 | Gateway docs included |
| v2.0.0 — Team Collaboration Features | Phase 4 (future) | Multi-site = multi-team |
| v2.0.0 — Custom Role-Based Permissions | Phase 5 (future) | Per-site permissions |
| v2.0.0 — REST API v2 | Phase 2 | Gateway uses REST v2 pattern |

## Appendix C: References

1. MCP Specification — Architecture: https://modelcontextprotocol.io/specification/2025-06-18/architecture
2. MCP 2026-07-28 Release Candidate: https://blog.modelcontextprotocol.io/posts/2026-07-28-release-candidate/
3. MCP Roadmap 2026 (The New Stack): https://thenewstack.io/model-context-protocol-roadmap-2026/
4. Kong MCP Gateway: https://konghq.com/blog/learning-center/what-is-a-mcp-gateway
5. Cloudflare Enterprise MCP: https://blog.cloudflare.com/enterprise-mcp/
6. Azure MCP API Management: https://learn.microsoft.com/en-us/azure/api-management/mcp-server-overview
7. WordPress MCP Adapter (Automattic): https://github.com/wordpress/mcp-adapter
8. Scaling AI with Multiple MCP Servers (GetKnit): https://www.getknit.dev/blog/scaling-ai-capabilities-using-multiple-mcp-servers-with-one-agent
9. WordPress Scalability (WP VIP): https://wpvip.com/blog/wordpress-scalability/
10. White-Label WordPress Architecture: https://www.webmastered.com/blog/white-label-wordpress-scalable-architecture/
11. WordPress Multisite Best Practices 2026: https://www.smackcoders.com/blog/wordpress-multisite-best-practices-the-essential-2026-admin-guide.html
12. WordPress Enterprise Architecture 2026: https://wppoland.com/en/wordpress-multisite-enterprise-architecture-2026/
13. 10 Best WordPress MCP Servers 2026: https://responsive.menu/10-best-wordpress-mcp-servers/
14. Databricks — What is MCP?: https://www.databricks.com/blog/what-is-model-context-protocol
15. Cisco — MCP for Network Automation: https://www.cisco.com/c/en/us/support/docs/cloud-systems-management/catalyst-center/223278-harness-the-power-of-mcp-servers.html
16. TrueFoundry — Enterprise MCP Server: https://www.truefoundry.com/blog/mcp-server-in-enterprise
17. Pantheon — Scaling WordPress: https://pantheon.io/learning-center/wordpress/scaling-infrastructure
18. Red Hat — Scaling WordPress Multi-Tenant: https://www.redhat.com/en/blog/scaling-wordpress-multi-tenant-scenario-openshift
