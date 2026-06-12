# External Services Reference

This document provides a comprehensive list of all external services used by the Open Operator System (oOS) plugin, including their purpose, data transmission details, and links to Terms of Service and Privacy Policies.

**Last Updated:** May 2026  
**Plugin Version:** 1.1.19

---

## Table of Contents

1. [AI Provider Services](#ai-provider-services)
2. [Research & Data Services](#research--data-services)
3. [Infrastructure & CDN Services](#infrastructure--cdn-services)
4. [OAuth Integration Services](#oauth-integration-services)
5. [Chat Channel Services](#chat-channel-services)
6. [Remote Connection Services](#remote-connection-services)
7. [WordPress Core Services](#wordpress-core-services)
8. [Implementation Guidelines](#implementation-guidelines)

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
- **Terms:** Self-hosted software — see https://github.com/lmstudio-ai

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

### 6a. NVIDIA NIM API

**Service URL:** `https://integrate.api.nvidia.com/v1` (default; supports custom/self-hosted NIM endpoints)  
**Purpose:** Cloud AI inference via NVIDIA's optimized model platform (Llama, Mistral, Nemotron, Gemma, and 40+ models)  
**Data Sent:**
- Chat messages and system prompts
- Tool execution results
- Model inference parameters

**When Used:** Every time an AI assistant is used with NVIDIA NIM as the provider

**Legal & Privacy:**
- **NVIDIA AI Enterprise EULA:** https://www.nvidia.com/en-us/data-center/products/nvidia-ai-enterprise/eula/
- **Privacy Policy:** https://www.nvidia.com/en-us/about-nvidia/privacy-policy/
- **NIM Documentation:** https://build.nvidia.com/
- **Data Usage:** Review NVIDIA's privacy policy for data handling details; self-hosted NIM containers keep data on-premises

**Related Files:**
- `includes/class-wp-mcp-ai-nvidia-client.php`
- `includes/infrastructure/providers/class-wp-mcp-ai-nvidia-provider-client.php`

---

### 6b. DeepSeek API

**Service URL:** `https://api.deepseek.com` (default; supports custom base URL for proxies or regional endpoints)  
**Purpose:** Cloud AI inference via DeepSeek's OpenAI-compatible API (deepseek-chat, deepseek-reasoner, deepseek-coder)  
**Data Sent:**
- Chat messages and conversation history
- System prompts and instructions
- Tool definitions and tool execution results
- Reasoning content passthrough (deepseek-reasoner)

**When Used:** Every time an AI assistant is used with DeepSeek as the provider

**Legal & Privacy:**
- **Terms of Service:** https://platform.deepseek.com/terms
- **Privacy Policy:** https://platform.deepseek.com/privacy
- **Data Usage:** See DeepSeek's privacy policy for data handling details

**Related Files:**
- `includes/class-wp-mcp-ai-deepseek-client.php`

---

### 6c. OpenRouter API

**Service URL:** `https://openrouter.ai/api/v1`  
**Purpose:** Unified OpenAI-compatible gateway routing to 200+ models from OpenAI, Anthropic, Google, Meta, Mistral, and more via a single API key  
**Data Sent:**
- Chat messages and conversation history
- System prompts and instructions
- Tool definitions and tool execution results

**When Used:** Every time an AI assistant is used with OpenRouter as the provider

**Legal & Privacy:**
- **Terms of Service:** https://openrouter.ai/terms
- **Privacy Policy:** https://openrouter.ai/privacy
- **Documentation:** https://openrouter.ai/docs
- **Data Usage:** Requests are routed to upstream provider APIs; review both OpenRouter and the upstream provider's policies

**Related Files:**
- `includes/class-wp-mcp-ai-openrouter-client.php`

---

### 6d. Kimi (Moonshot AI) API

**Service URL:** `https://api.moonshot.cn/v1` (default; supports custom base URL for proxies)  
**Purpose:** Cloud AI inference via Moonshot AI's OpenAI-compatible API supporting Kimi K2.6, K2.5, K2, K2-Thinking (chain-of-thought), and legacy moonshot-v1 model families with up to 256K context windows  
**Data Sent:**
- Chat messages and conversation history
- System prompts and instructions
- Tool definitions and tool execution results (stripped automatically for reasoning models that do not support tool calling)
- Token estimation requests (messages only, for pre-request token counting)

**When Used:**
- Every time an AI assistant is used with Kimi as the provider
- Token estimation endpoint called before each request when configured

**Legal & Privacy:**
- **Terms of Service:** https://platform.moonshot.cn/docs/policy/service-agreement
- **Privacy Policy:** https://platform.moonshot.cn/docs/policy/privacy-policy
- **API Documentation:** https://platform.moonshot.cn/docs/api-reference
- **Data Usage:** See Moonshot AI's privacy policy for data handling details

**Supported Models:**
- `kimi-k2.6` — 256K context, multimodal, tool calling (default)
- `kimi-k2.5` — 256K context, multimodal, tool calling
- `kimi-k2` — 256K context, tool calling
- `kimi-k2-thinking` — 256K context, chain-of-thought reasoning (no tool calling)
- `moonshot-v1-128k`, `moonshot-v1-32k`, `moonshot-v1-8k` — legacy models

**Related Files:**
- `includes/class-wp-mcp-ai-kimi-client.php`
- `includes/admin/sections/class-wp-mcp-ai-section-kimi.php`

---

### 6e. DigitalOcean Serverless Inference API

**Service URL:** `https://inference.do-ai.run/v1` (supports custom base URL override)  
**Purpose:** Cloud AI inference via DigitalOcean's OpenAI-compatible serverless inference platform; also provides native embeddings via the `/embeddings` endpoint  
**Data Sent:**
- Chat messages and conversation history
- System prompts and instructions
- Tool definitions and tool execution results
- Embedding text (when DigitalOcean is the configured embedding provider)

**When Used:**
- Every time an AI assistant is used with DigitalOcean as the provider
- Embedding requests when DigitalOcean is configured as the embedding provider and vector-context features are active

**Legal & Privacy:**
- **Terms of Service:** https://www.digitalocean.com/legal/terms-of-service-agreement
- **Privacy Policy:** https://www.digitalocean.com/legal/privacy-policy
- **AI / Inference Documentation:** https://docs.digitalocean.com/products/ai-ml/
- **Data Usage:** Processed within DigitalOcean's infrastructure; see their privacy policy

**Supported Models (seeded catalogue):**
- `llama3.3-70b-instruct` (default chat model)
- `llama3.1-8b-instruct`
- `deepseek-r1-distill-llama-70b`
- `openai-gpt-oss-120b`
- `gte-large-en-v1.5` (default embedding model)

**Related Files:**
- `includes/class-wp-mcp-ai-digitalocean-client.php`
- `includes/infrastructure/providers/class-wp-mcp-ai-digitalocean-provider-client.php`
- `includes/services/embedding/class-wp-mcp-ai-embedding-provider-digitalocean.php`

---

### 6f. Baseten API

**Service URL:** `https://api.baseten.co/v1` (default; supports custom base URL override)  
**Purpose:** Cloud AI inference via Baseten's OpenAI-compatible API platform. Supports chat completions, tool/function calling, JSON mode, SSE streaming, and reasoning content passthrough.  
**Data Sent:**
- Chat messages and conversation history
- System prompts and instructions
- Tool definitions and tool execution results
- Reasoning content (when using reasoning-capable models)

**When Used:**
- Every time an AI assistant is used with Baseten as the provider

**Legal & Privacy:**
- **Terms of Service:** https://www.baseten.co/terms-of-service
- **Privacy Policy:** https://www.baseten.co/privacy
- **API Documentation:** https://docs.baseten.co/api-reference
- **Data Usage:** See Baseten's privacy policy for data handling details

**Supported Models (seeded catalogue):**
- `deepseek-chat-v4` — DeepSeek V4 (default chat model)
- `deepseek-reasoner-v4` — DeepSeek V4 with reasoning
- Model discovery available via Settings → Providers → Baseten → Model Discovery

**Related Files:**
- `includes/class-wp-mcp-ai-baseten-client.php`
- `includes/infrastructure/providers/class-wp-mcp-ai-baseten-provider-client.php`

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
- **Privacy Policy:** https://www.intuit.com/privacy/
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

## Chat Channel Services

These services are used by the Chat Channels Toolkit pro addon for messaging platform integrations.

### 18. Microsoft Graph API (Office 365)

**Service URL:** `https://graph.microsoft.com/v1.0/`  
**Purpose:** Office 365 email and file management via Microsoft Outlook and OneDrive  
**Data Sent:**
- Microsoft Graph API bearer token (provided by the calling user)
- Email recipient addresses, subjects, and body content (Outlook tools)
- File paths, file content (base64-encoded), MIME types (OneDrive tools)
- Folder paths and item IDs (OneDrive file listing/retrieval)
- OData filter expressions (optional, for message filtering)

**When Used:**
- When the `send_outlook_mail` tool is used to send an email via Outlook
- When the `get_outlook_messages` tool is used to retrieve Outlook messages
- When `list_onedrive_files`, `get_onedrive_file`, or `upload_onedrive_file` tools are used
- When the `WP_MCP_AI_Outlook_Webhook_Controller` receives Outlook subscription notifications

**Legal & Privacy:**
- **Microsoft Privacy Statement:** https://privacy.microsoft.com/en-us/privacystatement
- **Microsoft Services Agreement:** https://www.microsoft.com/en-us/servicesagreement
- **Microsoft Graph API Terms:** https://learn.microsoft.com/en-us/legal/microsoft-apis/terms-of-use
- **Microsoft 365 Compliance:** https://www.microsoft.com/en-us/trust-center

**Related Files:**
- `addons/pro/includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-outlook-mail.php`
- `addons/pro/includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-outlook-messages.php`
- `addons/pro/includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-list-onedrive-files.php`
- `addons/pro/includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-onedrive-file.php`
- `addons/pro/includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-upload-onedrive-file.php`
- `addons/pro/includes/rest/class-wp-mcp-ai-outlook-webhook-controller.php`

---

### 19. iCloud Drive Gateway (User-Configured)

**Service URL:** User-configured HTTPS gateway URL (no fixed default URL)  
**Purpose:** iCloud Drive file listing, retrieval, and upload via a user-provided proxy/gateway service. Apple does not provide a direct third-party REST API for iCloud Drive; a self-hosted or third-party gateway that bridges to Apple CloudKit is required.  
**Data Sent:**
- Gateway API key or bearer token (provided by the calling user)
- File IDs, folder IDs, pagination cursors (listing/retrieval)
- File content (base64-encoded), filenames, and MIME types (upload)

**When Used:**
- When `list_icloud_drive_files`, `get_icloud_drive_file`, or `upload_icloud_drive_file` tools are used with a configured gateway
- When the `WP_MCP_AI_iCloud_Webhook_Controller` receives iCloud gateway push notifications

**Legal & Privacy:**
- The privacy policy and terms of service depend on the gateway service configured by the site administrator
- **Apple iCloud Terms:** https://www.apple.com/legal/internet-services/icloud/
- **Apple Privacy Policy:** https://www.apple.com/legal/privacy/
- **Apple CloudKit:** https://developer.apple.com/icloud/

**Security Note:** The gateway URL is validated to be a valid HTTPS URL. HTTP gateway URLs are rejected.

**Related Files:**
- `addons/pro/includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-list-icloud-drive-files.php`
- `addons/pro/includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-icloud-drive-file.php`
- `addons/pro/includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-upload-icloud-drive-file.php`
- `addons/pro/includes/rest/class-wp-mcp-ai-icloud-webhook-controller.php`

---

## Remote Connection Services

These services are used by the Remote Sites system (NV oOS Pro addon) when remote connections are configured. All entries here require explicit administrator configuration — no data is sent to these services unless a connection of the corresponding type has been set up.

### 20. EZuite ERP API

**Service URL:** `https://api.ezuite.com/api/External_Api/Action_Api/Invoke`  
**Purpose:** Enterprise inventory management — query product data, inventory levels, and warehouse information via the EZuite ERP platform  
**Data Sent:**
- API key and API secret (in request headers)
- Inventory query parameters (product codes, warehouse IDs, filters)

**When Used:** When EZuite ERP tools are used after a `ezuite_erp` connection is configured

**Legal & Privacy:**
- **Website:** https://www.ezuite.com/
- **Contact EZuite for API Terms and Privacy Policy**

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-ezuite-erp.php`
- `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`

---

### 21. iSAMS School Management API

**Service URL:** `https://{instance}.isams.cloud/api/`  
**Purpose:** School management system — query student records, staff, timetables, and exam results  
**Data Sent:**
- API key and API secret (in request headers)
- Query parameters (student IDs, year groups, date ranges, record type filters)

**When Used:** When iSAMS tools are used after an `isams` connection is configured

**Legal & Privacy:**
- **Website:** https://www.isams.com/
- **Privacy Policy:** https://www.isams.com/privacy-policy/
- **Terms of Service:** https://www.isams.com/terms-and-conditions/
- **API Documentation:** https://developerdocs.isams.cloud/

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-isams-query.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-sync-ecas-from-isams.php`

---

### 22. PayHere Payment Gateway

**Service URL:** `https://www.payhere.lk` (live) / `https://sandbox.payhere.lk` (sandbox)  
**Purpose:** Sri Lankan payment gateway — verify payment status, query transaction records  
**Data Sent:**
- App ID and App Secret (for authentication)
- Order IDs and transaction reference numbers
- Payment verification request parameters

**When Used:** When PayHere payment tools are used after a `payhere` connection is configured

**Legal & Privacy:**
- **Website:** https://www.payhere.lk/
- **Privacy Policy:** https://www.payhere.lk/privacy-policy
- **Terms of Service:** https://www.payhere.lk/terms
- **API Documentation:** https://support.payhere.lk/api-&-mobile-sdk

**Related Files:**
- Payment tool files in `addons/pro/includes/tools/`
- `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`

---

### 23. Gmail API (Google)

**Service URL:** `https://gmail.googleapis.com`  
**Purpose:** Search and read Gmail messages — read-only access using the Gmail REST API  
**Data Sent:**
- OAuth2 access token (derived from stored refresh token)
- Search query strings (e.g. `from:user@example.com subject:invoice`)
- Message IDs for retrieval

**When Used:** When the `search_gmail` tool is used after a `gmail` connection is configured

**Legal & Privacy:**
- **Google Privacy Policy:** https://policies.google.com/privacy
- **Google API Terms of Service:** https://developers.google.com/terms
- **Gmail API Terms:** https://developers.google.com/gmail/api/auth/scopes
- **OAuth2 Policy:** https://developers.google.com/identity/protocols/oauth2/policies
- **Data Usage:** Only read-only scope (`gmail.readonly`) is requested; no email sending or modification

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-search-gmail.php`

---

### 24. Google Drive API

**Service URL:** `https://www.googleapis.com/drive/v3`  
**Purpose:** List and read files in Google Drive — read-only access to file metadata and content  
**Data Sent:**
- OAuth2 access token (derived from stored refresh token)
- Search query strings (file names, MIME types, folder IDs)
- File IDs for content retrieval

**When Used:** When the `search_drive` tool is used after a `google_drive` connection is configured

**Legal & Privacy:**
- **Google Privacy Policy:** https://policies.google.com/privacy
- **Google API Terms of Service:** https://developers.google.com/terms
- **Drive API Docs:** https://developers.google.com/drive/api/v3/reference
- **Data Usage:** Only read-only scopes (`drive.readonly`, `drive.metadata.readonly`) are requested

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-search-drive.php`

---

### 25. Upwork API

**Service URL:** `https://api.upwork.com/graphql`  
**Purpose:** Freelance marketplace — search job postings, score job fit, and draft proposals  
**Data Sent:**
- OAuth2 access token (derived from stored refresh token)
- Job search queries (keywords, categories, filters)
- Job posting IDs for scoring and proposal drafting

**When Used:** When Upwork tools are used after an `upwork` connection is configured

**Legal & Privacy:**
- **Terms of Service:** https://www.upwork.com/legal/terms-of-use/
- **Privacy Policy:** https://www.upwork.com/legal/privacy-policy/
- **API Terms:** https://www.upwork.com/legal/api-tos/
- **Developer Portal:** https://www.upwork.com/developer/

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-search-upwork-jobs.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-score-upwork-job.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-draft-upwork-proposal.php`

---

### 26. Telegram Bot API

**Service URL:** `https://api.telegram.org`  
**Purpose:** Chat channel integration — send and receive Telegram messages, manage webhooks, and enable AI assistant auto-replies via a Telegram bot  
**Data Sent:**
- Bot Token (in API URL path)
- Message content, chat IDs, and reply payloads
- Webhook registration URL and secret token
- Reaction emoji identifiers

**When Used:** When Telegram tools are used or when an incoming webhook is received after a `telegram` connection is configured

**Legal & Privacy:**
- **Terms of Service:** https://telegram.org/tos
- **Privacy Policy:** https://telegram.org/privacy
- **Bot API Docs:** https://core.telegram.org/bots/api

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-telegram-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-telegram-updates.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-manage-telegram-commands.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-manage-telegram-webhook.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-add-telegram-message-reaction.php`

---

### 27. WhatsApp Business (Meta Cloud API)

**Service URL:** `https://graph.facebook.com/{version}` (default: `v21.0`)  
**Purpose:** WhatsApp Business messaging — send and receive WhatsApp messages via the Meta Cloud API  
**Data Sent:**
- Cloud API Access Token (in Authorization header)
- Message content, recipient phone numbers, and media payloads
- Webhook verification tokens (during setup)
- HMAC-SHA256 signatures (for webhook verification)

**When Used:** When WhatsApp tools are used or when an incoming webhook is received after a `whatsapp` connection is configured

**Legal & Privacy:**
- **Meta Terms of Service:** https://www.facebook.com/terms.php
- **WhatsApp Business Terms:** https://www.whatsapp.com/legal/business-terms/
- **Meta Privacy Policy:** https://www.facebook.com/privacy/policy/
- **Cloud API Docs:** https://developers.facebook.com/docs/whatsapp/cloud-api

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-whatsapp-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-whatsapp-messages.php`

---

### 28. Slack API

**Service URL:** `https://slack.com/api`  
**Purpose:** Slack workspace integration — send messages, read channel history, and list channels  
**Data Sent:**
- Bot Token (in Authorization header)
- Message content, channel IDs, and thread timestamps
- Signing Secret (for webhook verification)

**When Used:** When Slack tools are used or when an incoming webhook is received after a `slack` connection is configured

**Legal & Privacy:**
- **Terms of Service:** https://slack.com/terms-of-service
- **Privacy Policy:** https://slack.com/privacy-policy
- **API Terms:** https://api.slack.com/developer-policy

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-slack-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-slack-messages.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-slack-channels.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-create-slack-channel.php`

---

### 29. Discord API

**Service URL:** `https://discord.com/api/v10`  
**Purpose:** Discord server integration — send messages, read channel history, manage channels, and add message reactions  
**Data Sent:**
- Bot Token (in Authorization header)
- Message content, channel IDs, guild IDs, and reaction emojis
- Public Key (for webhook interaction verification)

**When Used:** When Discord tools are used or when an incoming webhook is received after a `discord` connection is configured

**Legal & Privacy:**
- **Terms of Service:** https://discord.com/terms
- **Privacy Policy:** https://discord.com/privacy
- **Developer Terms:** https://discord.com/developers/docs/policies-and-agreements/developer-terms-of-service
- **API Docs:** https://discord.com/developers/docs/reference

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-discord-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-discord-messages.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-discord-channels.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-discord-voice-channel-members.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-add-discord-message-reaction.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-create-discord-channel.php`

---

### 30. Microsoft Teams (Bot Framework)

**Service URL:** `https://smba.trafficmanager.net/apis`  
**Purpose:** Microsoft Teams integration — read Teams channel messages via the Azure Bot Framework  
**Data Sent:**
- Azure AD OAuth2 access token (derived from client credentials)
- Channel IDs, team IDs, and message content
- Bot Framework activity payloads (for incoming webhook messages)

**When Used:** When Teams tools are used or when an incoming Teams webhook is received after a `microsoft_teams` connection is configured

**Legal & Privacy:**
- **Microsoft Privacy Statement:** https://privacy.microsoft.com/en-us/privacystatement
- **Microsoft Services Agreement:** https://www.microsoft.com/en-us/servicesagreement
- **Azure Bot Service Terms:** https://azure.microsoft.com/en-us/support/legal/
- **Microsoft Graph API Terms:** https://learn.microsoft.com/en-us/legal/microsoft-apis/terms-of-use

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-teams-channels.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-teams-messages.php`

---

### 31. Facebook Messenger (Meta Graph API)

**Service URL:** `https://graph.facebook.com/{version}` (default: `v21.0`)  
**Purpose:** Facebook Messenger integration — send and receive messages via a Facebook Page  
**Data Sent:**
- Page Access Token (in request)
- Message content, recipient PSIDs (Page-Scoped IDs), and media payloads
- App Secret (for webhook HMAC-SHA256 signature verification)
- Verify Token (during webhook setup)

**When Used:** When Messenger tools are used or when an incoming webhook is received after a `facebook_messenger` connection is configured

**Legal & Privacy:**
- **Meta Terms of Service:** https://www.facebook.com/terms.php
- **Messenger Platform Terms:** https://developers.facebook.com/terms/
- **Meta Privacy Policy:** https://www.facebook.com/privacy/policy/
- **Messenger Platform Docs:** https://developers.facebook.com/docs/messenger-platform

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-messenger-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-messenger-conversations.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-create-messenger-broadcast.php`

---

### 32. Google Chat API

**Service URL:** `https://chat.googleapis.com/v1`  
**Purpose:** Google Chat (Google Workspace) integration — send messages, manage Spaces, and interact with Google Chat bots  
**Data Sent:**
- Service Account JSON credentials or OAuth2 access token
- Message content, Space IDs, and member identifiers
- OIDC audience URL (for webhook verification)

**When Used:** When Google Chat tools are used after a `google_chat` connection is configured

**Legal & Privacy:**
- **Google Privacy Policy:** https://policies.google.com/privacy
- **Google API Terms of Service:** https://developers.google.com/terms
- **Google Chat API Docs:** https://developers.google.com/chat/api/reference/rest
- **Google Workspace Terms:** https://workspace.google.com/terms/

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-google-chat-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-google-chat-messages.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-google-chat-spaces.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-create-google-chat-space.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-add-google-chat-space-member.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-list-google-chat-space-members.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-remove-google-chat-space-member.php`

---

### 33. Twitter / X API v2

**Service URL:** `https://api.twitter.com/2`  
**Purpose:** Twitter/X integration — retrieve direct messages and manage webhook subscriptions for real-time DM notifications  
**Data Sent:**
- Bearer Token or OAuth 2.0 access token (in Authorization header)
- Twitter User ID (for DM access)
- Webhook registration URLs

**When Used:** When Twitter DM tools are used after a `twitter` connection is configured

**Legal & Privacy:**
- **Terms of Service:** https://twitter.com/en/tos
- **Privacy Policy:** https://twitter.com/en/privacy
- **Developer Agreement:** https://developer.twitter.com/en/developer-terms/agreement-and-policy
- **Developer Portal:** https://developer.twitter.com/

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-twitter-dms.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-manage-twitter-webhook.php`

---

### 34. Apple Messages for Business

**Service URL:** User-configured MSP API URL (varies by Message Service Provider)  
**Purpose:** Apple Messages for Business integration — send and receive messages through the Apple Messages app via an approved Message Service Provider (MSP)  
**Data Sent:**
- MSP API key (in request headers)
- Message content, Business ID, and recipient identifiers
- Webhook secret (for message verification)

**When Used:** When Apple Messages tools are used after an `apple_messages` connection is configured

**Legal & Privacy:**
- **Apple Messages for Business Docs:** https://register.apple.com/resources/messages-for-business/MSP_Spec.pdf
- **Apple Privacy Policy:** https://www.apple.com/legal/privacy/
- **Apple Business Register Terms:** https://register.apple.com/
- **Note:** Privacy and terms for the MSP API URL also depend on the Message Service Provider chosen by the site administrator

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-apple-message.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-apple-message-group.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-send-apple-message-interactive.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-get-apple-messages.php`

---

### 35. Shopify API

**Service URL:** `https://{store}.myshopify.com` (Admin API) / `https://discover.shopifyapps.com` (Catalog API)  
**Purpose:** Shopify e-commerce integration — query products, customers, orders, and inventory  
**Data Sent:**
- **Admin API:** Admin API Access Token (in `X-Shopify-Access-Token` header); product/customer/order query parameters
- **Catalog API:** Client ID and Client Secret (exchanged for a JWT bearer token from `https://api.shopify.com/auth/access_token`); catalog search queries and filters

**When Used:** When Shopify tools are used after a `shopify` connection is configured

**Legal & Privacy:**
- **Terms of Service:** https://www.shopify.com/legal/terms
- **Privacy Policy:** https://www.shopify.com/legal/privacy
- **API Terms:** https://www.shopify.com/legal/api-terms
- **Partner Terms:** https://www.shopify.com/legal/partnersapi
- **Developer Docs:** https://shopify.dev/docs/api

**Related Files:**
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-shopify-products.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-shopify-customers.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-shopify-orders.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-shopify-inventory.php`

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
| NVIDIA NIM | Active | 2026-04 | Cloud AI inference |
| DeepSeek API | Active | 2026-05 | Cloud AI inference |
| OpenRouter API | Active | 2026-05 | Multi-model gateway |
| Kimi (Moonshot AI) | Active | 2026-05 | Cloud AI inference |
| DigitalOcean Serverless Inference | Active | 2026-05 | Cloud AI inference + embeddings |
| Baseten API | Active | 2026-05 | Cloud AI inference |
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
| Microsoft Graph (Office 365) | Active | 2026-02 | Azure AD OAuth2 |
| iCloud Drive Gateway | Active | 2026-02 | User-configured gateway |
| WordPress.org | Active | 2026-02 | Core service |
| EZuite ERP | Active | 2026-03 | Remote connection |
| iSAMS | Active | 2026-03 | Remote connection |
| PayHere | Active | 2026-03 | Remote connection |
| Gmail API | Active | 2026-03 | OAuth2, read-only |
| Google Drive API | Active | 2026-03 | OAuth2, read-only |
| Upwork API | Active | 2026-03 | OAuth2 |
| Telegram Bot API | Active | 2026-03 | Bot Token |
| WhatsApp Business (Meta) | Active | 2026-03 | Cloud API |
| Slack API | Active | 2026-03 | Bot Token |
| Discord API | Active | 2026-03 | Bot Token |
| Microsoft Teams (Bot Framework) | Active | 2026-03 | Azure AD OAuth2 |
| Facebook Messenger | Active | 2026-03 | Page Access Token |
| Google Chat API | Active | 2026-03 | Service Account / OAuth2 |
| Twitter / X API v2 | Active | 2026-03 | Bearer Token |
| Apple Messages for Business | Active | 2026-03 | MSP-dependent |
| Shopify API | Active | 2026-03 | Access Token / JWT |

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
