# Page Agent Integration Plan — Alibaba Page Agent → NV oOS

> **Status:** Draft Proposal
> **Date:** 2026-07-10
> **Target:** New addon `addons/page-agent/` (v0.1.0 → v1.0.0)
> **Page Agent Version:** 1.12.0 (MIT)
> **NV oOS Base Version:** 1.1.x+ (no base changes required)

---

## Executive Summary

Integrate **Alibaba Page Agent** — a MIT-licensed, client-side JavaScript GUI agent
that controls web interfaces via natural language — as a new self-contained addon
at `addons/page-agent/`.

Page Agent runs entirely in the browser, uses text-based DOM extraction + any
OpenAI-compatible LLM, and requires no headless browser, Python, or Chrome extension.
It gives NV oOS assistants the ability to _operate the WordPress admin interface and
frontend pages_ through natural language.

The addon plugs into existing NV oOS surfaces — the model router, tool registry,
REST API, and shortcode system — without requiring any changes to the base plugin.

---

## 1. Why an Addon?

### 1.1 The Addon Pattern (already established)

NV oOS ships 18 addons in this monorepo. Each extends the base plugin with a
self-contained capability. Page Agent is the same shape:

| Addon | Capability | Status |
|-------|-----------|--------|
| `algorave` | AI music generation | Production |
| `fantasy-football` | ESPN/Yahoo fantasy sports | Production |
| `graphify` | Knowledge graph builder | Production |
| `chat-spa` | React chat surface | Production |
| `embedded` | Local LLM inference (WebLLM) | Production |
| **`page-agent`** | **Page control copilot** | **Proposed** |

### 1.2 Why NOT elsewhere

| Location | Why rejected |
|----------|-------------|
| **Base `includes/`** | Adds `page-agent` npm dependency + ~50 KB JS bundle to every install. Base should stay lean. |
| **`addons/pro/`** | Page Agent core (MIT) is orthogonal to Pro (proprietary). Pro-only features (admin copilot, workflows) can gate behind a toolkit flag *within* the addon. |
| **`addons/embedded/`** | Embedded is about *where* the model runs (local WebLLM/llama.cpp). Page Agent is about *what* the model does (DOM control). Different concerns, different licenses (Proprietary vs MIT). |
| **New standalone repo** | The addon depends on NV oOS APIs (tool registry, model router, REST). Living in the monorepo ensures CI coverage and version alignment. |

### 1.3 What the base plugin already provides (zero new code)

The addon consumes these existing surfaces — no base changes needed:

| Need | Already exists in base |
|------|----------------------|
| LLM routing to any provider | `class-wp-mcp-ai-language-model-router.php` |
| Tool execution | `class-wp-mcp-ai-tool-registry.php` + REST dispatch |
| Chat widget on any page | `[mcp_ai_chat]` via `class-wp-mcp-ai-shortcode.php` |
| SSE streaming | `class-wp-mcp-ai-sse-stream.php` |
| Auth (nonce, bearer, guest) | `class-wp-mcp-ai-credentials.php` |
| MCP protocol | `class-wp-mcp-ai-rest-mcp-methods.php` |
| Admin settings framework | `class-admin-settings.php` (filterable sections) |
| Plugin dependency check | `nvoos_*_is_base_active()` pattern |

---

## 2. Page Agent Architecture Overview

```
Browser Tab (WordPress Page)
├── DOM (the page being controlled)
├── Page Agent (injected JS)
│   ├── @page-agent/core               — agent loop, context manager
│   ├── @page-agent/llms               — OpenAI-compatible LLM client
│   ├── @page-agent/page-controller    — DOM extraction + interaction + visual mask
│   └── @page-agent/ui                 — floating chat panel + i18n
│
│   ┌──────────────────────────────────────┐
│   │  NV oOS Bridge (page-agent-bridge.js) │  ← NEW
│   │  - Reads wpMcpAiPageAgent config      │
│   │  - Dispatches tool calls to REST      │
│   │  - Hooks into job event bus           │
│   └──────────────────────────────────────┘
│
└── LLM API (routed through wpMcpAiPageAgent.baseURL → /wp-json/mcp-ai/v1/...)
```

