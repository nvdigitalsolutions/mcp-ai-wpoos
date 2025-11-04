# Assistant Storage: CPT vs CCT

This document explains the different assistant storage mechanisms available in WP oOS and when to use each approach.

## Overview

WP oOS provides **two distinct methods** for storing AI assistant configurations:

1. **CPT (Custom Post Type)** - WordPress native Custom Post Type (`mcp_ai_assistant`)
2. **CCT (Custom Content Type)** - JetEngine Custom Content Type (`assistants`)

**Important:** As of version 1.0.0, these systems work together through **automatic synchronization**. When you save an assistant in the CPT admin interface and JetEngine is available, the settings are automatically synchronized to the CCT for API consumers that prefer the JetEngine endpoint.

## CPT (Custom Post Type) - Default Implementation

### What is it?

The CPT implementation uses WordPress's built-in Custom Post Type system to store assistant configurations. This is the **primary, fully-featured, and recommended** implementation.

**File:** `includes/assistants/class-wp-mcp-ai-assistant-cpt.php` (3,365 lines)

### Key Characteristics

- **Always available**: Loaded in both Base and Full versions
- **Full-featured**: Complete admin UI with meta boxes for all assistant settings
- **Extensive functionality**: 
  - Available Tools selection with tool groups
  - Model Defaults (provider, model, temperature, system prompt)
  - Base Knowledge (memory files, vector store ID)
  - Prompt Shortcuts (custom and pre-built)
  - Tool Role Rules (per-tool capability restrictions)
  - API Credentials management
  - External Action configuration
- **REST API integration**: Used throughout `/wp-json/mcp-ai/v1/` endpoints
- **Post type slug**: `mcp_ai_assistant`
- **Admin UI location**: WP Admin → AI Assistants

### Meta Fields (CPT)

The CPT stores extensive configuration through WordPress post meta:

```php
const META_TOOLS                   = '_wp_mcp_ai_tools';
const META_PROVIDER                = '_wp_mcp_ai_provider';
const META_MODEL                   = '_wp_mcp_ai_model';
const META_TEMPERATURE             = '_wp_mcp_ai_temperature';
const META_SYSTEM_PROMPT           = '_wp_mcp_ai_system_prompt';
const META_MEMORY_FILES            = '_wp_mcp_ai_memory_files';
const META_VECTOR_STORE_ID         = '_wp_mcp_ai_vector_store_id';
const META_TOOL_SHORTCUTS          = '_wp_mcp_ai_tool_shortcuts';
const META_TOOL_PREBUILT_SHORTCUTS = '_wp_mcp_ai_tool_prebuilt_shortcuts';
const META_DISABLE_TOOL_SHORTCUTS  = '_wp_mcp_ai_disable_tool_shortcuts';
const META_TOOL_ROLE_RULES         = '_wp_mcp_ai_tool_role_rules';
const META_CREDENTIALS             = WP_MCP_AI_Credentials::META_KEY;
const META_EXTERNAL_ACTION_ID      = '_wp_mcp_ai_external_action_id';
const META_EXTERNAL_ACTION_TYPE    = '_wp_mcp_ai_external_action_type';
```

### When to Use CPT

✅ **Use CPT if:**
- You want the standard WordPress editing experience
- You need full control over all assistant features
- You're using Base Version mode
- You prefer WordPress native data structures
- You want credential management built-in
- You need fine-grained tool role rules
- You're integrating with WordPress-first workflows

### REST API Support

The CPT is the **primary implementation** integrated with the REST API:

```php
// From includes/class-wp-mcp-ai-rest.php
$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
```

All REST endpoints (`/chat`, `/tools`, `/assistants`) use the CPT by default.

### Automatic CCT Synchronization

**New in v1.0.0:** When JetEngine is available (Full Version mode), the CPT automatically synchronizes its settings to the CCT on save. This enables:

- API consumers can use either endpoint
- JetEngine queries have access to assistant data
- Consistency between WordPress and JetEngine data stores
- Seamless migration paths for JetEngine-first workflows

The sync happens automatically in the `save_post` hook and includes:
- Title and description
- Provider and model settings
- System prompt
- Temperature
- Tool configuration (as JSON array)

