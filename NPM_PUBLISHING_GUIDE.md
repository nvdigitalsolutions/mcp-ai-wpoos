# NPM Package Publishing Guide

This guide explains how to publish the NPM packages from this repository.

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

Before publishing, ensure:

1. **NPM Token Configured**
   - Go to repository Settings → Secrets and variables → Actions
   - Add a secret named `NPM_TOKEN`
   - Value should be your NPM access token from https://www.npmjs.com/settings/tokens
   - Token needs "Automation" or "Publish" permissions

2. **NPM Organization**
   - The `@nvdigitalsolutions` organization must exist on NPM
   - Or update package names in `package.json` files

## Publishing Methods

### Method 1: GitHub UI (Recommended)

This is the easiest method and allows dry-run testing.

1. Go to the repository on GitHub
2. Click on the **Actions** tab
3. Select **"Publish Alpha to NPM"** workflow from the left sidebar
4. Click **"Run workflow"** button (top right)
5. Fill in the form:
   - **Use workflow from**: Select your branch (e.g., `main` or your feature branch)
   - **Alpha version to publish**: Enter version like `0.1.0-alpha.2`
   - **Dry run**: Check this box to test without publishing
6. Click **"Run workflow"** to start

**Dry Run**: Always test with dry run first to verify the build works!

### Method 2: Git Tag

You can trigger the workflow by pushing a git tag:

```bash
# Create and push an alpha tag
git tag v0.1.0-alpha.2
git push origin v0.1.0-alpha.2
```

The workflow will automatically:
- Detect the version from the tag
- Update all package.json files
- Build all three packages
- Publish to NPM with `@alpha` tag

### Method 3: GitHub CLI

If you have GitHub CLI installed:

```bash
# Trigger workflow with specific version
gh workflow run npm-publish-alpha.yml \
  -f version=0.1.0-alpha.2 \
  -f dry_run=false

# Check workflow status
gh run list --workflow=npm-publish-alpha.yml
```

## What Happens During Publishing

1. **Version Update**: All package.json files are updated to the specified version
2. **Build**: Each package runs its build script (`npm run build`)
   - Creates `dist/` directory with compiled code
   - Generates TypeScript definitions
3. **Verify**: Packages are verified with `npm pack --dry-run`
4. **Publish**: Each package is published to NPM with `@alpha` tag
   - Published as public packages
   - Accessible via `npm install @nvdigitalsolutions/nvoos-storage@alpha`

## After Publishing

Once published, users can install the packages:

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

### Workflow Not Showing Up

If the workflow doesn't appear in GitHub Actions:
1. Ensure the workflow file exists: `.github/workflows/npm-publish-alpha.yml`
2. Ensure the file has valid YAML syntax
3. The workflow needs at least one successful run to appear in the UI
4. Try pushing to the main branch first

### NPM Publishing Fails

Common issues:
- **Authentication Error**: Verify NPM_TOKEN secret is set correctly
- **Package Name Conflict**: Package name might already exist on NPM
- **Permissions Error**: NPM token needs publish permissions
- **Organization Not Found**: `@nvdigitalsolutions` org must exist on NPM

### Build Fails

If the build step fails:
1. Check that all dependencies are listed in package.json
2. Verify the build script in each package.json works locally
3. Test locally: `cd packages/nvoos-storage && npm run build`

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

The workflow is configured in `.github/workflows/npm-publish-alpha.yml`

Key features:
- ✅ Automated version updates
- ✅ Build verification
- ✅ Dry run support
- ✅ Package content verification
- ✅ Public access by default
- ✅ Alpha tag for pre-release versions

## Support

For issues or questions:
- Repository: https://github.com/nvdigitalsolutions/mcp-ai-wpoos
- Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- NPM Packages: https://www.npmjs.com/org/nvdigitalsolutions
