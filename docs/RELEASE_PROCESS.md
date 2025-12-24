# Release Process

**Repository:** nvdigitalsolutions/mcp-ai-wpoos  
**Version:** 1.0  
**Last Updated:** December 24, 2025

---

## Overview

This document outlines the complete release process for WP oOS plugin, from planning through post-release activities. Following this process ensures consistent, high-quality releases.

## Release Types

### Patch Release (v1.0.x)
- **Purpose:** Bug fixes, security patches, minor updates
- **Frequency:** As needed (1-2 per month typically)
- **Process Time:** 3-5 days
- **Testing Period:** 1 week minimum

### Minor Release (v1.x.0)
- **Purpose:** New features, enhancements, backward-compatible changes
- **Frequency:** Every 6-8 weeks
- **Process Time:** 1-2 weeks
- **Testing Period:** 2 weeks minimum

### Major Release (vX.0.0)
- **Purpose:** Breaking changes, major features, architecture changes
- **Frequency:** Every 6-12 months
- **Process Time:** 3-4 weeks
- **Testing Period:** 4 weeks minimum

---

## Pre-Release Phase

### 1. Planning (2-4 weeks before)

**Milestone Review:**
- [ ] Review milestone completion status
- [ ] Ensure all critical/high priority issues are closed
- [ ] Move incomplete low-priority issues to next milestone
- [ ] Document reasons for any scope changes

**Release Manager Assignment:**
- [ ] Assign release manager for this version
- [ ] Create release tracking issue
- [ ] Set target release date
- [ ] Schedule release meeting

**Communication:**
- [ ] Announce upcoming release in team channels
- [ ] Request testing help from community (if beta)
- [ ] Update roadmap document

---

### 2. Code Freeze (1 week before)

**Feature Complete:**
- [ ] All features merged to main branch
- [ ] No new features accepted
- [ ] Only bug fixes and documentation updates allowed

**Version Preparation:**
- [ ] Create release branch: `release/v1.x.x`
- [ ] Update version numbers in all locations:
  - [ ] `mcp-ai-wpoos.php` (Plugin header)
  - [ ] `mcp-ai-wpoos.php` (WP_MCP_AI_VERSION constant)
  - [ ] `readme.txt` (Stable tag)
  - [ ] `package.json` (version field)
- [ ] Run version verification script

```bash
# Verify all version numbers match
grep "Version:" mcp-ai-wpoos.php
grep "WP_MCP_AI_VERSION" mcp-ai-wpoos.php | head -1
grep "Stable tag:" readme.txt
grep '"version"' package.json
```

---

### 3. Pre-Release Checklist

**Code Quality:**
- [ ] All CI/CD checks passing
  - [ ] PHPUnit tests: `composer run test`
  - [ ] PHPCS: `composer run lint`
  - [ ] PHP Compatibility: `composer run lint:compat`
  - [ ] ESLint: `npm run lint:js`
  - [ ] JavaScript tests: `npm test`
- [ ] No PHPCS errors (warnings acceptable if documented)
- [ ] No failing tests
- [ ] Code coverage ≥ 70%

**Security:**
- [ ] CodeQL scan clean (no new vulnerabilities)
- [ ] Security audit completed
- [ ] Dependencies updated (run Dependabot)
- [ ] No known security issues
- [ ] Review `SECURITY.md` - update if needed

**Documentation:**
- [ ] CHANGELOG.md updated with all changes
  - [ ] New features documented
  - [ ] Bug fixes listed
  - [ ] Breaking changes (if major) highlighted
  - [ ] Migration guide (if major) included
- [ ] README.md updated (if needed)
- [ ] API documentation updated
- [ ] Tool reference updated (if new tools added)
- [ ] Screenshots updated (if UI changed)

**Translation:**
- [ ] Translation files generated: `composer run pot`
- [ ] POT file committed
- [ ] Language files in `languages/` directory

**Build:**
- [ ] Frontend assets built: `npm run build`
- [ ] Production Composer dependencies installed:
  ```bash
  composer install --no-dev --prefer-dist --optimize-autoloader
  ```
- [ ] Test local build
- [ ] Verify plugin activates without errors
- [ ] Test core functionality

---

### 4. Release Candidate (Optional - for Major/Minor)

**Create RC:**
- [ ] Tag RC version: `git tag -a v1.x.x-rc.1 -m "Release candidate 1"`
- [ ] Push tag: `git push origin v1.x.x-rc.1`
- [ ] Build RC ZIP file
- [ ] Upload to test server

