# WordPress.org Submission Packages

**Date:** January 30, 2026  
**Version:** 1.1.0  
**Status:** ✅ Ready for Distribution

---

## Package Overview

This build creates **8 ZIP files** for distribution:

### Original Packages (4 files) - Repository Text Domains
Built by `build-plugin-zip.sh` with repository text domains for internal development:
1. `mcp-ai-wpoos-base-1.1.0.zip` - Base version (text domain: `mcp-ai-wpoos-base`)
2. `mcp-ai-wpoos-pro-1.1.0.zip` - Pro add-on (text domain: `mcp-ai-wpoos-pro`)
3. `mcp-ai-wpoos-1.1.0.zip` - Combined (text domain: `mcp-ai-wpoos`)
4. `mcp-ai-wpoos-core-1.0.0.zip` - Core (text domain: `mcp-ai-wpoos-core`)

### WordPress.org Packages (4 files) - WordPress Text Domains
Built by `build-wordpress-org-from-base.sh` with WordPress.org text domains transformed throughout:
5. `nvdigital-open-operator-system-oos-1.1.0.zip` - Base (text domain: `nvdigital-open-operator-system-oos`)
6. `nvdigital-open-operator-system-oos-pro-1.1.0.zip` - Pro (text domain: `nvdigital-open-operator-system-oos-pro`)
7. `nvdigital-open-operator-system-oos-complete-1.1.0.zip` - Combined (text domain: `nvdigital-open-operator-system-oos`)
8. `nvdigital-open-operator-system-oos-core-1.0.0.zip` - Core (text domain: `nvdigital-open-operator-system-oos-core`)

---

## Package Details

### 1. BASE Package (WordPress.org Submission)
**Original:** `mcp-ai-wpoos-base-1.1.0.zip`  
**WordPress.org:** `nvdigital-open-operator-system-oos-1.1.0.zip` (8.5M)

**What's Included:**
- 127 base tools
- Multi-provider AI (OpenAI, Gemini, Ollama)
- Chat interface
- Tool management system
- Privacy API (GDPR compliant)
- Site Health integration

**Text Domain:** `nvdigital-open-operator-system-oos`

**Use For:**
- WordPress.org submission
- Free public distribution
- Sites requiring WordPress.org approved plugins

---

### 2. PRO Add-on Package
**Original:** `mcp-ai-wpoos-pro-1.1.0.zip`  
**WordPress.org:** `nvdigital-open-operator-system-oos-pro-1.1.0.zip` (19M)

**What's Included:**
- 70+ Pro tools
- Pro Dashboard
- Advanced integrations (WooCommerce, JetEngine, GitHub, Google, etc.)
- Social media tools
- Document generation (PDF, Word, Excel)
- Video processing (FFmpeg)

**Text Domain:** `nvdigital-open-operator-system-oos-pro`

**Requirements:** Requires base plugin to be installed first

**Use For:**
- Add-on distribution
- Pro features for existing base installations

---

### 3. COMPLETE Package (Self-hosted Distribution)
**Original:** `mcp-ai-wpoos-1.1.0.zip`  
**WordPress.org:** `nvdigital-open-operator-system-oos-complete-1.1.0.zip` (21M)

**What's Included:**
- Everything in BASE package
- Everything in PRO package
- All 197+ tools in one install

**Text Domain:** `nvdigital-open-operator-system-oos` (base) + `nvdigital-open-operator-system-oos-pro` (pro features)

**Use For:**
- Self-hosted websites
- Users who want all features in one package
- Private distribution
- Development environments

---

### 4. CORE Package (Lightweight)
**Original:** `mcp-ai-wpoos-core-1.0.0.zip`  
**WordPress.org:** `nvdigital-open-operator-system-oos-core-1.0.0.zip` (36K)

**What's Included:**
- 4 basic tools only
- Minimal footprint
- Essential AI functionality

**Text Domain:** `nvdigital-open-operator-system-oos-core`

