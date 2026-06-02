# Access Control Procedure
## Open Operator System (NV oOS) WordPress Plugin

**Document Classification:** Internal  
**Version:** 1.0.0  
**Effective Date:** 2026-01-05  
**Review Date:** 2026-04-05  
**Document Owner:** Chief Information Security Officer (CISO)

---

## 1. Purpose

This procedure defines the access control requirements and processes for the NV oOS WordPress plugin, ensuring that access to information assets is granted based on business and security requirements.

## 2. Scope

This procedure applies to:
- All users (administrators, developers, contributors, end-users)
- All systems and services within ISMS scope
- All information assets requiring access control
- Physical and logical access

## 3. Access Control Policy

### 3.1 Principles

**Least Privilege:** Users receive minimum access rights necessary for their role  
**Need-to-Know:** Access granted only to information required for job function  
**Separation of Duties:** Conflicting responsibilities are separated  
**Defense in Depth:** Multiple layers of access control

### 3.2 Access Control Layers

```
Layer 1: Network Access (HTTPS/TLS)
    ↓
Layer 2: WordPress Authentication
    ↓
Layer 3: User Roles & Capabilities
    ↓
Layer 4: Tool-Level Permissions
    ↓
Layer 5: API Rate Limiting
    ↓
Layer 6: Audit Logging
```

## 4. User Lifecycle Management

### 4.1 User Registration

**Process:**
1. User account created via WordPress registration or admin
2. Default role assigned based on registration method
3. Capabilities inherited from role
4. Initial password meets strength requirements
5. Account activation (email verification if enabled)
6. Audit log entry created

**Default Roles:**
- **Administrator:** Full access to plugin settings and tools
- **Editor:** Access to content tools
- **Author:** Limited tool access
- **Subscriber:** Chat access only (guest mode)

### 4.2 Access Provisioning

**For Plugin Administrators:**
```php
Required Capability: manage_options
Additional Capabilities:
- edit_mcp_ai_assistants
- delete_mcp_ai_assistants
- manage_mcp_ai_tools
- view_mcp_ai_logs
```

**For Assistant Creators:**
```php
Required Capability: edit_posts or edit_mcp_ai_assistants
Tool Permissions: Configurable per assistant
```

**For End Users (Chat):**
```php
Options:
1. WordPress authenticated users (any role)
2. Guest users (temporary tokens)
3. API clients (API key authentication)
```

### 4.3 Access Modification

**Trigger Events:**
- Role change
- Permission adjustment
- Assistant tool configuration change
- Security requirement change

**Process:**
1. Request submitted (or automatic via role change)
2. Verification of business need
3. Approval by administrator
4. Access modified in system
5. User notification (if applicable)
6. Audit log entry

### 4.4 Access Revocation

**Trigger Events:**
- User account deletion
- Role change (downgrade)
- Security violation
- Inactivity (optional policy)
- Employment termination

**Process:**
1. Access immediately disabled
2. Active sessions terminated
3. API keys invalidated
4. Audit log entry
5. Review of user's recent activities

## 5. Authentication Methods

### 5.1 WordPress Authentication (Primary)

**Requirements:**
- Username and password
- Password complexity: Enforced by WordPress
- Session management: WordPress session handling
- MFA support: Compatible with WordPress MFA plugins

**Implementation:**
```php
// WordPress handles authentication
is_user_logged_in()
current_user_can('manage_options')
```

### 5.2 API Key Authentication

**For Programmatic Access:**
```
Authentication: Bearer <api_key>
Format: nvoos_xxxxxxxxxxxxxxxxxxxxx
```

**Process:**
1. Generate via plugin admin
2. Key hashed and stored encrypted
3. Rate limits applied per key
4. Key can be revoked anytime
5. Expiration date optional

### 5.3 JWT Authentication

**For API Clients:**
- Requires Simple JWT Login plugin
- Token-based authentication
- Configurable expiration
- Refresh token support

### 5.4 Auth0 Integration

**For Enterprise SSO:**
- OAuth 2.0 flow
- SAML support
- Corporate identity provider integration
- Automatic role mapping

### 5.5 Guest Token Authentication

**For Public Chat:**
```
Header: X-WP-MCP-AI-Guest: <token>
Duration: 24 hours (configurable)
Scope: Chat access only
Rate Limits: Stricter than authenticated users
```

## 6. Authorization Model

### 6.1 WordPress Capabilities

**Core Capabilities:**
```php
manage_options          // Plugin administration
edit_mcp_ai_assistants  // Create/edit assistants
delete_mcp_ai_assistants // Delete assistants
manage_mcp_ai_tools     // Configure tools
view_mcp_ai_logs        // View audit logs
use_mcp_ai_chat         // Use chat interface
```

### 6.2 Tool-Level Permissions

Each tool declares required capability:

```php
class WP_MCP_AI_Tool_Example {
    public function get_definition() {
        return array(
            'name' => 'Example Tool',
            'required_capability' => 'edit_posts',
        );
    }
}
```