**Testing Period:**
- [ ] Internal testing (2-5 days)
- [ ] Beta tester feedback (if applicable)
- [ ] Fix critical bugs found
- [ ] Create additional RC if needed (rc.2, rc.3)

**RC Announcement:**
```markdown
## Release Candidate Available: v1.x.x-rc.1

We're preparing to release v1.x.x and would appreciate your help testing!

**Download:** [Link to RC ZIP]

**What's New:**
- Feature 1
- Feature 2
- Bug fix 1

**How to Test:**
1. Install on test site (NOT production)
2. Test new features
3. Check for regressions
4. Report issues: [Link]

**Testing Period:** Dec 15-22
**Expected Release:** Dec 24

Thank you for helping make WP oOS better! 🚀
```

---

## Release Phase

### 5. Create Release

**Tag Release:**
```bash
# Ensure you're on release branch
git checkout release/v1.x.x

# Create annotated tag
git tag -a v1.x.x -m "Release version 1.x.x"

# Push tag (triggers GitHub Actions release workflow)
git push origin v1.x.x
```

**GitHub Actions Will:**
1. Run all tests
2. Build production assets
3. Create production ZIP file
4. Create GitHub Release
5. Deploy to WordPress.org (if configured)

**Monitor Release Workflow:**
- [ ] Watch GitHub Actions workflow
- [ ] Verify all jobs succeed
- [ ] Check release artifacts

---

### 6. GitHub Release

The release workflow creates the GitHub release automatically, but verify:

**Release Notes:**
- [ ] Title: "WP Open Operator System v1.x.x"
- [ ] Changelog excerpt included
- [ ] Installation instructions present
- [ ] Documentation links included
- [ ] Download link to plugin ZIP

**Release Assets:**
- [ ] ZIP file attached: `wp-mcp-ai-1.x.x.zip`
- [ ] File size reasonable (typically 2-5 MB)
- [ ] Can download and extract successfully

---

### 7. WordPress.org Deployment

**If SVN credentials configured:**
- [ ] Release workflow deploys automatically
- [ ] Verify plugin page: https://wordpress.org/plugins/wp-mcp-ai/
- [ ] Check version number updated
- [ ] Verify changelog appears
- [ ] Test download from WP.org
- [ ] Verify screenshots (if updated)

**If manual deployment needed:**
```bash
# Checkout SVN repo
svn co https://plugins.svn.wordpress.org/wp-mcp-ai/ svn-repo

# Copy files to trunk
cd svn-repo
cp -r /path/to/plugin/* trunk/

# Copy to tags
cp -r trunk tags/1.x.x

# Commit
svn add tags/1.x.x
svn ci -m "Release version 1.x.x"
```

---

### 8. Post-Release Verification

**Immediate Checks (within 1 hour):**
- [ ] Plugin installs successfully from WP.org
- [ ] Activation works without errors
- [ ] Settings page loads
- [ ] Core functionality works
- [ ] No fatal errors in debug.log

**WordPress.org Checks:**
- [ ] Plugin page loads correctly
- [ ] Screenshots display properly
- [ ] Changelog formatted correctly
- [ ] Download count incrementing
- [ ] No immediate bad reviews

**GitHub Checks:**
- [ ] Release published successfully
- [ ] Downloads counting
- [ ] No immediate bug reports

---

## Post-Release Phase

### 9. Merge and Cleanup

**Merge Release Branch:**
```bash
# Merge release branch back to main
git checkout main
git merge --no-ff release/v1.x.x -m "Merge release v1.x.x"
git push origin main

# Delete release branch
git branch -d release/v1.x.x
git push origin --delete release/v1.x.x
```

**Update Documentation:**
- [ ] Mark milestone as complete
- [ ] Close all milestone issues
- [ ] Update roadmap document
- [ ] Archive release notes

---

### 10. Announcements

**Internal:**
- [ ] Announce in team channels
- [ ] Thank contributors
- [ ] Celebrate! 🎉

**External (for Minor/Major):**
- [ ] Blog post (if applicable)
- [ ] Social media announcement
- [ ] Email newsletter (if applicable)
- [ ] Update website

