# Separation of Concerns Violations - Visual Guide

**Quick reference with diagrams and examples**

---

## 📊 Violation Severity Map

```
┌─────────────────────────────────────────────────────────────┐
│                    CRITICAL VIOLATIONS                       │
├─────────────────────────────────────────────────────────────┤
│ 🔴 REST Controller (7,151 lines)                            │
│    ├─ Routing                                               │
│    ├─ Authentication                                         │
│    ├─ Validation                                             │
│    ├─ Business Logic                                         │
│    ├─ Data Access (SQL queries)                             │
│    ├─ Response Formatting                                    │
│    ├─ File Handling                                          │
│    └─ SSE Streaming                                          │
│                                                               │
│ 🔴 Admin Settings (5,191 lines)                             │
│    ├─ UI Rendering                                           │
│    ├─ AJAX Handling                                          │
│    ├─ Validation                                             │
│    ├─ OAuth Management                                       │
│    ├─ Settings Storage                                       │
│    └─ Data Queries                                           │
│                                                               │
│ 🔴 Hard-coded Dependencies (42 instances)                   │
│    └─ Direct 'new ClassName()' instead of DI                │
│                                                               │
│ 🔴 Data Access Violations                                   │
│    ├─ Controllers with SQL                                   │
│    ├─ Tools with $wpdb                                       │
│    ├─ Services with get_option()                            │
│    └─ Widgets with get_posts()                              │
│                                                               │
│ 🔴 Static Method Abuse                                      │
│    └─ 10 classes with 15+ static methods                    │
└─────────────────────────────────────────────────────────────┘
```

---

## 🏗️ Current Architecture (Problematic)

```
┌──────────────────────────────────────────────────────────┐
│                    REST Controller                        │
│  ┌────────────────────────────────────────────────────┐  │
│  │ • Handles HTTP requests                            │  │
│  │ • Authenticates users         ← MIXED CONCERNS    │  │
│  │ • Validates input                                  │  │
│  │ • Executes business logic                          │  │
│  │ • Queries database directly   ← WRONG LAYER       │  │
│  │ • Formats responses                                │  │
│  │ • Streams SSE                                      │  │
│  │ • Manages files                                    │  │
│  └────────────────────────────────────────────────────┘  │
│           │                                               │
│           │ Direct instantiation                          │
│           ├──> new Authenticator()  ← HARD-CODED         │
│           ├──> new Validator()                            │
│           └──> global $wpdb         ← DATABASE ACCESS     │
└──────────────────────────────────────────────────────────┘
```

---

## ✅ Recommended Architecture (Proper SoC)

```
┌─────────────────────────────────────────────────────────────┐
│                     PRESENTATION LAYER                       │
├─────────────────────────────────────────────────────────────┤
│  Chat Controller       Assistant Controller    Tool Controller│
│  (200 lines)          (150 lines)             (180 lines)     │
│  • Route /chat        • Route /assistants     • Route /tools  │
│  • Accept request     • Accept request        • Accept request│
│  • Call service       • Call service          • Call service  │
│  • Format response    • Format response       • Format response│
└──────────┬──────────────────────┬────────────────────┬────────┘
           │                      │                    │
           │ Dependency Injection │                    │
           ▼                      ▼                    ▼
┌─────────────────────────────────────────────────────────────┐
│                      SERVICE LAYER                           │
├─────────────────────────────────────────────────────────────┤
│  Chat Service         Assistant Service      Tool Service    │
│  • Process messages   • Manage assistants    • Execute tools  │
│  • Apply logic        • Check permissions    • Validate args  │
│  • Coordinate flow    • Business rules       • Handle errors  │
└──────────┬──────────────────────┬────────────────────┬────────┘
           │                      │                    │
           │ Use repositories     │                    │
           ▼                      ▼                    ▼
┌─────────────────────────────────────────────────────────────┐
│                    REPOSITORY LAYER                          │
├─────────────────────────────────────────────────────────────┤
│  Chat Repository    Assistant Repository   Settings Repository│
│  • Data access      • CRUD operations      • Configuration    │
│  • Query DB         • Abstract storage     • Options API      │
│  • Cache mgmt       • Handle metadata      • Type conversion  │
└─────────────────────────────────────────────────────────────┘
           │                      │                    │
           ▼                      ▼                    ▼
┌─────────────────────────────────────────────────────────────┐
│                      DATA LAYER                              │
├─────────────────────────────────────────────────────────────┤
│  WordPress Database        Options API        Custom Tables  │
└─────────────────────────────────────────────────────────────┘
```

