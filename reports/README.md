# PHPCS Lint Report — NV oOS (Base + Pro)

**Generated**: 2026-06-15
**Standard**: WordPress (error severity 1, warning severity 8)
**Memory**: 512M

---

## Summary

| Phase | Scope | Files w/ Issues | Errors | Warnings |
|-------|-------|-----------------|--------|----------|
| 1 | Base core (`includes/` excl. tools) | 59 | 365 | 0 |
| 2 | Base tools (`includes/tools/`) | 14 | 63 | 0 |
| 3 | Pro core (`addons/pro/includes/` excl. tools) | 32 | 107 | 0 |
| 4 | Pro toolkits (44 directories) | — | 1,123 | 0 |
| **Total** | | **105+** | **1,658** | **0** |

---

## Phase 1 — Base Core

- **Report**: `phase1-base-core.txt`
- **Scope**: `includes/` excluding `includes/tools/`
- **Files with issues**: 59
- **Errors**: 365

---

## Phase 2 — Base Tools

- **Report**: `phase2-base-tools.txt`
- **Scope**: `includes/tools/` (269 files, ~140 tool classes)
- **Files with issues**: 14
- **Errors**: 63

---

## Phase 3 — Pro Core

- **Report**: `phase3-pro-core.txt`
- **Scope**: `addons/pro/includes/` excluding `addons/pro/includes/tools/`
- **Files with issues**: 32
- **Errors**: 107

---

## Phase 4 — Pro Toolkits (by directory)

| # | Toolkit | Errors | Report |
|---|---------|--------|--------|
| 1 | infrastructure | 0 | `toolkit-infrastructure.txt` |
| 2 | vector-storage | 0 | `toolkit-vector-storage.txt` |
| 3 | erp-ezuite | 0 | `toolkit-erp-ezuite.txt` |
| 4 | paper-store | 0 | `toolkit-paper-store.txt` |
| 5 | remote-connections | 0 | `toolkit-remote-connections.txt` |
| 6 | automotive | 3 | `toolkit-automotive.txt` |
| 7 | capture | 0 | `toolkit-capture.txt` |
| 8 | vault | 0 | `toolkit-vault.txt` |
| 9 | google-workspace | 0 | `toolkit-google-workspace.txt` |
| 10 | wp-all-import-export | 4 | `toolkit-wp-all-import-export.txt` |
| 11 | research | 0 | `toolkit-research.txt` |
| 12 | architect-agent | 0 | `toolkit-architect-agent.txt` |
| 13 | email-marketing | 0 | `toolkit-email-marketing.txt` |
| 14 | media | 0 | `toolkit-media.txt` |
| 15 | places | 0 | `toolkit-places.txt` |
| 16 | jetengine | 1 | `toolkit-jetengine.txt` |
| 17 | math | 7 | `toolkit-math.txt` |
| 18 | developer | 1 | `toolkit-developer.txt` |
| 19 | ai-tool-builder | 1 | `toolkit-ai-tool-builder.txt` |
| 20 | multilingual | 4 | `toolkit-multilingual.txt` |
| 21 | quiz-management | 8 | `toolkit-quiz-management.txt` |
| 22 | analytics | 8 | `toolkit-analytics.txt` |
| 23 | comic-creation | 8 | `toolkit-comic-creation.txt` |
| 24 | extended-cognition | 0 | `toolkit-extended-cognition.txt` |
| 25 | video-production | 0 | `toolkit-video-production.txt` |
| 26 | dj-management | 1 | `toolkit-dj-management.txt` |
| 27 | calendar-booking | 9 | `toolkit-calendar-booking.txt` |
| 28 | dietpi | 60 | `toolkit-dietpi.txt` |
| 29 | social-media | 3 | `toolkit-social-media.txt` |
| 30 | document-generation | 8 | `toolkit-document-generation.txt` |
| 31 | orchestration | 20 | `toolkit-orchestration.txt` |
| 32 | financial-planning | 14 | `toolkit-financial-planning.txt` |
| 33 | site-creator-toolkit | 1 | `toolkit-site-creator-toolkit.txt` |
| 34 | eca-management | 0 | `toolkit-eca-management.txt` |
| 35 | image-production | 0 | `toolkit-image-production.txt` |
| 36 | ecommerce | 8 | `toolkit-ecommerce.txt` |
| 37 | architectural-design | 93 | `toolkit-architectural-design.txt` |
| 38 | project-management | 53 | `toolkit-project-management.txt` |
| 39 | chat-channels | 7 | `toolkit-chat-channels.txt` |
| 40 | cre-debt | 9 | `toolkit-cre-debt.txt` |
| 41 | regulatory-registration | 23 | `toolkit-regulatory-registration.txt` |
| 42 | cloudways | 18 | `toolkit-cloudways.txt` |
| 43 | law-firm | 64 | `toolkit-law-firm.txt` |
| 44 | healthcare | 16 | `toolkit-healthcare.txt` |
| 45 | crm | 671 | `toolkit-crm.txt` |

**Toolkit total**: **1,123 errors** across 44 directories (17 clean, 27 with issues)

---

## Top 10 Toolkits by Error Count

| Toolkit | Errors |
|---------|--------|
| crm | 671 |
| architectural-design | 93 |
| law-firm | 64 |
| dietpi | 60 |
| project-management | 53 |
| regulatory-registration | 23 |
| orchestration | 20 |
| cloudways | 18 |
| healthcare | 16 |
| financial-planning | 14 |

---

## How to Use These Reports

Each report file contains the full PHPCS output (file paths, line numbers, error messages).

To fix a specific toolkit:
1. Open `reports/toolkit-NAME.txt`
2. Work through errors top-to-bottom
3. Re-run the lint command to verify fixes:
   ```
   ./vendor/bin/phpcs -d memory_limit=512M --standard=WordPress --error-severity=1 --warning-severity=8 addons/pro/includes/tools/NAME/
   ```

To re-run any phase:
```
# Phase 1
./vendor/bin/phpcs -d memory_limit=512M --standard=WordPress --error-severity=1 --warning-severity=8 \
  --ignore=*/tools/*,*/vendor/*,*/node_modules/*,*/assets/examples/*,*/bin/*,*/lib/*,*/docker/*,*/phpcs/*,*/tests/helpers/*,*/tests/fixtures/*,test-capability-flags-integration.php,*-backup.php includes/

# Phase 2
./vendor/bin/phpcs -d memory_limit=512M --standard=WordPress --error-severity=1 --warning-severity=8 includes/tools/

# Phase 3
./vendor/bin/phpcs -d memory_limit=512M --standard=WordPress --error-severity=1 --warning-severity=8 \
  --ignore=*/tools/*,*/vendor/*,*/node_modules/*,*/assets/*,*/bin/*,*/docs/*,*/config/*,*/languages/*,*/scripts/*,*/services/*,*/node-services/* addons/pro/includes/
```
