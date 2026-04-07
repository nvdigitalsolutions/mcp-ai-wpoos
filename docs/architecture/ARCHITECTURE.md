# NV oOS Architecture Overview

**Last Updated:** April 2026  
**Version:** 1.1.6

This document provides a high-level architectural overview of the Open Operator System (NV oOS) plugin. For detailed technical documentation, see the main [docs](../) directory. For the end-to-end request flow trace, see [REQUEST-FLOW-WALKTHROUGH.md](REQUEST-FLOW-WALKTHROUGH.md).

## Table of Contents

- [System Overview](#system-overview)
- [Core Components](#core-components)
- [Data Flow](#data-flow)
- [Key Design Patterns](#key-design-patterns)
- [Extension Points](#extension-points)
- [Security Architecture](#security-architecture)

## System Overview

NV oOS is a WordPress plugin that provides an AI Assistant framework integrating with 9 AI providers (OpenAI, Google Gemini, Anthropic Claude, NVIDIA NIM, Ollama, LM Studio, Hugging Face, Cloudflare Workers AI, and Embedded/GGUF) and implementing the Model Context Protocol (MCP) for tool-based AI interactions.

### High-Level Architecture

```
┌───────────────────────────────────────────────────────────────────────────┐
│                          Frontend Layer                                   │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐   │
│  │Shortcode │  │Elementor │  │Gutenberg │  │  Chat    │  │ Pro TMA  │   │
│  │ [chat]   │  │ Widgets  │  │  Blocks  │  │ Widget   │  │Templates │   │
│  │          │  │  (24)    │  │   (6+18) │  │   UI     │  │          │   │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘  └──────────┘   │
└───────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌───────────────────────────────────────────────────────────────────────────┐
│                          REST API Layer                                   │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌─────────────┐  │
│  │ Chat Endpoint│  │ MCP JSON-RPC │  │ SSE Streaming│  │ Tool / Slash│  │
│  │/chat-client  │  │  /mcp-v1     │  │    Events    │  │  Commands   │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  └─────────────┘  │
│  5 base controllers + 17 Pro controllers = 22 REST controllers           │
└───────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌───────────────────────────────────────────────────────────────────────────┐
│                       Orchestration Layer                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌─────────────┐  │
│  │  Agentic     │  │   Resource   │  │    Token     │  │Multi-Agent  │  │
│  │  Workflow    │  │   Manager    │  │   Budget     │  │Orchestrator │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  └─────────────┘  │
└───────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌───────────────────────────────────────────────────────────────────────────┐
│                         Provider Layer (9 providers)                      │
│  ┌────────┐ ┌────────┐ ┌─────────┐ ┌───────┐ ┌────────┐ ┌───────────┐  │
│  │ OpenAI │ │ Gemini │ │Anthropic│ │NVIDIA │ │ Ollama │ │ LM Studio │  │
│  └────────┘ └────────┘ └─────────┘ └───────┘ └────────┘ └───────────┘  │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐                         │
│  │Hugging Face│  │ Cloudflare │  │  Embedded  │                         │
│  └────────────┘  └────────────┘  └────────────┘                         │
└───────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌───────────────────────────────────────────────────────────────────────────┐
│                           Tool Layer                                     │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐                    │
│  │ Base Tools   │  │  Pro Tools   │  │    Custom    │                    │
│  │    (226)     │  │    (127)     │  │  (Extensible)│                    │
│  └──────────────┘  └──────────────┘  └──────────────┘                    │
│  353 total tool class files + third-party via wp_mcp_ai_register_tools   │
└───────────────────────────────────────────────────────────────────────────┘
```

## Core Components

### 1. Assistant Management (`includes/assistants/`)

**Purpose:** Manage AI assistant configurations, tools, and settings.

**Key Classes:**
- `WP_MCP_AI_Assistant_CPT` - Custom Post Type for assistants
- `WP_MCP_AI_JetEngine_Assistants_CCT` - Optional CCT sync with JetEngine
- `WP_MCP_AI_Assistant_Repository` - Data access layer

**Storage Options:**
- **CPT (Custom Post Type):** Default storage in `wp_posts` table
- **CCT (Custom Content Type):** Optional JetEngine CCT for enhanced querying

### 2. Tool Registry (`includes/tools/`)

**Purpose:** Centralized registration and management of AI tools.

**Key Classes:**
- `WP_MCP_AI_Tool_Registry` - Singleton registry for all tools
- `WP_MCP_AI_Tool_Interface` - Interface all tools must implement
- `WP_MCP_AI_Tool_Capability_Flags_Interface` - Optional interface for async/background tools
- 353 tool class files (226 base + 127 pro)

**Tool Categories:**
1. **WordPress Core** — Posts, pages, media, users, taxonomy, settings, cron, cache
2. **Third-Party Plugins** — JetEngine, WooCommerce, Elementor, Rank Math, WPCode
3. **External Services** — Email (Mailjet, Brevo, Mailgun), SMS, social media, webhooks
4. **Research & Content** — Web search, Crawl4AI, summarization, analysis
5. **Media Generation** — Image (OpenAI, Gemini, Cloudflare), speech, music, video, vector
6. **Commerce & Finance** — WooCommerce, Shopify, QuickBooks Online/Desktop
7. **Chat Channels** (Pro) — 47 tools across 11 platforms (Telegram, WhatsApp, Slack, Discord, Teams, Messenger, Apple Messages, Google Chat, Twitter/X, Outlook, iCloud)
8. **Healthcare** (Pro) — DICOM imaging, vitals, wellness documents
9. **Vehicle Estimation** (Pro) — VIN decode, repair estimates, cleaning estimates
10. **Project Management** (Pro) — Task tracking, document generation, regulatory compliance

**Tool Counting Note:** Some tools have "-validated" variants using Symfony Validator for enhanced input validation. These variants are counted as the same tool (e.g., `create-post` and `create-post-validated` = 1 tool).

### 3. Process Service (`includes/services/class-wp-mcp-ai-process-service.php`)

**Purpose:** Modern external command execution framework using Symfony Process component.

**Added:** December 2025 (Symfony Phase 2B)

**Key Features:**
- Replaces direct `exec()` calls in Pro addon tools
- WordPress-friendly wrappers with WP_Error integration
- Configurable timeouts and graceful error handling
- Secure argument escaping and process control

**Migrated Components:**
- 6 Pro addon tools (FFmpeg, WP-CLI, Python rembg, Jukebox)
- 2 supporting services (Jukebox Service, Video Frame Extractor Service)

**Usage Pattern:**
```php
$process_service = WP_MCP_AI_Process_Service::get_instance();
$result = $process_service->run( $command, array(
    'timeout' => 120,
    'cwd' => '/path/to/working/dir',
) );

if ( is_wp_error( $result ) ) {
    // Handle error
} else {
    $output = $result['output'];
}
```

### 4. AI Provider Clients (`includes/`)

**Purpose:** Abstract and normalize AI provider APIs.

**Key Classes:**
- `WP_MCP_AI_Language_Model_Router` - Routes requests to appropriate provider with priority fallback
- `WP_MCP_AI_OpenAI_Client` - OpenAI API integration (GPT-4.1, GPT-5.2, o1, image/speech/transcription)
- `WP_MCP_AI_Enhanced_OpenAI_Client` - Extended OpenAI client with additional capabilities
- `WP_MCP_AI_Gemini_Client` - Google Gemini API integration (embeddings, video, music)
- `WP_MCP_AI_Anthropic_Client` - Anthropic Claude API integration
- `WP_MCP_AI_Nvidia_Client` - NVIDIA NIM API integration
- `WP_MCP_AI_Ollama_Client` - Ollama local AI integration
- `WP_MCP_AI_LM_Studio_Client` - LM Studio local AI integration
- `WP_MCP_AI_Huggingface_Client` - Hugging Face Inference API integration
- `WP_MCP_AI_Cloudflare_Client` - Cloudflare Workers AI integration
- `WP_MCP_AI_Embedded_Client` - Server-side GGUF inference via llama.cpp (Pro-only)

**Provider Infrastructure** (`includes/infrastructure/providers/`):
- `WP_MCP_AI_OpenAI_Provider_Client` - OpenAI provider abstraction
- `WP_MCP_AI_Gemini_Provider_Client` - Gemini provider abstraction
- `WP_MCP_AI_Anthropic_Provider_Client` - Anthropic provider abstraction
- `WP_MCP_AI_Ollama_Provider_Client` - Ollama provider abstraction

**Features:**
- Unified interface across all 9 providers
- Automatic provider fallback via configurable priority list
- Token counting and budget management per model
- SSE streaming support
- Function calling / tool use (OpenAI-compatible schema)
- Model-specific context window and output limit awareness

### 5. REST API (`includes/rest/`, `includes/class-wp-mcp-ai-rest.php`)

**Purpose:** Expose AI functionality via REST endpoints.

**Base Controllers** (15 files in `includes/rest/`):
- `WP_MCP_AI_REST_Chat_Controller` - Chat completions with streaming
- `WP_MCP_AI_REST_MCP_Controller` - MCP JSON-RPC 2.0 endpoint
- `WP_MCP_AI_REST_Tools_Controller` - Direct tool execution
- `WP_MCP_AI_REST_Teams_Controller` - Multi-agent team management
- `WP_MCP_AI_REST_Slash_Command_Controller` - Slash command execution
- `WP_MCP_AI_REST_Analytics_Manager` - Usage analytics
- `WP_MCP_AI_REST_Cost_Manager` - Cost tracking
- `WP_MCP_AI_REST_Token_Manager` - Token/credential management
- Plus: authenticator, validator, SSE handler, controller base, security REST controllers

**Pro Controllers** (17 files in `addons/pro/includes/rest/`):
- Webhook controllers for 9 messaging platforms (Telegram, WhatsApp, Slack, Discord, Teams, Messenger, Google Chat, Apple Messages, Twitter/X)
- Telegram Mini App controller (TMA)
- Chat channels REST controller
- WebChat signaling REST controller
- Outlook webhook controller
- iCloud webhook controller
- Skill manager REST controller
- ECA (External Capability API) REST controller

**Key Endpoints:**
- `POST /wp-json/mcp-ai/v1/chat` - MCP remote client chat (5 agentic iterations)
- `POST /wp-json/mcp-ai/v1/chat-client` - Browser UI chat (15 agentic iterations)
- `POST /wp-json/mcp-ai/v1/mcp-v1` - MCP JSON-RPC 2.0 endpoint
- `GET /wp-json/mcp-ai/v1/assistants` - List assistants (with SSE negotiation)
- `GET /wp-json/mcp-ai/v1/sse` - Server-Sent Events endpoint
- `POST /wp-json/mcp-ai/v1/tools` - Direct tool execution

**Authentication Methods:**
1. WordPress Nonce (`X-WP-Nonce` header) — same-origin browser requests
2. Assistant Credentials (`Authorization: Bearer cred_xxxxx.SECRET`) — plugin-issued tokens
3. Mesh API Keys — federation network requests
4. Auth0 Tokens — enterprise SSO integration
5. Guest Tokens (`X-WP-MCP-AI-Guest` header) — temporary public access

### 6. Orchestration Layer (`includes/services/`)

**Purpose:** Manage AI workflow execution, resource allocation, and budget enforcement.

**Key Classes:**
- `WP_MCP_AI_Agentic_Workflow_Optimizer` - Optimize multi-step workflows
- `WP_MCP_AI_Resource_Manager` - Monitor and allocate system resources
- `WP_MCP_AI_Token_Budget_Manager` - Enforce token limits
- `WP_MCP_AI_Orchestration_Health_Service` - System health monitoring

**Novel Features:**
- Predictive budget allocation
- Dynamic resource adjustment based on real-time metrics
- Registry-state-based tool scheduling
- Capability-based access control enforcement

See [orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md](orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md) for detailed analysis.

### 6. Admin Interface (`includes/admin/`)

**Purpose:** WordPress admin UI for configuration and management.

**Key Classes:**
- `WP_MCP_AI_Admin_Settings` - Main settings page (3,500 lines)
- `WP_MCP_AI_Tools_Manager` - Tools configuration UI
- `WP_MCP_AI_Performance_Reporter` - Performance monitoring dashboard
- `WP_MCP_AI_Cron_Manager` - Scheduled tasks management

## Data Flow

### Chat Request Flow

For a detailed, line-by-line trace of the complete request flow, see [REQUEST-FLOW-WALKTHROUGH.md](REQUEST-FLOW-WALKTHROUGH.md).

```
Browser (assets/js/chat.js → sendChat())
  │
  ├─ POST /wp-json/mcp-ai/v1/chat-client
  │   Accept: text/event-stream
  │
  ▼
REST_Chat_Controller::handle_chat_client_request()
  │  (sets max_iterations=15, delegates to main controller)
  ▼
WP_MCP_AI_REST::handle_chat_request()
  │  ├─ resolve assistant config (team/profession/regular)
  │  ├─ sanitize messages & extract attachments
  │  ├─ enforce rate/token limits
  │  ├─ build tools payload from Tool_Registry
  │  └─ streaming? → handle_chat_request_with_streaming()
  │
  ▼
SSE_Handler::send_sse_headers()  ←── starts SSE stream
  │
  ▼
Language_Model_Router::create_chat_completion()
  │  └─ route_to_provider() → OpenAI/Gemini/Anthropic/...
  │
  ▼
┌─── AGENTIC LOOP (up to 15 iterations) ──────────┐
│                                                   │
│  extract_tool_calls_from_response()               │
│    └─ no tools? → BREAK                          │
│                                                   │
│  for each tool_call:                              │
│    SSE: tool_start                                │
│    execute_tool_call_internal()                    │
│      └─ Tool_Registry::get_tool()                │
│      └─ $tool->execute($args, $context)          │
│    SSE: tool_result                               │
│                                                   │
│  validate token budget (may switch model)         │
│  Language_Model_Router::create_chat_completion()  │
│    (with tool results appended to messages)       │
│                                                   │
└──────────────────────────────────────────────────┘
  │
  ▼
SSE: response (final text + usage metadata)
SSE: [DONE]
  │
  ▼
Browser: render message, save to localStorage/CCT
```

### Tool Execution Flow

```
1. AI model requests tool execution
   ↓
2. Tool registry looks up tool by slug
   ↓
3. Capability check for tool execution
   ↓
4. Validate tool arguments against schema
   ↓
5. Execute tool's execute() method
   ↓
6. Tool performs operation (query DB, call API, etc.)
   ↓
7. Sanitize and format tool response
   ↓
8. Return response to AI model
   ↓
9. Model processes and continues or returns final answer
```

## Key Design Patterns

### 1. Repository Pattern

Abstracts data access for assistants, settings, and other entities.

**Example:**
```php
// Interface
interface WP_MCP_AI_Repository_Interface {
    public function find( $id );
    public function save( $entity );
    public function delete( $id );
}

// Implementation
class WP_MCP_AI_Assistant_Repository implements WP_MCP_AI_Repository_Interface {
    public function find( $id ) {
        return get_post( $id );
    }
}
```

### 2. Dependency Injection

Service container manages dependencies and promotes testability.

**Example:**
```php
$container = WP_MCP_AI_Container::get_instance();
$container->register( 'settings_repository', function() {
    return new WP_MCP_AI_Settings_Repository();
});
```

### 3. Registry Pattern

Tool registry provides centralized tool management.

**Example:**
```php
$registry = WP_MCP_AI_Tool_Registry::get_instance();
$registry->register_tool( 'WP_MCP_AI_Tool_Create_Post' );
$tool = $registry->get_tool( 'create_post' );
```

### 4. Strategy Pattern

Provider abstraction allows switching AI providers without code changes.

**Example:**
```php
$router = new WP_MCP_AI_Language_Model_Router();
$client = $router->get_client( $model_config );
// Returns OpenAI, Gemini, or Ollama client based on model
```

### 5. Observer Pattern

WordPress hooks/filters for extensibility.

**Example:**
```php
// Plugin provides hooks
do_action( 'wp_mcp_ai_register_tools', $registry );
apply_filters( 'wp_mcp_ai_tool_response', $response, $tool );

// Custom code extends
add_action( 'wp_mcp_ai_register_tools', function( $registry ) {
    $registry->register_tool( 'My_Custom_Tool' );
});
```

## Extension Points

### 1. Custom Tools

Add new AI tools via the registry:

```php
add_action( 'wp_mcp_ai_register_tools', function( $registry ) {
    require_once 'my-custom-tool.php';
    $registry->register_tool( 'My_Custom_Tool' );
});
```

### 2. Custom Providers

Extend provider support:

```php
add_filter( 'wp_mcp_ai_get_client', function( $client, $model_config ) {
    if ( 'my-provider' === $model_config['provider'] ) {
        return new My_Custom_AI_Client( $model_config );
    }
    return $client;
}, 10, 2 );
```

### 3. Custom Authentication

Add authentication methods:

```php
add_filter( 'wp_mcp_ai_authenticate_request', function( $user_id, $request ) {
    // Custom authentication logic
    return $user_id;
}, 10, 2 );
```

### 4. Hooks & Filters

**Tool Execution:**
- `wp_mcp_ai_before_tool_execution` - Before tool runs
- `wp_mcp_ai_after_tool_execution` - After tool runs
- `wp_mcp_ai_tool_response` - Filter tool response

**Chat:**
- `wp_mcp_ai_before_chat_request` - Before processing chat
- `wp_mcp_ai_after_chat_response` - After chat completes
- `wp_mcp_ai_chat_capability` - Filter required capability

**Configuration:**
- `wp_mcp_ai_settings` - Filter all settings
- `wp_mcp_ai_model_config` - Filter model configuration
- `wp_mcp_ai_tool_list` - Filter available tools

See [../guides/developer/architecture/DYNAMIC-CONFIGURATION-FILTERS.md](../guides/developer/architecture/DYNAMIC-CONFIGURATION-FILTERS.md) for complete list.

## Security Architecture

### Multi-Layer Security

**1. Input Validation & Sanitization:**
```php
$message = sanitize_text_field( $request['message'] );
$assistant_id = absint( $request['assistant_id'] );
$email = sanitize_email( $request['email'] );
```

**2. Capability Checks:**
```php
if ( ! current_user_can( 'edit_posts' ) ) {
    return new WP_Error( 'insufficient_permissions' );
}
```

**3. Nonce Verification:**
```php
if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
    return new WP_Error( 'invalid_nonce' );
}
```

**4. Output Escaping:**
```php
echo esc_html( $assistant_name );
echo '<a href="' . esc_url( $link ) . '">';
```

**5. Rate Limiting:**
- Per-user limits
- Per-model limits
- Global API limits
- Configurable via admin UI

**6. Security Monitoring:**
- `WP_MCP_AI_Nefarious_Usage_Monitor` - Detect suspicious patterns
- `WP_MCP_AI_Root_Security_Key` - Emergency shutdown
- Comprehensive audit logging

See [../../SECURITY.md](../../SECURITY.md) for complete security documentation.

## Performance Considerations

### Caching Strategy

**1. Transient Cache:**
```php
WP_MCP_AI_Cache_Helper::get( $key );
WP_MCP_AI_Cache_Helper::set( $key, $value, $expiration );
```

**2. Object Cache:**
```php
wp_cache_get( $key, 'wp_mcp_ai' );
wp_cache_set( $key, $value, 'wp_mcp_ai' );
```

**3. Database Query Optimization:**
- Prepared statements
- Index optimization
- Query result caching

### Optimization Features

- Message bundling (reduce API calls)
- Token budget management (prevent overspending)
- Resource monitoring (prevent memory exhaustion)
- Lazy loading (load tools on demand)
- SSE streaming (reduce perceived latency)

See [../features/performance/PERFORMANCE-OPTIMIZATION.md](../features/performance/PERFORMANCE-OPTIMIZATION.md) for details.

## Testing Architecture

### Test Suite Structure

```
tests/
├── bootstrap.php              # PHPUnit bootstrap
├── test-*.php                # Unit tests (60+ files)
├── rest/                     # REST API tests
├── rest-api/                 # REST integration tests
├── memory/                   # Memory handling tests
├── security/                 # Security tests
├── performance/              # Performance tests
└── js/                       # JavaScript tests
```

### Testing Tools

- **PHPUnit** - PHP unit and integration tests
- **Jest** - JavaScript testing
- **WordPress Test Suite** - WordPress-specific testing environment
- **Code Coverage** - Xdebug coverage reporting

See [../../CONTRIBUTING.md](../../CONTRIBUTING.md) for testing guidelines.

## Directory Structure

```
mcp-ai-wpoos/
├── includes/                 # Core plugin code
│   ├── admin/               # Admin UI classes (102 files)
│   ├── assistants/          # Assistant CPT/CCT management (10 files)
│   ├── tools/               # Base tool implementations (226 tool classes)
│   ├── elementor/           # Elementor widgets (24 widgets + 1 trait)
│   ├── integrations/        # Third-party integrations (23 files)
│   ├── services/            # Business logic services (53 files)
│   ├── rest/                # REST API controllers (15 files)
│   ├── blocks/              # Gutenberg blocks (6 blocks + 2 registrars)
│   ├── cli/                 # WP-CLI commands (8 command classes)
│   ├── slash-commands/      # Slash commands (7 commands + core framework)
│   ├── validators/          # Input/output validators (31 files)
│   ├── infrastructure/      # Provider infrastructure (10 files)
│   ├── knowledge-base/      # Knowledge base data (503 files)
│   ├── bundled-skills/      # Pre-bundled AI skills (23 files)
│   ├── professions/         # Professional role definitions (17 files)
│   └── class-*.php          # Core classes (router, shortcode, registry, etc.)
├── addons/                  # Plugin addons
│   ├── pro/                 # Pro addon (PHP 8.1+, 127 tools, 17 REST controllers)
│   └── canvas/              # Canvas native binaries (PDF OCR via Tesseract)
├── assets/                  # Frontend assets
│   ├── js/                  # JavaScript files (201 files)
│   └── css/                 # Stylesheets (111 files)
├── packages/                # 9 standalone NPM packages (@nvdigitalsolutions scope)
├── core/                    # Core framework components
├── shared/                  # Shared code between components
├── src/                     # React/webpack source (TMA, workflow builder)
├── tests/                   # Test suite (861+ test files)
├── docs/                    # Documentation (570+ files)
├── bin/                     # Build and development scripts (69 scripts)
├── languages/               # Translation files
├── examples/                # Example files
├── patches/                 # Dependency patches
└── vendor/                  # Composer dependencies
```

## Further Reading

- **[REQUEST-FLOW-WALKTHROUGH.md](REQUEST-FLOW-WALKTHROUGH.md)** - Detailed chat request → LLM → tool → SSE response trace
- **[QUICK_REFERENCE.md](../QUICK_REFERENCE.md)** - Fast reference guide
- **[DOCUMENTATION_INDEX.md](../DOCUMENTATION_INDEX.md)** - Complete documentation map
- **[CODE-REVIEW-MASTER.md](../guides/developer/best-practices/CODE-REVIEW-MASTER.md)** - Code quality assessment
- **[ORCHESTRATION-LAYER-ARCHITECTURE.md](orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md)** - Detailed orchestration layer analysis
- **[tool-reference.md](../reference/tools/tool-reference.md)** - Complete tool catalog
- **[rest-api.md](../reference/api/rest-api.md)** - REST API reference
- **[CURRENT-STATE-AGENTIC-WORKFLOW.md](core/CURRENT-STATE-AGENTIC-WORKFLOW.md)** - Agentic workflow deep dive

## Version History

- **1.1.6** (2026-04) - Vehicle estimation, Shopify auto-resolve, QuickBooks Desktop, NVIDIA NIM onboarding
  - 353 tool class files (226 base + 127 pro)
  - 9 AI providers including NVIDIA NIM and Embedded/GGUF
  - 22 REST controllers (5 base + 17 pro)
  - 24 Elementor widgets, 24 Gutenberg blocks
  - 7 slash commands + 21 Pro toolkit commands
  - 20 shortcodes, 9 NPM packages
  - 8 WP-CLI command classes
  - 570+ documentation files
  - 861+ test files
- **1.0.0** (2025-11) - Initial release
  - 104+ tools
  - OpenAI, Gemini, Ollama support
  - MCP JSON-RPC 2.0 implementation
  - SSE streaming
  - Comprehensive admin UI
  - 69 documentation files
  - 60+ test files

---

**Last Updated:** April 2026
**Maintainer:** NV Digital Solutions
**License:** GPLv3 or later
