# External Services Reference

This document provides a comprehensive list of all external services used by the Open Operator System (oOS) plugin, including their purpose, data transmission details, and links to Terms of Service and Privacy Policies.

**Last Updated:** January 2026  
**Plugin Version:** 1.1.0

---

## Table of Contents

1. [AI Provider Services](#ai-provider-services)
2. [Research & Data Services](#research--data-services)
3. [Infrastructure & CDN Services](#infrastructure--cdn-services)
4. [OAuth Integration Services](#oauth-integration-services)
5. [WordPress Core Services](#wordpress-core-services)
6. [Implementation Guidelines](#implementation-guidelines)

---

## AI Provider Services

These are the core AI services that power the plugin's assistant functionality. **At least one must be configured for the plugin to function.**

### 1. OpenAI API

**Service URL:** `https://api.openai.com`  
**Purpose:** Core AI functionality (chat, image generation, text-to-speech, embeddings, video generation with Sora)  
**Data Sent:** 
- Chat messages and conversation history
- System prompts and assistant instructions
- File attachments (images, documents, PDFs)
- Tool execution results
- Image generation prompts
- Audio transcription files

**When Used:**
- Every time an AI assistant is used with OpenAI as the provider
- Image generation tools (DALL-E)
- Speech generation tools (TTS)
- Video generation tools (Sora)
- Embedding creation for vector search

**Legal & Privacy:**
- **Terms of Service:** https://openai.com/policies/terms-of-use
- **Privacy Policy:** https://openai.com/privacy
- **API Terms:** https://openai.com/policies/api-terms
- **Usage Policies:** https://openai.com/policies/usage-policies
- **Data Usage:** OpenAI does not use API data to train models (as of March 2023)
- **Data Retention:** API data retained for 30 days for abuse monitoring, then deleted

**Related Files:**
- `includes/class-wp-mcp-ai-openai-client.php`
- `includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php`
- `includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php`
- `includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php`

---

### 2. Google Gemini API

**Service URL:** `https://generativelanguage.googleapis.com`  
**Purpose:** Core AI functionality (chat, image generation with Imagen, embeddings, geospatial analysis)  
**Data Sent:**
- Chat messages and conversation history
- System prompts and instructions
- File attachments
- Tool execution results
- Image generation prompts
- Geospatial queries

**When Used:**
- Every time an AI assistant is used with Gemini as the provider
- Image generation tools (Imagen)
- Embedding creation
- Geospatial analysis tools

**Legal & Privacy:**
- **Terms of Service:** https://ai.google.dev/terms
- **Privacy Policy:** https://ai.google.dev/privacy
- **Google APIs Terms:** https://developers.google.com/terms
- **Data Usage:** Review Google's data retention and usage policies
- **Additional Info:** https://policies.google.com/technologies/partner-sites

**Related Files:**
- `includes/class-wp-mcp-ai-gemini-client.php`
- `includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php`
- `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`

---

### 3. Anthropic API (Claude)

**Service URL:** `https://api.anthropic.com/v1/messages`  
**Purpose:** Core AI functionality (chat, vision, document analysis)  
**Data Sent:**
- Chat messages and conversation history
- System prompts and instructions
- File attachments (images, documents)
- Tool execution results

**When Used:**
- Every time an AI assistant is used with Anthropic as the provider
- Vision and document analysis features

**Legal & Privacy:**
- **Terms of Service:** https://www.anthropic.com/legal/consumer-terms
- **Privacy Policy:** https://www.anthropic.com/legal/privacy
- **Commercial Terms:** https://www.anthropic.com/legal/commercial-terms
- **Usage Policy:** https://www.anthropic.com/legal/aup
- **Data Usage:** Anthropic does not train models on API data (Claude API)
- **Data Retention:** Review Anthropic's privacy policy for retention details

**Related Files:**
- `includes/class-wp-mcp-ai-anthropic-client.php`

---

### 4. Ollama (Self-Hosted)

**Service URL:** Your local server (typically `http://localhost:11434`)  
**Purpose:** Privacy-focused local AI processing  
**Data Sent:** None (runs entirely on your server)  
**When Used:** When configured as AI provider

**Legal & Privacy:**
- **No external data transmission**
- **Complete data privacy and control**
- **Recommended for sensitive data**
- **GitHub:** https://github.com/ollama/ollama
- **Documentation:** https://ollama.ai/

**Related Files:**
- `includes/class-wp-mcp-ai-ollama-client.php`

---

### 5. LM Studio (Self-Hosted)

**Service URL:** Your local computer (typically `http://localhost:1234`)  
**Purpose:** Local AI with function calling support  
**Data Sent:** None (runs entirely on your computer)  
**When Used:** When configured as AI provider

**Legal & Privacy:**
- **No external data transmission**
- **Complete data privacy and control**
- **Recommended for sensitive data**
- **Website:** https://lmstudio.ai/
- **Terms:** https://lmstudio.ai/terms

**Related Files:**
- `includes/class-wp-mcp-ai-language-model-router.php`

---

### 6. Cloudflare Workers AI

**Service URL:** `https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/run/{model}`  
**Purpose:** AI image generation and inference  
**Data Sent:**
- Image generation prompts
- Model inference requests

**When Used:** When using Cloudflare AI tools

**Legal & Privacy:**
- **Terms of Service:** https://www.cloudflare.com/terms/
- **Privacy Policy:** https://www.cloudflare.com/privacypolicy/
- **Workers AI Docs:** https://developers.cloudflare.com/workers-ai/
- **Data Usage:** Review Cloudflare's privacy policy for data handling details

**Related Files:**
- `includes/class-wp-mcp-ai-cloudflare-client.php`
- `includes/tools/class-wp-mcp-ai-tool-generate-cloudflareai-image.php`

---

## Research & Data Services

These services provide real-world data for AI assistants (weather, news, search, etc.).

### 7. Brave Search API

**Service URL:** `https://api.search.brave.com/res/v1/web/search`  
**Purpose:** Web search functionality for AI assistants  
**Data Sent:**
- Search queries provided by users or AI
- Search parameters (count, offset)
- Optional: User location for local results

**When Used:** When the web search tool is called by an assistant

**Legal & Privacy:**
- **Terms of Service:** https://brave.com/terms-of-use/
- **Privacy Policy:** https://brave.com/privacy/browser/
- **API Terms:** https://brave.com/search/api/
- **Search Privacy:** https://search.brave.com/help/privacy

**Related Files:**
- `includes/tools/class-wp-mcp-ai-tool-web-search.php`
- `includes/tools/class-wp-mcp-ai-tool-web-search-validated.php`

---

### 8. Open-Meteo Weather API

**Service URL:** `https://api.open-meteo.com`  
**Purpose:** Weather forecasts and historical weather data  
**Data Sent:**
- Location coordinates (latitude/longitude)
- City names (converted to coordinates)
- Date ranges for historical data

**When Used:** When weather tools are used

**Legal & Privacy:**
- **Terms of Service:** https://open-meteo.com/en/terms
- **Privacy Policy:** https://open-meteo.com/en/terms (includes privacy information)
- **License:** CC BY 4.0 for data
- **Data Sources:** Listed at https://open-meteo.com/en/docs

**Related Files:**
- `includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php`

---

### 9. ReliefWeb API (UN OCHA)

**Service URL:** `https://api.reliefweb.int/v1/reports`  
**Purpose:** Humanitarian disaster and emergency reports  
**Data Sent:**
- Search queries for disaster reports
- Filters (date, country, disaster type)

**When Used:** When ReliefWeb tools are used for humanitarian data

**Legal & Privacy:**
- **Terms of Service:** https://reliefweb.int/terms-conditions
- **Privacy Policy:** https://reliefweb.int/privacy-policy
- **API Documentation:** https://reliefweb.int/help/api
- **About ReliefWeb:** https://reliefweb.int/about

**Related Files:**
- `includes/tools/class-wp-mcp-ai-tool-reliefweb-reports.php`

---

### 10. Hugging Face Datasets API

**Service URL:** `https://huggingface.co/api/datasets`  
**Purpose:** Access to public machine learning datasets  
**Data Sent:**
- Dataset queries and filters
- Dataset preview requests

**When Used:** When dataset exploration tools are used

**Legal & Privacy:**
- **Terms of Service:** https://huggingface.co/terms-of-service
- **Privacy Policy:** https://huggingface.co/privacy
- **Dataset Licenses:** Varies by dataset (check individual dataset pages)
- **Data Usage:** Review Hugging Face's privacy policy for data handling details

**Related Files:**
- `includes/tools/class-wp-mcp-ai-tool-huggingface-dataset-filter.php`
- `includes/tools/class-wp-mcp-ai-tool-huggingface-dataset-is-valid.php`

---

## Infrastructure & CDN Services

These services provide libraries and assets used by the plugin.

### 11. jsDelivr CDN

**Service URL:** `https://cdn.jsdelivr.net`  
**Purpose:** Chart.js library for data visualization  
**Data Sent:** None (library loaded client-side in browser)  
**When Used:** When chart generation tools create visualizations

**Legal & Privacy:**
- **Terms of Service:** https://www.jsdelivr.com/terms
- **Privacy Policy:** https://www.jsdelivr.com/privacy-policy-jsdelivr-net
- **About jsDelivr:** https://www.jsdelivr.com/

**Specific Assets Used:**
- Chart.js: `https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js`

**Related Files:**
- `includes/tools/class-wp-mcp-ai-tool-create-chart.php`
- `includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php`

**Note:** Per WordPress.org guidelines, this will be migrated to a locally bundled version in the next update.

---

## OAuth Integration Services

These services are **only used if you explicitly configure OAuth integrations**. They are entirely optional.

### 12. GitHub API

**Service URL:** `https://api.github.com`  
**Purpose:** Repository management, code search, issue tracking, pull requests  
**Data Sent:**
- OAuth access tokens
- Repository queries
- Commit data
- Issue/PR content
- Code search queries

**When Used:** When GitHub tools are used after OAuth setup

**Legal & Privacy:**
- **Terms of Service:** https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
- **Privacy Policy:** https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement
- **API Terms:** https://docs.github.com/en/site-policy/github-terms/github-terms-for-additional-products-and-features
- **OAuth Apps:** https://docs.github.com/en/developers/apps/getting-started-with-apps/about-apps

**Related Files:**
- `includes/integrations/class-wp-mcp-ai-github-client.php`
- `includes/integrations/class-wp-mcp-ai-github-oauth-handler.php`

---

### 13. Cloudways API

**Service URL:** `https://api.cloudways.com/api/v1`  
**Purpose:** Server management for Cloudways hosting customers  
**Data Sent:**
- OAuth access tokens
- Server management commands
- Application data

**When Used:** When Cloudways tools are used after OAuth setup

**Legal & Privacy:**
- **Terms of Service:** https://www.cloudways.com/en/terms-of-service.php
- **Privacy Policy:** https://www.cloudways.com/en/privacy-policy.php
- **API Documentation:** https://developers.cloudways.com/docs/

**Related Files:**
- `includes/integrations/class-wp-mcp-ai-cloudways-oauth-handler.php`

---

### 14. QuickBooks API (Intuit)

**Service URL:** `https://appcenter.intuit.com/connect/oauth2`  
**Purpose:** Accounting and financial data integration  
**Data Sent:**
- OAuth tokens
- Financial queries
- Transaction data

**When Used:** When QuickBooks tools are used after OAuth setup

**Legal & Privacy:**
- **Terms of Service:** https://accounts.intuit.com/terms-of-service
- **Privacy Policy:** https://www.intuit.com/privacy/statement/
- **Developer Terms:** https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-2.0

**Related Files:**
- `includes/integrations/class-wp-mcp-ai-quickbooks-oauth-handler.php`

---

### 15. Mailjet API

**Service URL:** `https://app.mailjet.com/oauth/authorize`  
**Purpose:** Email marketing and transactional email  
**Data Sent:**
- OAuth tokens
- Email campaign data
- Contact lists
- Email templates

**When Used:** When Mailjet tools are used after OAuth setup

**Legal & Privacy:**
- **Terms of Service:** https://www.mailjet.com/legal/terms-of-use/
- **Privacy Policy:** https://www.mailjet.com/privacy-policy/
- **API Terms:** https://www.mailjet.com/legal/api-terms-of-use/

**Related Files:**
- `includes/integrations/class-wp-mcp-ai-mailjet-oauth-handler.php`

---

### 16. Flowhub API

**Service URL:** `https://api.flowhub.com` (if applicable)  
**Purpose:** Cannabis retail inventory management  
**Data Sent:**
- API credentials
- Inventory queries
- Product data

**When Used:** When Flowhub inventory tools are used

**Legal & Privacy:**
- **Website:** https://flowhub.com/
- **Privacy Policy:** https://flowhub.com/privacy-policy/
- **Terms of Service:** https://flowhub.com/terms-of-service/

**Related Files:**
- `includes/tools/class-wp-mcp-ai-tool-flowhub-get-inventory.php`

---

## WordPress Core Services

These are official WordPress.org services used for plugin functionality.

### 17. WordPress.org Core API

**Service URL:** `https://api.wordpress.org/core/serve-happy/1.0/`  
**Purpose:** PHP version compatibility check for site health  
**Data Sent:**
- Current PHP version number

**When Used:** When site health tools are called

**Legal & Privacy:**
- **Privacy Policy:** https://wordpress.org/about/privacy/
- **Terms of Use:** https://wordpress.org/about/privacy/ (includes terms)
- **About WordPress.org:** https://wordpress.org/about/

**Related Files:**
- `includes/tools/class-wp-mcp-ai-tool-get-site-health.php`

---

## Implementation Guidelines

### For Plugin Developers

When adding a new external service integration:

1. **Add Entry to This Document**
   - Service name, URL, purpose
   - Data sent and when
   - Terms of Service and Privacy Policy links
   - Related files

2. **Update readme.txt**
   - Add to "External Services" section
   - Include brief description and legal links

3. **Document in Code**
   ```php
   /**
    * Connects to Example Service API
    * 
    * @link https://example.com/api
    * @link https://example.com/terms Terms of Service
    * @link https://example.com/privacy Privacy Policy
    * 
    * Data sent: User queries, authentication tokens
    * When: Only when user explicitly uses this feature
    */
   ```

4. **User Consent**
   - Ensure users are informed before data is sent
   - Provide opt-in/opt-out mechanisms where applicable
   - Document in settings UI

### For Site Administrators

**Before Enabling Any Integration:**

1. Review the service's Terms of Service and Privacy Policy
2. Update your site's privacy policy to inform users
3. Obtain user consent if processing personal data
4. Consider data residency requirements (GDPR, CCPA, etc.)
5. Review data processing agreements (DPAs) if required

**For Maximum Privacy:**

- Use self-hosted AI (Ollama or LM Studio)
- Disable optional integrations you don't need
- Review and limit tool availability
- Enable minimal logging
- Regular audit of active integrations

### Compliance Checklist

- [ ] All external services documented in this file
- [ ] All services listed in readme.txt with legal links
- [ ] Each integration file has docblock with service info
- [ ] User consent mechanisms in place
- [ ] Privacy policy template provided
- [ ] Data retention policies documented
- [ ] GDPR/CCPA compliance reviewed
- [ ] Optional vs required services clearly marked

---

## Service Status & Updates

| Service | Status | Last Verified | Notes |
|---------|--------|---------------|-------|
| OpenAI API | Active | 2026-02 | Stable |
| Google Gemini | Active | 2026-02 | Stable |
| Anthropic (Claude) | Active | 2026-02 | Stable |
| Ollama | Active | 2026-02 | Self-hosted |
| LM Studio | Active | 2026-02 | Self-hosted |
| Cloudflare Workers AI | Active | 2026-02 | Image generation |
| Brave Search | Active | 2026-02 | Requires API key |
| Open-Meteo | Active | 2026-02 | Free tier available |
| ReliefWeb | Active | 2026-02 | Public API |
| Hugging Face | Active | 2026-02 | Dataset access |
| Chart.js CDN | Active | 2026-02 | To be replaced with local |
| GitHub API | Active | 2026-02 | OAuth required |
| Cloudways | Active | 2026-02 | OAuth required |
| QuickBooks | Active | 2026-02 | OAuth required |
| Mailjet | Active | 2026-02 | OAuth required |
| Flowhub | Active | 2026-02 | Cannabis retail |
| WordPress.org | Active | 2026-02 | Core service |

---

## Legal Disclaimer

This documentation is provided for informational purposes only. Service terms, privacy policies, and URLs may change without notice. Always verify the current terms and policies directly with each service provider before integration.

The Open Operator System plugin developers are not responsible for:
- Changes to third-party service terms
- Data handling practices of third-party services
- Service availability or reliability
- Compliance with specific regulatory requirements (GDPR, CCPA, HIPAA, etc.)

Site administrators are responsible for:
- Reviewing and accepting third-party terms
- Ensuring compliance with applicable regulations
- Informing users about data processing
- Obtaining necessary consents
- Maintaining up-to-date privacy policies

---

## Plugin Analytics Service

### NV Digital Plugin Tracking

**Service URL:** `https://nvdigitalsolutions.com/api/plugin-tracking/activation`  
**Purpose:** Anonymous plugin activation/deactivation tracking to understand plugin usage patterns  
**Data Sent:**
- Plugin variant (complete, base, pro, or core)
- Plugin version number
- WordPress version
- PHP version
- Site locale (language)
- Multisite status (true/false)
- Hashed site identifier (non-reversible SHA-256 hash)
- Timestamp

**When Used:**
- Once when the plugin is activated
- Once when the plugin is deactivated

**Privacy Features:**
- **No PII collected**: No personally identifiable information is transmitted
- **Site URL hashed**: Site URL is converted to a non-reversible hash using SHA-256 with WordPress salts
- **No IP storage**: IP addresses are not logged or stored
- **Local development excluded**: Tracking automatically disabled for localhost and common dev domains
- **Opt-out available**: Can be disabled via settings or filter hook
- **Non-blocking**: Uses asynchronous HTTP requests with short timeout
- **Silent failure**: Tracking failures don't disrupt plugin operation
- **Fully transparent**: All tracking code is open source and documented

**How to Disable:**
1. **Via Settings**: Go to Settings → NV oOS → General → Log Management → Disable activation tracking
2. **Via Filter Hook**:
   ```php
   add_filter( 'wp_mcp_ai_enable_usage_tracking', '__return_false' );
   ```

**Legal & Privacy:**
- **GDPR Compliant**: No personal data collected
- **Privacy Policy**: https://nvdigitalsolutions.com/privacy
- **Terms of Service**: https://nvdigitalsolutions.com/terms
- **Data Retention**: Aggregated statistics only, no individual site data stored long-term

**Related Files:**
- `includes/class-wp-mcp-ai-activation-tracker.php`

**Purpose & Rationale:**
This minimal tracking helps NV Digital understand:
- Which plugin variants (complete, base, pro, core) are most popular
- PHP and WordPress version distributions for compatibility planning
- Geographic distribution of users (via locale) for translation priorities
- General adoption and retention metrics

All data is used solely for product improvement and never sold or shared with third parties.

---

## Contact & Updates

For updates to this document or to report outdated service information:

- **GitHub Issues:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Documentation:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs

**Maintenance Schedule:** This document should be reviewed quarterly and updated whenever:
- New external services are added
- Service URLs or legal links change
- Terms of Service or Privacy Policies are updated by providers
- WordPress.org plugin review requirements change
