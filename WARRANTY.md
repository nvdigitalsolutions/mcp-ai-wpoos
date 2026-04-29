# Warranty, Liability, and Safe Use Notice

**NV Digital Open Operator System (NV oOS)**  
Maintained by [NV Digital Solutions](https://nvdigitalsolutions.com)  
Copyright © 2025–2026 NV Digital Solutions. All rights reserved.

---

## Our Security Commitment

NV Digital Solutions is committed to keeping NV oOS as safe and secure as possible. Our practices include:

- **Continuous security auditing** — formal quarterly code reviews with published findings (see [`docs/compliance/`](docs/compliance/))
- **WordPress Coding Standards enforcement** — all code reviewed against WPCS and OWASP WordPress Security Guidelines
- **Input sanitization and output escaping** — every REST endpoint and tool validates and sanitizes untrusted input
- **Capability-based access control** — every tool and API endpoint checks `current_user_can()` before execution
- **Rate limiting and abuse detection** — built-in protection with configurable limits per user, model, and time period
- **Nefarious usage monitoring** — real-time detection of suspicious patterns with automatic emergency shutdown
- **Responsible disclosure program** — security researchers can report issues privately at [security@nvdigitalsolutions.com](mailto:security@nvdigitalsolutions.com) (see [`SECURITY.md`](SECURITY.md))

Despite these efforts, **no software system can be guaranteed to be 100% secure or free of defects at all times.**

---

## No Warranty — "As Is" Disclaimer

> This notice supplements, but does not replace, the warranty disclaimer in the [GNU General Public License v3](LICENSE) under which the base plugin is distributed.

**THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT.**

**IN NO EVENT SHALL NV DIGITAL SOLUTIONS, ITS CONTRIBUTORS, OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING BUT NOT LIMITED TO PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; BUSINESS INTERRUPTION; OR LOSS OF CONTENT) ARISING FROM OR IN CONNECTION WITH THE SOFTWARE OR ITS USE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGES.**

This disclaimer applies to the extent permitted by applicable law in your jurisdiction.

---

## ⚠️ Destructive Operations Warning

**By design, NV oOS grants AI assistants access to powerful WordPress operations.** When improperly configured or used without safeguards, these capabilities can cause irreversible harm:

| Capability Area | Potential Risk |
|---|---|
| **Content management tools** | AI-initiated bulk deletion or modification of posts, pages, custom post types, media, and taxonomies |
| **User and role management tools** | Creation, modification, or deletion of user accounts and capability assignments |
| **File system tools** | Writing, overwriting, or deleting files accessible to the web server process |
| **Email and notification tools** | Bulk email dispatch to your user list; potential for unintended mass communication |
| **WP-CLI integration** | Execution of arbitrary WP-CLI commands (restricted by default; requires explicit enablement and `manage_options` capability) |
| **External API tools** | Triggering paid operations on third-party platforms (media generation, e-commerce, messaging) |
| **Database tools** | Direct SQL operations that can modify or destroy data if capability guards are bypassed |
| **Cron and scheduling tools** | Creation of recurring background tasks that run indefinitely |
| **Federation and A2A tools** | Outbound connections to remote AI agents that may themselves perform destructive actions |

**These risks exist by design.** An AI orchestration layer powerful enough to automate real work is also powerful enough to cause damage when misconfigured. NV Digital Solutions cannot be held responsible for data loss, service disruption, or financial charges resulting from the use of these tools.

### Mitigation Recommendations

1. **Always test on a staging environment** before enabling new tools or assistants on production.
2. **Take regular, verified backups** before activating any tool with write or delete capabilities. Never rely on a single backup strategy.
3. **Apply the principle of least privilege** — assign only the capabilities each assistant genuinely needs. Do not grant `administrator`-level capabilities to public-facing assistants.
4. **Restrict tool access** — use the Tool Permissions settings to disable tools that are not needed for a given assistant.
5. **Enable rate limiting** for all public-facing chat endpoints (`Settings → NV oOS → Rate Limiting`).
6. **Review system prompts carefully** — a poorly written system prompt can lead an AI to interpret benign user requests as instructions to delete or modify data.
7. **Monitor activity logs** — use the built-in logging and the Nefarious Usage Monitor to detect unexpected tool executions early.
8. **Use the Root Security Key** — configure `WP_MCP_AI_ROOT_SECURITY_KEY` in `wp-config.php` as an emergency authentication layer (see [Root Security Key docs](docs/root-security-key.md)).

