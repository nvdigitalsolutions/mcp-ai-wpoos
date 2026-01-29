# WordPress Integration Enhancement - Implementation Checklist

**Quick Reference for Developers**  
**Plugin:** NV oOS (mcp-ai-wpoos)  
**Target:** 82% → 95% WordPress Integration Score

---

## 🔴 PHASE 1: CRITICAL COMPLIANCE (Week 1-2)

### Output Escaping (1.5-2 days) - HIGH PRIORITY

**Goal:** Fix 82 unescaped echo statements

- [ ] **Admin Dashboard Pages** (15 files)
  - [ ] `includes/admin/class-wp-mcp-ai-pro-dashboard.php`
  - [ ] `includes/admin/class-wp-mcp-ai-pro-dashboard-diagnostic.php`
  - [ ] `includes/admin/class-wp-mcp-ai-settings-dashboard.php`
  - [ ] Other admin classes (12+ files)

- [ ] **Elementor Widgets** (8 files)
  - [ ] `includes/elementor/class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php`
  - [ ] `includes/elementor/class-wp-mcp-ai-elementor-assistant-tools-widget.php`
  - [ ] Other widget classes (6+ files)

- [ ] **Tool Output** (12 files)
  - [ ] Review all tool `execute()` methods
  - [ ] Escape return values in tool responses
  - [ ] Use `wp_kses_post()` for HTML content

- [ ] **Metaboxes** (20+ files)
  - [ ] Profession metaboxes
  - [ ] Assistant metaboxes
  - [ ] Custom meta rendering

**Escaping Functions Reference:**
```php
echo esc_html( $text );           // Plain text
echo esc_attr( $attribute );       // HTML attributes
echo esc_url( $url );              // URLs
echo esc_js( $javascript );        // JavaScript strings
echo wp_kses_post( $html );        // HTML with allowed tags
echo intval( $number );            // Numbers
```

**Testing:**
- [ ] Run Plugin Check: `wp plugin check mcp-ai-wpoos`
- [ ] PHPCS scan: `composer run lint`
- [ ] Manual testing: Try XSS payloads in inputs

---

### Enqueue Compliance (2-3 days) - MEDIUM PRIORITY

**Goal:** Fix 97 direct `<script>`/`<style>` tags

- [ ] **Admin Page Scripts** (60 instances)
  - [ ] Create `enqueue_scripts()` methods in admin classes
  - [ ] Move inline JS to separate files
  - [ ] Use `wp_localize_script()` for data passing
  
  ```php
  add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_scripts' ) );
  
  public function enqueue_dashboard_scripts( $hook ) {
      if ( 'toplevel_page_mcp-ai-dashboard' !== $hook ) {
          return;
      }
      
      wp_enqueue_script(
          'wp-mcp-ai-dashboard',
          plugins_url( 'assets/js/dashboard.js', WP_MCP_AI_PLUGIN_FILE ),
          array( 'jquery' ),
          WP_MCP_AI_VERSION,
          true
      );
      
      wp_localize_script( 'wp-mcp-ai-dashboard', 'mcpAiData', array(
          'ajaxUrl' => admin_url( 'admin-ajax.php' ),
          'nonce'   => wp_create_nonce( 'mcp_ai_dashboard' ),
      ) );
  }
  ```

- [ ] **Inline Scripts** (25 instances)
  - [ ] Use `wp_add_inline_script()` for small JS snippets
  - [ ] Use `wp_add_inline_style()` for small CSS
  
  ```php
  wp_add_inline_script( 'wp-mcp-ai-dashboard', 'var myConfig = ' . wp_json_encode( $config ) . ';' );
  ```

- [ ] **Standalone HTML** (12 instances)
  - [ ] Document exemptions with phpcs:ignore
  - [ ] Add explanatory comments
  
  ```php
  // Chart generation - standalone HTML without WordPress context
  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
  echo '<script>/* Chart.js for standalone export */</script>';
  ```

**Testing:**
- [ ] No JavaScript conflicts
- [ ] Check browser console for errors
- [ ] Verify data still passes to scripts

