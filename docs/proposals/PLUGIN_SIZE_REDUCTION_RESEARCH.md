# Plugin Size Reduction Research

**Date**: January 21, 2026  
**Current Base ZIP Size**: 11 MB (1,990 files)  
**Goal**: Research options to reduce base plugin ZIP size for faster downloads and WordPress.org distribution

## Implementation Plan (Approved)

### Phase 1: Exclude Vectorizer Library ⭐ PRIORITY

**Action**: Remove `assets/js/vendor/neplex-vectorizer` from base build

**Impact**: 
- **Size Reduction**: -2.5 MB compressed (9.1 MB → 3.5 MB in assets/js/vendor)
- **Risk**: Low - only affects single `vectorize_image` tool (rarely used)
- **User Impact**: Minimal - tool will prompt for download on first use

**Implementation**:
1. Update `bin/build-plugin-zip.sh` to exclude vectorizer from base ZIP
2. Add download-on-demand logic for `vectorize_image` tool
3. Create settings UI for optional extensions
4. Test graceful fallback when vectorizer not installed

**Benefits**:
✅ Immediate 2.5 MB reduction (23% of current size)  
✅ Users only download what they need  
✅ No breaking changes for existing users  
✅ Simple implementation with clear user communication  

---

## Executive Summary

The base plugin ZIP is currently 11MB. Analysis shows significant opportunities for size reduction through:
1. **Exclude vectorizer library** (2.5MB immediate reduction) ⭐ **APPROVED FOR IMPLEMENTATION**
2. **Optional downloads for knowledge base content** (1-5MB reduction potential)
3. **Removing unminified source files** (1-2MB reduction)
4. **Optimizing vendor dependencies** (1-2MB reduction)

**Conservative estimate**: 3-5MB achievable (55-73% reduction to 6-8MB)  
**Aggressive estimate**: 7-9MB achievable (82-91% reduction to 2-4MB)

---

## Current Size Breakdown

### Base Plugin ZIP: 11 MB (1,990 files)

| Directory | Size | Percentage | Description |
|-----------|------|------------|-------------|
| **includes/knowledge-base** | 7.6 MB | 69% | Profession playbooks and documents |
| └─ profession-playbooks | 6.4 MB | 58% | 218 profession playbook files (11.5MB uncompressed) |
| └─ profession-documents | 824 KB | 7% | Profession-specific documentation |
| **assets/js** | 12 MB | - | JavaScript files (compressed in ZIP) |
| └─ vendor (neplex-vectorizer) | 9.1 MB | 83% | Image vectorization library (native binaries) |
| └─ source files (.js) | 1.5 MB | 13% | Unminified JavaScript |
| └─ minified files (.min.js) | 600 KB | 5% | Production-ready files |
| **includes/admin** | 2.4 MB | 22% | Admin UI and dashboard code |
| **includes/tools** | 2.3 MB | 21% | Tool implementations |
| **vendor** | 4.6 MB | 42% | PHP dependencies (Symfony, PSR, etc.) |
| **assets/css** | 488 KB | 4% | Stylesheets |
| **Other** | ~1 MB | 9% | Core classes, examples, docs |

---

## Architectural Context

### New Requirement (January 21, 2026)
> **The base plugin now defaults to ALL tools, and you need Pro for advanced toolkits.**

This means:
- Base version = Full plugin with all standard tools
- Pro version = Advanced toolkits (e-commerce, social media, analytics, multilingual, video production, media)
- Base version should be optimized for WordPress.org distribution

### Current Loading Mechanism

**Constants**:
- `WP_MCP_AI_BASE_VERSION` (default: `true`) - Controls feature availability
- `WP_MCP_AI_PRO_VERSION` - Indicates Pro addon is active

**Tool Loading**:
1. Core tools (priority 10): Posts, Media, Users, Taxonomies
2. Integration tools (priority 15): JetEngine, WooCommerce, Elementor, etc.
3. Pro tools (priority 20): Advanced toolkits (when Pro is active)

