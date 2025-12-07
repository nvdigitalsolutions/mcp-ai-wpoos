# WP Open Operator System (WP oOS) - Architecture Guide for Copilot

**Version:** 1.0.0  
**Last Updated:** 2025-11-11  
**Purpose:** Comprehensive code framework documentation to help Copilot understand the plugin architecture

---

## Table of Contents

1. [Overview](#overview)
2. [Plugin Entry Point](#plugin-entry-point)
3. [Core Architecture Patterns](#core-architecture-patterns)
4. [Directory Structure](#directory-structure)
5. [Class Hierarchy](#class-hierarchy)
6. [Namespacing and Naming Conventions](#namespacing-and-naming-conventions)
7. [Initialization Flow](#initialization-flow)
8. [Service Layer](#service-layer)
9. [Repository Layer](#repository-layer)
10. [REST API Architecture](#rest-api-architecture)
11. [AJAX Architecture](#ajax-architecture)
12. [Tool System](#tool-system)
13. [Admin Dashboard Architecture](#admin-dashboard-architecture)
14. [AI Client Abstraction](#ai-client-abstraction)
15. [Authentication System](#authentication-system)
16. [Error Handling and Logging](#error-handling-and-logging)
17. [Hooks and Filters](#hooks-and-filters)
18. [Constants and Configuration](#constants-and-configuration)
19. [Security Architecture](#security-architecture)
20. [Data Flow Diagrams](#data-flow-diagrams)
21. [Integration Points](#integration-points)

---

## Overview

WP Open Operator System (WP oOS) is a sophisticated WordPress plugin that provides an AI Assistant framework integrating with multiple AI providers (OpenAI, Gemini, Ollama, Anthropic) and implementing the Model Context Protocol (MCP).

### Key Architectural Principles

1. **Service-Repository Pattern**: Business logic in services, data access in repositories
2. **Dependency Injection**: Using WP_MCP_AI_Container for managing dependencies
3. **Interface-Driven Design**: Tools implement well-defined interfaces
4. **Modular Admin System**: Settings organized into pluggable sections
5. **REST API First**: API-driven architecture with MCP compliance
6. **Separation of Concerns**: Clear boundaries between layers
7. **Singleton Pattern**: For registries and core managers
8. **Factory Pattern**: For AI client creation via router

### Technology Stack

- **Language**: PHP 7.4+ (supports up to PHP 8.3)
- **Framework**: WordPress 6.0+
- **Architecture**: MVC-inspired with Service/Repository layers
- **API Protocol**: REST + Server-Sent Events (SSE)
- **Frontend**: JavaScript (ES6), jQuery compatible
- **Styling**: CSS3
- **Testing**: PHPUnit 9.6
- **Code Standards**: WordPress Coding Standards (WPCS)
- **Dependencies**: Composer (tiktoken-php, Symfony HTTP client)

---

## Plugin Entry Point

### Main File: `mcp-ai-wpoos.php`

**Location**: `/mcp-ai-wpoos.php`

**Responsibilities**:
- PHP version compatibility check (requires 7.4+)
- Define plugin constants
- Load Composer autoloader
- Define helper functions
- Require all core class files
- Initialize components in correct order
- Register WordPress hooks

**Key Constants Defined**:
```php
WP_MCP_AI_VERSION     // Plugin version: '1.0.0'
WP_MCP_AI_PATH        // Absolute filesystem path
WP_MCP_AI_URL         // Plugin URL
WP_MCP_AI_BASE_VERSION // Boolean flag for base vs full version
```

**PHP Version Check**:
- Checks for PHP 7.4+ before loading any classes
- Displays admin notice on incompatible versions
- Auto-deactivates on incompatible PHP versions
- Prevents parse errors from modern PHP syntax

**Loading Sequence** (in order):
1. PHP version check
2. Constants definition
3. Composer autoloader
4. Helper functions
5. Admin settings components
6. HTTP and cache helpers
7. Core managers and loggers
8. Security components
9. HTTP client and proxies
10. Credentials and rate limiting
11. AI client abstractions
12. Tool system
13. REST API
14. Shortcodes
15. Dependency injection container
16. Repository layer
17. Service layer
18. Federation system
19. Third-party integrations (if not base version)
20. Admin-only components
21. WP-CLI commands
22. Component initialization

---

## Core Architecture Patterns

### 1. Dependency Injection Container

**Class**: `WP_MCP_AI_Container`  
**File**: `includes/class-wp-mcp-ai-container.php`

**Purpose**: Centralized dependency management and object lifecycle control

**Pattern**: Service Locator + Factory

**Key Methods**:
- `get_instance()` - Singleton access
- `get( $service_id )` - Retrieve service by ID
- `register( $service_id, $factory )` - Register service factory
- `has( $service_id )` - Check if service exists

**Registered Services**:
- `tool_registry` - Tool registry singleton
- `router` - Language model router
- `rate_limiter` - Rate limit manager
- `token_budget_manager` - Token budget manager
- `service.chat` - Chat service
- `service.assistant` - Assistant service
- `service.tool` - Tool service
- `service.file` - File service
- `repository.assistant` - Assistant repository
- `repository.credential` - Credential repository
- `repository.settings` - Settings repository

**Access Pattern**:
```php
$container = WP_MCP_AI_Container::get_instance();
$chat_service = $container->get( 'service.chat' );
```

### 2. Service Layer Pattern

**Purpose**: Encapsulate business logic and coordinate between repositories and controllers

**Services**:

#### Chat Service
- **Class**: `WP_MCP_AI_Chat_Service`
- **File**: `includes/services/class-wp-mcp-ai-chat-service.php`
- **Responsibilities**: 
  - Process chat messages
  - Manage conversation context
  - Coordinate with AI providers
  - Handle tool execution during chat
  - Manage message attachments
  - Apply rate limiting

#### Assistant Service
- **Class**: `WP_MCP_AI_Assistant_Service`
- **File**: `includes/services/class-wp-mcp-ai-assistant-service.php`
- **Responsibilities**:
  - CRUD operations for assistants
  - Assistant validation
  - Permission checks
  - Retrieve assistant configuration
  - Manage assistant credentials

#### Tool Service
- **Class**: `WP_MCP_AI_Tool_Service`
- **File**: `includes/services/class-wp-mcp-ai-tool-service.php`
- **Responsibilities**:
  - Tool discovery and listing
  - Tool execution orchestration
  - Capability checking
  - Tool parameter validation
  - Tool response formatting

#### File Service
- **Class**: `WP_MCP_AI_File_Service`
- **File**: `includes/services/class-wp-mcp-ai-file-service.php`
- **Responsibilities**:
  - File upload handling
  - File validation (type, size)
  - File attachment to messages
  - File cleanup
  - MIME type checking

### 3. Repository Layer Pattern

**Purpose**: Abstract data access and persistence logic

**Repositories**:

#### Assistant Repository
- **Class**: `WP_MCP_AI_Assistant_Repository`
- **File**: `includes/repositories/class-wp-mcp-ai-assistant-repository.php`
- **Data Source**: WordPress Custom Post Type (`mcp_ai_assistant`)
- **Methods**:
  - `find( $id )` - Get assistant by ID
  - `find_all()` - List all assistants
  - `save( $data )` - Create/update assistant
  - `delete( $id )` - Delete assistant
  - `get_meta( $id, $key )` - Get assistant metadata

#### Credential Repository
- **Class**: `WP_MCP_AI_Credential_Repository`
- **File**: `includes/repositories/class-wp-mcp-ai-credential-repository.php`
- **Data Source**: Post meta + WP_MCP_AI_Credentials class
- **Methods**:
  - `find_by_assistant( $assistant_id )` - Get credentials for assistant
  - `generate( $assistant_id )` - Generate new credential
  - `verify( $token )` - Verify credential token
  - `revoke( $credential_id )` - Revoke credential

#### Settings Repository
- **Class**: `WP_MCP_AI_Settings_Repository`
- **File**: `includes/repositories/class-wp-mcp-ai-settings-repository.php`
- **Data Source**: WordPress options API
- **Methods**:
  - `get( $key, $default )` - Get setting value
  - `set( $key, $value )` - Save setting value
  - `delete( $key )` - Delete setting
  - `get_all()` - Get all plugin settings

### 4. Singleton Pattern

**Used For**: Registries, managers, and core components that should have single instance

**Implementations**:

```php
// Standard singleton structure used throughout
class WP_MCP_AI_Tool_Registry {
    protected static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    protected function __construct() {}
    protected function __clone() {}
    public function __wakeup() {}
}
```

**Singleton Classes**:
- `WP_MCP_AI_Tool_Registry`
- `WP_MCP_AI_Container`
- `WP_MCP_AI_Settings_Registry`
- `WP_MCP_AI_Logger`
- `WP_MCP_AI_Rate_Limit_Manager`
- `WP_MCP_AI_Token_Budget_Manager`

### 5. Factory Pattern

**Language Model Router** uses factory pattern to instantiate appropriate AI client

**Class**: `WP_MCP_AI_Language_Model_Router`  
**File**: `includes/class-wp-mcp-ai-language-model-router.php`

**Pattern**:
```php
public function get_client( $provider, $settings ) {
    switch ( $provider ) {
        case 'openai':
            return new WP_MCP_AI_OpenAI_Client( $settings );
        case 'gemini':
            return new WP_MCP_AI_Gemini_Client( $settings );
        case 'ollama':
            return new WP_MCP_AI_Ollama_Client( $settings );
        // ... etc
    }
}
```

### 6. Strategy Pattern

**Tool Execution** uses strategy pattern where each tool is a strategy

**Interface**: `WP_MCP_AI_Tool_Interface`  
**Implementations**: 73+ tool classes

Each tool implements the same interface but provides different execution strategies.

---

## Directory Structure

```
mcp-ai-wpoos/
├── mcp-ai-wpoos.php                 # Main plugin file (entry point)
├── includes/                      # All PHP includes
│   ├── admin/                    # Admin dashboard components
│   │   ├── sections/            # Modular settings sections (14 sections)
│   │   │   ├── abstract-wp-mcp-ai-settings-section.php
│   │   │   ├── class-wp-mcp-ai-section-overview.php
│   │   │   ├── class-wp-mcp-ai-section-general.php
│   │   │   ├── class-wp-mcp-ai-section-providers.php
│   │   │   ├── class-wp-mcp-ai-section-authentication.php
│   │   │   ├── class-wp-mcp-ai-section-tools.php
│   │   │   ├── class-wp-mcp-ai-section-orchestration.php
│   │   │   ├── class-wp-mcp-ai-section-integrations.php
│   │   │   ├── class-wp-mcp-ai-section-jetengine.php
│   │   │   ├── class-wp-mcp-ai-section-woocommerce.php
│   │   │   ├── class-wp-mcp-ai-section-elementor.php
│   │   │   ├── class-wp-mcp-ai-section-security.php
│   │   │   ├── class-wp-mcp-ai-section-performance.php
│   │   │   ├── class-wp-mcp-ai-section-token-manager.php
│   │   │   ├── class-wp-mcp-ai-section-custom-filters.php
│   │   │   └── class-wp-mcp-ai-section-advanced.php
│   │   ├── class-wp-mcp-ai-settings-dashboard.php    # Main dashboard controller
│   │   ├── class-wp-mcp-ai-settings-registry.php    # Section registry
│   │   ├── class-wp-mcp-ai-admin-settings-renderer.php  # HTML renderer
│   │   ├── class-wp-mcp-ai-settings-validator.php   # Input validation
│   │   ├── class-wp-mcp-ai-admin-ajax-handlers.php  # AJAX endpoints
│   │   ├── class-wp-mcp-ai-orchestration-renderer.php  # Orchestration UI
│   │   ├── class-wp-mcp-ai-custom-filters-applicator.php  # Filter manager
│   │   ├── class-wp-mcp-ai-dashboard-diagnostic.php  # Diagnostic page
│   │   ├── class-wp-mcp-ai-mcp-server-diagnostic.php  # MCP testing
│   │   ├── class-wp-mcp-ai-security-monitor-admin.php  # Security UI
│   │   ├── class-wp-mcp-ai-performance-reporter.php  # Performance UI
│   │   └── settings-dashboard-init.php              # Initialization
│   ├── assistants/               # Assistant CPT management
│   │   └── metaboxes/           # Meta box definitions
│   ├── blocks/                   # Gutenberg blocks (if applicable)
│   ├── crawler/                  # Crawl4AI integration
│   │   └── class-wp-mcp-ai-crawler.php
│   ├── elementor/                # Elementor widget integrations
│   ├── integrations/             # Third-party plugin integrations
│   │   ├── class-wp-mcp-ai-oauth-manager.php
│   │   ├── class-wp-mcp-ai-integration-simple-jwt.php
│   │   ├── class-wp-mcp-ai-integration-auth0-github.php
│   │   └── class-wp-mcp-ai-integration-wordpress-gravatar.php
│   ├── interfaces/               # PHP interfaces
│   │   └── interface-wp-mcp-ai-generic-tool-response.php
│   ├── repositories/             # Data access layer (3 repositories)
│   │   ├── class-wp-mcp-ai-assistant-repository.php
│   │   ├── class-wp-mcp-ai-credential-repository.php
│   │   └── class-wp-mcp-ai-settings-repository.php
│   ├── rest/                     # REST API components
│   │   ├── class-wp-mcp-ai-rest-authenticator.php  # Auth handler
│   │   ├── class-wp-mcp-ai-rest-validator.php     # Request validator
│   │   └── class-wp-mcp-ai-sse-handler.php        # SSE streaming
│   ├── services/                 # Business logic layer (6 services)
│   │   ├── class-wp-mcp-ai-chat-service.php
│   │   ├── class-wp-mcp-ai-assistant-service.php
│   │   ├── class-wp-mcp-ai-tool-service.php
│   │   ├── class-wp-mcp-ai-file-service.php
│   │   ├── class-wp-mcp-ai-orchestration-preset-service.php
│   │   └── class-wp-mcp-ai-orchestration-health-service.php
│   ├── tools/                    # Tool implementations (75 tool files)
│   │   ├── tools-init.php       # Tool bootstrapping
│   │   ├── class-wp-mcp-ai-tool-interface.php  # Base interface
│   │   ├── class-wp-mcp-ai-tool-*.php  # Individual tools (73 implementations)
│   │   ├── trait-wp-mcp-ai-tool-restrict-from-chat-client.php
│   │   └── remove-background.php
│   ├── class-admin-settings.php  # Legacy settings (backward compat)
│   ├── class-assistant-cpt.php   # Assistant custom post type
│   ├── class-openai-client.php   # Legacy OpenAI client
│   ├── class-rest-endpoints.php  # Legacy REST (backward compat)
│   ├── class-resource-manager.php
│   ├── class-tool-registry.php   # Legacy registry (backward compat)
│   ├── class-wp-mcp-ai-container.php  # DI container
│   ├── class-wp-mcp-ai-tool-registry.php  # Tool registry (current)
│   ├── class-wp-mcp-ai-rest.php  # Main REST controller
│   ├── class-wp-mcp-ai-rest-mcp-methods.php  # MCP protocol trait
│   ├── class-wp-mcp-ai-openai-client.php  # OpenAI implementation
│   ├── class-wp-mcp-ai-enhanced-openai-client.php  # Extended OpenAI
│   ├── class-wp-mcp-ai-gemini-client.php  # Google Gemini
│   ├── class-wp-mcp-ai-ollama-client.php  # Ollama (local AI)
│   ├── class-wp-mcp-ai-lm-studio-client.php  # LM Studio
│   ├── class-wp-mcp-ai-anthropic-client.php  # Anthropic Claude
│   ├── class-wp-mcp-ai-language-model-router.php  # Client factory
│   ├── class-wp-mcp-ai-rate-limit-manager.php
│   ├── class-wp-mcp-ai-token-budget-manager.php
│   ├── class-wp-mcp-ai-logger.php
│   ├── class-wp-mcp-ai-error-handler.php
│   ├── class-wp-mcp-ai-http-helper.php
│   ├── class-wp-mcp-ai-http.php
│   ├── class-wp-mcp-ai-credentials.php
│   ├── class-wp-mcp-ai-shortcode.php
│   ├── class-wp-mcp-ai-shortcodes.php
│   ├── class-wp-mcp-ai-federation.php
│   ├── container-helpers.php     # Container helper functions
│   ├── repositories-init.php     # Repository initialization
│   ├── services-init.php         # Service initialization
│   ├── tools-init.php           # Tool initialization
│   └── job-notifier-init.php    # Background job system
├── assets/
│   ├── js/                      # JavaScript files
│   │   └── chat.js             # Chat UI implementation
│   ├── css/                     # Stylesheets
│   └── examples/               # Code examples
├── tests/                       # PHPUnit test suite
│   ├── test-*.php              # Unit tests
│   ├── rest/                   # REST API tests
│   ├── rest-api/              # REST API integration tests
│   ├── helpers/               # Helper function tests
│   ├── memory/                # Memory and caching tests
│   └── crawler/               # Crawl4AI tests
├── docs/                        # Documentation (32+ files)
│   ├── COPILOT_ARCHITECTURE_GUIDE.md  # This file
│   ├── QUICK_REFERENCE.md
│   ├── DOCUMENTATION_INDEX.md
│   ├── tool-reference.md
│   ├── rest-api.md
│   └── ...
├── bin/                         # Development scripts
│   ├── install-wp-tests.sh
│   └── codex-startup.sh
├── languages/                   # Translation files
│   └── wp-mcp-ai.pot
├── composer.json               # PHP dependencies
├── package.json               # JS dependencies
├── phpunit.xml.dist          # PHPUnit configuration
└── README.md
```

---

## Class Hierarchy

### Core Base Classes and Interfaces

#### Tool System Hierarchy

```
WP_MCP_AI_Tool_Interface (interface)
    ├── WP_MCP_AI_Tool_Shortcuts_Interface (optional interface)
    ├── WP_MCP_AI_Tool_Fallback_Shortcut_Interface (optional interface)
    ├── WP_MCP_AI_Tool_Capability_Flags_Interface (optional interface)
    ├── WP_MCP_AI_Tool_Rules_Interface (optional interface)
    ├── WP_MCP_AI_Tool_Flow_Stage_Interface (optional interface)
    └── WP_MCP_AI_Tool_Context_Restrictions_Interface (optional interface)

Concrete Tool Implementations (73 classes):
    ├── WP_MCP_AI_Tool_Save_Post
    ├── WP_MCP_AI_Tool_Get_Posts
    ├── WP_MCP_AI_Tool_Create_Woo_Product
    ├── WP_MCP_AI_Tool_Get_JetEngine_Items
    ├── WP_MCP_AI_Tool_Generate_Gemini_Image
    ├── WP_MCP_AI_Tool_Transcribe_OpenAI_Audio
    ├── WP_MCP_AI_Tool_Query_Remote_Site
    ├── WP_MCP_AI_Tool_Check_Site_Security
    ├── WP_MCP_AI_Tool_Get_System_Logs
    ├── WP_MCP_AI_Tool_Count_Tokens
    └── ... (64 more tools)
```

#### Admin Settings Hierarchy

```
WP_MCP_AI_Settings_Section (abstract class)
    ├── WP_MCP_AI_Section_Overview
    ├── WP_MCP_AI_Section_General
    ├── WP_MCP_AI_Section_Custom_Filters
    ├── WP_MCP_AI_Section_Providers
    ├── WP_MCP_AI_Section_Authentication
    ├── WP_MCP_AI_Section_Tools
    ├── WP_MCP_AI_Section_Orchestration
    ├── WP_MCP_AI_Section_Integrations
    ├── WP_MCP_AI_Section_JetEngine_Integration
    ├── WP_MCP_AI_Section_WooCommerce_Integration
    ├── WP_MCP_AI_Section_Elementor_Integration
    ├── WP_MCP_AI_Section_Token_Manager
    ├── WP_MCP_AI_Section_Security
    ├── WP_MCP_AI_Section_Performance
    └── WP_MCP_AI_Section_Advanced
```

#### AI Client Hierarchy

```
Base AI Client (no formal inheritance, but consistent interface)
    ├── WP_MCP_AI_OpenAI_Client
    ├── WP_MCP_AI_Enhanced_OpenAI_Client (extends OpenAI functionality)
    ├── WP_MCP_AI_Gemini_Client
    ├── WP_MCP_AI_Ollama_Client
    ├── WP_MCP_AI_LM_Studio_Client
    └── WP_MCP_AI_Anthropic_Client

Managed by: WP_MCP_AI_Language_Model_Router (factory)
```

#### Service Layer Hierarchy

```
Services (no base class, following service pattern)
    ├── WP_MCP_AI_Chat_Service
    ├── WP_MCP_AI_Assistant_Service
    ├── WP_MCP_AI_Tool_Service
    ├── WP_MCP_AI_File_Service
    ├── WP_MCP_AI_Orchestration_Preset_Service
    └── WP_MCP_AI_Orchestration_Health_Service
```

#### Repository Layer Hierarchy

```
Repositories (no base class, following repository pattern)
    ├── WP_MCP_AI_Assistant_Repository
    ├── WP_MCP_AI_Credential_Repository
    └── WP_MCP_AI_Settings_Repository
```

#### REST API Components

```
WP_MCP_AI_REST (main controller)
    Uses trait: WP_MCP_AI_REST_MCP_Methods
    
Helper Classes:
    ├── WP_MCP_AI_REST_Authenticator
    ├── WP_MCP_AI_REST_Validator
    └── WP_MCP_AI_SSE_Handler
```

### Key Manager Classes

```
Singletons:
    ├── WP_MCP_AI_Tool_Registry
    ├── WP_MCP_AI_Container
    ├── WP_MCP_AI_Settings_Registry
    ├── WP_MCP_AI_Logger
    ├── WP_MCP_AI_Rate_Limit_Manager
    ├── WP_MCP_AI_Token_Budget_Manager
    ├── WP_MCP_AI_Cron_Manager
    └── WP_MCP_AI_Job_Queue_Manager

Utility Classes:
    ├── WP_MCP_AI_HTTP_Helper
    ├── WP_MCP_AI_HTTP
    ├── WP_MCP_AI_Cache_Helper
    ├── WP_MCP_AI_REST_Cache
    ├── WP_MCP_AI_Error_Handler
    ├── WP_MCP_AI_Text_Chunker
    └── WP_MCP_AI_Proxy_Utils
```

---

## Namespacing and Naming Conventions

### PHP Naming Conventions

**Classes**: `WP_MCP_AI_Class_Name`
- Prefix: `WP_MCP_AI_`
- Format: Snake_Case with uppercase first letters
- Example: `WP_MCP_AI_Tool_Registry`

**Functions**: `wp_mcp_ai_function_name()`
- Prefix: `wp_mcp_ai_`
- Format: snake_case
- Example: `wp_mcp_ai_get_chat_service()`

**Hooks (Actions/Filters)**: `wp_mcp_ai_hook_name`
- Prefix: `wp_mcp_ai_`
- Format: snake_case
- Example: `wp_mcp_ai_register_tools`

**Constants**: `WP_MCP_AI_CONSTANT_NAME`
- Prefix: `WP_MCP_AI_`
- Format: SCREAMING_SNAKE_CASE
- Example: `WP_MCP_AI_VERSION`

**WordPress Options**: `wp_mcp_ai_option_name`
- Prefix: `wp_mcp_ai_`
- Format: snake_case
- Example: `wp_mcp_ai_openai_api_key`

**Post Types**: `mcp_ai_post_type`
- Prefix: `mcp_ai_`
- Format: snake_case
- Example: `mcp_ai_assistant`

**REST Namespace**: `mcp-ai/v1`
- Format: kebab-case with version
- Example: `/wp-json/mcp-ai/v1/chat`

### File Naming Conventions

**Class Files**: `class-wp-mcp-ai-class-name.php`
- Prefix: `class-`
- Format: kebab-case
- Example: `class-wp-mcp-ai-tool-registry.php`

**Interface Files**: `interface-wp-mcp-ai-interface-name.php`
- Prefix: `interface-`
- Example: `interface-wp-mcp-ai-generic-tool-response.php`

**Trait Files**: `trait-wp-mcp-ai-trait-name.php`
- Prefix: `trait-`
- Example: `trait-wp-mcp-ai-tool-restrict-from-chat-client.php`

**Abstract Classes**: `abstract-wp-mcp-ai-class-name.php`
- Prefix: `abstract-`
- Example: `abstract-wp-mcp-ai-settings-section.php`

**Initialization Files**: `component-init.php`
- Suffix: `-init.php`
- Example: `tools-init.php`, `services-init.php`

### Variable Naming

**Class Properties**: `$property_name`
- Format: snake_case
- Example: `$tool_registry`, `$auth_context`

**Method Parameters**: `$parameter_name`
- Format: snake_case
- Example: `$assistant_id`, `$context`

**Local Variables**: `$variable_name`
- Format: snake_case
- Example: `$post_id`, `$response_data`

---

## Initialization Flow

### Boot Sequence

1. **PHP Version Check** (`mcp-ai-wpoos.php` line 33)
   - Validates PHP >= 7.4
   - Registers admin notice if incompatible
   - Deactivates plugin if incompatible
   - Halts execution to prevent parse errors

2. **Constants Definition** (line 63)
   - `WP_MCP_AI_VERSION`
   - `WP_MCP_AI_PATH`
   - `WP_MCP_AI_URL`

3. **Composer Autoloader** (line 74)
   - Loads vendor dependencies if available
   - tiktoken-php for token counting
   - Symfony HTTP client for API calls

4. **Helper Functions** (line 78-171)
   - `wp_mcp_ai_get_required_chat_capability()`
   - `wp_mcp_ai_filter_crawl4ai_base_url()`
   - `wp_mcp_ai_is_base_version()`

5. **Output Buffering Start** (line 173-202)
   - Prevents output leakage into JSON responses
   - Skips during Elementor operations
   - Safety mechanism for clean REST responses

6. **Admin Settings Components** (line 205-211)
   - Settings base classes
   - AJAX handlers
   - Validators
   - Registry

7. **HTTP & Cache Helpers** (line 213-221)
   - `WP_MCP_AI_HTTP_Helper::init()`
   - Early cache initialization
   - REST context fix

8. **Core Managers** (line 223-264)
   - Admin settings
   - Resource manager
   - Cron manager
   - Logger
   - Error handler
   - Security components
   - HTTP client
   - Credentials
   - Rate limiting
   - Token budgeting
   - Model selector
   - Job queue

9. **Custom Post Types** (line 239)
   - Assistant CPT registration

10. **AI Clients** (line 240-250)
    - OpenAI clients
    - Gemini client
    - Ollama client
    - LM Studio client
    - Anthropic client
    - Language model router

11. **Tool System** (line 263)
    - `tools-init.php` → `tools/tools-init.php`
    - Tool registry initialization via `plugins_loaded` hook

12. **REST API** (line 259)
    - Legacy endpoints (backward compat)
    - Modern REST controller loaded later

13. **Shortcodes** (line 261-262)
    - Shortcode handler classes

14. **Dependency Injection** (line 267-268)
    - Container initialization
    - Container helper functions

15. **Repository Layer** (line 271)
    - `repositories-init.php`

16. **Service Layer** (line 274)
    - `services-init.php`

17. **Federation System** (line 277-282)
    - Federation settings
    - Well-known endpoints
    - AI peer CPT
    - Federation logic

18. **Third-Party Integrations** (line 285-301)
    - Only if `!wp_mcp_ai_is_base_version()`
    - JetEngine
    - JetFormBuilder
    - Elementor
    - ChatKit
    - Simple JWT Login
    - Auth0

19. **Output Buffer Cleanup** (line 305-307)
    - Discards buffered output
    - Ensures clean state for REST

20. **Admin-Only Components** (line 309-361)
    - Only loaded when `is_admin()`
    - Cron manager UI
    - Performance reporter
    - Security monitor
    - Diagnostic pages
    - Settings dashboard (new or legacy based on config)
    - Plugin action links

21. **WP-CLI Commands** (line 363-365)
    - Only if `WP_CLI` defined

22. **Component Initialization** (line 367-395)
    - Message attachments
    - Response attachments
    - REST API context fix
    - HTTP bootstrap
    - Integration initialization

23. **Text Domain Loading** (line 397-411)
    - i18n support via `load_plugin_textdomain()`

### Hook Registration Points

**Critical Hooks** (in execution order):

1. `plugins_loaded` (priority 5)
   - Tool registry initialization

2. `rest_api_init`
   - REST route registration
   - Output buffer cleaning
   - SSE setup

3. `admin_menu`
   - Settings page registration
   - Diagnostic pages

4. `admin_notices`
   - Unavailable tool warnings
   - PHP version errors

5. `init`
   - CPT registration
   - Shortcode registration
   - Federation endpoints

6. `wp_loaded`
   - Full WordPress environment ready
   - Safe to access all WP functions

---

## Service Layer

### Service Initialization

**File**: `includes/services-init.php`

**Function**: `wp_mcp_ai_init_services()`

Returns array of initialized services:
```php
array(
    'chat'      => WP_MCP_AI_Chat_Service instance,
    'assistant' => WP_MCP_AI_Assistant_Service instance,
    'tool'      => WP_MCP_AI_Tool_Service instance,
    'file'      => WP_MCP_AI_File_Service instance,
)
```

**Helper Functions**:
- `wp_mcp_ai_get_chat_service()`
- `wp_mcp_ai_get_assistant_service()`
- `wp_mcp_ai_get_tool_service()`
- `wp_mcp_ai_get_file_service()`
- `wp_mcp_ai_get_language_model_router()`
- `wp_mcp_ai_get_rate_limit_manager()`
- `wp_mcp_ai_get_token_budget_manager()`
- `wp_mcp_ai_get_tool_registry()`

### Chat Service

**Class**: `WP_MCP_AI_Chat_Service`  
**File**: `includes/services/class-wp-mcp-ai-chat-service.php`

**Dependencies**:
- `WP_MCP_AI_Language_Model_Router`
- `WP_MCP_AI_Tool_Registry`
- `WP_MCP_AI_Assistant_Repository`
- `WP_MCP_AI_Rate_Limit_Manager`
- `WP_MCP_AI_Token_Budget_Manager`

**Key Methods**:

```php
public function process_chat(
    $messages,           // Array of message objects
    $assistant_id,       // Assistant ID
    $context = array(),  // Request context
    $stream = false      // Enable streaming
)
```

**Responsibilities**:
- Validate assistant exists and is accessible
- Apply rate limiting per user/assistant
- Manage token budgets
- Forward to appropriate AI provider
- Handle tool calls during conversation
- Process streaming responses
- Record chat transcripts
- Return formatted response

**Flow**:
1. Validate assistant and permissions
2. Check rate limits
3. Prepare message context
4. Invoke AI provider via router
5. Handle tool execution if requested
6. Apply token budget constraints
7. Stream or return complete response
8. Log conversation (if enabled)

### Assistant Service

**Class**: `WP_MCP_AI_Assistant_Service`  
**File**: `includes/services/class-wp-mcp-ai-assistant-service.php`

**Dependencies**:
- `WP_MCP_AI_Assistant_Repository`
- `WP_MCP_AI_Credential_Repository`

**Key Methods**:

```php
public function get_assistant( $assistant_id )
public function list_assistants( $args = array() )
public function create_assistant( $data )
public function update_assistant( $assistant_id, $data )
public function delete_assistant( $assistant_id )
public function get_enabled_tools( $assistant_id )
public function check_permission( $assistant_id, $capability )
```

**Responsibilities**:
- CRUD operations for assistants
- Permission validation
- Tool availability checking
- Credential management
- Assistant configuration retrieval

### Tool Service

**Class**: `WP_MCP_AI_Tool_Service`  
**File**: `includes/services/class-wp-mcp-ai-tool-service.php`

**Dependencies**:
- `WP_MCP_AI_Tool_Registry`

**Key Methods**:

```php
public function list_tools( $filters = array() )
public function get_tool( $tool_slug )
public function execute_tool( $tool_slug, $arguments, $context )
public function validate_tool_parameters( $tool_slug, $arguments )
public function check_tool_capability( $tool_slug, $capability )
```

**Responsibilities**:
- Tool discovery
- Tool execution
- Parameter validation
- Capability checking
- Tool metadata retrieval

### File Service

**Class**: `WP_MCP_AI_File_Service`  
**File**: `includes/services/class-wp-mcp-ai-file-service.php`

**Key Methods**:

```php
public function handle_upload( $file )
public function validate_file( $file )
public function attach_to_message( $file_id, $message_id )
public function cleanup_old_files( $days = 7 )
```

**Responsibilities**:
- File upload handling
- MIME type validation
- File size checking
- Attachment management
- Cleanup of temporary files

---

## Repository Layer

### What are Repositories?

**Repositories** are the **data access layer** of the plugin architecture. They provide an abstraction between the business logic (services) and the data storage mechanisms (WordPress database, options, post meta, etc.).

**Purpose**: 
- Isolate data access logic from business logic
- Provide a consistent interface for data operations
- Enable easier testing by mocking data layer
- Allow changing data storage without affecting business logic
- Centralize database query logic

**Current State**: The plugin has **3 implemented repositories** as part of Phase 4 refactoring (Milestone 9):

1. **Assistant Repository** - Manages assistant configuration data
2. **Credential Repository** - Manages authentication credentials
3. **Settings Repository** - Manages plugin settings and options

**Note**: This is a **work in progress**. Additional data entities that should have repositories but currently use direct database access include:

**Potential Future Repositories**:
- **AI Peer Repository** - For federation peer sites (currently `WP_MCP_AI_AI_Peer_CPT`)
- **Chat Transcript Repository** - For conversation history (currently `WP_MCP_AI_Chat_Transcript_Recorder`)
- **Rate Limits Repository** - For model rate limits (currently `WP_MCP_AI_Model_Rate_Limits_CCT`)
- **Performance Metrics Repository** - For monitoring data (currently `WP_MCP_AI_Performance_Monitor_CCT`)
- **Job Queue Repository** - For background jobs (currently `WP_MCP_AI_Job_Queue_Manager`)

**Why Only 3 Repositories Currently?**

The repository layer is being implemented **incrementally** as part of architectural refactoring:
- **Phase 4, Milestone 9**: Initial repository layer (3 core repositories)
- Focus on most critical data entities first (assistants, credentials, settings)
- Additional repositories to be added in future phases
- Legacy code gradually refactored to use repositories

**Pattern**: Repository Pattern (Data Access Object pattern)

**Benefits**:
- **Single Responsibility**: Each repository handles one entity type
- **Testability**: Services can be tested with mocked repositories
- **Maintainability**: Database changes isolated to repository layer
- **Consistency**: All data access follows same patterns

### Repository Initialization

**File**: `includes/repositories-init.php`

Loaded during plugin bootstrap. No explicit initialization function needed as repositories are instantiated on-demand via DI container.

**Container Registration**:

```php
// In WP_MCP_AI_Container
$container->register( 'repository.assistant', function() {
    return new WP_MCP_AI_Assistant_Repository();
} );

$container->register( 'repository.credential', function() {
    return new WP_MCP_AI_Credential_Repository();
} );

$container->register( 'repository.settings', function() {
    return new WP_MCP_AI_Settings_Repository();
} );
```

**Usage from Services**:

```php
// Service receives repository via dependency injection
class WP_MCP_AI_Assistant_Service {
    protected $assistant_repository;
    
    public function __construct( $assistant_repository ) {
        $this->assistant_repository = $assistant_repository;
    }
    
    public function get_assistant( $id ) {
        // Service uses repository for data access
        return $this->assistant_repository->find( $id );
    }
}
```

### Assistant Repository

**Class**: `WP_MCP_AI_Assistant_Repository`  
**File**: `includes/repositories/class-wp-mcp-ai-assistant-repository.php`

**Purpose**: Manages assistant configuration data (AI assistant settings, models, tools, instructions)

**Data Source**: Custom Post Type `mcp_ai_assistant`

**Why a Repository?**:
- Abstracts WordPress post type operations
- Provides clean interface for assistant CRUD
- Handles post meta serialization/deserialization
- Enables changing storage mechanism (e.g., to custom tables) without breaking services

**Schema** (Post Meta):
- `_wp_mcp_ai_model` - AI model identifier
- `_wp_mcp_ai_provider` - Provider (openai, gemini, etc.)
- `_wp_mcp_ai_temperature` - Temperature setting
- `_wp_mcp_ai_max_tokens` - Token limit
- `_wp_mcp_ai_enabled_tools` - Array of enabled tool slugs
- `_wp_mcp_ai_instructions` - System instructions
- `_wp_mcp_ai_credentials` - Credential IDs (hashed)

**Methods**:

```php
public function find( $assistant_id )
// Returns: array|null with assistant data
// Example: $assistant = $repo->find( 123 );

public function find_all( $args = array() )
// Returns: array of assistant arrays
// Example: $assistants = $repo->find_all( array( 'status' => 'publish' ) );

public function save( $data )
// Returns: int|WP_Error (post ID or error)
// Example: $id = $repo->save( array( 'title' => 'My Assistant', 'model' => 'gpt-4' ) );

public function delete( $assistant_id )
// Returns: bool|WP_Error
// Example: $deleted = $repo->delete( 123 );

public function get_meta( $assistant_id, $key, $single = true )
// Returns: mixed
// Example: $model = $repo->get_meta( 123, '_wp_mcp_ai_model' );

public function update_meta( $assistant_id, $key, $value )
// Returns: bool|int
// Example: $repo->update_meta( 123, '_wp_mcp_ai_model', 'gpt-4' );
```

**Query Arguments** (for `find_all`):
- `status` - 'publish', 'draft', etc.
- `per_page` - Results per page
- `paged` - Page number
- `orderby` - Sort field
- `order` - 'ASC' or 'DESC'

**Example Usage**:

```php
// Create new assistant
$assistant_id = $assistant_repo->save( array(
    'post_title' => 'Customer Support Bot',
    'post_status' => 'publish',
    'meta_input' => array(
        '_wp_mcp_ai_model' => 'gpt-4',
        '_wp_mcp_ai_provider' => 'openai',
        '_wp_mcp_ai_temperature' => 0.7,
    ),
) );

// Retrieve assistant
$assistant = $assistant_repo->find( $assistant_id );

// Update assistant
$assistant_repo->update_meta( $assistant_id, '_wp_mcp_ai_model', 'gpt-4-turbo' );

// List all assistants
$all_assistants = $assistant_repo->find_all( array(
    'status' => 'publish',
    'per_page' => 20,
) );
```

### Credential Repository

**Class**: `WP_MCP_AI_Credential_Repository`  
**File**: `includes/repositories/class-wp-mcp-ai-credential-repository.php`

**Purpose**: Manages authentication credentials for API access to assistants

**Data Source**: Post meta + `WP_MCP_AI_Credentials` class

**Why a Repository?**:
- Abstracts credential storage and hashing
- Provides secure credential generation
- Handles token verification logic
- Centralizes credential revocation

**Schema**:
- Stored in `_wp_mcp_ai_credentials` meta
- Format: Array of credential objects
- Each credential:
  - `id` - Unique credential ID
  - `token_hash` - Hashed token
  - `prefix` - Token prefix (visible part)
  - `created_at` - Creation timestamp
  - `last_used` - Last usage timestamp
  - `revoked` - Revocation status

**Methods**:

```php
public function find_by_assistant( $assistant_id )
// Returns: array of credential objects
// Example: $credentials = $repo->find_by_assistant( 123 );

public function generate( $assistant_id, $label = '' )
// Returns: array with 'credential_id' and 'token' (plaintext, shown once)
// Example: $new_cred = $repo->generate( 123, 'Mobile App Access' );

public function verify( $token )
// Returns: array|false with assistant_id and credential_id if valid
// Example: $valid = $repo->verify( 'cred_abc123.secret_token' );

public function revoke( $assistant_id, $credential_id )
// Returns: bool
// Example: $revoked = $repo->revoke( 123, 'abc123' );

public function update_last_used( $assistant_id, $credential_id )
// Returns: bool
// Example: $repo->update_last_used( 123, 'abc123' );
```

**Security**:
- Tokens are hashed with `password_hash()` before storage
- Only prefix is stored in plaintext for identification
- Plaintext token shown only once during generation
- Token format: `cred_{ID}.{SECRET}`

### Settings Repository

**Class**: `WP_MCP_AI_Settings_Repository`  
**File**: `includes/repositories/class-wp-mcp-ai-settings-repository.php`

**Data Source**: WordPress Options API

**Option Keys** (all prefixed with `wp_mcp_ai_`):

**Provider Settings**:
- `openai_api_key` - OpenAI API key
- `openai_model` - Default OpenAI model
- `gemini_api_key` - Google Gemini API key
- `gemini_model` - Default Gemini model
- `ollama_base_url` - Ollama server URL
- `ollama_model` - Default Ollama model
- `anthropic_api_key` - Anthropic API key
- `anthropic_model` - Default Anthropic model

**General Settings**:
- `enable_logging` - Boolean
- `log_level` - 'error', 'warning', 'info', 'debug'
- `enable_caching` - Boolean
- `cache_ttl` - Cache time-to-live in seconds
- `default_temperature` - Default temperature (0-2)
- `default_max_tokens` - Default token limit

**Security Settings**:
- `enable_rate_limiting` - Boolean
- `rate_limit_per_minute` - Requests per minute
- `rate_limit_per_hour` - Requests per hour
- `enable_auth0` - Auth0 integration
- `auth0_domain` - Auth0 domain
- `auth0_client_id` - Auth0 client ID
- `auth0_client_secret` - Auth0 secret

**Integration Settings**:
- `jetengine_sync_enabled` - Boolean
- `jetengine_cct_slug` - CCT slug for assistants
- `woocommerce_enabled` - Boolean
- `elementor_enabled` - Boolean

**Methods**:

```php
public function get( $key, $default = null )
// Returns: mixed (option value or default)

public function set( $key, $value )
// Returns: bool

public function delete( $key )
// Returns: bool

public function get_all()
// Returns: array of all plugin settings

public function get_provider_settings( $provider )
// Returns: array of provider-specific settings
```

---


## REST API Architecture

### Main REST Controller

**Class**: `WP_MCP_AI_REST`  
**File**: `includes/class-wp-mcp-ai-rest.php`  
**Lines**: 6,951 lines (largest class in the plugin)

**Namespace**: `mcp-ai/v1`

**Trait Used**: `WP_MCP_AI_REST_MCP_Methods`
- Implements MCP protocol methods
- `list_tools()`, `call_tool()`, `list_prompts()`, etc.

**Dependencies**:
- `WP_MCP_AI_Tool_Registry` - Tool discovery
- `WP_MCP_AI_Language_Model_Router` - AI provider routing
- `WP_MCP_AI_REST_Authenticator` - Authentication
- `WP_MCP_AI_REST_Validator` - Request validation
- `WP_MCP_AI_SSE_Handler` - Server-Sent Events
- Service instances (chat, assistant, tool, file)

**Constants**:

```php
const REST_NAMESPACE = 'mcp-ai/v1';
const MEMORY_MAX_DOCUMENT_CHARS = 4000;
const MEMORY_CHUNK_CHARS = 1200;
const MEMORY_MAX_TOTAL_CHARS = 12000;
const MEMORY_MAX_FILE_BYTES = 5242880;  // 5MB
const MEMORY_MAX_DOCUMENT_BYTES = 262144;  // 256KB
const MEMORY_MAX_TOTAL_BYTES = 1048576;  // 1MB
const CHAT_MAX_REQUEST_TOKENS = 480000;
const CHAT_APPROX_CHARS_PER_TOKEN = 4;
const TPM_SAFETY_MARGIN = 0.8;  // 80%
const TPM_FALLBACK_TOKENS = 100000;
const DOCUMENT_PROMPT_TOOL_SLUG = 'submit_document_prompt';
```

### REST Endpoints

#### Chat Endpoint

**Route**: `POST /wp-json/mcp-ai/v1/chat`

**Handler**: `handle_chat_request()`

**Parameters**:
- `assistant_id` (required) - Assistant post ID
- `messages` (required) - Array of message objects
- `stream` (optional) - Boolean, enable SSE streaming
- `temperature` (optional) - Override temperature
- `max_tokens` (optional) - Override token limit
- `model` (optional) - Override model
- `tool_choice` (optional) - 'auto', 'none', or specific tool

**Message Format**:
```php
array(
    'role' => 'user|assistant|system|tool',
    'content' => 'Message text',
    'tool_call_id' => 'Optional tool call ID',
    'tool_calls' => array(...)  // Optional tool calls
)
```

**Response Format** (non-streaming):
```php
array(
    'id' => 'chat-xxxxx',
    'object' => 'chat.completion',
    'created' => 1234567890,
    'model' => 'gpt-4',
    'choices' => array(
        array(
            'index' => 0,
            'message' => array(
                'role' => 'assistant',
                'content' => 'Response text',
                'tool_calls' => array(...)
            ),
            'finish_reason' => 'stop|length|tool_calls'
        )
    ),
    'usage' => array(
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'total_tokens' => 150
    )
)
```

**Response Format** (streaming):
- Content-Type: `text/event-stream`
- Events: `data: {json}\n\n`
- Chunks follow OpenAI streaming format

#### Assistants Endpoint

**Route**: `GET /wp-json/mcp-ai/v1/assistants`

**Handler**: `handle_list_assistants()`

**Parameters**:
- `per_page` (optional) - Results per page (default: 10)
- `page` (optional) - Page number (default: 1)
- `status` (optional) - 'publish', 'draft', etc.

**Response Format**:
```php
array(
    'assistants' => array(
        array(
            'id' => 123,
            'name' => 'Assistant Name',
            'description' => 'Description',
            'model' => 'gpt-4',
            'provider' => 'openai',
            'enabled_tools' => array('tool_1', 'tool_2'),
            'instructions' => 'System instructions',
            'temperature' => 0.7,
            'max_tokens' => 4096
        )
    ),
    'total' => 5,
    'pages' => 1
)
```

#### Tools Endpoint

**Route**: `GET /wp-json/mcp-ai/v1/tools`

**Handler**: `handle_list_tools()` (from MCP Methods trait)

**Parameters**:
- `assistant_id` (optional) - Filter by assistant
- `capability` (optional) - Filter by capability flag

**Response Format**:
```php
array(
    'tools' => array(
        array(
            'slug' => 'save_post',
            'name' => 'Save Post',
            'description' => 'Create or update a WordPress post',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'title' => array(
                        'type' => 'string',
                        'description' => 'Post title'
                    ),
                    'content' => array(
                        'type' => 'string',
                        'description' => 'Post content'
                    )
                ),
                'required' => array('title')
            ),
            'capability_flags' => array('write', 'state-changing'),
            'flow_stages' => array('anytime')
        )
    )
)
```

#### Execute Tool Endpoint

**Route**: `POST /wp-json/mcp-ai/v1/tools/execute`

**Handler**: `handle_execute_tool()`

**Parameters**:
- `tool_slug` (required) - Tool identifier
- `arguments` (required) - Tool arguments object
- `assistant_id` (optional) - Assistant context

**Response Format**:
```php
array(
    'success' => true,
    'result' => mixed,  // Tool-specific result
    'error' => null|array(
        'code' => 'error_code',
        'message' => 'Error description'
    )
)
```

#### SSE Endpoint

**Route**: `GET /wp-json/mcp-ai/v1/sse`

**Handler**: `handle_sse_stream()`

**Purpose**: Server-Sent Events endpoint for real-time updates

**Headers**:
- `Content-Type: text/event-stream`
- `Cache-Control: no-cache`
- `X-Accel-Buffering: no`

**Event Format**:
```
event: message
data: {"type":"chunk","content":"text"}

event: tool_call
data: {"tool":"save_post","arguments":{...}}

event: done
data: {"finish_reason":"stop"}
```

### REST Authentication

**Class**: `WP_MCP_AI_REST_Authenticator`  
**File**: `includes/rest/class-wp-mcp-ai-rest-authenticator.php`

**Authentication Methods** (in order of precedence):

1. **WordPress Nonce**
   - Header: `X-WP-Nonce: {nonce}`
   - For logged-in WordPress users
   - Same-origin requests only
   - Standard WordPress authentication

2. **Assistant Credentials**
   - Header: `Authorization: Bearer cred_{ID}.{SECRET}`
   - Plugin-issued bearer tokens
   - Tied to specific assistant
   - Hashed storage for security
   - Format: `cred_` prefix + credential ID + secret

3. **Auth0 JWT**
   - Header: `Authorization: Bearer {jwt_token}`
   - Enterprise authentication
   - Validated against Auth0 domain
   - User info extracted from token

4. **Guest Tokens**
   - Header: `X-WP-MCP-AI-Guest: {guest_token}`
   - Temporary tokens for public chat
   - Short-lived (default: 24 hours)
   - Limited to specific assistant

**Authentication Flow**:

```php
public function authenticate_request( $request ) {
    // 1. Check for WordPress nonce
    if ( $this->verify_nonce( $request ) ) {
        return $this->get_current_user_context();
    }
    
    // 2. Check for Authorization header
    $auth_header = $request->get_header( 'authorization' );
    if ( $auth_header ) {
        if ( strpos( $auth_header, 'cred_' ) !== false ) {
            return $this->verify_credential_token( $auth_header );
        } else {
            return $this->verify_auth0_token( $auth_header );
        }
    }
    
    // 3. Check for guest token
    $guest_token = $request->get_header( 'X-WP-MCP-AI-Guest' );
    if ( $guest_token ) {
        return $this->verify_guest_token( $guest_token );
    }
    
    // 4. No authentication provided
    return new WP_Error( 'unauthorized', 'Authentication required' );
}
```

**Context Object**:
```php
array(
    'user_id' => int,           // WordPress user ID or 0
    'assistant_id' => int,      // Associated assistant
    'auth_method' => string,    // 'nonce', 'credential', 'auth0', 'guest'
    'credential_id' => string,  // If credential auth
    'capabilities' => array(),  // User capabilities
    'is_guest' => bool,         // Guest mode flag
)
```

### Request Validation

**Class**: `WP_MCP_AI_REST_Validator`  
**File**: `includes/rest/class-wp-mcp-ai-rest-validator.php`

**Methods**:

```php
public function validate_chat_request( $request )
public function validate_assistant_id( $assistant_id )
public function validate_messages( $messages )
public function validate_tool_arguments( $tool_slug, $arguments )
public function sanitize_input( $input, $type )
```

**Validation Rules**:

**Messages Array**:
- Must be array
- Each message must have 'role' and 'content'
- Valid roles: 'user', 'assistant', 'system', 'tool'
- Content must be string (or array for multimodal)
- Maximum message history: 100 messages
- Maximum content length per message: 50,000 characters

**Tool Arguments**:
- Must match tool's parameter schema
- Required fields must be present
- Type validation (string, int, bool, array, object)
- Range validation (min, max, enum)
- Format validation (email, url, etc.)

**Assistant ID**:
- Must be positive integer
- Assistant post must exist
- Assistant must be published (or draft for admin users)
- User must have permission to access assistant

### SSE Streaming

**Class**: `WP_MCP_AI_SSE_Handler`  
**File**: `includes/rest/class-wp-mcp-ai-sse-handler.php`

**Purpose**: Handle Server-Sent Events streaming for real-time chat responses

**Methods**:

```php
public function start_stream()
// Initialize SSE headers and connection

public function send_event( $event_name, $data )
// Send SSE event: event: {name}\ndata: {json}\n\n

public function send_chunk( $content )
// Send content chunk

public function send_tool_call( $tool_call )
// Send tool call event

public function send_error( $error )
// Send error event

public function end_stream( $finish_reason = 'stop' )
// Close SSE stream
```

**Event Types**:
- `message` - Content chunk
- `tool_call` - Tool invocation
- `tool_result` - Tool execution result
- `error` - Error occurred
- `done` - Stream complete

**Stream Flow**:
1. Client connects to SSE endpoint
2. Server sends initial headers
3. Server processes request
4. Server sends events as they occur
5. Server sends `done` event
6. Connection closes

**Error Handling**:
- Connection timeout detection
- Graceful degradation
- Error events for failures
- Connection keep-alive (heartbeat comments)

---

## AJAX Architecture

### Overview

The plugin uses WordPress AJAX API for asynchronous admin operations. AJAX handlers provide real-time feedback for settings operations, credential management, tool testing, and diagnostic checks.

**Architecture Pattern**: Action-based routing with nonce verification

**Key Components**:
1. AJAX Handler Class
2. JavaScript AJAX Client
3. Nonce Security
4. Response Standardization

### AJAX Handler Class

**Class**: `WP_MCP_AI_Admin_AJAX_Handlers`  
**File**: `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php`

**Responsibilities**:
- Handle admin AJAX requests
- Validate nonces and capabilities
- Execute backend operations
- Return standardized JSON responses
- Log AJAX errors

**Initialization**:

The class is instantiated during admin initialization and hooks are registered automatically:

```php
// Initialization in admin context
if ( is_admin() ) {
    $ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();
}
```

### Registered AJAX Actions

**Provider Testing**:
```php
add_action( 'wp_ajax_wp_mcp_ai_test_provider', array( $this, 'handle_test_provider' ) );
```
- Tests AI provider API connection
- Validates API keys
- Returns connection status

**Credential Management**:
```php
add_action( 'wp_ajax_wp_mcp_ai_generate_credential', array( $this, 'handle_generate_credential' ) );
add_action( 'wp_ajax_wp_mcp_ai_revoke_credential', array( $this, 'handle_revoke_credential' ) );
```
- Generates new assistant credentials
- Revokes existing credentials
- Returns credential data (token shown once)

**Tool Testing**:
```php
add_action( 'wp_ajax_wp_mcp_ai_test_tool', array( $this, 'handle_test_tool' ) );
```
- Tests tool execution in admin
- Validates tool parameters
- Returns tool execution result

**System Diagnostics**:
```php
add_action( 'wp_ajax_wp_mcp_ai_get_diagnostics', array( $this, 'handle_get_diagnostics' ) );
```
- Retrieves system health information
- Checks plugin dependencies
- Returns diagnostic data

**Cache Management**:
```php
add_action( 'wp_ajax_wp_mcp_ai_clear_cache', array( $this, 'handle_clear_cache' ) );
```
- Clears plugin cache
- Refreshes transients
- Returns cache clear confirmation

**Settings Operations**:
```php
add_action( 'wp_ajax_wp_mcp_ai_save_settings', array( $this, 'handle_save_settings' ) );
add_action( 'wp_ajax_wp_mcp_ai_reset_settings', array( $this, 'handle_reset_settings' ) );
```
- Saves plugin settings
- Resets to defaults
- Validates input before saving

### AJAX Handler Pattern

**Standard Handler Structure**:

```php
public function handle_action_name() {
    // 1. Verify nonce
    check_ajax_referer( 'wp_mcp_ai_admin_nonce', 'nonce' );
    
    // 2. Check capability
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array(
            'message' => __( 'Insufficient permissions', 'wp-mcp-ai' ),
        ) );
        return;
    }
    
    // 3. Sanitize input
    $param = isset( $_POST['param'] ) ? sanitize_text_field( wp_unslash( $_POST['param'] ) ) : '';
    
    // 4. Validate input
    if ( empty( $param ) ) {
        wp_send_json_error( array(
            'message' => __( 'Missing required parameter', 'wp-mcp-ai' ),
        ) );
        return;
    }
    
    // 5. Execute operation
    $result = $this->perform_operation( $param );
    
    // 6. Handle errors
    if ( is_wp_error( $result ) ) {
        WP_MCP_AI_Logger::error( 'AJAX operation failed', array(
            'action' => 'action_name',
            'error' => $result->get_error_message(),
        ) );
        
        wp_send_json_error( array(
            'message' => $result->get_error_message(),
            'code' => $result->get_error_code(),
        ) );
        return;
    }
    
    // 7. Log success
    WP_MCP_AI_Logger::info( 'AJAX operation completed', array(
        'action' => 'action_name',
    ) );
    
    // 8. Return success response
    wp_send_json_success( array(
        'message' => __( 'Operation completed successfully', 'wp-mcp-ai' ),
        'data' => $result,
    ) );
}
```

### JavaScript AJAX Client

**Location**: `assets/js/admin.js` (or inline in admin pages)

**jQuery AJAX Call Pattern**:

```javascript
jQuery( document ).ready( function( $ ) {
    $( '#test-provider-button' ).on( 'click', function( e ) {
        e.preventDefault();
        
        var $button = $( this );
        var provider = $( '#provider-select' ).val();
        var apiKey = $( '#api-key-input' ).val();
        
        // Disable button during request
        $button.prop( 'disabled', true );
        
        // Show loading indicator
        $( '.status-indicator' ).addClass( 'loading' );
        
        $.ajax( {
            url: ajaxurl,  // WordPress global
            type: 'POST',
            data: {
                action: 'wp_mcp_ai_test_provider',
                nonce: wpMcpAiAdmin.nonce,  // Localized nonce
                provider: provider,
                api_key: apiKey
            },
            success: function( response ) {
                if ( response.success ) {
                    // Handle success
                    $( '.status-indicator' )
                        .removeClass( 'loading' )
                        .addClass( 'success' )
                        .text( response.data.message );
                } else {
                    // Handle error
                    $( '.status-indicator' )
                        .removeClass( 'loading' )
                        .addClass( 'error' )
                        .text( response.data.message );
                }
            },
            error: function( jqXHR, textStatus, errorThrown ) {
                // Handle AJAX error
                $( '.status-indicator' )
                    .removeClass( 'loading' )
                    .addClass( 'error' )
                    .text( 'Request failed: ' + textStatus );
            },
            complete: function() {
                // Re-enable button
                $button.prop( 'disabled', false );
            }
        } );
    } );
} );
```

### Nonce Management

**Nonce Generation** (server-side):

```php
wp_localize_script( 'wp-mcp-ai-admin', 'wpMcpAiAdmin', array(
    'nonce' => wp_create_nonce( 'wp_mcp_ai_admin_nonce' ),
    'ajaxurl' => admin_url( 'admin-ajax.php' ),
) );
```

**Nonce Verification** (in AJAX handler):

```php
check_ajax_referer( 'wp_mcp_ai_admin_nonce', 'nonce' );
```

**Nonce in JavaScript**:

```javascript
data: {
    action: 'wp_mcp_ai_action',
    nonce: wpMcpAiAdmin.nonce,  // From localized script
    // ... other data
}
```

### Response Standardization

**Success Response Format**:

```php
wp_send_json_success( array(
    'message' => 'Human-readable success message',
    'data' => array(
        'key1' => 'value1',
        'key2' => 'value2',
    ),
) );
```

Translates to JSON:

```json
{
    "success": true,
    "data": {
        "message": "Human-readable success message",
        "data": {
            "key1": "value1",
            "key2": "value2"
        }
    }
}
```

**Error Response Format**:

```php
wp_send_json_error( array(
    'message' => 'Human-readable error message',
    'code' => 'error_code',
    'details' => array(
        'field' => 'field_name',
        'issue' => 'validation_issue',
    ),
) );
```

Translates to JSON:

```json
{
    "success": false,
    "data": {
        "message": "Human-readable error message",
        "code": "error_code",
        "details": {
            "field": "field_name",
            "issue": "validation_issue"
        }
    }
}
```

### Error Handling in AJAX

**Backend Error Handling**:

1. **Validation Errors**:
```php
if ( empty( $required_param ) ) {
    wp_send_json_error( array(
        'message' => __( 'Required parameter missing', 'wp-mcp-ai' ),
        'code' => 'missing_parameter',
    ) );
}
```

2. **Permission Errors**:
```php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_send_json_error( array(
        'message' => __( 'Insufficient permissions', 'wp-mcp-ai' ),
        'code' => 'insufficient_permissions',
    ) );
}
```

3. **Operation Errors**:
```php
if ( is_wp_error( $result ) ) {
    WP_MCP_AI_Logger::error( 'Operation failed', array(
        'error' => $result->get_error_message(),
    ) );
    
    wp_send_json_error( array(
        'message' => $result->get_error_message(),
        'code' => $result->get_error_code(),
    ) );
}
```

**Frontend Error Handling**:

```javascript
success: function( response ) {
    if ( response.success ) {
        // Success path
        showSuccessNotice( response.data.message );
    } else {
        // Error returned by handler
        showErrorNotice( response.data.message );
        logError( response.data.code, response.data );
    }
},
error: function( jqXHR, textStatus, errorThrown ) {
    // AJAX request failed (network error, 500, etc.)
    showErrorNotice( 'Request failed: ' + textStatus );
    logError( 'ajax_error', {
        status: jqXHR.status,
        error: errorThrown
    } );
}
```

### AJAX Security Best Practices

**Implemented Security Measures**:

1. **Nonce Verification**: Every AJAX request verified with `check_ajax_referer()`
2. **Capability Checks**: User capabilities checked before operations
3. **Input Sanitization**: All input sanitized with WordPress functions
4. **Output Escaping**: Error messages and data escaped in responses
5. **Rate Limiting**: Optional rate limiting on AJAX endpoints
6. **Logging**: All AJAX operations logged for audit trail

**Example: Complete Secure Handler**:

```php
public function handle_test_provider() {
    // Nonce check
    check_ajax_referer( 'wp_mcp_ai_admin_nonce', 'nonce' );
    
    // Capability check
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'Unauthorized', 'wp-mcp-ai' ),
        ) );
    }
    
    // Input sanitization
    $provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
    $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
    
    // Input validation
    if ( empty( $provider ) || empty( $api_key ) ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'Provider and API key are required', 'wp-mcp-ai' ),
        ) );
    }
    
    // Test connection with timeout
    $result = $this->test_provider_connection( $provider, $api_key );
    
    // Error handling
    if ( is_wp_error( $result ) ) {
        WP_MCP_AI_Logger::error( 'Provider test failed', array(
            'provider' => $provider,
            'error' => $result->get_error_message(),
        ) );
        
        wp_send_json_error( array(
            'message' => esc_html( $result->get_error_message() ),
        ) );
    }
    
    // Success logging
    WP_MCP_AI_Logger::info( 'Provider test successful', array(
        'provider' => $provider,
    ) );
    
    // Success response with escaped output
    wp_send_json_success( array(
        'message' => esc_html__( 'Connection successful', 'wp-mcp-ai' ),
        'details' => array(
            'provider' => esc_html( $provider ),
            'status' => 'connected',
        ),
    ) );
}
```

### AJAX Flow Diagram

```
User Action (Click Button)
    ↓
JavaScript Event Handler
    ↓
jQuery.ajax() Call
    ├─> URL: admin-ajax.php
    ├─> Data: { action, nonce, params }
    └─> Type: POST
    ↓
WordPress AJAX Router
    ↓
Action Hook: wp_ajax_{action}
    ↓
AJAX Handler Method
    ├─> 1. check_ajax_referer()
    ├─> 2. current_user_can()
    ├─> 3. Sanitize input
    ├─> 4. Validate input
    ├─> 5. Execute operation
    ├─> 6. Log result
    └─> 7. wp_send_json_*()
    ↓
JSON Response
    ↓
JavaScript Success/Error Handler
    ↓
Update UI (show message, update display)
```

### Common AJAX Use Cases

**1. Testing API Connections**:
- Provider credentials validation
- Real-time connection testing
- Error message display

**2. Credential Management**:
- Generate new credentials
- Display plaintext token once
- Revoke existing credentials

**3. Tool Testing**:
- Test tool execution
- Validate tool parameters
- Display tool results

**4. Settings Management**:
- Save settings asynchronously
- Validate before save
- Show save confirmation

**5. Diagnostics**:
- Fetch system health
- Check dependencies
- Display diagnostic info

### AJAX Debugging

**Enable Logging**:

```php
define( 'WP_MCP_AI_DEBUG', true );
```

**Check AJAX Logs**:

```bash
wp option get wp_mcp_ai_recent_activity --format=json | grep "AJAX"
```

**Browser Console**:

```javascript
// Log all AJAX requests
jQuery( document ).ajaxComplete( function( event, xhr, settings ) {
    if ( settings.url.indexOf( 'admin-ajax.php' ) !== -1 ) {
        console.log( 'AJAX Complete:', settings.data, xhr.responseJSON );
    }
} );
```

**Common Issues**:

1. **Nonce Failures**: Check nonce generation and verification
2. **Permission Errors**: Verify user capabilities
3. **Invalid Response**: Check for PHP errors before wp_send_json_*
4. **Timeout**: Increase PHP max_execution_time for long operations

---

## Tool System

### Tool Registry

**Class**: `WP_MCP_AI_Tool_Registry`  
**File**: `includes/class-wp-mcp-ai-tool-registry.php`  
**Lines**: 969 lines

**Pattern**: Singleton

**Responsibilities**:
- Register tool implementations
- Discover available tools
- Filter tools by capability
- Validate tool dependencies
- Provide tool metadata

**Key Methods**:

```php
public static function get_instance()
// Get singleton instance

public function init()
// Initialize registry, load default tools

public function register_tool( $tool )
// Register a tool instance or class name

public function unregister_tool( $tool_slug )
// Remove tool from registry

public function get_tool( $tool_slug )
// Retrieve tool instance by slug

public function get_all_tools()
// Get array of all registered tools

public function get_tools_by_capability( $capability_flag )
// Filter tools by capability flag

public function get_tools_by_flow_stage( $stage )
// Filter tools by flow stage

public function load_default_tools()
// Load all built-in tools
```

**Tool Loading**:

```php
protected function load_default_tools() {
    $tool_files = glob( WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-*.php' );
    
    foreach ( $tool_files as $file ) {
        require_once $file;
        $class_name = $this->get_class_name_from_file( $file );
        
        if ( class_exists( $class_name ) ) {
            $this->register_tool( $class_name );
        }
    }
    
    // Allow third-party tool registration
    do_action( 'wp_mcp_ai_register_tools', $this );
}
```

**Hook**: `wp_mcp_ai_register_tools`
- Fired after default tools loaded
- Passes registry instance
- Allows third-party tool registration

**Example Usage**:
```php
add_action( 'wp_mcp_ai_register_tools', function( $registry ) {
    $registry->register_tool( new My_Custom_Tool() );
} );
```

### Tool Interface

**Interface**: `WP_MCP_AI_Tool_Interface`  
**File**: `includes/tools/class-wp-mcp-ai-tool-interface.php`

**Required Methods**:

```php
public function get_slug();
// Returns: string (unique identifier)

public function get_name();
// Returns: string (human-readable name)

public function get_description();
// Returns: string (what the tool does)

public function get_parameters_schema();
// Returns: array (JSON Schema for parameters)

public function execute( array $arguments = array(), array $context = array() );
// Returns: mixed|WP_Error
```

**Parameter Schema Example**:
```php
public function get_parameters_schema() {
    return array(
        'type' => 'object',
        'properties' => array(
            'title' => array(
                'type' => 'string',
                'description' => 'Post title',
            ),
            'content' => array(
                'type' => 'string',
                'description' => 'Post content (HTML allowed)',
            ),
            'status' => array(
                'type' => 'string',
                'enum' => array( 'draft', 'publish' ),
                'default' => 'draft',
            ),
        ),
        'required' => array( 'title' ),
    );
}
```

### Optional Tool Interfaces

#### Shortcuts Interface
**Interface**: `WP_MCP_AI_Tool_Shortcuts_Interface`

```php
public function get_shortcut_tasks();
// Returns: array[]|null

// Example return value:
array(
    array(
        'id' => 'create_blog_post',
        'label' => 'Create Blog Post',
        'description' => 'Quickly create a new blog post',
        'default_args' => array(
            'status' => 'draft',
            'post_type' => 'post',
        ),
    ),
)
```

#### Capability Flags Interface
**Interface**: `WP_MCP_AI_Tool_Capability_Flags_Interface`

```php
public function get_capability_flags();
// Returns: array<string>

// Example return value:
array(
    'write',
    'state-changing',
    'requires-capability',
    'idempotent',
)
```

**Standard Capability Flags**:

**Requirement Flags**:
- `requires-credentials` - Needs external API credentials
- `requires-plugin` - Needs WordPress plugin installed
- `requires-capability` - Needs specific user capability
- `requires-model` - Needs AI model specified
- `requires-vision-model` - Needs vision-capable model
- `requires-multimodal-model` - Needs multimodal model

**Operational Characteristics**:
- `read-only` - Only reads data
- `write` - Creates or modifies data
- `state-changing` - Modifies database/site state
- `reversible` - Changes can be undone
- `idempotent` - Safe to call multiple times
- `performance-impact` - May affect site performance
- `consumes-tokens` - Uses AI tokens/credits
- `model-dependent` - Behavior varies by model

**Network & Performance**:
- `local-only` - No external API calls
- `external-api` - Makes external HTTP requests
- `network-dependent` - Requires internet
- `async` - Takes significant time
- `rate-limited` - Subject to rate limits
- `deferred-result` - Result available later
- `requires-polling` - Need to poll for completion
- `supports-webhook` - Can notify via webhook
- `long-running` - May take minutes/hours
- `may-timeout` - May exceed HTTP timeout
- `background-only` - Must run in background
- `streaming-capable` - Supports streaming

**Data Characteristics**:
- `cacheable` - Results can be cached
- `non-deterministic` - Results may vary
- `pii-data` - Returns personal information
- `large-response` - May return >1MB
- `paginated` - Supports pagination
- `supports-compression` - Can compress output

#### Flow Stage Interface
**Interface**: `WP_MCP_AI_Tool_Flow_Stage_Interface`

```php
public function get_flow_stages();
// Returns: array<string>

// Possible values:
// 'anytime', 'start', 'middle', 'end'

// Example:
array( 'start', 'middle' )  // Not allowed at end
```

#### Context Restrictions Interface
**Interface**: `WP_MCP_AI_Tool_Context_Restrictions_Interface`

```php
public function is_allowed_in_context( $context );
// Returns: true|WP_Error

// Example implementation:
public function is_allowed_in_context( $context ) {
    if ( isset( $context['endpoint'] ) && $context['endpoint'] === 'chat-client' ) {
        return new WP_Error(
            'restricted',
            'This tool cannot be used from the public chat interface'
        );
    }
    return true;
}
```

### Tool Implementation Examples

#### Simple Read-Only Tool

```php
class WP_MCP_AI_Tool_Get_Posts implements 
    WP_MCP_AI_Tool_Interface,
    WP_MCP_AI_Tool_Capability_Flags_Interface {
    
    public function get_slug() {
        return 'get_posts';
    }
    
    public function get_name() {
        return 'Get Posts';
    }
    
    public function get_description() {
        return 'Retrieve WordPress posts matching criteria';
    }
    
    public function get_parameters_schema() {
        return array(
            'type' => 'object',
            'properties' => array(
                'per_page' => array(
                    'type' => 'integer',
                    'description' => 'Number of posts to return',
                    'default' => 10,
                    'minimum' => 1,
                    'maximum' => 100,
                ),
                'post_type' => array(
                    'type' => 'string',
                    'default' => 'post',
                ),
            ),
        );
    }
    
    public function execute( array $arguments = array(), array $context = array() ) {
        $per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
        $post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';
        
        $posts = get_posts( array(
            'posts_per_page' => $per_page,
            'post_type' => $post_type,
            'post_status' => 'publish',
        ) );
        
        return array(
            'posts' => array_map( function( $post ) {
                return array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'excerpt' => wp_trim_words( $post->post_content, 50 ),
                );
            }, $posts ),
            'count' => count( $posts ),
        );
    }
    
    public function get_capability_flags() {
        return array(
            'read-only',
            'local-only',
            'cacheable',
        );
    }
}
```

#### Tool with External API

```php
class WP_MCP_AI_Tool_Query_Remote_Site implements
    WP_MCP_AI_Tool_Interface,
    WP_MCP_AI_Tool_Capability_Flags_Interface {
    
    // ... interface methods ...
    
    public function execute( array $arguments = array(), array $context = array() ) {
        $url = esc_url_raw( $arguments['url'] );
        
        $response = wp_remote_get( $url, array(
            'timeout' => 30,
        ) );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body( $response );
        
        return array(
            'url' => $url,
            'status' => wp_remote_retrieve_response_code( $response ),
            'content' => wp_trim_words( $body, 500 ),
        );
    }
    
    public function get_capability_flags() {
        return array(
            'read-only',
            'external-api',
            'network-dependent',
            'may-timeout',
            'cacheable',
        );
    }
}
```

### Tool Count

**Total Tools**: 73 tool implementations

**Categories**:
- **WordPress Core** (15 tools): Posts, users, media, taxonomies
- **WooCommerce** (3 tools): Products, orders
- **JetEngine** (5 tools): CCT operations
- **External APIs** (10 tools): OpenAI, Gemini, social media
- **Security** (5 tools): Security checks, logs
- **System** (8 tools): Diagnostics, performance
- **Utilities** (27 tools): Various helper tools

---


## Admin Dashboard Architecture

### Settings Dashboard System

**Pattern**: Modular section-based architecture

**Key Components**:
1. Settings Dashboard Controller
2. Settings Registry
3. Settings Sections (14 sections)
4. Settings Renderer
5. Settings Validator
6. AJAX Handlers

### Dashboard Controller

**Class**: `WP_MCP_AI_Settings_Dashboard`  
**File**: `includes/admin/class-wp-mcp-ai-settings-dashboard.php`

**Responsibilities**:
- Register admin menu
- Handle tab navigation
- Coordinate section rendering
- Process form submissions
- Manage AJAX requests

**Menu Structure**:
```
WP oOS (Top-level menu)
├── Dashboard (Overview)
├── JetEngine Integration
├── WooCommerce Integration
├── Elementor Integration
└── Gmail Crawl Integration

Tools (WordPress menu)
└── WP oOS Diagnostic
    ├── Dashboard Diagnostic
    ├── MCP Server Diagnostic
    └── Provider Diagnostics
```

**Tabs**:
1. **Overview** - Dashboard home, quick stats
2. **General** - Basic settings, logging
3. **Providers** - AI provider configuration
4. **Authentication** - Auth0, credentials
5. **Tools** - Tool enable/disable
6. **Orchestration** - Agentic workflow settings
7. **Integrations** - Third-party plugins
8. **Security** - Security settings
9. **Performance** - Performance tuning
10. **Advanced** - Developer options

### Settings Registry

**Class**: `WP_MCP_AI_Settings_Registry`  
**File**: `includes/admin/class-wp-mcp-ai-settings-registry.php`

**Pattern**: Registry pattern

**Methods**:

```php
public static function register_section( WP_MCP_AI_Settings_Section $section )
// Register a settings section

public static function get_sections_by_tab( $tab_id )
// Get all sections for a tab (ordered by priority)

public static function get_section( $section_id )
// Get specific section by ID

public static function get_all_sections()
// Get all registered sections

public static function get_tabs()
// Get list of available tabs
```

**Section Registration** (in `settings-dashboard-init.php`):
```php
WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Overview() );
WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_General() );
// ... etc
```

### Settings Section Base Class

**Abstract Class**: `WP_MCP_AI_Settings_Section`  
**File**: `includes/admin/sections/abstract-wp-mcp-ai-settings-section.php`

**Required Methods**:

```php
abstract public function get_id();
// Returns: string (section identifier)

abstract public function get_title();
// Returns: string (section display title)

abstract public function get_tab();
// Returns: string (parent tab ID)

abstract public function get_fields();
// Returns: array (field definitions)

abstract public function render();
// Renders section HTML
```

**Optional Methods**:

```php
public function get_priority() {
    return 10;  // Lower numbers render first
}

public function get_description() {
    return '';  // Section description
}

public function validate( $input ) {
    return $input;  // Validate before save
}

public function sanitize( $input ) {
    return $input;  // Sanitize input
}
```

**Field Definition Format**:

```php
public function get_fields() {
    return array(
        'field_id' => array(
            'type' => 'text',  // Field type
            'label' => 'Field Label',
            'description' => 'Help text',
            'default' => 'default value',
            'placeholder' => 'Placeholder text',
            'required' => false,
            'sanitize_callback' => 'sanitize_text_field',
            'validation_callback' => array( $this, 'validate_field' ),
        ),
    );
}
```

**Supported Field Types**:
- `text` - Text input
- `textarea` - Textarea
- `checkbox` - Checkbox
- `select` - Dropdown select
- `radio` - Radio buttons
- `number` - Number input
- `email` - Email input
- `url` - URL input
- `password` - Password input
- `api_key` - API key (masked)
- `color` - Color picker
- `date` - Date picker
- `html` - Custom HTML
- `custom` - Custom field renderer

### Example Settings Section

```php
class WP_MCP_AI_Section_General extends WP_MCP_AI_Settings_Section {
    
    public function get_id() {
        return 'general';
    }
    
    public function get_title() {
        return __( 'General Settings', 'wp-mcp-ai' );
    }
    
    public function get_tab() {
        return 'general';
    }
    
    public function get_description() {
        return __( 'Configure basic plugin settings', 'wp-mcp-ai' );
    }
    
    public function get_fields() {
        return array(
            'enable_logging' => array(
                'type' => 'checkbox',
                'label' => __( 'Enable Logging', 'wp-mcp-ai' ),
                'description' => __( 'Log plugin activity for debugging', 'wp-mcp-ai' ),
                'default' => false,
            ),
            'log_level' => array(
                'type' => 'select',
                'label' => __( 'Log Level', 'wp-mcp-ai' ),
                'options' => array(
                    'error' => __( 'Errors Only', 'wp-mcp-ai' ),
                    'warning' => __( 'Warnings and Errors', 'wp-mcp-ai' ),
                    'info' => __( 'Info, Warnings, and Errors', 'wp-mcp-ai' ),
                    'debug' => __( 'All Messages', 'wp-mcp-ai' ),
                ),
                'default' => 'error',
            ),
        );
    }
    
    public function render() {
        // Renderer handles actual HTML output
        // This method can add custom content before/after fields
    }
    
    public function validate( $input ) {
        // Custom validation logic
        if ( isset( $input['log_level'] ) ) {
            $valid_levels = array( 'error', 'warning', 'info', 'debug' );
            if ( ! in_array( $input['log_level'], $valid_levels, true ) ) {
                add_settings_error(
                    'wp_mcp_ai_settings',
                    'invalid_log_level',
                    __( 'Invalid log level selected', 'wp-mcp-ai' )
                );
                $input['log_level'] = 'error';
            }
        }
        
        return $input;
    }
}
```

### Settings Renderer

**Class**: `WP_MCP_AI_Admin_Settings_Renderer`  
**File**: `includes/admin/class-wp-mcp-ai-admin-settings-renderer.php`

**Responsibilities**:
- Render settings page HTML
- Render tab navigation
- Render section fields
- Handle field-specific rendering

**Key Methods**:

```php
public function render_page()
// Render entire settings page

public function render_tabs( $active_tab )
// Render tab navigation

public function render_section( $section )
// Render individual section

public function render_field( $field_id, $field_config, $value )
// Render individual field
```

### AJAX Handlers

**Class**: `WP_MCP_AI_Admin_AJAX_Handlers`  
**File**: `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php`

**AJAX Actions**:

```php
// Test AI provider connection
wp_ajax_wp_mcp_ai_test_provider

// Generate assistant credential
wp_ajax_wp_mcp_ai_generate_credential

// Revoke credential
wp_ajax_wp_mcp_ai_revoke_credential

// Test tool execution
wp_ajax_wp_mcp_ai_test_tool

// Fetch system diagnostics
wp_ajax_wp_mcp_ai_get_diagnostics

// Clear cache
wp_ajax_wp_mcp_ai_clear_cache
```

**Example Handler**:

```php
public function handle_test_provider() {
    check_ajax_referer( 'wp_mcp_ai_admin_nonce', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    $provider = sanitize_text_field( $_POST['provider'] );
    $api_key = sanitize_text_field( $_POST['api_key'] );
    
    // Test connection
    $result = $this->test_provider_connection( $provider, $api_key );
    
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array(
            'message' => $result->get_error_message(),
        ) );
    }
    
    wp_send_json_success( array(
        'message' => 'Connection successful',
        'details' => $result,
    ) );
}
```

---

## AI Client Abstraction

### Language Model Router

**Class**: `WP_MCP_AI_Language_Model_Router`  
**File**: `includes/class-wp-mcp-ai-language-model-router.php`  
**Lines**: 169 lines

**Pattern**: Factory pattern

**Responsibilities**:
- Instantiate appropriate AI client
- Route requests to correct provider
- Abstract provider differences
- Provide unified interface

**Supported Providers**:
1. **OpenAI** - GPT models
2. **Google Gemini** - Gemini models
3. **Ollama** - Local AI models
4. **LM Studio** - Local AI models
5. **Anthropic** - Claude models

**Methods**:

```php
public function get_client( $provider, $settings = array() )
// Factory method to create client instance

public function chat( $provider, $messages, $settings = array() )
// Send chat request to provider

public function stream_chat( $provider, $messages, $settings = array() )
// Stream chat response from provider

public function count_tokens( $provider, $text )
// Count tokens for provider

public function get_available_models( $provider )
// List models available for provider
```

**Usage Example**:

```php
$router = wp_mcp_ai_get_language_model_router();

$messages = array(
    array( 'role' => 'user', 'content' => 'Hello!' ),
);

$response = $router->chat( 'openai', $messages, array(
    'model' => 'gpt-4',
    'temperature' => 0.7,
    'max_tokens' => 1000,
) );
```

### AI Client Interface

**Common Methods** (implemented by all clients):

```php
public function chat( $messages, $settings = array() )
// Send chat request
// Returns: array (chat completion response)

public function stream_chat( $messages, $settings = array() )
// Stream chat response
// Yields: chunks via generator

public function count_tokens( $text )
// Count tokens in text
// Returns: int

public function get_available_models()
// List available models
// Returns: array of model IDs

public function validate_settings( $settings )
// Validate configuration
// Returns: true|WP_Error
```

### OpenAI Client

**Class**: `WP_MCP_AI_OpenAI_Client`  
**File**: `includes/class-wp-mcp-ai-openai-client.php`

**API Endpoint**: `https://api.openai.com/v1/chat/completions`

**Supported Models**:
- `gpt-4-turbo-preview`
- `gpt-4`
- `gpt-4-32k`
- `gpt-3.5-turbo`
- `gpt-3.5-turbo-16k`

**Features**:
- Function calling (tool use)
- Vision capabilities (image input)
- JSON mode
- Streaming responses
- Token counting via tiktoken

**Settings**:

```php
array(
    'api_key' => string,         // Required: OpenAI API key
    'model' => string,           // Required: Model ID
    'temperature' => float,      // Optional: 0-2 (default: 0.7)
    'max_tokens' => int,         // Optional: Max response tokens
    'top_p' => float,            // Optional: 0-1
    'frequency_penalty' => float,// Optional: -2 to 2
    'presence_penalty' => float, // Optional: -2 to 2
    'tools' => array,            // Optional: Function definitions
    'tool_choice' => string,     // Optional: 'auto', 'none', or specific
)
```

### Gemini Client

**Class**: `WP_MCP_AI_Gemini_Client`  
**File**: `includes/class-wp-mcp-ai-gemini-client.php`

**API Endpoint**: `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`

**Supported Models**:
- `gemini-pro`
- `gemini-pro-vision`
- `gemini-ultra`

**Features**:
- Multimodal input (text, images)
- Function calling
- Safety settings
- Content filtering

**Settings**:

```php
array(
    'api_key' => string,         // Required: Google API key
    'model' => string,           // Required: Model ID
    'temperature' => float,      // Optional: 0-1
    'max_output_tokens' => int,  // Optional: Max response tokens
    'top_p' => float,            // Optional: 0-1
    'top_k' => int,              // Optional: Top-k sampling
    'safety_settings' => array,  // Optional: Content safety
)
```

### Ollama Client

**Class**: `WP_MCP_AI_Ollama_Client`  
**File**: `includes/class-wp-mcp-ai-ollama-client.php`

**API Endpoint**: `{base_url}/api/chat` (configurable)

**Purpose**: Run AI models locally

**Supported Models**: Any model available in Ollama
- `llama2`
- `mistral`
- `codellama`
- `phi`
- Many others

**Features**:
- Local inference (no API key needed)
- Privacy (data stays local)
- No rate limits
- Custom models

**Settings**:

```php
array(
    'base_url' => string,        // Required: Ollama server URL
    'model' => string,           // Required: Model name
    'temperature' => float,      // Optional: 0-2
    'num_predict' => int,        // Optional: Max tokens
    'top_p' => float,            // Optional: 0-1
    'top_k' => int,              // Optional: Top-k sampling
)
```

### Anthropic Client

**Class**: `WP_MCP_AI_Anthropic_Client`  
**File**: `includes/class-wp-mcp-ai-anthropic-client.php`

**API Endpoint**: `https://api.anthropic.com/v1/messages`

**Supported Models**:
- `claude-3-opus-20240229`
- `claude-3-sonnet-20240229`
- `claude-3-haiku-20240307`
- `claude-2.1`
- `claude-2.0`

**Features**:
- Long context windows (up to 200K tokens)
- Tool use (function calling)
- Streaming responses
- System prompts

**Settings**:

```php
array(
    'api_key' => string,         // Required: Anthropic API key
    'model' => string,           // Required: Model ID
    'max_tokens' => int,         // Required: Max response tokens
    'temperature' => float,      // Optional: 0-1
    'top_p' => float,            // Optional: 0-1
    'top_k' => int,              // Optional: Top-k sampling
    'system' => string,          // Optional: System prompt
)
```

---

## Authentication System

### Overview

WP oOS supports multiple authentication methods to accommodate different use cases:

1. **WordPress Nonce** - Standard WordPress authentication for logged-in users
2. **Assistant Credentials** - Plugin-issued bearer tokens for API access
3. **Auth0 JWT** - Enterprise SSO integration
4. **Guest Tokens** - Temporary tokens for public chat interfaces

### Credential System

**Class**: `WP_MCP_AI_Credentials`  
**File**: `includes/class-wp-mcp-ai-credentials.php`

**Purpose**: Generate and verify assistant-specific access credentials

**Token Format**: `cred_{CREDENTIAL_ID}.{SECRET}`

**Example**: `cred_abc123.xyz789secrettoken`

**Methods**:

```php
public static function generate( $assistant_id, $label = '' )
// Generate new credential
// Returns: array( 'credential_id', 'token', 'prefix' )

public static function verify( $token )
// Verify credential token
// Returns: array|false with assistant_id and credential_id

public static function revoke( $assistant_id, $credential_id )
// Revoke credential
// Returns: bool

public static function list_credentials( $assistant_id )
// List all credentials for assistant
// Returns: array of credential objects

public static function update_last_used( $assistant_id, $credential_id )
// Update last used timestamp
// Returns: bool
```

**Generation Process**:

1. Generate cryptographically secure random string
2. Create credential ID (short identifier)
3. Hash full token with `password_hash()`
4. Store only hash and prefix in database
5. Return plaintext token (shown only once)

**Verification Process**:

1. Parse token to extract credential ID and secret
2. Look up credential by ID
3. Verify hash matches using `password_verify()`
4. Check if credential is revoked
5. Update last used timestamp
6. Return assistant context if valid

**Storage**:

Stored in post meta: `_wp_mcp_ai_credentials`

```php
array(
    array(
        'id' => 'abc123',
        'label' => 'API Access for App X',
        'prefix' => 'cred_abc123',
        'hash' => '$2y$10$...', // bcrypt hash
        'created_at' => 1234567890,
        'last_used' => 1234567890,
        'revoked' => false,
    ),
)
```

### Auth0 Integration

**Purpose**: Enterprise SSO via Auth0

**Setup**:
1. Create Auth0 application
2. Configure callback URLs
3. Add Auth0 credentials to plugin settings
4. Map Auth0 users to WordPress users

**Configuration**:

```php
array(
    'enable_auth0' => true,
    'auth0_domain' => 'your-tenant.auth0.com',
    'auth0_client_id' => 'client_id',
    'auth0_client_secret' => 'client_secret',
    'auth0_redirect_uri' => home_url( '/wp-json/mcp-ai/v1/auth0/callback' ),
)
```

**Flow**:
1. User initiates login
2. Redirect to Auth0
3. User authenticates with Auth0
4. Auth0 redirects back with JWT
5. Plugin validates JWT
6. Create/link WordPress user
7. Issue WordPress session

### Guest Tokens

**Purpose**: Allow public access to specific assistants

**Use Case**: Public chat interfaces on websites

**Generation**:

```php
$guest_token = wp_mcp_ai_generate_guest_token( $assistant_id, array(
    'expires_in' => 86400,  // 24 hours
    'rate_limit' => 10,     // 10 requests per minute
) );
```

**Token Format**: JWT with claims:

```json
{
    "iss": "wp-mcp-ai",
    "sub": "guest",
    "assistant_id": 123,
    "exp": 1234567890,
    "rate_limit": 10
}
```

**Verification**:

1. Decode JWT
2. Verify signature
3. Check expiration
4. Validate assistant ID
5. Apply rate limits
6. Return guest context

---

## Hooks and Filters

### Actions

**Plugin Initialization**:

```php
do_action( 'wp_mcp_ai_loaded' );
// Fired after plugin fully loaded
// Use for late initialization
```

```php
do_action( 'wp_mcp_ai_register_tools', $registry );
// Fired during tool registry initialization
// Use to register custom tools
// @param WP_MCP_AI_Tool_Registry $registry
```

**Chat Events**:

```php
do_action( 'wp_mcp_ai_before_chat', $messages, $assistant_id, $context );
// Fired before processing chat request
// @param array $messages
// @param int $assistant_id
// @param array $context
```

```php
do_action( 'wp_mcp_ai_after_chat', $response, $messages, $assistant_id, $context );
// Fired after chat completion
// @param array $response
// @param array $messages
// @param int $assistant_id
// @param array $context
```

```php
do_action( 'wp_mcp_ai_chat_error', $error, $messages, $assistant_id, $context );
// Fired when chat fails
// @param WP_Error $error
// @param array $messages
// @param int $assistant_id
// @param array $context
```

**Tool Events**:

```php
do_action( 'wp_mcp_ai_before_tool_execute', $tool_slug, $arguments, $context );
// Fired before tool execution
// @param string $tool_slug
// @param array $arguments
// @param array $context
```

```php
do_action( 'wp_mcp_ai_after_tool_execute', $result, $tool_slug, $arguments, $context );
// Fired after tool execution
// @param mixed $result
// @param string $tool_slug
// @param array $arguments
// @param array $context
```

**Settings Events**:

```php
do_action( 'wp_mcp_ai_settings_saved', $settings, $tab, $section );
// Fired after settings saved
// @param array $settings
// @param string $tab
// @param string $section
```

### Filters

**Capability Checks**:

```php
apply_filters( 'wp_mcp_ai_chat_capability', 'edit_posts', $assistant_id, $context );
// Filter required capability for chat access
// Return 'public' to allow any visitor
// @param string $capability
// @param int $assistant_id
// @param string $context
// @return string|false
```

**Tool Filtering**:

```php
apply_filters( 'wp_mcp_ai_available_tools', $tools, $assistant_id );
// Filter available tools for assistant
// @param array $tools Array of tool objects
// @param int $assistant_id
// @return array
```

```php
apply_filters( 'wp_mcp_ai_tool_arguments', $arguments, $tool_slug, $context );
// Modify tool arguments before execution
// @param array $arguments
// @param string $tool_slug
// @param array $context
// @return array
```

**Chat Modifications**:

```php
apply_filters( 'wp_mcp_ai_chat_messages', $messages, $assistant_id, $context );
// Modify messages before sending to AI
// @param array $messages
// @param int $assistant_id
// @param array $context
// @return array
```

```php
apply_filters( 'wp_mcp_ai_chat_response', $response, $messages, $assistant_id, $context );
// Modify AI response before returning
// @param array $response
// @param array $messages
// @param int $assistant_id
// @param array $context
// @return array
```

**Settings Filters**:

```php
apply_filters( 'wp_mcp_ai_default_settings', $defaults );
// Filter default settings
// @param array $defaults
// @return array
```

```php
apply_filters( 'wp_mcp_ai_sanitize_settings', $settings, $section );
// Filter sanitized settings
// @param array $settings
// @param string $section
// @return array
```

**Rate Limiting**:

```php
apply_filters( 'wp_mcp_ai_rate_limit', $limit, $user_id, $assistant_id );
// Filter rate limit for user
// @param int $limit Requests per minute
// @param int $user_id
// @param int $assistant_id
// @return int
```

**Crawl4AI Integration**:

```php
apply_filters( 'wp_mcp_ai_crawl4ai_base_url', $base_url, $settings, $context );
// Filter Crawl4AI base URL
// @param string $base_url
// @param array $settings
// @param array $context
// @return string
```

---


## Constants and Configuration

### Plugin Constants

**Core Constants** (defined in `mcp-ai-wpoos.php`):

```php
WP_MCP_AI_VERSION        // Plugin version: '1.0.0'
WP_MCP_AI_PATH           // Absolute filesystem path to plugin directory
WP_MCP_AI_URL            // URL to plugin directory
```

**Configuration Constants** (optionally defined in `wp-config.php`):

```php
WP_MCP_AI_BASE_VERSION   // Boolean: Enable base version mode (default: true)
                         // Base version excludes third-party integrations
                         // Set to false for full version with all features

WP_MCP_AI_USE_OLD_SETTINGS  // Boolean: Use legacy settings page (default: false)
                            // Set to true to use old monolithic settings

WP_MCP_AI_DEBUG          // Boolean: Enable debug mode (default: false)
                         // Increases logging verbosity

WP_MCP_AI_CRAWL4AI_BASE_URL  // String: Crawl4AI server URL
                              // Overrides settings page value
```

**Environment Variables** (can be used instead of constants):

```bash
WP_MCP_AI_CRAWL4AI_BASE_URL="http://localhost:11235"
CRAWL4AI_BASE_URL="http://localhost:11235"
```

### WordPress Options

All plugin settings stored with `wp_mcp_ai_` prefix:

**Provider Keys**:
- `wp_mcp_ai_openai_api_key`
- `wp_mcp_ai_gemini_api_key`
- `wp_mcp_ai_anthropic_api_key`
- `wp_mcp_ai_ollama_base_url`

**Provider Models**:
- `wp_mcp_ai_openai_model`
- `wp_mcp_ai_gemini_model`
- `wp_mcp_ai_anthropic_model`
- `wp_mcp_ai_ollama_model`

**General Settings**:
- `wp_mcp_ai_enable_logging`
- `wp_mcp_ai_log_level`
- `wp_mcp_ai_enable_caching`
- `wp_mcp_ai_cache_ttl`
- `wp_mcp_ai_default_temperature`
- `wp_mcp_ai_default_max_tokens`

**Security Settings**:
- `wp_mcp_ai_enable_rate_limiting`
- `wp_mcp_ai_rate_limit_per_minute`
- `wp_mcp_ai_rate_limit_per_hour`
- `wp_mcp_ai_enable_auth0`
- `wp_mcp_ai_auth0_domain`
- `wp_mcp_ai_auth0_client_id`
- `wp_mcp_ai_auth0_client_secret`

**Integration Settings**:
- `wp_mcp_ai_jetengine_sync_enabled`
- `wp_mcp_ai_jetengine_cct_slug`
- `wp_mcp_ai_woocommerce_enabled`
- `wp_mcp_ai_elementor_enabled`

**Logging Options**:
- `wp_mcp_ai_recent_errors` - Array of recent error logs
- `wp_mcp_ai_recent_activity` - Array of recent activity logs

---

## Error Handling and Logging

### Logger

**Class**: `WP_MCP_AI_Logger`  
**File**: `includes/class-wp-mcp-ai-logger.php`

**Pattern**: Singleton

**Log Levels**:
1. `DEBUG` - Detailed debugging information
2. `INFO` - Informational messages
3. `WARNING` - Warning messages
4. `ERROR` - Error messages

**Methods**:

```php
public static function debug( $message, $context = array() )
public static function info( $message, $context = array() )
public static function warning( $message, $context = array() )
public static function error( $message, $context = array() )
```

**Usage**:

```php
WP_MCP_AI_Logger::info( 'Chat request processed', array(
    'assistant_id' => 123,
    'tokens_used' => 150,
) );

WP_MCP_AI_Logger::error( 'OpenAI API error', array(
    'error_code' => 'rate_limit_exceeded',
    'retry_after' => 60,
) );
```

**Storage**:

Logs stored in WordPress options:
- `wp_mcp_ai_recent_errors` - Last 100 error messages
- `wp_mcp_ai_recent_activity` - Last 100 info/debug messages

**Retrieval**:

```php
$errors = get_option( 'wp_mcp_ai_recent_errors', array() );
$activity = get_option( 'wp_mcp_ai_recent_activity', array() );
```

Via WP-CLI:

```bash
wp option get wp_mcp_ai_recent_errors --format=json
wp option get wp_mcp_ai_recent_activity --format=json
```

### Error Handler

**Class**: `WP_MCP_AI_Error_Handler`  
**File**: `includes/class-wp-mcp-ai-error-handler.php`

**Responsibilities**:
- Catch and format exceptions
- Provide user-friendly error messages
- Log errors for debugging
- Prevent information leakage

**Methods**:

```php
public static function handle_exception( Exception $e, $context = array() )
// Handle exception, log it, return WP_Error

public static function handle_wp_error( WP_Error $error, $context = array() )
// Format and log WP_Error

public static function create_user_friendly_error( $error_code, $technical_message )
// Create user-safe error message
```

**Error Codes**:

```php
// Authentication errors
'unauthorized'           // No authentication provided
'invalid_credentials'    // Invalid API credentials
'expired_token'         // Token expired

// Validation errors
'invalid_parameters'     // Invalid request parameters
'missing_required_field' // Required field missing
'invalid_format'        // Wrong data format

// Resource errors
'assistant_not_found'    // Assistant doesn't exist
'tool_not_found'        // Tool doesn't exist
'model_not_available'   // AI model unavailable

// Rate limiting errors
'rate_limit_exceeded'    // Too many requests
'quota_exceeded'        // Usage quota exceeded

// Provider errors
'provider_error'         // AI provider error
'network_error'         // Network request failed
'timeout'               // Request timed out

// System errors
'internal_error'        // Unexpected internal error
'insufficient_permissions' // User lacks permissions
```

**Error Response Format**:

```php
array(
    'error' => array(
        'code' => 'error_code',
        'message' => 'User-friendly error message',
        'details' => array(
            'technical_message' => 'Detailed technical info (only in debug mode)',
            'timestamp' => 1234567890,
            'request_id' => 'unique-request-id',
        ),
    ),
)
```

### Error Handling Patterns

**Pattern 1: Service Layer Error Handling**

Services should return `WP_Error` objects for errors:

```php
class WP_MCP_AI_Assistant_Service {
    public function create_assistant( $data ) {
        // Validate input
        if ( empty( $data['name'] ) ) {
            return new WP_Error(
                'missing_required_field',
                __( 'Assistant name is required', 'wp-mcp-ai' ),
                array( 'field' => 'name' )
            );
        }
        
        // Attempt operation
        $result = $this->assistant_repository->save( $data );
        
        // Check for errors from repository
        if ( is_wp_error( $result ) ) {
            WP_MCP_AI_Logger::error( 'Failed to create assistant', array(
                'error' => $result->get_error_message(),
                'data' => $data,
            ) );
            
            return $result;
        }
        
        // Success - return data
        WP_MCP_AI_Logger::info( 'Assistant created', array(
            'assistant_id' => $result,
        ) );
        
        return $result;
    }
}
```

**Pattern 2: REST API Error Handling**

REST controllers should handle errors and return appropriate HTTP status codes:

```php
public function handle_chat_request( $request ) {
    // Validate request
    $validation = $this->validator->validate_chat_request( $request );
    
    if ( is_wp_error( $validation ) ) {
        WP_MCP_AI_Logger::warning( 'Invalid chat request', array(
            'error' => $validation->get_error_message(),
        ) );
        
        return new WP_Error(
            $validation->get_error_code(),
            $validation->get_error_message(),
            array( 'status' => 400 )  // Bad Request
        );
    }
    
    // Process with service
    $result = $this->chat_service->process_chat(
        $request['messages'],
        $request['assistant_id']
    );
    
    // Handle service errors
    if ( is_wp_error( $result ) ) {
        $error_code = $result->get_error_code();
        
        // Map error codes to HTTP status codes
        $status_map = array(
            'unauthorized' => 401,
            'insufficient_permissions' => 403,
            'assistant_not_found' => 404,
            'rate_limit_exceeded' => 429,
            'provider_error' => 502,
            'timeout' => 504,
        );
        
        $status = isset( $status_map[ $error_code ] ) ? $status_map[ $error_code ] : 500;
        
        WP_MCP_AI_Logger::error( 'Chat request failed', array(
            'error_code' => $error_code,
            'error_message' => $result->get_error_message(),
            'status' => $status,
        ) );
        
        return new WP_Error(
            $error_code,
            $result->get_error_message(),
            array( 'status' => $status )
        );
    }
    
    // Success
    return rest_ensure_response( $result );
}
```

**Pattern 3: Tool Execution Error Handling**

Tools should handle errors gracefully and provide actionable error messages:

```php
public function execute( array $arguments = array(), array $context = array() ) {
    try {
        // Validate parameters
        if ( ! isset( $arguments['post_id'] ) ) {
            return new WP_Error(
                'missing_parameter',
                __( 'Post ID is required', 'wp-mcp-ai' ),
                array( 'parameter' => 'post_id' )
            );
        }
        
        $post_id = absint( $arguments['post_id'] );
        
        // Check if post exists
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error(
                'post_not_found',
                sprintf( __( 'Post with ID %d not found', 'wp-mcp-ai' ), $post_id )
            );
        }
        
        // Check permissions
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_Error(
                'insufficient_permissions',
                __( 'You do not have permission to edit this post', 'wp-mcp-ai' )
            );
        }
        
        // Perform operation
        $result = wp_update_post( array(
            'ID' => $post_id,
            'post_title' => sanitize_text_field( $arguments['title'] ),
        ) );
        
        // Check for WordPress errors
        if ( is_wp_error( $result ) ) {
            WP_MCP_AI_Logger::error( 'Failed to update post', array(
                'post_id' => $post_id,
                'error' => $result->get_error_message(),
            ) );
            
            return $result;
        }
        
        // Success
        return array(
            'success' => true,
            'post_id' => $post_id,
            'message' => __( 'Post updated successfully', 'wp-mcp-ai' ),
        );
        
    } catch ( Exception $e ) {
        // Catch unexpected exceptions
        WP_MCP_AI_Logger::error( 'Tool execution exception', array(
            'tool' => $this->get_slug(),
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ) );
        
        return new WP_Error(
            'tool_execution_error',
            __( 'An unexpected error occurred while executing the tool', 'wp-mcp-ai' )
        );
    }
}
```

**Pattern 4: AJAX Error Handling**

AJAX handlers should use standardized response methods:

```php
public function handle_ajax_action() {
    try {
        // Nonce verification
        check_ajax_referer( 'wp_mcp_ai_admin_nonce', 'nonce' );
        
        // Capability check
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array(
                'message' => __( 'Insufficient permissions', 'wp-mcp-ai' ),
                'code' => 'insufficient_permissions',
            ) );
        }
        
        // Process request
        $result = $this->perform_action();
        
        // Check for errors
        if ( is_wp_error( $result ) ) {
            WP_MCP_AI_Logger::error( 'AJAX action failed', array(
                'action' => 'ajax_action',
                'error' => $result->get_error_message(),
            ) );
            
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
            ) );
        }
        
        // Success
        wp_send_json_success( array(
            'message' => __( 'Action completed successfully', 'wp-mcp-ai' ),
            'data' => $result,
        ) );
        
    } catch ( Exception $e ) {
        WP_MCP_AI_Logger::error( 'AJAX exception', array(
            'exception' => $e->getMessage(),
        ) );
        
        wp_send_json_error( array(
            'message' => __( 'An unexpected error occurred', 'wp-mcp-ai' ),
            'code' => 'internal_error',
        ) );
    }
}
```

### Error Logging Best Practices

**1. Log Appropriate Level**:

```php
// DEBUG - Detailed info for troubleshooting
WP_MCP_AI_Logger::debug( 'API request details', array(
    'endpoint' => $endpoint,
    'parameters' => $params,
) );

// INFO - Normal operations
WP_MCP_AI_Logger::info( 'Chat completed', array(
    'tokens_used' => 150,
) );

// WARNING - Recoverable issues
WP_MCP_AI_Logger::warning( 'API rate limit approaching', array(
    'usage' => 90,
    'limit' => 100,
) );

// ERROR - Failures that need attention
WP_MCP_AI_Logger::error( 'API call failed', array(
    'error' => $error_message,
    'retries' => 3,
) );
```

**2. Include Context**:

```php
WP_MCP_AI_Logger::error( 'Tool execution failed', array(
    'tool' => 'save_post',
    'post_id' => 123,
    'user_id' => 456,
    'error' => $error_message,
    'timestamp' => time(),
) );
```

**3. Don't Log Sensitive Data**:

```php
// BAD - Logs API key
WP_MCP_AI_Logger::debug( 'API request', array(
    'api_key' => $api_key,  // Never log credentials!
) );

// GOOD - Mask sensitive data
WP_MCP_AI_Logger::debug( 'API request', array(
    'api_key' => substr( $api_key, 0, 8 ) . '...',
) );
```

### Error Monitoring

**Enable Debug Mode**:

```php
// In wp-config.php
define( 'WP_MCP_AI_DEBUG', true );
```

**Check Error Logs**:

```bash
# Via WP-CLI
wp option get wp_mcp_ai_recent_errors --format=json | jq .

# View specific error
wp option get wp_mcp_ai_recent_errors --format=json | jq '.[0]'

# Count errors
wp option get wp_mcp_ai_recent_errors --format=json | jq 'length'
```

**Clear Error Logs**:

```bash
# Clear errors
wp option delete wp_mcp_ai_recent_errors

# Clear activity logs
wp option delete wp_mcp_ai_recent_activity
```

### Exception Handling

**Global Exception Handler**:

The plugin includes a global exception handler for catching unexpected errors:

```php
class WP_MCP_AI_Error_Handler {
    public static function handle_exception( Exception $e, $context = array() ) {
        // Log exception
        WP_MCP_AI_Logger::error( 'Uncaught exception', array(
            'exception' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'context' => $context,
        ) );
        
        // Create user-friendly error
        return new WP_Error(
            'internal_error',
            __( 'An unexpected error occurred. Please try again.', 'wp-mcp-ai' ),
            array(
                'exception' => $e->getMessage(),
                'debug' => WP_DEBUG,
            )
        );
    }
}
```

**Usage**:

```php
try {
    $result = $risky_operation();
} catch ( Exception $e ) {
    return WP_MCP_AI_Error_Handler::handle_exception( $e, array(
        'operation' => 'risky_operation',
    ) );
}
```

---

## Security Architecture

### Security Principles

1. **Input Validation** - All input sanitized and validated
2. **Output Escaping** - All output escaped for context
3. **Capability Checks** - Granular permission system
4. **Nonce Verification** - CSRF protection on all forms
5. **SQL Injection Prevention** - Use prepared statements
6. **XSS Prevention** - Escape all user content
7. **File Upload Security** - MIME type and extension validation
8. **API Key Protection** - Encrypted storage, masked display
9. **Rate Limiting** - Prevent abuse
10. **Audit Logging** - Track sensitive operations

### Input Sanitization

**Text Fields**:
```php
$title = sanitize_text_field( $_POST['title'] );
$content = wp_kses_post( $_POST['content'] );
```

**Numeric Values**:
```php
$post_id = absint( $_POST['post_id'] );
$temperature = floatval( $_POST['temperature'] );
```

**URLs**:
```php
$url = esc_url_raw( $_POST['url'] );
```

**Email**:
```php
$email = sanitize_email( $_POST['email'] );
```

**Arrays**:
```php
$values = array_map( 'sanitize_text_field', $_POST['values'] );
```

### Output Escaping

**HTML Context**:
```php
echo esc_html( $user_input );
```

**Attribute Context**:
```php
<input value="<?php echo esc_attr( $value ); ?>">
```

**URL Context**:
```php
<a href="<?php echo esc_url( $link ); ?>">
```

**JavaScript Context**:
```php
<script>
var data = <?php echo wp_json_encode( $data ); ?>;
</script>
```

### Capability Checks

**WordPress Capabilities Used**:

```php
'manage_options'      // Admin settings access
'edit_posts'          // Default chat access
'publish_posts'       // Create content via tools
'edit_others_posts'   // Modify others' content
'delete_posts'        // Delete content via tools
'upload_files'        // File uploads
```

**Custom Capability Filtering**:

```php
$capability = apply_filters(
    'wp_mcp_ai_chat_capability',
    'edit_posts',
    $assistant_id,
    'rest'
);

if ( ! current_user_can( $capability ) ) {
    return new WP_Error( 'insufficient_permissions', 'Access denied' );
}
```

### Nonce Verification

**AJAX Requests**:
```php
check_ajax_referer( 'wp_mcp_ai_admin_nonce', 'nonce' );
```

**Form Submissions**:
```php
if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'wp_mcp_ai_settings' ) ) {
    wp_die( 'Security check failed' );
}
```

**REST API**:
```php
// WordPress nonce automatically verified by REST API framework
// For custom verification:
$nonce = $request->get_header( 'X-WP-Nonce' );
if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
    return new WP_Error( 'invalid_nonce', 'Nonce verification failed' );
}
```

### File Upload Security

**MIME Type Validation**:
```php
$allowed_types = array(
    'image/jpeg',
    'image/png',
    'image/gif',
    'application/pdf',
);

$file_type = wp_check_filetype( $filename, $allowed_types );

if ( ! in_array( $file_type['type'], $allowed_types, true ) ) {
    return new WP_Error( 'invalid_file_type', 'File type not allowed' );
}
```

**File Size Validation**:
```php
$max_size = 5 * 1024 * 1024; // 5MB

if ( $file_size > $max_size ) {
    return new WP_Error( 'file_too_large', 'File exceeds maximum size' );
}
```

**Extension Validation**:
```php
$allowed_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'pdf' );
$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

if ( ! in_array( $extension, $allowed_extensions, true ) ) {
    return new WP_Error( 'invalid_extension', 'File extension not allowed' );
}
```

### API Key Storage

**Storage**:
- Never stored in plaintext in database
- Option values are base64 encoded
- Access restricted to administrators
- Displayed masked in UI (e.g., `sk-...xyz`)

**Retrieval**:
```php
$api_key = get_option( 'wp_mcp_ai_openai_api_key' );
$api_key = base64_decode( $api_key );
```

**Display**:
```php
public static function mask_api_key( $key ) {
    if ( empty( $key ) ) {
        return '';
    }
    
    $length = strlen( $key );
    
    if ( $length <= 8 ) {
        return str_repeat( '*', $length );
    }
    
    $prefix = substr( $key, 0, 4 );
    $suffix = substr( $key, -4 );
    
    return $prefix . str_repeat( '*', $length - 8 ) . $suffix;
}
```

### Rate Limiting

**Class**: `WP_MCP_AI_Rate_Limit_Manager`

**Limits**:
- Per user, per assistant
- Requests per minute
- Requests per hour
- Requests per day

**Storage**: WordPress transients

**Implementation**:
```php
public function check_limit( $user_id, $assistant_id ) {
    $key = "wp_mcp_ai_rate_limit_{$user_id}_{$assistant_id}";
    $count = get_transient( $key );
    
    $limit = apply_filters( 'wp_mcp_ai_rate_limit', 10, $user_id, $assistant_id );
    
    if ( false === $count ) {
        set_transient( $key, 1, MINUTE_IN_SECONDS );
        return true;
    }
    
    if ( $count >= $limit ) {
        return new WP_Error( 'rate_limit_exceeded', 'Too many requests' );
    }
    
    set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
    return true;
}
```

---

## Data Flow Diagrams

### Chat Request Flow

```
1. Client Request
   ├─> WordPress REST API
   │   └─> /wp-json/mcp-ai/v1/chat
   │
2. Authentication
   ├─> WP_MCP_AI_REST_Authenticator
   │   ├─> Nonce verification
   │   ├─> Credential verification
   │   └─> Auth0 JWT verification
   │
3. Validation
   ├─> WP_MCP_AI_REST_Validator
   │   ├─> Validate assistant_id
   │   ├─> Validate messages
   │   └─> Validate parameters
   │
4. Rate Limiting
   ├─> WP_MCP_AI_Rate_Limit_Manager
   │   └─> Check user rate limits
   │
5. Service Layer
   ├─> WP_MCP_AI_Chat_Service
   │   ├─> Retrieve assistant configuration
   │   ├─> Apply token budgets
   │   └─> Prepare request
   │
6. AI Provider
   ├─> WP_MCP_AI_Language_Model_Router
   │   ├─> Select provider client
   │   │   ├─> OpenAI Client
   │   │   ├─> Gemini Client
   │   │   ├─> Ollama Client
   │   │   └─> Anthropic Client
   │   │
   │   └─> Send chat request
   │
7. Tool Execution (if requested)
   ├─> WP_MCP_AI_Tool_Service
   │   ├─> Validate tool parameters
   │   ├─> Execute tool
   │   └─> Return tool result
   │
8. Response Processing
   ├─> Format response
   ├─> Log conversation (if enabled)
   └─> Return to client
```

### Tool Execution Flow

```
1. Tool Call
   ├─> From AI response (during chat)
   │   └─> Embedded in chat completion
   │
   OR
   │
   └─> Direct REST API call
       └─> POST /wp-json/mcp-ai/v1/tools/execute
       │
2. Authentication & Authorization
   ├─> Verify user authentication
   └─> Check tool permissions
   │
3. Tool Discovery
   ├─> WP_MCP_AI_Tool_Registry
   └─> Retrieve tool instance
   │
4. Capability Check
   ├─> Check capability flags
   ├─> Check flow stage eligibility
   └─> Check context restrictions
   │
5. Parameter Validation
   ├─> Validate against JSON schema
   ├─> Sanitize inputs
   └─> Check required fields
   │
6. Tool Execution
   ├─> WP_MCP_AI_Tool_Interface::execute()
   │   ├─> Perform operations
   │   ├─> Access WordPress APIs
   │   ├─> Call external APIs (if needed)
   │   └─> Return result
   │
7. Result Processing
   ├─> Format result
   ├─> Log execution
   └─> Return to caller
```

### Settings Save Flow

```
1. Form Submission
   ├─> Admin settings page
   └─> POST request
   │
2. AJAX Handler
   ├─> WP_MCP_AI_Admin_AJAX_Handlers
   └─> check_ajax_referer()
   │
3. Capability Check
   └─> current_user_can( 'manage_options' )
   │
4. Section Processing
   ├─> WP_MCP_AI_Settings_Registry
   └─> Get section by ID
   │
5. Validation
   ├─> Section::validate()
   │   ├─> Custom validation rules
   │   └─> Return validated input or error
   │
6. Sanitization
   ├─> Section::sanitize()
   │   ├─> Sanitize each field
   │   └─> Apply sanitize_callback
   │
7. Save to Database
   ├─> Update options via Settings Repository
   └─> update_option()
   │
8. Fire Action
   ├─> do_action( 'wp_mcp_ai_settings_saved' )
   │
9. Response
   └─> wp_send_json_success()
```

---

## Integration Points

### WordPress Core Integrations

**Custom Post Types**:
- `mcp_ai_assistant` - Assistant configurations
- `mcp_ai_ai_peer` - Federation peer sites

**Post Meta**:
- Assistant settings stored as post meta
- Credentials stored as encrypted post meta
- Tool configurations stored as post meta

**Options API**:
- All plugin settings stored as options
- Prefixed with `wp_mcp_ai_`

**Transients**:
- Rate limiting counters
- Cache storage
- Temporary tokens

**Cron**:
- Background job processing
- Cleanup tasks
- Scheduled synchronization

**REST API**:
- Custom namespace: `mcp-ai/v1`
- Extends WordPress REST infrastructure
- Uses WordPress authentication

**Admin Menu**:
- Top-level menu: "WP oOS"
- Tools submenu: "WP oOS Diagnostic"

**Shortcodes**:
- `[wp_mcp_ai_chat]` - Embed chat interface
- `[wp_mcp_ai_assistant]` - Display assistant info

### Third-Party Plugin Integrations

**JetEngine** (optional):
- Custom Content Types (CCT) for assistants
- CCT for chat transcripts
- CCT for AI peers
- CCT for performance monitoring
- 5 additional tools

**WooCommerce** (optional):
- Create products via tools
- Retrieve order information
- 3 WooCommerce-specific tools

**Elementor** (optional):
- Chat widget
- Assistant selector widget
- Tool widgets
- 1 Elementor-specific tool

**Rank Math** (optional):
- SEO analysis tool

**WPCode** (optional):
- Code snippet management tool

**ChatKit** (optional):
- Enhanced chat UI integration

**Simple JWT Login** (optional):
- JWT authentication integration

**Auth0** (optional):
- Enterprise SSO integration
- User synchronization

### External Service Integrations

**OpenAI**:
- GPT models
- Function calling
- Vision API
- Audio transcription

**Google Gemini**:
- Gemini Pro/Ultra models
- Multimodal inputs

**Ollama**:
- Local model hosting
- Custom models

**LM Studio**:
- Local model hosting
- OpenAI-compatible API

**Anthropic**:
- Claude models
- Long context windows

**Crawl4AI**:
- Web scraping
- Content extraction
- Local deployment option

---

## Development Guidelines

### Adding a New Tool

1. **Create Tool Class**:
```php
// includes/tools/class-wp-mcp-ai-tool-my-tool.php

class WP_MCP_AI_Tool_My_Tool implements WP_MCP_AI_Tool_Interface {
    public function get_slug() {
        return 'my_tool';
    }
    
    public function get_name() {
        return __( 'My Tool', 'wp-mcp-ai' );
    }
    
    public function get_description() {
        return __( 'Description of what my tool does', 'wp-mcp-ai' );
    }
    
    public function get_parameters_schema() {
        return array(
            'type' => 'object',
            'properties' => array(
                'param1' => array(
                    'type' => 'string',
                    'description' => 'Parameter description',
                ),
            ),
            'required' => array( 'param1' ),
        );
    }
    
    public function execute( array $arguments = array(), array $context = array() ) {
        // Sanitize inputs
        $param1 = sanitize_text_field( $arguments['param1'] );
        
        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error( 'insufficient_permissions', 'Access denied' );
        }
        
        // Perform operations
        $result = $this->do_something( $param1 );
        
        // Return result
        return array(
            'success' => true,
            'data' => $result,
        );
    }
}
```

2. **Register Tool**:

Tool auto-registered if class file matches pattern: `class-wp-mcp-ai-tool-*.php`

OR manually register:

```php
add_action( 'wp_mcp_ai_register_tools', function( $registry ) {
    $registry->register_tool( new WP_MCP_AI_Tool_My_Tool() );
} );
```

3. **Add Tests**:
```php
// tests/test-my-tool.php

class Test_My_Tool extends WP_UnitTestCase {
    public function test_tool_executes() {
        $tool = new WP_MCP_AI_Tool_My_Tool();
        
        $result = $tool->execute( array(
            'param1' => 'test value',
        ) );
        
        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
    }
}
```

### Adding a Settings Section

1. **Create Section Class**:
```php
// includes/admin/sections/class-wp-mcp-ai-section-my-section.php

class WP_MCP_AI_Section_My_Section extends WP_MCP_AI_Settings_Section {
    public function get_id() {
        return 'my_section';
    }
    
    public function get_title() {
        return __( 'My Section', 'wp-mcp-ai' );
    }
    
    public function get_tab() {
        return 'general';  // Which tab to appear in
    }
    
    public function get_fields() {
        return array(
            'my_setting' => array(
                'type' => 'text',
                'label' => __( 'My Setting', 'wp-mcp-ai' ),
                'description' => __( 'Setting description', 'wp-mcp-ai' ),
            ),
        );
    }
    
    public function render() {
        // Custom rendering if needed
        // Otherwise, default renderer handles it
    }
}
```

2. **Register Section**:
```php
// In settings-dashboard-init.php

WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_My_Section() );
```

### Adding a REST Endpoint

1. **Add Route in REST Controller**:
```php
// In WP_MCP_AI_REST::register_routes()

register_rest_route(
    self::REST_NAMESPACE,
    '/my-endpoint',
    array(
        'methods'             => 'POST',
        'callback'            => array( $this, 'handle_my_endpoint' ),
        'permission_callback' => array( $this, 'check_permissions' ),
        'args'                => array(
            'param1' => array(
                'required'          => true,
                'validate_callback' => function( $param ) {
                    return is_string( $param );
                },
            ),
        ),
    )
);
```

2. **Implement Handler**:
```php
public function handle_my_endpoint( $request ) {
    // Get parameters
    $param1 = $request->get_param( 'param1' );
    
    // Sanitize
    $param1 = sanitize_text_field( $param1 );
    
    // Process
    $result = $this->do_something( $param1 );
    
    // Return response
    return rest_ensure_response( array(
        'success' => true,
        'data'    => $result,
    ) );
}
```

---

## Conclusion

This architecture guide provides a comprehensive overview of the WP Open Operator System (WP oOS) plugin codebase. It covers:

- Plugin entry point and initialization flow
- Core architectural patterns (DI, Service/Repository, Singleton, Factory, Strategy)
- Directory structure and file organization
- Class hierarchy and relationships
- Service layer and business logic
- Repository layer and data access
- REST API architecture and endpoints
- Tool system and extensibility
- Admin dashboard modular architecture
- AI client abstraction and provider support
- Authentication methods and security
- Hooks and filters for customization
- Error handling and logging
- Security best practices
- Data flow diagrams
- Integration points
- Development guidelines

### Key Takeaways for Copilot

1. **Modular Architecture**: Plugin uses clean separation of concerns with services, repositories, and controllers

2. **Extensibility**: Tools and settings sections are designed to be pluggable

3. **Security First**: Every input is sanitized, every output is escaped, capabilities are checked

4. **WordPress Standards**: Follows WPCS coding standards and WordPress best practices

5. **Provider Agnostic**: AI provider abstraction allows easy addition of new providers

6. **Well Documented**: Comprehensive PHPDoc blocks on all classes and methods

7. **Tested**: PHPUnit test suite covers critical paths

8. **Backward Compatible**: Maintains compatibility while introducing new features

9. **Performance Conscious**: Caching, rate limiting, token budgeting built-in

10. **Enterprise Ready**: Auth0 integration, federation support, advanced orchestration

### File Count Summary

- **Total PHP Files**: ~150+ files
- **Tool Implementations**: 73 tools
- **Admin Sections**: 14 sections
- **Services**: 6 services
- **Repositories**: 3 repositories
- **AI Clients**: 5 clients
- **REST Components**: 3 components
- **Admin Files**: 38 files
- **Test Files**: 30+ test files
- **Documentation Files**: 32+ docs

### Lines of Code

- **Main REST Controller**: 6,951 lines
- **Tool Registry**: 969 lines
- **Total Plugin**: ~50,000+ lines of PHP code

This guide should enable Copilot to:
- Understand the plugin architecture at a deep level
- Navigate the codebase effectively
- Make appropriate code suggestions
- Follow established patterns
- Maintain code quality and standards
- Extend functionality correctly

---

**Document Version**: 1.0.0  
**Last Updated**: 2025-11-11  
**Maintained By**: WP oOS Development Team
