# Base Version vs. Full Version Comparison

This document provides a detailed comparison between the Base Version and Full Version of WP oOS.

## Quick Comparison

| Feature | Base Version | Full Version |
|---------|-------------|--------------|
| **Setup Complexity** | Simple - WordPress only | Advanced - requires plugins |
| **Third-Party Plugins Required** | None | WooCommerce, JetEngine, etc. |
| **External APIs Required** | Only OpenAI/Gemini | Many (Google, Social Media, etc.) |
| **Total Tools** | 35 | 65 |
| **Memory Footprint** | Lower | Higher |
| **Best For** | Testing, development, simple sites | Production, ecommerce, marketing |

## Tool Comparison by Category

### Content Management & Search

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Get Recent Posts | ✅ | ✅ | WordPress core |
| Search Content | ✅ | ✅ | WordPress core |
| Search Attachments | ✅ | ✅ | WordPress core |
| Get User Info | ✅ | ✅ | WordPress core |
| Save Post | ✅ | ✅ | WordPress core |
| Submit Document Prompt | ✅ | ✅ | WordPress core |
| Get Elementor Templates | ❌ | ✅ | Elementor plugin |
| Get RankMath SEO | ❌ | ✅ | RankMath plugin |
| Create WPCode Snippet | ❌ | ✅ | WPCode plugin |

### Media Generation & Transcription

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Generate OpenAI Image | ✅ | ✅ | OpenAI API key |
| Generate Gemini Image | ✅ | ✅ | Gemini API key |
| Generate OpenAI Speech | ✅ | ✅ | OpenAI API key |
| Transcribe OpenAI Audio | ✅ | ✅ | OpenAI API key |
| Generate Perfume Lifestyle Image | ✅ | ✅ | OpenAI API key |

### Research & External Data

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Web Search | ✅ | ✅ | WordPress core |
| Crawl4AI Price Lookup | ✅ | ✅ | WordPress core |
| Get GDACS Events | ✅ | ✅ | WordPress core |
| Get Open-Meteo Forecast | ✅ | ✅ | WordPress core |
| Get NHC Active Storms | ✅ | ✅ | WordPress core |
| ReliefWeb Reports | ✅ | ✅ | WordPress core |
| Get Import Duty | ✅ | ✅ | WordPress core |
| Vision Product Search | ❌ | ✅ | Google Cloud Vision API |
| Vision Object Localization | ❌ | ✅ | Google Cloud Vision API |

### Operations & Diagnostics

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Get Site Summary | ✅ | ✅ | WordPress core |
| Get Site Health | ✅ | ✅ | WordPress core |
| Get Environment Status | ✅ | ✅ | WordPress core |
| Get System Logs | ✅ | ✅ | WordPress core |
| Get Update Status | ✅ | ✅ | WordPress core |
| Create Cron Job | ✅ | ✅ | WordPress core |
| Check WP-CLI | ✅ | ✅ | WordPress core |
| Probe Chat | ✅ | ✅ | WordPress core |
| Probe Remote MCP | ✅ | ✅ | WordPress core |
| Open OpenAI Usage | ✅ | ✅ | WordPress core |
| Open OpenAI Logs | ✅ | ✅ | WordPress core |

### Automation

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Run OpenAI External Action | ✅ | ✅ | OpenAI API key |
| Run Crawl4AI Job | ✅ | ✅ | WordPress core |
| Create Google Calendar Event | ❌ | ✅ | Google Calendar API |
| Search Gmail | ❌ | ✅ | Gmail API |

### Cache Management

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Purge Cache | ✅ | ✅ | WordPress core |
| Purge Cloudflare Cache | ✅ | ✅ | Cloudflare API (optional) |
| Purge Varnish Cache | ✅ | ✅ | Varnish (optional) |

### Communication

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Send Group Email | ✅ | ✅ | WordPress wp_mail() |
| Send Mailjet Email | ❌ | ✅ | Mailjet API |
| Send Telegram Message | ❌ | ✅ | Telegram Bot API |
| Send WhatsApp Message | ❌ | ✅ | WhatsApp Cloud API |
| Schedule Notify SMS | ❌ | ✅ | Notify.lk API |

### Commerce & Finance

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Create WooCommerce Product | ❌ | ✅ | WooCommerce plugin |
| Get WooCommerce Products | ❌ | ✅ | WooCommerce plugin |
| Get WooCommerce Orders | ❌ | ✅ | WooCommerce plugin |
| QuickBooks Report | ❌ | ✅ | QuickBooks Online API |

### Social Media

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Post Facebook/Instagram | ❌ | ✅ | Meta Graph API |
| Get Facebook/Instagram Insights | ❌ | ✅ | Meta Graph API |
| Post TikTok Video | ❌ | ✅ | TikTok Open API |
| Get TikTok Insights | ❌ | ✅ | TikTok Open API |
| Post LinkedIn Update | ❌ | ✅ | LinkedIn Marketing API |
| Get LinkedIn Insights | ❌ | ✅ | LinkedIn Marketing API |
| Post Google Business Update | ❌ | ✅ | Google Business API |
| Get Google Business Insights | ❌ | ✅ | Google Business API |