**Key Properties:**
- **No headless browser.** No Python. No Chrome extension required (optional for multi-page).
- **Text-based DOM.** Extracts accessible elements as structured text — no screenshots, no multi-modal models.
- **OpenAI-compatible API.** Any endpoint speaking `/v1/chat/completions` with function-calling.
- **IIFE + ESM.** Both CDN `<script>` and NPM `import` modes supported.
- **MCP Server (Beta).** Can be controlled externally via MCP protocol.
- **Size:** ~50 KB minzipped.

---

## 3. Addon Architecture

```
addons/page-agent/
│
│  ┌─────────────────────────────────────────────────┐
│  │  nvoos-page-agent.php           Entry point     │
│  │  - Plugin header + constants                     │
│  │  - Base dependency check                         │
│  │  - Bootstrap: includes/class-wp-mcp-ai-page-agent.php │
│  └──────────────────────┬──────────────────────────┘
│                         │
│         ┌───────────────┼───────────────┐
│         ▼               ▼               ▼
│  ┌────────────┐  ┌────────────┐  ┌──────────────┐
│  │  Main      │  │  REST      │  │  Widget/     │
│  │  class     │  │  bridge    │  │  Shortcode   │
│  │            │  │            │  │              │
│  │ Enqueue    │  │ execute-   │  │ [mcp_ai_     │
│  │ Config     │  │ tool       │  │ page_agent]  │
│  │ Settings   │  │ dom-       │  │ Elementor    │
│  │ Hooks      │  │ snapshot   │  │ widget       │
│  └─────┬──────┘  └─────┬──────┘  └──────────────┘
│        │               │
│        ▼               ▼
│  ┌─────────────────────────────────────────────────┐
│  │  NV oOS Base Plugin (untouched)                  │
│  │  ┌───────────┐ ┌──────────┐ ┌────────────────┐  │
│  │  │ Model     │ │ Tool     │ │ REST API        │  │
│  │  │ Router    │ │ Registry │ │ (auth, dispatch)│  │
│  │  └───────────┘ └──────────┘ └────────────────┘  │
│  └─────────────────────────────────────────────────┘
```

### 3.1 Dependency on Base Plugin

The addon requires the base plugin to be active. It checks at load time:

```php
// nvoos-page-agent.php
if ( ! function_exists( 'wp_mcp_ai_core_loaded' ) ) {
    add_action( 'admin_notices', 'nvoos_page_agent_missing_base_notice' );
    return; // Don't load
}
```

### 3.2 Pro Features Within the Addon

Instead of creating a separate `addons/page-agent-pro/`, premium features gate
behind a toolkit flag and a capability constant — the same pattern used by Pro
addon toolkits:

```php
// In includes/class-wp-mcp-ai-page-agent.php
if ( defined( 'WP_MCP_AI_PRO_ACTIVE' ) && WP_MCP_AI_PRO_ACTIVE ) {
    // Register admin copilot, workflows, analytics
    $this->load_pro_features();
}
```

| Feature | Gating |
|---------|--------|
| Frontend chat + Page Agent | Always (base plugin active) |
| Admin dashboard copilot | `WP_MCP_AI_PRO_ACTIVE` |
| Page Agent workflows | `WP_MCP_AI_PRO_ACTIVE` |
| MCP bridge for Page Agent | `WP_MCP_AI_PRO_ACTIVE` |
| Usage analytics | `WP_MCP_AI_PRO_ACTIVE` |
| Chrome extension companion | `WP_MCP_AI_PRO_ACTIVE` |

This keeps the addon as a single installable unit rather than two addons,
while still respecting the Base/Pro boundary.

---

## 4. Delivery Phases

### Phase 1 — Core Addon (v0.1.0, ~2 weeks)

**Goal:** Working Page Agent copilot on any page rendering the NV oOS chat widget.

#### 4A. Addon Scaffold

```
addons/page-agent/
├── nvoos-page-agent.php                            # Entry point
├── package.json                                    # { "dependencies": { "page-agent": "^1.12.0" } }
├── esbuild.config.js                               # Build JS bridge
├── readme.txt                                      # WordPress.org readme
├── includes/
│   ├── class-wp-mcp-ai-page-agent.php              # Main class
│   ├── class-wp-mcp-ai-page-agent-rest.php         # REST endpoints
│   ├── class-wp-mcp-ai-page-agent-widget.php       # Shortcode + widget
│   └── admin/
│       └── class-wp-mcp-ai-page-agent-settings.php # Settings page
├── assets/
│   └── js/
│       └── page-agent-bridge.js                    # Client-side bridge (built → .min.js)
└── tests/
    ├── test-page-agent.php
    └── test-page-agent-rest.php
```