---

## 🔍 Example: REST Controller Violation

### ❌ Current (Violates SoC)

```php
class WP_MCP_AI_REST {
    public function handle_chat_request( $request ) {
        // 1. Authentication (should be middleware)
        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error( 'forbidden' );
        }
        
        // 2. Validation (should be in validator)
        $messages = sanitize_text_field( $request['messages'] );
        
        // 3. Business Logic (should be in service)
        $settings = get_option( 'wp_mcp_ai_settings' );
        $api_key = $settings['openai_key'];
        
        // 4. Data Access (should be in repository)
        global $wpdb;
        $wpdb->insert( 'chat_logs', $data );
        
        // 5. External API call (should be in service)
        $response = $this->call_openai( $api_key, $messages );
        
        // 6. Response formatting (should be in transformer)
        return $this->format_response( $response );
    }
}
```

### ✅ Recommended (Proper SoC)

```php
class WP_MCP_AI_Chat_Controller {
    private $chat_service;
    private $validator;
    
    public function __construct( 
        Chat_Service $chat_service,
        Request_Validator $validator 
    ) {
        $this->chat_service = $chat_service;
        $this->validator = $validator;
    }
    
    public function handle_chat_request( WP_REST_Request $request ) {
        // Controller only coordinates, doesn't do work
        $validated = $this->validator->validate( $request );
        
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }
        
        $result = $this->chat_service->process_chat(
            $validated['messages'],
            $validated['assistant_id']
        );
        
        return rest_ensure_response( $result );
    }
}
```

---

## 🔍 Example: Hard-coded Dependencies

### ❌ Problem

```php
class WP_MCP_AI_REST {
    public function __construct() {
        // Hard-coded instantiation
        $this->authenticator = new WP_MCP_AI_REST_Authenticator();
        $this->validator     = new WP_MCP_AI_REST_Validator();
        
        // Cannot test with mocks!
        // Cannot swap implementations!
    }
}
```

### ✅ Solution

```php
class WP_MCP_AI_Chat_Controller {
    private $chat_service;
    private $authenticator;
    private $validator;
    
    public function __construct(
        Chat_Service $chat_service,
        REST_Authenticator $authenticator,
        Request_Validator $validator
    ) {
        $this->chat_service  = $chat_service;
        $this->authenticator = $authenticator;
        $this->validator     = $validator;
    }
    
    // Now fully testable with mocks!
    // Can easily swap implementations!
}

// Registered in container
$container->register( 'controller.chat', function( $c ) {
    return new WP_MCP_AI_Chat_Controller(
        $c->get( 'service.chat' ),
        $c->get( 'rest.authenticator' ),
        $c->get( 'rest.validator' )
    );
} );
```

---

## 🔍 Example: Data Access Violation

### ❌ Controller with SQL

```php
// includes/class-wp-mcp-ai-rest.php:1352
public function handle_chat_transcript_delete( $request ) {
    global $wpdb;
    
    // WRONG: Controller accessing database directly
    $deleted = $wpdb->delete(
        $table,
        array(
            'session_key'   => $session_key,
            'cct_author_id' => $user_id,
        ),
        array( '%s', '%d' )
    );
    
    return rest_ensure_response( array( 'deleted' => $deleted ) );
}
```

### ✅ Using Repository

