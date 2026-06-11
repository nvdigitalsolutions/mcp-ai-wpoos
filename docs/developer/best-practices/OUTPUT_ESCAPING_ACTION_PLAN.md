# Output Escaping Action Plan

**Status:** Ready for Implementation  
**Priority:** CRITICAL (Security)  
**Estimated Time:** 1-2 days  
**Instances:** 82+ unescaped echo statements

---

## 🎯 CRITICAL FIXES REQUIRED

The WordPress.org review identified 82+ instances of unescaped output. This document provides the action plan with prioritized fixes.

### Priority Levels

**P0 (Critical - Fix Immediately):**
- Admin dashboard pages (accessible to authenticated users)
- Public-facing widgets
- REST API responses visible to users

**P1 (High - Fix Next):**
- Elementor widgets
- Tool outputs shown to users

**P2 (Medium - Fix After P0/P1):**
- Generated standalone HTML (may need exemptions)
- Email templates

---

## ✅ FIXES TO IMPLEMENT

### P0: Admin Dashboard (Critical)

#### 1. class-wp-mcp-ai-pro-dashboard-diagnostic.php:208

**Issue:** Unescaped integer in printf
```php
// CURRENT (LINE 208 - WRONG):
isset( $results['failed_count'] ) ? $results['failed_count'] : 0

// SHOULD BE:
isset( $results['failed_count'] ) ? absint( $results['failed_count'] ) : 0
```

**Fix:**
```php
printf(
    /* translators: %d: Number of failed tests */
    esc_html__( '✗ %d test(s) failed. See details below.', 'mcp-ai-wpoos' ),
    isset( $results['failed_count'] ) ? absint( $results['failed_count'] ) : 0
);
```

**Reason:** While `%d` in printf forces integer formatting, WordPress.org wants explicit sanitization/casting for all variables.

---

### P0: Elementor Widgets (Critical)

#### 2. class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php:275

**Issue:** Unescaped description in table cell
```php
// CURRENT (LINE 275 - WRONG):
echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--description">' . $formatted_entry['description'] . '</td>';

// SHOULD BE:
echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--description">' . esc_html( $formatted_entry['description'] ) . '</td>';
```

**Context Check:** If `$formatted_entry['description']` may contain HTML that should render:
```php
// If HTML should render:
echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--description">' . wp_kses_post( $formatted_entry['description'] ) . '</td>';
```

#### 3. class-wp-mcp-ai-elementor-assistant-tools-widget.php:179

**Issue:** Method-formatted text not escaped
```php
// CURRENT (LINE 179 - WRONG):
echo '<p class="wp-mcp-ai-assistant-tools__notice">' . $empty_output . '</p>';
// Where: $empty_output = $this->format_text_inline($empty_message)

// SHOULD BE:
echo '<p class="wp-mcp-ai-assistant-tools__notice">' . wp_kses_post( $empty_output ) . '</p>';
```

**Reason:** `format_text_inline()` may add HTML markup. Use `wp_kses_post()` to allow safe HTML.

#### 4. class-wp-mcp-ai-elementor-assistant-base-knowledge-widget.php:191

**Issue:** Similar to #3
```php
// CURRENT (LINE 191 - WRONG):
echo '<p class="wp-mcp-ai-assistant-base-knowledge__notice">' . $no_files_output . '</p>';
// Where: $no_files_output = $this->format_text_inline($no_files_message)

// SHOULD BE:
echo '<p class="wp-mcp-ai-assistant-base-knowledge__notice">' . wp_kses_post( $no_files_output ) . '</p>';
```

#### 5. class-wp-mcp-ai-elementor-assistant-defaults-widget.php:156

**Issue:** Formatted title output
```php
// CURRENT (LINE 156 - WRONG):
echo '<h3 class="wp-mcp-ai-assistant-defaults__title">' . $title_output . '</h3>';
// Where: $title_output = $this->format_text_inline($title)

// SHOULD BE:
echo '<h3 class="wp-mcp-ai-assistant-defaults__title">' . wp_kses_post( $title_output ) . '</h3>';
```

---

### P0: Admin Orchestration (Critical)

#### 6. class-wp-mcp-ai-tools-orchestration-renderer.php:251

**Issue:** Unescaped method return value
```php
// CURRENT (LINE 251 - WRONG):
echo WP_MCP_AI_Editable_Capability_Flags_Renderer::render( $tool_slug, $capability_flags, $has_custom_flags, $force_sync );

// SHOULD BE:
// If render() returns HTML that should display:
echo wp_kses_post( WP_MCP_AI_Editable_Capability_Flags_Renderer::render( $tool_slug, $capability_flags, $has_custom_flags, $force_sync ) );

// OR if render() already escapes internally, add comment:
echo WP_MCP_AI_Editable_Capability_Flags_Renderer::render( $tool_slug, $capability_flags, $has_custom_flags, $force_sync );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method handles escaping internally
```