**Implementation:**
```php
// Automatic sync on CPT save
protected function sync_to_cct( $post_id, $post ) {
    // Maps CPT data to CCT fields
    // Creates or updates CCT item
    // Maintains link via _wp_mcp_ai_cct_item_id meta
}
```

**Linking:** A meta field `_wp_mcp_ai_cct_item_id` stores the relationship between CPT post ID and CCT item ID, ensuring updates modify the correct CCT record.

**Deletion:** When a CPT assistant is deleted, the linked CCT item is automatically removed to maintain data consistency.

## CCT (Custom Content Type) - JetEngine Implementation

### What is it?

The CCT implementation uses JetEngine's Custom Content Types module to store assistant configurations as structured data in JetEngine's database tables.

**File:** `includes/class-wp-mcp-ai-jetengine-assistants-cct.php` (399 lines)

### Key Characteristics

- **JetEngine required**: Only available in Full Version mode when JetEngine is active
- **Simplified fields**: Basic assistant metadata only
- **Automatic provisioning**: Self-registers on plugin activation
- **Lightweight**: Minimal configuration compared to CPT
- **CCT slug**: `assistants`
- **Admin UI location**: JetEngine → Assistants (when JetEngine CCT module is enabled)

### Meta Fields (CCT)

The CCT stores a simplified set of assistant properties:

```php
// Field definitions from get_meta_fields()
'title'         - Assistant display name (required)
'description'   - Assistant description or purpose
'provider'      - AI provider (e.g., openai, gemini, ollama)
'model'         - Model identifier (e.g., gpt-4o-mini)
'system_prompt' - System instructions for the assistant
'temperature'   - Sampling temperature (0-2)
'tools'         - JSON array of enabled tool slugs
```

### Field IDs

CCT fields use ID range `20000+` to avoid conflicts with other JetEngine CCTs:

```php
const FIELD_ID_BASE = 20000;
```

### REST API Configuration

The CCT is configured for REST API access and **receives synchronized data from the CPT**:

```php
'rest_get_enabled'    => true,
'rest_put_enabled'    => true,
'rest_post_enabled'   => true,
'rest_delete_enabled' => true,
'rest_get_access'     => 'manage_options',
'rest_put_access'     => 'edit_posts',
'rest_post_access'    => 'edit_posts',
'rest_delete_access'  => 'edit_posts',
```

JetEngine exposes this at `/wp-json/jet-cct/assistants`. **As of v1.0.0**, this endpoint is automatically populated with data from CPT assistants when they are saved.

### When to Use CCT

✅ **Use CCT endpoint if:**
- You prefer JetEngine's REST API format
- You're building JetEngine-specific integrations
- You want to query assistants using JetEngine's query builder
- You need JetEngine relations with other content types

✅ **The CPT remains the source of truth:**
- Always edit assistants through the WordPress CPT admin interface
- The CCT is automatically kept in sync
- Advanced features (credentials, shortcuts, memory files) are only in CPT
- The sync is one-way: CPT → CCT

❌ **Do NOT use CCT if:**
- You need the assistant to work with the built-in chat interface
- You want to use `/wp-json/mcp-ai/v1/chat` endpoints
- You need credential management
- You need tool role rules
- You need pre-built shortcuts
- You need memory file attachments

### Programmatic Access

If you need to interact with CCT assistants programmatically:

```php
// Get the item handler
$handler = WP_MCP_AI_JetEngine_Assistants_CCT::get_item_handler();

// Get the CCT slug
$slug = WP_MCP_AI_JetEngine_Assistants_CCT::get_slug(); // Returns 'assistants'
```

## Comparison Table

