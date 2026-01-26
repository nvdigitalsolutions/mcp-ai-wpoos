# Embedded Client Context Initialization Guide

## Overview

This guide explains how the embedded LLM client (WebLLM) receives and uses context including system instructions, professional prompts, and knowledge files.

## How Context is Passed to Embedded Client

### 1. PHP Configuration (Server-Side)

The assistant configuration is retrieved from WordPress post meta and passed to JavaScript:

```php
// includes/class-wp-mcp-ai-shortcode.php

// Get assistant configuration
$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

// Config includes:
$config['systemPrompt'] = $assistant_config['system_prompt'];      // Line 920
$config['memoryFiles'] = $assistant_config['memory_files'];        // Line 929
$config['vectorStoreId'] = $assistant_config['vector_store_id'];   // Line 932
$config['tools'] = $tool_definitions;                              // Line 1000

// Optional professional prompt from shortcode attribute
if ( ! empty( $atts['profession'] ) ) {
    $config['professionalPrompt'] = $professional_prompt;          // Line 1058
}

// Encoded and added to page as inline script
wp_add_inline_script( 'wp-mcp-ai-chat', 
    'window.wpMcpAiChatInstances["' . $instance_id . '"] = ' . json_encode( $config )
);
```

### 2. JavaScript Initialization (Client-Side)

The chat widget retrieves the configuration and creates the embedded client:

```javascript
// assets/js/chat.js (around line 11454)

// Configuration comes from PHP
const config = window.wpMcpAiChatInstances[instanceId];
const state = {
    config: instanceConfig,
    // ... other state
};

// When embedded provider is used (around line 11462)
// Combine system prompt + professional prompt
var completeSystemPrompt = state.config.systemPrompt || '';
if (state.config.professionalPrompt) {
    completeSystemPrompt = completeSystemPrompt + '\n\n' + state.config.professionalPrompt;
}

// Create assistant config for embedded client
const assistantConfig = {
    systemPrompt: completeSystemPrompt,              // Combined prompt
    tools: state.config.tools || [],                 // Tool definitions
    memoryFiles: state.config.memoryFiles || [],     // Knowledge file IDs
    vectorStoreId: state.config.vectorStoreId        // Vector store ID
};

// Create embedded client instance
state.embeddedClient = new WP_MCP_AI_EmbeddedLLM(instanceId, assistantConfig);
```

### 3. Embedded Client Initialization

The embedded client stores the configuration and initializes the model context:

```javascript
// assets/js/embedded-llm-client.js (around line 172)

constructor(instanceId, config = {}) {
    // Store configuration
    this.systemPrompt = config.systemPrompt || null;
    this.memoryFiles = config.memoryFiles || [];
    this.vectorStoreId = config.vectorStoreId || null;
    this.tools = config.tools || [];
    
    // Compute flags
    this.hasKnowledge = this._hasValidKnowledge(config.memoryFiles, config.vectorStoreId);
    this.hasTools = this._hasValidTools(config.tools);
    this.hasSystemPrompt = !!config.systemPrompt;
}

// After model loads (around line 311)
async initializeModelContext() {
    // Build system prompt with knowledge context
    var systemPromptContent = this.systemPrompt;
    
    if (this.hasKnowledge) {
        var knowledgeContext = this._buildKnowledgeContext();
        systemPromptContent += knowledgeContext;
    }
    
    // Send initialization message to model
    await this.currentEngine.chat.completions.create({
        messages: [
            { role: 'system', content: systemPromptContent },
            { role: 'user', content: 'Understood. I am ready to assist.' }
        ],
        temperature: 0.3,
        max_tokens: 50,
        stream: false
    });
}
```

## How Knowledge Files Work

### Understanding the Knowledge Architecture

**Important**: The embedded client runs entirely in the browser and **cannot directly access server-side knowledge files**. Instead, it uses a RAG (Retrieval Augmented Generation) pattern:

1. **Awareness**: System prompt informs the model that knowledge exists
   ```
   ## Base Knowledge
   
   You have access to the following knowledge base:
   - 3 file(s) in your knowledge base
   - Vector store ID: vs_abc123
   Use this knowledge to provide accurate and contextual responses.
   ```

2. **Retrieval**: Model uses tools to retrieve knowledge when needed
   - Tool: `semantic_content_search` - searches WordPress content using embeddings
   - **Automatically included** when assistant has `memoryFiles` or `vectorStoreId` configured
   - Tool executes server-side and returns relevant content
   - Model receives the content and incorporates it into its response

3. **Response**: Model generates answer using retrieved knowledge

### Automatic Tool Inclusion

When an assistant has knowledge files (`memoryFiles`) or a vector store (`vectorStoreId`) configured, the `semantic_content_search` tool is **automatically added** to the embedded client's available tools. This ensures the RAG pattern works seamlessly without manual configuration.