**Pro Toolkits** (disabled by default, enabled via dashboard):
- E-Commerce Toolkit
- Social Media Management Toolkit
- Advanced Analytics Toolkit
- Multi-language Content Toolkit
- Video Production Toolkit
- Media Toolkit

---

## Size Reduction Options

### Option 1: Make Knowledge Base Optional/Downloadable ⭐ RECOMMENDED

**Impact**: 7.6 MB reduction (69% of base plugin)  
**Effort**: Medium  
**Risk**: Low

**Current State**:
- 218 profession playbooks (6.4MB)
- Profession documents (824KB)
- Total: 7.6MB included in base plugin

**Recommendation**:
1. **Move to separate download** - Create "Knowledge Base Pack" as optional download
2. **Download on-demand** - Fetch playbooks when profession is first selected
3. **CDN distribution** - Host on GitHub releases or CDN
4. **Minimal base set** - Include only top 10-20 most common professions in base plugin

**Implementation**:
```php
// Add settings option
add_option( 'wp_mcp_ai_knowledge_base_source', 'cdn' ); // Options: 'bundled', 'cdn', 'local'

// Download playbook when needed
function wp_mcp_ai_get_profession_playbook( $profession_slug ) {
    $local_path = WP_MCP_AI_PLUGIN_DIR . 'includes/knowledge-base/profession-playbooks/professions/' . $profession_slug . '.txt';
    
    if ( file_exists( $local_path ) ) {
        return file_get_contents( $local_path );
    }
    
    // Download from CDN
    $cdn_url = 'https://cdn.example.com/playbooks/' . $profession_slug . '.txt';
    $response = wp_remote_get( $cdn_url );
    
    if ( ! is_wp_error( $response ) ) {
        $content = wp_remote_retrieve_body( $response );
        // Cache locally
        file_put_contents( $local_path, $content );
        return $content;
    }
    
    return '';
}
```

**Benefits**:
- Massive size reduction (69% of current size)
- Users only download what they need
- Easier to update playbooks without plugin updates
- Better for WordPress.org submission

**Drawbacks**:
- Requires internet connection for first use
- Additional complexity in code
- Need to maintain CDN/download infrastructure

---

### Option 2: Make Vectorizer Library Optional ⭐ HIGHLY RECOMMENDED

**Impact**: 9.1 MB reduction (83% of assets/js)  
**Effort**: Medium  
**Risk**: Low

**Current State**:
- `neplex-vectorizer` library (9.1MB) included for `vectorize_image` tool
- Native binaries for different platforms (Linux, macOS, Windows)
- Only used when vectorize_image tool is called

**Recommendation**:
1. **Exclude from base build** - Don't include in base plugin ZIP
2. **Download on first use** - Install when tool is first accessed
3. **Pro-only feature** - Move vectorize_image to Pro addon
4. **Alternative**: Use server-side API instead of native binaries

**Implementation**:
```bash
# Update build-plugin-zip.sh to exclude vectorizer
--exclude 'assets/js/vendor/neplex-vectorizer' \
```

```php
// Download on demand
function wp_mcp_ai_ensure_vectorizer_installed() {
    $vectorizer_path = WP_MCP_AI_PLUGIN_DIR . 'assets/js/vendor/neplex-vectorizer';
    
    if ( file_exists( $vectorizer_path ) ) {
        return true;
    }
    
    // Download from GitHub releases
    $download_url = 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download/vectorizer/neplex-vectorizer.zip';
    $temp_file = download_url( $download_url );
    
    if ( ! is_wp_error( $temp_file ) ) {
        // Extract to vendor directory
        unzip_file( $temp_file, dirname( $vectorizer_path ) );
        unlink( $temp_file );
        return true;
    }
    
    return false;
}
```

**Benefits**:
- Huge size reduction
- Most users don't use vectorize_image tool
- Can still be available when needed
- Cleaner base installation

**Drawbacks**:
- Requires internet connection for first use
- Tool not immediately available
- Need to handle download failures gracefully

---

### Option 3: Remove Unminified Source Files ⭐ RECOMMENDED

**Impact**: 1-2 MB reduction  
**Effort**: Low  
**Risk**: Low

