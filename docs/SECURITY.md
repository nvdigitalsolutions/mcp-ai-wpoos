# Security Policy

## Supported Versions

We release patches for security vulnerabilities for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

We take the security of oOS seriously. If you believe you have found a security vulnerability, please report it to us as described below.

### Please Do Not

- Do not open a public GitHub issue for security vulnerabilities
- Do not disclose the vulnerability publicly until it has been addressed
- Do not exploit the vulnerability beyond what is necessary to demonstrate it

### Please Do

**Report security bugs by emailing:** security@nvdigitalsolutions.com

Please include the following information in your report:

- Type of issue (e.g., XSS, SQL injection, authentication bypass)
- Full paths of source file(s) related to the manifestation of the issue
- The location of the affected source code (tag/branch/commit or direct URL)
- Any special configuration required to reproduce the issue
- Step-by-step instructions to reproduce the issue
- Proof-of-concept or exploit code (if possible)
- Impact of the issue, including how an attacker might exploit it

### What to Expect

- **Acknowledgment:** You should receive an acknowledgment of your report within 48 hours
- **Status Updates:** We will send you regular updates about our progress
- **Disclosure Timeline:** We aim to patch critical vulnerabilities within 7 days and moderate vulnerabilities within 30 days
- **Credit:** We will credit you in the security advisory (unless you prefer to remain anonymous)

## Security Best Practices for Users

### API Key Management

1. **Never commit API keys to version control**
   - Use environment variables or WordPress constants
   - Add `.env` files to `.gitignore`

2. **Rotate keys regularly**
   - Change OpenAI API keys every 90 days
   - Immediately rotate keys if compromised

3. **Use environment variables**
   ```php
   // wp-config.php
   define( 'WP_MCP_AI_OPENAI_API_KEY', getenv('OPENAI_API_KEY') );
   ```

### Access Control

1. **Limit user capabilities**
   - Only grant `edit_posts` capability to trusted users
   - Use the `wp_mcp_ai_chat_capability` filter to restrict access

2. **Implement proper authentication**
   - Use Auth0 or JWT for API access
   - Enable nonce verification for all AJAX requests

3. **Review assistant permissions**
   - Regularly audit which tools are enabled for each assistant
   - Disable unused tools

### File Upload Security

1. **Validate file types**
   - Plugin validates MIME types, but add additional server-side validation
   - Limit file sizes appropriately

2. **Scan uploaded files**
   - Consider integrating virus scanning for uploaded attachments
   - Implement content security policies

### Network Security

1. **Use HTTPS**
   - Always use SSL/TLS for production sites
   - Enable `FORCE_SSL_ADMIN` in wp-config.php

2. **Firewall configuration**
   - Whitelist OpenAI API endpoints
   - Rate limit REST API endpoints

3. **Content Security Policy**
   - Implement CSP headers to prevent XSS
   - Restrict iframe embedding

### Database Security

1. **Use prepared statements**
   - Plugin uses `$wpdb->prepare()` - verify in custom extensions

2. **Limit database user permissions**
   - Database user should have minimal required permissions

### WordPress Security

1. **Keep WordPress updated**
   - Update to latest WordPress version
   - Update all plugins and themes

2. **Use security plugins**
   - Consider Wordfence or Sucuri
   - Enable two-factor authentication

3. **Regular backups**
   - Backup database and files regularly
   - Test restoration procedures

## Known Security Considerations

### API Rate Limiting

The plugin does not implement rate limiting by default. For production environments, we recommend:

1. Using a reverse proxy (Cloudflare, etc.) for rate limiting
2. Implementing custom rate limiting using transients
3. Monitoring API usage through the built-in usage tracker

### Data Privacy

1. **Chat transcripts** are stored in WordPress database
   - Enable transcript recording only if needed
   - Implement data retention policies
   - Comply with GDPR/privacy regulations

2. **OpenAI API** processes user messages
   - Review OpenAI's data usage policies
   - Inform users about AI processing
   - Consider data residency requirements

3. **File attachments** are stored in WordPress Media Library
   - Ensure proper access controls
   - Consider encryption for sensitive files
   - Implement file retention policies

### Authentication Methods

The plugin supports multiple authentication methods:

1. **WordPress Authentication** (Default)
   - Uses WordPress user sessions
   - Requires logged-in users

2. **JWT Authentication**
   - Requires Simple JWT Login plugin
   - Token-based authentication for API access

