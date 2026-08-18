# Pro Toolkit Memory-Based Tracking System

## Overview

The pro toolkit activation system has been upgraded from a **count-based limit** (maximum 5 toolkits) to a **memory-based tracking system** that provides transparency about resource usage without enforcing hard limits.

## What Changed

### Before (Count-Based Limit)
- ❌ Hard limit of 5 pro toolkits could be enabled simultaneously
- ❌ Checkboxes were disabled when limit was reached
- ❌ Error message: "Maximum toolkit limit reached. Disable another toolkit to enable this one."
- ❌ Arbitrary restriction that didn't reflect actual resource requirements
- ❌ Environment-dependent (999 for dev, 5 for production)

### After (Memory-Based Tracking)
- ✅ **No hard limit** - enable as many toolkits as needed
- ✅ **Memory transparency** - shows estimated memory usage in MB
- ✅ **Smart status indicators** - Low/Moderate/High usage badges
- ✅ **Real-time updates** - JavaScript dynamically calculates total memory
- ✅ **Informed decisions** - Users see actual resource implications
- ✅ **Works in all environments** - no special dev/production behavior

## Memory Requirements by Toolkit

| Toolkit | Memory (MB) | Complexity | Notes |
|---------|-------------|------------|-------|
| **Video Production** | 256 MB | Very High | 12 tools, FFmpeg, video processing |
| **Cloudways** | 192 MB | Very High | 58+ tools, extensive server management |
| **Image Production** | 192 MB | Very High | 12-15 tools, AI generation, GPU processing |
| **Architectural Design** | 160 MB | High | 16 tools, 3D modeling, rendering |
| **CRE Debt** | 144 MB | High | 57 tools, CRE debt & securitization |
| **Health & Wellness** | 128 MB | High | 30+ tools, secure health data storage |
| **Law Firm** | 128 MB | High | 62 tools, 5 CPTs, matter management, billing/trust, compliance |
| **Site Creator** | 104 MB | High | 26 tools, page/section/widget builders, AI automation |
| **Document Generation** | 96 MB | High | Node.js, PDF/Word/Excel generation |
| **Analytics** | 96 MB | High | Data warehouse integrations |
| **Healthcare Imaging** | 96 MB | High | DICOM/Cornerstone3D medical imaging viewer |
| **DietPi** | 96 MB | High | 18+ tools, SSH, app clients, service management |
| **Chat Channels** | 84 MB | High | 21 tools, multi-platform messaging integration |
| **E-commerce** | 80 MB | Medium | 20 WooCommerce tools |
| **Financial Planner** | 80 MB | Medium | 24 tools, Plaid API |
| **Regulatory Registration** | 80 MB | Medium | 15 tools, multi-country registration management |
| **Multilingual** | 72 MB | Medium | Translation memory |
| **DJ Management** | 72 MB | Medium | 15-18 tools, music APIs, contracts |
| **Shopify Sync** | 72 MB | Medium | 5+ tools, Shopify GraphQL sync, CCT cache |
| **Project Management** | 64 MB | Medium | 13 tools |
| **Social Media** | 64 MB | Medium | 15 multi-platform tools |
| **Calendar Booking** | 64 MB | Medium | Calendar sync APIs |
| **FlowHub** | 64 MB | Medium | FlowHub POS API sync, CCT cache |
| **EZuite** | 64 MB | Medium | EZuite ERP API sync, CCT cache |
| **Places Management** | 56 MB | Medium | Google Maps API, geocoding |
| **CRM** | 56 MB | Medium | 10+ tools, contact/lead/campaign management |
| **Media Toolkit** | 48 MB | Medium | Template management, image processing |
| **AI Tool Builder** | 48 MB | Medium | 10 meta-tools |
| **ECA Management** | 40 MB | Low | iSAMS integration |
| **Quiz System** | 32 MB | Low | 7 assessment tools |
| **AI CPT Management** | 24 MB | Low | Lightweight metabox |
| **WebChat Integration** | 24 MB | Low | WebRTC rooms, message storage |
| **Architect Agent** | 16 MB | Low | 4 self-editing tools (file, shell, git, search) |

**Total if all enabled:** 2,892 MB (2.9 GB)

