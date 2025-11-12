# Understanding "3 Data Access Repositories" in WP oOS

**Question**: What does "Repositories: 3 data access repositories" mean?

---

## Quick Answer

**Repositories** are specialized PHP classes that handle **data access and persistence** in the plugin. They form the **data access layer** that sits between business logic (services) and data storage (WordPress database).

**"3 Data Access Repositories"** refers to three specific repository classes:

1. **Assistant Repository** - Manages AI assistant configurations
2. **Credential Repository** - Manages authentication credentials  
3. **Settings Repository** - Manages plugin settings and options

---

## What is the Repository Pattern?

### Definition

The **Repository Pattern** is a design pattern that abstracts data access logic into dedicated classes. Instead of services directly querying the database, they use repositories.

### Why Use Repositories?

**Without Repositories** (tightly coupled):
```php
// Service directly queries database - BAD
class Chat_Service {
    public function get_assistant( $id ) {
        $post = get_post( $id );  // Direct database access
        $model = get_post_meta( $id, '_model', true );
        // More database calls...
        return array( 'post' => $post, 'model' => $model );
    }
}
```

**With Repositories** (loosely coupled):
```php
// Service uses repository - GOOD
class WP_MCP_AI_Chat_Service {
    protected $assistant_repository;
    
    public function __construct( $assistant_repository ) {
        $this->assistant_repository = $assistant_repository;
    }
    
    public function get_assistant( $id ) {
        return $this->assistant_repository->find( $id );
    }
}
```

### Benefits

1. **Separation of Concerns**: Business logic separated from data access
2. **Single Responsibility**: Each repository handles one entity type
3. **Testability**: Easy to mock repositories for unit testing
4. **Maintainability**: Database changes don't affect services
5. **Consistency**: All data access follows same patterns

---

## The 3 Repositories Explained

### 1. Assistant Repository

**Purpose**: Manages AI assistant configurations

**What it stores**:
- Assistant name and description
- AI model selection (GPT-4, Gemini, etc.)
- Provider settings (OpenAI, Google, etc.)
- Enabled tools
- System instructions
- Temperature and token settings

**Data Source**: WordPress Custom Post Type (`mcp_ai_assistant`)

**Key Methods**:
- `find( $id )` - Get assistant by ID
- `find_all()` - List all assistants
- `save( $data )` - Create/update assistant
- `delete( $id )` - Delete assistant

**Example**:
```php
$repo = new WP_MCP_AI_Assistant_Repository();

// Create new assistant
$id = $repo->save( array(
    'post_title' => 'Support Bot',
    'meta_input' => array(
        '_wp_mcp_ai_model' => 'gpt-4',
        '_wp_mcp_ai_provider' => 'openai',
    ),
) );

// Retrieve assistant
$assistant = $repo->find( $id );
```

### 2. Credential Repository

**Purpose**: Manages API authentication credentials

**What it stores**:
- Bearer tokens for API access
- Token creation dates
- Last used timestamps
- Revocation status

**Data Source**: Post meta (encrypted and hashed)

**Key Methods**:
- `generate( $assistant_id )` - Create new credential
- `verify( $token )` - Validate credential
- `revoke( $assistant_id, $cred_id )` - Revoke credential
- `find_by_assistant( $id )` - List assistant credentials

**Security**:
- Tokens hashed with `password_hash()`
- Plaintext shown only once during generation
- Token format: `cred_{ID}.{SECRET}`

**Example**:
```php
$repo = new WP_MCP_AI_Credential_Repository();

// Generate new credential
$credential = $repo->generate( 123, 'Mobile App' );
echo $credential['token'];  // cred_abc123.xyz789secret (shown once!)

// Verify credential
$valid = $repo->verify( 'cred_abc123.xyz789secret' );
if ( $valid ) {
    // Token is valid, proceed with request
}
```

### 3. Settings Repository

**Purpose**: Manages plugin-wide settings

**What it stores**:
- API keys (OpenAI, Gemini, etc.)
- Default model settings
- Logging preferences
- Rate limiting configuration
- Integration settings

