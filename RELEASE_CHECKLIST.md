# WP oOS Release Checklist

This document outlines the complete process for releasing Open Operator System (WP oOS) to GitHub and the WordPress.org Plugin Directory.

## Table of Contents

1. [Pre-Release Preparation](#pre-release-preparation)
2. [GitHub Release](#github-release)
3. [WordPress.org Submission](#wordpressorg-submission)
4. [Post-Release Tasks](#post-release-tasks)

---

## Pre-Release Preparation

### 1. Code Quality Checks

- [ ] Run PHP linting: `composer run lint`
- [ ] Run PHP compatibility check: `composer run lint:compat`
- [ ] Run JavaScript linting: `npm run lint:js`
- [ ] Run all tests: `composer run test && npm test`
- [ ] Check for security vulnerabilities in dependencies

```bash
# Run all quality checks
composer run lint
composer run lint:compat
npm run lint:js
composer run test
npm test
```

### 2. Update Version Numbers

Update the version number in these files:

- [ ] `mcp-ai-wpoos.php` (Plugin header: `Version:` and `WP_MCP_AI_VERSION` constant)
- [ ] `readme.txt` (`Stable tag:` field)
- [ ] `package.json` (if applicable)

**Note**: `mcp-ai-wpoos-base.php` does NOT have a version in the repository - the build script adds it dynamically when creating the WordPress.org base version.

### 3. Update Changelog

- [ ] Add release notes to `CHANGELOG.md`
- [ ] Add release notes to `readme.txt` (under `== Changelog ==`)
- [ ] Include:
  - New features
  - Bug fixes
  - Breaking changes
  - Security fixes
  - Deprecations

### 4. Documentation Review

- [ ] Update `README.md` if features changed
- [ ] Update relevant docs in `/docs/` directory
- [ ] Check all documentation links work
- [ ] Update screenshot descriptions if UI changed

### 5. Build Production Assets

```bash
# Install dependencies
npm ci
composer install --no-dev

# Build minified assets
npm run build

# Verify minified files exist
ls -la assets/css/*.min.css
ls -la assets/js/*.min.js
```

### 6. Final Testing

- [ ] Test fresh installation on clean WordPress
- [ ] Test upgrade from previous version
- [ ] Test with SCRIPT_DEBUG enabled and disabled
- [ ] Test shortcode functionality
- [ ] Test REST API endpoints
- [ ] Test with WP Multisite
- [ ] Test with PHP 7.4, 8.0, 8.1, 8.2, 8.3

---

## GitHub Release

### Automatic Release (Recommended)

The GitHub Actions workflow handles releases automatically when you push a tag:

```bash
# Ensure all changes are committed
git add .
git commit -m "Prepare release v1.0.0"
git push origin main

# Create and push version tag
git tag v1.0.0
git push origin v1.0.0
```

The workflow will:
1. Build production assets
2. Create plugin ZIP
3. Create GitHub release with changelog

### Manual Release

If needed, create a release manually:

1. Go to [GitHub Releases](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases)
2. Click "Draft a new release"
3. Create a new tag (e.g., `v1.0.0`)
4. Add release title and notes
5. Upload the plugin ZIP file
6. Publish release

---

## WordPress.org Submission

### First-Time Submission

#### Step 1: Register on WordPress.org

- [ ] Create account at [wordpress.org/register](https://wordpress.org/register/)
- [ ] Confirm email address
- [ ] Log in to [wordpress.org](https://wordpress.org/)

#### Step 2: Submit Plugin for Review

1. Go to [Add Your Plugin](https://wordpress.org/plugins/developers/add/)
2. Upload your plugin ZIP file
3. Agree to guidelines
4. Submit for review

**Review Timeline:** Typically 1-10 business days

#### Step 3: Set Up SVN Access

After approval, you'll receive:
- SVN repository URL: `https://plugins.svn.wordpress.org/mcp-ai-wpoos/`
- Commit access with your wordpress.org credentials

#### Step 4: Initial SVN Setup

```bash
# Check out the empty repository
svn checkout https://plugins.svn.wordpress.org/mcp-ai-wpoos/ svn-mcp-ai-wpoos

cd svn-mcp-ai-wpoos

# Repository structure
# /trunk/     - Development version
# /tags/      - Tagged releases
# /assets/    - Plugin directory assets (banner, icon, screenshots)
```

### Deploying Updates

#### Option A: Manual SVN Deployment

```bash
# Navigate to your SVN checkout
cd svn-mcp-ai-wpoos

# Update trunk with latest plugin files
rm -rf trunk/*
cp -R /path/to/plugin/* trunk/

# Remove dev files from trunk
rm -rf trunk/.git trunk/node_modules trunk/tests trunk/.github

# Add new files
svn add --force trunk/*

# Create version tag
svn cp trunk tags/1.0.0

# Commit changes
svn commit -m "Release version 1.0.0"
```

#### Option B: Automated SVN Deployment

Uncomment the `deploy-wporg` job in `.github/workflows/release.yml` and add secrets:

1. Go to repository Settings → Secrets and variables → Actions
2. Add secrets:
   - `SVN_USERNAME`: Your wordpress.org username
   - `SVN_PASSWORD`: Your wordpress.org password

### Deploying Assets

Plugin assets (banner, icon, screenshots) are stored in `/assets/`:

```bash
cd svn-mcp-ai-wpoos

# Copy asset files
cp /path/to/.wordpress-org/*.png assets/

# Add and commit
svn add assets/* --force
svn commit -m "Update plugin assets"
```

---

## Post-Release Tasks

### 1. Announce the Release

- [ ] Blog post on company website
- [ ] Social media announcements
- [ ] Email newsletter to subscribers
- [ ] Update any documentation sites

### 2. Monitor Feedback

- [ ] Watch GitHub issues for bug reports
- [ ] Monitor WordPress.org support forums
- [ ] Track error logs if applicable

### 3. Update to Development Version

After a successful release, immediately update the version for continued development:

```bash
# Determine next version based on planned changes
# - New features planned: bump MINOR (e.g., 1.0.0 → 1.1.0-dev)
# - Only bug fixes planned: bump PATCH (e.g., 1.0.0 → 1.0.1-dev)
# - Breaking changes planned: bump MAJOR (e.g., 1.0.0 → 2.0.0-dev)

# Update version in these files to X.Y.Z-dev:
# - mcp-ai-wpoos.php (Version header + WP_MCP_AI_VERSION constant)
# - readme.txt (Stable tag)
# - package.json (version field)
# Note: mcp-ai-wpoos-base.php does not have a version in the repository

# Commit the version update
git add mcp-ai-wpoos.php readme.txt package.json
git commit -m "Bump version to X.Y.Z-dev for continued development"
git push origin main
```

### 4. Update Development Branch

```bash
# Merge release back to develop (if using Git Flow)
git checkout develop
git merge main
git push origin develop
```

### 5. Plan Next Release

- [ ] Review backlog for next version
- [ ] Prioritize bug fixes and features
- [ ] Update project roadmap

---

## Version Numbering

WP oOS follows [Semantic Versioning](https://semver.org/):

- **MAJOR.MINOR.PATCH** (e.g., 1.2.3)
- **MAJOR**: Breaking changes
- **MINOR**: New features (backward compatible)
- **PATCH**: Bug fixes (backward compatible)

Pre-release versions:
- `1.0.0-alpha.1`
- `1.0.0-beta.1`
- `1.0.0-rc.1`

### Development Version Management

To clearly indicate the development state of the repository:

**After a release is published:**
1. Immediately update the version to the next planned version with `-dev` suffix
2. This prevents confusion when developers clone the repository
3. Example: After releasing `1.0.0`, update to `1.1.0-dev` (for new features) or `1.0.1-dev` (for bug fixes only)

**Determining the next version:**
- Review the `[Unreleased]` section in CHANGELOG.md
- If there are new features → bump MINOR version (e.g., `1.0.0` → `1.1.0-dev`)
- If only bug fixes → bump PATCH version (e.g., `1.0.0` → `1.0.1-dev`)
- If breaking changes → bump MAJOR version (e.g., `1.0.0` → `2.0.0-dev`)

**When preparing for release:**
1. Remove the `-dev` suffix from all version strings
2. Move unreleased changes in CHANGELOG.md to a new version section
3. Follow the complete release process above

This ensures that anyone cloning the repository sees a development version number that accurately reflects the repository state.

---

## WordPress.org Guidelines Compliance

Ensure your plugin follows [WordPress.org guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/):

- [ ] No phone-home or tracking without consent
- [ ] No affiliate links without disclosure
- [ ] All code GPL-compatible
- [ ] No obfuscated code
- [ ] No cryptocurrency miners
- [ ] No sponsored links
- [ ] Clear, accurate description
- [ ] Working support contact

---

## Troubleshooting

### SVN Issues

```bash
# Check SVN status
svn status

# Resolve conflicts
svn resolve --accept working filename

# Revert changes
svn revert filename
```

### Common Problems

1. **"403 Forbidden" on SVN commit**
   - Check your credentials
   - Ensure you have commit access

2. **Assets not appearing on wordpress.org**
   - Assets can take up to 24 hours to appear
   - Verify correct file names and sizes
   - Check SVN assets directory structure

3. **ZIP file rejected**
   - Check file size limits
   - Remove unnecessary files
   - Ensure proper folder structure

---

## Resources

- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [Plugin Assets (Banners, Icons)](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/)
- [Using SVN](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)
- [10up Plugin Deploy Action](https://github.com/10up/action-wordpress-plugin-deploy)

---

## Quick Reference

```bash
# Pre-release checks
composer run lint && npm run lint:js && composer run test && npm test

# Build for release
npm run build
composer install --no-dev

# Create release tag
git tag v1.0.0 && git push origin v1.0.0

# Manual SVN deploy
svn commit -m "Release version 1.0.0"
```

---

*Last updated: December 2025*
