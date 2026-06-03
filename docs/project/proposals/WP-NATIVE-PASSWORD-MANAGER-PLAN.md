# WordPress-Native Password Manager - Implementation Plan

**Status:** 📋 DETAILED PLAN  
**Created:** January 23, 2026  
**Type:** Pro Add-on Feature  
**Complexity:** ⭐⭐⭐ MEDIUM-HIGH  
**Target Release:** Pro 1.3.0  
**Estimated Timeline:** 12-16 weeks  
**Budget:** $40,000 - $55,000

## Executive Summary

Create a **WordPress-native password vault** as a Bitwarden alternative, using Custom Post Types (CPT) and JetEngine Custom Content Types (CCT) for data storage. This provides secure credential management without Docker dependencies or external services.

**Key Differentiators:**
- ✅ Pure WordPress/PHP solution (no Docker required)
- ✅ Works on any WordPress hosting
- ✅ Leverages existing CPT/CCT architecture patterns
- ✅ AI assistant integration for automation
- ✅ Full admin UI with WordPress standards
- ⚠️ Not compatible with Bitwarden clients (custom ecosystem)

## Problem Statement

Organizations need secure credential management for:
- AI assistant automation (access to external services)
- Team password sharing
- Centralized credential storage
- Audit trails for compliance
- Password rotation workflows

**Current Challenge:** Bitwarden integration requires Docker (not available on many WordPress hosts) or managed service costs.

**Solution:** WordPress-native vault using proven CPT/CCT patterns already used for:
- Quizzes, ECAs, Places, Projects (existing CPT implementations)
- Task Plans, Autonomous Sessions (existing CCT implementations)
- Media Templates, Health Records (existing encrypted data storage)

## Architecture Overview

### Data Storage Layer

#### Custom Post Type: Vault Items
```php
Post Type: 'mcp_vault_item'
Slug: mcp_vault_item
Public: false
Show UI: true (admin only)
Capability: manage_own_vault
Supports: title, author
Hierarchical: false
```

**Post Meta Fields:**
- `_vault_type` - Item type (login, note, card, identity)
- `_vault_encrypted_data` - AES-256-GCM encrypted JSON
- `_vault_iv` - Initialization vector (16 bytes, base64)
- `_vault_auth_tag` - GCM authentication tag (base64)
- `_vault_favorite` - Boolean (0/1)
- `_vault_folder_id` - Foreign key to folder CPT
- `_vault_tags` - Comma-separated tags
- `_vault_last_used` - Unix timestamp
- `_vault_access_count` - Integer counter
- `_vault_created_by` - User ID

#### Custom Post Type: Vault Folders
```php
Post Type: 'mcp_vault_folder'
Hierarchical: true (parent folder support)
Public: false
Capability: manage_own_vault
```