| Feature | CPT (Custom Post Type) | CCT (Custom Content Type) |
|---------|------------------------|---------------------------|
| **Availability** | Always (Base + Full) | Full Version only (JetEngine required) |
| **Data Flow** | Source of truth | Receives synced data from CPT |
| **Admin UI** | Full WordPress editor with meta boxes | JetEngine CCT editor (read synced data) |
| **Lines of Code** | 3,365+ | 399 |
| **Field Count** | 14 meta fields | 7 fields (synced subset) |
| **REST Integration** | ✅ Primary endpoint `/wp-json/mcp-ai/v1/*` | ✅ Secondary endpoint `/wp-json/jet-cct/assistants` |
| **Synchronization** | N/A (is the source) | ✅ Auto-synced on CPT save |
| **Credentials** | ✅ Built-in API credential management | ❌ Not synced |
| **Tool Shortcuts** | ✅ Custom + pre-built shortcuts | ❌ Not synced |
| **Memory Files** | ✅ Media Library attachments | ❌ Not synced |
| **Tool Role Rules** | ✅ Per-tool capability restrictions | ❌ Not synced |
| **Vector Store** | ✅ Vector store ID field | ❌ Not synced |
| **External Actions** | ✅ OpenAI workflow/assistant triggers | ❌ Not synced |
| **Chat Interface** | ✅ Works with `[mcp_ai_chat]` | ⚠️ Partial (via sync) |
| **Elementor Widgets** | ✅ Full integration | ⚠️ Partial (via sync) |
| **Data Storage** | WordPress `wp_posts` + `wp_postmeta` | JetEngine CCT tables |
| **Query Method** | `WP_Query`, `get_post_meta()` | JetEngine item handler |
| **Link Field** | `_wp_mcp_ai_cct_item_id` (stores CCT ID) | Linked to CPT post ID |

## Architecture Comparison

### CPT Architecture

```
WordPress Post (mcp_ai_assistant)
├── Post Title: Assistant name
├── Post Content: Assistant description/context
└── Post Meta (14 fields)
    ├── Tools configuration
    ├── Model defaults
    ├── Memory files
    ├── Shortcuts
    ├── Role rules
    └── Credentials
```

### CCT Architecture

```
JetEngine CCT (assistants)
├── title: Assistant display name
├── description: Assistant description
├── provider: AI provider
├── model: Model identifier
├── system_prompt: System instructions
├── temperature: Sampling temperature
└── tools: JSON array of tool slugs
```

## Migration Considerations

### CPT → CCT

**Automatic in v1.0.0+**  
No manual migration needed! When you save a CPT assistant, it's automatically synced to the CCT.

**What gets synced:**
- Title and description
- Provider and model
- System prompt
- Temperature
- Tool configuration (as JSON)

**What doesn't sync:**
- Credentials (CPT-only feature)
- Tool shortcuts (CPT-only feature)
- Memory files (CPT-only feature)
- Tool role rules (CPT-only feature)
- Vector store ID (CPT-only feature)
- External action config (CPT-only feature)

### CCT → CPT

**Not supported.**  
The CCT is a read-only sync target. Always edit assistants through the CPT interface. Changes made directly to CCT items will be overwritten on the next CPT save.

## Code Examples

### Creating an Assistant (CPT)

```php
// Standard WordPress approach
$assistant_id = wp_insert_post( array(
    'post_type'    => 'mcp_ai_assistant',
    'post_title'   => 'My Assistant',
    'post_content' => 'Description',
    'post_status'  => 'publish',
) );

// Set meta
update_post_meta( $assistant_id, '_wp_mcp_ai_provider', 'openai' );
update_post_meta( $assistant_id, '_wp_mcp_ai_model', 'gpt-4o-mini' );
update_post_meta( $assistant_id, '_wp_mcp_ai_tools', array( 'search_content', 'save_post' ) );
```

### Creating an Assistant (CCT)

```php
// JetEngine approach (requires JetEngine active)
$handler = WP_MCP_AI_JetEngine_Assistants_CCT::get_item_handler();

if ( $handler ) {
    $item_id = $handler->update_item( array(
        'title'         => 'My Assistant',
        'description'   => 'Description',
        'provider'      => 'openai',
        'model'         => 'gpt-4o-mini',
        'tools'         => '["search_content","save_post"]',
    ) );
}
```

### Retrieving Configuration (CPT)

```php
$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

// Returns array with:
// - tools
// - provider
// - model
// - temperature
// - system_prompt
// - memory_files
// - vector_store_id
// - tool_shortcuts
// - tool_prebuilt_shortcuts
// - tool_role_rules
// - disable_prebuilt_shortcuts
// - external_action_identifier
// - external_action_type
```

### Retrieving from CCT