**Action Required:** Check if `WP_MCP_AI_Editable_Capability_Flags_Renderer::render()` escapes output internally. If yes, add phpcs:ignore comment. If no, add `wp_kses_post()` wrapper.

#### 7. class-wp-mcp-ai-tools-orchestration-renderer.php:528

**Issue:** Similar to #6
```php
// CURRENT (LINE 528 - WRONG):
echo self::render_stat_card( $stats['tools_with_model_reqs'], __( 'With Model Reqs', 'mcp-ai-wpoos' ), '#8c68cd' );

// SHOULD BE:
echo wp_kses_post( self::render_stat_card( $stats['tools_with_model_reqs'], __( 'With Model Reqs', 'mcp-ai-wpoos' ), '#8c68cd' ) );

// OR with comment if method escapes:
echo self::render_stat_card( $stats['tools_with_model_reqs'], __( 'With Model Reqs', 'mcp-ai-wpoos' ), '#8c68cd' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method handles escaping internally
```

---

### P0: Elementor Theme Preview (Critical)

#### 8. class-wp-mcp-ai-elementor-dashboard-theme-preview-widget.php:153

**Issue:** Unescaped CSS variable in inline style
```php
// CURRENT (LINE 153 - WRONG):
echo '<div class="wp-mcp-ai-theme-preview__chat"' . $container_css . '>';
// Where: $container_css = $this->build_container_style($colors)

// SHOULD BE:
echo '<div class="wp-mcp-ai-theme-preview__chat"' . esc_attr( $container_css ) . '>';
```

**Reason:** Inline style attribute must be escaped with `esc_attr()`.

---

### P1: Other Elementor Widgets

#### 9-15. Various Elementor Widgets with format_text_inline() Output

**Pattern:** Multiple widgets use `format_text_inline()` without escaping:

```php
// WRONG PATTERN:
echo '<h3>' . $this->format_text_inline($text) . '</h3>';

// CORRECT PATTERN:
echo '<h3>' . wp_kses_post( $this->format_text_inline($text) ) . '</h3>';
```

**Files to fix:**
- class-wp-mcp-ai-elementor-assistant-prompt-shortcuts-widget.php:193
- class-wp-mcp-ai-elementor-widget.php:1340, 1492

---

## 🔧 IMPLEMENTATION STEPS

### Step 1: Create Helper Function (Optional)

Add to a utilities class for consistent escaping of method outputs:

```php
/**
 * Safely echo HTML content from internal methods.
 *
 * Use this when echoing content from methods that may return HTML.
 * Allows safe HTML tags while preventing XSS.
 *
 * @param string $content Content to echo.
 */
public static function safe_echo_html( $content ) {
    echo wp_kses_post( $content );
}
```

### Step 2: Fix Files Systematically

Work through each priority level:

**Day 1 Morning:** P0 Admin Dashboard
- Fix class-wp-mcp-ai-pro-dashboard-diagnostic.php
- Test admin dashboard
- Commit

**Day 1 Afternoon:** P0 Elementor Widgets  
- Fix 5 Elementor widget files
- Test widgets in Elementor editor
- Commit

**Day 2 Morning:** P0 Admin Orchestration
- Fix orchestration renderer files
- Verify render methods escape internally or add wrappers
- Test orchestration pages
- Commit

**Day 2 Afternoon:** P1 Remaining Widgets
- Fix remaining Elementor widgets
- Test all widgets
- Commit

### Step 3: Verify Each Fix

After each file:
```bash
# Check PHP syntax
php -l path/to/file.php

# Visual test
# 1. Load the page/widget
# 2. Verify output displays correctly
# 3. Check browser console for errors
# 4. Inspect HTML to confirm escaping worked
```

### Step 4: Run Full Validation

```bash
# Check all fixes
grep -rn "echo.*\$" includes/ --include="*.php" | grep -v "esc_html\|esc_attr\|esc_url\|wp_kses\|phpcs:ignore" | wc -l

# Should be significantly reduced from 82+
```

---

## 📋 DECISION TREE

For each unescaped echo, ask:

### Question 1: What type of content is it?

**Plain text?** → Use `esc_html()`
```php
echo esc_html( $plain_text );
```

**HTML attribute?** → Use `esc_attr()`
```php
echo '<div class="' . esc_attr( $class ) . '">';
```