**Use For:**
- Lightweight installations
- Testing environments
- Minimal AI integration needs

---

## Key Differences: Original vs WordPress.org Versions

| Aspect | Original Packages | WordPress.org Packages |
|--------|-------------------|------------------------|
| **Plugin Headers** | Repository text domains (mcp-ai-wpoos-base, mcp-ai-wpoos-pro, etc.) | WordPress text domains (nvdigital-open-operator-system-oos, nvdigital-open-operator-system-oos-pro) |
| **Code Text Domains** | Repository text domains (__('text', 'mcp-ai-wpoos')) | WordPress text domains (__('text', 'nvdigital-open-operator-system-oos')) |
| **Translation Files** | Repository names (mcp-ai-wpoos-base.pot) | WordPress names (nvdigital-open-operator-system-oos.pot) |
| **Use Case** | Development, testing, internal builds | Production, WordPress.org submission, public distribution |
| **Recommended For** | Internal repository development | End users and WordPress.org |

**Important:** For WordPress.org submission or public distribution, **always use the WordPress.org versions** (nvdigital-open-operator-system-oos-*) which have all text domains fully transformed throughout the codebase (both headers and code).

**Original packages** (mcp-ai-wpoos-*) maintain repository text domains for development consistency and are useful for:
- Local development and testing
- Internal CI/CD pipelines
- Developer debugging and troubleshooting

**WordPress.org packages** (nvdigital-open-operator-system-oos-*) use production-ready text domains and are required for:
- WordPress.org plugin directory submission
- Public distribution to end users
- Production deployments

---

## Installation

### BASE Package (WordPress.org)
- **File to use:** `nvdigital-open-operator-system-oos-1.1.0.zip`
- **WordPress.org:** Submit to https://wordpress.org/plugins/developers/add/
- **Manual Install:** Upload via Plugins → Add New → Upload Plugin

### PRO Package  
- **File to use:** `nvdigital-open-operator-system-oos-pro-1.1.0.zip`
- **Requirements:** Base plugin must be installed first
- **Manual Install:** Upload via Plugins → Add New → Upload Plugin

### COMPLETE Package (Self-hosted)
- **File to use:** `nvdigital-open-operator-system-oos-complete-1.1.0.zip`
- **Self-hosted Only:** Cannot submit to WordPress.org (includes Pro)
- **Manual Install:** Upload via Plugins → Add New → Upload Plugin
- **Distribution:** Host on your website for customer downloads

### CORE Package (Lightweight)
- **File to use:** `nvdigital-open-operator-system-oos-core-1.0.0.zip`
- **Manual Install:** Upload via Plugins → Add New → Upload Plugin
- **Use Case:** Minimal installations, testing

---

## Compliance

**Original Packages (mcp-ai-wpoos-*):**
- ✅ Use repository text domains (mcp-ai-wpoos*)
- ✅ Maintain development consistency
- ✅ Fully functional for internal testing
- ⚠️ Not suitable for WordPress.org submission or public distribution

**WordPress.org Packages (nvdigital-open-operator-system-oos-*):**
- ✅ Text domains fully transformed to WordPress.org standards in headers and code
- ✅ Translation files renamed to match WordPress text domains
- ✅ No .backup files
- ✅ No broken references
- ✅ Fully functional and production-ready
- ✅ Ready for WordPress.org submission and public distribution

**Submission Guidelines:**
- **BASE package:** WordPress.org submission ready ✅
- **PRO package:** Self-hosted add-on distribution (requires base plugin)
- **COMPLETE package:** Self-hosted distribution only ⚠️ (includes proprietary Pro features - cannot submit to WordPress.org)
- **CORE package:** Lightweight WordPress.org or self-hosted distribution ✅

---

## Support

- **Documentation:** See `docs/` directory in repository
- **GitHub:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos
- **Issues:** GitHub Issues

Built: January 30, 2026