**Announcement Template:**
```markdown
## WP oOS v1.x.x Released! 🚀

We're excited to announce the release of WP Open Operator System v1.x.x!

**What's New:**
- ✨ Feature 1 - [Description]
- ✨ Feature 2 - [Description]
- 🐛 Bug Fix 1
- 🐛 Bug Fix 2

**How to Update:**
- **From WordPress.org:** Updates will appear in your dashboard
- **From GitHub:** Download from [Releases](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases)

**Documentation:**
- [What's New Guide](link)
- [Upgrade Guide](link) (if breaking changes)
- [Full Changelog](link)

**Thank You:**
Special thanks to our contributors: @user1, @user2, @user3

**Links:**
- Download: https://wordpress.org/plugins/wp-mcp-ai/
- GitHub: https://github.com/nvdigitalsolutions/mcp-ai-wpoos
- Docs: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs

Questions? Open an issue or discussion on GitHub!
```

---

### 11. Monitoring (First 48 Hours)

**Watch For:**
- [ ] Bug reports in GitHub Issues
- [ ] Support requests
- [ ] WordPress.org reviews (negative)
- [ ] Error reports in logging system
- [ ] Social media mentions

**Hotfix Protocol:**
If critical bug discovered:
1. Create hotfix branch from tag: `hotfix/v1.x.x`
2. Fix bug
3. Test fix
4. Release patch version immediately: v1.x.(x+1)
5. Follow expedited release process

---

### 12. Planning Next Release

**Create Next Milestone:**
- [ ] Create next patch milestone (if needed)
- [ ] Create next minor milestone
- [ ] Set tentative due dates
- [ ] Move unprioritized issues to new milestone

**Retrospective:**
- [ ] What went well?
- [ ] What could be improved?
- [ ] Any process changes needed?
- [ ] Update this document if needed

---

## Version Numbering

### Semantic Versioning

