# Phase 3 Visual Architecture Guide - REST Controller Refactoring

## Current State vs Future State

### Before Phase 3 (Current - 7,289 lines)
```
┌─────────────────────────────────────────────────────────┐
│          WP_MCP_AI_REST (Monolithic)                    │
│                    7,289 lines                          │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐ │
│  │ MCP Clients  │  │   Browser    │  │ Admin Tools  │ │
│  │ (Claude,LM)  │  │    Chat      │  │   (Cron)     │ │
│  └──────────────┘  └──────────────┘  └──────────────┘ │
│         ↓                 ↓                  ↓          │
│  ┌──────────────────────────────────────────────────┐  │
│  │         10 REST Endpoints Mixed Together         │  │
│  │  /assistants  /chat  /chat-client  /tools        │  │
│  │  /mcp  /sse  /transcripts  /files  /cron-status  │  │
│  └──────────────────────────────────────────────────┘  │
│         ↓                 ↓                  ↓          │
│  ┌──────────────────────────────────────────────────┐  │
│  │     Authentication Logic Repeated 10x            │  │
│  │     Validation Logic Repeated 10x                │  │
│  │     Error Formatting Repeated 10x                │  │
│  └──────────────────────────────────────────────────┘  │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

### After Phase 3 (Target - 4 Focused Controllers)
```
                   ┌─────────────────────────────────────┐
                   │  WP_MCP_AI_REST_Controller_Base     │
                   │      (Abstract Base Class)          │
                   │          265 lines                  │
                   ├─────────────────────────────────────┤
                   │ • Multi-client authentication       │
                   │ • Error/Success formatting          │
                   │ • Request validation                │
                   │ • Permission checks                 │
                   │ • Parameter sanitization            │
                   └────────────┬────────────────────────┘
                                │
                 ┌──────────────┼──────────────┬─────────┐
                 │              │              │         │
    ┌────────────▼────┐  ┌─────▼──────┐  ┌───▼────┐  ┌─▼──────┐
    │ Chat Controller │  │    MCP     │  │ Tools  │  │ Admin  │
    │   ~800 lines    │  │ Controller │  │ Ctrl   │  │  Ctrl  │
    ├─────────────────┤  ├────────────┤  ├────────┤  ├────────┤
    │ /chat           │  │ /mcp       │  │ /tools │  │ /cron  │
    │ /chat-client    │  │ /sse       │  │        │  │        │
    │ /transcripts    │  │ /assistants│  │        │  │        │
    └─────────────────┘  └────────────┘  └────────┘  └────────┘
         │                    │              │           │
         ↓                    ↓              ↓           ↓
    ┌────────────┐      ┌──────────┐   ┌────────┐  ┌────────┐
    │  Browser   │      │  Remote  │   │  Both  │  │ Admin  │
    │   Chat     │      │   MCP    │   │ Client │  │  Only  │
    │  Clients   │      │ Clients  │   │  Types │  │        │
    └────────────┘      └──────────┘   └────────┘  └────────┘
```

## Multi-Client Authentication Flow

```
┌─────────────────────────────────────────────────────────────┐
│                  REST Request Arrives                       │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ↓
            ┌────────────────────────────┐
            │ WP_MCP_AI_REST_Authenticator│
            │  (Shared Authentication)   │
            └────────────┬───────────────┘
                         │
         ┌───────────────┼───────────────┐
         │               │               │
         ↓               ↓               ↓
  ┌─────────────┐ ┌────────────┐ ┌────────────┐
  │   Bearer    │ │  WordPress │ │   Guest    │
  │   Token     │ │   Cookie   │ │   Token    │
  │             │ │            │ │            │
  │ MCP Clients │ │  Browser   │ │  Public    │
  │ (Claude,LM) │ │   Users    │ │   Chat     │
  └──────┬──────┘ └─────┬──────┘ └─────┬──────┘
         │              │              │
         └──────────────┼──────────────┘
                        │
                        ↓
         ┌──────────────────────────┐
         │   Auth Context Stored    │
         │  {                       │
         │    user_id: 123,         │
         │    auth_type: 'bearer',  │
         │    is_guest: false       │
         │  }                       │
         └──────────────┬───────────┘
                        │
                        ↓
         ┌──────────────────────────┐
         │  Controller Handler      │
         │  (Chat, MCP, Tools, etc) │
         └──────────────────────────┘
```

## Phase 3 Timeline & Status

```
✅ Week 1: Base Controller (COMPLETE)
├─ Abstract base class created
├─ Multi-client auth support
├─ 11 unit tests passing
├─ SSE compatible responses
└─ 265 lines, 0 breaking changes