#### 4B. Entry Point (`nvoos-page-agent.php`)

```php
<?php
/**
 * Plugin Name: NV oOS Page Agent
 * Description: AI-powered page control copilot. Give any WordPress page its own AI agent that can click, type, and navigate via natural language.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: mcp-ai-wpoos
 * Author: NV Digital Solutions
 * License: GPL-3.0-or-later
 * Text Domain: nvoos-page-agent
 *
 * @package NV_oOS_Page_Agent
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Constants ────────────────────────────────────────────
define( 'NVOOS_PAGE_AGENT_VERSION', '0.1.0' );
define( 'NVOOS_PAGE_AGENT_FILE', __FILE__ );
define( 'NVOOS_PAGE_AGENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'NVOOS_PAGE_AGENT_URL', plugin_dir_url( __FILE__ ) );

// ── Dependency Check ─────────────────────────────────────
if ( ! function_exists( 'wp_mcp_ai_core_loaded' ) ) {
    add_action( 'admin_notices', 'nvoos_page_agent_missing_base_notice' );
    return;
}

// ── Bootstrap ────────────────────────────────────────────
require_once NVOOS_PAGE_AGENT_PATH . 'includes/class-wp-mcp-ai-page-agent.php';

add_action( 'plugins_loaded', array( 'WP_MCP_AI_Page_Agent', 'init' ), 20 );
```

#### 4C. Main Class

```php
class WP_MCP_AI_Page_Agent {

    const SCRIPT_HANDLE = 'nvoos-page-agent-bridge';
    const OPTION_ENABLED = 'nvoos_page_agent_enabled';
    const OPTION_MODEL = 'nvoos_page_agent_model';
    const OPTION_LANGUAGE = 'nvoos_page_agent_language';

    public static function init() {
        $instance = new self();
        $instance->register_hooks();
    }

    private function register_hooks() {
        // Enqueue scripts
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );

        // Register REST routes
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Register tools with NV oOS tool registry
        add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_tools' ) );

        // Admin settings
        if ( is_admin() ) {
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        }

        // Shortcode
        add_shortcode( 'mcp_ai_page_agent', array( $this, 'render_shortcode' ) );
    }

    public function maybe_enqueue() {
        if ( ! get_option( self::OPTION_ENABLED, false ) ) {
            return;
        }

        $suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.js' : '.min.js';

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            NVOOS_PAGE_AGENT_URL . 'assets/js/page-agent-bridge' . $suffix,
            array( 'wp-mcp-ai-chat' ),
            NVOOS_PAGE_AGENT_VERSION,
            true
        );

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'wpMcpAiPageAgent',
            $this->build_config()
        );
    }

    private function build_config() {
        $model_router = WP_MCP_AI_Language_Model_Router::instance();
        $model_slug   = get_option( self::OPTION_MODEL, 'gpt-4o-mini' );
        $llm_config   = $model_router->get_client_config( $model_slug );

        return array(
            'model'      => $llm_config['model'],
            'baseURL'    => $llm_config['base_url'],
            'apiKey'     => $llm_config['api_key'],
            'language'   => get_option( self::OPTION_LANGUAGE, 'en-US' ),
            'restUrl'    => rest_url( 'nvoos-page-agent/v1' ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'tools'      => $this->get_exposed_tools(),   // Tool definitions
        );
    }
}
```

#### 4D. REST Bridge Endpoints

```php
// namespace: nvoos-page-agent/v1

POST /execute-tool
    → Dispatches a tool call through NV oOS tool registry
    → Returns canonical envelope (success + data or WP_Error)

POST /dom-snapshot
    → Records a client-side DOM snapshot for debugging
    → Optional; used by Page Agent for context sharing

GET  /config
    → Returns current addon config (model, language, enabled tools)
```

#### 4E. Tool Registration

```php
public function register_tools( $registry ) {
    require_once NVOOS_PAGE_AGENT_PATH . 'includes/tools/class-wp-mcp-ai-tool-page-agent-execute.php';
    $registry->register_tool( 'WP_MCP_AI_Tool_Page_Agent_Execute' );
}
```

The `page_agent_execute` tool lets the NV oOS assistant LLM *delegate* to Page Agent:

```php
class WP_MCP_AI_Tool_Page_Agent_Execute extends WP_MCP_AI_Tool_Base {
    public function get_slug() { return 'page_agent_execute'; }

    public function get_definition() {
        return array(
            'name'        => 'Page Agent Execute',
            'description' => 'Execute a natural language instruction on the current page through the browser. Use for UI-level operations like navigating admin menus, filling forms, or clicking buttons.',
            'required_capability' => 'edit_posts',
            'parameters' => array(
                'type'       => 'object',
                'properties' => array(
                    'instruction' => array(
                        'type'        => 'string',
                        'description' => 'Natural language instruction (e.g., "Click Posts → Add New").',
                    ),
                    'wait_for_result' => array(
                        'type'        => 'boolean',
                        'description' => 'Whether to wait for the page agent result before responding.',
                        'default'     => true,
                    ),
                ),
                'required' => array( 'instruction' ),
            ),
        );
    }

    public function execute( $arguments, $context ) {
        // This tool is special: it doesn't execute server-side.
        // It signals the chat UI to invoke Page Agent in the browser,
        // then waits for the result via SSE or polling.
        $instruction = sanitize_text_field( $arguments['instruction'] );

        return array(
            'success' => true,
            'message' => __( 'Page Agent instruction queued.', 'nvoos-page-agent' ),
            'data'    => array(
                'type'        => 'page_agent_delegate',
                'instruction' => $instruction,
                'status'      => 'pending',
            ),
        );
    }
}
```

#### 4F. Client-Side Bridge (`assets/js/page-agent-bridge.js`)

```js
import { PageAgent } from 'page-agent';

( function( config ) {
    'use strict';

    let agent = null;
    let active = false;

    async function init() {
        agent = new PageAgent( {
            model:    config.model,
            baseURL:  config.baseURL,
            apiKey:   config.apiKey,
            language: config.language,
        } );

        // Listen for delegated instructions from the chat LLM
        window.wpMcpAiJobBus.on( 'page-agent:execute', handleExecute );

        // Expose for programmatic use
        window.wpMcpAiPageAgentInstance = agent;
        active = true;
    }

    async function handleExecute( { instruction, requestId } ) {
        if ( ! agent ) return;

        try {
            const result = await agent.execute( instruction );
            window.wpMcpAiJobBus.emit( 'page-agent:result', {
                requestId,
                success: true,
                result,
            } );
        } catch ( error ) {
            window.wpMcpAiJobBus.emit( 'page-agent:result', {
                requestId,
                success: false,
                error: error.message,
            } );
        }
    }

    // Init when DOM is ready
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )( window.wpMcpAiPageAgent || {} );
```

---

### Phase 2 — Admin Copilot & Pro Features (v0.2.0, ~2 weeks)

#### 5A. Admin Dashboard Copilot

```php
// includes/class-wp-mcp-ai-page-agent-admin-copilot.php (gated by WP_MCP_AI_PRO_ACTIVE)

class WP_MCP_AI_Page_Agent_Admin_Copilot {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_copilot' ) );
        add_action( 'admin_bar_menu', array( $this, 'add_copilot_button' ), 100 );
    }

    public function enqueue_admin_copilot( $hook ) {
        if ( ! $this->is_allowed_role() ) return;

        wp_enqueue_script(
            'nvoos-page-agent-admin',
            NVOOS_PAGE_AGENT_URL . 'assets/js/page-agent-admin-copilot.min.js',
            array( 'nvoos-page-agent-bridge' ),
            NVOOS_PAGE_AGENT_VERSION,
            true
        );

        wp_localize_script( 'nvoos-page-agent-admin', 'wpMcpAiPageAgentAdmin', array(
            'currentPage'    => $hook,
            'pageTitle'      => get_admin_page_title(),
            'allowedActions' => $this->get_allowed_actions( $hook ),
            'confirmDestructive' => get_option( 'nvoos_page_agent_confirm_destructive', true ),
        ) );
    }
}
```

#### 5B. Pro Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `nvoos_page_agent_admin_enabled` | `true` | Enable admin copilot |
| `nvoos_page_agent_allowed_roles` | `['administrator']` | Roles permitted to use admin copilot |
| `nvoos_page_agent_max_steps` | `20` | Max agent steps per instruction |
| `nvoos_page_agent_confirm_destructive` | `true` | Require confirmation for delete/publish/trash |
| `nvoos_page_agent_log_actions` | `true` | Write to audit log |

