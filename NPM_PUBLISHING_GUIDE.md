# Package Publishing Guide

This guide explains how to publish the NPM packages from this repository to **both NPM and GitHub Packages**.

## Dual Publishing Strategy

These public packages are published to **two registries** for maximum reach:

1. **NPM Registry** - Public, no authentication needed for consumers
2. **GitHub Packages** - Integrated with repository, backup distribution

Both workflows are triggered automatically by the same git tags.

## Available Packages

The repository contains 3 NPM packages in the `packages/` directory:

1. **@nvdigitalsolutions/nvoos-storage** (v0.1.0-alpha.1)
   - Async storage utilities with Web Worker optimization
   - Located: `packages/nvoos-storage/`

2. **@nvdigitalsolutions/nvoos-markdown** (v0.1.0-alpha.1)
   - Security-hardened markdown renderer with XSS protection
   - Located: `packages/nvoos-markdown/`

3. **@nvdigitalsolutions/nvoos-events** (v0.1.0-alpha.1)
   - Real-time event coordination with SSE client and job event bus
   - Located: `packages/nvoos-events/`

## Prerequisites

### For NPM Publishing

1. **NPM Automation Token Required** (⚠️ IMPORTANT)
   - Go to https://www.npmjs.com/settings/tokens
   - Click "Generate New Token" → Select **"Automation"** token type
   - **DO NOT use "Publish" or "Granular Access Token"** - these require 2FA/OTP which won't work in CI/CD
   - Copy the token (shown only once)
   - Go to repository Settings → Secrets and variables → Actions
   - Add a secret named `NPM_TOKEN`
   - Paste the Automation token as the value

2. **NPM Organization**
   - The `@nvdigitalsolutions` organization must exist on NPM
   - Or update package names in `package.json` files

### For GitHub Packages Publishing

1. **GitHub Packages Permissions**
   - The workflow uses `GITHUB_TOKEN` which is automatically provided by GitHub Actions
   - No additional secrets need to be configured
   - The repository must have packages write permissions (already configured in workflow)

## Publishing Methods

### Method 1: Git Tag (Recommended - Triggers Both)

This is the simplest method and triggers **both** NPM and GitHub Packages workflows simultaneously:

```bash
# Create and push an alpha tag
git tag v0.1.0-alpha.2
git push origin v0.1.0-alpha.2
```

**Both workflows trigger automatically:**
- ✅ NPM workflow publishes to NPM registry
- ✅ GitHub Packages workflow publishes to GitHub Packages

### Method 2: GitHub UI (Manual Trigger)

You can manually trigger either workflow for testing:

**For NPM Publishing:**
1. Go to the repository on GitHub
2. Click on the **Actions** tab
3. Select **"Publish Alpha to NPM"** workflow
4. Click **"Run workflow"** button (top right)
5. Fill in the form:
   - **Alpha version to publish**: Enter version like `0.1.0-alpha.2`
   - **Dry run**: Check this box to test without publishing
6. Click **"Run workflow"** to start

**For GitHub Packages Publishing:**
1. Follow same steps above
2. Select **"Publish Alpha to GitHub Packages"** workflow instead

**Dry Run**: Always test with dry run first to verify the build works!

### Method 3: GitHub CLI

If you have GitHub CLI installed:

```bash
# Trigger NPM workflow with specific version
gh workflow run npm-publish.yml \
  -f version=0.1.0-alpha.2 \
  -f dry_run=false

# Trigger GitHub Packages workflow
gh workflow run npm-publish-alpha.yml \
  -f version=0.1.0-alpha.2 \
  -f dry_run=false

# Check workflow status
gh run list --workflow=npm-publish.yml
gh run list --workflow=npm-publish-alpha.yml
```

## What Happens During Publishing

1. **Version Update**: All package.json files are updated to the specified version
2. **Build**: Each package runs its build script (`npm run build`)
   - Creates `dist/` directory with compiled code
   - Generates TypeScript definitions
3. **Verify**: Packages are verified with `npm pack --dry-run`
4. **Publish**: Each package is published with `@alpha` tag
   - **NPM workflow** → `https://registry.npmjs.org` (public)
   - **GitHub Packages workflow** → `https://npm.pkg.github.com`

## After Publishing

### Installing from NPM (Easiest)

No authentication needed - just install:

```bash
# Install latest alpha version
npm install @nvdigitalsolutions/nvoos-storage@alpha
npm install @nvdigitalsolutions/nvoos-markdown@alpha
npm install @nvdigitalsolutions/nvoos-events@alpha

# Install specific alpha version
npm install @nvdigitalsolutions/nvoos-storage@0.1.0-alpha.2
```

### Installing from GitHub Packages

Requires authentication setup:

#### Setup .npmrc

Create or update `.npmrc` file in your project:

```bash
@nvdigitalsolutions:registry=https://npm.pkg.github.com
```

#### Authenticate

You'll need a GitHub Personal Access Token with `read:packages` scope:

```bash
# Login to GitHub Packages
npm login --registry=https://npm.pkg.github.com

# Username: Your GitHub username
# Password: Your GitHub Personal Access Token
# Email: Your email
```

#### Install Packages

```bash
# Install latest alpha version
npm install @nvdigitalsolutions/nvoos-storage@alpha
npm install @nvdigitalsolutions/nvoos-markdown@alpha
npm install @nvdigitalsolutions/nvoos-events@alpha

# Install specific alpha version
npm install @nvdigitalsolutions/nvoos-storage@0.1.0-alpha.2
```

## Version Guidelines

Alpha versions should follow this format: `X.Y.Z-alpha.N`

Examples:
- `0.1.0-alpha.1` - First alpha release
- `0.1.0-alpha.2` - Second alpha release
- `0.2.0-alpha.1` - First alpha of v0.2.0

When ready for production:
- `0.1.0` - First stable release
- `0.2.0` - Minor version bump
- `1.0.0` - Major version

## Troubleshooting

### Workflows Not Showing Up

If workflows don't appear in GitHub Actions:
1. Ensure workflow files exist: 
   - `.github/workflows/npm-publish.yml` (NPM)
   - `.github/workflows/npm-publish-alpha.yml` (GitHub Packages)
2. Ensure files have valid YAML syntax
3. Workflows need at least one successful run to appear in the UI
4. Try pushing to the main branch first

### NPM Publishing Fails

Common issues:
- **OTP/2FA Error (`EOTP`)**: You're using the wrong token type
  - **Solution**: Use an **"Automation"** token, not "Publish" or "Granular Access Token"
  - Automation tokens bypass 2FA/OTP requirements for CI/CD
  - Generate at: https://www.npmjs.com/settings/tokens → "Automation" token type
  - Update the `NPM_TOKEN` secret in repository settings
- **Authentication Error**: Verify `NPM_TOKEN` secret is set correctly in repository settings
- **Package Name Conflict**: Package name might already exist on NPM
- **Permissions Error**: Ensure you used an "Automation" token with full publish permissions
- **Organization Not Found**: `@nvdigitalsolutions` org must exist on NPM

**📖 Full Troubleshooting Guide:** See [docs/npm-publishing-troubleshooting.md](docs/npm-publishing-troubleshooting.md) for detailed solutions

### GitHub Packages Publishing Fails

Common issues:
- **Permission Error**: Ensure workflow has `packages: write` permission (already configured)
- **Package Name Conflict**: Package name might already exist in GitHub Packages
- **Authentication Error**: GITHUB_TOKEN is automatically provided, no manual setup needed
- **Organization Scope**: Packages must be scoped to `@nvdigitalsolutions`

### Build Fails

If the build step fails:
1. Check that all dependencies are listed in package.json
2. Verify the build script in each package.json works locally
3. Test locally: `cd packages/nvoos-storage && npm run build`

### Installing from GitHub Packages Fails

If installation fails:
- Ensure `.npmrc` is configured with GitHub Packages registry for the scope
- Verify you're authenticated with a GitHub Personal Access Token
- Token must have `read:packages` scope
- Check package exists: Visit https://github.com/nvdigitalsolutions/mcp-ai-wpoos/packages

## Local Testing

Before publishing, test locally:

```bash
# Build packages locally
cd packages/nvoos-storage
npm run build
cd ../..

cd packages/nvoos-markdown
npm run build
cd ../..

cd packages/nvoos-events
npm run build
cd ../..

# Test package contents
cd packages/nvoos-storage
npm pack --dry-run
cd ../..
```

## Workflow Configuration

Two separate workflows handle publishing:

### NPM Workflow (`.github/workflows/npm-publish.yml`)

Key features:
- ✅ Publishes to public NPM registry
- ✅ Requires `NPM_TOKEN` secret
- ✅ Automated version updates
- ✅ Build verification
- ✅ Dry run support
- ✅ Package content verification
- ✅ Public access by default
- ✅ Alpha tag for pre-release versions

### GitHub Packages Workflow (`.github/workflows/npm-publish-alpha.yml`)

Key features:
- ✅ Publishes to GitHub Packages registry
- ✅ Uses automatic `GITHUB_TOKEN` (no setup needed)
- ✅ Automated version updates
- ✅ Build verification
- ✅ Dry run support
- ✅ Package content verification
- ✅ Alpha tag for pre-release versions
- ✅ Integrated repository permissions

## Support

For issues or questions:
- Repository: https://github.com/nvdigitalsolutions/mcp-ai-wpoos
- Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- NPM Packages: https://www.npmjs.com/org/nvdigitalsolutions
- GitHub Packages: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/packages