📅 Week 2: Chat Controller (NEXT)
├─ Extract /chat endpoint
├─ Extract /chat-client endpoint
├─ Extract /chat-transcripts
├─ Different iteration limits
│  ├─ MCP: 5 iterations
│  └─ Browser: 15 iterations
└─ SSE streaming preserved

📅 Week 3: MCP Protocol Controller
├─ Extract /mcp (JSON-RPC 2.0)
├─ Extract /sse (streaming)
├─ Extract /assistants (directory)
└─ Strict MCP 2024-11-05 compliance

📅 Week 4: Tools & Admin Controllers
├─ Extract /tools endpoint
├─ Extract /cron-status
└─ Extract /files/{id}/download
```

## Client Type Matrix

| Client Type | Auth Method | Endpoints Used | Use Case |
|-------------|-------------|----------------|----------|
| **Claude Desktop** | Bearer token | `/mcp`, `/chat`, `/tools` | Remote MCP client |
| **LM Studio** | Bearer token | `/mcp`, `/sse`, `/assistants` | Local AI client |
| **Browser Chat** | WordPress cookie | `/chat-client`, `/transcripts` | Site visitors |
| **Public Chat** | Guest token | `/chat-client` only | Anonymous users |
| **Admin Dashboard** | Cookie + capability | `/cron-status`, all endpoints | Site admins |

## Security Layers

```
┌──────────────────────────────────────────────────┐
│         Request Arrives at Endpoint              │
└────────────────────┬─────────────────────────────┘
                     │
                     ↓
         ┌───────────────────────┐
         │  1. Authentication    │  ← Base Controller
         │     Check             │     permissions_check_*()
         └───────────┬───────────┘
                     │
                     ↓
         ┌───────────────────────┐
         │  2. Capability        │  ← WordPress Core
         │     Verification      │     current_user_can()
         └───────────┬───────────┘
                     │
                     ↓
         ┌───────────────────────┐
         │  3. Input             │  ← Base Controller
         │     Sanitization      │     sanitize_*()
         └───────────┬───────────┘
                     │
                     ↓
         ┌───────────────────────┐
         │  4. Request           │  ← REST Validator
         │     Validation        │     validate_*()
         └───────────┬───────────┘
                     │
                     ↓
         ┌───────────────────────┐
         │  5. Controller        │  ← Child Controller
         │     Handler Execute   │     handle_*()
         └───────────┬───────────┘
                     │
                     ↓
         ┌───────────────────────┐
         │  6. Response          │  ← Base Controller
         │     Formatting        │     success() / error()
         └───────────┬───────────┘
                     │
                     ↓
         ┌───────────────────────┐
         │  7. Nefarious Usage   │  ← Background Monitor
         │     Monitoring        │     (Post-response)
         └───────────────────────┘
```

## File Structure After Phase 3

```
includes/
├── rest/
│   ├── class-wp-mcp-ai-rest-controller-base.php    (265 lines) ✅
│   ├── class-wp-mcp-ai-rest-chat-controller.php    (~800 lines) 📅
│   ├── class-wp-mcp-ai-rest-mcp-controller.php     (~600 lines) 📅
│   ├── class-wp-mcp-ai-rest-tools-controller.php   (~400 lines) 📅
│   ├── class-wp-mcp-ai-rest-admin-controller.php   (~300 lines) 📅
│   ├── class-wp-mcp-ai-rest-authenticator.php      (existing)
│   ├── class-wp-mcp-ai-rest-validator.php          (existing)
│   └── class-wp-mcp-ai-sse-handler.php            (existing)
│
├── class-wp-mcp-ai-rest.php                        (~1,500 lines after)
│   └── (Becomes router/delegator to child controllers)
│
tests/
├── test-rest-controller-base.php                   (300 lines) ✅
├── test-rest-chat-controller.php                   📅
├── test-rest-mcp-controller.php                    📅
└── test-rest-tools-controller.php                  📅
```

## Success Metrics

| Metric | Before | After Phase 3 | Improvement |
|--------|--------|---------------|-------------|
| **Largest File** | 7,289 lines | <1,000 lines | 86% reduction |
| **Controllers** | 1 monolithic | 5 focused | 5x modularity |
| **Test Coverage** | Difficult | Per-controller | Better isolation |
| **Code Reuse** | Duplicated | Base class | DRY principle |
| **Maintainability** | Low | High | Easier changes |
| **Breaking Changes** | - | 0 | 100% compatible |

---

**Phase 3.1 Complete** ✅  
**Next: Phase 3.2 - Chat Controller Extraction**
