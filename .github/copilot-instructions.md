# Copilot Instructions for Open Operator System (NV oOS)

This repository contains **Open Operator System (NV oOS)**, a WordPress plugin that provides an AI Assistant framework integrating with OpenAI GPT models, Gemini, Ollama, and MCP (Model Context Protocol) tools.

## Multi-Agent Awareness

This repo is developed by multiple AI agents. Before doing work, be aware of:

- **`AGENTS.md`** — single source of truth for the agent inventory, context-loading strategy, and inter-agent coordination rules.
- **`CLAUDE.md`** — Claude Code's per-turn context (naming conventions, PHP-compat, security, tool patterns, architecture).
- **`.github/agents/*.agent.md`** — GitHub Custom Agents auto-discovered by Copilot Coding Agent and compatible runtimes. Each file declares one role-specific agent (its scope, tools, triggers). Per the layering rule in [`AGENTS.md` §2](../AGENTS.md), these files are intentionally slim — they **do not** restate naming/security/PHP-compat rules, so always cross-reference `AGENTS.md` + `CLAUDE.md` + `.context/` for those.
- **`.context/*.md`** — subsystem context files loaded on-demand by all agents (conventions, security checklist, REST API, tool registry, chat UI, testing, Pro vs Base).
- **`.bmad/agents/*.yaml`** — internal BMAD workflow agents that run inside NV oOS assistants (not coding-time agents).
- **`.agents/skills/*.md`** — 52 coding-time agent skills for Zed editor: 20 wp-* WordPress plugin development patterns (wp-abilities-api, wp-action-scheduler, wp-html-api, wp-i18n-audit, wp-plugin-*, wp-query-cache, wp-rest-api, wp-security-*, wp-utf8-text), 31 design-* skills, and the mcp-ai-wpoos-plugin operational guide.

When making changes, check `git log` first — another agent may have already addressed part of the task. Do not duplicate scopes that are owned by an agent declared in `.github/agents/` or in the `AGENTS.md` inventory.

**Folder context (per-folder READMEs):** Every PHP-bearing subdirectory under `includes/` and `addons/pro/includes/` ships a `README.md` declaring that folder's purpose, public surface, neighbors, and which `.context/*.md` files to load alongside it. **When editing files in `includes/<folder>/`, read `includes/<folder>/README.md` first.** Convention details in [`docs/developer/folder-readme-convention.md`](../docs/developer/folder-readme-convention.md); enforcement via `composer run docs:check-folder-readmes` (part of `composer run ci:all`).


## Repository Structure

```
mcp-ai-wpoos/
├── includes/              # Core plugin classes
│   ├── admin/            # Admin UI and settings
│   ├── assistants/       # Assistant CPT and CCT management
│   ├── tools/            # ~1,559 total built-in tool implementations
│   ├── security/         # Security infrastructure (10 classes: request guard, security posture, destructive ops gate, URL guard, concurrency guard, cost tracker, API key store, CSP headers, audit logger, load guard)
│   ├── elementor/        # Elementor widget integrations
│   ├── okf/              # OKF v0.2 engine (parser, reader, writer)
│   ├── integrations/     # Third-party plugin integrations
│   ├── bridge/           # WordPress adapters for lib/core domain contracts
│   └── crawler/          # Crawl4AI integration
├── lib/core/              # Framework-agnostic AI engine (nvoos/core): 32 domain contracts, ChatOrchestrator, ProviderRouter, 109 tools
├── assets/
│   ├── js/               # Frontend JavaScript (chat UI)
│   └── css/              # Stylesheets
├── tests/                # PHPUnit test suite (including tests/security/)
├── docs/                 # Comprehensive documentation (~1,600 files)
├── bin/                  # Development scripts
├── .agents/skills/       # 52 coding-time agent skills for Zed editor (20 wp-* + 31 design-* + mcp-ai-wpoos-plugin)
├── .bmad/                # 6 BMAD workflow agent YAML definitions
├── .context/             # Subsystem context files for AI agent sessions
└── languages/            # Translation files
```

## Key Technologies

- **WordPress Plugin** (PHP 7.4+, WordPress 6.0+)
- **AI Providers**: OpenAI, Google Gemini, Ollama (local AI)
- **MCP Protocol**: Server-Sent Events, REST API
- **Optional Integrations**: JetEngine, WooCommerce, Elementor, Rank Math, WPCode
- **OKF (Open Knowledge Format)**: Google's v0.1 vendor-neutral knowledge format for curated, deterministic knowledge with 6 MCP tools
- **nvoos/core**: Framework-agnostic AI orchestration engine with Hexagonal Architecture (Ports & Adapters), 32 domain contracts, 21 WordPress adapters
- **Security Infrastructure**: 10 security classes (request guard, security posture scoring with 21 signals, destructive ops gate, URL guard, concurrency guard, cost tracker, API key store, CSP headers, audit logger, load guard) with Site Health integration
- **Architecture**: Custom Post Types, REST API, Server-Sent Events, Hexagonal Architecture

