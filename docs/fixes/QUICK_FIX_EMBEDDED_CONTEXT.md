# Quick Fix Guide - Embedded Client Context Issue

## The Issue

Your logs show:
```
hasKnowledge: false
memoryFileCount: 0
systemPromptLength: 16 ("Your name is bob")
```

This means the embedded client is not receiving knowledge files or a complete system prompt.

## Most Likely Causes (in order of probability)

### 1. Assistant Has No Knowledge Files Configured ⭐ MOST LIKELY

**Check:**
1. Go to WordPress Admin → Assistants
2. Edit the assistant (ID 1704 based on your logs)
3. Scroll to "Memory Files" or "Base Knowledge" section
4. Are there any files selected?

**Fix:**
- Add memory files to the assistant
- OR if you don't need knowledge files, this is expected behavior

### 2. Shortcode Missing Profession Attribute

**Check:**
Look at your shortcode:
```
[mcp_ai_chat assistant_id="1704" ...]
```

**Fix:**
If you want a professional prompt, add:
```
[mcp_ai_chat assistant_id="1704" profession="123"]
```
where 123 is the ID of a profession post.

### 3. System Prompt Is Actually That Short

**Check:**
1. Go to WordPress Admin → Assistants  
2. Edit assistant (ID 1704)
3. Check "System Prompt" field
4. Is it just "Your name is bob"?

**Fix:**
- Add more detailed instructions
- OR this is expected if you want a minimal system prompt

## Quick Test - Verify Configuration

### Step 0: Enable Debug Mode (Required for Diagnostic Logs)

Add this to your page **before** the chat widget loads:

```html
<script>
window.wpMcpAiChatDebugMode = true;
</script>
```

Or add to your theme's `functions.php`:

```php
add_action( 'wp_footer', function() {
    ?>
    <script>
    window.wpMcpAiChatDebugMode = true;
    </script>
    <?php
}, 5 );
```

**Note**: Debug mode exposes configuration details in browser console. Only enable for troubleshooting, then remove.

### Step 1: Rebuild JavaScript

You made changes to chat.js which needs to be bundled:

```bash
cd /path/to/mcp-ai-wpoos
npm run build
# or for production:
npm run build:pro
```

### Step 2: Rebuild and Test

You made changes to chat.js which needs to be bundled:

```bash
cd /path/to/mcp-ai-wpoos
npm run build
# or for production:
npm run build:pro
```

### Step 3: Check Assistant Configuration

Open browser console and run:

```javascript
// Get the config for your assistant
const config = window.wpMcpAiChatInstances['wp-mcp-ai-chat-1704']; // Adjust ID

console.log('System Prompt:', config.systemPrompt);
console.log('Professional Prompt:', config.professionalPrompt);
console.log('Memory Files:', config.memoryFiles);
console.log('Vector Store ID:', config.vectorStoreId);
console.log('Tools:', config.tools);
```

### Step 4: Look for New Diagnostic Logs

After rebuilding and enabling debug mode, refresh the page and look for these logs in console:

**Important**: Logs only appear when `window.wpMcpAiChatDebugMode = true` is set.

```
[NV oOS] Creating embedded client with state.config:
```

This will show you EXACTLY what values are present (counts only, not full content for security).

## What Each Component Does

### System Prompt (Required for meaningful responses)
- **Where**: WordPress Admin → Edit Assistant → System Prompt
- **Purpose**: Base instructions for the AI
- **Example**: "You are a helpful customer service assistant..."
- **In logs**: `systemPromptLength` should be > 100 characters typically

### Professional Prompt (Optional - from shortcode attribute)
- **Where**: Shortcode attribute `profession="123"`
- **Purpose**: Adds professional role on top of system prompt
- **Example**: "# Role: Technical Support Specialist..."
- **In logs**: `professionalPromptLength` should show if present

### Memory Files (Optional - for knowledge base)
- **Where**: WordPress Admin → Edit Assistant → Memory Files
- **Purpose**: Tell model it can access knowledge via tools
- **Example**: 3 uploaded documents about products
- **In logs**: `memoryFilesLength` should be > 0, `hasKnowledge: true`

### Vector Store (Optional - advanced knowledge)
- **Where**: WordPress Admin → Edit Assistant → Vector Store ID
- **Purpose**: Advanced knowledge storage with OpenAI
- **Example**: "vs_abc123xyz"
- **In logs**: `vectorStoreIdValue` should show the ID