**URL?** → Use `esc_url()`
```php
echo '<a href="' . esc_url( $link ) . '">';
```

**HTML content that should render?** → Use `wp_kses_post()`
```php
echo wp_kses_post( $html_content );
```

**Integer/number?** → Cast explicitly
```php
echo absint( $number );  // For positive integers
echo (int) $number;      // For any integer
```

### Question 2: Is it from a method that escapes internally?

**Check the method:**
```php
// Look inside the method being called
public function render() {
    return '<div>' . esc_html( $this->content ) . '</div>';
    // ↑ Already escaped!
}
```

**If yes,** add phpcs:ignore comment:
```php
echo $this->render();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method handles escaping internally
```

**If no,** wrap the output:
```php
echo wp_kses_post( $this->render() );
```

### Question 3: Is this standalone HTML for export?

**If yes,** and it's meant to be saved as a file:
```php
// This HTML is for download/export, not direct output to WordPress page
$html = '<div>' . $content . '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone HTML for export
echo $html;
```

---

## ⚠️ COMMON MISTAKES TO AVOID

### Mistake 1: Using esc_html() on HTML

```php
// WRONG - strips all HTML tags:
echo esc_html( '<strong>Bold text</strong>' );
// Output: &lt;strong&gt;Bold text&lt;/strong&gt;

// CORRECT - allows safe HTML:
echo wp_kses_post( '<strong>Bold text</strong>' );
// Output: <strong>Bold text</strong>
```

### Mistake 2: Not escaping at output time

```php
// WRONG - escaping too early:
$title = esc_html( get_the_title() );
// ... later ...
echo $title;  // Still flagged as unescaped!

// CORRECT - escape late:
$title = get_the_title();
// ... later ...
echo esc_html( $title );
```

### Mistake 3: Forgetting integers in printf

```php
// WRONG:
printf( 'Count: %d', $count );  // $count not sanitized

// CORRECT:
printf( 'Count: %d', absint( $count ) );
```

---

## ✅ TESTING CHECKLIST

After implementing all fixes:

### Functionality Testing
- [ ] Admin dashboard loads without errors
- [ ] All Elementor widgets display correctly
- [ ] HTML rendering works (bold, links, etc.)
- [ ] No broken layouts
- [ ] No JavaScript console errors

### Security Testing
- [ ] No unescaped variables in browser source
- [ ] Special characters display correctly (not as HTML entities when they shouldn't)
- [ ] XSS test: Try entering `<script>alert('XSS')</script>` in form fields - should be escaped

### Code Quality
- [ ] Run: `grep -rn "echo.*\$" includes/ --include="*.php" | grep -v "esc_\|phpcs:ignore" | wc -l`
- [ ] Result should be 0 or only documented exemptions
- [ ] PHP syntax check passes: `find includes/ -name "*.php" -exec php -l {} \;`
- [ ] PHPCS check passes (or only documented exemptions)

---

## 📊 PROGRESS TRACKING

Use this checklist to track fixes:

### P0: Critical (Must Fix First)
- [ ] class-wp-mcp-ai-pro-dashboard-diagnostic.php:208
- [ ] class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php:275
- [ ] class-wp-mcp-ai-elementor-assistant-tools-widget.php:179
- [ ] class-wp-mcp-ai-elementor-assistant-base-knowledge-widget.php:191
- [ ] class-wp-mcp-ai-elementor-assistant-defaults-widget.php:156
- [ ] class-wp-mcp-ai-tools-orchestration-renderer.php:251
- [ ] class-wp-mcp-ai-tools-orchestration-renderer.php:528
- [ ] class-wp-mcp-ai-elementor-dashboard-theme-preview-widget.php:153

### P1: High Priority
- [ ] class-wp-mcp-ai-elementor-assistant-prompt-shortcuts-widget.php:193
- [ ] class-wp-mcp-ai-elementor-widget.php:1340
- [ ] class-wp-mcp-ai-elementor-widget.php:1492
- [ ] [Add remaining 71 instances as you identify them]

### P2: Medium Priority
- [ ] Generated standalone HTML files (may need exemptions)
- [ ] Email templates (may need exemptions)

---

## 🚀 READY TO START

1. **Read this entire document**
2. **Start with P0 fixes** (8 critical instances)
3. **Test after each file**
4. **Commit incrementally**
5. **Move to P1** once P0 complete
6. **Run full validation** when done

**Estimated time:** 1-2 days for systematic completion of all 82+ instances.

---

**Last Updated:** 2026-01-17  
**Status:** Ready for Implementation  
**Next Action:** Begin P0 fixes starting with class-wp-mcp-ai-pro-dashboard-diagnostic.php
