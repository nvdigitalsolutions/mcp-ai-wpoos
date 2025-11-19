# Agentic Workflow Visual Summary

**Quick Reference Diagram for WP oOS Agentic Workflow**

---

## 🔄 The Complete Journey: User to Response

```
👤 USER
 │
 │ Types: "Get my recent posts and current time"
 │
 ▼
┌─────────────────────────────────────────┐
│  💻 FRONTEND (Browser)                  │
│                                         │
│  • Waits 800ms (message bundling)      │
│  • Sends POST to /chat-client          │
│  • Includes: assistant_id, messages    │
└─────────────────────────────────────────┘
 │
 ▼
┌─────────────────────────────────────────┐
│  🔐 AUTHENTICATION                      │
│                                         │
│  Priority:                              │
│  1️⃣ WordPress Nonce (same-origin)      │
│  2️⃣ Bearer Token (cred_xxxxx.SECRET)   │
│  3️⃣ Auth0 JWT (enterprise)             │
└─────────────────────────────────────────┘
 │
 ▼
┌─────────────────────────────────────────┐
│  👤 ASSISTANT LOOKUP                    │
│                                         │
│  • Verify exists & published           │
│  • Check user has capability           │
│  • Load configuration                  │
│    - Provider: OpenAI/Gemini/Ollama   │
│    - Model: GPT-4/Gemini-Pro/etc      │
│    - Tools: [list of enabled tools]   │
│    - Max iterations: 15 (or custom)   │
└─────────────────────────────────────────┘
 │
 ▼
┌─────────────────────────────────────────┐
│  🎯 AGENTIC LOOP                        │
│                                         │
│  Iteration 0:                           │
│  ┌─────────────────────────────────┐   │
│  │ 🤖 Send to LLM                  │   │
│  │ Messages: [user question]       │   │
│  └─────────────────────────────────┘   │
│           ▼                             │
│  ┌─────────────────────────────────┐   │
│  │ 🧠 LLM Decides                  │   │
│  │ "I need 2 tools:"               │   │
│  │ • get_recent_posts              │   │
│  │ • get_current_time              │   │
│  └─────────────────────────────────┘   │
│           ▼                             │
│  ┌─────────────────────────────────┐   │
│  │ 🔧 Execute Tool 1               │   │
│  │ get_recent_posts({limit: 5})    │   │
│  │ → Returns: 5 posts              │   │
│  └─────────────────────────────────┘   │
│           ▼                             │
│  ┌─────────────────────────────────┐   │
│  │ 🔧 Execute Tool 2               │   │
│  │ get_current_time()              │   │
│  │ → Returns: "12:00 UTC"          │   │
│  └─────────────────────────────────┘   │
│           ▼                             │
│  ┌─────────────────────────────────┐   │
│  │ 📝 Add to conversation          │   │
│  │ [user, assistant, tool, tool]   │   │
│  └─────────────────────────────────┘   │
│                                         │
│  Iteration 1:                           │
│  ┌─────────────────────────────────┐   │
│  │ 🤖 Send updated conversation    │   │
│  │ Now LLM has tool results        │   │
│  └─────────────────────────────────┘   │
│           ▼                             │
│  ┌─────────────────────────────────┐   │
│  │ 🧠 LLM Responds                 │   │
│  │ "Here are your 5 posts: ...     │   │
│  │  Current time is 12:00 UTC"     │   │
│  │ No more tools needed ✅          │   │
│  └─────────────────────────────────┘   │
│           ▼                             │
│  Loop ENDS (no tool_calls)              │
└─────────────────────────────────────────┘
 │
 ▼
┌─────────────────────────────────────────┐
│  📦 RESPONSE FORMATTING                 │
│                                         │
│  {                                      │
│    "choices": [{                        │
│      "message": {                       │
│        "content": "Here are..."         │
│      }                                  │
│    }],                                  │
│    "tool_results": [                    │
│      {...posts...},                     │
│      {...time...}                       │
│    ]                                    │
│  }                                      │
└─────────────────────────────────────────┘
 │
 ▼
┌─────────────────────────────────────────┐
│  💾 TRANSCRIPT SAVING                   │
│                                         │
│  • localStorage (24h) ✅                │
│  • JetEngine CCT (optional) ✅          │
└─────────────────────────────────────────┘
 │
 ▼
┌─────────────────────────────────────────┐
│  💻 FRONTEND DISPLAY                    │
│                                         │
│  • Show final response                 │
│  • Tool indicators: ⚙️ ✓ ⚠️            │
│  • Extract attachments                 │
│  • Add download links                  │
└─────────────────────────────────────────┘
 │
 ▼
👤 USER SEES ANSWER
```

---

## 🏗️ System Architecture (Simplified)