---

### ob_start() Documentation (30 minutes) - LOW PRIORITY

**Goal:** Document 2 ob_start() cleanup mechanisms

- [ ] **File:** `mcp-ai-wpoos.php:1074`
  ```php
  // Start output buffering for SSE stream
  // Cleanup via shutdown hook 'wp_mcp_ai_cleanup_buffers' registered at line 150
  ob_start();
  ```

- [ ] **File:** `includes/class-wp-mcp-ai-rest.php:250`
  ```php
  // Output buffering for REST response
  // Cleanup via ob_end_clean() at line 280 before wp_send_json()
  ob_start();
  ```

---

### Validation Checklist

- [ ] Run WordPress Plugin Check: `wp plugin check mcp-ai-wpoos`
- [ ] PHPCS validation: `composer run lint`
- [ ] Security scan: `composer run security-check`
- [ ] Test on clean WordPress install (6.0, 6.4, 6.7)
- [ ] Verify no broken functionality
- [ ] Check browser console for JS errors
- [ ] Validate AJAX requests still work

**Expected Result:** 100% WordPress.org compliance, ready for submission

---

## 🟡 PHASE 2: CORE FEATURE INTEGRATION (Week 3-5)

### Site Health Integration (6-8 hours)

- [ ] Create `includes/class-wp-mcp-ai-site-health.php`
- [ ] Implement 5+ health checks:
  - [ ] API connectivity test
  - [ ] Credentials configuration check
  - [ ] File permissions validation
  - [ ] Rate limit monitoring
  - [ ] Tool availability check
- [ ] Add debug information panel
- [ ] Test via Tools → Site Health

**Files to Create:**
- `includes/class-wp-mcp-ai-site-health.php`

**Hook Registration:**
```php
add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
add_filter( 'debug_information', array( $this, 'add_debug_info' ) );
```

---

### Block Editor Integration (2-3 days)

- [ ] **AI Chat Block** (convert shortcode)
  - [ ] Create `includes/blocks/ai-chat/`
  - [ ] Files: `block.json`, `edit.js`, `save.js`, `style.css`
  - [ ] Assistant selector in sidebar
  - [ ] Theme customization options
  - [ ] Live preview in editor

- [ ] **Assistant Selector Block**
  - [ ] Create `includes/blocks/assistant-selector/`
  - [ ] Grid/list view toggle
  - [ ] Category filtering
  - [ ] Search functionality

- [ ] **AI Statistics Block**
  - [ ] Create `includes/blocks/ai-statistics/`
  - [ ] Server-side render
  - [ ] Usage metrics display
  - [ ] Date range filter

- [ ] **AI Status Block**
  - [ ] Create `includes/blocks/ai-status/`
  - [ ] Server-side render
  - [ ] Real-time API status
  - [ ] Rate limit indicators

**Build Commands:**
```bash
npm run build:blocks
npm run start:blocks  # for development
```

**Testing:**
- [ ] Insert blocks in editor
- [ ] Verify block settings work
- [ ] Test on frontend
- [ ] Check mobile responsiveness

---

### Privacy API Integration (1 day)

- [ ] Create `includes/class-wp-mcp-ai-privacy.php`
- [ ] Implement privacy policy content
- [ ] Create personal data exporter:
  - [ ] Chat transcripts exporter
  - [ ] Usage data exporter
- [ ] Create personal data eraser:
  - [ ] Chat transcripts eraser
  - [ ] Usage statistics eraser
- [ ] Test via Tools → Export/Erase Personal Data

**Testing:**
- [ ] Request data export for test user
- [ ] Verify ZIP contains correct data
- [ ] Request data erasure
- [ ] Verify data is deleted

---

### Enhanced WP-CLI (2-3 days)

**New Command Classes:**
- [ ] `includes/cli/class-wp-mcp-ai-cli-assistant.php`
- [ ] `includes/cli/class-wp-mcp-ai-cli-tool.php`
- [ ] `includes/cli/class-wp-mcp-ai-cli-diagnostic.php`
- [ ] `includes/cli/class-wp-mcp-ai-cli-maintenance.php`

