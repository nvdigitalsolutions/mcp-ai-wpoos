# Repository Rename Guide: wp-mcp-ai → mcp-ai-wpoos

## Overview

This document outlines the repository rename from `nvdigitalsolutions/wp-mcp-ai` to `nvdigitalsolutions/mcp-ai-wpoos` to better align the repository name with the actual plugin name "Open Operator System (WP oOS)".

## Rationale

**Why rename from `wp-mcp-ai` to `mcp-ai-wpoos`?**

1. **Better alignment with plugin branding**: The plugin is branded as "WP oOS" (Open Operator System)
2. **Avoids WordPress-first naming**: Starting with `mcp-` instead of `wp-` emphasizes the MCP (Model Context Protocol) focus
3. **More memorable**: `mcp-ai-wpoos` is more distinctive and easier to associate with the product name
4. **Future-proof**: Aligns with the plugin's evolution beyond just WordPress integration

## Pre-Rename Preparation (Completed)

All repository references have been updated in commit `cbab909`:

- ✅ **57 files updated**
- ✅ **89 total replacements**
- ✅ All documentation updated
- ✅ GitHub workflows updated
- ✅ Issue templates updated
- ✅ Badges and links updated

### Files Updated

#### Main Documentation
- `README.md` - GitHub badges, issue links, repository links
- `readme.txt` - WordPress.org readme file
- `BUILD.md` - Build instructions
- `RELEASE_CHECKLIST.md` - Release process documentation
- `SECURITY.md` - Security policy

#### GitHub Configuration
- `.github/workflows/release.yml` - Release workflow
- `.github/ISSUE_TEMPLATE/config.yml` - Issue templates
- `.github/copilot-instructions.md` - Copilot instructions

#### Documentation (67+ files)
- All files in `docs/` directory
- All files in `docs/archive/` directory
- `includes/admin/README-SETTINGS-DASHBOARD.md`
- `core/README.md`
- `.wordpress-org/README.md`

## Performing the Rename on GitHub

When you're ready to rename the repository on GitHub:

1. **Navigate to Settings**
   - Go to https://github.com/nvdigitalsolutions/wp-mcp-ai
   - Click "Settings" tab
   - Scroll to "Repository name"

2. **Rename the Repository**
   - Change from: `wp-mcp-ai`
   - Change to: `mcp-ai-wpoos`
   - Click "Rename"

3. **GitHub Will Automatically**
   - Set up redirects from old URL to new URL
   - Update all issue and PR references
   - Preserve all stars, forks, and watchers
   - Maintain all commit history

## Post-Rename Actions

### For Repository Maintainers

1. **Update Local Git Remotes**
   ```bash
   git remote set-url origin https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
   ```

2. **Update CI/CD Pipelines**
   - Check any external CI/CD systems
   - Update deployment scripts if needed

3. **Update External References**
   - Package registries (if applicable)
   - Third-party documentation
   - Social media links
   - Marketing materials

### For Contributors

1. **Update Local Remotes**
   ```bash
   cd /path/to/wp-mcp-ai
   git remote set-url origin https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
   git remote -v  # Verify the change
   ```

2. **Pull Latest Changes**
   ```bash
   git pull origin main
   ```

3. **Update Fork (if applicable)**
   - GitHub will automatically redirect your fork
   - Or manually update fork's upstream remote

## GitHub's Automatic Redirects

GitHub provides automatic redirects for:

✅ **Repository URL**: `github.com/nvdigitalsolutions/wp-mcp-ai` → `github.com/nvdigitalsolutions/mcp-ai-wpoos`
✅ **Git Clone URLs**: Both HTTPS and SSH will redirect
✅ **API Endpoints**: GitHub API calls will redirect
✅ **Badge URLs**: GitHub Actions badges will redirect
✅ **Issue/PR Links**: All issue and PR URLs will redirect

⚠️ **Note**: While redirects work, it's best practice to update to the new URL to avoid confusion.

## Breaking Changes (None Expected)

The rename should not break:

- ✅ Git clone operations (redirects)
- ✅ GitHub Actions workflows (updated in code)
- ✅ Issue and PR references (GitHub handles automatically)
- ✅ External documentation links (redirects)
- ✅ Forks and pull requests (GitHub handles automatically)

## Files That Do NOT Need Changing

The following remain unchanged (intentionally):

- **Plugin file names**: `mcp-ai-wpoos.php` (main plugin file)
- **Text domain**: `mcp-ai-wpoos` (translation domain)
- **Package name**: `@package WP_MCP_AI` (PHPDoc metadata)
- **Constants**: `WP_MCP_AI_*` constants (PHP constants)
- **Function prefixes**: `wp_mcp_ai_*` (PHP function names)
- **Class names**: `WP_MCP_AI_*` (PHP class names)

These internal identifiers don't need to match the repository name and changing them would break backward compatibility.

## Verification Checklist

After the rename, verify:

- [ ] Repository accessible at new URL
- [ ] Old URL redirects to new URL
- [ ] GitHub Actions workflows run successfully
- [ ] Badges display correctly on README
- [ ] Issue and PR links work
- [ ] Contributors can clone/pull
- [ ] Forks can sync with upstream

## Timeline

- **Preparation**: December 7, 2024 - All references updated in commit `cbab909`
- **Rename**: [To be scheduled by repository owner]
- **Verification**: [After rename completion]

## Rollback Plan

If issues arise after rename:

1. GitHub allows renaming back to the original name
2. All redirects will work in reverse
3. Repository history is preserved
4. Can rename back at any time if needed

However, this is unlikely to be needed given GitHub's robust redirect system.

## Contact

For questions about this rename:

- **GitHub Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues (will work after rename)
- **Current Issues**: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues (redirects to new URL)

## References

- [GitHub Documentation: Renaming a Repository](https://docs.github.com/en/repositories/creating-and-managing-repositories/renaming-a-repository)
- [GitHub Blog: Repository Redirects](https://github.blog/2013-05-16-repository-redirects-are-here/)

## Conclusion

This repository rename is a straightforward process with minimal risk thanks to:

1. ✅ All internal references already updated
2. ✅ GitHub's automatic redirect system
3. ✅ No breaking changes to plugin internals
4. ✅ Backward compatibility maintained

The repository is ready to be renamed whenever the owner decides to proceed.