```
┌──────────────────────────────────────────────────────────────┐
│                      WP oOS ARCHITECTURE                      │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌────────────┐     ┌────────────┐     ┌────────────┐      │
│  │  Frontend  │────▶│  REST API  │────▶│  Services  │      │
│  │            │     │            │     │            │      │
│  │ • chat.js  │     │ • /chat-   │     │ • Chat     │      │
│  │ • 800ms    │     │   client   │     │ • Assistant│      │
│  │   bundle   │     │ • Auth     │     │ • Tool     │      │
│  └────────────┘     └────────────┘     └────────────┘      │
│                                               │              │
│                                               ▼              │
│                        ┌──────────────────────────────────┐ │
│                        │  ORCHESTRATION LAYER             │ │
│                        │                                  │ │
│                        │  ┌────────────────────────────┐  │ │
│                        │  │  Language Model Router     │  │ │
│                        │  │  • OpenAI / Gemini / Ollama│  │ │
│                        │  └────────────────────────────┘  │ │
│                        │  ┌────────────────────────────┐  │ │
│                        │  │  Tool Registry (65+ tools) │  │ │
│                        │  │  • Capability checking     │  │ │
│                        │  │  • Execution & caching     │  │ │
│                        │  └────────────────────────────┘  │ │
│                        │  ┌────────────────────────────┐  │ │
│                        │  │  Resource Managers         │  │ │
│                        │  │  • Token budgets (TPM/RPM) │  │ │
│                        │  │  • Rate limiting           │  │ │
│                        │  │  • Performance metrics     │  │ │
│                        │  └────────────────────────────┘  │ │
│                        └──────────────────────────────────┘ │
│                                               │              │
│                                               ▼              │
│                        ┌──────────────────────────────────┐ │
│                        │  DATA LAYER                      │ │
│                        │                                  │ │
│                        │  • Assistants (CPT/CCT)          │ │
│                        │  • Transcripts (localStorage/CCT)│ │
│                        │  • Settings (Options)            │ │
│                        └──────────────────────────────────┘ │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

---

## ⚙️ Assistant Components

```
┌──────────────────────────────────────────────────┐
│  🤖 AI ASSISTANT (Custom Post Type)              │
├──────────────────────────────────────────────────┤
│                                                  │
│  Core Properties:                                │
│  ├─ ID: 123                                      │
│  ├─ Name: "Content Helper"                       │
│  ├─ Provider: OpenAI / Gemini / Ollama           │
│  ├─ Model: gpt-4 / gemini-pro / llama2           │
│  ├─ System Prompt: "You are a helpful..."        │
│  ├─ Tools: [get_posts, search, create_post]      │
│  ├─ Max Iterations: 15                           │
│  ├─ Temperature: 0.7                             │
│  └─ Required Capability: edit_posts              │
│                                                  │
│  Storage:                                        │
│  ├─ Primary: WordPress CPT (wp_posts)            │
│  └─ Optional: JetEngine CCT (custom table)       │
│                                                  │
└──────────────────────────────────────────────────┘
```

---

## 🔧 Tool Execution Flow

```
Tool Call Received
       ▼
┌────────────────┐
│ Lookup in      │
│ Tool Registry  │
└────────────────┘
       ▼
┌────────────────┐    ❌ No capability
│ Capability     │───────────────────▶ Return Error
│ Check          │
└────────────────┘
       ▼ ✅
┌────────────────┐
│ Validate       │
│ Arguments      │
└────────────────┘
       ▼
┌────────────────┐    ✅ Cache hit!
│ Check Cache    │───────────────────▶ Return Cached Result
│ (if enabled)   │
└────────────────┘
       ▼ ❌ Cache miss
┌────────────────┐
│ Execute Tool   │
│ tool->execute()│
└────────────────┘
       ▼
┌────────────────┐
│ Format Result  │
│ & Compress     │
│ (if >10KB)     │
└────────────────┘
       ▼
┌────────────────┐
│ Store in Cache │
│ (if idempotent)│
└────────────────┘
       ▼
   Return Result
```

---

## 🎚️ Configuration Priority

```
┌─────────────────────────────────────────┐
│  MAX ITERATIONS CONFIGURATION           │
├─────────────────────────────────────────┤
│                                         │
│  1️⃣ PER-ASSISTANT OVERRIDE (Highest)   │
│     $assistant_meta['max_agentic_...'] │
│                    ▼                    │
│  2️⃣ ADMIN SETTING                      │
│     Settings → WP oOS → Custom AI       │
│                    ▼                    │
│  3️⃣ PROGRAMMATIC FILTER                │
│     wp_mcp_ai_max_agentic_iterations   │
│                    ▼                    │
│  4️⃣ ENDPOINT DEFAULT                   │
│     • /chat-client: 15                 │
│     • /chat: 5                         │
│                    ▼                    │
│  5️⃣ SAFETY BOUNDS                      │
│     min(1, max(50, $iterations))       │
│                                         │
└─────────────────────────────────────────┘
```

---

## 📊 Performance Characteristics

| Scenario | Iterations | Time | Notes |
|----------|-----------|------|-------|
| **Simple Q&A** | 1 | <2s | No tools needed |
| **Single Tool** | 2 | 3-5s | One tool execution |
| **Multi-Tool** | 3-6 | 5-15s | Multiple tools |
| **Complex Workflow** | 5-10 | 10-30s | Many coordinated tools |
| **With Cache Hit** | Same | -50% | Cached tool results |
| **Max Limit** | 15/5 | Varies | Depends on endpoint |

---

## 🎯 Quick Troubleshooting

### Issue: Workflow stops too early
**Check:** Max iterations setting  
**Fix:** Increase in Settings → WP oOS → General Settings → Custom AI Settings (Filters)

### Issue: Tool not executing
**Check:** User capability & tool enabled for assistant  
**Fix:** Enable tool in assistant config or adjust user role

### Issue: Slow performance
**Check:** Too many iterations or large responses  
**Fix:** Enable caching, reduce max iterations, or use SSE streaming

### Issue: Infinite loop
**Check:** Tool returning same result repeatedly  
**Fix:** Review tool logic or reduce max iterations

---

## 📚 Related Documentation

- **[CURRENT-STATE-AGENTIC-WORKFLOW.md](CURRENT-STATE-AGENTIC-WORKFLOW.md)** - Complete detailed guide
- **[agentic-workflow-architecture.md](agentic-workflow-architecture.md)** - Technical architecture
- **[tool-reference.md](tool-reference.md)** - All 65+ tools catalog
- **[rest-api.md](rest-api.md)** - REST API documentation
- **[BEST_PRACTICES.md](BEST_PRACTICES.md)** - Usage best practices

---

**Quick Reference Card**  
Print this page for a visual summary of how WP oOS agentic workflows operate!
