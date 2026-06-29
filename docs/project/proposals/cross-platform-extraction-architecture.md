# oOS Core Extraction Architecture

## Comprehensive Proposal for Framework-Agnostic AI Orchestration Engine

**Status:** ✅ Phases 0–2 Complete · Phase 3 In Progress (~22%)  
**Version:** 1.1.0 (implementation notes added)  
**Date:** 2026-05-31 (proposal), updated 2026-06-11 (v1.1.29 status)  
**Author:** AI Agent (via NV oOS)  
**Audience:** Engineering leadership, architecture reviewers  

> **📌 Implementation Status (v1.1.35):** Phases 0–2 are **complete**. The `nvoos/core` package with 9 domain contracts, 10 entities, 8 events, 4 application services, 12 provider clients, and 43 migrated base tools lives at `lib/core/`. All 8 WordPress adapters (`lib/wordpress-adapter/`), plus Craft and Laravel adapter stubs, are complete. Phase 3 (tool migration) is at ~22% (43/~195 base tools). Pro tool migration (0/~810+) is the next frontier. The extraction runs behind a feature flag (`?engine=oos`) and `WP_MCP_AI_OOS_ENGINE` constant. See [`cross-platform-extraction-gap-analysis.md`](./cross-platform-extraction-gap-analysis.md) for detailed current-state assessment.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)  
2. [Industry Standards & Rationale](#2-industry-standards--rationale)  
3. [Target Architecture](#3-target-architecture)  
4. [Domain Interface Contracts](#4-domain-interface-contracts)  
5. [Directory & Package Structure](#5-directory--package-structure)  
6. [Migration Strategy — Strangler Fig Pattern](#6-migration-strategy--strangler-fig-pattern)  
7. [Testing & Quality Strategy](#7-testing--quality-strategy)  
8. [Governance & Versioning](#8-governance--versioning)  
9. [Risk Analysis & Mitigation](#9-risk-analysis--mitigation)  
10. [Timeline & Milestones](#10-timeline--milestones)  
11. [Appendices](#11-appendices)  

---

## 1. Executive Summary

### 1.1 The Opportunity

NV oOS currently operates as a monolithic WordPress plugin containing ~250,000+ lines of PHP across ~960 AI tools, 12 provider clients, an agentic orchestration loop, SSE streaming, ACP protocol support, voice/realtime APIs, and a chat-memory bridge. The AI orchestration core — the agentic loop, tool registry, provider routing, and streaming infrastructure — represents approximately 40% of the codebase by value but is **coupled to ~25 WordPress-specific APIs** (`WP_Error`, `get_post`, `current_user_can`, `wp_remote_get`, `WP_Query`, `get_option`, etc.).

This coupling blocks deployment to Laravel, CraftCMS, Symfony, or standalone PHP environments — each representing significant addressable markets (Laravel: ~40,000+ active projects; CraftCMS: enterprise CMS with growing adoption; Symfony: backbone of Drupal, Magento, Sylius, and thousands of SaaS products).

### 1.2 The Proposal

Extract the AI orchestration engine into a **framework-agnostic Composer package** (`nvoos/core`) using the **Hexagonal Architecture (Ports & Adapters)** pattern, where the existing WordPress plugin becomes one adapter among many. The core owns every contract it defines — zero PSR or Symfony inheritance.

### 1.3 Key Outcomes

| Outcome | Measurement |
|---|---|
| **Code reuse** | ~40% of logic (agentic loop, providers, tools) shared across all platforms |
| **Platform independence** | WordPress, Laravel, CraftCMS, Symfony, and standalone PHP supported |
| **Testability** | Core engine unit-testable without any framework bootstrapping |
| **PHP version freedom** | Core targets PHP 8.1+ (enum, readonly, fibers); WordPress adapter stays PHP 7.4+ |
| **Market expansion** | TAM increases from WordPress-only (~43% CMS market) to entire PHP ecosystem |

---

## 2. Industry Standards & Rationale

### 2.1 Hexagonal Architecture (Ports & Adapters)

**Origin:** Alistair Cockburn, 2005. Also known as "Ports and Adapters."  

**Core principle:** The application's business logic (the "domain") lives at the center of a hexagon. All external concerns — databases, web frameworks, file systems, third-party APIs — connect through **ports** (interfaces defined by the domain) and **adapters** (concrete implementations of those interfaces).

```
                  ┌──────────────────────┐
                  │    External World     │
                  │  ┌──────┐ ┌────────┐ │
                  │  │  DB  │ │  HTTP  │ │
                  │  └──┬───┘ └───┬────┘ │
                  └─────┼─────────┼──────┘
                        │         │
              ┌─────────▼─────────▼──────────┐
              │         ADAPTERS              │
              │  (Concrete implementations)   │
              │  WordPressAdapter              │
              │  LaravelAdapter               │
              │  CraftAdapter                  │
              └─────────┬─────────┬──────────┘
                        │         │
              ┌─────────▼─────────▼──────────┐
              │           PORTS               │
              │  (Interfaces in domain terms) │
              │  ContentStoreInterface         │
              │  AuthProviderInterface         │
              │  SettingsStoreInterface        │
              └─────────┬─────────┬──────────┘
                        │         │
              ┌─────────▼─────────▼──────────┐
              │      DOMAIN (CORE)            │
              │  Agentic Loop                  │
              │  Tool Registry                 │
              │  Provider Clients              │
              │  SSE Streaming                 │
              └──────────────────────────────┘
```

**Why this fits NV oOS:** The plugin already has an embryonic version of this pattern. The existing interfaces (`Interface_WP_MCP_AI_HTTP_Client`, `Interface_WP_MCP_AI_Options_Store`, `Interface_WP_MCP_AI_Provider_Client`) are ports. The WordPress-specific implementations (`class-wp-mcp-ai-wp-http-client.php`, the direct `get_option()` calls in provider clients) are the adapters. The extraction formalizes and completes this pattern.

### 2.2 DDD Tactical Patterns

Per Eric Evans' *Domain-Driven Design* (2003), the following tactical patterns apply to this extraction:

| Pattern | Application to oOS |
|---|---|
| **Bounded Context** | The AI orchestration engine is a distinct bounded context from the host CMS. They communicate through well-defined interfaces. |
| **Anti-Corruption Layer (ACL)** | Each framework adapter acts as an ACL, translating between the core's domain language (`ContentStoreInterface::find()`) and the framework's language (`WP_Query`, Eloquent `Model::find()`, Craft `Entry::find()`). |
| **Repository Pattern** | `ContentStoreInterface`, `FileStoreInterface`, and `SettingsStoreInterface` are repositories — they abstract data access behind collection-like interfaces. |
| **Entity/Value Object** | Tool definitions, assistant configurations, and chat messages are value objects. A tool execution result is an entity with identity (execution ID, timestamp). |
| **Domain Event** | The existing 60+ WordPress action hooks (`wp_mcp_ai_before_tool_execution`) become domain events dispatched through `EventDispatcherInterface`. |
| **Application Service** | The agentic loop (`handle_chat_request`) is the primary application service, orchestrating tools, providers, and the event bus. |

### 2.3 Domain-Owned Contracts (Zero External Dependencies)

The `lib/core` package defines its own contracts — it does not extend PSR or Symfony interfaces.

| Contract | Replaces | Adapter implements via |
|---|---|---|
| `HttpClientInterface` | `wp_remote_get` / PSR-18 / Guzzle | `send(method, url, headers, body): HttpResponse` |
| `CacheStoreInterface` | `get_transient` / PSR-6 / Symfony Cache | `getValue`, `setValue`, `deleteValue`, `increment`, `remember` |
| `EventDispatcherInterface` | `do_action` / PSR-14 / Symfony EventDispatcher | `dispatch`, `filter`, `listen`, `listenFilter`, `removeListener` |
| `ErrorFactoryInterface` | `WP_Error` / framework exceptions | `create`, `isError`, `normalize`, `notFound`, `forbidden`, `validationFailed`, `rateLimited` |
| `AuthProviderInterface` | `current_user_can` / Laravel Gates | `currentUserId`, `userCan`, `authenticate`, `issueCredential`, etc. |
| `ContentStoreInterface` | `WP_Query` / Eloquent / Element queries | `find`, `query`, `create`, `update`, `delete`, etc. |
| `SettingsStoreInterface` | `get_option` / Laravel Config | `get`, `all`, `set`, `delete`, `getApiKey`, `getDefaultProvider`, etc. |
| `FileStoreInterface` | `wp_media_handle_upload` / Flysystem | `store`, `getPath`, `getMetadata`, `userCanAccess`, `delete`, `findByMetadata` |
| `QueueClientInterface` | Action Scheduler / Laravel Queues | `enqueue`, `getStatus`, `cancel`, `schedule`, `unschedule`, `listJobs` |
| `ToolInterface` | Tool execution contract | `getSlug`, `getName`, `getDescription`, `getParametersSchema`, `getRequiredCapability`, `execute` |

The core `composer.json` requires only `php: ^8.1`. Adapters bring their own HTTP, cache, and event implementations.

> **Historical note**: Earlier drafts of this document proposed extending PSR-6, PSR-14, PSR-18, and depending on `nyholm/psr7` + `symfony/validator`. These were removed in the domain-decoupling refactor (commit `7ace36732`) — the core now owns every contract it defines.

### 2.4 Dependency Inversion Principle (SOLID — 'D')

> "High-level modules should not depend on low-level modules. Both should depend on abstractions."

The current plugin inverts this: high-level tools and the agentic loop *import* low-level WordPress functions. After extraction:

```
BEFORE (current):                      AFTER (proposed):
┌──────────────┐                      ┌──────────────┐
│  Agentic     │                      │  Agentic     │
│  Loop        │                      │  Loop        │
│  (high-level)│                      │  (high-level)│
└──────┬───────┘                      └──────┬───────┘
       │ imports                               │ depends on
       ▼                                       ▼
┌──────────────┐                      ┌──────────────┐
│ WordPress    │                      │ Interface    │
│ Functions    │                      │ (abstraction)│
│ (low-level)  │                      └──────┬───────┘
└──────────────┘                             │ implements
                                             ▼
                                    ┌──────────────┐
                                    │ WordPress    │
                                    │ Adapter      │
                                    │ (low-level)  │
                                    └──────────────┘
```

### 2.5 Strangler Fig Pattern

**Origin:** Martin Fowler, 2004. Named after the strangler fig vine that grows around a host tree, eventually replacing it.

**Application to NV oOS:**

Instead of a "big bang" rewrite, the extraction follows this pattern:

1. Define interfaces alongside existing code (no behavior change)
2. Wrap WordPress calls behind adapters (WP plugin still works identically)
3. Extract core logic into the package (WP plugin depends on the package)
4. Gradually migrate each component (tool by tool, service by service)
5. Once all logic is behind interfaces, WordPress is just one adapter
6. Build Laravel/Craft adapters that implement the same interfaces
7. Eventually, the WordPress adapter becomes thin — only implementing interfaces

### 2.6 Reference Implementations

| Project | Relevance | Pattern Used |
|---|---|---|
| **Flysystem** (thephpleague/flysystem) | File system abstraction across local, S3, FTP, etc. — exactly the pattern needed for FileStore | Ports & Adapters |
| **Laravel Scout** | Search abstraction with drivers for Algolia, Meilisearch, database. Tools per provider. | Driver/Adapter pattern |
| **MoneyPHP** (moneyphp/money) | Framework-agnostic value object for currency math. Used in Laravel, Symfony, and WordPress projects. | Pure domain object, zero dependencies |
| **Symfony Mailer** | Transport abstraction with adapters for SMTP, Sendgrid, Mailgun, etc. | Ports & Adapters |
| **PHP League OAuth2 Client** | Provider-agnostic OAuth2. Already used in this project. | Strategy + Adapter pattern |
| **Laravel Framework** | The entire framework is a set of interfaces (`Illuminate\Contracts\`) with concrete implementations. Apps depend on contracts, not concretions. | Contracts/Adapters throughout |

---

## 3. Target Architecture

### 3.1 Layer Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                     PRESENTATION LAYER                               │
│  ┌───────────────┐  ┌───────────────┐  ┌─────────────────────────┐  │
│  │ Admin UI      │  │ REST API      │  │ SSE / Voice / Real-time │  │
│  │ (framework-   │  │ Controllers   │  │ Controllers             │  │
│  │  specific)    │  │ (framework-   │  │ (framework-specific)    │  │
│  │               │  │  specific)    │  │                         │  │
│  └───────┬───────┘  └───────┬───────┘  └────────────┬────────────┘  │
└──────────┼──────────────────┼───────────────────────┼───────────────┘
           │                  │                       │
           └──────────────────┼───────────────────────┘
                              │
┌─────────────────────────────┼───────────────────────────────────────┐
│                APPLICATION LAYER (Framework-Agnostic)                │
│                              │                                       │
│  ┌───────────────────────────┼───────────────────────────────────┐  │
│  │                    ChatOrchestrator                            │  │
│  │  - Agentic loop (extract_tool_calls → execute → iterate)       │  │
│  │  - TPM validation & model switching                            │  │
│  │  - Context assembly & truncation                               │  │
│  │  - SSE framing & chunk streaming                               │  │
│  └──────────┬──────────────────┬──────────────────┬───────────────┘  │
│             │                  │                  │                  │
│  ┌──────────▼──────┐  ┌────────▼───────┐  ┌───────▼──────────────┐  │
│  │ ToolRegistry    │  │ ProviderRouter │  │ SkillRegistry         │  │
│  │ (tool lifecycle)│  │ (model select) │  │ (skill discovery)     │  │
│  └──────────┬──────┘  └────────┬───────┘  └───────┬──────────────┘  │
│             │                  │                  │                  │
└─────────────┼──────────────────┼──────────────────┼──────────────────┘
              │                  │                  │
┌─────────────┼──────────────────┼──────────────────┼──────────────────┐
│             │     DOMAIN LAYER (Framework-Agnostic)                   │
│             │                  │                  │                   │
│  ┌──────────▼──────┐  ┌────────▼───────┐  ┌───────▼──────────────┐  │
│  │ ToolInterface   │  │ ProviderClient │  │ SkillInterface        │  │
│  │ + 6 optional    │  │ Interface      │  │                       │  │
│  │ sub-interfaces  │  │                │  │                       │  │
│  └─────────────────┘  └────────────────┘  └───────────────────────┘  │
│                                                                      │
│  ┌─────────────────────── DOMAIN INTERFACES ──────────────────────┐  │
│  │                                                                  │  │
│  │  ContentStoreInterface   AuthProviderInterface                   │  │
│  │  FileStoreInterface      SettingsStoreInterface                  │  │
│  │  CacheStoreInterface     QueueClientInterface                    │  │
│  │  HttpClientInterface     EventDispatcherInterface               │  │
│  │  ErrorFactoryInterface   LoggerInterface (PSR-3)                 │  │
│  │                                                                  │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
              │                  │                  │
┌─────────────┼──────────────────┼──────────────────┼──────────────────┐
│             │  INFRASTRUCTURE LAYER (Framework-Specific Adapters)     │
│             │                  │                  │                   │
│  ┌──────────▼──────┐  ┌────────▼───────┐  ┌───────▼──────────────┐  │
│  │ WordPress       │  │ Laravel        │  │ CraftCMS              │  │
│  │ Adapters        │  │ Adapters       │  │ Adapters              │  │
│  │                 │  │                │  │                       │  │
│  │ WPContentStore  │  │ EloquentStore  │  │ CraftElementStore     │  │
│  │ WPAuthProvider  │  │ SanctumAuth    │  │ CraftUserAuth         │  │
│  │ WPFileStore     │  │ LocalFileStore │  │ CraftAssetStore       │  │
│  │ WPSettingsStore │  │ ConfigStore    │  │ CraftConfigStore      │  │
│  │ WPCacheStore    │  │ RedisStore     │  │ CraftCacheStore       │  │
│  │ WPQueueClient   │  │ SqsQueueClient │  │ CraftQueueClient      │  │
│  └─────────────────┘  └────────────────┘  └───────────────────────┘  │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

### 3.2 Runtime Wire-Up (Per-Platform DI)

Each platform boots the core with its own adapter set. The core never knows which platform it runs on:

**WordPress** (`plugins_loaded`, priority 20):
```php
$container = new Nvoos\Core\Container();  // PSR-11 implementation

// Framework-specific adapters
$container->set(ContentStoreInterface::class,
    fn() => new Nvoos\WordPress\Adapter\ContentStore());
$container->set(AuthProviderInterface::class,
    fn() => new Nvoos\WordPress\Adapter\AuthProvider());
// ... 7 more adapters ...

// Core services (same across all platforms)
$container->singleton(ChatOrchestrator::class,
    fn($c) => new ChatOrchestrator(
        $c->get(ToolRegistryInterface::class),
        $c->get(ProviderRouterInterface::class),
        $c->get(EventDispatcherInterface::class),
    ));

// Boot
$orchestrator = $container->get(ChatOrchestrator::class);
$orchestrator->registerRoutes();  // Delegates to WP REST API or Laravel routes
```

**Laravel** (`AppServiceProvider::register()`):
```php
// Exact same interfaces, different implementations
$this->app->singleton(ContentStoreInterface::class,
    fn() => new Nvoos\Laravel\Adapter\ContentStore());
$this->app->singleton(AuthProviderInterface::class,
    fn() => new Nvoos\Laravel\Adapter\AuthProvider());
// ... same 7 adapters with Laravel implementations ...

// Same core services, same wiring
$this->app->singleton(ChatOrchestrator::class,
    fn($app) => new ChatOrchestrator(/* same */));
```

---

## 4. Domain Interface Contracts

### 4.1 Design Principles

All domain interfaces follow these rules:

1. **No framework types in signatures** — use only PHP primitives, `DateTimeInterface`, `JsonSerializable`, `Stringable`, and other domain interfaces.
2. **No `WP_Error` anywhere** — the `ErrorFactoryInterface` creates framework-appropriate errors. Core code checks with `$errors->isError($result)`.
3. **`null` means "not found"** — not `false`, not `WP_Error`. Clarify semantics through the return type.
4. **Immutable value objects for configuration** — `AssistantConfig`, `ToolDefinition`, `ChatMessage` are readonly value objects (PHP 8.1+).
5. **Events for side effects** — use `EventDispatcherInterface` instead of WordPress action hooks. The domain event system uses fully domain-owned contracts.
6. **No HTTP coupling in domain** — HTTP concerns stay in the infrastructure layer.

### 4.2 Core Interfaces

#### 4.2.1 ContentStoreInterface

*Replaces:* `get_post()`, `wp_insert_post()`, `wp_delete_post()`, `get_post_meta()`, `update_post_meta()`, `wp_get_post_terms()`, `WP_Query`

```php
namespace Nvoos\Core\Domain\Contract;

interface ContentStoreInterface
{
    /**
     * Find a single content item by ID.
     *
     * @return ContentItem|null  Null when not found, not accessible, or wrong type.
     */
    public function find(int $id, ?int $userId = null): ?ContentItem;

    /**
     * Query content with filtering and pagination.
     *
     * @return ContentCollection  Collection with items, total count, and pagination metadata.
     */
    public function query(ContentQuery $query): ContentCollection;

    /**
     * Create a new content item.
     *
     * @throws AccessDeniedException  When user lacks permission.
     * @throws ValidationException    When data fails validation.
     */
    public function create(CreateContentCommand $command): ContentItem;

    /**
     * Update an existing content item.
     */
    public function update(int $id, UpdateContentCommand $command): ContentItem;

    /**
     * Delete a content item.
     */
    public function delete(int $id, int $userId): void;

    /**
     * Get metadata for a content item.
     *
     * @return array<string, mixed>
     */
    public function getMeta(int $id): array;

    /**
     * Get taxonomy terms for a content item.
     *
     * @return array<string, array<int, string>>  Taxonomy → [term names]
     */
    public function getTaxonomyTerms(int $id): array;

    /**
     * Check if a user can access a content item.
     */
    public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool;
}
```

**Value Objects:**

```php
namespace Nvoos\Core\Domain\Entity;

final readonly class ContentItem implements \JsonSerializable
{
    public function __construct(
        public int $id,
        public string $title,
        public string $content,
        public string $status,       // 'publish', 'draft', 'private', etc.
        public string $type,         // 'post', 'page', 'mcp_ai_assistant', etc.
        public int $authorId,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public array $meta = [],     // key-value metadata
        public array $taxonomy = [], // taxonomy → terms
        public ?string $excerpt = null,
        public ?string $slug = null,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'content'    => $this->content,
            'status'     => $this->status,
            'type'       => $this->type,
            'author_id'  => $this->authorId,
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt->format('c'),
            'meta'       => $this->meta,
            'taxonomy'   => $this->taxonomy,
            'excerpt'    => $this->excerpt,
            'slug'       => $this->slug,
        ];
    }
}

final readonly class ContentQuery
{
    public function __construct(
        public array $types = [],           // Filter by content types
        public array $statuses = ['publish'],
        public ?string $search = null,
        public ?int $authorId = null,
        public array $include = [],         // Specific IDs to include
        public array $exclude = [],         // IDs to exclude
        public array $metaQuery = [],       // Meta field filtering
        public array $taxQuery = [],        // Taxonomy filtering
        public string $orderBy = 'date',
        public string $order = 'DESC',
        public int $page = 1,
        public int $perPage = 20,
        public ?int $userId = null,         // For permission filtering
    ) {}
}

final readonly class ContentCollection
{
    /**
     * @param ContentItem[] $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public int $totalPages,
    ) {}
}

final readonly class CreateContentCommand
{
    public function __construct(
        public string $title,
        public string $type,
        public string $status = 'publish',
        public string $content = '',
        public int $authorId,
        public ?string $excerpt = null,
        public array $meta = [],
        public array $taxonomyInput = [],  // taxonomy → [term names/IDs]
    ) {}
}

final readonly class UpdateContentCommand
{
    public function __construct(
        public ?string $title = null,
        public ?string $content = null,
        public ?string $status = null,
        public ?string $excerpt = null,
        public array $meta = [],           // Will be merged, not replaced
        public array $taxonomyInput = [],
        public int $userId,                // Who is making the update
    ) {}
}
```

#### 4.2.2 AuthProviderInterface

*Replaces:* `get_current_user_id()`, `current_user_can()`, `user_can()`, `wp_verify_nonce()`, `is_user_logged_in()`, `get_userdata()`, `wp_create_nonce()`

```php
namespace Nvoos\Core\Domain\Contract;

interface AuthProviderInterface
{
    /**
     * Get the current authenticated user ID. Returns 0 for guests/unauthenticated.
     */
    public function currentUserId(): int;

    /**
     * Check if a user has a specific capability/permission.
     *
     * Capabilities are domain-specific strings: 'edit_posts', 'manage_options',
     * 'read', 'public', 'manage_assistants', etc.
     *
     * @param int|null $objectId  Optional object-level permission check (e.g., "can edit post 42")
     */
    public function userCan(int $userId, string $capability, ?int $objectId = null): bool;

    /**
     * Verify a request authentication token.
     *
     * Supports multiple token types:
     *  - bearer: Authorization header tokens (local credential, Auth0 JWT)
     *  - nonce: WordPress-style nonce for same-origin requests
     *  - mesh: Mesh network API key
     *  - guest: Temporary guest token for public chat surfaces
     *
     * @return AuthContext  Context including user_id, token_type, scoped assistant, etc.
     * @throws AuthenticationException  When token is invalid or expired.
     */
    public function authenticate(string $token, string $tokenType = 'bearer'): AuthContext;

    /**
     * Issue a new credential for external API access to an assistant.
     *
     * @return Credential  The issued credential with token and metadata.
     */
    public function issueCredential(int $assistantId, array $options = []): Credential;

    /**
     * Revoke a previously issued credential.
     */
    public function revokeCredential(string $credentialId): void;

    /**
     * Get user information by ID.
     *
     * @return UserInfo|null  Null if user does not exist.
     */
    public function getUserInfo(int $userId): ?UserInfo;

    /**
     * Check if a user belongs to the current site (multisite awareness).
     */
    public function isUserMemberOfSite(int $userId): bool;
}
```

**Value Objects:**

```php
namespace Nvoos\Core\Domain\Entity;

final readonly class AuthContext implements \JsonSerializable
{
    public function __construct(
        public int $userId = 0,
        public bool $authenticated = false,
        public string $tokenType = '',        // 'bearer', 'nonce', 'mesh', 'guest'
        public ?int $scopedAssistantId = null, // Token is scoped to one assistant
        public array $capabilities = [],       // Resolved capability strings
        public array $metadata = [],           // Additional auth metadata
    ) {}

    public function isGuest(): bool
    {
        return 'guest' === $this->tokenType;
    }

    public function jsonSerialize(): array { /* ... */ }
}

final readonly class Credential
{
    public function __construct(
        public string $id,
        public string $token,
        public string $secret,         // Hashed, never returned after creation
        public int $assistantId,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $expiresAt,
        public array $capabilities = [],
    ) {}
}

final readonly class UserInfo
{
    public function __construct(
        public int $id,
        public string $login,
        public string $displayName,
        public string $email,
        public array $roles = [],
        public array $capabilities = [],
    ) {}
}
```

#### 4.2.3 SettingsStoreInterface

*Replaces:* `get_option()`, `update_option()`, `delete_option()`, `WP_MCP_AI_Admin_Settings::get_settings()`

```php
namespace Nvoos\Core\Domain\Contract;

interface SettingsStoreInterface
{
    /**
     * Get a single setting value.
     *
     * @return mixed  The setting value, or $default if not set.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Get all settings as an associative array.
     *
     * @return array<string, mixed>
     */
    public function all(): array;

    /**
     * Set a setting value.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Delete a setting.
     */
    public function delete(string $key): void;

    /**
     * Get the default AI provider (e.g., 'openai', 'gemini').
     */
    public function getDefaultProvider(): string;

    /**
     * Get the default AI model.
     */
    public function getDefaultModel(): string;

    /**
     * Get an API key for a given provider.
     *
     * @return string|null  Null if not configured.
     */
    public function getApiKey(string $provider): ?string;

    /**
     * Get the base URL for a provider's API endpoint.
     */
    public function getApiBaseUrl(string $provider): ?string;
}
```

#### 4.2.4 FileStoreInterface

*Replaces:* `get_attached_file()`, `wp_upload_dir()`, `wp_insert_attachment()`, `get_post_mime_type()`, `WP_Filesystem`

```php
namespace Nvoos\Core\Domain\Contract;

interface FileStoreInterface
{
    /**
     * Store a file from a local path. Returns the stored file's metadata.
     */
    public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile;

    /**
     * Get the absolute filesystem path for a stored file.
     *
     * @return string|null  Null if file not found.
     */
    public function getPath(int $fileId): ?string;

    /**
     * Get file metadata.
     */
    public function getMetadata(int $fileId): ?StoredFile;

    /**
     * Check if a user can access a file.
     */
    public function userCanAccess(int $fileId, int $userId): bool;

    /**
     * Delete a stored file.
     */
    public function delete(int $fileId): void;

    /**
     * Find files by metadata (e.g., find by OpenAI file_id).
     *
     * @param array<string, mixed> $criteria
     * @return StoredFile[]
     */
    public function findByMetadata(array $criteria, int $limit = 50): array;
}

final readonly class StoredFile implements \JsonSerializable
{
    public function __construct(
        public int $id,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public string $localPath,
        public ?string $publicUrl = null,
        public array $metadata = [],
        public int $ownerId,
        public \DateTimeImmutable $createdAt,
    ) {}

    public function jsonSerialize(): array { /* ... */ }
}
```

#### 4.2.5 CacheStoreInterface

*Replaces:* `get_transient()`, `set_transient()`, `delete_transient()`, `wp_cache_get()`, `wp_cache_set()`

Domain-owned contract — does not extend PSR-6. Platform adapters implement this interface directly.

```php
namespace Nvoos\Core\Domain\Contract;

interface CacheStoreInterface
{
    /**
     * Get a cached value (simpler API wrapping PSR-6).
     *
     * @return mixed  Cached value or null on miss.
     */
    public function getValue(string $key, mixed $default = null): mixed;

    /**
     * Set a cached value with TTL in seconds (simpler API wrapping PSR-6).
     */
    public function setValue(string $key, mixed $value, int $ttl = 3600): bool;

    /**
     * Delete a cached value.
     */
    public function deleteValue(string $key): bool;

    /**
     * Atomic increment.
     *
     * @return int  New value after increment.
     */
    public function increment(string $key, int $by = 1, int $ttl = 3600): int;

    /**
     * Remember a value in cache, computing it via callback on miss.
     */
    public function remember(string $key, int $ttl, callable $callback): mixed;
}
```

#### 4.2.6 QueueClientInterface

*Replaces:* Action Scheduler (`as_enqueue_async_action`), WP-Cron (`wp_schedule_single_event`), Job queue manager

```php
namespace Nvoos\Core\Domain\Contract;

interface QueueClientInterface
{
    /**
     * Enqueue a job for asynchronous execution.
     *
     * @return string  Job ID for tracking.
     */
    public function enqueue(string $handler, array $payload, array $options = []): string;

    /**
     * Get the status of a queued job.
     *
     * @return JobStatus
     */
    public function getStatus(string $jobId): JobStatus;

    /**
     * Cancel a queued job.
     */
    public function cancel(string $jobId): bool;

    /**
     * Schedule a recurring job.
     *
     * @param string $cronExpression  Cron expression (e.g., '*/5 * * * *')
     *                                or interval string ('hourly', 'daily')
     * @return string  Schedule ID.
     */
    public function schedule(string $handler, array $payload, string $cronExpression): string;

    /**
     * Unschedule a recurring job.
     */
    public function unschedule(string $scheduleId): void;

    /**
     * List jobs filtered by status and optional constraints.
     *
     * @return JobStatus[]
     */
    public function listJobs(array $filters = [], int $limit = 50): array;
}

final readonly class JobStatus implements \JsonSerializable
{
    public function __construct(
        public string $jobId,
        public string $status,      // 'queued', 'running', 'completed', 'failed', 'cancelled'
        public ?array $result = null,
        public ?string $error = null,
        public ?\DateTimeImmutable $queuedAt = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $completedAt = null,
        public int $attempts = 0,
    ) {}

    public function jsonSerialize(): array { /* ... */ }
}
```

#### 4.2.7 EventDispatcherInterface

*Replaces:* `do_action()`, `apply_filters()`, 60+ WordPress hook registrations

Domain-owned contract — does not extend PSR-14. Includes both dispatch and filter semantics.

```php
namespace Nvoos\Core\Domain\Contract;

interface EventDispatcherInterface
{
    /**
     * Dispatch an event to all registered listeners.
     *
     * @template T of object
     * @param T $event
     * @return T  The event, possibly modified by listeners.
     */
    public function dispatch(object $event): object;

    /**
     * Filter a value through registered listeners. Each listener receives
     * the value and returns a (potentially modified) value.
     *
     * Replaces: apply_filters('hook_name', $value, ...$args)
     */
    public function filter(string $eventName, mixed $value, mixed ...$args): mixed;

    /**
     * Register a listener for an event.
     *
     * @param callable $listener  Signature depends on event type.
     * @param int      $priority  Higher numbers run first (matching WP convention).
     */
    public function listen(string $eventName, callable $listener, int $priority = 10): void;

    /**
     * Register a filter listener.
     */
    public function listenFilter(string $eventName, callable $filter, int $priority = 10): void;
}
```

#### 4.2.8 ErrorFactoryInterface

*Replaces:* `new WP_Error($code, $message, $data)`, `is_wp_error()`, `$error->get_error_code()`, `$error->get_error_message()`, `$error->get_error_data()`

```php
namespace Nvoos\Core\Domain\Contract;

interface ErrorFactoryInterface
{
    /**
     * Create an error instance.
     *
     * @return mixed  The framework-specific error object.
     *                WordPress: WP_Error
     *                Laravel: throws an exception
     *                Standalone: Nvoos\Core\Domain\Error\DomainError
     */
    public function create(string $code, string $message, array $data = []): mixed;

    /**
     * Check if a value represents an error.
     */
    public function isError(mixed $value): bool;

    /**
     * Normalize any error to a consistent array shape.
     *
     * @return array{code: string, message: string, data: array}
     */
    public function normalize(mixed $error): array;

    /**
     * Create a "not found" error.
     */
    public function notFound(string $message = 'Resource not found.', array $data = []): mixed;

    /**
     * Create a "forbidden" error.
     */
    public function forbidden(string $message = 'Access denied.', array $data = []): mixed;

    /**
     * Create a "validation failed" error.
     */
    public function validationFailed(string $message, array $errors = []): mixed;

    /**
     * Create a "rate limit exceeded" error.
     */
    public function rateLimited(string $message, int $retryAfterSeconds = 60): mixed;
}
```

---

## 5. Directory & Package Structure

### 5.1 Monorepo Layout

```
o-os/
├── packages/
│   ├── core/                          # nvoos/core — Composer package
│   │   ├── composer.json              # { "name": "nvoos/core", "php": "^8.1" }
│   │   ├── src/
│   │   │   ├── Domain/
│   │   │   │   ├── Contract/          # All interfaces (ports)
│   │   │   │   │   ├── ContentStoreInterface.php
│   │   │   │   │   ├── AuthProviderInterface.php
│   │   │   │   │   ├── SettingsStoreInterface.php
│   │   │   │   │   ├── FileStoreInterface.php
│   │   │   │   │   ├── CacheStoreInterface.php
│   │   │   │   │   ├── QueueClientInterface.php
│   │   │   │   │   ├── EventDispatcherInterface.php
│   │   │   │   │   ├── ErrorFactoryInterface.php
│   │   │   │   │   ├── HttpClientInterface.php
│   │   │   │   │   └── LoggerInterface.php
│   │   │   │   ├── Entity/            # Value objects
│   │   │   │   │   ├── ContentItem.php
│   │   │   │   │   ├── ContentQuery.php
│   │   │   │   │   ├── ContentCollection.php
│   │   │   │   │   ├── CreateContentCommand.php
│   │   │   │   │   ├── UpdateContentCommand.php
│   │   │   │   │   ├── AuthContext.php
│   │   │   │   │   ├── Credential.php
│   │   │   │   │   ├── UserInfo.php
│   │   │   │   │   ├── StoredFile.php
│   │   │   │   │   ├── JobStatus.php
│   │   │   │   │   ├── ToolDefinition.php
│   │   │   │   │   ├── AssistantConfig.php
│   │   │   │   │   ├── ChatMessage.php
│   │   │   │   │   └── ChatResponse.php
│   │   │   │   └── Error/             # Domain errors
│   │   │   │       ├── DomainError.php
│   │   │   │       ├── AccessDeniedException.php
│   │   │   │       ├── NotFoundException.php
│   │   │   │       └── ValidationException.php
│   │   │   ├── Application/
│   │   │   │   ├── Chat/
│   │   │   │   │   ├── ChatOrchestrator.php        # The agentic loop
│   │   │   │   │   ├── ToolExecutor.php            # Tool execution in loop
│   │   │   │   │   ├── MessageTruncator.php        # Token budget management
│   │   │   │   │   ├── ModelSwitchDecider.php      # TPM-based model switching
│   │   │   │   │   └── ChatContinuationStore.php   # Async job → session bridge
│   │   │   │   ├── Tool/
│   │   │   │   │   ├── ToolRegistry.php            # Tool registration lifecycle
│   │   │   │   │   └── ToolValidator.php           # Schema/argument validation
│   │   │   │   ├── Provider/
│   │   │   │   │   └── ProviderRouter.php          # Model selection & routing
│   │   │   │   └── Skill/
│   │   │   │       └── SkillRegistry.php           # Agent skill discovery
│   │   │   ├── Infrastructure/
│   │   │   │   ├── Provider/                       # AI provider clients
│   │   │   │   │   ├── AbstractProviderClient.php
│   │   │   │   │   ├── OpenAIClient.php
│   │   │   │   │   ├── GeminiClient.php
│   │   │   │   │   ├── AnthropicClient.php
│   │   │   │   │   ├── DeepSeekClient.php
│   │   │   │   │   ├── OllamaClient.php
│   │   │   │   │   ├── LMStudioClient.php
│   │   │   │   │   ├── OpenRouterClient.php
│   │   │   │   │   ├── KimiClient.php
│   │   │   │   │   ├── DigitalOceanClient.php
│   │   │   │   │   ├── NvidiaNimClient.php
│   │   │   │   │   ├── CloudflareClient.php
│   │   │   │   │   └── HuggingFaceClient.php
│   │   │   │   ├── Streaming/
│   │   │   │   │   ├── SseHandler.php              # RFC 6202 SSE streaming
│   │   │   │   │   └── StreamChunker.php           # Chunked text streaming
│   │   │   │   ├── Protocol/
│   │   │   │   │   └── AcpServer.php               # Agent Client Protocol
│   │   │   │   ├── Voice/
│   │   │   │   │   ├── AbstractVoiceProvider.php
│   │   │   │   │   ├── OpenAIRealtimeProvider.php
│   │   │   │   │   └── GeminiLiveProvider.php
│   │   │   │   ├── Cost/
│   │   │   │   │   └── CostCalculator.php
│   │   │   │   ├── Security/
│   │   │   │   │   ├── PromptInjectionDetector.php
│   │   │   │   │   └── RateLimiter.php
│   │   │   │   └── Harness/
│   │   │   │       ├── HarnessProfileEngine.php
│   │   │   │       └── PromptCueInjector.php
│   │   │   └── Tool/                               # Framework-agnostic tool classes
│   │   │       ├── AbstractTool.php
│   │   │       ├── GetPostTool.php                 # Example: uses ContentStoreInterface
│   │   │       ├── CreatePostTool.php
│   │   │       ├── WebSearchTool.php               # Example: uses HttpClientInterface
│   │   │       ├── GenerateImageTool.php
│   │   │       ├── AnalyzeImageTool.php
│   │   │       ├── TranscribeAudioTool.php
│   │   │       └── ...                             # ~195 base tools, ~765 pro tools
│   │   ├── tests/
│   │   │   ├── Unit/
│   │   │   │   ├── Domain/Entity/
│   │   │   │   ├── Application/Chat/
│   │   │   │   └── Tool/
│   │   │   └── Integration/
│   │   │       ├── Provider/
│   │   │       └── Streaming/
│   │   └── phpunit.xml.dist
│   │
│   ├── wordpress-adapter/             # nvoos/wordpress-adapter
│   │   ├── composer.json              # { "name": "nvoos/wordpress-adapter", "php": "^7.4" }
│   │   ├── src/
│   │   │   ├── Adapter/
│   │   │   │   ├── ContentStore.php
│   │   │   │   ├── AuthProvider.php
│   │   │   │   ├── SettingsStore.php
│   │   │   │   ├── FileStore.php
│   │   │   │   ├── CacheStore.php
│   │   │   │   ├── QueueClient.php
│   │   │   │   ├── EventDispatcher.php
│   │   │   │   └── ErrorFactory.php
│   │   │   └── Bridge/
│   │   │       ├── RestRouteRegistrar.php    # Maps core routes to WP REST API
│   │   │       └── AdminPageRegistrar.php    # Maps core config to WP admin pages
│   │   └── tests/
│   │
│   ├── laravel-adapter/               # nvoos/laravel-adapter
│   │   ├── composer.json              # { "name": "nvoos/laravel-adapter", "php": "^8.1" }
│   │   ├── src/
│   │   │   ├── Adapter/
│   │   │   │   ├── ContentStore.php          # Eloquent-backed
│   │   │   │   ├── AuthProvider.php           # Sanctum/Passport-backed
│   │   │   │   ├── SettingsStore.php          # config() + database
│   │   │   │   ├── FileStore.php              # Laravel Storage (S3/local)
│   │   │   │   ├── CacheStore.php             # Redis/memcached via Laravel Cache
│   │   │   │   ├── QueueClient.php            # Laravel Queues (Redis/SQS/DB)
│   │   │   │   ├── EventDispatcher.php        # Laravel Events
│   │   │   │   └── ErrorFactory.php           # Laravel exceptions
│   │   │   ├── Providers/
│   │   │   │   └── OosServiceProvider.php     # Laravel service provider
│   │   │   ├── Http/
│   │   │   │   └── Controllers/               # Laravel route controllers
│   │   │   │       ├── ChatController.php
│   │   │   │       ├── ToolController.php
│   │   │   │       ├── AssistantController.php
│   │   │   │       ├── SseController.php
│   │   │   │       └── VoiceController.php
│   │   │   └── Console/
│   │   │       └── Commands/
│   │   │           ├── ImportAssistants.php
│   │   │           └── HealthCheck.php
│   │   └── tests/
│   │
│   └── craft-adapter/                 # nvoos/craft-adapter
│       ├── composer.json              # { "name": "nvoos/craft-adapter", "php": "^8.1" }
│       └── src/
│           ├── Adapter/
│           │   └── ...                          # Craft CMS element types, etc.
│           ├── Module.php                       # Craft module registration
│           └── controllers/
│
├── plugins/
│   └── mcp-ai-wpoos/                  # The existing WordPress plugin
│       ├── mcp-ai-wpoos.php           # Plugin header (unchanged)
│       ├── composer.json              # Depends on nvoos/core + nvoos/wordpress-adapter
│       ├── includes/
│       │   ├── bootstrap/             # WordPress bootstrapping only
│       │   ├── admin/                 # WordPress admin pages only
│       │   ├── rest/                  # WordPress REST controller classes (thin)
│       │   └── tools/                 # WordPress-specific tools only
│       │       └── ...               # (tools that need WP-specific integrations)
│       └── ...
│
├── composer.json                      # Root: monorepo configuration
├── phpstan.neon.dist                  # Static analysis across all packages
├── phpcs.xml.dist                     # Coding standards
├── rector.php                         # Automated refactoring rules
└── docs/
    └── proposals/
        └── cross-platform-extraction-architecture.md  # This document
```

### 5.2 Composer Package Definitions

**`packages/core/composer.json`:**
```json
{
    "name": "nvoos/core",
    "description": "Framework-agnostic AI orchestration engine — agentic loop, tool registry, provider routing, SSE streaming.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0 || ^11.0",
        "phpstan/phpstan": "^1.10",
        "mockery/mockery": "^1.6"
    },
    "autoload": {
        "psr-4": {
            "Nvoos\\Core\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Nvoos\\Core\\Tests\\": "tests/"
        }
    },
    "suggest": {
        "nvoos/wordpress-adapter": "WordPress integration via the adapter layer",
        "nvoos/laravel-adapter": "Laravel integration via the adapter layer",
        "nvoos/craft-adapter": "Craft CMS integration via the adapter layer"
    }
}
```

**`packages/wordpress-adapter/composer.json`:**
```json
{
    "name": "nvoos/wordpress-adapter",
    "description": "WordPress adapter implementations for the oOS Core engine.",
    "type": "wordpress-plugin",
    "license": "GPL-3.0-or-later",
    "require": {
        "php": "^7.4",
        "nvoos/core": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Oos\\WordPress\\": "src/"
        }
    }
}
```

### 5.3 Namespace Mapping

| Current (WP Plugin) | Extracted Core |
|---|---|
| `WP_MCP_AI` | `Nvoos\Core\Application\Chat\ChatOrchestrator` |
| `WP_MCP_AI_Tool_Registry` | `Nvoos\Core\Application\Tool\ToolRegistry` |
| `WP_MCP_AI_OpenAI_Client` | `Nvoos\Core\Infrastructure\Provider\OpenAIClient` |
| `WP_MCP_AI_SSE_Handler` | `Nvoos\Core\Infrastructure\Streaming\SseHandler` |
| `WP_MCP_AI_Language_Model_Router` | `Nvoos\Core\Application\Provider\ProviderRouter` |
| `WP_MCP_AI_Tool_Get_Post` | `Nvoos\Core\Tool\GetPostTool` |
| `Interface_WP_MCP_AI_HTTP_Client` | `Nvoos\Core\Domain\Contract\HttpClientInterface` (domain-owned) |
| `Interface_WP_MCP_AI_Options_Store` | `Nvoos\Core\Domain\Contract\SettingsStoreInterface` |
| `WP_MCP_AI_Tool_Interface` | `Nvoos\Core\Domain\Contract\ToolInterface` |
| `WP_MCP_AI_Cost_Calculator` | `Nvoos\Core\Infrastructure\Cost\CostCalculator` |
| `WP_MCP_AI_ACP_Server` | `Nvoos\Core\Infrastructure\Protocol\AcpServer` |
| `WP_MCP_AI_Prompt_Injection_Detector` | `Nvoos\Core\Infrastructure\Security\PromptInjectionDetector` |

---

## 6. Migration Strategy — Strangler Fig Pattern

### 6.1 Phase 0: Establish the Monorepo (Week 1–2)

**Goal:** Create the monorepo structure without changing any existing behavior.

**Actions:**
1. Initialize `packages/core/` with the domain interfaces only (no implementations)
2. Initialize `packages/wordpress-adapter/` with empty adapter stubs
3. Add `composer.json` dependencies so the existing WP plugin can `require "nvoos/core": "@dev"`
4. CI/CD pipeline validates all packages pass PHPStan level 5+ and PHPCS

**Outcome:** Zero user-facing change. The foundation exists for incremental migration.

### 6.2 Phase 1: WordPress Adapters (Week 3–8)

**Goal:** All WordPress API calls in core services route through adapters.

**Actions:**
1. Implement all 9 WordPress adapters (`ContentStore`, `AuthProvider`, etc.)
2. Each adapter wraps existing WordPress function calls — no logic changes
3. Update `WP_MCP_AI_Container` to register adapters as services
4. Begin injecting adapters into provider clients (smallest scope, lowest risk)
5. Validate: all existing integration tests pass unchanged

**Example — Provider client migration:**
```php
// BEFORE: OpenAI client reads settings directly
class WP_MCP_AI_OpenAI_Client {
    public function get_api_key() {
        $settings = WP_MCP_AI_Admin_Settings::get_settings();
        return $settings['openai_api_key'] ?? '';
    }
}

// AFTER: OpenAI client receives SettingsStore via DI
class OpenAIClient extends AbstractProviderClient {
    public function __construct(
        private SettingsStoreInterface $settings,
        private HttpClientInterface $http,
        private ErrorFactoryInterface $errors,
    ) {
        parent::__construct($settings, $http, $errors);
    }

    protected function getApiKey(): ?string {
        return $this->settings->get('openai_api_key');
    }
}
```

### 6.3 Phase 2: Core Services Extraction (Week 9–20)

**Goal:** Core services (agentic loop, tool registry, SSE, ACP) move to `nvoos/core` package.

**Actions (by service, ordered by dependency):**

| Order | Service | Week | Risk |
|---|---|---|---|
| 1 | `ErrorFactory` + domain error classes | 9 | Low |
| 2 | `CostCalculator` | 9 | Low |
| 3 | `SseHandler` + `StreamChunker` | 10 | Low |
| 4 | `SkillRegistry` | 10 | Low |
| 5 | Provider clients (all 12) | 11–13 | Medium |
| 6 | `ProviderRouter` | 14 | Medium |
| 7 | `ToolRegistry` + tool base class | 15–16 | High |
| 8 | `ChatOrchestrator` (agentic loop) | 17–19 | **Very High** |
| 9 | `AcpServer` | 20 | Low |

**For each service:**
1. Define the service class in `packages/core/src/` with full unit test coverage
2. Create a thin wrapper class in the WP plugin that delegates to the core class
3. Update all callers to use the core class (via the wrapper or directly)
4. Delete the old WP-specific implementation
5. Run full test suite — must pass

**GPT migration example:**
```php
// Step 1: Core class with no WP dependencies
namespace Nvoos\Core\Application\Chat;

class ChatOrchestrator
{
    public function __construct(
        private ToolRegistryInterface $tools,
        private ProviderRouterInterface $providers,
        private EventDispatcherInterface $events,
        private ErrorFactoryInterface $errors,
    ) {}

    public function handleChatRequest(
        array $messages,
        AssistantConfig $config,
        AuthContext $auth,
        array $options = [],
    ): ChatResponse {
        // Agentic loop logic — identical to current handle_chat_request()
        // but uses $this->events->dispatch() instead of do_action()
        // and $this->errors->create() instead of new WP_Error()
    }
}

// Step 2: Thin WP wrapper (delegates to core)
class WP_MCP_AI_REST {
    private ChatOrchestrator $orchestrator;

    public function handle_chat_request(WP_REST_Request $request) {
        // Translate WP types → core types
        $auth = $this->authenticator->toAuthContext();
        $messages = $this->translator->wpMessagesToCore($request->get_param('messages'));
        $config = $this->translator->wpConfigToCore($assistantId);

        // Delegate
        $response = $this->orchestrator->handleChatRequest($messages, $config, $auth);

        // Translate core types → WP types
        return $this->translator->coreResponseToWp($response);
    }
}
```

### 6.4 Phase 3: Tool Migration (Week 21–40+)

**Goal:** Tools depend only on domain interfaces, not WordPress functions.

**Migration per tool (repeat for each of ~195 base tools):**
1. Create `Nvoos\Core\Tool\{ToolName}Tool` implementing `ToolInterface`
2. Use domain interfaces (ContentStore, AuthProvider, FileStore, etc.) instead of WP functions
3. Write unit test with mocked adapters
4. Create WP wrapper tool that delegates to core tool
5. Update `tools-init.php` registration
6. Run tool-specific tests

**Migration priority (by usage frequency and complexity):**

| Tier | Tools | Count | Strategy |
|---|---|---|---|
| **Tier 1** — Pure logic / external APIs | Web search, image generation, speech synthesis, content moderation, model discovery, token counting, chart generation | ~40 | Full migration first — these tools barely touch WordPress |
| **Tier 2** — Read-only WordPress data | Get post, get recent posts, search content, get user info, list cron jobs, get site health | ~50 | Migrate using ContentStore/AuthProvider adapters |
| **Tier 3** — State-changing WordPress | Create post, save post, delete post, create term, update term | ~40 | Migrate using ContentStore write methods |
| **Tier 4** — Plugin-specific integrations | WooCommerce, JetEngine, Elementor, Rank Math, WPCode, Newsletter | ~40 | Abstract plugin APIs behind domain interfaces |
| **Tier 5** — Pro tools (addons/pro) | Cloudways, CRM, healthcare, enterprise | ~765 | Migrate on demand; many are already external APIs |

### 6.5 Phase 4: New Platform Adapters (Week 30+)

**Goal:** Build Laravel and CraftCMS adapters that implement the same domain interfaces.

**Actions:**
1. Implement all 9 adapters for Laravel (EloquContentStore, SanctumAuthProvider, etc.)
2. Build Laravel controllers (ChatController, ToolController) that delegate to `ChatOrchestrator`
3. Create Laravel service provider for DI wiring
4. Create CraftCMS module with element-type-backed adapters
5. End-to-end testing on each platform with Tier 1 tools

### 6.6 Phase 5: Sunset WordPress-Specific Code (Week 45+)

**Goal:** The WordPress plugin is a thin shell: plugin header, admin pages, REST route registration, and DI wiring.

**Final state of the WP plugin:**
- `mcp-ai-wpoos.php` — Plugin header only
- `includes/bootstrap/` — WordPress lifecycle hooks only
- `includes/admin/` — WordPress admin pages (unchanged)
- `includes/rest/` — Thin controllers that delegate to core
- `includes/tools/` — WordPress-specific tools only (JetEngine, Elementor, WooCommerce, etc.)
- Everything else lives in `nvoos/core` + `nvoos/wordpress-adapter`

---

## 7. Testing & Quality Strategy

### 7.1 Testing Pyramid for Extracted Core

```
       ┌──────────┐
       │   E2E    │  5% — Full chat flow on each platform with real LLM (optional)
       │          │
       └────┬─────┘
       ┌────┴─────┐
       │Integration│ 15% — Provider clients → real API sandbox (record/replay)
       │           │
       └─────┬─────┘
       ┌─────┴──────┐
       │   Unit      │ 80% — Every domain class tested with mocked adapters
       │             │
       └─────────────┘
```

### 7.2 Unit Test Example (Tool with Mocked Adapters)

```php
class GetPostToolTest extends TestCase
{
    private ContentStoreInterface $contentStore;
    private AuthProviderInterface $authProvider;
    private ErrorFactoryInterface $errorFactory;
    private GetPostTool $tool;

    protected function setUp(): void
    {
        $this->contentStore = $this->createMock(ContentStoreInterface::class);
        $this->authProvider = $this->createMock(AuthProviderInterface::class);
        $this->errorFactory = new StubErrorFactory(); // In-memory implementation

        $this->tool = new GetPostTool(
            $this->contentStore,
            $this->authProvider,
            $this->errorFactory,
        );
    }

    public function testExecuteReturnsPostWhenAuthorized(): void
    {
        $this->authProvider->method('userCan')
            ->with(1, 'edit_posts')
            ->willReturn(true);

        $expectedPost = new ContentItem(
            id: 42,
            title: 'Test Post',
            content: 'Hello world',
            status: 'publish',
            type: 'post',
            authorId: 1,
            createdAt: new DateTimeImmutable('2026-01-01'),
            updatedAt: new DateTimeImmutable('2026-01-02'),
            meta: ['_custom_field' => 'value'],
            taxonomy: ['category' => ['News']],
            excerpt: 'Summary',
            slug: 'test-post',
        );

        $this->contentStore->method('find')
            ->with(42, 1)
            ->willReturn($expectedPost);

        $result = $this->tool->execute(
            ['post_id' => 42],
            ['user_id' => 1],
        );

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame($expectedPost, $result['data']);
    }

    public function testExecuteReturnsErrorWhenNotFound(): void
    {
        $this->authProvider->method('userCan')->willReturn(true);
        $this->contentStore->method('find')->willReturn(null);

        $result = $this->tool->execute(
            ['post_id' => 999],
            ['user_id' => 1],
        );

        $this->assertTrue($this->errorFactory->isError($result));
        $normalized = $this->errorFactory->normalize($result);
        $this->assertSame('not_found', $normalized['code']);
    }

    public function testExecuteReturnsErrorWhenUnauthorized(): void
    {
        $this->authProvider->method('userCan')->willReturn(false);

        $result = $this->tool->execute(
            ['post_id' => 42],
            ['user_id' => 1],
        );

        $this->assertTrue($this->errorFactory->isError($result));
        $normalized = $this->errorFactory->normalize($result);
        $this->assertSame('forbidden', $normalized['code']);
    }
}
```

### 7.3 Integration Test Example (Provider Client with Record/Replay)

```php
class OpenAIClientIntegrationTest extends TestCase
{
    private OpenAIClient $client;
    private HttpClientInterface $http;

    protected function setUp(): void
    {
        // Use Symfony HttpClient with fixture-based responses (no network)
        $this->http = new MockHttpClient([
            // Fixture: chat completion response
            'https://api.openai.com/v1/chat/completions' => function (string $method, string $url, array $options) {
                return new MockResponse(json_encode([
                    'id' => 'chatcmpl-123',
                    'object' => 'chat.completion',
                    'model' => 'gpt-4o-mini',
                    'choices' => [[
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => 'Hello!'],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => [
                        'prompt_tokens' => 10,
                        'completion_tokens' => 5,
                        'total_tokens' => 15,
                    ],
                ]));
            },
        ]);

        $settings = new InMemorySettingsStore([
            'openai_api_key' => 'sk-test-key',
            'default_model' => 'gpt-4o-mini',
        ]);

        $this->client = new OpenAIClient(
            $settings,
            $this->http,
            new StubErrorFactory(),
        );
    }

    public function testChatCompletionReturnsStructuredResponse(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $response = $this->client->chat($messages, [
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertIsArray($response);
        $this->assertSame('Hello!', $response['content']);
        $this->assertSame('gpt-4o-mini', $response['model']);
        $this->assertSame(15, $response['usage']['total_tokens']);
    }
}
```

### 7.4 Quality Gates

| Gate | Tool | Threshold |
|---|---|---|
| **Static Analysis** | PHPStan | Level 8 (strictest) for core, Level 5 for adapters |
| **Coding Standards** | PHP_CodeSniffer | PSR-12 + custom rules |
| **Mutation Testing** | Infection | MSI ≥ 80% for domain layer |
| **Architecture Tests** | PHPStan rules + Deptrac | No adapter imports in domain layer |
| **Dependency Check** | Composer Unused + Deptrac | No WordPress references in core package |
| **Security Audit** | Local PHP Security Checker | Zero known vulnerabilities in dependencies |

---

## 8. Governance & Versioning

### 8.1 Semantic Versioning

| Package | Versioning | Breaking Change Policy |
|---|---|---|
| `nvoos/core` | SemVer (MAJOR.MINOR.PATCH) | Interface changes = MAJOR bump. New methods on interfaces = MAJOR unless default implementation provided. |
| `nvoos/wordpress-adapter` | SemVer | Must match core MAJOR version. |
| `nvoos/laravel-adapter` | SemVer | Must match core MAJOR version. |
| `nvoos/craft-adapter` | SemVer | Must match core MAJOR version. |
| `mcp-ai-wpoos` (WP plugin) | CalVer-ish (`1.X.Y`) | Backward compat with WordPress.org expectations. |

### 8.2 Interface Stability Guarantees

1. **Domain interfaces are the public API.** They follow SemVer strictly.
2. **New interface methods** get default implementations via abstract base classes where possible, avoiding breaking changes for adapter authors.
3. **Deprecation notices** are added one MAJOR version before removal.
4. **Entity value objects** may add optional fields in MINOR versions. Making a required field optional or vice versa is a MAJOR change.

### 8.3 Backward Compatibility During Extraction

During the migration, the existing WordPress plugin must continue to function:

1. **All existing hooks** (`wp_mcp_ai_*`) continue to fire — the EventDispatcher adapter bridges them
2. **Existing PHP classes** are preserved as wrappers — `class WP_MCP_AI_Tool_Get_Post extends Nvoos\Core\Tool\GetPostTool`
3. **Existing REST API routes** remain at the same URLs with the same response shapes
4. **Integration tests from the existing test suite** continue to pass at every phase

### 8.4 Contribution Guidelines

```
Core package:
  - Domain interface changes require 2 reviewers + architecture sign-off
  - Provider client changes require integration test update
  - Tool changes require unit test with mocked adapters

Adapter packages:
  - Must pass PHPStan Level 5
  - Must implement 100% of the domain interfaces
  - Must not introduce new domain logic (delegate to core)

WordPress plugin:
  - Must pass existing WordPress test suite
  - Must maintain PHP 7.4 compatibility for base plugin
```

---

## 9. Risk Analysis & Mitigation

| Risk | Probability | Impact | Mitigation |
|---|---|---|---|
| **Tool migration takes 12+ months** | High | Schedule slippage | Prioritize Tier 1 & 2 tools (~90 tools). Remaining tools continue to work in WP plugin, unavailable on other platforms until migrated. |
| **WP_Error → ErrorFactory migration breaks error handling** | Medium | High — chat failures become opaque | Build normalization middleware in REST layer. All errors pass through `ErrorFactory::normalize()` before JSON encoding. |
| **Agentic loop extraction introduces regressions** | Medium | Very High — breaks core chat functionality | Maintain dual implementations during transition with feature flag. Run both paths against integration tests. |
| **Performance regression from adapter indirection** | Low | Medium — 1-2% overhead from interface calls | Measure before/after with Blackfire.io. Keep adapter methods thin (one-liners to WP functions). PHP 8.1+ JIT further reduces overhead. |
| **Pro addon (~765 tools) becomes a migration bottleneck** | High | High — blocks full platform independence | Pro tools continue to work in WP plugin. Migration is opt-in per tool. External-API tools (Cloudways, CRM) migrate easily. Plugin-specific tools (JetEngine, WooCommerce) remain WP-only. |
| **Interface design proves insufficient for real-world tool complexity** | Medium | High — requires interface version bumps | Start with Tier 1 tools (simplest) to validate interfaces before committing to Tier 2+. Iterate interfaces during Phase 1-2 before locking them for Phase 3+. |
| **Community forks or confusion during extraction** | Low | Low — communication issue | Clear CHANGELOG, upgrade guide, and deprecation notices. Blog post explaining the vision. |

---

## 10. Timeline & Milestones

### 10.1 Gantt Chart Summary

```
Phase 0: Monorepo Setup            ██░░░░░░░░░░░░░░░░░░░░░░░  Weeks 1–2
Phase 1: WordPress Adapters        ░░██████░░░░░░░░░░░░░░░░░  Weeks 3–8
Phase 2: Core Services Extraction  ░░░░░░░░████████████░░░░░  Weeks 9–20
Phase 3: Tool Migration (Tier 1)   ░░░░░░░░░░░░░░░░░░██████░  Weeks 21–28
Phase 4: Laravel Adapter v0.1      ░░░░░░░░░░░░░░░░░░░░░░██░  Weeks 29–38
Phase 3: Tool Migration (Tier 2)   ░░░░░░░░░░░░░░░░░░░░░░████  Weeks 30–40
Phase 4: Craft Adapter v0.1        ░░░░░░░░░░░░░░░░░░░░░░░░█  Weeks 39–48
Phase 5: Sunset WP-Specific Code   ░░░░░░░░░░░░░░░░░░░░░░░░░  Weeks 45–52
```

### 10.2 Detailed Milestones

| Week | Milestone | Deliverable | Success Criteria |
|---|---|---|---|
| **2** | Monorepo booted | `packages/core/`, `packages/wordpress-adapter/`, CI passing | PHPStan Level 5 on all packages. Zero existing test regressions. |
| **8** | WP adapters live | All 9 adapters implemented and used by at least 2 provider clients | Existing plugin functions identically. Provider clients get API keys via SettingsStore interface. |
| **12** | Provider clients extracted | All 12 provider clients in `nvoos/core`, WP plugin delegates to them | Provider client unit tests pass with mocked adapters. Chat completions work identically. |
| **16** | Agentic loop extracted | `ChatOrchestrator` in `nvoos/core`, WP REST layer is thin wrapper | Existing chat tests pass. Agentic loop behavior identical (iteration count, tool execution, TPM switching). |
| **20** | Core services complete | SSE, ACP, Voice, Cost Calculator, Harness, Skills all in core | All integration tests pass. Feature parity with v1.1.25 WP plugin. |
| **24** | Tier 1 tools migrated | ~40 tools in core, tested with mocked adapters | Tools work in WP plugin (via thin wrappers) and standalone PHP (via adapter stubs). |
| **28** | Tier 2 tools migrated | ~50 WP-data tools migrated | Same tool class runs in WP and Laravel test harnesses. |
| **34** | Laravel adapter alpha | Laravel chat endpoint works with Tier 1 tools | `POST /api/chat` returns AI response with tool execution in a fresh Laravel app. |
| **40** | Tier 3 tools migrated | ~40 state-changing tools migrated | Create post, save post, etc. work in Laravel via the same tool classes. |
| **48** | Craft adapter alpha | Craft CMS chat endpoint works with Tier 1 tools | `POST /actions/o-os/chat/chat` returns AI response in Craft CMS. |
| **52** | Production-ready | WP plugin is thin shell. Laravel adapter is beta-quality. All Tier 1-3 tools work cross-platform. | Existing WP users see no change. Laravel users can install `nvoos/laravel-adapter` and start using AI agents. |

### 10.3 Resource Estimate

| Role | FTE | Duration |
|---|---|---|
| **Lead Architect** | 1.0 | Full 12 months — interface design, architecture reviews, PHPStan config |
| **Senior PHP Engineer ×2** | 2.0 | Full 12 months — core extraction, provider migration, agentic loop |
| **Tool Migration Engineer** | 1.0 | Months 3–12 — systematic tool-by-tool migration |
| **Laravel Specialist** | 0.5 | Months 7–12 — Laravel adapter, controllers, Eloquent integration |
| **Craft CMS Specialist** | 0.5 | Months 9–12 — Craft adapter, element types, module registration |
| **QA Engineer** | 0.5 | Months 3–12 — integration testing, regression prevention |
| **DevOps** | 0.25 | Ongoing — CI/CD, package publishing, versioning automation |
| **Total** | ~5.75 FTE | ~2,990 person-days over 12 months |

---

## 11. Appendices

### A. Deferred Decisions (Require Further Discussion)

1. **License for `nvoos/core`:** Currently GPLv3 (inherited from WordPress). MIT or Apache 2.0 would be more permissive for non-GPL ecosystems. Legal review required.
2. **Pro addon packaging:** Should the Pro addon be a separate composer package (`oos/pro`) or a paid addon to the core? Commercial licensing model TBD.
3. **PHP 7.4 compat:** The core package targets PHP 8.1 for design cleanliness (enums, readonly, named args, fibers). The WordPress adapter remains PHP 7.4. Is this split acceptable?
4. **Event system strategy:** The domain-owned `EventDispatcherInterface` supports both dispatch (actions) and filter semantics — no PSR-14 dependency needed.
5. **Admin UI sharing:** Should admin/configuration pages be rebuilt per platform or share a common SPA (Vue/React) that communicates via the REST API?
6. **Authentication token format:** Should cross-platform bearer tokens use a standardized format (JWT with standard claims) or remain custom per platform?

### B. Glossary

| Term | Definition |
|---|---|
| **Port** | An interface defined by the domain that external systems implement. *"What the domain needs."* |
| **Adapter** | A concrete implementation of a port for a specific framework or infrastructure. *"How the framework provides it."* |
| **Hexagonal Architecture** | Architectural pattern where the domain is at the center and all external concerns connect through ports and adapters. |
| **Strangler Fig** | Incremental migration pattern where new code slowly replaces old code without a big-bang rewrite. |
| **Anti-Corruption Layer** | A translation layer that prevents a legacy system's concepts from leaking into a new domain model. |
| **Bounded Context** | A boundary within which a specific domain model applies, with its own ubiquitous language. |
| **Value Object** | An immutable object whose equality is based on its value, not identity (e.g., a date, a money amount). |
| **PSR** | PHP Standard Recommendation — interface standards published by the PHP-FIG for framework interoperability. |
| **SemVer** | Semantic Versioning — MAJOR.MINOR.PATCH where MAJOR = breaking changes. |
| **DI** | Dependency Injection — objects receive their dependencies rather than creating them. |

### C. References

1. Cockburn, A. (2005). *Hexagonal Architecture (Ports and Adapters)*. https://alistair.cockburn.us/hexagonal-architecture/
2. Evans, E. (2003). *Domain-Driven Design: Tackling Complexity in the Heart of Software*. Addison-Wesley.
3. Fowler, M. (2004). *Strangler Fig Application*. https://martinfowler.com/bliki/StranglerFigApplication.html
4. PHP-FIG. *PHP Standard Recommendations*. https://www.php-fig.org/psr/
5. Vernon, V. (2013). *Implementing Domain-Driven Design*. Addison-Wesley.
6. Noback, M. (2020). *Advanced Web Application Architecture*. Leanpub.
7. Synolia. (2020). *Applying Hexagonal Architecture to a Symfony Project*. https://dzone.com/articles/applying-hexagonal-architecture-to-a-symfony-proje
8. The League of Extraordinary Packages. *Flysystem*. https://flysystem.thephpleague.com/ — Reference implementation of the Ports & Adapters pattern in PHP.