**Capability Check:**
```php
if (!current_user_can($tool->get_required_capability())) {
    return new WP_Error('unauthorized', 'Insufficient permissions');
}
```

### 6.3 Assistant-Level Permissions

Administrators can configure which tools are available per assistant:

```
Assistant A:
├── Enabled Tools: [post_create, post_list, upload_file]
└── Disabled Tools: [all others]

Assistant B:
├── Enabled Tools: [all wordpress tools]
└── Disabled Tools: [woocommerce, jetengine]
```

### 6.4 API Rate Limiting

**Per User/API Key:**
- Default: 100 requests per hour
- Configurable in settings
- Burst allowance: 20 requests per minute
- Tracked via transients

**Implementation:**
```php
$limit = wp_mcp_ai_check_rate_limit($user_id);
if (!$limit['allowed']) {
    return new WP_Error('rate_limit', 'Rate limit exceeded');
}
```

## 7. Access Control Monitoring

### 7.1 Audit Logging

**Logged Events:**
- Successful authentication
- Failed authentication attempts
- Permission changes
- Tool execution attempts
- API key generation/revocation
- Assistant creation/modification
- File uploads
- Configuration changes

**Log Format:**
```php
array(
    'timestamp' => '2026-01-05 19:00:00',
    'event_type' => 'authentication',
    'result' => 'success',
    'user_id' => 1,
    'user_login' => 'admin',
    'ip_address' => '192.168.1.1',
    'user_agent' => 'Mozilla/5.0...',
    'details' => 'User logged in successfully'
)
```

**Log Retention:** 12 months minimum

### 7.2 Failed Access Monitoring

**Thresholds:**
- 5 failed logins in 5 minutes: Account locked (15 minutes)
- 10 failed API requests: API key suspended
- Suspicious patterns: Administrator notification

**Actions:**
```php
if ($failed_attempts >= 5) {
    wp_mcp_ai_lock_account($user_id, 900); // 15 minutes
    wp_mcp_ai_notify_admin('security_alert', $details);
}
```

### 7.3 Anomaly Detection

**Monitored Patterns:**
- Login from unusual location
- Login at unusual time
- Excessive tool usage
- Unusual data access patterns
- Privilege escalation attempts

## 8. Access Reviews

### 8.1 Regular Reviews

**Schedule:**
- Monthly: Administrator access review
- Quarterly: All user access review
- Annually: Comprehensive access audit

**Review Process:**
1. Generate access report
2. Verify business need for each access
3. Identify excessive permissions
4. Remove unnecessary access
5. Document review results

### 8.2 Automated Reviews

**Triggers:**
- User inactive for 90 days
- Role change
- Plugin update affecting permissions
- Security incident

## 9. Special Access Scenarios

### 9.1 Emergency Access

**Break-Glass Procedure:**
1. Emergency access to locked accounts
2. Root security key verification required
3. Full audit trail maintained
4. Post-emergency review mandatory

**Implementation:**
```php
if (wp_mcp_ai_verify_root_security_key($provided_key)) {
    // Grant temporary elevated access
    wp_mcp_ai_log_break_glass_access($user_id);
    // Access expires after 1 hour
}
```

### 9.2 Service Accounts

**For Automated Processes:**
- Unique API keys
- Minimal required permissions
- No interactive login
- Regular rotation (90 days)
- Clear ownership and purpose

### 9.3 Third-Party Access

**For Integrations:**
- Scoped API keys
- Read-only by default
- Documented integration purpose
- Regular review of active integrations
- Ability to revoke instantly

## 10. Password Management

### 10.1 Password Requirements

**Strength:**
- Minimum length: 12 characters (WordPress default)
- Complexity: Uppercase, lowercase, numbers, symbols recommended
- No common passwords
- No personal information

**Implementation:**
```php
// WordPress handles password strength checking
// Additional validation can be added via filters
add_filter('wp_mcp_ai_password_requirements', function($requirements) {
    $requirements['min_length'] = 12;
    $requirements['require_special'] = true;
    return $requirements;
});
```

### 10.2 Password Storage

- bcrypt hashing (WordPress default)
- Salted and hashed
- Never stored in plain text
- Never transmitted in plain text (HTTPS only)

### 10.3 Password Reset

**Process:**
1. User requests reset via WordPress
2. Reset link sent to registered email
3. Link valid for 24 hours
4. One-time use only
5. New password must meet requirements
6. Audit log entry created

## 11. Session Management

### 11.1 Session Creation

**WordPress Sessions:**
```php
// Cookie-based sessions
// Session timeout: WordPress default (48 hours)
// "Remember Me": 14 days
```

**API Sessions:**
```php
// Token-based (JWT)
// Expiration: Configurable (default 1 hour)
// Refresh tokens: Optional
```

### 11.2 Session Termination

**Automatic:**
- Timeout after inactivity
- User logout
- Password change (all sessions terminated)
- Account locked/disabled

**Manual:**
- Administrator can terminate user sessions
- User can terminate other sessions

### 11.3 Concurrent Sessions