## Media Worker Sidecar Interaction

When the optional **Media Worker sidecar** is configured (via the
`WP_MEDIA_WORKER_URL` constant in `wp-config.php`, or Settings → Media Worker),
heavy NPM-package workloads — PDF/OCR/document, video (FFmpeg), and image
processing — route to the sidecar container over HTTP instead of spawning
local Node.js/FFmpeg subprocesses on the WordPress host.

The estimate adjusts automatically in that case:

- Affected toolkits: **Document Generation, Video Production, Image
  Production, Media Toolkit** (defined in
  `get_sidecar_offload_toolkits()`).
- Each affected estimate is reduced by **50%**
  (`MEDIA_WORKER_OFFLOAD_FACTOR` in
  `includes/admin/sections/class-wp-mcp-ai-section-tools.php`).
- A green note appears under the counter, e.g. *"Media Worker sidecar
  configured … This currently moves 296 MB off the WordPress estimate."*
- The real-time JavaScript counter uses the same adjusted map, so live
  checkbox updates stay consistent with the server-rendered total.

| Toolkit | Base estimate | With sidecar |
|---|---|---|
| Video Production | 256 MB | 128 MB |
| Image Production | 192 MB | 96 MB |
| Document Generation | 96 MB | 48 MB |
| Media Toolkit | 48 MB | 24 MB |

The sidecar's own memory footprint lives in its own container (or managed
Node host) and is **not** part of the WordPress PHP `memory_limit`, so it is
not added back into this counter. The discount keys off **configured** state,
not a live health check: if the sidecar is unreachable at runtime the plugin
falls back to local execution, so treat the reduced estimate as the
off-host case and disconnect the sidecar in Settings if you want the
conservative local-execution numbers back.

## Status Indicators

The system uses color-coded status badges to indicate overall memory usage:

### 🟢 Low Usage (< 500 MB)
- **Color:** Green
- **Status:** "Low Usage"
- **Action:** No concerns, plenty of headroom

### 🟡 Moderate Usage (500-799 MB)
- **Color:** Yellow/Orange  
- **Status:** "Moderate Usage"
- **Action:** Monitor if adding more heavy toolkits

### 🔴 High Usage (≥ 800 MB)
- **Color:** Red
- **Status:** "High Usage"
- **Action:** Consider your server capacity (informational only)

**Important:** These are **informational indicators** only. The system does not prevent you from enabling any toolkits.

## User Interface

### Display Format
```
Pro Toolkit Memory Usage
━━━━━━━━━━━━━━━━━━━━━━━━━
256 MB estimated memory usage (5 toolkits enabled) [Moderate Usage]

This shows the estimated memory usage for all enabled pro toolkits. 
Memory requirements vary by toolkit complexity and tool count. 
You can enable as many toolkits as needed for your use case.
```

### Real-Time Updates
When you check/uncheck a toolkit checkbox:
1. JavaScript extracts the toolkit's memory requirement
2. Recalculates the total memory usage
3. Updates the counter display
4. Adjusts the status badge color/text
5. **Does NOT disable any checkboxes**

## Technical Implementation

### PHP Backend

#### Memory Requirements Map
```php
private function get_toolkit_memory_requirements() {
    return array(
        'enable_quiz_system'                   => 32,
        'enable_media_toolkit'                 => 48,
        'enable_document_generation_toolkit'   => 96,
        // ... 20 toolkits total
    );
}
```

#### Memory Calculation
```php
$toolkit_memory_requirements = $this->get_toolkit_memory_requirements();
$total_memory_mb = 0;

foreach ( $toolkit_memory_requirements as $option => $memory_mb ) {
    if ( ! empty( $settings[ $option ] ) ) {
        $total_memory_mb += $memory_mb;
    }
}
```

#### Status Thresholds
```php
if ( $total_memory_mb >= 800 ) {
    $counter_status = __( 'High Usage', 'mcp-ai-wpoos' );
} elseif ( $total_memory_mb >= 500 ) {
    $counter_status = __( 'Moderate Usage', 'mcp-ai-wpoos' );
} else {
    $counter_status = __( 'Low Usage', 'mcp-ai-wpoos' );
}
```