We follow [Semantic Versioning 2.0.0](https://semver.org/):

```
MAJOR.MINOR.PATCH

MAJOR: Breaking changes, incompatible API changes
MINOR: New features, backward-compatible
PATCH: Bug fixes, backward-compatible
```

**Examples:**
- `1.0.0` → `1.0.1` (patch: bug fixes)
- `1.0.1` → `1.1.0` (minor: new features)
- `1.9.5` → `2.0.0` (major: breaking changes)

### Pre-Release Versions

**Beta:**
- `1.1.0-beta.1` - First beta
- `1.1.0-beta.2` - Second beta

**Release Candidate:**
- `1.1.0-rc.1` - First RC
- `1.1.0-rc.2` - Second RC

**Alpha (if needed):**
- `2.0.0-alpha.1` - First alpha

---

## Files to Update

### Version Numbers

| File | Location | Format |
|------|----------|--------|
| Plugin Header | `mcp-ai-wpoos.php` line ~8 | `Version: 1.x.x` |
| Version Constant | `mcp-ai-wpoos.php` line ~50 | `define( 'WP_MCP_AI_VERSION', '1.x.x' );` |
| Readme Stable Tag | `readme.txt` line ~6 | `Stable tag: 1.x.x` |
| Package.json | `package.json` line ~3 | `"version": "1.x.x"` |

### Verification Script

```bash
#!/bin/bash
# verify-versions.sh
# Verifies all version numbers match

VERSION=$1

if [ -z "$VERSION" ]; then
    echo "Usage: ./verify-versions.sh 1.x.x"
    exit 1
fi

echo "Checking version $VERSION..."

# Check plugin header
if grep -q "Version: $VERSION" mcp-ai-wpoos.php; then
    echo "✅ Plugin header version correct"
else
    echo "❌ Plugin header version mismatch"
    exit 1
fi

# Check constant
if grep -q "WP_MCP_AI_VERSION', '$VERSION'" mcp-ai-wpoos.php; then
    echo "✅ Version constant correct"
else
    echo "❌ Version constant mismatch"
    exit 1
fi

# Check readme
if grep -q "Stable tag: $VERSION" readme.txt; then
    echo "✅ Readme stable tag correct"
else
    echo "❌ Readme stable tag mismatch"
    exit 1
fi

# Check package.json
if grep -q "\"version\": \"$VERSION\"" package.json; then
    echo "✅ Package.json version correct"
else
    echo "❌ Package.json version mismatch"
    exit 1
fi

echo ""
echo "✅ All version numbers match!"
```

---

## Emergency Hotfix Process

**For Critical Bugs Only:**

1. **Assess Severity:**
   - Is it a security issue?
   - Does it cause data loss?
   - Does it break core functionality?

2. **Create Hotfix Branch:**
   ```bash
   git checkout -b hotfix/v1.x.x v1.x.x
   ```

3. **Fix Bug:**
   - Minimal changes only
   - Focus on the specific issue
   - Add test to prevent regression

4. **Expedited Testing:**
   - Test fix thoroughly
   - Run critical tests only
   - Skip non-essential checks

5. **Release:**
   - Bump patch version
   - Tag: v1.x.(x+1)
   - Deploy immediately
   - Skip RC phase

6. **Communication:**
   - Notify users immediately
   - Explain issue and fix
   - Provide update instructions

---

## Rollback Procedure

**If release has critical issues:**

1. **Assess Impact:**
   - How many users affected?
   - Can workaround be provided?
   - Is hotfix possible?

2. **WordPress.org:**
   - Cannot rollback (users on various versions)
   - Release hotfix as soon as possible
   - Update plugin page with warning if needed

3. **GitHub:**
   - Can unpublish release (not recommended)
   - Better: Release hotfix with higher version
   - Add warning to release notes

4. **Communication:**
   - Issue urgent advisory
   - Provide workaround if available
   - Set expectations for hotfix timeline

---

## Release Checklist Template

Copy this checklist for each release:

```markdown
## Release Checklist: v1.x.x

### Pre-Release
- [ ] Milestone reviewed and complete
- [ ] Release manager assigned
- [ ] Release date set
- [ ] Code freeze announced

### Version Updates
- [ ] Plugin header updated
- [ ] Version constant updated
- [ ] Readme stable tag updated
- [ ] Package.json updated
- [ ] Versions verified with script

### Quality Checks
- [ ] PHPUnit: All tests pass
- [ ] PHPCS: Zero errors
- [ ] PHP Compatibility: Passed
- [ ] ESLint: Passed
- [ ] JavaScript tests: Passed
- [ ] CodeQL: Clean

### Documentation
- [ ] CHANGELOG.md updated
- [ ] README.md updated (if needed)
- [ ] API docs updated (if needed)
- [ ] Screenshots updated (if needed)
- [ ] Translation files generated

### Build
- [ ] Frontend assets built
- [ ] Production composer installed
- [ ] Local test successful
- [ ] RC tested (if applicable)

### Release
- [ ] Tag created and pushed
- [ ] GitHub Actions succeeded
- [ ] GitHub Release verified
- [ ] WordPress.org deployed
- [ ] Download tested

### Post-Release
- [ ] Release branch merged
- [ ] Milestone closed
- [ ] Announcements sent
- [ ] Next milestone created
- [ ] Monitoring active

### 48-Hour Check
- [ ] No critical bugs reported
- [ ] No negative reviews
- [ ] Installation working
- [ ] Core functionality verified
```

---

## Useful Commands

```bash
# Create release branch
git checkout -b release/v1.x.x main

# Update all version numbers (use sed or manual)
./bin/bump-version.sh 1.x.x

# Verify versions
./bin/verify-versions.sh 1.x.x

# Run all tests
composer run test
composer run lint
composer run lint:compat
npm test

# Build assets
npm run build
composer install --no-dev --optimize-autoloader

# Create tag
git tag -a v1.x.x -m "Release version 1.x.x"
git push origin v1.x.x

# View tags
git tag -l

# Delete tag (if mistake)
git tag -d v1.x.x
git push origin :refs/tags/v1.x.x
```

---

## FAQ

**Q: How long should testing period be?**  
A: Minimum 1 week for patches, 2 weeks for minor, 4 weeks for major.

**Q: Can we skip RC for patches?**  
A: Yes, patches can skip RC if changes are minimal and well-tested.

**Q: What if CI fails during release?**  
A: Do not proceed. Fix issues first. Never release with failing tests.

**Q: How do we handle urgent security patches?**  
A: Use emergency hotfix process. Release as soon as possible after testing.

**Q: Should we announce every patch release?**  
A: Not necessarily. Major announcements for minor/major releases only.

**Q: What if WordPress.org deployment fails?**  
A: Contact WordPress.org support. Can deploy manually via SVN as backup.

---

## See Also

- [ROADMAP.md](ROADMAP.md) - Product roadmap
- [MILESTONE_STRATEGY.md](MILESTONE_STRATEGY.md) - Milestone management
- [CONTRIBUTING.md](../CONTRIBUTING.md) - Contributing guidelines
- [CHANGELOG.md](../CHANGELOG.md) - Change history
- `.github/workflows/release.yml` - Release automation

---

**Document Version:** 1.0  
**Last Updated:** December 24, 2025  
**Next Review:** After first release using this process
