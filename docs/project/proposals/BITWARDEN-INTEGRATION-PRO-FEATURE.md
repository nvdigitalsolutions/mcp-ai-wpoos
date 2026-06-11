# Bitwarden Server Integration - Pro Feature Proposal

**Status:** ✅ Implemented (v1.1.29) — Full Password Vault subsystem in addons/pro/includes/vault/ (8 files): AES-256-GCM encryption, Bitwarden import/export/sync, REST controller, vault CPTs.
**Created:** January 23, 2026  
**Proposed By:** GitHub Issue Request  
**Target Release:** Pro 1.2.0  

## Executive Summary

Add Bitwarden password vault integration as a new Pro feature, enabling AI assistants to securely manage credentials, retrieve passwords, and automate identity management workflows through the Bitwarden API.

## Problem Statement

Organizations using Open Operator System need secure credential management for:
- **Automated Authentication**: AI assistants need credentials to access external services
- **Password Rotation**: Programmatic password updates and rotation
- **Secure Storage**: Centralized, encrypted credential storage
- **Team Collaboration**: Shared credentials via Bitwarden Organizations
- **Compliance**: Audit trails for credential access

Currently, there's no standardized way for assistants to securely access and manage credentials.

## Proposed Solution

### 1. Core Integration Components

#### A. Base Plugin Integration (includes/integrations/)

**OAuth Handler** (`class-wp-mcp-ai-bitwarden-oauth-handler.php`)
- OAuth 2.0 authentication flow
- Token management (access + refresh tokens)
- Support for self-hosted Bitwarden servers
- Connection status UI in admin settings

**API Client** (`class-wp-mcp-ai-bitwarden-client.php`)
- Vault item operations (CRUD)
- Organization management
- Collection access
- Search capabilities
- Automatic token refresh

**Integration Init** (`bitwarden-integration-init.php`)
- Hook registration
- OAuth callback handlers
- Disconnect functionality

#### B. Pro Add-on Tools (addons/pro/includes/tools/)

Three specialized tools for comprehensive vault management:

**Tool 1: Bitwarden Vault Access** (`class-wp-mcp-ai-pro-tool-bitwarden-vault-access.php`)
- List vault items (with filtering)
- Search by name/URI
- Retrieve specific credentials
- Read-only operations
- Type filtering (Login, Note, Card, Identity)

**Tool 2: Bitwarden Store Credential** (`class-wp-mcp-ai-pro-tool-bitwarden-store-credential.php`)
- Create new vault items
- Update existing credentials
- Password generation
- Secure note creation
- Support for all item types

**Tool 3: Bitwarden Organization Management** (`class-wp-mcp-ai-pro-tool-bitwarden-organization.php`)
- List organizations
- Manage collections
- Share credentials with teams
- Enterprise-focused operations

### 2. Configuration Requirements

#### Admin Settings (Settings → NV oOS → Tools → External Tools)

New section: **Bitwarden Integration**