---

## ⚡ Resource Consumption Warning

NV oOS makes calls to external AI APIs, generates media, runs background tasks, and processes streaming responses. When improperly configured or under heavy load, resource usage can escalate quickly:

| Resource | Risk |
|---|---|
| **AI API credits (OpenAI, Gemini, Anthropic, etc.)** | Unrestricted use can exhaust API quotas or incur unexpected billing charges |
| **Server CPU and memory** | Agentic loops, multi-agent orchestration, and concurrent SSE streams can saturate server resources on shared or low-spec hosting |
| **Database** | High-frequency tool executions or logging at maximum verbosity can generate large volumes of database writes |
| **Network bandwidth** | Media generation (images, video, audio) and web crawling can consume significant outbound and inbound bandwidth |
| **WordPress cron** | Background task scheduling can pile up if tasks are not completing (e.g., due to a slow AI provider response) |
| **Storage** | Generated media assets, transcript storage, and audit logs accumulate over time |

**NV Digital Solutions is not responsible for hosting charges, API overage fees, or performance degradation** resulting from plugin usage, including misconfiguration or unexpectedly high traffic.

### Resource Management Recommendations

1. **Set token budget limits** via the Agentic Loop Token Management settings to cap spending per request.
2. **Configure API key rate limits** at the provider level (OpenAI, Google Cloud, etc.) as a hard billing backstop.
3. **Monitor token usage** in `Settings → NV oOS → Token Usage Statistics`.
4. **Tune the agentic loop** — lower `wp_mcp_ai_max_agentic_iterations` on resource-constrained sites.
5. **Review cron task queue** regularly with `wp cron event list` to identify stuck or runaway scheduled tasks.
6. **Set log retention limits** to prevent unbounded growth of the activity log option.
7. **Test on adequate hardware** — shared hosting plans are generally unsuitable for production AI workloads. A VPS with at least 1 GB RAM and a dedicated PHP process manager is recommended.

---

## Configuration Responsibility

NV oOS ships with conservative defaults, but **it is the site owner's and developer's responsibility** to:

- Review and configure all settings before exposing the plugin to end users
- Understand the capability and scope of every tool enabled for each assistant
- Keep WordPress core, PHP, and all plugins (including NV oOS) updated to the latest supported versions
- Secure the WordPress installation itself (strong passwords, HTTPS, file permissions, no debug output in production)
- Comply with applicable data-protection laws (GDPR, CCPA, PIPEDA, etc.) when processing user data through AI providers
- Review each AI provider's Terms of Service and Privacy Policy before transmitting user data to their APIs

---

## Industry Standards and References

This notice is informed by the following industry standards and best practices:

| Standard / Resource | Relevance |
|---|---|
| [GNU General Public License v3, Sections 15–17](https://www.gnu.org/licenses/gpl-3.0.html#section15) | Formal warranty disclaimer for the base plugin's open-source distribution |
| [OWASP WordPress Security Implementation Guide](https://owasp.org/www-project-web-security-testing-guide/) | Secure coding and configuration baselines |
| [WordPress Plugin Developer Handbook — Security](https://developer.wordpress.org/plugins/security/) | WordPress-specific sanitization, escaping, and nonce standards |
| [NIST SP 800-53 — Software and Information Integrity](https://csrc.nist.gov/publications/detail/sp/800-53/rev-5/final) | Controls for integrity of software components and data |
| [ISO/IEC 27001:2022 — Information Security Management](https://www.iso.org/standard/27001) | Risk management framework reflected in the Pro Dashboard compliance tools |
| [OWASP ASVS 4.0 — Cryptography, Error Handling, Logging](https://owasp.org/www-project-application-security-verification-standard/) | Verification standards that inform NV oOS logging and error-handling design |
| [Common Vulnerability Scoring System (CVSS) v3.1](https://www.first.org/cvss/v3.1/specification-document) | Severity classification used in security audit reports |

---

## Contact and Reporting

- **Security vulnerabilities:** [security@nvdigitalsolutions.com](mailto:security@nvdigitalsolutions.com) — see [`SECURITY.md`](SECURITY.md) for the responsible disclosure process
- **General support:** [https://nvdigitalsolutions.com/wpoos](https://nvdigitalsolutions.com/wpoos)
- **Bug reports and feature requests:** [GitHub Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)

---

*This notice is maintained by NV Digital Solutions.*  
*Last updated: April 2026*