**Data Source**: WordPress Options API

**Key Methods**:
- `get( $key, $default )` - Retrieve setting
- `set( $key, $value )` - Save setting
- `delete( $key )` - Remove setting
- `get_all()` - Get all settings

**Example**:
```php
$repo = new WP_MCP_AI_Settings_Repository();

// Save API key
$repo->set( 'openai_api_key', 'sk-...' );

// Retrieve setting
$api_key = $repo->get( 'openai_api_key' );

// Get all settings
$all_settings = $repo->get_all();
```

---

## How Repositories Work with Services

### Architecture Flow

```
Controller (REST/AJAX)
    ↓
Service (Business Logic)
    ↓
Repository (Data Access)
    ↓
WordPress Database
```

### Example: Chat Service Uses Assistant Repository

```php
class WP_MCP_AI_Chat_Service {
    protected $assistant_repository;
    protected $ai_client;
    
    public function __construct( $assistant_repository, $ai_client ) {
        $this->assistant_repository = $assistant_repository;
        $this->ai_client = $ai_client;
    }
    
    public function process_chat( $messages, $assistant_id ) {
        // 1. Service asks repository for data
        $assistant = $this->assistant_repository->find( $assistant_id );
        
        if ( ! $assistant ) {
            return new WP_Error( 'assistant_not_found' );
        }
        
        // 2. Service uses data for business logic
        $model = $assistant['model'];
        $provider = $assistant['provider'];
        
        // 3. Service calls AI provider
        return $this->ai_client->chat( $model, $provider, $messages );
    }
}
```

**Key Points**:
- Service doesn't know HOW data is stored (CPT, meta, options)
- Service only knows WHAT data it needs
- Repository handles all database operations

---

## Dependency Injection

Repositories are injected into services via the DI Container:

```php
// Container registration
$container->register( 'repository.assistant', function() {
    return new WP_MCP_AI_Assistant_Repository();
} );

$container->register( 'service.chat', function() use ( $container ) {
    return new WP_MCP_AI_Chat_Service(
        $container->get( 'repository.assistant' ),
        $container->get( 'router' )
    );
} );

// Usage
$chat_service = $container->get( 'service.chat' );
```

---

## Why 3 Repositories?

**Current Implementation**: The plugin currently has 3 repositories as part of **Phase 4 refactoring** (Milestone 9). This is an **incremental architectural improvement**.

**The 3 Implemented Repositories**:
1. **Assistants** - Most critical entity, complex with multiple properties
2. **Credentials** - Security-sensitive data requiring special handling
3. **Settings** - Plugin-wide configuration

**Should There Be More Repositories?**

**Yes!** You're correct to ask this question. The plugin has several other data entities that would benefit from the repository pattern:

**Entities Currently Missing Repositories**:

1. **AI Peer Repository** (for federation)
   - Current: `WP_MCP_AI_AI_Peer_CPT` class
   - Stores: Federation peer site information
   - Data Source: Custom Post Type `mcp_ai_ai_peer`

2. **Chat Transcript Repository** (for conversation history)
   - Current: `WP_MCP_AI_Chat_Transcript_Recorder` class
   - Stores: Chat conversations and responses
   - Data Source: JetEngine CCT or custom tables

3. **Rate Limits Repository** (for model limits)
   - Current: `WP_MCP_AI_Model_Rate_Limits_CCT` class
   - Stores: API rate limit tracking
   - Data Source: JetEngine CCT

4. **Performance Metrics Repository** (for monitoring)
   - Current: `WP_MCP_AI_Performance_Monitor_CCT` class
   - Stores: Performance and usage metrics
   - Data Source: JetEngine CCT

5. **Job Queue Repository** (for background tasks)
   - Current: `WP_MCP_AI_Job_Queue_Manager` class
   - Stores: Asynchronous job queue
   - Data Source: WordPress transients/options

**Why Not All Implemented Yet?**

The repository layer is being added **incrementally**:

1. **Phase 4, Milestone 9**: Initial 3 core repositories (✅ Complete)
2. **Future Phases**: Additional repositories will be added
3. **Gradual Migration**: Legacy code being refactored over time
4. **Backward Compatibility**: Existing code continues to work during transition

**Current Architecture State**:
```
✅ Service Layer: 6 services (complete)
⚠️  Repository Layer: 3 of ~8 repositories (38% complete)
✅ DI Container: Fully implemented
```

**Hybrid Approach** (current state):
- New code uses repositories (assistants, credentials, settings)
- Legacy code uses direct database access (peers, transcripts, metrics)
- Both patterns coexist during migration

**Could we have more repositories?**
- **Absolutely!** The pattern is designed to be extensible
- Additional repositories planned for future releases
- Each new repository makes testing and maintenance easier

**Why not one big repository?**
- **Single Responsibility Principle**: Each class has one job
- **Easier to test**: Smaller, focused classes
- **Better organization**: Clear separation of concerns
- **Flexibility**: Can change one without affecting others

**Recommended Future Work**:

For complete architectural consistency, these entities should be migrated to repositories:

| Priority | Entity | Current Implementation | Benefit |
|----------|--------|----------------------|---------|
| High | AI Peers | CPT class | Better federation data access |
| High | Chat Transcripts | Recorder class | Easier querying and testing |
| Medium | Rate Limits | CCT class | Centralized rate limit logic |
| Medium | Performance Metrics | CCT class | Better performance querying |
| Low | Job Queue | Manager class | Improved job management |

**Summary**: The "3 repositories" represents the **initial phase** of repository pattern adoption, not the final architecture. More repositories should (and likely will) be added as the codebase evolves.

---

## Repository vs. Direct Database Access

### Direct Access (Avoid)

```php
// Service directly queries database
$post = get_post( $assistant_id );
$model = get_post_meta( $assistant_id, '_wp_mcp_ai_model', true );
$provider = get_post_meta( $assistant_id, '_wp_mcp_ai_provider', true );
```

**Problems**:
- Service knows database structure
- Hard to test (mocking WordPress functions is difficult)
- Changes to storage require updating all services
- No centralized validation

### Repository Access (Recommended)

```php
// Service uses repository
$assistant = $this->assistant_repository->find( $assistant_id );
$model = $assistant['model'];
$provider = $assistant['provider'];
```

**Benefits**:
- Service doesn't know database structure
- Easy to test (mock repository)
- Storage changes isolated to repository
- Centralized validation in repository

---

## Testing with Repositories

Repositories make unit testing much easier:

```php
// Mock repository for testing
class Mock_Assistant_Repository {
    public function find( $id ) {
        return array(
            'id' => $id,
            'model' => 'gpt-4',
            'provider' => 'openai',
        );
    }
}

// Test service with mock
$mock_repo = new Mock_Assistant_Repository();
$service = new WP_MCP_AI_Chat_Service( $mock_repo, $ai_client );

$result = $service->process_chat( $messages, 123 );
// Test passes without touching database!
```

---

## Summary

**"3 Data Access Repositories"** = 3 specialized classes that abstract data access:

| Repository | Manages | Data Source |
|------------|---------|-------------|
| **Assistant Repository** | AI assistant configs | Custom Post Type |
| **Credential Repository** | API credentials | Post Meta (encrypted) |
| **Settings Repository** | Plugin settings | WordPress Options |

**Key Concept**: Repositories are the **only** classes that directly access the database. Services use repositories, never direct database calls.

**Pattern**: Repository Pattern provides separation between business logic and data access, making code more maintainable, testable, and flexible.

---

For complete technical details, see:
- **Full Guide**: [COPILOT_ARCHITECTURE_GUIDE.md](./COPILOT_ARCHITECTURE_GUIDE.md) - Section 9
- **Quick Reference**: [ARCHITECTURE_QUICK_REFERENCE.md](./ARCHITECTURE_QUICK_REFERENCE.md)
