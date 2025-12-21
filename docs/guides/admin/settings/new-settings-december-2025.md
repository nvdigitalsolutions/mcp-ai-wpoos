# New Settings Guide - December 2025

This guide documents the 27 new settings added to the WP oOS admin UI in December 2025 (PR #2072).

## Overview

Previously, these settings were defined in the codebase but not visible in the WordPress admin interface. They are now properly exposed with appropriate UI controls and help text.

---

## Media Settings

### MIME Type Allowlists

**Location:** Settings → WP oOS → Media

#### `allowed_file_mimes`
- **Type:** Textarea
- **Description:** Comma-separated list of allowed MIME types for file uploads
- **Default:** WordPress default MIME types
- **Example:**
  ```
  application/pdf,
  application/zip,
  text/plain,
  text/csv
  ```
- **Use Case:** Restrict file uploads to specific types for security

#### `allowed_image_mimes`
- **Type:** Textarea
- **Description:** Comma-separated list of allowed MIME types for image uploads
- **Default:** `image/jpeg, image/png, image/gif, image/webp`
- **Example:**
  ```
  image/jpeg,
  image/png,
  image/webp
  ```
- **Use Case:** Control which image formats can be uploaded

---

## OpenAI Provider Settings

**Location:** Settings → WP oOS → Providers → OpenAI

### Text-to-Speech (TTS) Configuration

#### `openai_speech_model`
- **Type:** Select
- **Description:** OpenAI TTS model to use for speech generation
- **Options:**
  - `tts-1` (Standard quality, faster)
  - `tts-1-hd` (High definition quality)
- **Default:** `tts-1`
- **Use Case:** Choose quality vs. speed for speech synthesis

#### `openai_speech_voice`
- **Type:** Select
- **Description:** Voice to use for OpenAI speech generation
- **Options:**
  - `alloy` (Neutral, balanced)
  - `echo` (Male, calm)
  - `fable` (British, expressive)
  - `onyx` (Deep, authoritative)
  - `nova` (Female, friendly)
  - `shimmer` (Female, warm)
- **Default:** `alloy`
- **Use Case:** Select voice character for TTS output

#### `openai_speech_format`
- **Type:** Select
- **Description:** Audio format for generated speech
- **Options:**
  - `mp3` (Compressed, widely compatible)
  - `opus` (High quality, efficient)
  - `aac` (Advanced audio coding)
  - `flac` (Lossless, larger files)
- **Default:** `mp3`
- **Use Case:** Choose audio format based on quality/size needs

### High Token Model Switching

#### `enable_high_token_model_switch`
- **Type:** Checkbox
- **Description:** Automatically switch to fallback model when token limit exceeded
- **Default:** Disabled
- **Use Case:** Prevent errors by falling back to a model with larger context window

#### `high_token_fallback_model`
- **Type:** Text
- **Description:** Model to use when primary model exceeds token limit
- **Default:** `gpt-4-turbo-preview`
- **Example:** `gpt-4-32k` or `gpt-4-turbo-preview`
- **Use Case:** Specify which model to use for overflow handling

---

## Tool Configuration Settings

**Location:** Settings → WP oOS → Tools → Configuration (New Subtab)

### Web Search

#### `web_search_provider`
- **Type:** Select
- **Description:** Search engine to use for web search tool
- **Options:**
  - `duckduckgo` (Privacy-focused)
  - `brave` (Requires API key)
- **Default:** `duckduckgo`
- **Use Case:** Choose search provider based on privacy/features

### Group Email Controls

#### `group_email_capability`
- **Type:** Text
- **Description:** WordPress capability required to send group emails
- **Default:** `edit_posts`
- **Example:** `manage_options` (admin only) or `publish_posts`
- **Use Case:** Control who can use the group email tool

#### `group_email_max_recipients`
- **Type:** Number
- **Description:** Maximum number of recipients for group emails
- **Default:** `50`
- **Range:** 1-500
- **Use Case:** Prevent spam and resource abuse

### Cache Management

#### `enable_varnish_purge`
- **Type:** Checkbox
- **Description:** Enable Varnish cache purging integration
- **Default:** Disabled
- **Use Case:** Enable if using Varnish reverse proxy

---

## Cloudways Integration Settings

**Location:** Settings → WP oOS → Integrations → Cloudways

#### `cloudways_app_id`
- **Type:** Text
- **Description:** Cloudways application identifier
- **Example:** `12345`
- **Where to Find:** Cloudways Dashboard → Applications → Application Details
- **Use Case:** Required for app-specific Cloudways operations

#### `cloudways_server_id`
- **Type:** Text
- **Description:** Cloudways server identifier
- **Example:** `67890`
- **Where to Find:** Cloudways Dashboard → Servers → Server Details
- **Use Case:** Required for server-specific Cloudways operations

---

## Analytics Settings

**Location:** Settings → WP oOS → Integrations

### Google Analytics 4

#### `google_analytics_credentials_json`
- **Type:** Textarea (JSON)
- **Description:** Service account JSON credentials for GA4 API
- **Format:** Complete JSON object from Google Cloud Console
- **Example:**
  ```json
  {
    "type": "service_account",
    "project_id": "your-project-id",
    "private_key_id": "key-id",
    "private_key": "-----BEGIN PRIVATE KEY-----\n...",
    "client_email": "service-account@project.iam.gserviceaccount.com",
    "client_id": "123456789",
    "auth_uri": "https://accounts.google.com/o/oauth2/auth",
    "token_uri": "https://oauth2.googleapis.com/token"
  }
  ```
- **Where to Find:** Google Cloud Console → IAM & Admin → Service Accounts
- **Use Case:** Required for Google Analytics reporting tool

### International Trade

#### `ita_tariff_api_key`
- **Type:** Text
- **Description:** API key for International Trade Administration tariff lookup
- **Where to Get:** https://api.trade.gov/
- **Use Case:** Required for import duty calculation tool

---

## Federation & Mesh Networking Settings

**Location:** Settings → WP oOS → Advanced → Federation & Mesh (New Subtab)

### Federation Directory

#### `enable_federation_directory`
- **Type:** Checkbox
- **Description:** Participate in the WP oOS federation directory
- **Default:** Disabled
- **Use Case:** Allow site discovery for distributed AI workloads

### Regional Routing

#### `federation_regions`
- **Type:** Text (comma-separated)
- **Description:** Geographic regions where this site operates
- **Default:** `global`
- **Example:** `us-east,us-west,eu-central,ap-southeast`
- **Use Case:** Enable regional routing for data residency compliance

#### `federation_data_tags`
- **Type:** Text (comma-separated)
- **Description:** Metadata tags for federation discovery
- **Default:** Empty
- **Example:** `ecommerce,woocommerce,content-heavy,media-processing`
- **Use Case:** Help other sites discover your capabilities

### Rate Limiting

#### `federation_qps`
- **Type:** Number
- **Description:** Queries per second limit for federation requests
- **Default:** `10`
- **Range:** 1-100
- **Use Case:** Control inbound federation request rate

#### `federation_burst`
- **Type:** Number
- **Description:** Burst capacity for rate limiting
- **Default:** `20`
- **Range:** 1-200
- **Use Case:** Allow temporary spikes in federation requests

### Mesh Networking

#### `mesh_inbound_api_key`
- **Type:** Text (readonly, auto-generated)
- **Description:** API key for authenticating inbound mesh requests
- **Auto-generated:** When mesh networking is enabled
- **Format:** `mesh_xxxxxxxx...` (32 characters)
- **Use Case:** Share with peer sites to allow mesh connections

#### `mesh_peer_sites`
- **Type:** Textarea (JSON)
- **Description:** Configuration for mesh network peer sites
- **Format:** JSON array of peer configurations
- **Example:**
  ```json
  [
    {
      "name": "Production Site",
      "url": "https://example.com",
      "api_key": "mesh_abc123...",
      "regions": ["us-east"],
      "capabilities": ["compute", "storage"]
    },
    {
      "name": "EU Mirror",
      "url": "https://eu.example.com",
      "api_key": "mesh_def456...",
      "regions": ["eu-central"],
      "capabilities": ["compute"]
    }
  ]
  ```
- **Use Case:** Configure peer sites for distributed workload sharing

### Advanced Federation

#### `federation_jwks_keys`
- **Type:** Textarea (JSON)
- **Description:** JWKS (JSON Web Key Set) for federation authentication
- **Format:** JWKS JSON format
- **Example:**
  ```json
  {
    "keys": [
      {
        "kty": "RSA",
        "kid": "key-1",
        "use": "sig",
        "n": "...",
        "e": "AQAB"
      }
    ]
  }
  ```
- **Use Case:** Advanced authentication for federation requests

#### `federation_price_hints`
- **Type:** Textarea (JSON)
- **Description:** Pricing hints for federation compute sharing
- **Format:** JSON object with pricing data
- **Example:**
  ```json
  {
    "compute": {
      "per_request": 0.01,
      "per_token": 0.0001,
      "currency": "USD"
    },
    "storage": {
      "per_gb_month": 0.10,
      "currency": "USD"
    }
  }
  ```
- **Use Case:** Communicate resource costs to federation partners

---

## Migration Notes

### From Hidden Settings

If you were using any of these settings via filters or direct database access, they are now properly accessible via the WordPress admin UI. Your existing values will be preserved.

### Naming Changes

The following settings had naming inconsistencies fixed:

**Authentication Section:**
- ❌ `enable_wpcom_gravatar_bridge` → ✅ `enable_wordpress_gravatar_bridge`
- ❌ `wpcom_gravatar_userinfo_endpoint` → ✅ `wordpress_gravatar_userinfo_endpoint`

**Tools Section:**
- ❌ `enable_mesh_computing` → ✅ `enable_mesh`

If you were using the old names in filters, update to the new names.

---

## Security Considerations

### MIME Type Allowlists
- Restricting MIME types helps prevent upload of potentially dangerous files
- Consider your use case carefully before allowing executable types
- Always validate uploads on the server side

### API Keys and Credentials
- Never commit credentials to version control
- Use environment variables for sensitive data when possible
- Rotate API keys regularly
- Limit service account permissions to minimum required

### Federation & Mesh Networking
- Only enable federation if you understand the security implications
- Auto-generated mesh API keys should be treated as passwords
- Validate peer site certificates in production
- Monitor federation request logs for unusual activity
- Consider firewall rules to restrict federation access

### Rate Limiting
- Set conservative rate limits initially
- Monitor for abuse and adjust as needed
- Consider burst capacity for legitimate traffic spikes

---

## Troubleshooting

### Settings Not Saving

1. Check WordPress user capabilities (usually need `manage_options`)
2. Verify no JavaScript errors in browser console
3. Check for plugin conflicts (try disabling other plugins)
4. Look for PHP errors in WordPress debug log

### JSON Settings Invalid

For JSON fields (`google_analytics_credentials_json`, `mesh_peer_sites`, etc.):

1. Validate JSON syntax using online validators
2. Check for missing quotes or commas
3. Ensure no trailing commas (invalid JSON)
4. Use a JSON formatter for readability

### Federation Not Working

1. Verify `enable_federation_directory` is checked
2. Confirm `mesh_inbound_api_key` is generated
3. Check that peer sites have correct URL and API key
4. Review federation request logs for errors
5. Verify firewall allows inbound requests from peer IPs

---

## Best Practices

### TTS Configuration
- Use `tts-1` for faster, lower-cost generation
- Use `tts-1-hd` for higher quality needs
- Test different voices to find best fit for your content
- Consider MP3 format for web delivery, FLAC for archival

### Group Email
- Set `group_email_max_recipients` conservatively
- Require higher capabilities (`manage_options`) for sensitive sites
- Monitor for abuse patterns
- Consider implementing custom logging

### Federation & Mesh
- Start with `enable_federation_directory` disabled
- Test with trusted peers first
- Document peer configurations clearly
- Set up monitoring for federation requests
- Use meaningful `federation_data_tags`

### API Credentials
- Store sensitive credentials in environment variables
- Use service accounts with minimum required permissions
- Rotate credentials regularly
- Audit API usage periodically

---

## Further Reading

- [Federation & Discovery System](features/mesh/federation-discovery.md)
- [Mesh Compute Pooling](features/mesh/mesh-compute-pooling.md)
- [Rate Limit Protection](troubleshooting/deployment/rate-limit-protection.md)
- [OpenAI TTS Documentation](https://platform.openai.com/docs/guides/text-to-speech)
- [Google Analytics 4 API Setup](https://developers.google.com/analytics/devguides/reporting/data/v1)