**What this means:**
- You don't need to manually add `semantic_content_search` to the assistant's tools
- If knowledge exists, the tool is automatically available
- The embedded client can retrieve knowledge content on-demand
- Works for both memory files and vector stores

### Example Flow

```
User: "What are our return policies?"

Model thinks: "I need to search the knowledge base for return policy information"
→ Calls tool: semantic_content_search(query: "return policies", limit: 5)

Server executes search and returns:
→ Results: [
    { title: "Return Policy", content: "Customers can return within 30 days..." },
    { title: "Refund Process", content: "Refunds are processed within 5-7 days..." }
]

Model receives results and generates response:
→ "According to our return policy, customers can return items within 30 days..."
```

## Configuring Context for Your Assistant

### 1. System Prompt (Base Instructions)

Set in WordPress Admin → Assistants → Edit Assistant → System Prompt

```
You are a helpful customer service assistant for Acme Corp.
You should always be polite, professional, and concise.
```

### 2. Primary Roles (Professional Identity)

Set in WordPress Admin → Assistants → Edit Assistant → Primary Roles

These are automatically converted to a prompt and prepended to your system prompt:

```
# Primary Role: Customer Service Expert
# Secondary Role: Sales Support

[Your system prompt here]
```

### 3. Professional Prompt (Shortcode Override)

Use the `profession` attribute in the shortcode:

```
[mcp_ai_chat assistant_id="123" profession="456"]
```

This adds an additional layer on top of the assistant's system prompt.

### 4. Memory Files (Knowledge Base)

Set in WordPress Admin → Assistants → Edit Assistant → Memory Files

- Upload files or select existing files
- File IDs are stored in `_wp_mcp_ai_memory_files` post meta
- File IDs are passed to client as `memoryFiles` array
- **`semantic_content_search` tool is automatically added** when knowledge files are configured
- Model uses `semantic_content_search` tool to retrieve content when needed (RAG pattern)

### 5. Vector Store (Advanced Knowledge)

Set in WordPress Admin → Assistants → Edit Assistant → Vector Store ID

- Create vector store using OpenAI API or WordPress tools
- Store ID is passed to client as `vectorStoreId` string
- **`semantic_content_search` tool is automatically added** when vector store is configured
- Model can use vector search tools to query the store

## Troubleshooting

### Issue: "hasKnowledge: false" in logs

**Possible Causes:**
1. Assistant doesn't have memory files configured
   - Solution: Edit assistant and add memory files in WordPress admin
2. `memoryFiles` array is empty
   - Check: `state.config.memoryFiles` in browser console
   - Solution: Verify files are properly saved in post meta

### Issue: Professional prompt not included

**Possible Causes:**
1. Shortcode doesn't have `profession` attribute
   - Solution: Add `profession="123"` to shortcode
2. Profession ID is invalid
   - Check: Verify profession post exists and is published
3. `professionalPrompt` is not in config
   - Check: `state.config.professionalPrompt` in browser console

### Issue: System prompt too short

**Possible Causes:**
1. System prompt field is empty in assistant settings
   - Solution: Add system prompt in WordPress admin
2. Primary roles not configured
   - Solution: Add primary roles to assistant

## Diagnostic Logging

### Enable Debug Mode

Diagnostic logs are only shown when debug mode is enabled to avoid exposing configuration details in production.

**Option 1: Add to page HTML**
```html
<script>
window.wpMcpAiChatDebugMode = true;
</script>
<!-- Chat widget here -->
```

**Option 2: Add to theme functions.php**
```php
add_action( 'wp_footer', function() {
    ?>
    <script>
    window.wpMcpAiChatDebugMode = true;
    </script>
    <?php
}, 5 ); // Priority 5 ensures it runs before chat scripts
```

**Option 3: Browser console**
```javascript
window.wpMcpAiChatDebugMode = true;
// Then reload the page
```

**Security Note**: Debug mode exposes system configuration details (prompt lengths, file counts, etc.) in browser console. Only enable for troubleshooting, then disable.

### Enable Diagnostic Logs

The latest version includes comprehensive diagnostic logging. **Enable debug mode first** (see above), then look for these logs in browser console:

```javascript
// When creating embedded client (with debug mode enabled)
[NV oOS] Creating embedded client with state.config: {
    hasSystemPrompt: true,
    systemPromptLength: 250,
    systemPromptPreview: "You are a helpful...",
    hasProfessionalPrompt: true,
    professionalPromptLength: 150,
    hasMemoryFiles: true,
    memoryFilesLength: 3,
    memoryFilesCount: 3,  // File IDs not logged for security
    hasVectorStoreId: true,
    hasTools: true,
    toolsCount: 5
}

// After preparing config (with debug mode enabled)
[NV oOS] Prepared assistantConfig for embedded client: {
    hasSystemPrompt: true,
    systemPromptLength: 450,  // Combined: assistant + professional + knowledge
    systemPromptPreview: "You are a helpful...\n\n# Professional Role...",
    hasMemoryFiles: true,
    memoryFilesCount: 3,
    hasVectorStoreId: true,
    hasTools: true,
    toolsCount: 5
}

// Client creation (always logged)
[NV oOS Embedded Client] Created new instance: {
    instanceId: "chat-1704-...",
    hasSystemPrompt: true,
    systemPromptLength: 450,
    hasTools: true,
    toolCount: 5,
    hasKnowledge: true,
    memoryFileCount: 3
}
```

**Note**: For security reasons, full system prompts, file IDs, vector store IDs, and tool definitions are only logged in debug mode. Production logs show counts and flags only.

### What to Check

1. **state.config values**: Should show all values from PHP
2. **assistantConfig values**: Should show combined/enhanced values
3. **Client instance values**: Should match assistantConfig
4. **hasKnowledge flag**: Should be `true` if memoryFiles OR vectorStoreId exists

### Reporting Issues

If knowledge/files are not being included, provide:
1. Full diagnostic logs from browser console
2. Assistant ID
3. Shortcode used
4. Expected vs actual behavior

## Best Practices

### 1. Layer Your Prompts Strategically

```
System Prompt (Base):     "You are a helpful assistant for Acme Corp."
↓
Primary Roles:            "# Role: Customer Service Expert"
↓
Professional Prompt:      "# Specialty: Technical Support"
↓
Knowledge Context:        "## Base Knowledge: 3 files available"
```

### 2. Keep System Prompts Focused

- **Do**: Clear, specific instructions
- **Don't**: Include knowledge content directly (use knowledge files instead)

### 3. Use Knowledge Files for Large Content

- **Do**: Store product catalogs, FAQs, policies in knowledge files
- **Don't**: Put entire documents in system prompt

### 4. Test Incrementally

1. Start with basic system prompt
2. Add primary roles
3. Add professional prompt
4. Add knowledge files
5. Add tools

Test after each step to verify behavior.

### 5. Monitor Token Usage

The embedded client has limited context (typically 4K-128K tokens). Be mindful of:
- System prompt length
- Number of knowledge files mentioned
- Number of tools available
- Conversation history

## Technical Reference

### Configuration Flow Diagram

```
WordPress Database
    ↓
WP_MCP_AI_Assistant_CPT::get_assistant_configuration()
    ↓
WP_MCP_AI_Shortcode::render_shortcode()
    ↓
wp_add_inline_script() → window.wpMcpAiChatInstances[id]
    ↓
JavaScript: init() → state.config
    ↓
sendChatEmbedded() → assistantConfig
    ↓
new WP_MCP_AI_EmbeddedLLM(instanceId, assistantConfig)
    ↓
constructor() → this.systemPrompt, this.memoryFiles, etc.
    ↓
loadModel() → initializeModelContext()
    ↓
Model initialized with complete context
```

### Key Files

- **PHP Config**: `includes/class-wp-mcp-ai-shortcode.php` (lines 865-1086)
- **JS Init**: `assets/js/chat.js` (lines 11454-11545)
- **Client**: `assets/js/embedded-llm-client.js` (lines 172-452)
- **Assistant CPT**: `includes/assistants/class-wp-mcp-ai-assistant-cpt.php` (lines 4676-4780)

### Database Schema

```
wp_postmeta
├── _wp_mcp_ai_system_prompt        (text)
├── _wp_mcp_ai_memory_files         (array of file IDs)
├── _wp_mcp_ai_vector_store_id      (string)
├── _wp_mcp_ai_tools                (array of tool slugs)
└── _wp_mcp_ai_primary_roles        (array of role IDs)
```

## FAQs

**Q: Why can't the embedded client access knowledge files directly?**  
A: Security and architecture. Files are server-side resources. The client uses tools to retrieve knowledge server-side.

**Q: What's the difference between system prompt and professional prompt?**  
A: System prompt is the assistant's base instructions. Professional prompt is an additional layer from the shortcode `profession` attribute.

**Q: How many knowledge files can I configure?**  
A: No hard limit, but be mindful of token usage. The system prompt just mentions the count, not the full content.

**Q: Can I use both memoryFiles and vectorStoreId?**  
A: Yes! They're complementary. memoryFiles are individual file references, vectorStoreId is a collection.

**Q: Why is my system prompt so short?**  
A: Check if the assistant has a system prompt configured in WordPress admin. Also check primary roles.

## Support

For issues or questions:
1. Check diagnostic logs first
2. Verify assistant configuration in WordPress admin
3. Review this guide's troubleshooting section
4. Open an issue with full diagnostic logs

---

Last updated: 2026-01-26
Version: 1.1.0
