# WP oOS Architecture Quick Reference

**For**: GitHub Copilot and Developers  
**Version**: 1.0.0  
**Last Updated**: 2025-11-11

---

## 🚀 Quick Navigation

- **Full Guide**: [COPILOT_ARCHITECTURE_GUIDE.md](./COPILOT_ARCHITECTURE_GUIDE.md) (3,652 lines)
- **Verification Report**: [ARCHITECTURE_VERIFICATION_REPORT.md](./ARCHITECTURE_VERIFICATION_REPORT.md)
- **Main Documentation**: [DOCUMENTATION_INDEX.md](./DOCUMENTATION_INDEX.md)

---

## 📁 File Structure (Quick Map)

```
mcp-ai-wpoos/
├── mcp-ai-wpoos.php           # Core plugin file (loaded by base version)
├── wp-mcp-ai-base.php         # Base version entry point (renamed to mcp-ai-wpoos-base.php in base distribution)
├── includes/
│   ├── services/              # 6 business logic services
│   ├── repositories/          # 3 data access repositories
│   ├── tools/                 # 73 tool implementations
│   ├── admin/sections/        # 14 settings sections
│   ├── rest/                  # REST API components
│   ├── class-wp-mcp-ai-rest.php           # Main REST controller (6,951 lines)
│   ├── class-wp-mcp-ai-tool-registry.php  # Tool registry (969 lines)
│   ├── class-wp-mcp-ai-container.php      # DI container
│   ├── class-wp-mcp-ai-language-model-router.php  # AI client factory
│   ├── class-wp-mcp-ai-openai-client.php  # OpenAI provider
│   ├── class-wp-mcp-ai-gemini-client.php  # Gemini provider
│   ├── class-wp-mcp-ai-ollama-client.php  # Ollama provider
│   └── class-wp-mcp-ai-anthropic-client.php # Anthropic provider
├── assets/                    # JavaScript and CSS
├── tests/                     # PHPUnit tests
└── docs/                      # Documentation
```

---

## 🏗️ Architecture Patterns

### 1. Dependency Injection
```php
$container = WP_MCP_AI_Container::get_instance();
$service = $container->get( 'service.chat' );
```

### 2. Service Layer
```php
$chat_service = wp_mcp_ai_get_chat_service();
$result = $chat_service->process_chat( $messages, $assistant_id );
```

### 3. Repository Layer
```php
$assistant_repo = $container->get( 'repository.assistant' );
$assistant = $assistant_repo->find( $assistant_id );
```

### 4. Tool System
```php
class My_Tool implements WP_MCP_AI_Tool_Interface {
    public function get_slug() { return 'my_tool'; }
    public function execute( $args, $context ) { /* ... */ }
}
```

### 5. Settings Section
```php
class My_Section extends WP_MCP_AI_Settings_Section {
    public function get_id() { return 'my_section'; }
    public function get_tab() { return 'general'; }
    public function get_fields() { return array( /* ... */ ); }
}
```

---

## 🔑 Key Classes

### Services (Business Logic)
- `WP_MCP_AI_Chat_Service` - Chat processing
- `WP_MCP_AI_Assistant_Service` - Assistant management
- `WP_MCP_AI_Tool_Service` - Tool orchestration
- `WP_MCP_AI_File_Service` - File handling

### Repositories (Data Access)
- `WP_MCP_AI_Assistant_Repository` - Assistant CRUD
- `WP_MCP_AI_Credential_Repository` - Credential management
- `WP_MCP_AI_Settings_Repository` - Settings storage

**Note**: Currently 3 repositories implemented (Phase 4 refactoring). Additional entities that should have repositories:
- AI Peers (federation)
- Chat Transcripts (history)
- Rate Limits (model limits)
- Performance Metrics (monitoring)
- Job Queue (background tasks)

### Core Components
- `WP_MCP_AI_Container` - DI container
- `WP_MCP_AI_Tool_Registry` - Tool registry
- `WP_MCP_AI_REST` - REST API controller
- `WP_MCP_AI_Language_Model_Router` - AI client factory

### AI Clients
- `WP_MCP_AI_OpenAI_Client` - OpenAI GPT
- `WP_MCP_AI_Gemini_Client` - Google Gemini
- `WP_MCP_AI_Ollama_Client` - Local Ollama
- `WP_MCP_AI_Anthropic_Client` - Anthropic Claude

---

## 🌐 REST API

**Namespace**: `mcp-ai/v1`

**Endpoints**:
- `POST /chat` - Chat completion
- `GET /assistants` - List assistants
- `GET /tools` - List tools
- `POST /tools/execute` - Execute tool
- `GET /sse` - SSE streaming

**Authentication** (4 methods):
1. WordPress Nonce (`X-WP-Nonce`)
2. Credentials (`Authorization: Bearer cred_...`)
3. Auth0 JWT (`Authorization: Bearer eyJ...`)
4. Guest Token (`X-WP-MCP-AI-Guest`)

---

## 🛠️ Tool System

**Base Interface**: `WP_MCP_AI_Tool_Interface`

**Required Methods**:
- `get_slug()` - Unique identifier
- `get_name()` - Human-readable name
- `get_description()` - What tool does
- `get_parameters_schema()` - JSON Schema
- `execute( $args, $context )` - Run tool

**Optional Interfaces**:
- `WP_MCP_AI_Tool_Shortcuts_Interface`
- `WP_MCP_AI_Tool_Capability_Flags_Interface`
- `WP_MCP_AI_Tool_Flow_Stage_Interface`
- `WP_MCP_AI_Tool_Context_Restrictions_Interface`