**Commands to Implement:**
- [ ] `wp mcp-ai assistant list`
- [ ] `wp mcp-ai assistant create`
- [ ] `wp mcp-ai assistant delete`
- [ ] `wp mcp-ai assistant export`
- [ ] `wp mcp-ai assistant import`
- [ ] `wp mcp-ai tool list`
- [ ] `wp mcp-ai tool enable`
- [ ] `wp mcp-ai tool disable`
- [ ] `wp mcp-ai tool test`
- [ ] `wp mcp-ai diagnose api`
- [ ] `wp mcp-ai diagnose permissions`
- [ ] `wp mcp-ai diagnose tools`
- [ ] `wp mcp-ai cache clear`
- [ ] `wp mcp-ai transcript cleanup`
- [ ] `wp mcp-ai usage report`

**Testing:**
```bash
wp mcp-ai assistant list --format=table
wp mcp-ai tool list --status=stable
wp mcp-ai diagnose api
```

---

### Dashboard Widgets (1-2 days)

- [ ] Create `includes/admin/class-wp-mcp-ai-dashboard-widgets.php`
- [ ] Implement widgets:
  - [ ] AI Usage Summary
  - [ ] Recent Conversations
  - [ ] API Health Status
  - [ ] Tool Activity

**Hook Registration:**
```php
add_action( 'wp_dashboard_setup', array( $this, 'register_widgets' ) );
```

**Testing:**
- [ ] Widgets appear on dashboard
- [ ] Data loads correctly
- [ ] Action links work
- [ ] Performance is acceptable

---

## 🟢 PHASE 3: DEVELOPER EXPERIENCE (Week 5-6)

### Custom Taxonomies (1 day)

- [ ] Create `includes/assistants/class-wp-mcp-ai-assistant-taxonomies.php`
- [ ] Register taxonomies:
  - [ ] `mcp_ai_assistant_category` (hierarchical)
  - [ ] `mcp_ai_use_case` (non-hierarchical)
- [ ] Seed default terms
- [ ] Add to assistant edit screen
- [ ] Enable REST API support

**Testing:**
- [ ] Create categories and assign
- [ ] Filter assistants by category
- [ ] Verify REST API access

---

### Multisite Improvements (2 days)

- [ ] Create `includes/class-wp-mcp-ai-multisite.php`
- [ ] Implement features:
  - [ ] Per-site settings with network defaults
  - [ ] Network admin menu
  - [ ] Network-wide analytics
  - [ ] Site Health network integration
- [ ] Test on multisite installation

**Testing:**
- [ ] Network activate plugin
- [ ] Configure network defaults
- [ ] Override on individual site
- [ ] View network analytics

---

## 🟢 PHASE 4: TESTING & DOCUMENTATION (Week 6-7)

### Integration Tests (2 days)

**New Test Files:**
- [ ] `tests/integration/test-site-health.php`
- [ ] `tests/integration/test-privacy-api.php`
- [ ] `tests/integration/test-blocks.php`
- [ ] `tests/integration/test-dashboard-widgets.php`
- [ ] `tests/multisite/test-network-activation.php`
- [ ] `tests/multisite/test-per-site-settings.php`
- [ ] `tests/cli/test-assistant-commands.php`
- [ ] `tests/cli/test-tool-commands.php`

**Run Tests:**
```bash
# All tests
composer run test

# Specific category
vendor/bin/phpunit tests/integration/
vendor/bin/phpunit tests/multisite/ --multisite

# With coverage
composer run test:coverage
```

**Coverage Target:** 90%+

---

### Documentation Updates (1-2 days)

**New Documents:**
- [ ] `docs/WORDPRESS_INTEGRATION_GUIDE.md`
- [ ] `docs/BLOCK_EDITOR_GUIDE.md`
- [ ] `docs/PRIVACY_GDPR_COMPLIANCE.md`
- [ ] `docs/MULTISITE_DEPLOYMENT.md`
- [ ] `docs/WP_CLI_REFERENCE.md`