```php
$handler = WP_MCP_AI_JetEngine_Assistants_CCT::get_item_handler();

if ( $handler ) {
    // Find by CPT post ID link
    $cpt_id = 123;
    $cct_item_id = get_post_meta( $cpt_id, '_wp_mcp_ai_cct_item_id', true );
    
    if ( $cct_item_id ) {
        $item = $handler->get_item( $cct_item_id );
        // Returns: title, description, provider, model, system_prompt, temperature, tools (JSON)
    }
}

// Or via JetEngine REST API
// GET /wp-json/jet-cct/assistants/{item_id}
```

### Checking Sync Status

```php
// Check if a CPT assistant has been synced to CCT
$cct_item_id = get_post_meta( $assistant_id, '_wp_mcp_ai_cct_item_id', true );

if ( $cct_item_id ) {
    echo "Synced to CCT item #" . $cct_item_id;
} else {
    echo "Not yet synced (JetEngine may not be available)";
}
```

## Related CCTs in WP oOS

WP oOS also registers another CCT for **chat transcripts** (separate from assistants):

- **Slug**: `ai_chat_transcripts`
- **Purpose**: Store conversation history
- **File**: `includes/class-wp-mcp-ai-jetengine-cct.php`
- **Usage**: Actively used for chat persistence when JetEngine is available

This is different from the assistants CCT and serves a completely different purpose.

## Recommendations

### For Most Users

**Use CPT (Custom Post Type) - Automatic CCT sync included**
- Edit assistants through the WordPress CPT interface (WP Admin → AI Assistants)
- Core features work immediately
- CCT is automatically synced when JetEngine is available
- Full documentation and examples available
- Active REST API support at `/wp-json/mcp-ai/v1/`

### For JetEngine Power Users

**Use CPT + Leverage CCT API**
- Edit in CPT interface (always the source of truth)
- Query via CCT endpoint (`/wp-json/jet-cct/assistants`) when needed
- Use JetEngine relations to link assistants with other CCTs
- Build JetEngine-powered dashboards with synced data

### For API Consumers

**Choose based on your needs:**

**Use `/wp-json/mcp-ai/v1/` (CPT) if:**
- You need full assistant configuration
- You're using credentials for authentication
- You want chat, tools, and directory endpoints

**Use `/wp-json/jet-cct/assistants` (CCT) if:**
- You need basic configuration only
- You prefer JetEngine's REST format
- You're building JetEngine-integrated apps
- You want to query using JetEngine filters

## Bootstrap Information

### CPT Bootstrap

The CPT is always bootstrapped via the main plugin class:

```php
// From wp-mcp-ai.php, line 144
require_once WP_MCP_AI_PATH . 'includes/class-assistant-cpt.php';

// Loaded in main plugin initialization
$this->assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
```

### CCT Bootstrap

The CCT bootstraps itself when the file is loaded (Full Version only):

```php
// From includes/class-wp-mcp-ai-jetengine-assistants-cct.php, line 399
WP_MCP_AI_JetEngine_Assistants_CCT::bootstrap();

// Only loaded in Full Version (wp-mcp-ai.php, line 171)
if ( ! wp_mcp_ai_is_base_version() ) {
    require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-assistants-cct.php';
}
```

## Version Compatibility

| Version | CPT Available | CCT Available |
|---------|--------------|---------------|
| Base Version | ✅ Yes | ❌ No |
| Full Version | ✅ Yes | ✅ Yes (if JetEngine active) |

## Conclusion

**The CPT is the primary, authoritative assistant storage mechanism.** The CCT serves as an automatically-synchronized secondary endpoint for JetEngine-based integrations.

**The Sync Architecture (v1.0.0+):**
- CPT is the source of truth - always edit here
- CCT receives automatic updates on CPT save
- Both REST endpoints are available
- Basic config syncs; advanced features are CPT-only
- Deletion is synchronized (CPT deletion removes CCT item)

When in doubt, **use the CPT**. It provides all features, works everywhere, and automatically populates the CCT when JetEngine is available.

## See Also

- [Assistant Tool Shortcuts](./assistant-tool-shortcuts.md)
- [REST API Reference](./rest-api.md)
- [JetEngine REST API Reference](./jet-engine-rest-routes.md)
- [Base vs Full Version Comparison](./base-vs-full-comparison.md)