3. **Auth0 Integration**
   - OAuth 2.0 authentication
   - Recommended for enterprise deployments

4. **Guest Tokens**
   - Limited access tokens for public chat
   - Should be used with caution

Choose the authentication method appropriate for your security requirements.

## Threat Model

### Security Boundaries

The plugin defines the following trust boundaries:

1. **WordPress Admin Area**
   - **Trust Level:** Trusted
   - **Access Control:** WordPress capabilities (manage_options, edit_posts)
   - **Data Flow:** Admin → Plugin → AI Providers → Admin

2. **Public REST API Endpoints**
   - **Trust Level:** Untrusted
   - **Access Control:** Authentication required (except CORS preflight and Federation Directory)
   - **Data Flow:** External → Plugin → (Authentication) → Internal Systems

3. **Federation Directory**
   - **Trust Level:** Public
   - **Access Control:** Rate-limited public access (60 requests/minute)
   - **Data Flow:** External → Plugin → Peer Metadata (non-sensitive)

4. **AI Provider APIs**
   - **Trust Level:** Semi-trusted
   - **Access Control:** API keys (encrypted storage)
   - **Data Flow:** Plugin → AI Provider → Plugin

### Attack Vectors

#### 1. API Enumeration

**Threat:** Attacker enumerates federation peers via `/ai-dir/v1/peers` endpoint.

**Likelihood:** Medium  
**Impact:** Low (intentional disclosure of peer metadata)  
**Mitigation:**
- Rate limiting (60 req/min) - **Implemented v1.1.1**
- Only non-sensitive peer metadata exposed
- Health monitoring alerts on unusual access patterns
- Admin bypass for legitimate operations

#### 2. Rate Limit Bypass

**Threat:** Attacker bypasses rate limiting via IP rotation or proxies.

**Likelihood:** Medium  
**Impact:** Medium (excessive resource usage)  
**Mitigation:**
- IP-based rate limiting with transients
- Cloudflare or WAF for additional protection (recommended)
- Monitoring and alerting for unusual patterns
- Rate limit headers (X-RateLimit-*) for transparency

#### 3. Credential Theft

**Threat:** Attacker gains access to encrypted API keys in database.

**Likelihood:** Low  
**Impact:** High (compromise of AI provider accounts)  
**Mitigation:**
- AES-256-CBC encryption with random IVs
- Master key rotation capability
- WordPress database security best practices
- Monitor for unauthorized API usage
- Regular key rotation recommended

#### 4. SSE Connection Exhaustion

**Threat:** Attacker opens many SSE connections to exhaust server resources.

**Likelihood:** Medium  
**Impact:** High (denial of service)  
**Mitigation:**
- Rate limiting on SSE endpoint
- 5-minute maximum connection duration
- Authentication required for SSE connections
- Per-user connection limits (planned v1.2.0)
- Server resource monitoring

#### 5. Tool Execution Abuse

**Threat:** Authorized user executes tools maliciously (e.g., mass deletion).

**Likelihood:** Low  
**Impact:** High (data loss)  
**Mitigation:**
- Tool-level capability checks
- Action confirmation for destructive operations
- Audit logging of all tool executions
- Tool result validation
- Principle of least privilege

#### 6. Cross-Site Scripting (XSS)

**Threat:** Attacker injects malicious scripts via user input.

**Likelihood:** Low  
**Impact:** High (session hijacking, data theft)  
**Mitigation:**
- Input sanitization (sanitize_text_field, wp_kses_post)
- Output escaping (esc_html, esc_url, esc_attr)
- Content Security Policy headers recommended
- Nonce validation on all forms
- Double-escaping for user-generated content

#### 7. SQL Injection

**Threat:** Attacker injects SQL via unsanitized input.

**Likelihood:** Very Low  
**Impact:** Critical (database compromise)  
**Mitigation:**
- Parameterized queries ($wpdb->prepare)
- Input validation and type checking
- WordPress database abstraction layer
- No direct SQL execution
- Regular security audits

### Data Classification

| Data Type | Classification | Storage | Encryption | Access Control |
|-----------|---------------|---------|------------|----------------|
| API Keys | **Critical** | Database | ✅ AES-256-CBC | Admin only |
| User Messages | **Sensitive** | Database/localStorage | ❌ (encrypted in transit) | User + Admin |
| Chat Transcripts | **Sensitive** | Database | ❌ (encrypted in transit) | User + Admin |
| Peer Metadata | **Public** | Database | ❌ | Public (rate-limited) |
| Tool Configurations | **Internal** | Database | ❌ | Admin only |
| User Credentials | **Critical** | WordPress | ✅ bcrypt | WordPress core |