**Current State**:
- Both `.js` and `.min.js` files included (1.5MB source + 600KB minified)
- Both `.css` and `.min.css` files included
- Unminified files only needed when `SCRIPT_DEBUG` is enabled

**Recommendation**:
Include only minified files in production ZIP

**Implementation**:
```bash
# Update build-plugin-zip.sh
# Remove unminified JS (except vendor)
find "build/${BASE_SLUG}/assets/js" -type f -name "*.js" ! -name "*.min.js" ! -path "*/vendor/*" -delete

# Remove unminified CSS
find "build/${BASE_SLUG}/assets/css" -type f -name "*.css" ! -name "*.min.css" -delete
```

**Benefits**:
- Simple to implement
- No functional impact (minified files are used in production)
- ~1-2MB immediate reduction

**Drawbacks**:
- Debugging requires cloning repository
- SCRIPT_DEBUG won't work in production installs
- **TRADE-OFF**: Current approach keeps both for better developer experience

**Decision**: Keep unminified files for now (developer-friendly approach per BUILD.md line 294-296)

---

### Option 4: Optimize Vendor Dependencies

**Impact**: 1-2 MB reduction  
**Effort**: High  
**Risk**: Medium

**Current State**:
- Symfony components: 3.5MB
- PSR libraries: 268KB
- Others: ~900KB
- Total vendor: 4.6MB

**Recommendation**:
1. **Review Symfony usage** - Ensure all components are needed
2. **Replace with lighter alternatives** where possible
3. **Remove unused code** - Strip translation files, examples, tests (already done)

**Analysis**:
```bash
vendor/symfony/         3.5MB
├── http-client        (Required for API calls)
├── validator          (Required for input validation)
├── cache              (Required for caching)
├── filesystem         (Required for file operations)
├── process            (Required for Pro addon tools)
└── [others]
```

**Current Optimizations** (already applied per BUILD.md lines 226-286):
- ✅ Removed vendor test directories
- ✅ Removed vendor .git directories
- ✅ Removed Symfony translations (2MB saved)
- ✅ Removed vendor documentation files
- ✅ Removed CI/dev config files

**Additional Options**:
1. **Consider lightweight alternatives**:
   - Guzzle instead of Symfony HTTP Client (but may be larger)
   - Custom validation instead of Symfony Validator
   - WordPress filesystem API instead of Symfony Filesystem

2. **Tree-shaking**: Use tools to remove unused code from dependencies

**Benefits**:
- Reduces dependency footprint
- Faster autoloading
- Smaller ZIP

**Drawbacks**:
- High effort to refactor
- Risk of breaking functionality
- May require significant testing
- Symfony components are enterprise-grade and well-tested

**Recommendation**: **NOT RECOMMENDED** - Current vendor optimization is already good

---

### Option 5: Split Admin UI into Separate Plugin

**Impact**: 2.4 MB reduction  
**Effort**: Very High  
**Risk**: High

**Current State**:
- `includes/admin`: 2.4MB
- Admin dashboard, settings, diagnostics
- Essential for plugin configuration

**Recommendation**: **NOT RECOMMENDED**

**Reasoning**:
- Admin UI is essential for plugin setup
- Splitting would create poor user experience
- High complexity for minimal benefit
- WordPress.org guidelines prefer single plugin

---

### Option 6: Lazy Load Tools

**Impact**: 2.3 MB reduction  
**Effort**: Medium  
**Risk**: Medium

**Current State**:
- `includes/tools`: 2.3MB
- 65+ tool implementations
- All loaded on plugin initialization

**Recommendation**: Implement lazy loading for tools

**Implementation**:
```php
// Tool Registry with lazy loading
class WP_MCP_AI_Tool_Registry {
    private static $tool_definitions = array();
    private static $loaded_tools = array();
    
    public static function register_tool( $slug, $class_name, $file_path ) {
        self::$tool_definitions[ $slug ] = array(
            'class' => $class_name,
            'file'  => $file_path,
        );
    }
    
    public static function get_tool( $slug ) {
        if ( isset( self::$loaded_tools[ $slug ] ) ) {
            return self::$loaded_tools[ $slug ];
        }
        
        if ( isset( self::$tool_definitions[ $slug ] ) ) {
            $definition = self::$tool_definitions[ $slug ];
            require_once $definition['file'];
            self::$loaded_tools[ $slug ] = new $definition['class']();
            return self::$loaded_tools[ $slug ];
        }
        
        return null;
    }
}
```