**Registration**:
```php
add_action( 'wp_mcp_ai_register_tools', function( $registry ) {
    $registry->register_tool( new My_Tool() );
} );
```

---

## ⚙️ Admin Dashboard

**Pattern**: Modular sections

**Base Class**: `WP_MCP_AI_Settings_Section`

**Tabs**:
- Overview, General, Providers, Authentication
- Tools, Orchestration, Integrations, Security
- Performance, Advanced

**Sections**: 14 total

**Registration**:
```php
WP_MCP_AI_Settings_Registry::register_section( new My_Section() );
```

---

## 🔐 Security

**Input Sanitization**:
```php
sanitize_text_field()
sanitize_email()
esc_url_raw()
absint()
wp_kses_post()
```

**Output Escaping**:
```php
esc_html()
esc_attr()
esc_url()
wp_json_encode()
```

**Capability Checks**:
```php
if ( ! current_user_can( 'manage_options' ) ) {
    return new WP_Error( 'insufficient_permissions' );
}
```

**Nonce Verification**:
```php
check_ajax_referer( 'wp_mcp_ai_admin_nonce', 'nonce' );
wp_verify_nonce( $_POST['_wpnonce'], 'action_name' );
```

---

## 🎣 Hooks & Filters

### Actions
```php
do_action( 'wp_mcp_ai_loaded' );
do_action( 'wp_mcp_ai_register_tools', $registry );
do_action( 'wp_mcp_ai_before_chat', $messages, $assistant_id );
do_action( 'wp_mcp_ai_after_chat', $response, $messages, $assistant_id );
```

### Filters
```php
apply_filters( 'wp_mcp_ai_chat_capability', 'edit_posts', $assistant_id );
apply_filters( 'wp_mcp_ai_available_tools', $tools, $assistant_id );
apply_filters( 'wp_mcp_ai_chat_messages', $messages, $assistant_id );
apply_filters( 'wp_mcp_ai_rate_limit', 10, $user_id, $assistant_id );
```

---

## 📝 Naming Conventions

### Classes
```php
WP_MCP_AI_Class_Name  // Snake_Case with prefix
```

### Functions
```php
wp_mcp_ai_function_name()  // snake_case with prefix
```

### Files
```php
class-wp-mcp-ai-class-name.php       # Classes
interface-wp-mcp-ai-name.php         # Interfaces
abstract-wp-mcp-ai-name.php          # Abstract classes
trait-wp-mcp-ai-name.php             # Traits
component-init.php                    # Initialization
```

### Constants
```php
WP_MCP_AI_CONSTANT_NAME  // SCREAMING_SNAKE_CASE
```

### WordPress Options
```php
wp_mcp_ai_option_name  // snake_case with prefix
```

---

## 🔄 Data Flow

### Chat Request Flow
```
Client → REST API → Authenticator → Validator → 
Rate Limiter → Chat Service → AI Router → AI Client → 
Tool Service (if needed) → Response
```

### Tool Execution Flow
```
Request → Authentication → Tool Registry → 
Capability Check → Parameter Validation → 
Tool::execute() → Result Formatting → Response
```

### Settings Save Flow
```
Form Submit → AJAX Handler → Capability Check → 
Section Validation → Section Sanitization → 
Settings Repository → Database → Action Hook
```

---

## 📊 Statistics

- **Total Files**: 150+ PHP files
- **Total Lines**: ~50,000+ lines
- **Tools**: 73 implementations
- **Sections**: 14 admin sections
- **Services**: 6 business services
- **Repositories**: 3 data repositories
- **AI Providers**: 5 supported
- **REST Endpoints**: 5+ documented
- **Auth Methods**: 4 supported
- **Documentation**: 3,652 lines

---

## 🚦 Development Workflow

### Adding a New Tool
1. Create `class-wp-mcp-ai-tool-name.php`
2. Implement `WP_MCP_AI_Tool_Interface`
3. Auto-registered or use `wp_mcp_ai_register_tools` hook
4. Add tests in `tests/test-tool-name.php`

### Adding a Settings Section
1. Create `class-wp-mcp-ai-section-name.php`
2. Extend `WP_MCP_AI_Settings_Section`
3. Register in `settings-dashboard-init.php`

### Adding a REST Endpoint
1. Add route in `WP_MCP_AI_REST::register_routes()`
2. Implement handler method
3. Add permission callback
4. Add tests

---

## ✅ Code Quality

### Linting
```bash
composer run lint              # WordPress Coding Standards
composer run lint:compat       # PHP compatibility check
composer run format            # Auto-fix code style
```

### Testing
```bash
composer run test:install      # Setup test environment
composer run test              # Run PHPUnit tests
```

### Build
```bash
composer run pot               # Generate translation template
```

---

## 📚 Resources

- **Architecture Guide**: Complete framework documentation (3,652 lines)
- **Verification Report**: Structural audit results
- **Tool Reference**: docs/tool-reference.md
- **REST API Docs**: docs/rest-api.md
- **Best Practices**: docs/BEST_PRACTICES.md
- **Code Review**: docs/CODE-REVIEW-MASTER.md

---

**Quick Tip**: For detailed information on any component, refer to the full [COPILOT_ARCHITECTURE_GUIDE.md](./COPILOT_ARCHITECTURE_GUIDE.md).