### Analytics

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Google Analytics Report | ❌ | ✅ | Google Analytics 4 API |

### JetEngine/JetFormBuilder

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Get JetEngine Items | ❌ | ✅ | JetEngine plugin |
| List JetEngine Routes | ❌ | ✅ | JetEngine plugin |
| Invoke JetEngine Route | ❌ | ✅ | JetEngine plugin |
| Get JetFormBuilder Forms | ❌ | ✅ | JetFormBuilder plugin |
| Get JetFormBuilder Submissions | ❌ | ✅ | JetFormBuilder plugin |

### Authentication

| Tool | Base | Full | Requirements |
|------|------|------|--------------|
| Generate Simple JWT Token | ❌ | ✅ | Simple JWT Login plugin |

## Integration Support

### Base Version
- ✅ WordPress Core
- ✅ OpenAI API
- ✅ Gemini API
- ✅ Ollama (local AI)
- ✅ LM Studio (local AI)

### Full Version (All Base Version plus:)
- ✅ WooCommerce
- ✅ JetEngine
- ✅ JetFormBuilder
- ✅ Elementor
- ✅ RankMath SEO
- ✅ WPCode
- ✅ ChatKit
- ✅ Simple JWT Login
- ✅ All external APIs listed above

## Configuration Requirements

### Base Version (Default)
1. WordPress 6.0+ with PHP 7.4+
2. OpenAI API key OR Gemini API key OR Ollama/LM Studio
3. **No configuration needed** - Base version is enabled by default

### Full Version
1. WordPress 6.0+ with PHP 7.4+
2. OpenAI API key OR Gemini API key OR Ollama/LM Studio
3. Define constant in wp-config.php: `define( 'WP_MCP_AI_BASE_VERSION', false );`
4. Optional: WooCommerce, JetEngine, Elementor, etc.
5. Optional: External API keys for services you want to use

## Use Cases

### When to Use Base Version

✅ **Perfect for:**
- Learning and experimenting with AI
- Development and testing environments
- Simple blogs and content sites
- Sites without ecommerce
- Users who want minimal setup
- Privacy-conscious deployments (local AI only)
- Budget-conscious projects

❌ **Not ideal for:**
- Ecommerce sites
- Sites with WooCommerce
- Marketing automation needs
- Social media management
- Sites already using JetEngine/Elementor

### When to Use Full Version

✅ **Perfect for:**
- Production ecommerce sites
- Marketing agencies
- Sites with WooCommerce
- Social media automation
- Complex WordPress setups with JetEngine/Elementor
- Business sites needing CRM/ERP integration
- Sites requiring external API integrations

❌ **Not needed for:**
- Simple blogs
- Testing environments
- Sites without external integrations
- Users wanting simplicity

## Migration Path

### From Base to Full

1. Add the constant: `define( 'WP_MCP_AI_BASE_VERSION', false );` to wp-config.php
2. Install required plugins (WooCommerce, JetEngine, etc.) as needed
3. Configure API credentials for external services
4. The additional 30 tools will automatically become available

### From Full to Base

1. Remove the constant: Delete or comment out `define( 'WP_MCP_AI_BASE_VERSION', false );`
2. The 30 extended tools will be hidden from assistants
3. Third-party plugin integrations will not load
4. No data is lost - switching back restores everything

## Performance Impact

### Base Version
- **Memory Usage**: Lower (~30% reduction from full version)
- **Load Time**: Faster (fewer classes to load)
- **Admin Complexity**: Simpler (fewer tools in assistant editor)

### Full Version
- **Memory Usage**: Higher (all integrations loaded)
- **Load Time**: Standard WordPress plugin load time
- **Admin Complexity**: More options (all 65 tools available)

## Recommended Approach

1. **Start with Base Version** if you're new to the plugin or WordPress
2. **Switch to Full Version** as your needs grow and you add plugins
3. **Use Base Version** for staging/development, Full Version for production
4. **Mix and Match** - Use custom filters to create your own version with specific tools

## Support and Documentation

- Base Version Guide: [BASE-VERSION.md](../BASE-VERSION.md)
- Full Documentation: [README.md](../README.md)
- Tool Reference: See README for complete tool descriptions
- GitHub Issues: Report problems or request features

## Summary

The Base Version provides a streamlined, WordPress-native experience with 35 essential tools, while the Full Version offers 65 tools including third-party integrations and external API connections. Both versions are production-ready and can be switched at any time without data loss.

Choose Base Version for simplicity and WordPress-only features. Choose Full Version for advanced integrations and external service connections.