**Benefits**:
- Reduces memory footprint
- Faster plugin initialization
- Only loads tools when needed

**Drawbacks**:
- Doesn't reduce ZIP size (all files still included)
- Complexity in tool registration
- Need to ensure all tools support lazy loading

**Impact on ZIP size**: **ZERO** - Only helps with runtime performance

---

## Compression Analysis

### Current ZIP Compression

```bash
Total files: 1,990
Total size uncompressed: ~35 MB
ZIP file size: 11 MB
Compression ratio: 68%
```

### By File Type

| Type | Uncompressed | Compressed | Ratio |
|------|--------------|------------|-------|
| PHP | ~20 MB | ~5 MB | 75% |
| JavaScript | ~12 MB | ~4 MB | 67% |
| Text (playbooks) | ~11.5 MB | ~1.5 MB | 87% |
| Binary (.node) | ~9.1 MB | ~3.5 MB | 62% |

**Observation**: Text files compress extremely well (87%). Binary files less so (62%).

---

## Recommended Implementation Plan

### Phase 1: Quick Wins (Target: 3-4 MB reduction)

**Priority 1**: Exclude Vectorizer from Base Build
- **Impact**: 9.1 MB → ~3.5 MB compressed = **2.5 MB savings**
- **Effort**: 2 hours
- **Risk**: Low (only affects one tool)

**Priority 2**: Make Knowledge Base Optional (Lite Version)
- **Impact**: Include only top 20 professions = **1 MB savings**
- **Effort**: 4 hours
- **Risk**: Low (graceful fallback to CDN)

**Total Phase 1**: **3.5 MB reduction** (11 MB → 7.5 MB) = **32% smaller**

---

### Phase 2: Advanced Optimizations (Target: Additional 1-2 MB)

**Priority 3**: Implement On-Demand Knowledge Base Downloads
- **Impact**: Remove remaining 180+ playbooks = **1 MB savings**
- **Effort**: 8 hours (CDN setup, download logic, caching)
- **Risk**: Medium (requires infrastructure)

**Priority 4**: Split Features by Tool Usage
- Analyze tool usage statistics
- Move rarely-used tools to optional downloads
- **Impact**: 0.5-1 MB potential savings
- **Effort**: 12 hours
- **Risk**: Medium

**Total Phase 2**: **1.5 MB reduction** (7.5 MB → 6 MB) = **45% smaller than original**

---

### Phase 3: Aggressive Optimization (Target: 2-3 MB base plugin)

**Priority 5**: Convert to Micro-Core Architecture
- Ultra-minimal base plugin (2-3 MB)
- All features as optional extensions
- Download manager in core
- **Impact**: 4-5 MB savings
- **Effort**: 40+ hours
- **Risk**: High (major architectural change)

---

## Cost-Benefit Analysis

| Option | Impact | Effort | Risk | ROI | Recommended |
|--------|--------|--------|------|-----|-------------|
| Exclude Vectorizer | 2.5 MB | Low | Low | ⭐⭐⭐⭐⭐ | YES |
| Optional Knowledge Base | 1-5 MB | Medium | Low | ⭐⭐⭐⭐ | YES |
| Remove Unminified Files | 1-2 MB | Low | Low | ⭐⭐⭐ | MAYBE |
| Optimize Vendor | 1-2 MB | High | Medium | ⭐⭐ | NO |
| Split Admin UI | 2.4 MB | Very High | High | ⭐ | NO |
| Lazy Load Tools | 0 MB | Medium | Medium | ⭐ | NO |

---

## WordPress.org Guidelines

### Size Limits
- **Soft limit**: 10 MB (warnings above this)
- **Hard limit**: No official limit, but 20+ MB raises red flags
- **Recommendation**: Keep under 5-8 MB for best user experience