#### 5C. Workflow Integration

Leverage `class-wp-mcp-ai-workflow-engine-v2.php` (Pro):
- Record Page Agent sessions as workflow runs
- Replay saved sessions
- Schedule recurring Page Agent tasks

---

### Phase 3 — Advanced (v0.3.0 → v1.0.0, ~3 weeks)

#### 6A. MCP Server Bridge

Page Agent ships with an MCP Server (Beta). Wire it into NV oOS's MCP implementation:

```
External MCP Client
    │
    ▼
NV oOS MCP Server (existing base)
    │  ┌─ tools/list     → NV oOS tools
    │  ├─ tools/call     → NV oOS tool execution
    │  └─ resources/read → NV oOS resources
    │
    ▼
Page Agent MCP Bridge (new, in addon)
    │  ┌─ page/execute   → run Page Agent instruction
    │  ├─ page/dom       → DOM snapshot
    │  └─ page/screenshot→ visual feedback
    │
    ▼
Browser Tab (via Chrome Extension or WebSocket)
```

**File:** `includes/mcp/class-wp-mcp-ai-page-agent-mcp-bridge.php`

#### 6B. Bidirectional Tool ↔ Page Bridge

The current execution model is:
1. LLM plans → calls server-side tool → result

With Page Agent:
1. LLM plans → calls `page_agent_execute` tool
2. Tool returns a `page_agent_delegate` envelope
3. Chat UI picks up the envelope, invokes Page Agent in browser
4. Page Agent result returns via SSE/polling
5. LLM continues planning with the result

This creates a **seamless two-tier execution model** without the LLM needing to know whether a tool is server-side or page-side.

#### 6C. DOM Context Provider

Instead of sending full HTML to the LLM, pre-process with `WP_HTML_Tag_Processor`:

```php
// includes/class-wp-mcp-ai-page-agent-dom-context.php

class WP_MCP_AI_Page_Agent_DOM_Context {
    /**
     * Extract interactive elements from admin page HTML.
     *
     * Uses WP_HTML_Tag_Processor (WP 6.2+) to safely extract
     * buttons, links, inputs, selects, and other actionable elements
     * without regex or DOMDocument.
     */
    public function extract_interactive_elements( $html ) {
        $processor = new WP_HTML_Tag_Processor( $html );
        $elements  = array();

        while ( $processor->next_tag() ) {
            if ( $this->is_interactive( $processor ) ) {
                $elements[] = $this->describe_element( $processor );
            }
        }

        return $elements;
    }
}
```

---

## 7. File Manifest (Complete)

```
addons/page-agent/
│
├── nvoos-page-agent.php                         # Entry point
├── package.json                                  # npm: "page-agent": "^1.12.0"
├── esbuild.config.js                             # Build JS bridge
├── readme.txt                                    # WP.org readme
├── uninstall.php                                 # Cleanup on uninstall
│
├── includes/
│   ├── class-wp-mcp-ai-page-agent.php            # Main: init, enqueue, config, hooks
│   ├── class-wp-mcp-ai-page-agent-rest.php       # REST: execute-tool, dom-snapshot, config
│   ├── class-wp-mcp-ai-page-agent-widget.php     # Shortcode [mcp_ai_page_agent] + Elementor
│   ├── class-wp-mcp-ai-page-agent-dom-context.php # DOM pre-processor (Phase 3)
│   │
│   ├── admin/
│   │   └── class-wp-mcp-ai-page-agent-settings.php   # Settings page
│   │
│   ├── tools/
│   │   ├── class-wp-mcp-ai-tool-page-agent-execute.php     # Delegates to browser agent
│   │   ├── class-wp-mcp-ai-tool-page-agent-screenshot.php  # Capture page screenshot
│   │   └── class-wp-mcp-ai-tool-page-agent-workflow.php    # Record/replay sessions
│   │
│   ├── pro/                                                    # Gated by WP_MCP_AI_PRO_ACTIVE
│   │   ├── class-wp-mcp-ai-page-agent-admin-copilot.php       # Admin bar copilot
│   │   ├── class-wp-mcp-ai-page-agent-workflow-recorder.php   # Session recording
│   │   └── class-wp-mcp-ai-page-agent-analytics.php           # Usage tracking
│   │
│   └── mcp/
│       └── class-wp-mcp-ai-page-agent-mcp-bridge.php          # MCP integration (Phase 3)
│
├── assets/
│   └── js/
│       ├── page-agent-bridge.js                               # Client-side bridge (source)
│       ├── page-agent-bridge.min.js                           # Built output
│       ├── page-agent-admin-copilot.js                        # Admin copilot (source)
│       └── page-agent-admin-copilot.min.js                    # Built output
│
├── tests/
│   ├── test-page-agent.php                                    # Unit tests
│   ├── test-page-agent-rest.php                               # REST endpoint tests
│   └── test-page-agent-tools.php                              # Tool tests
│
└── docs/
    └── page-agent.md                                          # User-facing documentation
```

