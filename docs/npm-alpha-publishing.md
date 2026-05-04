# NPM Alpha Publishing Guide

This guide explains how to publish alpha versions of the NPM packages from this repository to the NPM registry.

## 📦 Packages

Seventeen standalone NPM packages are published from this repository:

**Tier 1 — Core**
- **@nvdigitalsolutions/nvoos-storage** - Async storage utilities with Web Worker optimization
- **@nvdigitalsolutions/nvoos-markdown** - Security-hardened markdown renderer with XSS protection
- **@nvdigitalsolutions/nvoos-events** - Real-time event coordination with SSE client and job event bus

**Tier 2 — Extended**
- **@nvdigitalsolutions/nvoos-http-client** - Resilient HTTP client with retry and exponential backoff
- **@nvdigitalsolutions/nvoos-clipboard** - Clipboard copy utilities with Clipboard API fallback
- **@nvdigitalsolutions/nvoos-offline-sync** - IndexedDB-backed offline-first sync manager

**Tier 3 — Chat UI**
- **@nvdigitalsolutions/nvoos-slash-commands** - Slash command system with fuzzy-search autocomplete
- **@nvdigitalsolutions/nvoos-audio** - Browser audio I/O: TTS, STT, translation, and voice chat with VAD
- **@nvdigitalsolutions/nvoos-dom-batcher** - RAF DOM batcher, scroll batcher, and streaming UI utilities

**Tier 4 — Browser AI Runtime**
- **@nvdigitalsolutions/nvoos-llm-worker** - Web Worker manager for non-blocking LLM operations
- **@nvdigitalsolutions/nvoos-model-loader** - Progressive 4-stage AI model loading UI
- **@nvdigitalsolutions/nvoos-transformers-client** - HuggingFace Transformers.js task wrapper

**Tier 5 — Chat Services**
- **@nvdigitalsolutions/nvoos-client-tools** - Browser-native AI tool registry (summarize, sentiment, translate, embed, image, audio)
- **@nvdigitalsolutions/nvoos-chat-memory** - Promise-based REST client for an AI chat memory bridge
- **@nvdigitalsolutions/nvoos-attachments** - File attachment helpers: type detection, validation, normalisation
- **@nvdigitalsolutions/nvoos-cron-status** - SSE-first cron/job status monitor with REST polling fallback
- **@nvdigitalsolutions/nvoos-transcription** - MediaRecorder-based audio recording + tool-call transcription pipeline

All packages are published under the `@nvdigitalsolutions` NPM organization.

## 🚀 Publishing Alpha Versions

### Prerequisites

1. **NPM Account & Organization**
   - Create an NPM account at https://www.npmjs.com
   - Join or create the `@nvdigitalsolutions` organization
   - Ensure you have publish permissions

2. **NPM Access Token**
   - Go to https://www.npmjs.com/settings/YOUR_USERNAME/tokens
   - Click "Generate New Token" → "Automation" or "Publish"
   - Copy the token (it will only be shown once)

3. **GitHub Repository Secret**
   - Go to repository Settings → Secrets and variables → Actions
   - Click "New repository secret"
   - Name: `NPM_TOKEN`
   - Value: Paste your NPM access token
   - Click "Add secret"

### Method 1: Using the Helper Script (Recommended)

The easiest way to publish an alpha version is using the provided script:

```bash
# From the repository root
./bin/publish-alpha.sh 0.1.0-alpha.2

# This script will:
# 1. Validate the version format
# 2. Check for uncommitted changes
# 3. Update all package.json files
# 4. Build all seventeen packages
# 5. Create a commit and tag
# 6. Push the tag to trigger the GitHub Actions workflow
```

**Version Format:** `X.Y.Z-alpha.N`

Examples:
- `0.1.0-alpha.1` - First alpha of version 0.1.0
- `0.1.0-alpha.2` - Second alpha of version 0.1.0
- `0.2.0-alpha.1` - First alpha of version 0.2.0

### Method 2: Manual Process

If you prefer to do it manually:

```bash
# 1. Update package versions
for pkg in nvoos-storage nvoos-markdown nvoos-events nvoos-http-client nvoos-clipboard nvoos-offline-sync nvoos-slash-commands nvoos-audio nvoos-dom-batcher nvoos-llm-worker nvoos-model-loader nvoos-transformers-client nvoos-client-tools nvoos-chat-memory nvoos-attachments nvoos-cron-status nvoos-transcription; do
  (cd packages/$pkg && npm version 0.1.0-alpha.2 --no-git-tag-version)
done

# 2. Build all packages
for pkg in nvoos-storage nvoos-markdown nvoos-events nvoos-http-client nvoos-clipboard nvoos-offline-sync nvoos-slash-commands nvoos-audio nvoos-dom-batcher nvoos-llm-worker nvoos-model-loader nvoos-transformers-client nvoos-client-tools nvoos-chat-memory nvoos-attachments nvoos-cron-status nvoos-transcription; do
  (cd packages/$pkg && npm run build)
done

# 3. Commit the changes
git add packages/*/package.json
git commit -m "Bump alpha version to 0.1.0-alpha.2"

# 4. Create and push tag
git tag -a v0.1.0-alpha.2 -m "Alpha release 0.1.0-alpha.2"
git push origin v0.1.0-alpha.2
```

### Method 3: Manual Workflow Dispatch

You can also trigger the workflow manually from GitHub:

1. Go to https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/npm-publish-alpha.yml
2. Click "Run workflow"
3. Enter the version (e.g., `0.1.0-alpha.2`)
4. Optionally enable "Dry run" to test without publishing
5. Click "Run workflow"

## 🔍 Monitoring the Publication

After pushing an alpha tag:

1. **GitHub Actions**: Monitor the workflow at https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions
2. **NPM Registry**: Check packages at:
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-storage
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-markdown
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-events
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-http-client
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-clipboard
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-offline-sync
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-slash-commands
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-audio
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-dom-batcher
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-llm-worker
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-model-loader
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-transformers-client
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-client-tools
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-chat-memory
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-attachments
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-cron-status
   - https://www.npmjs.com/package/@nvdigitalsolutions/nvoos-transcription

## 📥 Installing Alpha Versions

Once published, users can install alpha versions:

```bash
# Install latest alpha version
npm install @nvdigitalsolutions/nvoos-storage@alpha

# Install specific alpha version
npm install @nvdigitalsolutions/nvoos-storage@0.1.0-alpha.2

# Install all nine packages (Tier 1 — Core)
npm install @nvdigitalsolutions/nvoos-storage@alpha \
            @nvdigitalsolutions/nvoos-markdown@alpha \
            @nvdigitalsolutions/nvoos-events@alpha

# Install Tier 2 — Extended
npm install @nvdigitalsolutions/nvoos-http-client@alpha \
            @nvdigitalsolutions/nvoos-clipboard@alpha \
            @nvdigitalsolutions/nvoos-offline-sync@alpha

# Install Tier 3 — Chat UI
npm install @nvdigitalsolutions/nvoos-slash-commands@alpha \
            @nvdigitalsolutions/nvoos-audio@alpha \
            @nvdigitalsolutions/nvoos-dom-batcher@alpha

# Install Tier 4 — Browser AI Runtime
npm install @nvdigitalsolutions/nvoos-llm-worker@alpha \
            @nvdigitalsolutions/nvoos-model-loader@alpha \
            @nvdigitalsolutions/nvoos-transformers-client@alpha

# Install Tier 5 — Chat Services
npm install @nvdigitalsolutions/nvoos-client-tools@alpha \
            @nvdigitalsolutions/nvoos-chat-memory@alpha \
            @nvdigitalsolutions/nvoos-attachments@alpha \
            @nvdigitalsolutions/nvoos-cron-status@alpha \
            @nvdigitalsolutions/nvoos-transcription@alpha
```

## 🧪 Testing Alpha Packages

Before publishing, you can test the packages locally:

```bash
# Build all packages
for pkg in nvoos-storage nvoos-markdown nvoos-events nvoos-http-client nvoos-clipboard nvoos-offline-sync nvoos-slash-commands nvoos-audio nvoos-dom-batcher nvoos-llm-worker nvoos-model-loader nvoos-transformers-client nvoos-client-tools nvoos-chat-memory nvoos-attachments nvoos-cron-status nvoos-transcription; do
  (cd packages/$pkg && npm run build)
done

# Create local tarball for testing (example with nvoos-storage)
cd packages/nvoos-storage
npm pack
# This creates @nvdigitalsolutions-nvoos-storage-0.1.0-alpha.1.tgz

# Install in a test project
cd /path/to/test-project
npm install /path/to/mcp-ai-wpoos/packages/nvoos-storage/@nvdigitalsolutions-nvoos-storage-0.1.0-alpha.1.tgz
```

## 📋 Alpha Release Checklist

Before publishing an alpha version:

- [ ] All packages build successfully (`npm run build` in each package)
- [ ] Version numbers follow semantic versioning with `-alpha.N` suffix
- [ ] No uncommitted changes in the repository
- [ ] NPM_TOKEN secret is configured in GitHub repository
- [ ] You have publish permissions for @nvdigitalsolutions organization
- [ ] CHANGELOG.md is updated (if applicable)
- [ ] README files in each package are up to date

## 🔄 Version Progression

Typical alpha version progression:

1. **Initial Alpha**: `0.1.0-alpha.1`
2. **Bug Fixes**: `0.1.0-alpha.2`, `0.1.0-alpha.3`
3. **Feature Complete**: `0.1.0-beta.1` (when ready for beta)
4. **Release Candidate**: `0.1.0-rc.1`
5. **Stable Release**: `0.1.0`

## 🚨 Troubleshooting

### "NPM_TOKEN secret not configured"

The workflow will skip publishing if the NPM_TOKEN secret is not set. Add it in:
Settings → Secrets and variables → Actions → New repository secret

### "Invalid alpha version format"

Alpha versions must follow the format `X.Y.Z-alpha.N`:
- ✅ `0.1.0-alpha.1`
- ✅ `1.2.3-alpha.10`
- ❌ `0.1.0-alpha` (missing number)
- ❌ `alpha.1` (missing semver)

### "Tag already exists"

If a tag already exists, you need to either:
1. Use a different version number
2. Delete the existing tag: `git tag -d v0.1.0-alpha.1 && git push origin :refs/tags/v0.1.0-alpha.1`

### "403 Forbidden" when publishing

This means you don't have publish permissions for the @nvdigitalsolutions organization:
1. Verify you're a member of the organization on NPM
2. Verify the organization exists
3. Check your NPM access token has "Automation" or "Publish" scope

### Packages not showing as "latest"

This is expected! Alpha versions are published with the `alpha` tag, not `latest`. Users need to explicitly install with `@alpha` or specify the exact version.

## 📚 Additional Resources

- [NPM Package Documentation](../packages/README.md)
- [Package Source Code](../packages/)
- [Main Repository README](../README.md)
- [Contributing Guide](../CONTRIBUTING.md)

## 🆘 Support

For issues with alpha publishing:
1. Check the GitHub Actions logs for error messages
2. Review the troubleshooting section above
3. Open an issue at https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
4. Contact NV Digital Solutions at hello@nvdigitalsolutions.com