### Current Status
- Base plugin: **11 MB** (slightly above soft limit)
- **Impact**: May trigger review delays or warnings

### After Phase 1 Optimization
- Base plugin: **7.5 MB** (within recommended range)
- **Impact**: Smooth WordPress.org approval process

---

## Technical Implementation Details

### 1. Exclude Vectorizer from Build

**File**: `bin/build-plugin-zip.sh`

**Change** (add to existing excludes around line 226):
```bash
--exclude 'assets/js/vendor/neplex-vectorizer' \
```

**Tool File**: `includes/tools/class-wp-mcp-ai-tool-vectorize-image.php`

**Add check** (before execution):
```php
public function execute( $arguments, $context ) {
    $vectorizer_path = WP_MCP_AI_PLUGIN_DIR . 'assets/js/vendor/neplex-vectorizer';
    
    if ( ! file_exists( $vectorizer_path ) ) {
        return array(
            'error' => 'Vectorizer library not installed. Please install the Image Vectorizer extension from Settings → Extensions.',
        );
    }
    
    // Existing execution code...
}
```

---

### 2. Optional Knowledge Base Pack

**File**: `includes/knowledge-base/class-wp-mcp-ai-knowledge-base-manager.php` (new)

```php
<?php
class WP_MCP_AI_Knowledge_Base_Manager {
    
    const CDN_BASE_URL = 'https://cdn.nvdigitalsolutions.com/mcp-ai-wpoos/knowledge-base/';
    const CACHE_DIR = WP_CONTENT_DIR . '/uploads/mcp-ai-cache/playbooks/';
    
    /**
     * Get profession playbook (with on-demand download)
     */
    public static function get_playbook( $profession_slug ) {
        // 1. Check local bundled copy
        $local_path = WP_MCP_AI_PLUGIN_DIR . 'includes/knowledge-base/profession-playbooks/professions/' . $profession_slug . '.txt';
        if ( file_exists( $local_path ) ) {
            return file_get_contents( $local_path );
        }
        
        // 2. Check cached copy
        $cache_path = self::CACHE_DIR . $profession_slug . '.txt';
        if ( file_exists( $cache_path ) ) {
            return file_get_contents( $cache_path );
        }
        
        // 3. Download from CDN
        $cdn_url = self::CDN_BASE_URL . 'professions/' . $profession_slug . '.txt';
        $response = wp_remote_get( $cdn_url, array( 'timeout' => 30 ) );
        
        if ( is_wp_error( $response ) ) {
            error_log( 'Failed to download playbook: ' . $response->get_error_message() );
            return '';
        }
        
        $content = wp_remote_retrieve_body( $response );
        
        // Cache locally
        wp_mkdir_p( dirname( $cache_path ) );
        file_put_contents( $cache_path, $content );
        
        return $content;
    }
    
    /**
     * Get bundled professions list (included in base plugin)
     */
    public static function get_bundled_professions() {
        return array(
            'business_consultant',
            'content_writer',
            'marketing_consultant',
            'web_developer',
            'graphic_designer',
            'data_analyst',
            'project_manager',
            'social_media_manager',
            'seo_specialist',
            'customer_support',
            // Top 20 most common professions
        );
    }
}
```

---

### 3. Settings UI for Extensions

**File**: `includes/admin/class-wp-mcp-ai-settings-extensions.php` (new)

