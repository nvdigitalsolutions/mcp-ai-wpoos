# WP oOS Architecture Overview

**Last Updated:** December 2025
**Version:** 1.0.0

This document provides a high-level architectural overview of the WP Open Operator System (WP oOS) plugin. For detailed technical documentation, see the [docs/](docs/) directory.

## Table of Contents

- [System Overview](#system-overview)
- [Core Components](#core-components)
- [Data Flow](#data-flow)
- [Key Design Patterns](#key-design-patterns)
- [Extension Points](#extension-points)
- [Security Architecture](#security-architecture)

## System Overview

WP oOS is a WordPress plugin that provides an AI Assistant framework integrating with multiple AI providers (OpenAI, Google Gemini, Ollama) and implementing the Model Context Protocol (MCP) for tool-based AI interactions.

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Frontend Layer                           │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │  Shortcode   │  │   Elementor  │  │  Chat Widget │      │
│  │    [chat]    │  │    Widgets   │  │      UI      │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                     REST API Layer                          │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │ Chat Endpoint│  │ MCP JSON-RPC │  │ SSE Streaming│      │
│  │  /wp-json/   │  │   /mcp-v1    │  │    Events    │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                  Orchestration Layer                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │  Agentic     │  │   Resource   │  │    Token     │      │
│  │  Workflow    │  │   Manager    │  │   Budget     │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                   Provider Layer                            │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │   OpenAI     │  │    Gemini    │  │    Ollama    │      │
│  │    Client    │  │    Client    │  │    Client    │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                     Tool Layer                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │ WordPress    │  │  Third-Party │  │    Custom    │      │
│  │    Tools     │  │     Tools    │  │    Tools     │      │
│  │   (35+)      │  │   (30+)      │  │  (Extensible)│      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
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
- 104+ tool implementations in `includes/tools/class-wp-mcp-ai-tool-*.php`

**Tool Categories:**
1. **WordPress Core** (35 tools) - Posts, pages, media, users, taxonomy
2. **Third-Party Plugins** (30 tools) - JetEngine, WooCommerce, Elementor, etc.
3. **External Services** (10 tools) - Email, SMS, webhooks, AI services
4. **Research & Content** (15 tools) - Web scraping, summarization, analysis
5. **Advanced** (14 tools) - Cron jobs, database queries, code execution

### 3. AI Provider Clients (`includes/`)

**Purpose:** Abstract and normalize AI provider APIs.

**Key Classes:**
- `WP_MCP_AI_Language_Model_Router` - Routes requests to appropriate provider
- `WP_MCP_AI_OpenAI_Client` - OpenAI API integration
- `WP_MCP_AI_Gemini_Client` - Google Gemini API integration
- `WP_MCP_AI_Ollama_Client` - Ollama local AI integration
- `WP_MCP_AI_LM_Studio_Client` - LM Studio local AI integration

**Features:**
- Unified interface across providers
- Automatic model selection and fallback
- Token counting and budget management
- Streaming support (SSE)
- Function calling / tool use

### 4. REST API (`includes/rest/`, `includes/class-wp-mcp-ai-rest.php`)

**Purpose:** Expose AI functionality via REST endpoints.

**Key Endpoints:**
- `POST /wp-json/mcp-ai/v1/chat` - Chat completions with streaming
- `POST /wp-json/mcp-ai/v1/mcp-v1` - MCP JSON-RPC 2.0 endpoint
- `GET /wp-json/mcp-ai/v1/assistants` - List assistants
- `GET /wp-json/mcp-ai/v1/sse` - Server-Sent Events endpoint
- `POST /wp-json/mcp-ai/v1/tools` - Direct tool execution

**Authentication Methods:**
1. WordPress Nonce (same-origin)
2. Assistant Credentials (bearer tokens)
3. Auth0 Tokens (enterprise)
4. Guest Tokens (temporary public access)

### 5. Orchestration Layer (`includes/services/`)

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

See [docs/ORCHESTRATION-LAYER-ARCHITECTURE.md](docs/ORCHESTRATION-LAYER-ARCHITECTURE.md) for detailed analysis.

### 6. Admin Interface (`includes/admin/`)

**Purpose:** WordPress admin UI for configuration and management.

**Key Classes:**
- `WP_MCP_AI_Admin_Settings` - Main settings page (3,500 lines)
- `WP_MCP_AI_Tools_Manager` - Tools configuration UI
- `WP_MCP_AI_Performance_Reporter` - Performance monitoring dashboard
- `WP_MCP_AI_Cron_Manager` - Scheduled tasks management

## Data Flow

### Chat Request Flow

```
1. User sends message via frontend
   ↓
2. JavaScript validates and bundles message
   ↓
3. POST to /wp-json/mcp-ai/v1/chat
   ↓
4. Authentication & capability checks
   ↓
5. Load assistant configuration
   ↓
6. Initialize AI provider client
   ↓
7. Tool selection based on assistant config
   ↓
8. Stream response via SSE
   ↓
9. Agentic loop: tool calls → execution → response
   ↓
10. Return final response to client
   ↓
11. (Optional) Save transcript to database/CCT
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

See [docs/DYNAMIC-CONFIGURATION-FILTERS.md](docs/DYNAMIC-CONFIGURATION-FILTERS.md) for complete list.

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

See [SECURITY.md](SECURITY.md) for complete security documentation.

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

See [docs/PERFORMANCE-OPTIMIZATION.md](docs/PERFORMANCE-OPTIMIZATION.md) for details.

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

See [CONTRIBUTING.md](CONTRIBUTING.md) for testing guidelines.

## Directory Structure

```
mcp-ai-wpoos/
├── includes/                 # Core plugin code (213 PHP files)
│   ├── admin/               # Admin UI classes
│   ├── assistants/          # Assistant management
│   ├── tools/               # Tool implementations (104 tools)
│   ├── elementor/           # Elementor widgets
│   ├── integrations/        # Third-party integrations
│   ├── repositories/        # Data access layer
│   ├── services/            # Business logic services
│   ├── rest/                # REST API controllers
│   └── class-*.php          # Core classes
├── assets/                  # Frontend assets
│   ├── js/                  # JavaScript files
│   └── css/                 # Stylesheets
├── tests/                   # Test suite (60+ test files)
├── docs/                    # Documentation (69 files)
├── bin/                     # Development scripts
├── languages/               # Translation files
└── vendor/                  # Composer dependencies
```

## Further Reading

- **[QUICK_REFERENCE.md](docs/QUICK_REFERENCE.md)** - Fast reference guide
- **[DOCUMENTATION_INDEX.md](docs/DOCUMENTATION_INDEX.md)** - Complete documentation map
- **[CODE-REVIEW-MASTER.md](docs/CODE-REVIEW-MASTER.md)** - Code quality assessment
- **[ORCHESTRATION-LAYER-ARCHITECTURE.md](docs/ORCHESTRATION-LAYER-ARCHITECTURE.md)** - Detailed orchestration layer analysis
- **[tool-reference.md](docs/tool-reference.md)** - Complete tool catalog
- **[rest-api.md](docs/rest-api.md)** - REST API reference

## Version History

- **1.0.0** (2025-11) - Initial release
  - 104+ tools
  - OpenAI, Gemini, Ollama support
  - MCP JSON-RPC 2.0 implementation
  - SSE streaming
  - Comprehensive admin UI
  - 69 documentation files
  - 60+ test files

---

**Last Updated:** December 2025
**Maintainer:** NV Digital Solutions
**License:** GPLv3 or later
