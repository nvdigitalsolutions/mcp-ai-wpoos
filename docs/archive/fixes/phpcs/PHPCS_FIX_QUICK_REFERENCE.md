# PHPCS Fix Quick Reference

**Last Updated:** 2026-02-01  
**Full Plan:** See `SAFE_PHPCS_FIX_PLAN.md`

---

## 🚦 Traffic Light System

| Symbol | Meaning | Action |
|--------|---------|--------|
| 🟢 | **Safe** | Fix it |
| 🟡 | **Caution** | Review carefully before fixing |
| 🔴 | **Danger** | Suppress, don't fix |
| ⚪ | **Documented** | No action needed |

---

## 📋 At-a-Glance Summary

| Violation Type | Count | Status | Priority |
|----------------|-------|--------|----------|
| Missing @throws tags | 12 | 🟢 Safe | Phase 1 |
| Missing translator comments | 4 | 🟢 Safe | Phase 1 |
| Lonely if statements | 6 | 🟢 Safe | Phase 1 |
| Array size in loops | 3 | 🟢 Safe | Phase 2 |
| Short ternary | 13 | 🟢 Safe | Phase 3 |
| Nonce verification | 4 | 🟡 Caution | Phase 2 ⚠️ |
| Global var override | 6 | 🟡 Caution | Phase 2 |
| Yoda conditions | 29 | 🟡 Caution | Phase 3 |
| **File naming** | **38** | **🔴 Danger** | **Suppress** |
| **Direct DB queries** | **28** | **🔴 Danger** | **Suppress** |
| **Prepared SQL** | **16** | **🔴 Danger** | **Review first** |
| **error_log calls** | **45** | **🔴 Danger** | **Suppress** |
| Unused parameters | 90 | ⚪ Documented | None |
| Others | 379 | ⚪ Various | See plan |

---

## 🎯 Quick Start Commands

### Find Specific Violations

```bash
# Missing @throws tags
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=Squiz.Commenting.FunctionComment.ThrowTagMissing

# Missing translator comments
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=WordPress.WP.I18n.MissingTranslatorsComment

# Lonely if statements
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=Universal.ControlStructures.DisallowLonelyIf

# Short ternary operators
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=Universal.Operators.DisallowShortTernary

# Yoda conditions
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=WordPress.PHP.YodaConditions

# File naming issues
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=WordPress.Files.FileName

# Direct DB queries
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=WordPress.DB.DirectDatabaseQuery
```

---

## 🚀 Implementation Priority

### HIGH PRIORITY (Security)
1. 🟡 **Nonce verification** (4 instances)
2. 🔴 **Prepared SQL** (16 instances) - Review each!

### MEDIUM PRIORITY (Performance)
3. 🟢 **Array size in loops** (3 instances)
4. 🟡 **Global var override** (6 instances)

### LOW PRIORITY (Code Quality)
5. 🟢 **Missing @throws** (12 instances)
6. 🟢 **Translator comments** (4 instances)
7. 🟢 **Lonely if** (6 instances)
8. 🟢 **Short ternary** (13 instances)
9. 🟡 **Yoda conditions** (29 instances)

### DOCUMENTATION (Suppressions)
10. 🔴 **File naming** (38) - Add suppressions
11. 🔴 **Direct DB** (28) - Add suppressions
12. 🔴 **error_log** (45) - Add suppressions

---

## 📖 Common Patterns

### Adding @throws Tag
```php
/**
 * Process upload.
 * @param array $file File data.
 * @return int Attachment ID.
 * @throws WP_Error If validation fails.  // ← ADD
 */
```

### Adding Translator Comment
```php
/* translators: Settings page title */
__( 'Settings', 'mcp-ai-wpoos' );
```

### Fixing Lonely If
```php
// Before
if ( $a ) {
    if ( $b ) { /* code */ }
}

// After
if ( $a && $b ) { /* code */ }
```

### Caching Loop Size
```php
// Before
for ( $i = 0; $i < count( $items ); $i++ ) {}

// After
$count = count( $items );
for ( $i = 0; $i < $count; $i++ ) {}
```

### Converting Short Ternary
```php
// Before
$name = $user->name ?: 'Anonymous';

// After
$name = $user->name ? $user->name : 'Anonymous';
// or
$name = $user->name ?? 'Anonymous';
```

### Yoda Condition
```php
// Before
if ( $status === 'active' ) {}

// After
if ( 'active' === $status ) {}
```

### Suppressing File Naming
```php
// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
class WP_MCP_AI_Tool_Example {}
```

### Suppressing Direct DB Query
```php
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table requires direct access
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient data
$results = $wpdb->get_results( $sql );
```

---

## ⚠️ Critical Safety Rules

### DO NOT:
- ❌ Rename class files (breaks autoloader)
- ❌ Remove `error_log()` without reviewing logging architecture
- ❌ Change direct DB queries without understanding query complexity
- ❌ Modify SQL preparation without security review
- ❌ Change unused parameters (may be interface/hook requirements)

### DO:
- ✅ Add documentation (@throws, translators)
- ✅ Optimize loop conditions
- ✅ Fix missing nonce checks (after review)
- ✅ Add suppressions with clear explanations
- ✅ Test after every change

---

## 🧪 Testing Checklist

After each change:
- [ ] Run PHPCS on changed files
- [ ] Run PHPUnit tests
- [ ] Test plugin activation
- [ ] Manually test affected features
- [ ] Review git diff
- [ ] Commit with clear message

---

## 📊 Progress Tracking

Track your progress:

```markdown
### Phase 1: Quick Wins
- [ ] Missing @throws tags (12)
- [ ] Missing translator comments (4)
- [ ] Lonely if statements (6)

### Phase 2: Performance & Safety
- [ ] Array size in loops (3)
- [ ] Nonce verification (4)
- [ ] Global var override (6)

### Phase 3: Code Style
- [ ] Short ternary (13)
- [ ] Yoda conditions (29)

### Phase 4: Suppressions
- [ ] File naming (38)
- [ ] Direct DB queries (28)
- [ ] error_log calls (45)
```

---

## 🆘 Need Help?

- **Full documentation:** `SAFE_PHPCS_FIX_PLAN.md`
- **Unused params analysis:** `PHPCS_IGNORE_ANALYSIS.md`
- **WordPress standards:** https://developer.wordpress.org/coding-standards/
- **PHPCS docs:** https://github.com/squizlabs/PHP_CodeSniffer/wiki

---

**Total Violations:** 667  
**Safe to Fix:** 71  
**Requires Review:** 36  
**Suppress Only:** 127  
**Documented/Intentional:** 433