- Multiple sessions allowed by default
- Can be limited via policy
- Each session tracked independently

## 12. API Access Control

### 12.1 REST API Endpoints

**Authentication Required:**
```
POST /wp-json/mcp-ai/v1/chat
GET  /wp-json/mcp-ai/v1/assistants
POST /wp-json/mcp-ai/v1/tools/execute
```

**Authentication Methods:**
1. WordPress nonce (same-origin)
2. API key (Bearer token)
3. JWT token
4. Guest token (limited scope)

### 12.2 CORS Configuration

**Default Policy:**
```php
// Same-origin by default
// Can be configured for specific origins
Access-Control-Allow-Origin: https://trusted-domain.com
Access-Control-Allow-Methods: GET, POST
Access-Control-Allow-Credentials: true
```

### 12.3 API Rate Limiting

**Tiered Limits:**
- Guest users: 20 requests/hour
- Authenticated users: 100 requests/hour
- API keys: 1000 requests/hour
- Administrators: 5000 requests/hour

## 13. Mobile and Remote Access

### 13.1 Remote Access Requirements

- VPN not required (WordPress over HTTPS)
- Strong authentication required
- Device security recommendations provided
- Session timeout applies

### 13.2 Mobile Device Access

- Responsive web interface
- API access via mobile apps
- Same authentication requirements
- Device-specific rate limits optional

## 14. Access Violations

### 14.1 Violation Types

**Minor:**
- Single failed login attempt
- Accessing unauthorized but non-sensitive content
- Minor policy deviation

**Major:**
- Multiple failed login attempts
- Attempted privilege escalation
- Accessing sensitive data without authorization
- Sharing credentials

**Critical:**
- Successful unauthorized access
- Data exfiltration
- System compromise
- Credential theft

### 14.2 Response Procedures

**Automatic:**
- Account lockout
- API key suspension
- Administrator notification
- Audit log entry

**Manual:**
- Investigation
- User notification
- Access revocation
- Incident report
- Disciplinary action (if applicable)

## 15. Compliance and Reporting

### 15.1 Compliance Requirements

- GDPR: Right to access, right to erasure
- ISO 27001: Access control (A.5.15-A.5.18, A.8.2-A.8.5)
- WordPress.org: Plugin security guidelines

### 15.2 Access Reports

**Available Reports:**
- Current user access matrix
- Recent access changes
- Failed authentication attempts
- API key usage statistics
- Guest token usage
- Tool execution by user

### 15.3 Evidence Collection

For audits and compliance:
- Access control configurations
- Audit logs (authentication, authorization)
- Access review documentation
- Incident reports related to access

## 16. Roles and Responsibilities

### 16.1 Administrators

- Grant and revoke access
- Configure access controls
- Review access regularly
- Respond to access violations
- Maintain access documentation

### 16.2 Users

- Protect credentials
- Report suspicious activity
- Comply with access policies
- Request access changes through proper channels
- Log out when finished

### 16.3 Security Team

- Monitor access patterns
- Investigate violations
- Update access controls
- Conduct access reviews
- Provide access control guidance

## 17. Technical Implementation

### 17.1 Code Examples

**Capability Check:**
```php
function wp_mcp_ai_check_tool_access($tool_slug, $user_id = null) {
    $user_id = $user_id ?: get_current_user_id();
    $tool = wp_mcp_ai_get_tool($tool_slug);
    
    if (!$tool) {
        return false;
    }
    
    $required_cap = $tool->get_required_capability();
    return user_can($user_id, $required_cap);
}
```

**Rate Limit Check:**
```php
function wp_mcp_ai_check_rate_limit($identifier, $limit = 100, $window = 3600) {
    $key = 'rate_limit_' . md5($identifier);
    $requests = get_transient($key);
    
    if (false === $requests) {
        $requests = 0;
    }
    
    if ($requests >= $limit) {
        return array('allowed' => false, 'remaining' => 0);
    }
    
    $requests++;
    set_transient($key, $requests, $window);
    
    return array('allowed' => true, 'remaining' => $limit - $requests);
}
```

### 17.2 Database Schema

**Access Log Table:**
```sql
CREATE TABLE {$wpdb->prefix}nvoos_access_log (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    timestamp datetime DEFAULT CURRENT_TIMESTAMP,
    user_id bigint(20),
    event_type varchar(50),
    result varchar(20),
    ip_address varchar(45),
    user_agent text,
    resource varchar(255),
    details text,
    PRIMARY KEY (id),
    KEY user_id (user_id),
    KEY event_type (event_type),
    KEY timestamp (timestamp)
);
```

## 18. References

- [ISMS Policy](../ISMS-Policy.md)
- [Statement of Applicability](../Statement-of-Applicability.md)
- [WordPress Capabilities Documentation](https://developer.wordpress.org/plugins/users/roles-and-capabilities/)
- [Authentication Documentation](../../reference/api/authentication.md)

## 19. Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-05 | GitHub Copilot | Initial access control procedure |

---

**Next Review:** 2026-04-05 (Quarterly)
