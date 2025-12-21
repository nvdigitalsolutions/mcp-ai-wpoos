# Current State: Assistant & Processing in Agentic Workflows

**Last Updated:** November 14, 2025  
**Plugin Version:** 1.0.0  
**Status:** Production Documentation

---

## Table of Contents

1. [Overview](#overview)
2. [System Architecture](#system-architecture)
3. [Assistant Components](#assistant-components)
4. [Processing Flow](#processing-flow)
5. [Agentic Loop Mechanics](#agentic-loop-mechanics)
6. [Tool Execution](#tool-execution)
7. [Orchestration Layer](#orchestration-layer)
8. [Data Flow](#data-flow)
9. [Configuration Points](#configuration-points)
10. [Real-World Examples](#real-world-examples)

---

## Overview

Open Operator System (WP oOS) implements a sophisticated **agentic workflow** where AI assistants autonomously execute tools in iterative loops until they have all information needed to respond to user queries. This document describes the **current state** of how assistants and processing components work together.

### Key Concepts

**Assistant**: A configured AI agent with specific capabilities, tools, model settings, and base knowledge. Stored as WordPress Custom Post Type (`mcp_ai_assistant`).

**Processing**: The orchestration layer that handles chat requests, manages agentic loops, executes tools, and coordinates with AI providers.

**Agentic Workflow**: An autonomous loop where the AI can:
- Analyze user requests
- Decide which tools to call
- Execute multiple tools in sequence
- Gather information from various sources
- Synthesize comprehensive responses

---

## System Architecture

### High-Level Component Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         FRONTEND LAYER                           │
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │  Chat UI (JavaScript)                                       │ │
│  │  - User input handling                                      │ │
│  │  - Message bundling (800ms delay)                          │ │
│  │  - SSE streaming display                                    │ │
│  │  - Tool execution feedback (⚙️ ✓ ⚠️)                       │ │
│  │  - Result normalization                                     │ │
│  │  Files: assets/js/chat.js                                   │ │
│  └────────────────────────────────────────────────────────────┘ │
│                              ↓ HTTP POST                         │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│                         REST API LAYER                           │
│                                                                  │
│  ┌────────────────────┐          ┌────────────────────────────┐ │
│  │  /chat-client      │          │  /chat                     │ │
│  │  - Browser UI      │          │  - MCP protocol           │ │
│  │  - Max: 15 iter    │          │  - Max: 5 iterations      │ │
│  │  - Guest tokens    │          │  - Bearer auth            │ │
│  └────────────────────┘          └────────────────────────────┘ │
│                                                                  │
│  WP_MCP_AI_REST                                                  │
│  - Authentication (3 methods: nonce, bearer, Auth0)             │
│  - Request validation                                            │
│  - SSE handling                                                  │
│  Files: includes/class-wp-mcp-ai-rest.php                       │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│                       SERVICE LAYER                              │
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │  Assistant Service                                          │ │
│  │  - Assistant validation                                     │ │
│  │  - Configuration retrieval                                  │ │
│  │  - Capability management                                    │ │
│  │  - Default resolution                                       │ │
│  │  Files: includes/services/class-wp-mcp-ai-assistant-service│ │
│  └────────────────────────────────────────────────────────────┘ │
│                              ↓                                   │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │  Chat Service                                               │ │
│  │  - Message processing                                       │ │
│  │  - Agentic loop orchestration                              │ │
│  │  - Tool execution coordination                              │ │
│  │  - Transcript recording                                     │ │
│  │  - Token budget management                                  │ │
│  │  Files: includes/services/class-wp-mcp-ai-chat-service.php │ │
│  └────────────────────────────────────────────────────────────┘ │
│                              ↓                                   │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │  Tool Service                                               │ │
│  │  - Tool validation                                          │ │
│  │  - Execution coordination                                   │ │
│  │  - Result formatting                                        │ │
│  │  Files: includes/services/class-wp-mcp-ai-tool-service.php │ │
│  └────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│                    ORCHESTRATION LAYER                           │
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │  Language Model Router                                      │ │
│  │  - Provider selection (OpenAI, Gemini, Ollama)            │ │
│  │  - Client initialization                                    │ │
│  │  - Model configuration                                      │ │
│  │  Files: includes/class-wp-mcp-ai-language-model-router.php │ │
│  └────────────────────────────────────────────────────────────┘ │
│                              ↓                                   │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │  Tool Registry                                              │ │
│  │  - 65+ built-in tools                                       │ │
│  │  - Tool discovery                                           │ │
│  │  - Capability checking                                      │ │
│  │  - Execution orchestration                                  │ │
│  │  Files: includes/class-wp-mcp-ai-tool-registry.php         │ │
│  └────────────────────────────────────────────────────────────┘ │
│                              ↓                                   │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │  Agentic Workflow Optimizer                                 │ │
│  │  - Tool result caching                                      │ │
│  │  - Result compression                                       │ │
│  │  - Performance metrics                                      │ │
│  │  Files: includes/class-wp-mcp-ai-agentic-workflow-optimizer│ │
│  └────────────────────────────────────────────────────────────┘ │
│                              ↓                                   │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │  Resource Managers                                          │ │
│  │  - Token Budget Manager (TPM/RPM limits)                   │ │
│  │  - Rate Limit Manager (API throttling)                     │ │
│  │  - Resource Manager (PHP limits detection)                 │ │
│  │  Files: includes/services/*                                 │ │
│  └────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│                        DATA LAYER                                │
│                                                                  │
│  ┌─────────────┐  ┌─────────────┐  ┌──────────┐  ┌──────────┐ │
│  │ Assistants  │  │ Professions │  │ Settings │  │  Chat    │ │
│  │ (CPT/CCT)   │  │ (CPT/CCT)   │  │ (Options)│  │Transcripts│ │
│  └─────────────┘  └─────────────┘  └──────────┘  └──────────┘ │
│                                                                  │
│  WordPress Database + Optional JetEngine CCT Storage             │
└─────────────────────────────────────────────────────────────────┘
```

---

## Assistant Components

### Assistant Definition

An **Assistant** in WP oOS is a complete AI agent configuration stored as a WordPress Custom Post Type.

#### Core Properties

| Property | Type | Description | Storage |
|----------|------|-------------|---------|
| **ID** | int | WordPress post ID | `wp_posts.ID` |
| **Name** | string | Assistant display name | `wp_posts.post_title` |
| **Description** | text | Assistant purpose/role | `wp_posts.post_content` |
| **Status** | string | publish/draft/private | `wp_posts.post_status` |
| **AI Provider** | string | openai/gemini/ollama | Post Meta |
| **Model** | string | gpt-4/gemini-pro/llama2 | Post Meta |
| **System Prompt** | text | Behavior instructions | Post Meta |
| **Tools** | array | Enabled tool slugs | Post Meta |
| **Base Knowledge** | text | Context documents | Post Meta |
| **Max Iterations** | int | Agentic loop limit (1-50) | Post Meta |
| **Temperature** | float | Creativity (0.0-2.0) | Post Meta |
| **Required Capability** | string | WP capability needed | Post Meta |
| **Credentials** | array | API keys (hashed) | Post Meta |

#### Assistant Post Type

```php
// Registration
register_post_type( 'mcp_ai_assistant', array(
    'label'               => 'AI Assistants',
    'public'              => true,
    'show_in_rest'        => true,
    'supports'            => array( 'title', 'editor', 'thumbnail' ),
    'capability_type'     => 'post',
    'has_archive'         => false,
    'hierarchical'        => false,
    'rewrite'             => array( 'slug' => 'assistants' ),
) );
```

#### Assistant Service Responsibilities

**WP_MCP_AI_Assistant_Service** handles:

1. **Validation**: Verify assistant exists and is published
2. **Access Control**: Check user has required capability
3. **Configuration**: Retrieve all assistant settings
4. **Defaults**: Resolve default assistant when none specified
5. **Tools**: Get enabled tools for assistant

```php
// Example: Assistant Validation
$assistant_service = new WP_MCP_AI_Assistant_Service();
$assistant = $assistant_service->validate_assistant_access( $assistant_id, $user_id );

if ( is_wp_error( $assistant ) ) {
    return $assistant; // Access denied
}

$config = $assistant_service->get_assistant_config( $assistant_id );
```

### Profession Integration

**Professions** provide pre-configured templates for creating specialized AI assistants with domain-specific knowledge, tools, and behavior patterns.

#### Profession Custom Post Type

A **Profession** is stored as a WordPress Custom Post Type (`mcp_ai_profession`) and contains:

| Property | Type | Description | Storage |
|----------|------|-------------|---------|
| **ID** | int | WordPress post ID | `wp_posts.ID` |
| **Name** | string | Profession display name | `wp_posts.post_title` |
| **Description** | text | Brief profession description | `wp_posts.post_content` |
| **Slug** | string | Unique identifier | `wp_posts.post_name` |
| **Category** | string | advisory/creative/technical/healthcare/legal/financial/other | Post Meta |
| **Role Description** | text | Primary role and responsibilities for AI instructions | Post Meta |
| **Expertise Areas** | array | Specific areas of expertise | Post Meta |
| **Warnings** | array | Disclaimers the AI should communicate | Post Meta |
| **Knowledge Base** | text | Domain-specific knowledge content (markdown) | Post Meta |
| **Default Tools** | array | Recommended tool slugs for this profession | Post Meta |

#### Default Tools Array

The **Default Tools** field is a critical component of profession templates that defines which tools are most relevant for a given profession.

**Purpose**: 
- Provides recommended tool slugs that align with the profession's expertise
- Automatically pre-selects appropriate tools when creating assistants from this profession
- Ensures new assistants have domain-appropriate capabilities from the start

**Storage**:
- Stored in `wp_postmeta` table as serialized array
- Meta key: `default_tools`
- Value format: `array( 'tool_slug_1', 'tool_slug_2', ... )`

**How Default Tools are Applied**:

When an assistant is created from a profession template, the `default_tools` array is automatically applied to the assistant's enabled tools configuration:

```php
// Profession defines recommended tools
$profession['default_tools'] = array(
    'search_content',
    'get_recent_posts',
    'create_post',
    'get_user_info'
);

// These tools are pre-selected when creating assistant
$assistant_config['tools'] = $profession['default_tools'];
```

**Examples by Profession Category**:

- **Data Scientist**: `['analyze_data', 'create_chart', 'get_recent_posts', 'search_content']`
- **Content Writer**: `['create_post', 'search_content', 'get_elementor_templates', 'save_post']`
- **E-commerce Manager**: `['get_woo_products', 'get_woo_recent_orders', 'create_woo_product', 'search_content']`
- **SEO Specialist**: `['get_rankmath_seo', 'search_content', 'save_post', 'get_recent_posts']`
- **Social Media Manager**: `['post_facebook_instagram', 'post_linkedin_update', 'create_post', 'search_attachments']` *(Requires Pro for social media tools)*

**Tool Selection Best Practices**:

1. **Relevance**: Only include tools directly related to the profession's responsibilities
2. **Minimal Set**: Limit to 4-8 essential tools to avoid overwhelming new users
3. **Read + Write Balance**: Include both information retrieval and action tools
4. **Capability Awareness**: Ensure selected tools match the typical capability level for the profession
5. **Cross-Platform**: Consider tools that work across different WordPress configurations

**Customization**:

Administrators can modify default tools when:
- Creating custom profession templates via `save_profession` tool
- Manually editing profession post meta in WordPress admin
- Using filters to programmatically adjust tool recommendations

**Validation**:

The system validates that:
- All tool slugs in the array correspond to registered tools
- Tools are available in the current WordPress installation
- User has necessary capabilities to use the specified tools

#### Profession Workflow

1. **Discovery**: Use `list_professions` tool to browse available professions
2. **Details**: Use `get_profession` tool to retrieve full profession data
3. **Assistant Creation**: Profession data automatically populates assistant configuration:
   - System prompt includes role description and warnings
   - Base knowledge populated with profession knowledge base
   - Default tools pre-selected
   - Expertise areas inform assistant behavior
4. **Management**: Use `save_profession` tool to create/update professions
5. **Analytics**: Use `get_profession_stats` tool for profession insights

#### Profession Service Responsibilities

**WP_MCP_AI_Profession_Service** handles:

1. **Retrieval**: Get professions by slug, ID, or category
2. **Transformation**: Format profession data for assistants or display
3. **Merging**: Combine multiple professions for hybrid assistants
4. **Validation**: Check profession existence and data integrity

```php
// Example: Using Professions in Assistant Creation
$profession_service = wp_mcp_ai_get_profession_service();
$profession = $profession_service->get_profession( 'data_scientist' );

// Profession data is merged into assistant configuration
$assistant_config = array(
    'name'           => 'Data Science Assistant',
    'system_prompt'  => $profession['role_description'] . "\n\nWarnings:\n" . implode( "\n", $profession['warnings'] ),
    'base_knowledge' => $profession['knowledge_base'],
    'tools'          => $profession['default_tools'],
);
```

#### Profession Categories

Professions are organized into categories:

- **Advisory/Consulting**: Business advisors, career counselors, consultants
- **Creative Services**: Designers, writers, artists, content creators
- **Technical/STEM**: Engineers, developers, data scientists, researchers
- **Healthcare/Medical**: Medical professionals, therapists, nutritionists
- **Legal**: Attorneys, paralegals, legal researchers
- **Financial**: Accountants, financial planners, analysts
- **Other**: Miscellaneous professions

#### Profession Default Tools

The profession integration provides **4 specialized tools** that enable AI assistants to discover, manage, and utilize profession templates:

##### 1. List Professions (`list_professions`)

**Purpose**: Discover available professions that can be used when creating AI assistants.

**Parameters**:
- `category` (optional): Filter by category (advisory, creative, technical, healthcare, legal, financial, other)
- `detailed` (optional, boolean): If true, returns detailed information including expertise areas and default tools. Default: false

**Use Cases**:
- Browse available profession templates
- Filter professions by domain/category
- Get quick overview of all professions
- Discover profession options for assistant creation

**Example Response**:
```json
{
  "success": true,
  "count": 12,
  "professions": {
    "data_scientist": {
      "name": "Data Scientist",
      "category": "technical",
      "description": "Helps with data analysis and ML projects",
      "expertise": ["Machine learning", "Statistics", "Python"],
      "default_tools": ["analyze_data", "create_chart", "get_recent_posts"]
    }
  }
}
```

##### 2. Get Profession (`get_profession`)

**Purpose**: Retrieve detailed information about a specific profession.

**Parameters**:
- `profession_slug` (required): The slug of the profession to retrieve (e.g., "data_scientist", "graphic_designer")

**Returns**:
- Complete profession data including:
  - Role description
  - Expertise areas
  - Warnings/disclaimers
  - Knowledge base content (markdown)
  - Default tools array
  - Category and metadata

**Use Cases**:
- Get full profession details before creating assistant
- Review profession knowledge base
- Inspect recommended tools for a profession
- Understand profession expertise areas

**Example Response**:
```json
{
  "success": true,
  "profession": {
    "id": 456,
    "name": "Data Scientist",
    "slug": "data_scientist",
    "category": "technical",
    "role_description": "Helps with data analysis, ML models, and statistical insights",
    "expertise": ["Machine learning", "Statistics", "Python", "R"],
    "warnings": [
      "Always validate data sources",
      "Results require expert review"
    ],
    "knowledge_base": "# Data Science Best Practices\n...",
    "default_tools": ["analyze_data", "create_chart", "get_recent_posts"]
  }
}
```

##### 3. Save Profession (`save_profession`)

**Purpose**: Create a new profession or update an existing one.

**Parameters**:
- `title` (required): Display name (e.g., "Data Scientist")
- `slug` (required): Unique identifier (e.g., "data_scientist")
- `description` (optional): Brief profession description
- `category` (required): One of: advisory, creative, technical, healthcare, legal, financial, other
- `role_description` (optional): Role description for AI instructions
- `expertise` (optional, array): Expertise areas (e.g., ["Machine learning", "Statistics"])
- `warnings` (optional, array): Disclaimers the AI should communicate
- `knowledge_base` (optional): Domain-specific knowledge content (markdown)
- `default_tools` (optional, array): Recommended tool slugs

**Use Cases**:
- Create custom profession templates
- Update existing profession definitions
- Add domain-specific knowledge bases
- Configure profession-specific tool recommendations

**Example Usage**:
```json
{
  "title": "Marine Biologist",
  "slug": "marine_biologist",
  "category": "technical",
  "role_description": "Specializes in marine ecosystems and ocean science",
  "expertise": ["Marine ecology", "Oceanography", "Conservation"],
  "default_tools": ["search_content", "get_recent_posts", "web_search"]
}
```

##### 4. Get Profession Stats (`get_profession_stats`)

**Purpose**: Retrieve statistics and analytics about professions.

**Parameters**: None

**Returns**:
- Total profession count
- Count by category
- Category distribution percentages
- Analytics metadata

**Use Cases**:
- Monitor profession library growth
- Analyze profession category distribution
- Generate profession usage reports
- Track profession system health

**Example Response**:
```json
{
  "success": true,
  "total": 42,
  "by_category": {
    "technical": 15,
    "creative": 10,
    "advisory": 8,
    "healthcare": 5,
    "legal": 2,
    "financial": 2
  },
  "percentages": {
    "technical": 35.7,
    "creative": 23.8,
    "advisory": 19.0
  }
}
```

#### Tool Workflow Pattern

The profession tools work together in a typical workflow:

1. **Discovery** → Use `list_professions` to browse available options
2. **Inspection** → Use `get_profession` to get full details
3. **Assistant Creation** → Apply profession template to new assistant
4. **Management** → Use `save_profession` to create/update professions
5. **Analytics** → Use `get_profession_stats` for insights

**Example Complete Workflow**:

```php
// Step 1: Discover professions
list_professions({ category: "technical", detailed: true })

// Step 2: Get specific profession details
get_profession({ profession_slug: "data_scientist" })

// Step 3: Create assistant using profession
// Profession data automatically populates:
// - System prompt (role_description + warnings)
// - Base knowledge (knowledge_base)
// - Enabled tools (default_tools)

// Step 4: Create custom profession (if needed)
save_profession({
  title: "Custom Analyst",
  slug: "custom_analyst",
  category: "technical",
  expertise: ["Data analysis", "Reporting"],
  default_tools: ["analyze_data", "create_chart"]
})

// Step 5: Check statistics
get_profession_stats()
```

---

## Processing Flow

### Complete Request Flow

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. USER INPUT                                                    │
│    User types message in chat UI                                │
│    Files may be attached (images, documents)                    │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. MESSAGE BUNDLING (Frontend)                                  │
│    800ms delay to batch rapid inputs                            │
│    Prevents redundant API calls                                 │
│    Location: assets/js/chat.js                                  │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. HTTP REQUEST                                                  │
│    POST /wp-json/mcp-ai/v1/chat-client                          │
│    Headers:                                                      │
│    - X-WP-Nonce (WordPress nonce)                               │
│    - Authorization: Bearer <token> (optional)                   │
│    - X-WP-MCP-AI-Guest: <guest-token> (optional)               │
│    Body:                                                         │
│    {                                                             │
│      "assistant_id": 123,                                       │
│      "messages": [...],                                         │
│      "options": {...}                                           │
│    }                                                             │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4. AUTHENTICATION (REST API Layer)                              │
│    Three methods in priority order:                             │
│    a) WordPress Nonce (same-origin requests)                    │
│    b) Assistant Bearer Token (cred_xxxxx.SECRET)                │
│    c) Auth0 JWT Token (enterprise integrations)                 │
│                                                                  │
│    Class: WP_MCP_AI_REST_Authenticator                          │
│    Location: includes/rest/class-wp-mcp-ai-rest-authenticator  │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5. REQUEST VALIDATION                                            │
│    - Sanitize all input fields                                  │
│    - Validate message structure                                 │
│    - Check required parameters                                  │
│    - Verify file uploads (if any)                               │
│                                                                  │
│    Class: WP_MCP_AI_REST_Validator                              │
│    Location: includes/rest/class-wp-mcp-ai-rest-validator      │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6. ASSISTANT VALIDATION (Service Layer)                         │
│    - Verify assistant exists                                    │
│    - Check assistant is published                               │
│    - Validate user has required capability                      │
│    - Load assistant configuration                               │
│                                                                  │
│    Class: WP_MCP_AI_Assistant_Service                           │
│    Method: validate_assistant_access()                          │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 7. CONFIGURATION ASSEMBLY                                        │
│    Build complete config from:                                  │
│    - Assistant settings (provider, model, tools)                │
│    - Admin settings (global defaults)                           │
│    - Request parameters (overrides)                             │
│    - Apply filters for customization                            │
│                                                                  │
│    Priority: Request > Assistant > Admin > Defaults             │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 8. MAX ITERATIONS CALCULATION                                    │
│    Determine agentic loop limit:                                │
│                                                                  │
│    1. Per-assistant override (highest priority)                 │
│       $config['max_agentic_iterations']                         │
│                                                                  │
│    2. Admin setting (Custom AI Settings)                        │
│       wp_mcp_ai_max_agentic_iterations filter                   │
│                                                                  │
│    3. Endpoint default:                                         │
│       /chat-client: 15                                          │
│       /chat: 5                                                  │
│                                                                  │
│    4. Safety bounds: min(1, max(50, $iterations))              │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 9. RATE LIMITING CHECK                                          │
│    - Check requests per minute (RPM)                            │
│    - Check tokens per minute (TPM)                              │
│    - Enforce rate limits if exceeded                            │
│                                                                  │
│    Class: WP_MCP_AI_Rate_Limit_Manager                          │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 10. TOKEN BUDGET ALLOCATION                                      │
│     - Calculate available token budget                          │
│     - Apply safety margins (80% of limit)                       │
│     - Consider PHP memory limits                                │
│     - Determine workload tier (Low/Medium/High)                 │
│                                                                  │
│     Class: WP_MCP_AI_Token_Budget_Manager                       │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 11. CHAT SERVICE PROCESSING                                      │
│     Entry point for agentic workflow                            │
│                                                                  │
│     Class: WP_MCP_AI_Chat_Service                               │
│     Method: process_chat_request()                              │
│                                                                  │
│     See "Agentic Loop Mechanics" section below                  │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 12. RESPONSE FORMATTING                                          │
│     - Add tool_results array to response                        │
│     - Include usage statistics                                  │
│     - Format for SSE or standard JSON                           │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 13. TRANSCRIPT RECORDING                                         │
│     - Save conversation to localStorage (24h)                   │
│     - Optionally save to JetEngine CCT (permanent)              │
│     - Record timing and token usage                             │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 14. FRONTEND RENDERING                                           │
│     - Normalize tool results for display                        │
│     - Extract attachments (images, files)                       │
│     - Show tool execution feedback                              │
│     - Re-render with download links                             │
└─────────────────────────────────────────────────────────────────┘
```

---

## Agentic Loop Mechanics

The **agentic loop** is where the AI autonomously executes tools to gather information.

### Loop Algorithm

```php
// Simplified agentic loop implementation
// Location: WP_MCP_AI_Chat_Service::process_chat_request()

iteration = 0
max_iterations = 15 // or configured value
tool_result_messages = []

// Fire action for optimization layer
do_action( 'wp_mcp_ai_agentic_loop_start' );

while ( iteration < max_iterations ) {
    
    // Step 1: Send messages to LLM
    response = client->create_chat_completion( messages, options );
    
    if ( is_wp_error( response ) ) {
        break; // Error occurred
    }
    
    // Step 2: Extract tool calls from response
    tool_calls = extract_tool_calls_from_response( response );
    
    // Step 3: Check if tools were requested
    if ( empty( tool_calls ) ) {
        break; // No tools needed, final response ready
    }
    
    // Step 4: Add assistant message to conversation
    messages[] = {
        'role': 'assistant',
        'content': response.choices[0].message.content,
        'tool_calls': tool_calls
    };
    
    // Step 5: Execute each tool
    foreach ( tool_calls as tool_call ) {
        
        // Execute tool
        result = tool_service->execute_tool(
            tool_call.function.name,
            tool_call.function.arguments,
            context
        );
        
        // Add tool result to conversation
        tool_message = {
            'role': 'tool',
            'tool_call_id': tool_call.id,
            'content': json_encode( result )
        };
        
        messages[] = tool_message;
        tool_result_messages[] = tool_message;
    }
    
    // Step 6: Increment iteration counter
    iteration++;
}

// Fire action for metrics collection
do_action( 'wp_mcp_ai_agentic_loop_complete' );

// Add tool results to response for frontend
response['tool_results'] = tool_result_messages;

return response;
```

### Iteration Example

**User Request**: "What are my recent posts and what's the current time?"

```
Iteration 0:
  User: "What are my recent posts and what's the current time?"
  
  LLM Response:
  - tool_calls: [
      { name: "get_recent_posts", arguments: { limit: 5 } },
      { name: "get_current_time", arguments: {} }
    ]
  
  Tool Executions:
  - get_recent_posts → Returns 5 posts
  - get_current_time → Returns "2024-11-14 12:00:00 UTC"
  
  Messages after iteration 0:
  1. {role: "user", content: "What are my recent posts..."}
  2. {role: "assistant", tool_calls: [...]}
  3. {role: "tool", content: "{"posts": [...]}""}
  4. {role: "tool", content: "{"time": "..."}""}

Iteration 1:
  Messages sent to LLM: [all 4 messages from above]
  
  LLM Response:
  - No tool_calls (has all needed information)
  - content: "Here are your 5 most recent posts: ... 
             The current time is 12:00:00 UTC."
  
  Loop breaks (no more tools needed)

Final Response:
  {
    "choices": [{
      "message": {
        "role": "assistant",
        "content": "Here are your 5 most recent posts..."
      }
    }],
    "tool_results": [
      {role: "tool", content: "{"posts": [...]}"},
      {role: "tool", content: "{"time": "..."}"}
    ]
  }
```

### Max Iterations Safety

The loop has a maximum iteration limit to prevent:
- Infinite loops
- Excessive API costs
- Request timeouts
- Resource exhaustion

**Configuration Priority**:

1. **Per-Assistant Config** (Highest)
   ```php
   update_post_meta( $assistant_id, 'max_agentic_iterations', 20 );
   ```

2. **Admin Setting** (General Settings → Custom AI Settings subtab)
   ```
   Settings → WP oOS → General Settings → Custom AI Settings (Filters) → Max Agentic Iterations
   ```

3. **Programmatic Filter**
   ```php
   add_filter( 'wp_mcp_ai_max_agentic_iterations', function( $iterations, $config ) {
       if ( $config['assistant_type'] === 'complex' ) {
           return 25;
       }
       return $iterations;
   }, 10, 2 );
   ```

4. **Endpoint Default**
   - `/chat-client`: 15 (for browser UI)
   - `/chat`: 5 (for programmatic access)

5. **Safety Bounds**: `max(1, min(50, $iterations))`

---

## Tool Execution

### Tool Registry

The **Tool Registry** manages all 65+ built-in tools and any custom tools.

#### Tool Structure

```php
// Each tool implements:
interface WP_MCP_AI_Tool_Interface {
    
    // Unique identifier
    public function get_slug();
    
    // Tool metadata for LLM
    public function get_definition();
    
    // Required WordPress capability
    public function get_required_capability();
    
    // Execution logic
    public function execute( $arguments, $context );
}
```

#### Example Tool

```php
class WP_MCP_AI_Tool_Get_Recent_Posts implements WP_MCP_AI_Tool_Interface {
    
    public function get_slug() {
        return 'get_recent_posts';
    }
    
    public function get_definition() {
        return array(
            'name' => 'get_recent_posts',
            'description' => 'Retrieve recent blog posts',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'limit' => array(
                        'type' => 'integer',
                        'description' => 'Number of posts to retrieve',
                        'default' => 10,
                    ),
                    'post_type' => array(
                        'type' => 'string',
                        'description' => 'Post type to query',
                        'default' => 'post',
                    ),
                ),
            ),
        );
    }
    
    public function get_required_capability() {
        return 'read'; // Any logged-in user
    }
    
    public function execute( $arguments, $context ) {
        $limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
        $post_type = isset( $arguments['post_type'] ) 
            ? sanitize_text_field( $arguments['post_type'] ) 
            : 'post';
        
        $posts = get_posts( array(
            'numberposts' => $limit,
            'post_type'   => $post_type,
            'post_status' => 'publish',
        ) );
        
        return array(
            'success' => true,
            'posts'   => array_map( function( $post ) {
                return array(
                    'id'      => $post->ID,
                    'title'   => $post->post_title,
                    'excerpt' => wp_trim_words( $post->post_content, 20 ),
                    'date'    => $post->post_date,
                    'link'    => get_permalink( $post->ID ),
                );
            }, $posts ),
        );
    }
}
```

### Tool Execution Flow

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. TOOL CALL RECEIVED                                            │
│    From LLM response: tool_calls array                          │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. TOOL LOOKUP                                                   │
│    Registry retrieves tool by slug                              │
│    Verify tool exists and is enabled for assistant              │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. CAPABILITY CHECK                                              │
│    Verify user has required capability                          │
│    Tool-specific: tool->get_required_capability()               │
│    User-specific: current_user_can( $capability )               │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4. ARGUMENT VALIDATION                                           │
│    Parse JSON arguments                                         │
│    Validate against tool parameter schema                       │
│    Sanitize all inputs                                          │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5. CACHE CHECK (if enabled)                                     │
│    Optimizer checks for cached result                           │
│    Only for idempotent tools (read-only)                        │
│    5-minute TTL by default                                      │
│                                                                  │
│    Filter: wp_mcp_ai_before_tool_execute                        │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6. TOOL EXECUTION                                                │
│    tool->execute( $arguments, $context )                        │
│    Context includes:                                             │
│    - user_id: Current user ID                                   │
│    - assistant_id: Assistant being used                         │
│    - session_id: Conversation session                           │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 7. ERROR HANDLING                                                │
│    If error: Return structured error response                   │
│    If WP_Error: Convert to standard format                      │
│    Log errors for debugging                                     │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 8. RESULT FORMATTING                                             │
│    Ensure consistent structure                                  │
│    Add success/error indicators                                 │
│    Compress large results (>10KB)                               │
│                                                                  │
│    Filter: wp_mcp_ai_tool_result_content                        │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 9. CACHE STORAGE (if enabled)                                   │
│    Store result in WordPress object cache                       │
│    Action: wp_mcp_ai_after_tool_execute                         │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 10. RETURN TO CHAT SERVICE                                       │
│     Result added to messages as role: "tool"                    │
│     Continue agentic loop                                       │
└─────────────────────────────────────────────────────────────────┘
```

### Tool Categories

**65+ built-in tools** organized by category:

| Category | Count | Examples |
|----------|-------|----------|
| **WordPress Core** | 15 | get_recent_posts, search_content, get_site_summary |
| **Media** | 8 | search_attachments, download_image, get_media_info |
| **Users** | 6 | get_users, get_current_user, update_user_meta |
| **Professions** | 4 | list_professions, get_profession, save_profession, get_profession_stats |
| **WooCommerce** | 8 | get_woo_products, get_woo_orders, update_product |
| **JetEngine** | 12 | get_jetengine_items, create_cct_item, query_relations |
| **Elementor** | 5 | get_elementor_templates, apply_template |
| **SEO (Rank Math)** | 3 | get_rankmath_seo, analyze_seo_score |
| **Utilities** | 8 | get_current_time, send_email, schedule_cron |

Full reference: `docs/tool-reference.md`

---

## Orchestration Layer

The **Orchestration Layer** coordinates all components and enforces policies.

### Key Components

#### 1. Language Model Router

**Responsibility**: Select and initialize the appropriate AI client

```php
class WP_MCP_AI_Language_Model_Router {
    
    public function get_client( $config ) {
        $provider = $config['provider']; // 'openai', 'gemini', 'ollama'
        
        switch ( $provider ) {
            case 'openai':
                return new WP_MCP_AI_OpenAI_Client( $config );
            
            case 'gemini':
                return new WP_MCP_AI_Gemini_Client( $config );
            
            case 'ollama':
                return new WP_MCP_AI_Ollama_Client( $config );
            
            default:
                return new WP_Error( 'invalid_provider', "Unknown provider: $provider" );
        }
    }
}
```

**Supported Providers**:
- **OpenAI**: GPT-4, GPT-3.5, GPT-4 Vision
- **Google Gemini**: Gemini Pro, Gemini Pro Vision
- **Ollama**: Local models (Llama 2, Mistral, etc.)

#### 2. Token Budget Manager

**Responsibility**: Prevent API limit violations

```php
class WP_MCP_AI_Token_Budget_Manager {
    
    public function get_max_tokens( $config ) {
        // Get model limits
        $model_limit = $this->get_model_token_limit( $config['model'] );
        
        // Apply safety margin (80%)
        $safe_limit = floor( $model_limit * 0.8 );
        
        // Consider PHP memory
        $memory_limit = $this->get_memory_based_limit();
        
        return min( $safe_limit, $memory_limit );
    }
    
    public function validate_token_budget( $messages, $max_tokens ) {
        $estimated_tokens = $this->estimate_token_count( $messages );
        
        if ( $estimated_tokens > $max_tokens ) {
            return new WP_Error( 'token_budget_exceeded', 
                "Estimated tokens ($estimated_tokens) exceed limit ($max_tokens)" 
            );
        }
        
        return true;
    }
}
```

**Budget Sources**:
- Model-specific limits (e.g., GPT-4: 8K/32K/128K)
- TPM (Tokens Per Minute) limits from API provider
- PHP memory limits (converted to token budget)
- Admin-configured limits

#### 3. Rate Limit Manager

**Responsibility**: Throttle API requests

```php
class WP_MCP_AI_Rate_Limit_Manager {
    
    public function check_rate_limit( $user_id, $assistant_id ) {
        $key = "rate_limit_{$user_id}_{$assistant_id}";
        
        $count = get_transient( $key );
        
        if ( false === $count ) {
            set_transient( $key, 1, 60 ); // 1 request in last minute
            return true;
        }
        
        $limit = $this->get_rpm_limit( $assistant_id );
        
        if ( $count >= $limit ) {
            return new WP_Error( 'rate_limit_exceeded', 
                "Rate limit exceeded: $count/$limit requests per minute" 
            );
        }
        
        set_transient( $key, $count + 1, 60 );
        return true;
    }
}
```

#### 4. Agentic Workflow Optimizer

**Responsibility**: Performance optimizations

**Features**:
- **Tool Result Caching**: 5-minute cache for idempotent tools
- **Result Compression**: Compress responses >10KB (saves 20-40%)
- **Performance Metrics**: Track execution time, memory, iterations

**Cacheable Tools**:
- get_site_summary
- search_content
- get_recent_posts
- search_attachments
- get_elementor_templates
- get_jetengine_items
- get_woo_products
- get_rankmath_seo
- list_professions
- get_profession
- get_profession_stats

```php
class WP_MCP_AI_Agentic_Workflow_Optimizer {
    
    // Check cache before execution
    public function check_tool_cache( $result, $tool_name, $arguments ) {
        if ( ! $this->is_cacheable_tool( $tool_name ) ) {
            return $result;
        }
        
        $cache_key = $this->get_cache_key( $tool_name, $arguments );
        $cached = wp_cache_get( $cache_key, 'wp_mcp_ai_tool_results' );
        
        if ( false !== $cached ) {
            $this->record_metric( 'cache_hit', $tool_name );
            return $cached;
        }
        
        $this->record_metric( 'cache_miss', $tool_name );
        return $result;
    }
    
    // Store result after execution
    public function cache_tool_result( $result, $tool_name, $arguments, $context ) {
        if ( is_wp_error( $result ) || ! $this->is_cacheable_tool( $tool_name ) ) {
            return;
        }
        
        $cache_key = $this->get_cache_key( $tool_name, $arguments );
        wp_cache_set( $cache_key, $result, 'wp_mcp_ai_tool_results', 300 ); // 5 min
    }
}
```

---

## Data Flow

### Message Flow Diagram

```
User Message
     ↓
┌────────────────────────────────────────┐
│  CONVERSATION MESSAGES ARRAY            │
│                                         │
│  [                                      │
│    {                                    │
│      role: "system",                   │
│      content: "You are a helpful..."   │
│    },                                   │
│    {                                    │
│      role: "user",                     │
│      content: "What are my posts?"     │
│    }                                    │
│  ]                                      │
└────────────────────────────────────────┘
     ↓
┌────────────────────────────────────────┐
│  LLM PROCESSES AND RESPONDS             │
│                                         │
│  Response includes tool_calls:         │
│  [                                      │
│    {                                    │
│      id: "call_abc123",                │
│      type: "function",                 │
│      function: {                       │
│        name: "get_recent_posts",       │
│        arguments: '{"limit": 5}'       │
│      }                                  │
│    }                                    │
│  ]                                      │
└────────────────────────────────────────┘
     ↓
┌────────────────────────────────────────┐
│  TOOL EXECUTION                         │
│                                         │
│  Tool: get_recent_posts                │
│  Args: {limit: 5}                      │
│  Result: {                              │
│    success: true,                      │
│    posts: [...]                        │
│  }                                      │
└────────────────────────────────────────┘
     ↓
┌────────────────────────────────────────┐
│  MESSAGES ARRAY UPDATED                 │
│                                         │
│  [                                      │
│    {role: "system", content: "..."},   │
│    {role: "user", content: "..."},     │
│    {                                    │
│      role: "assistant",                │
│      content: null,                    │
│      tool_calls: [...]                 │
│    },                                   │
│    {                                    │
│      role: "tool",                     │
│      tool_call_id: "call_abc123",      │
│      content: '{"success": true, ...}' │
│    }                                    │
│  ]                                      │
└────────────────────────────────────────┘
     ↓
┌────────────────────────────────────────┐
│  SENT BACK TO LLM (Next Iteration)     │
│                                         │
│  LLM now has context:                  │
│  - Original user question              │
│  - Tool it called                      │
│  - Tool execution result               │
│                                         │
│  Can now formulate final response      │
└────────────────────────────────────────┘
     ↓
┌────────────────────────────────────────┐
│  FINAL RESPONSE                         │
│                                         │
│  {                                      │
│    role: "assistant",                  │
│    content: "Here are your 5 recent    │
│              posts: ..."               │
│  }                                      │
│                                         │
│  No tool_calls = loop ends             │
└────────────────────────────────────────┘
     ↓
┌────────────────────────────────────────┐
│  RESPONSE TO USER                       │
│                                         │
│  Response includes:                    │
│  - Final assistant message             │
│  - tool_results array (for UI)        │
│  - usage statistics                    │
└────────────────────────────────────────┘
```

### Data Storage

#### Assistants Storage

**Primary**: WordPress Custom Post Type
- Post table: `wp_posts` (post_type = 'mcp_ai_assistant')
- Meta table: `wp_postmeta` (all configuration)

**Optional**: JetEngine CCT
- Custom table with identical fields
- Enables advanced queries and relations
- Synced bidirectionally with CPT

#### Professions Storage

**Primary**: WordPress Custom Post Type
- Post table: `wp_posts` (post_type = 'mcp_ai_profession')
- Meta table: `wp_postmeta` (category, expertise, tools, knowledge base)

**Optional**: JetEngine CCT
- Custom table for profession data
- Advanced filtering by category and expertise
- Relationship queries with assistants

**Caching**:
- Object cache for profession queries (1 hour TTL)
- Cache group: `wp_mcp_ai_professions`
- Automatically cleared on profession save/delete

#### Chat Transcripts Storage

**Primary**: Browser localStorage
- 24-hour retention
- Per-assistant conversations
- Export to JSON available

**Optional**: JetEngine CCT
- Permanent server-side storage
- Full conversation history
- Searchable and analyzable

#### Settings Storage

**WordPress Options**:
- `wp_mcp_ai_settings`: Main plugin settings
- `wp_mcp_ai_provider_settings`: Provider credentials
- `wp_mcp_ai_cron_jobs`: Scheduled tasks registry

**Transients** (Caching):
- `wp_mcp_ai_tool_results_{hash}`: Tool result cache
- `rate_limit_{user}_{assistant}`: Rate limit tracking
- Tool definitions cache (5 minutes)

---

## Configuration Points

### Assistant-Level Configuration

Configured in **WordPress Admin → AI Assistants → Edit Assistant**

| Setting | Location | Impact |
|---------|----------|--------|
| **AI Provider** | Meta: provider | Which API to use (OpenAI/Gemini/Ollama) |
| **Model** | Meta: model | Which model version (GPT-4/Gemini-Pro/etc) |
| **System Prompt** | Meta: system_prompt | Behavior and personality |
| **Tools** | Meta: tools | Enabled tool slugs array |
| **Base Knowledge** | Meta: base_knowledge | Context documents |
| **Max Iterations** | Meta: max_agentic_iterations | Override global limit |
| **Temperature** | Meta: temperature | Creativity level (0.0-2.0) |
| **Max Tokens** | Meta: max_tokens | Response length limit |
| **Required Capability** | Meta: required_capability | Who can use assistant |

### Global Configuration

Configured in **Settings → WP oOS**

#### General Settings Tab

- Enable logging
- Default provider
- Default model
- Error tracking

#### Custom AI Settings Tab

- Max agentic iterations (1-50)
- Default temperature
- Default max tokens
- Rate limits (RPM/TPM)

#### Provider Settings

- **OpenAI**: API key, organization ID
- **Gemini**: API key, project ID
- **Ollama**: Base URL, default model

### Programmatic Configuration

#### Filters

```php
// Override max iterations
add_filter( 'wp_mcp_ai_max_agentic_iterations', function( $iterations, $config ) {
    if ( $config['assistant_id'] === 123 ) {
        return 25; // Special assistant gets more iterations
    }
    return $iterations;
}, 10, 2 );

// Modify chat options before sending to LLM
add_filter( 'wp_mcp_ai_chat_options', function( $options, $config, $request ) {
    $options['temperature'] = 0.7; // Force temperature
    return $options;
}, 10, 3 );

// Add custom cacheable tools
add_filter( 'wp_mcp_ai_cacheable_tools', function( $tools, $tool_name ) {
    $tools[] = 'my_custom_readonly_tool';
    return $tools;
}, 10, 2 );
```

#### Actions

```php
// Before chat request
add_action( 'wp_mcp_ai_before_chat_request', function( $assistant_id, $messages, $options, $request ) {
    // Custom logging
    error_log( "Chat request for assistant $assistant_id" );
}, 10, 4 );

// After tool execution
add_action( 'wp_mcp_ai_after_tool_execute', function( $result, $tool_name, $arguments, $context ) {
    // Custom analytics
    update_tool_usage_stats( $tool_name );
}, 10, 4 );

// Agentic loop metrics
add_action( 'wp_mcp_ai_agentic_metrics', function( $metrics ) {
    // Performance monitoring
    if ( $metrics['duration'] > 10 ) {
        alert_slow_response( $metrics );
    }
} );
```

#### Constants

```php
// Disable agentic optimizations
define( 'WP_MCP_AI_DISABLE_AGENTIC_OPTIMIZATIONS', true );

// Enable debug mode
define( 'WP_MCP_AI_DEBUG', true );

// Set base version (fewer tools)
define( 'WP_MCP_AI_BASE_VERSION', true );
```

---

## Real-World Examples

### Example 1: Simple Question-Answer

**User**: "What is my site's tagline?"

```php
// No tools needed - direct answer from WordPress
Iteration 0:
  Messages: [
    {role: "system", content: "You are an AI assistant..."},
    {role: "user", content: "What is my site's tagline?"}
  ]
  
  LLM Response:
    content: "Your site's tagline is: 'Just another WordPress site'"
    tool_calls: [] // No tools needed
  
  Loop breaks immediately (no tools)
```

**Result**: Single iteration, instant response

---

### Example 2: Tool-Required Query

**User**: "Show me my 3 most recent posts"

```php
Iteration 0:
  LLM decides: Need get_recent_posts tool
  
  Tool Call:
    name: "get_recent_posts"
    arguments: {limit: 3}
  
  Tool Result:
    {
      success: true,
      posts: [
        {id: 456, title: "Post 1", date: "2024-11-14"},
        {id: 455, title: "Post 2", date: "2024-11-13"},
        {id: 454, title: "Post 3", date: "2024-11-12"}
      ]
    }
  
  Messages after tool:
    [...previous...,
     {role: "assistant", tool_calls: [...]},
     {role: "tool", content: "{...post data...}"}
    ]

Iteration 1:
  LLM receives tool result
  Formulates response using post data
  
  Final Response:
    "Here are your 3 most recent posts:
     1. Post 1 (Nov 14, 2024)
     2. Post 2 (Nov 13, 2024)
     3. Post 3 (Nov 12, 2024)"
  
  No more tools needed, loop ends
```

**Result**: 2 iterations, 1 tool execution

---

### Example 3: Multi-Tool Complex Query

**User**: "Create a blog post about the weather in London, add an image, and schedule it for tomorrow"

```php
Iteration 0:
  LLM decides: Need weather info first
  
  Tool Call:
    name: "get_weather"
    arguments: {location: "London"}
  
  Tool Result:
    {temperature: "15°C", condition: "Cloudy", ...}

Iteration 1:
  LLM has weather data
  Decides: Need to create post content
  
  Tool Call:
    name: "create_post"
    arguments: {
      title: "Weather in London Today",
      content: "Today in London, it's 15°C and cloudy...",
      status: "draft"
    }
  
  Tool Result:
    {post_id: 789, success: true}

Iteration 2:
  LLM knows post is created
  Decides: Need to find weather image
  
  Tool Call:
    name: "search_attachments"
    arguments: {query: "weather cloudy"}
  
  Tool Result:
    {images: [{id: 123, url: "...weather.jpg"}]}

Iteration 3:
  LLM has image
  Decides: Attach image to post
  
  Tool Call:
    name: "update_post"
    arguments: {
      post_id: 789,
      featured_image: 123
    }
  
  Tool Result:
    {success: true}

Iteration 4:
  LLM knows post has image
  Decides: Need to schedule for tomorrow
  
  Tool Call:
    name: "schedule_post"
    arguments: {
      post_id: 789,
      publish_date: "2024-11-15 09:00:00"
    }
  
  Tool Result:
    {success: true, scheduled_for: "Nov 15, 2024 9:00 AM"}

Iteration 5:
  LLM has completed all tasks
  Formulates summary response
  
  Final Response:
    "I've created a blog post about London's weather (15°C, cloudy), 
     added a weather image, and scheduled it to publish tomorrow at 9 AM."
  
  No more tools needed, loop ends
```

**Result**: 6 iterations, 5 tool executions

**Timeline**:
1. Get weather data
2. Create post
3. Find image
4. Attach image
5. Schedule post
6. Respond to user

---

### Example 4: Error Recovery

**User**: "Get posts from a non-existent post type"

```php
Iteration 0:
  Tool Call:
    name: "get_recent_posts"
    arguments: {post_type: "fake_type"}
  
  Tool Result:
    {
      success: false,
      error: "Invalid post type: fake_type"
    }

Iteration 1:
  LLM receives error
  Tries alternative approach
  
  Tool Call:
    name: "get_recent_posts"
    arguments: {post_type: "post"} // Default to standard posts
  
  Tool Result:
    {success: true, posts: [...]}

Iteration 2:
  LLM has valid data
  
  Final Response:
    "I couldn't find posts of type 'fake_type', but here are 
     your recent blog posts instead: ..."
```

**Result**: 3 iterations, error recovery successful

---

### Example 5: Creating Assistant from Profession

**User**: "Create a data science assistant for my team"

```php
Iteration 0:
  LLM decides: Need to explore available professions
  
  Tool Call:
    name: "list_professions"
    arguments: {category: "technical", detailed: true}
  
  Tool Result:
    {
      success: true,
      count: 12,
      professions: {
        "data_scientist": {
          name: "Data Scientist",
          category: "technical",
          expertise: ["Machine learning", "Data visualization", "Statistics"],
          ...
        },
        ...
      }
    }

Iteration 1:
  LLM found matching profession
  Decides: Get full profession details
  
  Tool Call:
    name: "get_profession"
    arguments: {profession_slug: "data_scientist"}
  
  Tool Result:
    {
      success: true,
      profession: {
        id: 456,
        name: "Data Scientist",
        role_description: "Helps with data analysis, ML models...",
        expertise: ["Machine learning", "Statistics", "Python"],
        warnings: ["Always validate data sources", "Results require expert review"],
        knowledge_base: "# Data Science Best Practices\n...",
        default_tools: ["analyze_data", "create_chart", "get_recent_posts"]
      }
    }

Iteration 2:
  LLM has profession data
  Decides: Create assistant with profession configuration
  
  Tool Call:
    name: "create_assistant"
    arguments: {
      name: "Data Science Team Assistant",
      profession: "data_scientist",
      description: "Helps team with data analysis and ML projects",
      tools: ["analyze_data", "create_chart", "get_recent_posts", "search_content"]
    }
  
  Tool Result:
    {
      success: true,
      assistant_id: 789,
      message: "Assistant created with Data Scientist profession template"
    }

Iteration 3:
  LLM has completed assistant creation
  
  Final Response:
    "I've created a Data Science Team Assistant for you! The assistant 
     is configured with data science expertise including machine learning, 
     statistics, and data visualization. It has the recommended tools 
     enabled and will always remind users to validate data sources and 
     review results with an expert. You can start using it right away!"
```

**Result**: 4 iterations, profession-based assistant created

**Benefits of Profession Integration**:
- Pre-configured domain expertise
- Consistent best practices across assistants
- Built-in warnings and disclaimers
- Recommended tools automatically selected
- Knowledge base provides context

---

## Summary

### How It All Works Together

1. **User** types message in chat UI
2. **Frontend** bundles messages and sends to REST API
3. **REST Layer** authenticates and validates request
4. **Assistant Service** verifies assistant and loads configuration
5. **Chat Service** orchestrates the agentic loop:
   - Sends messages to LLM
   - Extracts tool calls
   - Executes tools via Tool Service
   - Adds results to conversation
   - Repeats until final answer
6. **Orchestration Layer** manages:
   - Rate limiting
   - Token budgets
   - Result caching
   - Performance metrics
7. **Response** returns to frontend with tool results
8. **Frontend** renders final answer with visual feedback

### Key Strengths

✅ **Autonomous**: AI decides which tools to use  
✅ **Iterative**: Can gather information in multiple steps  
✅ **Safe**: Max iterations prevent infinite loops  
✅ **Flexible**: Configuration at assistant, admin, and code levels  
✅ **Optimized**: Caching, compression, metrics  
✅ **Secure**: Capability checking, authentication, validation  
✅ **Provider-Agnostic**: Works with OpenAI, Gemini, Ollama  
✅ **Extensible**: Custom tools, filters, actions

### Performance Characteristics

| Metric | Typical Value | Notes |
|--------|---------------|-------|
| **Simple Query** | 1 iteration, <2 sec | No tools needed |
| **Single Tool** | 2 iterations, 3-5 sec | One tool execution |
| **Multi-Tool** | 3-6 iterations, 5-15 sec | Multiple tools |
| **Complex Workflow** | 5-10 iterations, 10-30 sec | Many coordinated tools |
| **Cache Hit** | -50% time | Cached tool results |
| **Max Iterations** | 15 (chat-client), 5 (chat) | Configurable 1-50 |

---

## Related Documentation

- **[agentic-workflow-architecture.md](agentic-workflow-architecture.md)** - Detailed architecture
- **[tool-reference.md](reference/tools/tool-reference.md)** - All 65+ tools documented
- **[rest-api.md](reference/api/rest-api.md)** - REST API reference
- **[ORCHESTRATION-LAYER-ARCHITECTURE.md](architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md)** - Orchestration details
- **[BEST_PRACTICES.md](guides/developer/best-practices/BEST_PRACTICES.md)** - Usage best practices
- **[QUICK_REFERENCE.md](QUICK_REFERENCE.md)** - Quick reference guide

---

**Maintained by:** NV Digital Solutions  
**Repository:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos  
**License:** GPLv3 or later
