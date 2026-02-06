# NPM Alpha Publishing Guide

This guide explains how to publish alpha versions of the NPM packages from this repository to the NPM registry.

## 📦 Packages

Three standalone NPM packages are published from this repository:

- **@nvdigitalsolutions/nvoos-storage** - Async storage utilities with Web Worker optimization
- **@nvdigitalsolutions/nvoos-markdown** - Security-hardened markdown renderer with XSS protection
- **@nvdigitalsolutions/nvoos-events** - Real-time event coordination with SSE client and job event bus

All packages are published under the `@nvdigitalsolutions` NPM organization.

## 🚀 Publishing Alpha Versions

### Prerequisites

1. **NPM Account & Organization**
   - Create an NPM account at https://www.npmjs.com
   - Join or create the `@nvdigitalsolutions` organization
   - Ensure you have publish permissions

2. **NPM Access Token** (⚠️ IMPORTANT: Must be "Automation" type)
   - Go to https://www.npmjs.com/settings/YOUR_USERNAME/tokens
   - Click "Generate New Token" → Select **"Automation"** token type
   - **DO NOT use "Publish" or "Granular Access Token"** types
     - These require 2FA/OTP for each publish operation
     - CI/CD systems cannot provide OTP codes
     - You'll get error: `EOTP: This operation requires a one-time password`
   - Copy the token (it will only be shown once)
   - **Why Automation tokens?**
     - Designed specifically for CI/CD pipelines
     - Bypass 2FA/OTP requirements (secure for automation)
     - Have full publish permissions without interaction

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
# 4. Build all three packages
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
cd packages/nvoos-storage && npm version 0.1.0-alpha.2 --no-git-tag-version && cd ../..
cd packages/nvoos-markdown && npm version 0.1.0-alpha.2 --no-git-tag-version && cd ../..
cd packages/nvoos-events && npm version 0.1.0-alpha.2 --no-git-tag-version && cd ../..

# 2. Build all packages
cd packages/nvoos-storage && npm run build && cd ../..
cd packages/nvoos-markdown && npm run build && cd ../..
cd packages/nvoos-events && npm run build && cd ../..

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

## 📥 Installing Alpha Versions

Once published, users can install alpha versions:

```bash
# Install latest alpha version
npm install @nvdigitalsolutions/nvoos-storage@alpha

# Install specific alpha version
npm install @nvdigitalsolutions/nvoos-storage@0.1.0-alpha.2

# Install all three packages
npm install @nvdigitalsolutions/nvoos-storage@alpha \
            @nvdigitalsolutions/nvoos-markdown@alpha \
            @nvdigitalsolutions/nvoos-events@alpha
```

## 🧪 Testing Alpha Packages

Before publishing, you can test the packages locally:

```bash
# Build all packages
cd packages/nvoos-storage && npm run build && cd ../..
cd packages/nvoos-markdown && npm run build && cd ../..
cd packages/nvoos-events && npm run build && cd ../..

# Create local tarball for testing
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

### "EOTP: This operation requires a one-time password"

**Most Common Issue!** This error means you're using the wrong NPM token type:

```
npm error code EOTP
npm error This operation requires a one-time password from your authenticator.
```

**Solution:**
1. Go to https://www.npmjs.com/settings/[username]/tokens
2. Revoke the old token (if it says "Publish" or "Granular Access Token")
3. Click "Generate New Token" → Select **"Automation"** token type
4. Copy the new token
5. Update the `NPM_TOKEN` secret in GitHub:
   - Go to repository Settings → Secrets and variables → Actions
   - Edit the `NPM_TOKEN` secret
   - Paste the new Automation token
6. Re-run the failed workflow

**Why this happens:**
- Your NPM account has 2FA enabled (good!)
- "Publish" and "Granular Access Token" types require OTP codes
- GitHub Actions can't provide interactive OTP codes
- "Automation" tokens bypass this for CI/CD specifically

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

This means you don't have publish permissions:
1. Verify you're a member of the @nvdigitalsolutions organization on NPM
2. Verify the organization exists
3. Check your NPM access token is an "Automation" token (not "Publish")
4. Ensure the token was created by an org member with publish permissions

### Packages not showing as "latest"

This is expected! Alpha versions are published with the `alpha` tag, not `latest`. Users need to explicitly install with `@alpha` or specify the exact version.

## 📚 Additional Resources

- [NPM Package Documentation](../packages/README.md)
- [Package Source Code](../packages/)
- [Main Repository README](../README.md)
- [Contributing Guide](../CONTRIBUTING.md)

## 🆘 Support

For issues with alpha publishing:
1. **Check Troubleshooting Guide**: [docs/npm-publishing-troubleshooting.md](npm-publishing-troubleshooting.md)
2. Check the GitHub Actions logs for error messages
3. Review the troubleshooting section above
4. Open an issue at https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
5. Contact NV Digital Solutions at hello@nvdigitalsolutions.com