### Security Assumptions

The plugin assumes:

1. **WordPress Core is secure** - Up-to-date installation with latest security patches
2. **Server is hardened** - HTTPS enabled, proper file permissions (644/755)
3. **Database is secured** - Strong passwords, restricted network access
4. **AI Providers are trustworthy** - OpenAI, Google Gemini, Anthropic, etc.
5. **Admins are trusted** - manage_options capability holders are vetted
6. **Network is secure** - SSL/TLS for all external communications

### Out of Scope

The following are **not** protected by the plugin:

1. **WordPress Core vulnerabilities** - User must keep WordPress updated
2. **Server-level attacks** - DDoS, infrastructure compromise, OS vulnerabilities
3. **Client-side attacks** - Compromised browser, malware, keyloggers
4. **Social engineering** - Phishing, credential theft via deception
5. **Physical access** - Server room access, disk theft, memory dumps
6. **Third-party plugin vulnerabilities** - JetEngine, WooCommerce, Elementor security

## Incident Response

### Security Incident Classification

| Severity | Examples | Response Time |
|----------|----------|---------------|
| **Critical** | API key theft, database breach, SQL injection | < 1 hour |
| **High** | XSS/CSRF vulnerabilities, authentication bypass | < 4 hours |
| **Medium** | Rate limit bypass, information disclosure | < 24 hours |
| **Low** | Minor configuration issues, documentation gaps | < 7 days |

### Incident Response Steps

1. **Detect** - Monitor logs, user reports, automated scanning, rate limit violations
2. **Contain** - Disable affected features, rotate credentials, block malicious IPs
3. **Investigate** - Determine scope, identify root cause, assess data exposure
4. **Remediate** - Deploy fix, verify resolution, update affected users
5. **Document** - Create incident report, update procedures, share lessons learned
6. **Communicate** - Notify affected users if necessary (GDPR/privacy laws)

### Security Monitoring

**Recommended monitoring:**

- **API Usage Patterns** - Unusual spikes or geographic distribution changes
- **Failed Authentication Attempts** - Potential brute force attacks (>10/min)
- **Rate Limit Violations** - Repeated 429 responses from same IPs (>5/min)
- **Error Rates** - Increased 4xx/5xx responses (>1% of requests)
- **Tool Execution Patterns** - Unusual tool usage or repeated failures
- **Database Performance** - Slow queries (>1s), excessive connections
- **SSE Connections** - Unusual connection counts or long-lived connections

### Security Contact

Report security vulnerabilities to: **security@nvdigitalsolutions.com**

Include:
- Vulnerability description and severity assessment
- Steps to reproduce with proof-of-concept
- Affected versions and configurations
- Suggested mitigation if available

Expected response times:
- Initial acknowledgment: 48 hours
- Severity assessment: 72 hours
- Fix timeline: Critical (7 days), High (14 days), Medium (30 days)

## Security Checklist for Production Deployment

- [ ] SSL/TLS certificate installed and configured
- [ ] API keys stored in environment variables (not database)
- [ ] WordPress and all plugins updated to latest versions
- [ ] Strong admin passwords enforced
- [ ] Two-factor authentication enabled for admins
- [ ] File upload limits configured
- [ ] Rate limiting implemented
- [ ] Database backups automated
- [ ] Security monitoring enabled
- [ ] Access logs reviewed regularly
- [ ] Unused tools and features disabled
- [ ] Content Security Policy headers configured
- [ ] CORS policy configured restrictively
- [ ] Error reporting disabled in production
- [ ] Debug mode disabled (`WP_DEBUG = false`)

## Security Audit History

| Date | Version | Auditor | Findings | Status |
|------|---------|---------|----------|--------|
| 2024-11-02 | 1.0.0 | Internal | Code review completed | Ongoing |

## Additional Resources

- [WordPress Security Handbook](https://developer.wordpress.org/apis/security/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [OpenAI Security Best Practices](https://platform.openai.com/docs/guides/safety-best-practices)
- [NV oOS Documentation](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)

## Contact

For security concerns, please contact:
- **Email:** security@nvdigitalsolutions.com
- **Website:** https://nvdigitalsolutions.com

---

**Last Updated:** November 2, 2024