**Post Meta:**
- `_folder_color` - Hex color (#FF5733)
- `_folder_icon` - Icon identifier (dashicon name)

#### JetEngine CCT: Vault Items (Optional)
```php
CCT Slug: 'mcp_vault_items'
Purpose: Enhanced query performance
Fields: Mirror CPT meta fields
Sync: Bi-directional with CPT
```

### Security Architecture

```
┌──────────────────────────────────────────────────┐
│         WordPress Admin UI / REST API            │
│      (Settings → NV oOS → Password Vault)        │
└─────────────────┬────────────────────────────────┘
                  │ HTTPS + WordPress Auth
                  ▼
┌──────────────────────────────────────────────────┐
│        Authentication & Authorization            │
│  • WordPress user sessions                       │
│  • Nonce verification                            │
│  • Capability checks (manage_own_vault)          │
│  • Rate limiting (100 req/min)                   │
└─────────────────┬────────────────────────────────┘
                  │
                  ▼
┌──────────────────────────────────────────────────┐
│         Encryption Service (AES-256-GCM)         │
│  • Per-user encryption keys                      │
│  • PBKDF2 key derivation (100k iterations)       │
│  • Authenticated encryption (GCM mode)           │
│  • Secure random IV generation                   │
└─────────────────┬────────────────────────────────┘
                  │
                  ▼
┌──────────────────────────────────────────────────┐
│      Data Storage (CPT + optional CCT)           │
│  • wp_posts (vault items, folders)               │
│  • wp_postmeta (encrypted data, metadata)        │
│  • wp_jet_cct_mcp_vault_items (optional CCT)    │
│  • wp_mcp_vault_audit_log (access logs)         │
└──────────────────────────────────────────────────┘
```

## Implementation Phases

### Phase 1: Core Data Structure (Weeks 1-3)

#### Deliverables:
1. Vault Item CPT registration
2. Vault Folder CPT registration
3. Optional Vault Items CCT (if JetEngine active)
4. Post meta schema implementation
5. Database migration/upgrade scripts

#### Files Created:
```
addons/pro/includes/
├── vault-manager-init.php (initialization)
├── class-wp-mcp-ai-vault-item-cpt.php
├── class-wp-mcp-ai-vault-folder-cpt.php
└── class-wp-mcp-ai-vault-items-cct.php (optional)
```

#### CPT Pattern (Following Quiz CPT Pattern):
```php
class WP_MCP_AI_Vault_Item_CPT {
    const POST_TYPE = 'mcp_vault_item';
    const CAPABILITY = 'manage_own_vault';
    
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_boxes' ] );
        add_action( 'save_post_' . self::POST_TYPE, [ __CLASS__, 'save_meta' ], 10, 2 );
        
        // Optional: Sync to CCT if JetEngine active
        if ( function_exists( 'jet_engine' ) ) {
            add_action( 'save_post_' . self::POST_TYPE, [ __CLASS__, 'sync_to_cct' ], 15, 2 );
        }
    }
    
    public static function register_post_type() {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name' => __( 'Vault Items', 'mcp-ai-wpoos-pro' ),
                'singular_name' => __( 'Vault Item', 'mcp-ai-wpoos-pro' ),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'wp-mcp-ai-settings',
            'capability_type' => 'post',
            'capabilities' => [
                'edit_post' => self::CAPABILITY,
                'read_post' => self::CAPABILITY,
                'delete_post' => self::CAPABILITY,
            ],
            'supports' => [ 'title', 'author' ],
            'show_in_rest' => true,
            'rest_base' => 'vault-items',
        ] );
    }
}
```

### Phase 2: Encryption Layer (Weeks 4-5)

#### Deliverables:
1. Encryption service with AES-256-GCM
2. Key derivation functions (PBKDF2)
3. Per-user key management
4. Secure random generation utilities
5. Unit tests for encryption/decryption

#### Files Created:
```
addons/pro/includes/services/
├── class-wp-mcp-ai-vault-encryption-service.php
├── class-wp-mcp-ai-vault-key-manager.php
└── class-wp-mcp-ai-vault-security-utils.php
```

#### Encryption Implementation:
```php
class WP_MCP_AI_Vault_Encryption_Service {
    
    /**
     * Encrypt sensitive data using AES-256-GCM
     */
    public function encrypt( $plaintext, $user_id ) {
        $key = $this->get_user_key( $user_id );
        $iv = random_bytes( 16 ); // 128-bit IV for GCM
        
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $auth_tag,
            '', // No additional authenticated data
            16   // 128-bit auth tag
        );
        
        if ( false === $ciphertext ) {
            return new WP_Error( 'encryption_failed', __( 'Failed to encrypt data', 'mcp-ai-wpoos-pro' ) );
        }
        
        return [
            'iv' => base64_encode( $iv ),
            'ciphertext' => base64_encode( $ciphertext ),
            'auth_tag' => base64_encode( $auth_tag ),
        ];
    }
    
    /**
     * Decrypt sensitive data
     */
    public function decrypt( $encrypted_data, $user_id ) {
        $key = $this->get_user_key( $user_id );
        
        $iv = base64_decode( $encrypted_data['iv'] );
        $ciphertext = base64_decode( $encrypted_data['ciphertext'] );
        $auth_tag = base64_decode( $encrypted_data['auth_tag'] );
        
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $auth_tag
        );
        
        if ( false === $plaintext ) {
            return new WP_Error( 'decryption_failed', __( 'Failed to decrypt data', 'mcp-ai-wpoos-pro' ) );
        }
        
        return $plaintext;
    }
    
    /**
     * Get or create user-specific encryption key
     */
    private function get_user_key( $user_id ) {
        $user_salt = get_user_meta( $user_id, '_vault_encryption_salt', true );
        
        if ( empty( $user_salt ) ) {
            $user_salt = bin2hex( random_bytes( 32 ) );
            update_user_meta( $user_id, '_vault_encryption_salt', $user_salt );
        }
        
        // Derive key from WordPress AUTH_KEY + user salt
        $key_material = AUTH_KEY . $user_salt . $user_id;
        
        // PBKDF2 with 100,000 iterations
        $derived_key = hash_pbkdf2( 'sha256', $key_material, $user_salt, 100000, 32, true );
        
        return $derived_key;
    }
}
```

**Security Features:**
- AES-256-GCM (authenticated encryption)
- Random IV per encryption operation
- PBKDF2 key derivation (100k iterations)
- Per-user encryption keys
- WordPress AUTH_KEY as master key material
- User-specific salt (stored in user meta)

### Phase 3: REST API (Weeks 6-7)

#### Deliverables:
1. REST controller for vault operations
2. Authentication & authorization
3. Rate limiting middleware
4. API documentation
5. Integration tests

#### Files Created:
```
addons/pro/includes/rest/
├── class-wp-mcp-ai-vault-rest-controller.php
└── class-wp-mcp-ai-vault-rate-limiter.php
```

#### REST Endpoints:
```
Base: /wp-json/mcp-ai/v1/vault

GET    /items              - List user's vault items (encrypted=false returns decrypted)
POST   /items              - Create new vault item
GET    /items/{id}         - Get specific item (decrypted)
PUT    /items/{id}         - Update vault item
PATCH  /items/{id}         - Partial update
DELETE /items/{id}         - Delete vault item (moves to trash)

GET    /folders            - List folders (hierarchical)
POST   /folders            - Create folder
PUT    /folders/{id}       - Update folder
DELETE /folders/{id}       - Delete folder

GET    /search             - Search vault (?q=github&type=login&folder=5)
POST   /generate-password  - Generate secure password
GET    /audit-log          - Get access audit log (paginated)
GET    /stats              - Get vault statistics
POST   /export             - Export vault data (encrypted JSON)
POST   /import             - Import vault data

GET    /breach-check       - Check passwords against HIBP API
```

#### API Response Format:
```json
{
  "success": true,
  "data": {
    "id": 123,
    "type": "login",
    "name": "GitHub Account",
    "data": {
      "username": "user@example.com",
      "password": "ghp_1234567890abcdef",
      "uris": ["https://github.com", "https://api.github.com"],
      "notes": "Personal development account"
    },
    "folder": {
      "id": 5,
      "name": "Development",
      "color": "#FF5733"
    },
    "tags": ["development", "github", "work"],
    "favorite": false,
    "created_at": "2026-01-23T12:00:00Z",
    "updated_at": "2026-01-23T14:30:00Z",
    "last_used": "2026-01-23T14:25:00Z",
    "access_count": 42
  }
}
```

### Phase 4: Admin UI (Weeks 8-10)

#### Deliverables:
1. Vault management admin page
2. Item editor interface
3. Folder management UI
4. Dashboard with statistics
5. Import/export functionality

#### Files Created:
```
addons/pro/includes/admin/
├── class-wp-mcp-ai-vault-admin-page.php
├── class-wp-mcp-ai-vault-dashboard.php
├── class-wp-mcp-ai-vault-item-editor.php
└── class-wp-mcp-ai-vault-settings.php

addons/pro/includes/metaboxes/
├── class-wp-mcp-ai-vault-item-metabox.php
├── class-wp-mcp-ai-vault-organization-metabox.php
└── class-wp-mcp-ai-vault-security-metabox.php

addons/pro/assets/css/
└── admin-vault-manager.css

addons/pro/assets/js/
├── vault-admin.js
├── vault-item-editor.js
└── password-generator.js
```

#### Admin Page Location:
**Settings → NV oOS → Password Vault**

#### Dashboard Tab:
- Total items count by type (logins, notes, cards, identities)
- Recently accessed items (last 10)
- Security score:
  - Weak passwords count
  - Reused passwords count
  - Old passwords (>90 days)
  - Compromised passwords (HIBP check)
- Quick actions:
  - Add new item
  - Generate password
  - Export vault
- Storage statistics

#### Items Tab (DataTable):
- Columns: Icon | Name | Type | Folder | Tags | Last Used | Actions
- Filters: All Types | Logins | Notes | Cards | Identities
- Folder filter (dropdown)
- Tag filter (multi-select)
- Search box (real-time)
- Bulk actions:
  - Move to folder
  - Add tags
  - Delete
  - Export selected
- Add Item button (opens modal)

#### Folders Tab:
- Tree view with drag-and-drop
- Create folder button
- Edit folder (name, color, icon)
- Delete folder (with item reassignment)
- Move folders (drag-and-drop)

#### Security Tab:
- Password generator settings:
  - Default length (12-128)
  - Character sets (uppercase, lowercase, numbers, symbols)
  - Avoid ambiguous characters
- Vault settings:
  - Auto-lock timeout (minutes)
  - Require master password (optional)
  - Session timeout
- Breach monitoring:
  - Enable HIBP integration
  - Auto-check on save
  - Notification preferences
- Data management:
  - Export vault (encrypted JSON)
  - Import vault (with merge options)
  - Clear audit log
  - Delete all items (with confirmation)

#### Item Editor Modal:
```
┌─────────────────────────────────────────────┐
│ Add Vault Item                           [X]│
├─────────────────────────────────────────────┤
│ Type: [Login ▼] [Note] [Card] [Identity]  │
│                                             │
│ Name: [                                  ] │
│                                             │
│ ┌── Login Details ───────────────────────┐ │
│ │ Username: [                           ]│ │
│ │ Password: [                           ]│ │
│ │           [Generate] [Show] [Copy]     │ │
│ │                                         │ │
│ │ URIs:                                   │ │
│ │ [https://github.com                   ]│ │
│ │ [+ Add URI]                            │ │
│ │                                         │ │
│ │ Notes:                                  │ │
│ │ [                                      ]│ │
│ │ [                                      ]│ │
│ └─────────────────────────────────────────┘ │
│                                             │
│ Folder: [Development ▼]                    │
│ Tags: [github, work, dev]                  │
│ [ ] Mark as favorite                        │
│                                             │
│              [Cancel] [Save Item]           │
└─────────────────────────────────────────────┘
```

### Phase 5: AI Assistant Tools (Weeks 11-12)

#### Deliverables:
1. Vault Access tool (read-only)
2. Vault Management tool (CRUD)
3. Password Generator tool
4. Tool registration in pro addon
5. Usage examples and documentation

#### Files Created:
```
addons/pro/includes/tools/
├── class-wp-mcp-ai-pro-tool-vault-access.php
├── class-wp-mcp-ai-pro-tool-vault-manage.php
└── class-wp-mcp-ai-pro-tool-generate-password.php
```

#### Tool 1: Vault Access
```php
Slug: vault_access
Description: Retrieve credentials from user's password vault
Parameters:
  - action: list|get|search
  - type: login|note|card|identity (optional filter)
  - item_id: integer (for get action)
  - search_term: string (for search action)
  - folder_id: integer (optional filter)
  - tags: array (optional filter)
Capability Flags:
  - pro
  - read-only
  - requires-authentication
  - sensitive-data
  - audit-logged
```

**Example Usage:**
```json
{
  "action": "search",
  "search_term": "github",
  "type": "login"
}
```

**Response:**
```json
{
  "success": true,
  "items": [
    {
      "id": 123,
      "name": "GitHub Account",
      "username": "user@example.com",
      "password": "ghp_1234567890abcdef",
      "uris": ["https://github.com"]
    }
  ],
  "count": 1
}
```

#### Tool 2: Vault Management
```php
Slug: vault_manage
Description: Create, update, or delete vault items
Parameters:
  - action: create|update|delete
  - item_id: integer (for update/delete)
  - type: login|note|card|identity
  - name: string
  - data: object (type-specific fields)
  - folder_id: integer (optional)
  - tags: array (optional)
  - favorite: boolean (optional)
Capability Flags:
  - pro
  - write
  - requires-authentication
  - sensitive-data
  - audit-logged
```

**Example Usage (Create):**
```json
{
  "action": "create",
  "type": "login",
  "name": "Production Database",
  "data": {
    "username": "db_admin",
    "password": "auto-generated-password",
    "uris": ["mysql://prod-db.example.com:3306"],
    "notes": "Production MySQL credentials"
  },
  "folder_id": 7,
  "tags": ["database", "production"]
}
```

#### Tool 3: Password Generator
```php
Slug: generate_password
Description: Generate secure random password
Parameters:
  - length: integer (12-128, default 20)
  - uppercase: boolean (default true)
  - lowercase: boolean (default true)
  - numbers: boolean (default true)
  - symbols: boolean (default true)
  - avoid_ambiguous: boolean (default true)
Capability Flags:
  - pro
  - stateless
```

### Phase 6: Testing & Security (Weeks 13-14)

#### Testing Strategy:

**Unit Tests:**
```
addons/pro/tests/
├── test-vault-encryption.php
├── test-vault-cpt.php
├── test-vault-rest-api.php
├── test-vault-tools.php
└── test-vault-security.php
```

**Test Coverage:**
- Encryption/decryption correctness
- Key derivation consistency
- IV uniqueness verification
- Auth tag validation
- CPT registration and meta save
- REST API authentication
- REST API authorization (user isolation)
- Rate limiting enforcement
- Tool execution with various inputs
- SQL injection prevention
- XSS prevention
- CSRF protection

**Security Audit:**
- Code review by security specialist
- Penetration testing
- Vulnerability scanning (WPScan, CodeQL)
- Encryption verification
- Access control validation
- Audit log verification

### Phase 7: Documentation & Launch (Weeks 15-16)

#### Documentation Files:
```
docs/guides/user/
├── password-vault-getting-started.md
├── password-vault-user-guide.md
└── password-vault-ai-automation.md

docs/guides/developer/
├── password-vault-architecture.md
├── password-vault-api-reference.md
└── password-vault-tool-reference.md

addons/pro/
└── PASSWORD_VAULT_README.md
```

#### Launch Checklist:
- [ ] All unit tests passing
- [ ] Security audit complete
- [ ] Documentation complete
- [ ] Migration scripts tested
- [ ] Backup/restore tested
- [ ] Performance tested (1000+ items)
- [ ] Browser compatibility tested
- [ ] Mobile responsive UI verified
- [ ] Accessibility compliance (WCAG 2.1 AA)
- [ ] Translation strings extracted
- [ ] Changelog updated
- [ ] Marketing materials prepared

## Database Schema

### WordPress Tables

#### Vault Items (CPT)
```sql
-- Uses wp_posts table
SELECT *
FROM wp_posts
WHERE post_type = 'mcp_vault_item'
  AND post_author = {current_user_id}
  AND post_status = 'private';

-- Meta fields in wp_postmeta
post_id | meta_key                | meta_value
--------|------------------------|---------------------------
123     | _vault_type            | login
123     | _vault_encrypted_data  | eyJhbGciOiJ...base64...
123     | _vault_iv              | MTIzNDU2Nzg5...base64
123     | _vault_auth_tag        | YWJjZGVmZ2g...base64
123     | _vault_favorite        | 0
123     | _vault_folder_id       | 5
123     | _vault_tags            | github,development,work
123     | _vault_last_used       | 1737648000
123     | _vault_access_count    | 42
```

#### Vault Folders (CPT)
```sql
-- Uses wp_posts table (hierarchical)
SELECT *
FROM wp_posts
WHERE post_type = 'mcp_vault_folder'
  AND post_author = {current_user_id}
ORDER BY post_title;

-- Meta fields
post_id | meta_key      | meta_value
--------|--------------|------------
5       | _folder_color | #FF5733
5       | _folder_icon  | dashicons-lock
```

### Custom Tables

#### Audit Log
```sql
CREATE TABLE wp_mcp_vault_audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  action varchar(50) NOT NULL,
  item_id bigint(20) unsigned DEFAULT NULL,
  item_name varchar(255) DEFAULT NULL,
  item_type varchar(50) DEFAULT NULL,
  ip_address varchar(45) NOT NULL,
  user_agent text,
  context varchar(50) DEFAULT NULL,
  details longtext,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY user_id (user_id),
  KEY item_id (item_id),
  KEY action (action),
  KEY created_at (created_at),
  KEY user_action_date (user_id, action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Logged Actions:**
- `item_created` - New vault item created
- `item_viewed` - Item accessed/decrypted
- `item_updated` - Item modified
- `item_deleted` - Item deleted
- `item_restored` - Item restored from trash
- `password_generated` - Password generated
- `vault_exported` - Vault data exported
- `vault_imported` - Vault data imported
- `login_failed` - Failed vault access attempt

#### Optional: JetEngine CCT
```sql
CREATE TABLE wp_jet_cct_mcp_vault_items (
  _ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  cct_status varchar(50) DEFAULT 'publish',
  cct_modified datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  cct_created datetime DEFAULT CURRENT_TIMESTAMP,
  cct_author_id bigint(20) unsigned NOT NULL,
  
  -- Vault fields
  item_name varchar(255) NOT NULL,
  item_type varchar(50) NOT NULL,
  encrypted_data longtext NOT NULL,
  encryption_iv varchar(255) NOT NULL,
  auth_tag varchar(255) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  folder_id bigint(20) unsigned DEFAULT NULL,
  tags text,
  is_favorite tinyint(1) DEFAULT 0,
  last_used datetime DEFAULT NULL,
  access_count int(11) DEFAULT 0,
  
  PRIMARY KEY (_ID),
  KEY user_id (user_id),
  KEY item_type (item_type),
  KEY folder_id (folder_id),
  KEY is_favorite (is_favorite),
  KEY last_used (last_used),
  KEY user_type_favorite (user_id, item_type, is_favorite)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Security Model

### Encryption Details

**Algorithm:** AES-256-GCM (Galois/Counter Mode)
- Block cipher: AES with 256-bit key
- Mode: GCM (provides both confidentiality and authenticity)
- IV: 128-bit (16 bytes), randomly generated per encryption
- Auth tag: 128-bit (16 bytes), verifies integrity

**Key Derivation:**
```
Master Key Material = WordPress AUTH_KEY constant
User Salt = 32-byte random hex (stored in user_meta)
User ID = WordPress user ID

Derived Key = PBKDF2-HMAC-SHA256(
    password = AUTH_KEY + User Salt + User ID,
    salt = User Salt,
    iterations = 100,000,
    key_length = 32 bytes
)
```

**Data Flow:**
1. User creates vault item with plaintext password
2. System derives user-specific encryption key
3. Generate random 16-byte IV
4. Encrypt plaintext using AES-256-GCM (key, IV)
5. Store: encrypted data + IV + auth tag (all base64)
6. On retrieval: decrypt using same key + stored IV + verify auth tag

**Security Properties:**
- ✅ Encryption at rest (database dumps are encrypted)
- ✅ Per-user key isolation (users can't decrypt each other's data)
- ✅ Authenticated encryption (tampering detected via auth tag)
- ✅ Forward secrecy (changing AUTH_KEY invalidates all keys)
- ✅ Unique IV per encryption (prevents pattern analysis)

### Access Control

**WordPress Capabilities:**
```php
'manage_own_vault' => [
    'administrator' => true,
    'editor' => false,
    'author' => false,
    'contributor' => false,
    'subscriber' => false,
]
```

**Filters for customization:**
```php
// Allow custom roles to manage vault
add_filter( 'user_has_cap', function( $allcaps, $caps, $args ) {
    if ( in_array( 'manage_own_vault', $caps ) ) {
        if ( in_array( 'custom_role', $allcaps ) ) {
            $allcaps['manage_own_vault'] = true;
        }
    }
    return $allcaps;
}, 10, 3 );
```

**User Isolation:**
- Users can ONLY access their own vault items
- Enforced at database query level (post_author = user_id)
- Enforced at REST API level (capability + ownership checks)
- Enforced at UI level (only show user's items)

**Admin Override:**
- Site administrators CANNOT access user vaults by default
- Optional filter to grant admin access (disabled by default for privacy)
- All admin access logged in audit log

### Threat Model

**Protected Against:**
- ✅ Unauthorized WordPress users
- ✅ Database SQL injection
- ✅ XSS attacks
- ✅ CSRF attacks
- ✅ Database dumps/backups
- ✅ Man-in-the-middle (HTTPS required)
- ✅ Brute force (rate limiting)
- ✅ Session hijacking (WordPress session security)
- ✅ Data tampering (GCM auth tags)

**NOT Protected Against:**
- ❌ Compromised WordPress admin account
- ❌ Server-level access (root/SSH)
- ❌ PHP process memory dumps
- ❌ WordPress site malware/backdoors
- ❌ Physical server access

**Mitigation Strategies:**
- Regular WordPress security updates
- Strong admin passwords + 2FA (Wordfence, iThemes Security)
- Server hardening (file permissions, disable exec functions)
- Web application firewall (Cloudflare, Sucuri)
- Regular security scans (WPScan, Sucuri)
- Encrypted database backups (off-site)
- Audit log monitoring
- Incident response plan

## Features Comparison

| Feature | Bitwarden Cloud | Vaultwarden (Docker) | WP-Native Manager |
|---------|----------------|----------------------|-------------------|
| **Deployment** | SaaS | Self-hosted | WordPress plugin |
| **Setup Complexity** | Easy | Medium | Easy |
| **System Requirements** | None | Docker | WordPress 6.0+ |
| **Compatible Clients** | Official apps | Official apps | REST API only |
| **Browser Extension** | Yes | Yes | Optional (future) |
| **Mobile Apps** | Yes | Yes | No (MVP) |
| **Desktop App** | Yes | Yes | No (MVP) |
| **Encryption** | Client-side (zero-knowledge) | Client-side | Server-side (transparent) |
| **WordPress Integration** | API | API | Native |
| **AI Assistant Access** | Via API | Via API | Direct (same database) |
| **User Management** | Separate | Separate | WordPress users |
| **Hosting Requirements** | None | Docker + VPS | Standard WordPress hosting |
| **Multi-tenant** | Yes | Yes | Per-site (multisite capable) |
| **Cost** | $10/year | Free (hosting costs) | Free with Pro addon |
| **Development Time** | N/A | 2-3 months | 12-16 weeks |
| **Maintenance** | None | Medium | Low |

**When to Choose WP-Native:**
- ✅ Already using WordPress Pro addon
- ✅ Need AI assistant automation
- ✅ No Docker available (shared hosting)
- ✅ Want WordPress-integrated experience
- ✅ Don't need Bitwarden ecosystem compatibility
- ✅ Team already familiar with WordPress

**When to Choose Vaultwarden:**
- ✅ Need official Bitwarden client compatibility
- ✅ Have Docker available
- ✅ Want true zero-knowledge encryption
- ✅ Need mobile/desktop apps
- ✅ Require browser extension
- ✅ Want maximum security (client-side crypto)

## Use Cases

### Use Case 1: AI Assistant Deployment Automation
```
User: "Deploy the staging site to production"

AI Assistant:
1. vault_access(search_term="production ftp", type="login")
2. Retrieves FTP credentials securely
3. Connects to production server
4. Deploys files
5. Credentials never exposed in chat history
6. Access logged in audit log
```

### Use Case 2: Automated Password Rotation
```
User: "Rotate all database passwords older than 90 days"

AI Assistant:
1. vault_access(type="login", tags=["database"])
2. Filters items by last_used > 90 days
3. For each database:
   a. generate_password(length=32, symbols=true)
   b. Update database server password
   c. vault_manage(action="update", new password)
4. Reports success/failures
5. All changes logged
```

### Use Case 3: Team Onboarding
```
User: "Create staging environment access for new developer John"

AI Assistant:
1. Creates staging server account for John
2. generate_password(length=20)
3. vault_manage(action="create", type="login", name="John - Staging SSH")
4. Stores credentials in "Team" folder
5. Creates WordPress user for John
6. Sends welcome email with initial access
```

### Use Case 4: Compliance Reporting
```
User: "Generate credential access report for Q1 2026"

AI Assistant:
1. Queries audit log for date range
2. Filters by action="item_viewed"
3. Groups by user_id and item_type
4. Generates report:
   - Total accesses
   - Most accessed items
   - Access by user
   - Access by time of day
5. Exports to CSV/PDF
```

### Use Case 5: Security Audit
```
User: "Check for weak or compromised passwords"

AI Assistant:
1. vault_access(action="list", type="login")
2. For each password:
   a. Check strength (length, complexity)
   b. Check against HIBP API (breach detection)
   c. Check for reuse across items
3. Generates security report:
   - 3 weak passwords
   - 1 compromised password
   - 2 reused passwords
4. Recommends actions
```

## Timeline & Budget

### Development Timeline: 16 Weeks

**Phase 1: Core Data Structure (Weeks 1-3)**
- CPT/CCT setup
- Database schema
- Migration scripts

**Phase 2: Encryption Layer (Weeks 4-5)**
- Encryption service
- Key management
- Security utilities

**Phase 3: REST API (Weeks 6-7)**
- Endpoint implementation
- Authentication
- Rate limiting

**Phase 4: Admin UI (Weeks 8-10)**
- Admin pages
- Item editor
- Dashboard

**Phase 5: AI Tools (Weeks 11-12)**
- Tool implementation
- Tool registration
- Documentation

**Phase 6: Testing & Security (Weeks 13-14)**
- Unit tests
- Security audit
- Penetration testing

**Phase 7: Documentation & Launch (Weeks 15-16)**
- User documentation
- Developer documentation
- Launch preparation

### Budget Estimate: $40,000 - $55,000

**Team Required:**
- 1x Senior PHP Developer (16 weeks) - $25,000-$35,000
- 1x Frontend Developer (6 weeks) - $8,000-$10,000
- 1x Security Specialist (2 weeks) - $5,000-$7,000
- 1x Technical Writer (2 weeks) - $2,000-$3,000

**Breakdown:**
- Development: $33,000-$45,000 (80%)
- Security audit: $5,000-$7,000 (12%)
- Documentation: $2,000-$3,000 (8%)

**Ongoing Costs:**
- Maintenance: ~5 hours/month ($500/month)
- Security updates: ~2 hours/quarter ($200/quarter)
- Feature enhancements: As needed

## Success Metrics

**Adoption Metrics:**
- 40% of Pro users enable Password Vault within 60 days
- Average 25+ vault items per active user
- 50+ vault accesses per week per active user

**Usage Metrics:**
- 75% of vault users use AI assistant tools monthly
- <2% error rate on encryption/decryption
- <1s average API response time

**Security Metrics:**
- Zero security incidents related to vault
- 100% of capability checks passing
- Complete audit trail for all operations
- <5% of passwords flagged as weak/compromised

**Business Metrics:**
- Vault feature mentioned in 30%+ of Pro renewals
- 15%+ conversion rate for trial users who use vault
- <1% support tickets related to vault issues

## Future Enhancements (Post-MVP)

### Version 1.4.0 (Q2 2026)
- [ ] Master password feature (optional extra security layer)
- [ ] Vault sharing between WordPress users
- [ ] Time-limited access (temporary credentials)
- [ ] Password health dashboard
- [ ] Automated password expiration reminders

### Version 1.5.0 (Q3 2026)
- [ ] Chrome/Firefox browser extension
- [ ] One-time password (OTP/TOTP) support
- [ ] Secure file attachments (encrypted files)
- [ ] Emergency access (designated contacts)
- [ ] Vault templates (pre-configured item sets)

### Version 2.0.0 (Q4 2026)
- [ ] Mobile app (React Native)
- [ ] Biometric unlock (fingerprint, Face ID)
- [ ] Hardware security key support (YubiKey)
- [ ] Advanced sharing (organizations, collections)
- [ ] SSO integration (SAML, OIDC)

## Risks & Mitigation

### Risk 1: Performance with Large Vaults
**Risk:** Encryption/decryption may slow down with 1000+ items  
**Mitigation:**
- Lazy decryption (only decrypt when requested)
- Caching layer for frequently accessed items
- Pagination for list operations
- Background processing for bulk operations
- Optional CCT for enhanced query performance

### Risk 2: Key Management Complexity
**Risk:** User keys could be lost if user meta deleted  
**Mitigation:**
- Backup user salt in separate options table
- Export includes encryption keys (encrypted with WordPress keys)
- Recovery process using WordPress AUTH_KEY
- Documentation for disaster recovery

### Risk 3: Hosting Compatibility
**Risk:** Some hosts may restrict encryption functions  
**Mitigation:**
- Check for openssl extension on activation
- Fallback to alternative encryption if needed
- Clear error messages and documentation
- List of compatible hosting providers

### Risk 4: User Adoption
**Risk:** Users may not understand or trust the system  
**Mitigation:**
- Clear onboarding wizard
- Video tutorials and documentation
- Security certifications and audits
- Transparency about encryption methods
- Success stories and testimonials

## Conclusion

The WordPress-Native Password Manager provides a practical, secure solution for credential management within the WordPress ecosystem. By leveraging existing CPT/CCT patterns and transparent encryption, it offers a user-friendly alternative to complex Docker-based solutions while maintaining strong security.

**Key Advantages:**
- No Docker dependency (works on any WordPress host)
- Native WordPress integration (familiar UI/UX)
- AI assistant access (unique automation capabilities)
- Proven architecture (follows existing Pro addon patterns)
- Reasonable development timeline (16 weeks vs 6+ months)

**Recommendation:** ✅ **PROCEED WITH IMPLEMENTATION**

This approach balances security, usability, and feasibility, making it an excellent addition to the Pro addon feature set.

---

**Created:** January 23, 2026  
**Status:** Awaiting stakeholder approval  
**Next Steps:** Review plan, approve budget, begin Phase 1
