# PHPCS Violations Decision Flowchart

**WordPress Plugin Submission:** ✅ Ready - Use this flowchart for compliance decisions

```
┌─────────────────────────────────────────────┐
│  Start: PHPCS Violation Found (667 total)  │
└─────────────────┬───────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────┐
│  Is it auto-fixable by PHPCBF?              │
└─────────┬───────────────────────────────────┘
          │
    YES ──┤── Already Fixed ✅ (Initial run)
          │
     NO ──┤
          │
          ▼
┌─────────────────────────────────────────────┐
│  Is it a file naming issue?                 │
│  (38 instances)                             │
└─────────┬───────────────────────────────────┘
          │
    YES ──┤──► 🔴 SUPPRESS ONLY
          │    - Breaking change
          │    - Breaks autoloader
          │    - Add phpcs:ignore comment
          │
     NO ──┤
          │
          ▼
┌─────────────────────────────────────────────┐
│  Is it unused function parameter?           │
│  (90 instances)                             │
└─────────┬───────────────────────────────────┘
          │
    YES ──┤──► ⚪ NO ACTION
          │    - Interface requirement
          │    - WordPress hook signature
          │    - Already documented
          │
     NO ──┤
          │
          ▼
┌─────────────────────────────────────────────┐
│  Is it direct DB query or error_log?        │
│  (73 instances: 28 DB + 45 error_log)       │
└─────────┬───────────────────────────────────┘
          │
    YES ──┤──► 🔴 SUPPRESS ONLY
          │    - Architectural decision
          │    - Plugin logging system
          │    - Add suppression with reason
          │
     NO ──┤
          │
          ▼
┌─────────────────────────────────────────────┐
│  Is it prepared SQL issue?                  │
│  (16 instances)                             │
└─────────┬───────────────────────────────────┘
          │
    YES ──┤──► 🟡 REVIEW REQUIRED
          │    ├─ Is it actually unsafe?
          │    │  └─► FIX: Use $wpdb->prepare()
          │    │
          │    └─ Is it false positive?
          │       └─► SUPPRESS: Add explanation
          │
     NO ──┤
          │
          ▼
┌─────────────────────────────────────────────┐
│  Is it security-related?                    │
│  (Nonce, escaping, sanitization)            │
│  (4-7 instances)                            │
└─────────┬───────────────────────────────────┘
          │
    YES ──┤──► 🟡 HIGH PRIORITY FIX
          │    - Review context
          │    - Add nonce verification
          │    - Add proper escaping
          │    - Test thoroughly
          │
     NO ──┤
          │
          ▼
┌─────────────────────────────────────────────┐
│  Is it documentation-only?                  │
│  (@throws, translator comments)             │
│  (16 instances)                             │
└─────────┬───────────────────────────────────┘
          │
    YES ──┤──► 🟢 PHASE 1: Quick Fix
          │    - No logic changes
          │    - Add missing docs
          │    - Low risk
          │
     NO ──┤
          │
          ▼
┌─────────────────────────────────────────────┐
│  Is it performance-related?                 │
│  (Loop optimization, global vars)           │
│  (9 instances)                              │
└─────────┬───────────────────────────────────┘
          │
    YES ──┤──► 🟢 PHASE 2: Performance Fix
          │    - Cache loop conditions
          │    - Rename conflicting vars
          │    - Test after changes
          │
     NO ──┤
          │
          ▼
┌─────────────────────────────────────────────┐
│  Is it code style?                          │
│  (Yoda, ternary, lonely if)                 │
│  (48 instances)                             │
└─────────┬───────────────────────────────────┘
          │
    YES ──┤──► 🟢 PHASE 3: Style Fix
          │    - Yoda conditions (careful!)
          │    - Short ternary → full
          │    - Combine nested ifs
          │    - Review each change
          │
     NO ──┤
          │
          ▼
┌─────────────────────────────────────────────┐
│  Is it documented in                        │
│  PHPCS_IGNORE_ANALYSIS.md?                  │
│  (379 instances)                            │
└─────────┬───────────────────────────────────┘
          │
    YES ──┤──► ⚪ NO ACTION
          │    - Intentional design
          │    - Future feature
          │    - Already explained
          │
     NO ──┤
          │
          ▼
┌─────────────────────────────────────────────┐
│  Uncategorized violation                    │
│  - Review SAFE_PHPCS_FIX_PLAN.md           │
│  - Consult team lead                        │
│  - Document decision                        │
└─────────────────────────────────────────────┘
```

## Legend

- ✅ **Already Done** - Auto-fixed by PHPCBF
- 🟢 **Safe to Fix** - Low risk, proceed with testing
- 🟡 **Caution** - Review required, medium-high risk
- 🔴 **Danger** - Suppress only, do not modify
- ⚪ **No Action** - Documented/intentional

## Statistics

| Category | Count | Action |
|----------|-------|--------|
| Auto-fixed ✅ | 10 | Done |
| Safe to fix 🟢 | 71 | Implement |
| Review required 🟡 | 20 | Careful |
| Suppress only 🔴 | 127 | Document |
| No action ⚪ | 469 | None |
| **TOTAL** | **667** | - |

## Quick Decision Table

| Violation Type | Symbol | Decision |
|----------------|--------|----------|
| File naming | 🔴 | Suppress |
| Unused parameter | ⚪ | No action |
| Direct DB query | 🔴 | Suppress |
| error_log call | 🔴 | Suppress |
| Prepared SQL | 🟡 | Review |
| Missing @throws | 🟢 | Fix |
| Translator comment | 🟢 | Fix |
| Lonely if | 🟢 | Fix |
| Loop optimization | 🟢 | Fix |
| Nonce verification | 🟡 | Fix |
| Global var override | 🟡 | Fix |
| Short ternary | 🟢 | Fix |
| Yoda condition | 🟡 | Fix (careful) |
| Increment usage | 🔴 | Suppress |

---

**Use this flowchart** when encountering any PHPCS violation to quickly determine the correct action.

**For WordPress.org submission:** All decisions follow WordPress Plugin Review Guidelines. See **PHPCS_DOCUMENTATION_INDEX.md** for complete submission checklist.