Fields:
- `bitwarden_client_id` - OAuth Client ID
- `bitwarden_client_secret` - OAuth Client Secret
- `bitwarden_identity_server` - Identity server URL (default: https://identity.bitwarden.com)
- `bitwarden_api_server` - API server URL (default: https://api.bitwarden.com)
- `bitwarden_access_token` - Stored access token (encrypted)
- `bitwarden_refresh_token` - Stored refresh token (encrypted)
- `bitwarden_token_expires` - Token expiry timestamp
- `bitwarden_user_email` - Connected user email
- `bitwarden_user_id` - Connected user ID

UI Components:
- Connection status indicator
- "Connect Bitwarden Account" button
- "Disconnect" button
- Self-hosted server configuration toggle

### 3. Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    WordPress Admin UI                       │
│  Settings → NV oOS → Tools → External Tools → Bitwarden    │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      │ OAuth 2.0 Flow
                      ▼
┌─────────────────────────────────────────────────────────────┐
│              Bitwarden OAuth Handler                        │
│  • Authorization redirect                                   │
│  • Token exchange                                           │
│  • Token refresh                                            │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      │ API Calls
                      ▼
┌─────────────────────────────────────────────────────────────┐
│              Bitwarden API Client                           │
│  • Vault operations (list, get, create, update, delete)    │
│  • Organization management                                  │
│  • Collection access                                        │
│  • Search functionality                                     │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      │ HTTPS
                      ▼
┌─────────────────────────────────────────────────────────────┐
│         Bitwarden Server (Cloud or Self-Hosted)            │
│  • identity.bitwarden.com (OAuth)                          │
│  • api.bitwarden.com (REST API)                            │
│  • Or custom self-hosted URLs                              │
└─────────────────────────────────────────────────────────────┘
```

### 4. Security Considerations

#### Token Storage
- Access tokens stored in WordPress options (encrypted)
- Refresh tokens encrypted at rest
- Automatic token expiry handling
- Secure token refresh flow

#### Capability Checks
- `manage_options` required for OAuth connection
- Per-tool capability checks via filters
- `wp_mcp_ai_bitwarden_access_capability` filter for vault access
- `wp_mcp_ai_bitwarden_write_capability` filter for vault writes

#### Audit Trail
- Log all vault access operations
- Track credential retrieval
- Record modifications
- Integration with existing WP MCP AI logging system

#### Data Security
- Never store passwords in plaintext
- All API communication over HTTPS
- Vault data never cached locally
- Immediate token revocation on disconnect

### 5. Use Cases

#### Use Case 1: Automated Deployment Credentials
```
User: "Deploy the staging site to production"

Assistant uses:
1. bitwarden_vault_access to retrieve FTP credentials
2. Uses credentials to authenticate with hosting
3. Performs deployment
4. No credentials exposed in conversation
```

#### Use Case 2: Password Rotation
```
User: "Rotate all database passwords older than 90 days"

Assistant uses:
1. bitwarden_vault_access to list all database credentials
2. Filters by age
3. bitwarden_store_credential to generate and update new passwords
4. Updates database server passwords
5. Stores new passwords in vault
```

#### Use Case 3: Team Onboarding
```
User: "Create accounts for new team member John on all our services"

Assistant uses:
1. bitwarden_organization to access shared credentials
2. Creates accounts on services using shared admin credentials
3. bitwarden_store_credential to save John's new credentials
4. Shares credentials with John via Bitwarden collection
```

#### Use Case 4: Compliance Reporting
```
User: "Show me all credentials accessed in the last 30 days"

Assistant uses:
1. Reviews WP MCP AI logs for bitwarden_vault_access operations
2. Generates compliance report
3. No actual passwords displayed, just access metadata
```

### 6. Implementation Plan

#### Phase 1: Core Integration (Base Plugin) ✅ IN PROGRESS
- [x] Create OAuth handler class
- [x] Create API client class
- [x] Create integration init file
- [ ] Add admin settings UI
- [ ] Add connection status display
- [ ] Add settings field sanitization
- [ ] Test OAuth flow with Bitwarden Cloud
- [ ] Test OAuth flow with self-hosted server

#### Phase 2: Pro Tools (Pro Add-on)
- [ ] Implement Bitwarden Vault Access tool
- [ ] Implement Bitwarden Store Credential tool
- [ ] Implement Bitwarden Organization Management tool
- [ ] Register tools in pro addon
- [ ] Add capability filters
- [ ] Add usage logging

#### Phase 3: Documentation
- [ ] Create integration guide (docs/guides/developer/integration/)
- [ ] Document OAuth setup process
- [ ] Document tool usage examples
- [ ] Update tool reference documentation
- [ ] Add security best practices guide
- [ ] Create troubleshooting guide

#### Phase 4: Testing
- [ ] Unit tests for OAuth handler
- [ ] Unit tests for API client
- [ ] Integration tests for tools
- [ ] Manual testing with Bitwarden Cloud
- [ ] Manual testing with self-hosted server
- [ ] Security penetration testing
- [ ] Performance testing (large vaults)

#### Phase 5: Security & Compliance
- [ ] Run CodeQL security scanner
- [ ] Penetration testing
- [ ] Audit token storage security
- [ ] Review capability checks
- [ ] Document security model
- [ ] Create security disclosure process

### 7. Tool Specifications

#### Tool: bitwarden_vault_access

**Slug:** `bitwarden_vault_access`

**Description:** Retrieve credentials and items from Bitwarden vault

**Parameters:**
```json
{
  "action": "list|get|search",
  "type": "login|note|card|identity",
  "item_id": "string (for get action)",
  "search_term": "string (for search action)",
  "favorite_only": "boolean",
  "organization_id": "string (optional)",
  "collection_id": "string (optional)"
}
```

**Response:**
```json
{
  "success": true,
  "items": [
    {
      "id": "abc123",
      "name": "GitHub Account",
      "type": "Login",
      "username": "user@example.com",
      "uris": ["https://github.com"],
      "favorite": false
    }
  ],
  "count": 1
}
```

**Capability Flags:**
- `pro` - Pro tier only
- `read-only` - No vault modifications
- `requires-credentials` - Needs Bitwarden OAuth
- `external-api` - Makes API calls
- `network-dependent` - Requires connectivity
- `sensitive-data` - Handles credentials

#### Tool: bitwarden_store_credential

**Slug:** `bitwarden_store_credential`

**Description:** Create or update credentials in Bitwarden vault

**Parameters:**
```json
{
  "action": "create|update",
  "item_id": "string (for update)",
  "type": "login|note|card|identity",
  "name": "string",
  "username": "string (for login)",
  "password": "string (for login)",
  "uris": ["string"],
  "notes": "string",
  "favorite": "boolean",
  "folder_id": "string (optional)",
  "organization_id": "string (optional)",
  "collection_ids": ["string"]
}
```

**Response:**
```json
{
  "success": true,
  "item": {
    "id": "abc123",
    "name": "GitHub Account",
    "type": "Login",
    "created_at": "2026-01-23T12:00:00Z",
    "updated_at": "2026-01-23T12:00:00Z"
  }
}
```

**Capability Flags:**
- `pro` - Pro tier only
- `write` - Modifies vault data
- `requires-credentials` - Needs Bitwarden OAuth
- `external-api` - Makes API calls
- `network-dependent` - Requires connectivity
- `sensitive-data` - Handles credentials

#### Tool: bitwarden_organization

**Slug:** `bitwarden_organization`

**Description:** Manage Bitwarden organizations and collections

**Parameters:**
```json
{
  "action": "list_orgs|list_collections|share_item",
  "organization_id": "string",
  "collection_id": "string",
  "item_id": "string (for share_item)"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "organizations": [
      {
        "id": "org123",
        "name": "My Company",
        "type": "enterprise",
        "seats": 50,
        "max_collections": 100
      }
    ]
  }
}
```

**Capability Flags:**
- `pro` - Pro tier only
- `read-write` - Can read and modify
- `requires-credentials` - Needs Bitwarden OAuth
- `external-api` - Makes API calls
- `network-dependent` - Requires connectivity
- `enterprise` - Enterprise features

### 8. Benefits

#### For Users
✅ **Secure Automation**: AI assistants can access credentials without exposing them  
✅ **Centralized Management**: All credentials in one secure vault  
✅ **Team Collaboration**: Share credentials via organizations  
✅ **Audit Trail**: Complete logging of credential access  
✅ **Self-Hosted Support**: Works with self-hosted Bitwarden servers  

#### For Developers
✅ **Standard API**: Well-documented Bitwarden REST API  
✅ **OAuth 2.0**: Industry-standard authentication  
✅ **Extensible**: Easy to add more vault operations  
✅ **Type Safety**: Strong typing for vault items  

#### For Enterprise
✅ **Compliance**: Audit logs for credential access  
✅ **SSO Integration**: Bitwarden supports SAML/OIDC  
✅ **Role-Based Access**: Organization-level permissions  
✅ **Self-Hosted**: On-premises deployment option  

### 9. Comparison to Alternatives

| Feature | Bitwarden | 1Password | LastPass | KeePass |
|---------|-----------|-----------|----------|---------|
| REST API | ✅ Full | ✅ Full | ⚠️ Limited | ❌ None |
| Self-Hosted | ✅ Yes | ❌ No | ❌ No | ✅ Yes |
| OAuth 2.0 | ✅ Yes | ✅ Yes | ✅ Yes | ❌ No |
| Organizations | ✅ Yes | ✅ Yes | ✅ Yes | ❌ No |
| Open Source | ✅ Yes | ❌ No | ❌ No | ✅ Yes |
| Enterprise | ✅ Yes | ✅ Yes | ✅ Yes | ⚠️ Limited |
| Price | 💰 $10/yr | 💰💰 $8/mo | 💰💰 $4/mo | 💰 Free |

**Why Bitwarden?**
- ✅ Open source (security transparency)
- ✅ Self-hosted option (data sovereignty)
- ✅ Excellent API documentation
- ✅ Affordable pricing
- ✅ Active development community
- ✅ Enterprise-ready

### 10. Risks & Mitigations

#### Risk 1: Token Leakage
**Mitigation:**
- Encrypt tokens at rest
- Use WordPress transients with expiry
- Implement token rotation
- Clear tokens on disconnect
- Never log full tokens

#### Risk 2: Over-Privileged Access
**Mitigation:**
- Use least-privilege OAuth scopes
- Implement capability checks per tool
- Allow administrators to restrict tool access
- Log all credential access
- Support read-only mode

#### Risk 3: API Rate Limiting
**Mitigation:**
- Implement exponential backoff
- Cache organization/collection lists
- Batch operations where possible
- Respect Bitwarden rate limits
- Queue long-running operations

#### Risk 4: Self-Hosted Server Issues
**Mitigation:**
- Validate server URLs
- Check SSL certificates
- Support custom CA certificates
- Comprehensive error messages
- Fallback to cloud if self-hosted fails

### 11. Success Metrics

**Adoption:**
- 25% of Pro users connect Bitwarden within 30 days
- 50+ active Bitwarden connections by 90 days

**Usage:**
- Average 100+ vault access operations per week
- 75% of connected users use at least one tool weekly
- <1% error rate on API calls

**Security:**
- Zero security incidents related to token leakage
- 100% of capability checks passing
- Complete audit trail for all operations

**Performance:**
- <2s average API response time
- 99.5% OAuth success rate
- <5% token refresh failures

### 12. Future Enhancements

**Phase 2 (v1.3.0):**
- [ ] Bitwarden Send integration (temporary sharing)
- [ ] Vault attachment management (files)
- [ ] Emergency access configuration
- [ ] Password health reports
- [ ] Breach monitoring integration

**Phase 3 (v1.4.0):**
- [ ] Bitwarden CLI integration (alternative to API)
- [ ] Bulk credential import/export
- [ ] Custom field management
- [ ] TOTP code generation
- [ ] Vault backup/restore

**Enterprise Features:**
- [ ] Directory Connector sync
- [ ] SSO configuration
- [ ] Advanced event logging
- [ ] Policy enforcement
- [ ] Custom roles

### 13. Dependencies

**External:**
- Bitwarden account (Cloud or self-hosted)
- OAuth application registration
- PHP 7.4+ with cURL support
- WordPress 6.0+

**Internal:**
- Base plugin: includes/class-wp-mcp-ai-admin-settings.php
- Base plugin: includes/class-wp-mcp-ai-logger.php
- Pro plugin: addons/pro/mcp-ai-wpoos-pro.php
- Tool registry system

**Optional:**
- JetEngine (for CCT storage of logs)
- WPCode (for custom credential usage snippets)

### 14. Documentation Plan

**User Documentation:**
1. Getting Started Guide
   - Creating Bitwarden account
   - Registering OAuth application
   - Connecting to WordPress
   - First credential retrieval

2. Administrator Guide
   - Security configuration
   - Capability management
   - Audit log review
   - Self-hosted server setup

3. Use Case Examples
   - Automated deployments
   - Password rotation workflows
   - Team onboarding
   - Compliance reporting

**Developer Documentation:**
1. Integration Guide (docs/guides/developer/integration/bitwarden-integration.md)
   - Architecture overview
   - OAuth flow details
   - API client usage
   - Tool development

2. API Reference
   - All client methods
   - Tool parameters
   - Response formats
   - Error handling

3. Security Guide
   - Token security
   - Capability checks
   - Audit logging
   - Best practices

### 15. Timeline

**Week 1-2: Core Integration (Base Plugin)**
- OAuth handler implementation ✅ DONE
- API client implementation ✅ DONE
- Integration initialization ✅ DONE
- Admin UI integration
- Testing OAuth flows

**Week 3-4: Pro Tools**
- Vault Access tool
- Store Credential tool
- Organization Management tool
- Tool registration and testing

**Week 5: Documentation**
- Integration guide
- User documentation
- API reference
- Security documentation

**Week 6: Testing & Security**
- Unit tests
- Integration tests
- Security review
- CodeQL scanning
- Penetration testing

**Week 7-8: Beta Testing**
- Internal testing
- Selected user beta
- Bug fixes
- Performance optimization

**Week 9: Release**
- Final testing
- Documentation review
- Release preparation
- Marketing materials

### 16. Conclusion

The Bitwarden integration represents a significant enhancement to Open Operator System Pro, providing enterprise-grade credential management for AI assistants. With its strong security model, self-hosted option, and comprehensive API, Bitwarden is the ideal choice for organizations requiring secure, automated credential access.

The implementation follows our established patterns for third-party integrations (GitHub, QuickBooks, etc.) while adding robust security measures appropriate for credential management. The modular design allows for future enhancements without breaking changes.

**Recommendation:** ✅ APPROVE and proceed with implementation per the phased plan outlined above.

---

## Appendix A: OAuth Application Setup

### Bitwarden Cloud
1. Go to https://vault.bitwarden.com
2. Navigate to Settings → Organizations
3. Create OAuth application
4. Set redirect URI: `https://yoursite.com/wp-admin/admin-post.php?action=wp_mcp_ai_bitwarden_oauth_callback`
5. Copy Client ID and Client Secret

### Self-Hosted Bitwarden
1. Access your Bitwarden admin panel
2. Navigate to Settings → API Keys
3. Create OAuth application
4. Configure redirect URI
5. Note custom Identity and API server URLs

## Appendix B: Bitwarden API Endpoints

**Identity Server:**
- `/connect/authorize` - OAuth authorization
- `/connect/token` - Token exchange/refresh

**API Server:**
- `/ciphers` - Vault items
- `/organizations` - Organizations
- `/collections` - Collections
- `/accounts/profile` - User profile
- `/sync` - Vault synchronization

## Appendix C: Error Codes

| Code | Description | Resolution |
|------|-------------|------------|
| `no_access_token` | Not connected | Connect Bitwarden account |
| `unauthorized` | Token invalid | Reconnect account |
| `api_error` | API failure | Check server status |
| `rate_limited` | Too many requests | Wait and retry |
| `invalid_item` | Item not found | Check item ID |
| `permission_denied` | Insufficient access | Check organization roles |

---

**Last Updated:** January 23, 2026  
**Status:** 🚧 Implementation in progress  
**Next Review:** Week of January 27, 2026
