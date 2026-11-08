# Proposal 131 — PHP 8.1 Baseline (single floor for base + Pro)

- **Date:** 2026-11-08
- **Status:** **Shipped** (this branch). Enables [Proposal 132](132-functional-type-signatures-proposal.md).
- **Owner:** Core
- **Supersedes:** the "two PHP version targets" rule in `CLAUDE.md`, `.context/conventions.md`, and `AGENTS.md`.
- **Compatibility field (for PRDs):** **PHP 8.1+** — for base plugin, Pro addon, and all `lib/*` packages.

---

## 1. Decision

NV oOS moves from **two floors** (base plugin 7.4, Pro addon 8.1) to **one
floor: PHP 8.1**, enforced in the plugin header, `readme.txt`, `composer.json`,
the PHPCompatibilityWP gate, and every runtime version guard.

The base/Pro split stops being a *PHP-version* distinction and becomes what it
always was in practice: a **tool-set** distinction.

## 2. Motivation

| # | Reason | Evidence |
|---|---|---|
| 1 | **7.4 has been end-of-life since 2022-11-28.** Keeping a distributed WordPress.org plugin on a four-year-EOL branch is a supply-chain liability, not a compatibility feature. | PHP supported-versions |
| 2 | **The split was already fictional inside `lib/`.** `lib/core/composer.json` requires `"php": "^8.1"` and `lib/wordpress-adapter/composer.json` also requires `"^8.1"`. Only the plugin-side guards said 7.4. | measured |
| 3 | **The 7.4 floor blocked type work.** `lib/core/phpstan.neon.dist` recorded level 8 as blocked by ~251 "no value type specified in iterable type array" errors, explicitly justified by "PHP 7.4 compatibility in the WordPress adapter". | measured |
| 4 | **CI already runs 8.2 everywhere.** 28 workflow files pin `php-version: '8.2'`. Nothing has executed the 7.4 path in CI for some time — the floor was aspirational, not tested. | `.github/workflows/*.yml` |
| 5 | **WordPress itself supports 8.1.** WordPress 6.x runs on 8.1/8.2; every mainstream host offers 8.1+. | WordPress requirements |
| 6 | **Pro was already 8.1**, so the shared code that touched both tiers had to stay 7.4-shaped for no benefit. | `addons/pro` |

**Net:** the 7.4 floor bought no compatibility we could verify and cost us the
entire PHP 8.1 type vocabulary. That trade is now reversed.

### 2.1 Why 8.1 specifically (and not 8.2)

8.1 is the **lowest** floor that (a) matches what `lib/core` and
`lib/wordpress-adapter` already require, (b) gives us enums, `readonly`, union
types, and `never`, and (c) keeps the widest host population reachable in one
step. It is not a security argument: 8.1's own security support ended
**2025-12-31**.

So 8.1 is a *bridge*, not a destination:

| | Floor | CI | Compat gate |
|---|---|---|---|
| Now (this proposal) | 8.1 | 8.2 | 8.1–8.4 |
| Recommended next | 8.2 (security support to 2026-12-31) | 8.2 | 8.2–8.4 |

Nothing in the type work below depends on 8.2-only syntax, so the follow-up
bump stays cheap.

## 3. What changed

| Surface | Before | After |
|---|---|---|
| Plugin header (`mcp-ai-wpoos.php`) | `Requires PHP: 7.4` | `Requires PHP: 8.1` |
| `readme.txt` | `Requires PHP: 7.4`; "Base Plugin (PHP 7.4+)" and "Pro Addon (PHP 8.1+ required)" | `Requires PHP: 8.1`; both distributions 8.1+, split described as tool sets |
| `composer.json` | no `php` constraint | `"php": ">=8.1"` |
| `composer.json` scripts | `lint:compat` / `lint:base:compat` pinned `testVersion 7.4-8.3` | `testVersion 8.1-8.4` |
| `.github/workflows/php-linting.yml` | `testVersion 7.4-8.3` | `testVersion 8.1-8.4` |
| Runtime guards | 51 × `version_compare( PHP_VERSION, '7.4.0', '<' )` | `'8.1.0'` |
| Guard comments | 51 × "Prevent parse errors on PHP < 7.4 …" | "… on PHP < 8.1 …" |
| Admin diagnostics | "Minimum: 7.4", `version_compare(…, '7.4', '>=')` | 8.1 |
| Site-health tool fallbacks | `'recommended_version' => '7.4'` | `'8.1'` |
| Addon plugin headers | 20 × `Requires PHP: 7.4` | `Requires PHP: 8.1` |
| Pro portability notes | "Designed PHP 7.4-compatible", "no enums, readonly, or named arguments" | 8.1-compatible; 8.1 features permitted |
| Agent docs | `AGENTS.md`, `CLAUDE.md`, `.codex/README.md`, `.github/copilot-instructions.md` | single 8.1 floor |
| Context library | `.context/conventions.md` (PHP section + Base-vs-Pro table), `.context/cross-platform-extraction.md`, `.context/oos-engine.md`, both `.context/templates/*` | 8.1 |
| Skill | `.agents/skills/mcp-ai-wpoos-assistant-portability/SKILL.md` item 6 | 8.1 floor |
| `lib/core/phpstan.neon.dist` | "blocked by … PHP 7.4 compatibility" | blocker removed; level-8 plan restated |
| Developer/feature docs | ~46 files with floor statements | 8.1+, compat ranges 8.1–8.4 |