## Development Workflow

### Initial Setup

```bash
# Install PHP dependencies (PHPUnit, WordPress Coding Standards)
composer install

# Install JavaScript dependencies (ESLint)
npm install

# Set up WordPress test environment (run once)
composer run test:install
```

### Testing

```bash
# Run PHPUnit test suite
composer run test

# Run specific test file
vendor/bin/phpunit tests/test-assistant-tools.php

# Run tests with coverage (requires Xdebug)
vendor/bin/phpunit --coverage-html coverage/
```

### Linting

```bash
# PHP linting (WordPress Coding Standards)
composer run lint

# Auto-fix PHP code style issues
composer run format

# PHP compatibility check (PHP 7.4-8.3)
composer run lint:compat

# JavaScript linting
npm run lint:js

# Auto-fix JavaScript issues
npm run lint:js:fix
```

### Building

```bash
# Generate translation template
composer run pot
```

### Local Development

**Option 1: Docker (Recommended)**
```bash
docker compose up -d
# Access at http://localhost:8000
# MySQL: wordpress/wordpress/wordpress
```

**Option 2: Codex Environment**
```bash
bin/codex-startup.sh
# Access at http://localhost:8000
# Admin: admin/password
```

## Coding Standards

### PHP Standards

- **WordPress Coding Standards**: Follow WPCS rules strictly
- **Naming Conventions**:
  - Classes: `WP_MCP_AI_Class_Name` (snake_case with WP_MCP_AI prefix)
  - Functions: `wp_mcp_ai_function_name()` (snake_case with prefix)
  - Hooks: `wp_mcp_ai_hook_name` (snake_case with prefix)
- **Security**:
  - Always sanitize input with `sanitize_text_field()`, `absint()`, etc.
  - Always escape output with `esc_html()`, `esc_url()`, etc.
  - Check capabilities before privileged operations
  - Use nonces for form submissions
  - Validate file uploads and MIME types
- **Documentation**: All classes, methods, and functions must have PHPDoc blocks
- **Tool authoring rules (Unix Theory P0–P6)**: Tool `execute()` returns the canonical envelope (success array or `WP_Error`, **never** `array( 'success' => false, ... )`) and obeys the two-gate sanitisation rule (sanitize `$arguments[...]` at entry, escape every value at exit). Two custom PHPCS sniffs (`WPMCPAI.Tools.CanonicalReturnEnvelope`, `WPMCPAI.Tools.SanitizeAtEntry`) enforce this at severity 5. See [`CLAUDE.md`](../CLAUDE.md) → "Tool Return Format — Canonical Envelope" and "Tool Sanitisation — Two-Gate Rule" for full rationale and examples.

### JavaScript Standards

- **WordPress ESLint Plugin**: Follow `@wordpress/eslint-plugin` rules
- **Code Style**:
  - Use tabs for indentation
  - Single quotes for strings
  - jQuery compatibility for WordPress environments
- **Security**:
  - Sanitize user input before DOM insertion
  - Use `wp.i18n` for translatable strings
  - Validate API responses before processing

## Architecture Patterns

### Tool System

Tools are the core extensibility mechanism. Each tool:
- Extends the tool registry pattern
- Implements `execute()` method
- Declares required capabilities
- Has a unique slug identifier
- Located in `includes/tools/class-wp-mcp-ai-tool-*.php`

Example tool structure:
```php
class WP_MCP_AI_Tool_Example extends WP_MCP_AI_Tool_Base {
    public function get_slug() {
        return 'example_tool';
    }
    
    public function get_definition() {
        return array(
            'name' => 'Example Tool',
            'description' => 'Tool description',
            'required_capability' => 'edit_posts',
        );
    }
    
    public function execute( $arguments, $context ) {
        // Tool implementation
    }
}
```

### REST API Endpoints

The plugin exposes MCP-compliant REST endpoints at `/wp-json/mcp-ai/v1/`:
- `GET /assistants` - List available assistants (with SSE support)
- `POST /chat` - Send chat messages with streaming responses
- `POST /tools` - Execute tools directly
- `GET /sse` - Server-Sent Events endpoint for streaming

### Authentication

Three authentication methods are supported:
1. **WordPress Nonce**: For same-origin requests (`X-WP-Nonce` header)
2. **Assistant Credentials**: Plugin-issued bearer tokens (`Authorization: Bearer cred_xxxxx.SECRET`)
3. **Auth0 Tokens**: For enterprise integrations (`Authorization: Bearer <Auth0-token>`)
4. **Guest Tokens**: Temporary tokens for public chat surfaces (`X-WP-MCP-AI-Guest` header)

### Data Storage

- **Assistants**: Custom Post Type (`mcp_ai_assistant`) with optional JetEngine CCT sync
- **Chat Transcripts**: Browser localStorage (24h) + optional JetEngine CCT (permanent)
- **Settings**: WordPress options with `wp_mcp_ai_` prefix
- **Credentials**: Hashed tokens in post meta