```php
// Controller
public function handle_chat_transcript_delete( WP_REST_Request $request ) {
    $session_key = $request->get_param( 'session_key' );
    $user_id = get_current_user_id();
    
    // Use repository for data access
    $result = $this->transcript_repository->delete_session(
        $session_key,
        $user_id
    );
    
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    
    return rest_ensure_response( $result );
}

// Repository (new class)
class WP_MCP_AI_Transcript_Repository {
    public function delete_session( $session_key, $user_id ) {
        global $wpdb;
        
        $table = $this->get_table_name();
        
        $deleted = $wpdb->delete(
            $table,
            array(
                'session_key'   => $session_key,
                'cct_author_id' => $user_id,
            ),
            array( '%s', '%d' )
        );
        
        if ( false === $deleted ) {
            return new WP_Error( 'delete_failed', 'Could not delete transcript' );
        }
        
        return array( 'deleted' => $deleted );
    }
}
```

---

## 🔍 Example: Static Method Abuse

### ❌ Problem

```php
// 44 static methods in one class!
class WP_MCP_AI_Tool_Token_Limits {
    public static function get_limit( $tool_slug ) {
        // Hidden dependency on global state
        $limits = get_option( 'wp_mcp_ai_tool_limits' );
        return $limits[ $tool_slug ] ?? null;
    }
    
    public static function set_limit( $tool_slug, $limit ) {
        // Modifies global state
        $limits = get_option( 'wp_mcp_ai_tool_limits', array() );
        $limits[ $tool_slug ] = $limit;
        update_option( 'wp_mcp_ai_tool_limits', $limits );
    }
    
    // Cannot mock in tests!
    // Cannot inject dependencies!
}
```

### ✅ Solution

```php
class WP_MCP_AI_Tool_Token_Limits_Service {
    private $settings_repository;
    
    public function __construct( Settings_Repository $settings_repository ) {
        $this->settings_repository = $settings_repository;
    }
    
    public function get_limit( $tool_slug ) {
        $limits = $this->settings_repository->get( 'tool_limits', array() );
        return $limits[ $tool_slug ] ?? null;
    }
    
    public function set_limit( $tool_slug, $limit ) {
        $limits = $this->settings_repository->get( 'tool_limits', array() );
        $limits[ $tool_slug ] = $limit;
        $this->settings_repository->set( 'tool_limits', $limits );
    }
    
    // Now testable with mock repository!
}
```

---

## 📈 Refactoring Progress Tracker

```
Phase 1: Data Access (Weeks 1-3)
┌────────────────────────────────────────────┐
│ ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░ │ 0%
└────────────────────────────────────────────┘

Phase 2: Dependency Injection (Weeks 4-5)  
┌────────────────────────────────────────────┐
│ ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░ │ 0%
└────────────────────────────────────────────┘

Phase 3: Split Controllers (Weeks 6-9)
┌────────────────────────────────────────────┐
│ ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░ │ 0%
└────────────────────────────────────────────┘

Overall Progress
┌────────────────────────────────────────────┐
│ ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░ │ 0%
└────────────────────────────────────────────┘
```

---

## 🎯 Quick Decision Tree

```
Need to add new feature?
│
├─ Does it need data?
│  └─ YES → Use/Create Repository
│     └─ NO → Continue
│
├─ Does it have business logic?
│  └─ YES → Use/Create Service
│     └─ NO → Continue
│
├─ Does it handle HTTP?
│  └─ YES → Use/Create Controller
│     └─ NO → Continue
│
└─ Does it need UI?
   └─ YES → Create View/Template
      └─ NO → Create Utility Class
```

---

## 📚 Related Documents

- **Full Analysis**: `SEPARATION_OF_CONCERNS_VIOLATIONS.md`
- **Executive Summary**: `SEPARATION_OF_CONCERNS_SUMMARY.md`
- **This Visual Guide**: `SEPARATION_OF_CONCERNS_VISUAL.md`

---

**Updated**: 2025-11-13  
**Status**: Initial Review Complete