---

## 8. Risk Assessment & Mitigations

| Risk | Severity | Mitigation |
|------|----------|------------|
| **LLM hallucination → destructive action** | High | Confirmation gate for destructive ops; configurable max steps; capability check per action |
| **DOM extraction leaks sensitive data** | High | Extract only labeled interactive elements; filter by CSS class/data-attr; admin page blocklist |
| **Page Agent LLM costs** | Medium | Route through existing cost calculator; separate model config; recommend `gpt-4o-mini` |
| **Browser compatibility** | Medium | Page Agent supports all modern browsers; NV oOS already tests Chrome, Firefox, Safari, Edge |
| **Performance — large DOMs** | Low | Page Agent uses text-based extraction; DOM Context Provider pre-filters interactive elements |
| **Security — cross-origin** | Low | Page Agent runs same-origin; REST endpoints use WordPress nonces + capability checks |
| **Page Agent version drift** | Low | Pin `^1.12.0` in addon's `package.json`; Dependabot handles updates |
| **Base plugin version compatibility** | Low | Addon checks `WP_MCP_AI_VERSION` at load; admin notice if too old |

---

## 9. Testing Strategy

### 9.1 PHPUnit Tests

```
addons/page-agent/tests/
├── test-page-agent.php             # Config building, enqueue logic, settings
├── test-page-agent-rest.php        # Permission checks, tool dispatch, error responses
└── test-page-agent-tools.php       # page_agent_execute tool definition + envelope
```

### 9.2 JavaScript Tests (Jest)

```
addons/page-agent/tests/js/
└── page-agent-bridge.test.js       # Init, event handling, error paths
```

### 9.3 Manual / E2E Scenarios

| Scenario | Expected result |
|----------|----------------|
| Install & activate addon | Settings page appears under NV oOS menu |
| Enable Page Agent → load page with `[mcp_ai_chat]` | Chat widget shows Page Agent mode toggle |
| Type "Click the login button" | Page Agent finds and clicks the button |
| Disable Page Agent | Chat widget falls back to standard mode |
| Pro: Admin copilot button | Floating ☰ button in admin bar |
| Pro: "Delete all drafts" → confirm | Confirmation dialog appears before action |
| Pro: Record → replay workflow | Saved session replays successfully |

---

## 10. Dependency Management

### 10.1 NPM (addon-level, not base)

```json
// addons/page-agent/package.json
{
  "name": "nvoos-page-agent",
  "private": true,
  "version": "0.1.0",
  "scripts": {
    "build": "node esbuild.config.js",
    "test": "jest"
  },
  "dependencies": {
    "page-agent": "^1.12.0"
  },
  "devDependencies": {
    "esbuild": "^0.27.0",
    "jest": "^30.2.0"
  }
}
```

### 10.2 Composer

No PHP dependencies. The addon uses only WordPress core APIs and NV oOS base plugin hooks.

### 10.3 WordPress

Requires NV oOS base plugin (`mcp-ai-wpoos`) active. Works with WordPress 6.0+, PHP 7.4+.

---

## 11. LLM Routing

Page Agent needs its own LLM. The addon reads from NV oOS's model router:

```php
$model_router = WP_MCP_AI_Language_Model_Router::instance();
$llm_config   = $model_router->get_client_config(
    get_option( 'nvoos_page_agent_model', 'gpt-4o-mini' )
);
// → { model: 'gpt-4o-mini', base_url: 'https://api.openai.com/v1', api_key: 'sk-...' }
```

**Recommended models:**
| Model | Use case |
|-------|----------|
| `gpt-4o-mini` | Default — fast, cheap, good DOM understanding |
| `gpt-4o` / `claude-3.5-sonnet` | Complex multi-step workflows |
| `qwen2.5` via Ollama | Local, zero API cost |
| Page Agent demo LLM | Testing only, not for production |