### JavaScript Frontend

#### Memory Map from PHP
```javascript
var toolkitMemory = <?php echo wp_json_encode( $this->get_toolkit_memory_requirements() ); ?>;
```

#### Dynamic Calculation
```javascript
function updateToolkitMemory() {
    var totalMemory = 0;
    
    toolkitCheckboxes.filter(':checked').each(function() {
        var inputName = $(this).attr('name');
        var optionName = inputName.match(/\[([^\]]+)\]/)[1];
        if (toolkitMemory[optionName]) {
            totalMemory += toolkitMemory[optionName];
        }
    });
    
    counter.text(totalMemory);
    // Update status badge based on thresholds
}
```

## Migration Notes

### Removed Features
1. **Hard Toolkit Limit**
   - Filter `wp_mcp_ai_max_active_pro_toolkits` is no longer used
   - No more "maximum 5 toolkits" restriction
   - Environment-based limits removed

2. **Checkbox Disabling**
   - Removed `prop('disabled', true)` logic
   - Removed CSS for disabled checkboxes
   - Users can now check any combination

3. **Error Messages**
   - Removed "Maximum toolkit limit reached" notice
   - No blocking messages shown

### Maintained Features
1. **Visual Status Indicators**
   - Same color scheme (green/yellow/red)
   - Same badge styling
   - Same dashboard location

2. **Real-Time Updates**
   - Still updates on checkbox change
   - Still shows dynamic count
   - Enhanced to show memory instead of count

## Testing

### Unit Tests Added

```php
/**
 * Test that toolkit memory usage is displayed correctly.
 */
public function test_toolkit_memory_usage_displayed()

/**
 * Test that toolkit memory requirements are defined for all toolkits.
 */
public function test_toolkit_memory_requirements_defined()
```

### Manual Testing Checklist
- [ ] Navigate to Settings → NV oOS → Tools & Features → Features
- [ ] Verify "Pro Toolkit Memory Usage" heading is displayed
- [ ] Check initial memory count (should be 0 MB if none enabled)
- [ ] Enable Quiz System (32 MB) - verify counter updates to 32 MB
- [ ] Enable Video Production (256 MB) - verify counter updates to 288 MB
- [ ] Check status badge changes: Low → Moderate → High
- [ ] Verify no checkboxes are disabled
- [ ] Enable multiple heavy toolkits (Cloudways, Image Production, etc.)
- [ ] Confirm all checkboxes remain functional
- [ ] Save settings and reload page - verify counts persist

## Benefits

### For Users
1. **Transparency** - See actual resource implications
2. **Flexibility** - No artificial limits
3. **Informed Decisions** - Know which toolkits are memory-intensive
4. **Better Planning** - Plan server resources based on actual needs

### For Developers
1. **Maintainability** - Memory values easy to update
2. **Extensibility** - New toolkits just need memory value added
3. **Consistency** - Same approach across all environments
4. **No Magic Numbers** - Clear relationship between tools and resources

### For System Administrators
1. **Resource Planning** - Estimate memory needs
2. **Performance Monitoring** - Identify heavy toolkit usage
3. **Scaling Decisions** - Know when to upgrade server resources
4. **No Surprises** - Transparent resource consumption

## Future Enhancements

Potential improvements for future versions:

1. **Dynamic Memory Measurement**
   - Track actual memory usage in production
   - Update estimates based on real-world data
   - Per-site memory profiling

2. **Memory Optimization Suggestions**
   - Recommend toolkit combinations for common use cases
   - Suggest alternatives for heavy toolkits
   - Memory-saving tips per toolkit

3. **Advanced Thresholds**
   - Configurable warning thresholds via settings
   - Per-environment threshold customization
   - Integration with server monitoring

4. **Memory History**
   - Track memory usage trends over time
   - Alert on sudden increases
   - Historical memory usage charts

## Support

For questions or issues related to toolkit memory tracking:

1. **Documentation:** Check this file and `docs/` directory
2. **GitHub Issues:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
3. **Troubleshooting:** See `docs/deployment-troubleshooting.md`

---

**Version:** 1.0.0  
**Last Updated:** 2026-01-22  
**Author:** NV Digital Solutions
