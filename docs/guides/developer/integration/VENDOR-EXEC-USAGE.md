# Vendor Dependency Exec Usage - WordPress.org Submission Notes

## Summary for WordPress.org Plugin Review Team

This document addresses exec/shell_exec usage in vendor dependencies shipped with the plugin.

## Vendor Packages with Exec Calls

The following production dependencies contain exec/shell_exec/proc_open calls, but **these are NOT used by the plugin**:

### Symfony Components

**Package**: `symfony/http-client` (HTTP client library)
- **Files**: `CurlHttpClient.php`
- **Usage**: Internal cURL process management (not used by our code)
- **Our Usage**: We use the PSR-18 HTTP client interface only

**Package**: `symfony/filesystem` (File operations)
- **Files**: `Filesystem.php`  
- **Usage**: Optional chmod/chown operations on Unix systems (not called by our code)
- **Our Usage**: We use basic file read/write methods only

**Package**: `symfony/cache` (Caching library)
- **Files**: Redis proxy classes, PDO adapters
- **Usage**: Database/cache connection management (not used by our code)
- **Our Usage**: Plugin uses WordPress transient API for caching, not Symfony cache

### Other Dependencies

**Package**: `php-http/discovery` (HTTP client discovery)
- **Files**: `ClassDiscovery.php`
- **Usage**: Internal class loading optimization (not used by our code)
- **Our Usage**: Dependency pulled in by http-client, not directly used

## Plugin's Actual Usage

The plugin **DOES NOT**:
- Call exec, shell_exec, proc_open, popen, system, or passthru in core code
- Use the exec-containing vendor code paths
- Execute shell commands in the WordPress.org version

All shell execution features have been moved to the separately-distributed Pro addon:
- Video processing (ffmpeg)
- Audio generation (Python/Jukebox)
- CLI tools (WP-CLI)
- Performance testing (PHPUnit)

## Production Dependencies (Actually Used)

1. **rahul900day/tiktoken-php** - Token counting for AI models (no exec)
2. **symfony/http-client** - HTTP requests to AI APIs (PSR-18 interface, no exec)
3. **nyholm/psr7** - PSR-7 HTTP message implementation (no exec)

## Verification

To verify no exec usage in plugin code:

```bash
# Check core includes directory (returns 0)
grep -rn "\bexec\s*(\|\bshell_exec\s*(\|\bproc_open\s*(" --include="*.php" includes/

# Check main plugin files
php -l mcp-ai-wpoos.php mcp-ai-wpoos-base.php
```

## Commitment

- No shell execution in WordPress.org distribution
- All shell-based features in separate Pro addon
- Vendor exec calls are unused code paths
- Plugin tested and functional without exec functions

## Contact

For questions about this submission:
- Email: support@nvdigitalsolutions.com
- GitHub: https://github.com/nvdigitalsolutions/mcp-ai-wpoos
