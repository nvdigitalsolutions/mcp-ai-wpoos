# Embedded Addon — Comprehensive Implementation Plan

**Status:** 📋 Implementation Plan  
**Date:** 2026-08-04  
**Author:** AI Agent (Zed) — Research + Architecture  
**Based on:**
- Full codebase audit of `addons/embedded/` (18 PHP classes, 26 JS assets, 15 test files, 16 docs)
- `healthcare-vitals-openmed-integration-plan-v2.md` (7-phase OpenMed proposal, not started)
- `LOCAL-VOICE-EMBEDDED-IMPLEMENTATION-PLAN.md` (5-epic Voice/STT proposal, 44% complete)
- WordPress 7.0 Connectors API & WP AI Client standards
- WordPress 6.9 Abilities API + MCP Adapter patterns
- Industry best-practice research (registry pattern, provider architecture, HIPAA compliance)

---

## Table of Contents

1. [Industry Standards Alignment](#1-industry-standards-alignment)
2. [Architecture Design](#2-architecture-design)
3. [Stream A: Backend Registry & Architecture Hardening](#3-stream-a-backend-registry--architecture-hardening)
4. [Stream B: Voice/STT Completion](#4-stream-b-voicestt-completion)
5. [Stream C: OpenMed Healthcare Integration](#5-stream-c-openmed-healthcare-integration)
6. [Stream D: Quality, Abilities & MCP Compatibility](#6-stream-d-quality-abilities--mcp-compatibility)
7. [Testing Strategy](#7-testing-strategy)
8. [Deployment & Migration](#8-deployment--migration)
9. [File Manifest](#9-file-manifest)

---

## 1. Industry Standards Alignment

### 1.1 WordPress 7.0 Connectors API

WordPress 7.0 (March 2026) introduced the **Connectors API** — a registry-based framework for managing external service connections. The embedded addon should align with its conventions:

| Connectors API Convention | Embedded Addon Equivalent |
|---------------------------|--------------------------|
| `WP_Connector_Registry` singleton | `NV_oOS_Embedded_Backend_Registry` singleton |
| `register( $id, $args )` / `unregister( $id )` | `register_backend( $backend )` / `unregister_backend( $slug )` |
| `is_registered( $id )` / `get_registered( $id )` | `has_backend( $slug )` / `get_backend( $slug )` |
| `get_all_registered()` | `get_all_backends()` |
| `wp_connectors_init` action | `nvoos_embedded_backends_init` action |
| Auto-discovery from provider registry | Auto-registration of built-in backends |

**Key Design Decision:** The embedded addon's `NV_oOS_Embedded_Backend_Registry` follows the same `register()`/`unregister()` pattern that WordPress Core established with the Connectors API. This is the WordPress ecosystem's standard for provider management.

### 1.2 WordPress 6.9 Abilities API

WordPress 6.9 introduced `wp_register_ability()` — a standardized way to expose plugin operations as machine-readable, typed, discoverable capabilities. Every embedded addon tool that an AI agent might invoke should be registered as an ability.

**Registration pattern:**
```php
add_action( 'wp_abilities_api_init', function () {
    if ( ! function_exists( 'wp_register_ability' ) ) {
        return;
    }
    wp_register_ability( 'nvoos-embedded/transcribe-audio', array(
        'label'             => __( 'Transcribe Audio', 'nvoos-embedded' ),
        'description'       => __( 'Converts audio to text using the configured STT backend.', 'nvoos-embedded' ),
        'category'          => 'nvoos-embedded-voice',
        'input_schema'      => array(
            'type'       => 'object',
            'properties' => array(
                'audio'    => array( 'type' => 'string', 'description' => 'Base64-encoded audio data' ),
                'model'    => array( 'type' => 'string', 'default' => 'gemma4:e4b' ),
                'language' => array( 'type' => 'string', 'default' => 'en' ),
            ),
            'required'   => array( 'audio' ),
        ),
        'output_schema'     => array(
            'type'       => 'object',
            'properties' => array(
                'text'     => array( 'type' => 'string' ),
                'language' => array( 'type' => 'string' ),
            ),
        ),
        'permission_callback' => function () {
            return is_user_logged_in() || apply_filters( 'nvoos_embedded_allow_guest_transcribe', false );
        },
        'execute_callback'    => function ( $input ) {
            $transcriber = new WP_MCP_AI_Embedded_Transcribe();
            return $transcriber->transcribe( $input['audio'], $input );
        },
        'meta' => array(
            'mcp' => array( 'public' => true ),
        ),
    ) );
} );
```

### 1.3 WordPress MCP Adapter

The official `wordpress/mcp-adapter` package bridges Abilities to the Model Context Protocol. Once abilities are registered with `meta.mcp.public => true`, AI agents (Claude Desktop, Cursor, VS Code, Claude Code) can discover and execute them as MCP tools.

**Compatibility strategy:**
- All embedded tools register as abilities on `wp_abilities_api_init`
- `meta.mcp.public` flag enabled for read-only operations; gated for destructive ones
- Custom MCP server creation for the embedded addon's tool surface
- STDIO transport support via WP-CLI for local development
- HTTP transport for production via `@automattic/mcp-wordpress-remote`

### 1.4 Registry Pattern (WordPress & Industry)

The registry pattern is the established standard in WordPress 7.0+:
- Connectors API uses `WP_Connector_Registry`
- AI Client uses `ProviderRegistry`
- Abilities API uses `WP_Abilities_Registry`
- MCP Adapter uses server registration

**The embedded addon's backend registry follows the same conventions**, making it familiar to any developer who has worked with WordPress 7.0's Connectors or AI Client.

---

## 2. Architecture Design

### 2.1 Target Architecture

```
┌──────────────────────────────────────────────────────────────────────────┐
│                    EMBEDDED ADDON ARCHITECTURE (v0.2.0)                    │
│                                                                            │
│  ┌──────────────────────────────────────────────────────────────────────┐ │
│  │                    Backend Registry (NEW)                              │ │
│  │  NV_oOS_Embedded_Backend_Registry :: register() / unregister()       │ │
│  │  Action: nvoos_embedded_backends_init                                 │ │
│  └────────────────────────────────┬─────────────────────────────────────┘ │
│                                   │                                        │
│     ┌─────────────────────────────┼─────────────────────────────┐         │
│     │                             │                             │         │
│     ▼                             ▼                             ▼         │
│  ┌──────────────┐  ┌──────────────────────────┐  ┌──────────────────────┐ │
│  │ LLM Backends │  │ STT Backends             │  │ Future Backends      │ │
│  │              │  │                          │  │ (Vision, Embeddings, │ │
│  │ • Client-    │  │ • whisper.cpp WASM       │  │  OpenMed, etc.)      │ │
│  │   Side       │  │ • Gemma 4 Server         │  │                      │ │
│  │   WebLLM     │  │ • Transformers.js        │  │                      │ │
│  │ • Server-    │  │   Whisper                │  │                      │ │
│  │   Side       │  │                          │  │                      │ │
│  │   llama.cpp  │  │                          │  │                      │ │
│  └──────┬───────┘  └───────────┬──────────────┘  └──────────────────────┘ │
│         │                      │                                           │
│         ▼                      ▼                                           │
│  ┌──────────────────────────────────────────────────────────────────────┐ │
│  │                    Abilities Layer (NEW)                               │ │
│  │  wp_register_ability() for all discoverable operations               │ │
│  │  → MCP Adapter compatible (meta.mcp.public)                           │ │
│  └──────────────────────────────────────────────────────────────────────┘ │
│                                   │                                        │
│                                   ▼                                        │
│  ┌──────────────────────────────────────────────────────────────────────┐ │
│  │                    Existing Infrastructure                             │ │
│  │  • NV_oOS_Embedded (core singleton, script enqueue)                  │ │
│  │  • WebChat CPT + Signaling REST + 7 tools                            │ │
│  │  • WebLLM Enqueue Manager + Tool Adapter                             │ │
│  │  • Settings Pages (admin UI)                                          │ │
│  └──────────────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Design Decisions

| Decision | Rationale | Standard Reference |
|----------|-----------|-------------------|
| **Backend Registry follows Connectors API conventions** | `WP_Connector_Registry` established the `register()`/`unregister()` pattern in WordPress 7.0. Aligning reduces cognitive load. | [Connectors API dev note](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/) |
| **All tools register as Abilities** | `wp_register_ability()` is the WordPress standard for exposing operations to AI. NV oOS tools become universally discoverable. | [Abilities API handbook](https://developer.wordpress.org/apis/abilities-api/) |
| **MCP compatibility via meta.mcp.public** | The official MCP Adapter discovers abilities with this flag. No custom MCP server needed for basic discovery. | [MCP Adapter blog post](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/) |
| **Backend interface contract (PHP)** | Each backend implements `NV_oOS_Embedded_LLM_Backend` or `NV_oOS_Embedded_STT_Backend`. Same pattern as STT's `STTServiceAPI` (JS). | STT proposal §1.1 |
| **Settings under single option key** | `nvoos_embedded_settings` with schema validation. Follows `wp_mcp_ai_settings` pattern. | [WP Plugin Options Storage skill](../../.agents/skills/wp-plugin-options-storage/SKILL.md) |
| **Server-side kept as peer backend** | The embedded addon is a separate proprietary addon — no WordPress.org size constraint. Both backends serve different hosting environments. | STT proposal's multi-backend pattern |

---

## 3. Stream A: Backend Registry & Architecture Hardening

**Priority:** 🔴 HIGH | **Duration:** 5 days | **Stories:** 4

### Story A.1 — Backend Registry Foundation (Day 1–2)

#### A.1.1: Interface Contract

**File:** `addons/embedded/includes/embedded/interface-nvoos-embedded-llm-backend.php`

```php
<?php
/**
 * Embedded LLM Backend Interface
 *
 * Every inference backend (client-side WebLLM, server-side llama.cpp, future
 * providers) implements this contract. Follows the same multi-backend pattern
 * established by the STT system (STTServiceAPI in JS, STT backends in PHP).
 *
 * @package NV_oOS_Embedded
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contract for embedded LLM inference backends.
 *
 * @since 0.2.0
 */
interface NV_oOS_Embedded_LLM_Backend {

    /**
     * Unique machine-readable identifier.
     *
     * @return string e.g. 'client_side', 'server_side'
     */
    public function get_slug(): string;

    /**
     * Human-readable display name.
     *
     * @return string e.g. 'Client-Side WebLLM (Browser)'
     */
    public function get_label(): string;

    /**
     * One-paragraph description for settings UI.
     *
     * @return string
     */
    public function get_description(): string;

    /**
     * Whether this backend can operate in the current environment.
     *
     * Client-side always returns true (no server requirements).
     * Server-side checks shell_exec, binary path, PHP functions.
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * Human-readable requirements list for diagnostics.
     *
     * @return array Array of requirement descriptions and their status.
     *               e.g. [ 'shell_exec' => true, 'binary_found' => false, 'ram_sufficient' => true ]
     */
    public function get_requirements(): array;

    /**
     * Execute a chat completion request.
     *
     * Client-side backends return configuration for browser JS.
     * Server-side backends execute inference directly.
     *
     * @param array $messages Chat messages in OpenAI format.
     * @param array $options  Model, temperature, max_tokens, etc.
     * @return array|WP_Error Result array or error.
     */
    public function create_chat_completion( array $messages, array $options );

    /**
     * List models available through this backend.
     *
     * @return array Array of model definitions with slug, label, size, context_window.
     */
    public function get_available_models(): array;

    /**
     * Health status for Site Health integration.
     *
     * @return array Associative array with keys: status (good|recommended|critical),
     *               label, description, actions, test result data.
     */
    public function get_health_status(): array;
}
```

#### A.1.2: Backend Registry

**File:** `addons/embedded/includes/embedded/class-nvoos-embedded-backend-registry.php`

```php
<?php
/**
 * Embedded Backend Registry
 *
 * Central registry for LLM and STT backends following the WordPress 7.0
 * Connectors API pattern: register(), unregister(), is_registered(),
 * get_registered(), get_all_registered().
 *
 * @package NV_oOS_Embedded
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registry singleton for embedded backends.
 *
 * @since 0.2.0
 */
class NV_oOS_Embedded_Backend_Registry {

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Registered LLM backends, keyed by slug.
     *
     * @var array<string, NV_oOS_Embedded_LLM_Backend>
     */
    private $llm_backends = array();

    /**
     * Registered STT backends, keyed by slug.
     *
     * @var array<string, NV_oOS_Embedded_STT_Backend>
     */
    private $stt_backends = array();

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register an LLM backend.
     *
     * @param NV_oOS_Embedded_LLM_Backend $backend Backend instance.
     * @return bool True on success, false if slug already registered.
     */
    public function register_llm_backend( NV_oOS_Embedded_LLM_Backend $backend ): bool {
        $slug = $backend->get_slug();
        if ( isset( $this->llm_backends[ $slug ] ) ) {
            return false;
        }
        $this->llm_backends[ $slug ] = $backend;
        return true;
    }

    /**
     * Unregister an LLM backend.
     *
     * @param string $slug Backend slug.
     * @return NV_oOS_Embedded_LLM_Backend|null Removed backend or null if not found.
     */
    public function unregister_llm_backend( string $slug ): ?NV_oOS_Embedded_LLM_Backend {
        if ( ! isset( $this->llm_backends[ $slug ] ) ) {
            return null;
        }
        $backend = $this->llm_backends[ $slug ];
        unset( $this->llm_backends[ $slug ] );
        return $backend;
    }

    /**
     * Check if an LLM backend is registered.
     *
     * @param string $slug Backend slug.
     * @return bool
     */
    public function has_llm_backend( string $slug ): bool {
        return isset( $this->llm_backends[ $slug ] );
    }

    /**
     * Get a specific LLM backend.
     *
     * @param string $slug Backend slug.
     * @return NV_oOS_Embedded_LLM_Backend|null
     */
    public function get_llm_backend( string $slug ): ?NV_oOS_Embedded_LLM_Backend {
        return $this->llm_backends[ $slug ] ?? null;
    }

    /**
     * Get all registered LLM backends.
     *
     * @return array<string, NV_oOS_Embedded_LLM_Backend>
     */
    public function get_all_llm_backends(): array {
        return $this->llm_backends;
    }

    /**
     * Get available LLM backends (filtered by is_available).
     *
     * @return array<string, NV_oOS_Embedded_LLM_Backend>
     */
    public function get_available_llm_backends(): array {
        return array_filter(
            $this->llm_backends,
            function ( $backend ) {
                return $backend->is_available();
            }
        );
    }

    /**
     * Get the active LLM backend based on settings.
     *
     * @return NV_oOS_Embedded_LLM_Backend|null
     */
    public function get_active_llm_backend(): ?NV_oOS_Embedded_LLM_Backend {
        $settings  = get_option( 'nvoos_embedded_settings', array() );
        $preferred = $settings['inference_backend'] ?? 'auto';

        // Explicit selection.
        if ( 'auto' !== $preferred && $this->has_llm_backend( $preferred ) ) {
            $backend = $this->get_llm_backend( $preferred );
            return $backend && $backend->is_available() ? $backend : null;
        }

        // Auto mode: prefer server-side if available, fall back to client-side.
        $server = $this->get_llm_backend( 'server_side' );
        if ( $server && $server->is_available() ) {
            return $server;
        }

        return $this->get_llm_backend( 'client_side' );
    }

    // ── STT Backend methods (same pattern) ──

    /**
     * Register an STT backend.
     *
     * @param NV_oOS_Embedded_STT_Backend $backend Backend instance.
     * @return bool
     */
    public function register_stt_backend( NV_oOS_Embedded_STT_Backend $backend ): bool {
        $slug = $backend->get_slug();
        if ( isset( $this->stt_backends[ $slug ] ) ) {
            return false;
        }
        $this->stt_backends[ $slug ] = $backend;
        return true;
    }

    // ... (unregister_stt_backend, has_stt_backend, get_stt_backend,
    //      get_all_stt_backends, get_available_stt_backends, get_active_stt_backend
    //      — identical pattern to LLM methods above)
}
```

#### A.1.3: Client-Side Backend Implementation

**File:** `addons/embedded/includes/embedded/class-nvoos-embedded-client-backend.php`

```php
<?php
/**
 * Client-Side WebLLM Backend
 *
 * Implements NV_oOS_Embedded_LLM_Backend for browser-side WebLLM inference.
 * This backend does NOT execute inference on the server — it returns configuration
 * that the browser JS client uses to run WebLLM/WebGPU inference client-side.
 *
 * @package NV_oOS_Embedded
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Client-side WebLLM backend.
 *
 * @since 0.2.0
 */
class NV_oOS_Embedded_Client_Backend implements NV_oOS_Embedded_LLM_Backend {

    /**
     * Available MLC-compiled models for browser inference.
     *
     * @var array
     */
    const MODELS = array(
        'Llama-3.2-1B-Instruct-q4f16_1-MLC' => array(
            'label'          => 'Llama 3.2 1B Instruct',
            'size_mb'        => 800,
            'context_window' => 4096,
            'recommended'    => true,
        ),
        'Qwen2.5-0.5B-Instruct-q4f16_1-MLC' => array(
            'label'          => 'Qwen2.5 0.5B Instruct',
            'size_mb'        => 400,
            'context_window' => 2048,
        ),
        'Qwen2.5-1.5B-Instruct-q4f16_1-MLC' => array(
            'label'          => 'Qwen2.5 1.5B Instruct',
            'size_mb'        => 1000,
            'context_window' => 4096,
        ),
        'Llama-3.2-3B-Instruct-q4f16_1-MLC' => array(
            'label'          => 'Llama 3.2 3B Instruct',
            'size_mb'        => 2000,
            'context_window' => 8192,
        ),
        'Phi-3.5-mini-instruct-q4f16_1-MLC' => array(
            'label'          => 'Phi-3.5 Mini Instruct',
            'size_mb'        => 2500,
            'context_window' => 4096,
        ),
    );

    /** @inheritDoc */
    public function get_slug(): string {
        return 'client_side';
    }

    /** @inheritDoc */
    public function get_label(): string {
        return __( 'Client-Side WebLLM (Browser)', 'nvoos-embedded' );
    }

    /** @inheritDoc */
    public function get_description(): string {
        return __(
            'Runs AI models entirely in the user\'s browser using WebGPU/WebAssembly. '
            . 'Zero server CPU/RAM usage. Works on shared hosting. Requires Chrome 113+, '
            . 'Edge 113+, or Safari 18+. Models auto-download to browser cache on first use.',
            'nvoos-embedded'
        );
    }

    /** @inheritDoc */
    public function is_available(): bool {
        // Client-side has no server requirements — always available.
        return true;
    }

    /** @inheritDoc */
    public function get_requirements(): array {
        return array(
            'webgpu_browser' => array(
                'label'  => __( 'Browser with WebGPU support', 'nvoos-embedded' ),
                'status' => true,
                'note'   => __( 'Chrome 113+, Edge 113+, Safari 18+. Firefox uses WebAssembly fallback.', 'nvoos-embedded' ),
            ),
            'model_download' => array(
                'label'  => __( 'First-use model download', 'nvoos-embedded' ),
                'status' => true,
                'note'   => __( '400MB–2.5GB download on first use per browser. Subsequent uses load from IndexedDB cache.', 'nvoos-embedded' ),
            ),
        );
    }

    /** @inheritDoc */
    public function create_chat_completion( array $messages, array $options ) {
        // Client-side doesn't execute inference on server.
        // Returns configuration for the browser JS client.
        $settings = get_option( 'nvoos_embedded_settings', array() );
        $model    = $options['model'] ?? ( $settings['client_model'] ?? 'Llama-3.2-1B-Instruct-q4f16_1-MLC' );

        return array(
            'backend'   => 'client_side',
            'model'     => $model,
            'cdn_url'   => 'https://cdn.jsdelivr.net/npm/@mlc-ai/web-llm@latest/dist/web-llm.min.js',
            'stream'    => ! empty( $options['stream'] ),
            'max_tokens' => $options['max_tokens'] ?? 512,
            'temperature' => $options['temperature'] ?? 0.7,
        );
    }

    /** @inheritDoc */
    public function get_available_models(): array {
        return self::MODELS;
    }

    /** @inheritDoc */
    public function get_health_status(): array {
        return array(
            'status'      => 'good',
            'label'       => __( 'Client-Side WebLLM Backend', 'nvoos-embedded' ),
            'description' => __( 'No server requirements. Works on any hosting.', 'nvoos-embedded' ),
        );
    }
}
```

#### A.1.4: Server-Side Backend Implementation

**File:** `addons/embedded/includes/embedded/class-nvoos-embedded-server-backend.php`

```php
<?php
/**
 * Server-Side llama.cpp Backend
 *
 * Implements NV_oOS_Embedded_LLM_Backend for server-side GGUF inference
 * via llama.cpp. Wraps the existing WP_MCP_AI_Embedded_Client as an
 * internal implementation detail.
 *
 * @package NV_oOS_Embedded
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Server-side llama.cpp backend.
 *
 * @since 0.2.0
 */
class NV_oOS_Embedded_Server_Backend implements NV_oOS_Embedded_LLM_Backend {

    /**
     * Internal client instance (existing WP_MCP_AI_Embedded_Client).
     *
     * @var WP_MCP_AI_Embedded_Client|null
     */
    private $client = null;

    /**
     * Get the internal client, creating if needed.
     *
     * @return WP_MCP_AI_Embedded_Client
     */
    private function get_client(): WP_MCP_AI_Embedded_Client {
        if ( null === $this->client && class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
            $this->client = new WP_MCP_AI_Embedded_Client();
        }
        return $this->client;
    }

    /** @inheritDoc */
    public function get_slug(): string {
        return 'server_side';
    }

    /** @inheritDoc */
    public function get_label(): string {
        return __( 'Server-Side llama.cpp (VPS/Dedicated)', 'nvoos-embedded' );
    }

    /** @inheritDoc */
    public function get_description(): string {
        return __(
            'Runs AI models on your WordPress server using llama.cpp with GGUF models. '
            . 'Requires shell_exec access and sufficient server RAM. Best for VPS and '
            . 'dedicated servers. Provides consistent performance regardless of client device.',
            'nvoos-embedded'
        );
    }

    /** @inheritDoc */
    public function is_available(): bool {
        if ( ! function_exists( 'shell_exec' ) ) {
            return false;
        }

        $disabled = explode( ',', ini_get( 'disable_functions' ) );
        $disabled = array_map( 'trim', $disabled );

        if ( in_array( 'shell_exec', $disabled, true ) ) {
            return false;
        }

        $client = $this->get_client();
        if ( ! $client ) {
            return false;
        }

        $binary = $client->get_inference_binary();
        return ! is_wp_error( $binary );
    }

    /** @inheritDoc */
    public function get_requirements(): array {
        $client = $this->get_client();

        $shell_available = function_exists( 'shell_exec' );
        if ( $shell_available ) {
            $disabled = explode( ',', ini_get( 'disable_functions' ) );
            $disabled = array_map( 'trim', $disabled );
            $shell_available = ! in_array( 'shell_exec', $disabled, true );
        }

        $binary_status = $client
            ? ( is_wp_error( $client->get_inference_binary() ) ? false : true )
            : false;

        $proc_open_available = function_exists( 'proc_open' );

        return array(
            'shell_exec' => array(
                'label'  => __( 'shell_exec() available', 'nvoos-embedded' ),
                'status' => $shell_available,
                'note'   => $shell_available
                    ? __( 'PHP shell_exec() is enabled.', 'nvoos-embedded' )
                    : __( 'shell_exec() is disabled. Contact your hosting provider.', 'nvoos-embedded' ),
            ),
            'binary_found' => array(
                'label'  => __( 'llama.cpp binary found', 'nvoos-embedded' ),
                'status' => $binary_status,
                'note'   => $binary_status
                    ? __( 'llama.cpp binary detected.', 'nvoos-embedded' )
                    : __( 'Binary not found. Install llama.cpp.', 'nvoos-embedded' ),
            ),
            'proc_open' => array(
                'label'  => __( 'proc_open() available', 'nvoos-embedded' ),
                'status' => $proc_open_available,
                'note'   => $proc_open_available
                    ? __( 'Streaming support available.', 'nvoos-embedded' )
                    : __( 'proc_open() not available. Streaming disabled.', 'nvoos-embedded' ),
            ),
        );
    }

    /** @inheritDoc */
    public function create_chat_completion( array $messages, array $options ) {
        $client = $this->get_client();
        if ( ! $client ) {
            return new WP_Error(
                'embedded_client_unavailable',
                __( 'Server-side embedded client is not available.', 'nvoos-embedded' )
            );
        }

        // Streaming via proc_open when available.
        if ( ! empty( $options['stream'] ) && function_exists( 'proc_open' ) ) {
            return $this->stream_chat_completion( $messages, $options );
        }

        return $client->create_chat_completion( $messages, $options );
    }

    /**
     * Stream chat completion using proc_open (non-blocking).
     *
     * @param array $messages Chat messages.
     * @param array $options  Request options.
     * @return Generator|WP_Error
     */
    private function stream_chat_completion( array $messages, array $options ) {
        // Delegate to existing WP_MCP_AI_Embedded_Client with stream flag.
        // The client's run_binary() method is enhanced to use proc_open
        // when stream mode is requested (see Story A.2.1).
        $client = $this->get_client();
        return $client->create_chat_completion( $messages, array_merge( $options, array( 'stream' => true ) ) );
    }

    /** @inheritDoc */
    public function get_available_models(): array {
        $client = $this->get_client();
        return $client ? $client->get_available_models() : array();
    }

    /** @inheritDoc */
    public function get_health_status(): array {
        $reqs = $this->get_requirements();
        $all_ok = true;

        foreach ( $reqs as $req ) {
            if ( ! $req['status'] ) {
                $all_ok = false;
                break;
            }
        }

        return array(
            'status'      => $all_ok ? 'good' : 'critical',
            'label'       => __( 'Server-Side llama.cpp Backend', 'nvoos-embedded' ),
            'description' => $all_ok
                ? __( 'Server-side inference is operational.', 'nvoos-embedded' )
                : __( 'One or more requirements are not met.', 'nvoos-embedded' ),
            'actions'     => $all_ok ? '' : __( 'Install llama.cpp binary and ensure shell_exec is enabled.', 'nvoos-embedded' ),
            'test'        => array(
                'label' => __( 'Server-side embedded requirements', 'nvoos-embedded' ),
                'status' => $all_ok ? 'good' : 'critical',
                'badge'  => $all_ok ? array(
                    'label' => __( 'Operational', 'nvoos-embedded' ),
                    'color' => 'green',
                ) : array(
                    'label' => __( 'Not available', 'nvoos-embedded' ),
                    'color' => 'red',
                ),
                'description' => '<ul><li>' . implode( '</li><li>', array_column( $reqs, 'note' ) ) . '</li></ul>',
                'test' => 'nvoos_embedded_server_requirements',
            ),
        );
    }
}
```

#### A.1.5: Wiring the Registry into NV_oOS_Embedded

**Modifications to `class-nvoos-embedded.php`:**

```php
// In on_plugins_loaded():
// Replace direct WP_MCP_AI_Embedded_Client usage with registry dispatch.

add_action( 'nvoos_embedded_backends_init', array( __CLASS__, 'register_default_backends' ) );

// New method:
public static function register_default_backends() {
    $registry = NV_oOS_Embedded_Backend_Registry::get_instance();

    // Register LLM backends.
    $registry->register_llm_backend( new NV_oOS_Embedded_Client_Backend() );
    $registry->register_llm_backend( new NV_oOS_Embedded_Server_Backend() );

    /**
     * Fires after default embedded backends are registered.
     *
     * Plugins can use this to register additional backends or
     * override existing ones (unregister + register pattern).
     *
     * @since 0.2.0
     *
     * @param NV_oOS_Embedded_Backend_Registry $registry The backend registry.
     */
    do_action( 'nvoos_embedded_backends_registered', $registry );
}

// Update handle_embedded_chat_completion to use registry:
public static function handle_embedded_chat_completion( $result, $messages, $options ) {
    if ( null !== $result ) {
        return $result;
    }

    $registry = NV_oOS_Embedded_Backend_Registry::get_instance();
    $backend  = $registry->get_active_llm_backend();

    if ( ! $backend ) {
        return new WP_Error(
            'no_embedded_backend',
            __( 'No embedded inference backend is available.', 'nvoos-embedded' )
        );
    }

    return $backend->create_chat_completion( $messages, $options );
}
```

### Story A.2 — Server-Side Streaming Enhancement (Day 2–3)

#### A.2.1: proc_open Streaming in WP_MCP_AI_Embedded_Client

**Modify:** `class-wp-mcp-ai-embedded-client.php`, method `run_binary()`

Replace blocking `shell_exec()` with `proc_open()` for streaming:

```php
/**
 * Run inference binary.
 *
 * Uses proc_open for streaming (non-blocking stdout reads) when available.
 * Falls back to shell_exec for hosts without proc_open.
 *
 * @since 0.2.0
 *
 * @param string $command Full command to execute.
 * @param array  $options Options including 'stream' flag.
 * @return array|WP_Error Result with 'output' and 'exit_code', or error.
 */
private function run_binary( $command, $options = array() ) {
    $stream = ! empty( $options['stream'] );

    if ( $stream && function_exists( 'proc_open' ) ) {
        return $this->run_binary_streaming( $command, $options );
    }

    // Fallback: blocking shell_exec.
    $output    = shell_exec( $command . ' 2>&1' );
    $exit_code = 0;

    return array(
        'output'    => $output,
        'exit_code' => $exit_code,
    );
}

/**
 * Execute binary with proc_open for streaming output.
 *
 * @param string $command Command to execute.
 * @param array  $options Stream options.
 * @return array|WP_Error
 */
private function run_binary_streaming( $command, $options = array() ) {
    $descriptors = array(
        0 => array( 'pipe', 'r' ),  // stdin.
        1 => array( 'pipe', 'w' ),  // stdout.
        2 => array( 'pipe', 'w' ),  // stderr.
    );

    $process = proc_open( $command, $descriptors, $pipes );

    if ( ! is_resource( $process ) ) {
        return new WP_Error(
            'proc_open_failed',
            __( 'Failed to start inference process.', 'nvoos-embedded' )
        );
    }

    fclose( $pipes[0] ); // Close stdin.

    // Set stdout to non-blocking.
    stream_set_blocking( $pipes[1], false );
    stream_set_blocking( $pipes[2], false );

    $output    = '';
    $timeout   = $options['timeout'] ?? 120;
    $start     = time();

    while ( ! feof( $pipes[1] ) ) {
        $chunk = fread( $pipes[1], 4096 );
        if ( false !== $chunk && '' !== $chunk ) {
            $output .= $chunk;

            // Emit chunk via action for SSE/streaming consumers.
            do_action( 'nvoos_embedded_stream_chunk', $chunk, $options );
        }

        if ( ( time() - $start ) > $timeout ) {
            proc_terminate( $process );
            fclose( $pipes[1] );
            fclose( $pipes[2] );
            proc_close( $process );
            return new WP_Error(
                'inference_timeout',
                __( 'Inference timed out.', 'nvoos-embedded' )
            );
        }

        usleep( 10000 ); // 10ms sleep to prevent CPU spin.
    }

    fclose( $pipes[1] );
    fclose( $pipes[2] );

    $exit_code = proc_close( $process );

    return array(
        'output'    => $output,
        'exit_code' => $exit_code,
    );
}
```

#### A.2.2: Model Integrity Verification

**Modify:** `class-wp-mcp-ai-embedded-client.php`, method `download_model()`

Add SHA-256 hash verification:

```php
/**
 * Verify downloaded model integrity against Hugging Face model card.
 *
 * @param string $file_path Path to downloaded GGUF file.
 * @param string $model_slug Model identifier.
 * @return bool|WP_Error True if verified, WP_Error on mismatch.
 */
private function verify_model_integrity( $file_path, $model_slug ) {
    $models    = $this->get_available_models();
    $model_def = $models[ $model_slug ] ?? null;

    if ( ! $model_def || empty( $model_def['sha256'] ) ) {
        // No hash available — skip verification.
        return true;
    }

    $actual_hash = hash_file( 'sha256', $file_path );

    if ( ! hash_equals( $model_def['sha256'], $actual_hash ) ) {
        // Delete the corrupted file.
        @unlink( $file_path );

        return new WP_Error(
            'model_integrity_failed',
            sprintf(
                /* translators: %s: model slug */
                __( 'Model %s failed integrity check. The downloaded file may be corrupted.', 'nvoos-embedded' ),
                $model_slug
            )
        );
    }

    return true;
}
```

### Story A.3 — Settings Consolidation (Day 3–4)

**File:** `addons/embedded/includes/class-nvoos-embedded.php` — Add settings schema and migration.

```php
/**
 * Default settings schema.
 *
 * @since 0.2.0
 *
 * @return array
 */
public static function get_default_settings(): array {
    return array(
        // Inference.
        'inference_backend'     => 'auto',  // auto | client_side | server_side
        'client_model'          => 'Llama-3.2-1B-Instruct-q4f16_1-MLC',
        'server_model'          => 'granite-3.1-2b-instruct',
        'server_binary_path'    => '',
        'server_max_tokens'     => 512,
        'server_temperature'    => 0.7,
        'server_context_window' => 2048,
        // Voice / STT.
        'enable_voice_mode'     => false,
        'stt_backend'           => 'whisper_cpp_wasm',
        'stt_model'             => 'tiny.en',
        'vad_threshold'         => 0.5,
        'vad_silence_ms'        => 800,
        'gemma4_audio_endpoint' => '',
        // Features.
        'enable_tool_calling'   => false,
        'enable_multimodal'     => false,
        'enable_langchain'      => false,
        // WebChat.
        'enable_webchat'        => false,
        'webchat_max_rooms'     => 50,
    );
}

/**
 * Migrate settings from scattered options to unified key.
 *
 * Runs once on plugin update. Preserves old keys for 1 release cycle.
 *
 * @since 0.2.0
 *
 * @return void
 */
public static function maybe_migrate_settings() {
    $new_settings     = get_option( self::OPTION_KEY, array() );
    $migration_done   = get_option( 'nvoos_embedded_settings_migrated_v020', false );

    if ( $migration_done ) {
        return;
    }

    // Merge from wp_mcp_ai_settings.
    $base_settings = get_option( 'wp_mcp_ai_settings', array() );
    if ( ! empty( $base_settings['enable_webchat_integration'] ) ) {
        $new_settings['enable_webchat'] = true;
    }

    // Merge from standalone feature flags.
    if ( get_option( 'wp_mcp_ai_enable_webllm_tools', false ) ) {
        $new_settings['enable_tool_calling'] = true;
    }
    if ( get_option( 'wp_mcp_ai_enable_webllm_vision', false ) ) {
        $new_settings['enable_multimodal'] = true;
    }

    // Merge any existing nvoos_embedded_settings that may already exist.
    $existing = get_option( self::OPTION_KEY, array() );
    $new_settings = array_merge( self::get_default_settings(), $existing, $new_settings );

    update_option( self::OPTION_KEY, $new_settings, 'yes' );
    update_option( 'nvoos_embedded_settings_migrated_v020', true );

    // Clean up old standalone options.
    delete_option( 'wp_mcp_ai_enable_webllm_tools' );
    delete_option( 'wp_mcp_ai_enable_webllm_vision' );
}
```

### Story A.4 — Documentation Fix (Day 4–5)

**Documents to correct:**

| File | Change |
|------|--------|
| `EMBEDDED_LLM_FAQ.md` | Remove "Server-side is deprecated ❌" claim. Replace with backend selection guide. |
| `EMBEDDED_LLM_FAQ.md` Q4 | Change from "Should server-side be removed? YES" to "Both backends are supported." |
| `IMPLEMENTATION_COMPLETE.md` | Change "Server-side will be removed" to "Server-side maintained as peer backend." |
| `EMBEDDED_LLM_COMPARISON.md` | Rename "Legacy" to "Server-Side llama.cpp Backend". |
| `README.md` | Change "Don't use server-side" to "Choose the backend that matches your hosting." |

**New documentation structure:**
```
addons/embedded/docs/
├── README.md                          ← Quick start (update)
├── architecture.md                    ← NEW: backend registry, dual-path diagram
├── configuration.md                   ← NEW: settings reference
├── backends/
│   ├── client-side-webllm.md          ← Client-side guide
│   └── server-side-llamacpp.md        ← Server-side setup
├── voice-stt.md                       ← NEW: voice mode + STT config
├── webchat.md                         ← NEW: WebChat guide
├── security.md                        ← NEW: security posture
├── CHANGELOG.md
└── archive/                           ← Old docs with redirect notices
```

---

## 4. Stream B: Voice/STT Completion

**Priority:** 🟡 MEDIUM | **Duration:** 7 days | **Stories:** 11 (remaining)

### 4.1 Epic 3 — Voice Mode UX (Days 1–3)

#### Story B.1.1: Voice Mode State Machine

**File:** `assets/js/voice-mode-embedded.js` (enhance existing)

```javascript
/**
 * Voice mode state machine for embedded STT.
 *
 * States: idle → listening → transcribing → responding → idle
 *
 * @since 1.3.0
 */
const VoiceModeState = {
    IDLE:          'idle',
    LISTENING:     'listening',
    TRANSCRIBING:  'transcribing',
    RESPONDING:    'responding',
    ERROR:         'error',
};

class EmbeddedVoiceMode {
    constructor( options ) {
        this.state          = VoiceModeState.IDLE;
        this.sttService     = null;
        this.audioCapture   = null;
        this.onStateChange  = options.onStateChange || ( () => {} );
        this.onTranscript   = options.onTranscript || ( () => {} );
        this.onError        = options.onError || ( () => {} );
    }

    /**
     * Transition to a new state, firing callback.
     *
     * @param {string} newState VoiceModeState value.
     */
    setState( newState ) {
        const oldState = this.state;
        this.state = newState;
        this.onStateChange( newState, oldState );
    }

    /**
     * Start listening (push-to-talk pressed).
     */
    async startListening() {
        if ( this.state !== VoiceModeState.IDLE ) {
            return;
        }

        this.setState( VoiceModeState.LISTENING );

        try {
            await this.audioCapture.start();
        } catch ( error ) {
            this.setState( VoiceModeState.ERROR );
            this.onError( error );
        }
    }

    /**
     * Stop listening and transcribe (push-to-talk released).
     */
    async stopAndTranscribe() {
        if ( this.state !== VoiceModeState.LISTENING ) {
            return;
        }

        this.setState( VoiceModeState.TRANSCRIBING );

        try {
            const audioBlob = await this.audioCapture.stop();
            const result    = await this.sttService.transcribe( audioBlob );
            this.onTranscript( result.text );
            this.setState( VoiceModeState.IDLE );
        } catch ( error ) {
            this.setState( VoiceModeState.ERROR );
            this.onError( error );
        }
    }

    /**
     * Cancel current operation and return to idle.
     */
    cancel() {
        if ( this.audioCapture ) {
            this.audioCapture.stop();
        }
        this.setState( VoiceModeState.IDLE );
    }

    /**
     * Check if voice mode is supported in current browser.
     *
     * @return {boolean}
     */
    static isSupported() {
        return !! (
            navigator.mediaDevices?.getUserMedia &&
            ( window.AudioContext || window.webkitAudioContext )
        );
    }
}
```

#### Story B.1.2: Push-to-Talk Button

Add microphone button to chat input. Uses `pointerdown`/`pointerup` events for hold-to-talk behavior. Same CSS class conventions as `chat-voice-mode-integration.js` (`--listening`, `--processing`).

#### Story B.1.3: Waveform Visualization

Canvas-based real-time audio level display using `AnalyserNode.getByteTimeDomainData()`. Rendered during `LISTENING` state.

#### Story B.1.4: Transcription Display Overlay

Shows interim transcript during `TRANSCRIBING` state. Same DOM structure as `chat-transcription-realtime-service.js` for CSS consistency.

#### Story B.1.5: Accessibility

- `aria-label` on PTT button
- Focus management through state transitions
- Screen reader announcements on state changes (`aria-live="polite"` region)
- Keyboard shortcut to toggle voice mode (configurable)
- WCAG 2.1 AA color contrast for waveform visualization

### 4.2 Epic 4 — Tool Calling from Voice (Days 3–5)

#### Story B.2.1: Transcript-to-Tool Pipeline

Route transcribed text through WebLLM with tool definitions. Follows existing `webllm-function-calling-client.js` pattern.

#### Story B.2.2: Voice-Initiated Function Calling

The AI model decides if the transcribed text is:
- A direct message → respond normally
- A tool invocation → execute tool, then respond

#### Story B.2.3: Progress Feedback During Voice Tool Execution

TTS or text feedback while tools run. Uses `nvoos_embedded_stream_chunk` action (PHP) or WebLLM streaming (JS).

### 4.3 Epic 5 — Polish & Testing (Days 5–7)

- PHPUnit tests for transcribe endpoint edge cases
- JS unit tests for STT service and state machine
- Manual integration test plan
- Browser compatibility matrix (Chrome, Edge, Safari, Firefox WASM fallback)

---

## 5. Stream C: OpenMed Healthcare Integration

**Priority:** 🟡 MEDIUM | **Duration:** 14 days | **Phases:** 6

### 5.1 Phase 1 — OpenMed Service Client (Days 1–3)

**New file:** `addons/pro/includes/tools/healthcare/class-wp-mcp-ai-openmed-client.php`

Follows the existing `WP_MCP_AI_Healthcare_Engine` singleton pattern. Key methods:

```php
class WP_MCP_AI_OpenMed_Client {
    const SETTINGS_OPTION = 'wp_mcp_ai_openmed_settings';

    private $base_url;
    private $api_key;
    private $timeout    = 30;
    private $verify_ssl = true;

    public static function get_instance(): self;
    public static function is_configured(): bool;
    public function health(): array|WP_Error;
    public function deidentify( string $text, string $method, array $opts = [] ): array|WP_Error;
    public function extract_pii( string $text, array $opts = [] ): array|WP_Error;
    public function analyze_text( string $text, string $model_name, array $opts = [] ): array|WP_Error;
    public function get_loaded_models(): array|WP_Error;
    private function request( string $method, string $path, ?array $body = null ): array|WP_Error;
}
```

**Settings added to Healthcare Vitals admin page:**
- Service URL (default: `http://localhost:8080`)
- API key (password field, stored in `connectors_ai_openmed_api_key` per WP 7.0 convention)
- Default PII model
- Default NER model
- Connection test button

### 5.2 Phase 2 — PII De-identification Tool (Days 3–5)

**New tool:** `deidentify_health_record`

5-layer defense-in-depth security:
1. PHI Acknowledgement gate
2. User capability check (`deidentify_phi`)
3. Tool-level capability enforcement
4. Immutable audit record
5. No raw PHI persisted in WordPress

Register as WordPress Ability:
```php
wp_register_ability( 'nvoos-healthcare/deidentify-record', array(
    'label'             => __( 'De-identify Health Record', 'nvoos-embedded' ),
    'description'       => __( 'Removes 18 HIPAA Safe Harbor identifiers from clinical text.', 'nvoos-embedded' ),
    'category'          => 'nvoos-healthcare',
    'permission_callback' => function () {
        return current_user_can( 'deidentify_phi' );
    },
    'execute_callback'  => function ( $input ) {
        $client = WP_MCP_AI_OpenMed_Client::get_instance();
        return $client->deidentify( $input['text'], $input['method'], $input );
    },
    'meta' => array( 'mcp' => array( 'public' => false ) ), // Not public — PHI-aware.
) );
```

### 5.3 Phase 3 — Clinical NER (Days 5–7)

**New tools:** `extract_clinical_entities`, `extract_and_import_clinical_entities`

### 5.4–5.6 — Docker, Site Health, Testing (Days 7–14)

Follows the detailed specifications in `healthcare-vitals-openmed-integration-plan-v2.md` Phases 4–7.

---

## 6. Stream D: Quality, Abilities & MCP Compatibility

**Priority:** 🟢 LOW | **Duration:** 5 days | **Stories:** 4

### Story D.1 — Multi-Modal Vision (Day 1–2)

Expose existing `webllm-multimodal-client.js` through settings UI:
- Add "Enable Multi-Modal (Vision)" checkbox
- Configure 2 models: LLaVA v1.5 7B, Qwen2-VL 2B
- Wire image upload → base64 → WebLLM vision pipeline
- Add `client_analyze_image` ability

### Story D.2 — Client-Side Streaming Wiring (Day 3)

WebLLM natively supports streaming via `engine.chat.completions.create({ stream: true })`. Wire the token callback to the existing SSE infrastructure in `chat.js`.

### Story D.3 — Security Hardening (Day 4)

Address 3 remaining audit findings:
- Verify WebChat REST endpoints have proper `permission_callback`
- Audit STT transcribe for DoS protection
- Add rate limiting to transcribe endpoint
- Verify nonce verification on all AJAX handlers

### Story D.4 — Abilities Registration & MCP Compatibility (Day 5)

Register all embedded tools as WordPress Abilities. This is the **highest-value single task** in Stream D — it makes every embedded tool discoverable by AI agents via the MCP Adapter.

**Abilities to register:**

| Ability ID | Tool/Operation | MCP Public? |
|-----------|---------------|-------------|
| `nvoos-embedded/transcribe-audio` | STT transcribe | Yes (read-only) |
| `nvoos-embedded/list-stt-backends` | STT backend listing | Yes |
| `nvoos-embedded/get-llm-backends` | LLM backend listing | Yes |
| `nvoos-embedded/get-model-list` | Available models | Yes |
| `nvoos-embedded/create-webchat-room` | WebChat room create | No (state-changing) |
| `nvoos-embedded/get-webchat-messages` | WebChat messages | Conditional |
| `nvoos-embedded/send-webchat-message` | WebChat message send | No |
| `nvoos-embedded/analyze-image` | Multi-modal vision | Yes |
| `nvoos-healthcare/deidentify-record` | PHI de-identification | No (PHI-aware) |
| `nvoos-healthcare/extract-clinical-entities` | Clinical NER | Yes |

**Registration example (aggregated in a single file):**

**New file:** `addons/embedded/includes/abilities/class-nvoos-embedded-abilities.php`

```php
<?php
/**
 * Embedded Addon — Abilities Registration
 *
 * Registers all embedded addon operations as WordPress Abilities
 * for AI agent discoverability via the MCP Adapter.
 *
 * @package NV_oOS_Embedded
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abilities registrar for the embedded addon.
 *
 * @since 0.2.0
 */
class NV_oOS_Embedded_Abilities {

    /**
     * Register all abilities on wp_abilities_api_init.
     *
     * @return void
     */
    public static function init() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
    }

    /**
     * Register embedded addon abilities.
     *
     * @return void
     */
    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        self::register_transcribe_ability();
        self::register_backend_list_ability();
        self::register_model_list_ability();
        // ... additional abilities.
    }

    /**
     * Register transcribe-audio ability.
     */
    private static function register_transcribe_ability() {
        wp_register_ability( 'nvoos-embedded/transcribe-audio', array(
            'label'       => __( 'Transcribe Audio', 'nvoos-embedded' ),
            'description' => __( 'Converts speech audio to text using the configured STT backend.', 'nvoos-embedded' ),
            'category'    => 'nvoos-embedded-voice',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'audio'    => array(
                        'type'        => 'string',
                        'description' => __( 'Base64-encoded WAV audio data.', 'nvoos-embedded' ),
                    ),
                    'language' => array(
                        'type'    => 'string',
                        'default' => 'en',
                    ),
                ),
                'required' => array( 'audio' ),
            ),
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'text'     => array( 'type' => 'string' ),
                    'language' => array( 'type' => 'string' ),
                ),
            ),
            'permission_callback' => function () {
                return apply_filters( 'nvoos_embedded_allow_guest_transcribe', is_user_logged_in() );
            },
            'execute_callback' => function ( $input ) {
                $transcriber = new WP_MCP_AI_Embedded_Transcribe();
                return $transcriber->transcribe( $input['audio'], array(
                    'language' => $input['language'] ?? 'en',
                ) );
            },
            'meta' => array(
                'mcp' => array( 'public' => true ),
            ),
        ) );
    }

    /**
     * Register backend listing ability.
     */
    private static function register_backend_list_ability() {
        wp_register_ability( 'nvoos-embedded/get-llm-backends', array(
            'label'       => __( 'Get LLM Backends', 'nvoos-embedded' ),
            'description' => __( 'Lists available embedded LLM inference backends and their status.', 'nvoos-embedded' ),
            'category'    => 'nvoos-embedded-inference',
            'input_schema'  => array( 'type' => 'object', 'properties' => array() ),
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'backends' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'slug'        => array( 'type' => 'string' ),
                                'label'       => array( 'type' => 'string' ),
                                'available'   => array( 'type' => 'boolean' ),
                                'description' => array( 'type' => 'string' ),
                            ),
                        ),
                    ),
                    'active' => array( 'type' => 'string' ),
                ),
            ),
            'permission_callback' => '__return_true',
            'execute_callback' => function () {
                $registry = NV_oOS_Embedded_Backend_Registry::get_instance();
                $backends = array();

                foreach ( $registry->get_all_llm_backends() as $slug => $backend ) {
                    $backends[] = array(
                        'slug'        => $slug,
                        'label'       => $backend->get_label(),
                        'available'   => $backend->is_available(),
                        'description' => $backend->get_description(),
                    );
                }

                $active = $registry->get_active_llm_backend();

                return array(
                    'backends' => $backends,
                    'active'   => $active ? $active->get_slug() : null,
                );
            },
            'meta' => array(
                'mcp' => array( 'public' => true ),
            ),
        ) );
    }
}
```

---

## 7. Testing Strategy

### 7.1 New PHPUnit Tests

| Test File | Covers |
|-----------|--------|
| `test-embedded-backend-registry.php` | `NV_oOS_Embedded_Backend_Registry`: register, unregister, get, has, duplicate prevention |
| `test-embedded-client-backend.php` | `NV_oOS_Embedded_Client_Backend`: is_available, get_models, create_chat_completion returns config |
| `test-embedded-server-backend.php` | `NV_oOS_Embedded_Server_Backend`: is_available, requirements, health status |
| `test-embedded-streaming.php` | `run_binary_streaming()`: proc_open, timeout, chunk emission |
| `test-embedded-model-integrity.php` | SHA-256 verification: match, mismatch, no-hash-available |
| `test-embedded-settings-migration.php` | Migration from scattered options to unified key |
| `test-embedded-abilities-registration.php` | `wp_register_ability()` calls, schema validation, execute callbacks |
| `test-embedded-voice-state-machine.php` | Voice mode state transitions, edge cases |
| `test-openmed-client.php` | OpenMed service client: health, deidentify, analyze, error handling |
| `test-openmed-tool-deidentify.php` | `deidentify_health_record` tool: capability check, PHI gate, audit trail |

### 7.2 New JavaScript Tests

| Test File | Covers |
|-----------|--------|
| `stt-service-api.test.js` | STT backend registration, backend selection |
| `voice-mode-state-machine.test.js` | State transitions, cancel behavior, error handling |
| `webllm-streaming.test.js` | Token-by-token callback, stream completion |

### 7.3 Integration Tests

| Scenario | Method |
|----------|--------|
| Backend registry: client-side + server-side registered, auto selects available | PHPUnit |
| Settings migration: scattered keys → unified, old keys cleaned | PHPUnit |
| Abilities: registered abilities appear in `wp_get_abilities()` | PHPUnit |
| MCP Adapter: abilities with `meta.mcp.public` discoverable via MCP tools | Manual + WP-CLI |
| OpenMed: Docker container health → deidentify → audit trail | Docker Compose + PHPUnit |
| Voice UX: PTT → waveform → transcribe → display | Manual + browser test matrix |

---

## 8. Deployment & Migration

### 8.1 Activation Sequence

```
1. Plugin loads → NV_oOS_Embedded::init()
2. plugins_loaded → verify base plugin active
3. nvoos_embedded_backends_init → register default backends
4. nvoos_embedded_backends_registered → 3rd-party backends register
5. wp_abilities_api_init → register embedded abilities
6. rest_api_init → register transcribe endpoint
7. wp_enqueue_scripts → enqueue based on active backend
8. Site Health → add embedded health checks
```

### 8.2 Backward Compatibility

| Old API | New API | Migration |
|---------|---------|-----------|
| `wp_mcp_ai_embedded_chat_completion` filter | Dispatched via `NV_oOS_Embedded_Backend_Registry::get_active_llm_backend()` | Filter still fires; internal dispatch changed |
| `wp_mcp_ai_is_embedded_provider_available` filter | `NV_oOS_Embedded_Server_Backend::is_available()` | Filter preserved; checks backend registry |
| `WP_MCP_AI_Embedded_Client` direct instantiation | Wrapped by `NV_oOS_Embedded_Server_Backend` | Direct usage still works; wrapped for new API |
| Scattered settings keys | `nvoos_embedded_settings` | Auto-migration on update; old keys preserved 1 cycle |

### 8.3 Rollback Safety

- Old settings keys NOT deleted — only new unified key is updated
- `WP_MCP_AI_Embedded_Client` class NOT removed — wrapped by backend
- `wp_mcp_ai_embedded_chat_completion` filter preserved as pass-through
- `nvoos_embedded_settings_migrated_v020` flag gates migration

---

## 9. File Manifest

### New Files

```
addons/embedded/includes/embedded/interface-nvoos-embedded-llm-backend.php
addons/embedded/includes/embedded/class-nvoos-embedded-server-backend.php
addons/embedded/includes/embedded/class-nvoos-embedded-client-backend.php
addons/embedded/includes/embedded/class-nvoos-embedded-backend-registry.php
addons/embedded/includes/abilities/class-nvoos-embedded-abilities.php
addons/embedded/docs/architecture.md
addons/embedded/docs/configuration.md
addons/embedded/docs/backends/client-side-webllm.md
addons/embedded/docs/backends/server-side-llamacpp.md
addons/embedded/docs/voice-stt.md
addons/embedded/docs/webchat.md
addons/embedded/docs/security.md
tests/php/test-embedded-backend-registry.php
tests/php/test-embedded-client-backend.php
tests/php/test-embedded-server-backend.php
tests/php/test-embedded-streaming.php
tests/php/test-embedded-model-integrity.php
tests/php/test-embedded-settings-migration.php
tests/php/test-embedded-abilities-registration.php
tests/php/test-embedded-voice-state-machine.php

# OpenMed (Pro addon):
addons/pro/includes/tools/healthcare/class-wp-mcp-ai-openmed-client.php
addons/pro/includes/tools/healthcare/class-wp-mcp-ai-tool-deidentify-health-record.php
addons/pro/includes/tools/healthcare/class-wp-mcp-ai-tool-extract-clinical-entities.php
addons/pro/includes/tools/healthcare/class-wp-mcp-ai-tool-extract-and-import-clinical-entities.php
addons/pro/includes/tools/healthcare/class-wp-mcp-ai-healthcare-audit.php
addons/pro/includes/admin/class-wp-mcp-ai-openmed-settings.php
```

### Modified Files

```
addons/embedded/nvoos-embedded.php                                ← Load abilities class
addons/embedded/includes/class-nvoos-embedded.php                 ← Backend registry + settings migration + Site Health
addons/embedded/includes/embedded/class-wp-mcp-ai-embedded-client.php  ← proc_open streaming + SHA-256 verify
addons/embedded/includes/embedded/class-nvoos-embedded-webllm-enqueue.php ← Multi-modal enqueue
addons/embedded/includes/admin/class-wp-mcp-ai-embedded-model-ajax.php   ← model integrity check on download
addons/embedded/includes/admin/class-wp-mcp-ai-webllm-settings-page.php  ← Unified settings UI
addons/embedded/assets/js/embedded-llm-client.js                  ← Streaming token callback
addons/embedded/assets/js/voice-mode-embedded.js                  ← State machine + PTT + waveform
addons/embedded/assets/css/voice-embedded.css                     ← Voice UX styles + a11y
docker-compose.yml                                                 ← OpenMed service
```

---

## References

- [WordPress 7.0 Connectors API](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)
- [WordPress 7.0 AI Client](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- [WordPress 6.9 Abilities API](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/)
- [WordPress MCP Adapter](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/)
- [WordPress Plugin Registry Pattern](https://www.mindspun.com/blog/wordpress-plugin-development-best-practices/)
- [php-ai-client (WordPress/Automattic)](https://github.com/WordPress/wp-ai-client)
- `healthcare-vitals-openmed-integration-plan-v2.md`
- `LOCAL-VOICE-EMBEDDED-IMPLEMENTATION-PLAN.md`
- `.agents/skills/wp-plugin-architecture/SKILL.md`
- `.agents/skills/wp-rest-api/SKILL.md`
- `.agents/skills/wp-security-audit/SKILL.md`