**Total: 156 files**, all mechanical. No behaviour change, no class renames,
no API changes.

### 3.1 Single source of truth

`mcp-ai-wpoos.php` now defines the floor once:

```php
if ( ! defined( 'WP_MCP_AI_MIN_PHP' ) ) {
	define( 'WP_MCP_AI_MIN_PHP', '8.1.0' );
}

if ( version_compare( PHP_VERSION, WP_MCP_AI_MIN_PHP, '<' ) ) { … }
```

The admin notice reads the same constant, so the header, the guard, and the
operator-facing message can no longer drift apart.

## 4. Policy consequences

1. **WordPress.org listing.** `Requires PHP: 8.1` is what wp.org renders and
   what the install button checks. Sites on ≤8.0 will be refused installation
   rather than fatalling after activation.
2. **Existing installs on 7.4** will be deactivated on update by WordPress's own
   PHP-requirement handling; the plugin's own guard produces the admin notice and
   self-deactivation as a fallback for direct/mu-plugin loads.
3. **Contributor floor** is now genuinely uniform: one runtime locally, one in
   CI (`8.2`), one in the compat gate (`8.1–8.4`).
4. **Standalone plugins are out of scope.** `nvoos-docs-hub`,
   `nvoos-content-graph`, and `nvoos-design-system` are distributed
   independently and keep their own floors. The generic `wp-*` skills keep
   `php-min: "7.4"` because they describe WordPress patterns generally, not
   this repository.

## 5. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Installer on 7.4 loses the plugin after update | Medium | Medium | Release note + wp.org "Requires PHP" surfaces it at install/update; guard shows a clear admin notice and self-deactivates |
| A host reports 8.0 only | Low | Medium | Documented upgrade path to 8.1 (8.0 is also EOL) |
| 8.1-only syntax snuck in without tests | Low | Low | `lint:compat` now pins `8.1-8.4`, so *nothing below 8.1* can pass; PHPUnit on 8.2 covers runtime |
| Doc/code drift on the floor | Low | Low | `WP_MCP_AI_MIN_PHP` constant + the updated agent docs; the floor is stated in one table per doc |

## 6. Validation

- `php -l` clean on the main entry point and on every file with a structural
  edit (composer note, pro composition, architectural engines, site-health,
  verifier, relevance trait, site-creator settings).
- `composer.json` parses; `require.php` = `>=8.1`; both compat scripts read
  `8.1-8.4`.
- Repo-wide scan: zero remaining floor statements at 7.4 in `includes/`,
  `addons/`, `lib/`, or the agent-facing docs. Remaining `7.4` hits are
  vendored code, SVG path data, section numbers (`### 7.4 …`), historical
  release notes, or accurate historical facts ("available since PHP 7.4").
- Not run: full PHPUnit suite and PHPCS (both large). Recommended gate before
  merge: `composer run lint:compat` + `composer run test`.

## 7. Follow-ups

| # | Item |
|---|---|
| 1 | Release note: "NV oOS now requires PHP 8.1+ (was 7.4 for base)." |
| 2 | wp.org submission note explaining the floor change and the 8.1 EOL rationale. |
| 3 | Opportunistic cleanups now unblocked — not mandatory, do them where touched: `strpos()` → `str_contains()`, `switch` → `match`, array constants → enums, typed properties. |
| 4 | Optional: set the repo-specific `php-min` on the `wp-*` skills if they are ever treated as repo policy rather than generic WordPress guidance. |
| 5 | Execute [Proposal 132](132-functional-type-signatures-proposal.md) — the type work this bump unlocks. |
| 6 | Plan the **8.2** floor (8.1 security support ended 2025-12-31). The type work does not depend on 8.2-only syntax, so this is a one-line gate + guard change plus a release note. |

## 8. Sources

- PHP supported versions: <https://www.php.net/supported-versions.php>
- WordPress PHP compatibility: <https://make.wordpress.org/hosting/handbook/handbook/php/>
- In-repo evidence: `lib/core/composer.json`, `lib/wordpress-adapter/composer.json`,
  `lib/core/phpstan.neon.dist`, `.github/workflows/php-linting.yml`
- Related: [Proposal 132 — Functional type signatures](132-functional-type-signatures-proposal.md)