**Update Existing:**
- [ ] `README.md` - Add new features
- [ ] `DOCUMENTATION_INDEX.md` - Add new docs
- [ ] `QUICK_REFERENCE.md` - Update with new features

---

## 📋 Final Validation Checklist

### Before Submitting to WordPress.org

- [ ] **Plugin Check:** 100% pass rate
  ```bash
  wp plugin check mcp-ai-wpoos
  ```

- [ ] **PHPCS:** No errors or warnings
  ```bash
  composer run lint
  ```

- [ ] **Security Scan:** No vulnerabilities
  ```bash
  composer run security-check
  ```

- [ ] **Test Suite:** 90%+ pass rate
  ```bash
  composer run test
  ```

- [ ] **Manual Testing:**
  - [ ] Clean WordPress 6.7 install
  - [ ] WordPress 6.4 (minimum supported)
  - [ ] WordPress 6.0 (backward compatibility)
  - [ ] Multisite network
  - [ ] Block editor integration
  - [ ] Site Health checks
  - [ ] Privacy tools
  - [ ] WP-CLI commands

- [ ] **Documentation:**
  - [ ] All new features documented
  - [ ] Screenshots updated
  - [ ] Video walkthroughs (optional)
  - [ ] Changelog updated

- [ ] **Performance:**
  - [ ] No N+1 queries
  - [ ] Query monitor clean
  - [ ] Page load times acceptable
  - [ ] Memory usage within limits

---

## 🎯 Success Metrics

### Target Scores

| Metric | Before | Target | Status |
|--------|--------|--------|--------|
| WordPress.org Compliance | 82% | 100% | ⏳ |
| Security Score | 95% | 100% | ⏳ |
| Block Editor Integration | 30% | 80% | ⏳ |
| Site Health Tests | 0 | 5+ | ⏳ |
| Privacy API | 0% | 100% | ⏳ |
| WP-CLI Commands | 1 | 15+ | ⏳ |
| Dashboard Widgets | 1 | 5+ | ⏳ |
| Custom Taxonomies | 0 | 2 | ⏳ |
| Multisite Support | 60% | 85% | ⏳ |
| Test Coverage | 85% | 90%+ | ⏳ |
| **Overall Integration** | **82%** | **95%** | ⏳ |

---

## 🚨 Known Issues & Gotchas

### Output Escaping
- **Issue:** JSON output in JavaScript contexts
- **Solution:** Use `wp_json_encode()` and flag as safe
- **Example:**
  ```php
  wp_add_inline_script( 'my-script', 'var config = ' . wp_json_encode( $config ) . ';' );
  ```

### Enqueue Compliance
- **Issue:** Chart.js in standalone exports
- **Solution:** Use phpcs:ignore with explanation
- **Example:**
  ```php
  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone HTML export
  ```

### Multisite Testing
- **Issue:** Need actual multisite install
- **Solution:** Use Docker Compose or LocalWP
- **Command:**
  ```bash
  docker-compose up -d
  wp core multisite-install
  ```

---

## 📞 Support Resources

- **Full Proposal:** [WORDPRESS_INTEGRATION_ENHANCEMENT_PROPOSAL.md](proposals/WORDPRESS_INTEGRATION_ENHANCEMENT_PROPOSAL.md)
- **Executive Summary:** [WORDPRESS_INTEGRATION_GAPS_EXECUTIVE_SUMMARY.md](WORDPRESS_INTEGRATION_GAPS_EXECUTIVE_SUMMARY.md)
- **WordPress.org Guidelines:** https://developer.wordpress.org/plugins/wordpress-org/
- **Block Editor Handbook:** https://developer.wordpress.org/block-editor/
- **WP-CLI Handbook:** https://make.wordpress.org/cli/handbook/

---

**Last Updated:** January 28, 2026  
**Status:** Ready for Implementation  
**Estimated Total Time:** 6-7 weeks