## Expected Log Output

### Good Configuration (All Context Present)

```javascript
[NV oOS] Creating embedded client with state.config: {
    hasSystemPrompt: true,
    systemPromptLength: 250,
    systemPromptPreview: "You are a helpful...",  // First 100 chars
    hasProfessionalPrompt: true,
    professionalPromptLength: 150,
    professionalPromptPreview: "# Role: Technical...",  // First 100 chars
    hasMemoryFiles: true,
    memoryFilesLength: 3,
    memoryFilesCount: 3,  // Count only, IDs not logged for security
    hasVectorStoreId: true,
    // Vector store ID not logged for security
    hasTools: true,
    toolsCount: 5  // Count only, definitions not logged for security
}
```

**Note**: With debug mode enabled, you'll see counts and truncated previews, not full sensitive values.

### Minimal Configuration (No Knowledge, No Professional Prompt)

```javascript
[NV oOS] Creating embedded client with state.config: {
    hasSystemPrompt: true,
    systemPromptLength: 50,
    systemPromptPreview: "Your name is bob",
    hasProfessionalPrompt: false,
    hasMemoryFiles: false,
    memoryFilesLength: 0,
    memoryFilesCount: 0,
    hasVectorStoreId: false,
    hasTools: false,
    toolsCount: 0
}
```

### Your Current Configuration (From Logs)

```javascript
{
    hasSystemPrompt: true,
    systemPromptLength: 16,  // ⚠️ Very short!
    // Professional prompt: NOT SHOWN (probably false)
    // Memory files: NOT SHOWN (probably [])
    hasKnowledge: false,     // ⚠️ No knowledge configured
    memoryFileCount: 0       // ⚠️ No files
}
```

## Action Items

1. ✅ **Rebuild JavaScript** - Already done with the new logging
2. ⬜ **Check Assistant Configuration** - Do you have knowledge files added?
3. ⬜ **Test and Share Logs** - Run with rebuilt code and share console logs
4. ⬜ **Based on logs, we'll identify exact issue**

## Understanding Knowledge Files

**Important**: Embedded clients can't directly access file content (security). Instead:

1. System prompt tells model: "You have 3 files in your knowledge base"
2. **`semantic_content_search` tool automatically added** when knowledge exists
3. User asks question
4. Model thinks: "I should search the knowledge base"
5. Model calls tool: `semantic_content_search(query: "user question")`
6. Tool runs on server, searches files, returns relevant content
7. Model uses content to answer question

This is called **RAG** (Retrieval Augmented Generation).

**Important**: As of this update, the `semantic_content_search` tool is automatically included when an assistant has memory files or a vector store configured. You don't need to manually add it!

## Quick Decision Tree

```
Is systemPromptLength > 50 characters?
├─ NO → Check WordPress Admin → Edit Assistant → System Prompt field
│       Add more detailed instructions
│
└─ YES → Is hasKnowledge: true?
    ├─ NO → Do you NEED knowledge files?
    │   ├─ YES → Add files in WordPress Admin → Edit Assistant → Memory Files
    │   └─ NO → This is expected, no action needed
    │
    └─ YES → Is professionalPrompt shown in logs?
        ├─ NO → Do you NEED professional prompt?
        │   ├─ YES → Add profession="123" to shortcode
        │   └─ NO → This is expected, no action needed
        │
        └─ YES → Everything looks good! ✅
```

## Still Stuck?

Share these from your browser console:

1. The full output of:
   ```javascript
   console.log(window.wpMcpAiChatInstances);
   ```

2. Look for and copy:
   ```
   [NV oOS] Creating embedded client with state.config:
   [NV oOS] Prepared assistantConfig for embedded client:
   [NV oOS Embedded Client] Created new instance:
   ```

3. Assistant ID and shortcode you're using

## Reference

- **Full Guide**: `docs/EMBEDDED_CLIENT_CONTEXT_GUIDE.md`
- **Changed Files**: `assets/js/chat.js` (added diagnostic logging)
- **PR**: Review the changes in the pull request for details

---

Last updated: 2026-01-26  
Issue: Embedded client context initialization  
Status: Diagnostic logging added, awaiting user test
