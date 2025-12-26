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
- [WP oOS Documentation](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)

## Contact

For security concerns, please contact:
- **Email:** security@nvdigitalsolutions.com
- **Website:** https://nvdigitalsolutions.com

---

**Last Updated:** November 2, 2024