```php
<?php
class WP_MCP_AI_Settings_Extensions {
    
    public static function render_page() {
        ?>
        <div class="wrap">
            <h1>Extensions</h1>
            <p>Optional components for extended functionality</p>
            
            <table class="wp-list-table widefat">
                <thead>
                    <tr>
                        <th>Extension</th>
                        <th>Size</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong>Image Vectorizer</strong><br>
                            <span class="description">Convert raster images to SVG vectors</span>
                        </td>
                        <td>9.1 MB</td>
                        <td><?php echo self::is_extension_installed( 'vectorizer' ) ? '✅ Installed' : '❌ Not Installed'; ?></td>
                        <td>
                            <?php if ( self::is_extension_installed( 'vectorizer' ) ) : ?>
                                <button class="button">Uninstall</button>
                            <?php else : ?>
                                <button class="button button-primary">Install</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Complete Knowledge Base</strong><br>
                            <span class="description">218 profession playbooks</span>
                        </td>
                        <td>7.6 MB</td>
                        <td><?php echo self::is_extension_installed( 'knowledge-base-full' ) ? '✅ Installed' : '⚠️ Partial (20/218)'; ?></td>
                        <td>
                            <button class="button button-primary">Download All</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    private static function is_extension_installed( $extension ) {
        switch ( $extension ) {
            case 'vectorizer':
                return file_exists( WP_MCP_AI_PLUGIN_DIR . 'assets/js/vendor/neplex-vectorizer' );
            case 'knowledge-base-full':
                $playbooks_dir = WP_MCP_AI_PLUGIN_DIR . 'includes/knowledge-base/profession-playbooks/professions/';
                return count( glob( $playbooks_dir . '*.txt' ) ) > 50;
            default:
                return false;
        }
    }
}
```

---

## Testing Strategy

### Phase 1 Testing

1. **Base Plugin Without Vectorizer**
   - ✅ Install base plugin
   - ✅ Verify all non-vectorize tools work
   - ✅ Attempt to use vectorize_image tool
   - ✅ Verify error message appears
   - ✅ Install vectorizer extension
   - ✅ Verify vectorize_image now works

2. **Knowledge Base Lite**
   - ✅ Install plugin
   - ✅ Select bundled profession (should load instantly)
   - ✅ Select non-bundled profession (should download from CDN)
   - ✅ Verify fallback for offline scenarios
   - ✅ Check cached professions persist

3. **ZIP Size Validation**
   - ✅ Build plugin with `./bin/build-plugin-zip.sh --base`
   - ✅ Verify ZIP size < 8 MB
   - ✅ Verify file count < 1500
   - ✅ Extract and test installation

---

## Migration Strategy

### For Existing Users

**Scenario 1**: User has vectorize_image in workflows
- Plugin upgrade maintains existing vectorizer installation
- New installs require explicit download

**Scenario 2**: User uses custom professions
- All custom playbooks remain in wp-content/uploads
- Only default playbooks affected

**Scenario 3**: Offline installation
- Download "full bundle" from GitHub releases
- Includes all optional components
- Traditional installation method

---

## Alternative Approaches

### Approach A: Two Distribution Tracks

1. **Lite Version** (3-4 MB)
   - Minimal dependencies
   - Core tools only
   - Fast download
   - WordPress.org primary

2. **Full Version** (11 MB)
   - Everything included
   - No downloads needed
   - Self-hosted/GitHub releases

### Approach B: Progressive Enhancement

1. **Install base** (4-5 MB)
2. **Auto-detect needed features** on first use
3. **Download components** in background
4. **Notify user** when ready

### Approach C: WordPress.org + Addon Store

1. **Base on WordPress.org** (5 MB)
2. **Extensions on custom marketplace**
3. **In-plugin installer** for extensions
4. **One-click activation**

---

## Conclusions and Recommendations

### Summary

The base plugin can be reduced from **11 MB to 6-8 MB** with moderate effort:

1. ✅ **Exclude neplex-vectorizer** (2.5 MB savings) - High ROI, low risk
2. ✅ **Optional knowledge base** (1-5 MB savings) - High ROI, low-medium risk
3. ⚠️ **Remove unminified files** (1-2 MB) - Consider trade-offs
4. ❌ **Vendor optimization** - Already optimized, not worth effort

### Next Steps

1. **Get stakeholder approval** on approach
2. **Implement Phase 1** (Quick wins)
3. **Test thoroughly** on fresh WordPress installs
4. **Update documentation** (BUILD.md, README.md)
5. **Submit to WordPress.org** (if under 8 MB)

### Success Metrics

- ✅ Base plugin < 8 MB
- ✅ All core functionality intact
- ✅ Graceful handling of optional components
- ✅ Clear user communication about extensions
- ✅ No breaking changes for existing users

---

**Questions? Need clarification on any approach?**