## Testing Guidelines

### Test Organization

Tests are organized by feature/component:
- `test-*.php` - Unit tests for specific components
- `tests/rest/` - REST API endpoint tests
- `tests/rest-api/` - REST API integration tests
- `tests/helpers/` - Helper function tests
- `tests/memory/` - Memory and caching tests
- `tests/crawler/` - Crawl4AI integration tests

### Writing Tests

```php
class Test_Feature extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        // Test setup
    }
    
    public function test_feature_works() {
        // Arrange
        $input = 'test data';
        
        // Act
        $result = wp_mcp_ai_function( $input );
        
        // Assert
        $this->assertEquals( 'expected', $result );
    }
}
```

### Test Coverage Goals

- All REST endpoints must have tests
- All tools should have basic execution tests
- Security checks (capabilities, nonces) must be tested
- Critical paths (chat flow, tool execution) need integration tests

## Common Tasks

### Adding a New Tool

1. Create `includes/tools/class-wp-mcp-ai-tool-new-tool.php`
2. Extend tool base class and implement required methods
3. Register in `includes/tools-init.php`
4. Add tests in `tests/test-new-tool.php`
5. Update tool reference documentation in `docs/tool-reference.md`

### Adding a REST Endpoint

1. Add route in `includes/class-wp-mcp-ai-rest.php`
2. Implement permission callback
3. Implement endpoint handler
4. Add tests in `tests/rest/` or `tests/rest-api/`
5. Document in `docs/rest-api.md`

### Modifying the Chat UI

1. Edit JavaScript in `assets/js/chat.js`
2. Update styles in `assets/css/`
3. Test in both shortcode and Elementor widget contexts
4. Verify localStorage persistence works
5. Check guest token functionality

## Important Considerations

### Base Version vs Full Version

The plugin has two modes:
- **Base Version** (default): ~303 core tools, no third-party dependencies
- **Full Version**: ~1,559 tools (~303 base + ~1,256 pro) including WooCommerce, JetEngine, and Pro addons; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative

Control with: `define( 'WP_MCP_AI_BASE_VERSION', true/false );`

When adding features, consider which version they belong to.

### Optional Dependencies

These plugins add features but are not required:
- **JetEngine**: CCT storage, server-side chat transcripts, 5 additional tools
- **WooCommerce**: E-commerce tools (3 tools)
- **Elementor**: Widget integrations (1 tool + widgets)
- **Rank Math**: SEO analysis tool
- **WPCode**: Code snippet management tool

Always check if dependencies are active before using their APIs.

### Multisite Support

The plugin supports WordPress multisite:
- Network activation allowed
- Per-site configuration
- Network-wide settings available
- Test multisite scenarios when modifying core functionality

### Security First

This plugin handles:
- User data and credentials
- Third-party API keys (OpenAI, Google, etc.)
- File uploads and processing
- External HTTP requests
- Background task scheduling

Always:
- Validate and sanitize all input
- Escape all output
- Check capabilities before privileged operations
- Use nonces for state-changing requests
- Validate file uploads thoroughly
- Rate limit API requests
- Log security events

## Debugging

### Enable Logging

In WordPress admin: **Settings → NV oOS → Enable Logging**

Or via constant: `define( 'WP_MCP_AI_DEBUG', true );`

### Retrieve Logs

```bash
# Via WP-CLI
wp option get wp_mcp_ai_recent_errors --format=json
wp option get wp_mcp_ai_recent_activity --format=json

# Check PHP error log
tail -f /path/to/php-error.log | grep "WP_MCP_AI"
```

### Debug Mode

The plugin respects `WP_DEBUG` and provides additional debug output when enabled.

## Documentation

The `docs/` directory contains ~1,600 comprehensive documentation files across 12 directories. Key documents:
- `docs/QUICK_REFERENCE.md` - Fast reference guide
- `docs/DOCUMENTATION_INDEX.md` - Complete documentation map
- `docs/tool-reference.md` - Tool documentation (live registry is authoritative for the exact count)
- `docs/rest-api.md` - Complete REST API reference
- `docs/CODE_REVIEW.md` - Code quality standards
- `docs/BEST_PRACTICES.md` - Usage recommendations

## CI/CD

The repository uses GitHub Actions:
- **PHPUnit workflow** (`.github/workflows/phpunit.yml`): Runs on push and PRs
  - Tests against PHP 8.1
  - Uses MySQL 8.0
  - Runs full test suite

## Getting Help

- **Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Troubleshooting**: See `docs/deployment-troubleshooting.md`
- **Contributing**: See `CONTRIBUTING.md`
- **Security**: See `SECURITY.md`

## License

GPLv3 or later - See LICENSE file

---

**Note**: This is a complex enterprise WordPress plugin. When in doubt, check existing patterns in the codebase and comprehensive documentation in the `docs/` directory.