---

## 12. Extracted NPM Package (future)

Like the 17 packages in `packages/`, the bridge could be extracted:

```
packages/nvoos-page-agent/
├── package.json           # @nvdigitalsolutions/nvoos-page-agent
├── src/
│   └── index.ts           # Typed bridge — framework-agnostic
├── dist/
└── README.md
```

This lets non-WordPress projects (React, Laravel, Vue) embed Page Agent + NV oOS tool dispatch.

---

## 13. Success Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| Task completion rate | >85% | success/error ratio from bridge |
| Avg steps per task | <10 | Page Agent step counter |
| LLM cost per session | <$0.05 | NV oOS cost calculator |
| Admin copilot adoption | >20% of Pro users | Usage analytics |

---

## 14. Timeline

| Phase | Version | Duration | Key Deliverable |
|-------|---------|----------|----------------|
| **1 — Core Addon** | v0.1.0 | ~2 weeks | Page Agent on frontend chat, REST bridge, settings, tests |
| **2 — Admin Copilot + Pro** | v0.2.0 | ~2 weeks | Admin copilot, workflows, Elementor, analytics, pro gating |
| **3 — Advanced** | v0.3.0 | ~3 weeks | MCP bridge, bidirectional tool↔page, DOM context provider |
| **Stable** | v1.0.0 | +1 week | Polish, docs, WP.org submission |
| **Total** | | ~8 weeks | |

---

## Appendix A: Page Agent API Reference

```js
// Constructor
const agent = new PageAgent({
    model:       'gpt-4o',
    baseURL:     'https://api.openai.com/v1',
    apiKey:      'sk-...',
    language:    'en-US',
    maxSteps:    50,
    systemPrompt: 'You are a helpful WordPress admin assistant.',
});

// Core methods
await agent.execute('Click the login button');
await agent.execute('Fill the form with test data');
agent.stop();        // Abort current task
agent.destroy();     // Cleanup
```

## Appendix B: Page Agent Internal Package Structure

```
page-agent (npm) v1.12.0
├── @page-agent/core               Agent loop, context manager, planning
├── @page-agent/llms               OpenAI-compatible client, function calling
├── @page-agent/page-controller    DOM extraction, interaction, visual mask
└── @page-agent/ui                 Floating panel, chat interface, i18n
```

## Appendix C: Addon Code Conventions

Per `AGENTS.md` + `.context/conventions.md`:
- **PHP:** WordPress Coding Standards, `WP_MCP_AI_` prefix, snake_case
- **JS:** WordPress ESLint Plugin, tabs, single quotes (same as base)
- **Security:** Sanitize input, escape output, capability checks, nonces
- **Tools:** Canonical envelope (`success => true` + `data` or `WP_Error`)
- **Pro gating:** Check `WP_MCP_AI_PRO_ACTIVE` constant before loading pro features
- **Addon pattern:** `nvoos_page_agent_` prefix for functions, `NVOOS_PAGE_AGENT_` for constants

## Appendix D: Base Plugin Surface Used (zero changes)

The addon reads/hooks into these base plugin systems — none need modification:

| Surface | How the addon uses it |
|---------|----------------------|
| `wp_mcp_ai_core_loaded()` | Dependency check at addon load |
| `WP_MCP_AI_Language_Model_Router` | Read model config for Page Agent |
| `WP_MCP_AI_Tool_Registry` | Register `page_agent_execute` tool |
| `wp_mcp_ai_register_tools` action | Hook for tool registration |
| `[mcp_ai_chat]` shortcode | Co-exist on same page; hook into its JS events |
| `wpMcpAiJobBus` (client-side) | Listen for page-agent events |
| `WP_REST_Controller` | Register addon's own REST routes under `nvoos-page-agent/v1` |
| `wp_enqueue_script` / `wp_localize_script` | Standard WP asset loading |
| `get_option` / `add_settings_field` | Standard WP settings API |

---

**Next Steps:**
1. Approve addon-based architecture
2. Create `addons/page-agent/` directory scaffold
3. Spike: `npm install page-agent@^1.12.0` + verify esbuild bundles cleanly
4. Implement Phase 1 core addon
5. Submit to addon inventory (ADDON_INVENTORY.md, entry #19)
