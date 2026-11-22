# WPCS budget ratchet

The tree-wide WordPress Coding Standards budget lives in
[`.github/phpcs-budget.json`](../../.github/phpcs-budget.json) and is enforced by
[`.github/scripts/phpcs-ratchet.php`](../../.github/scripts/phpcs-ratchet.php) in
the `PHP Linting` workflow.

**The budget only shrinks.**

## Why it exists

The previous gate allowed *ten errors per changed file*. That is a per-file
allowance, so:

- a large file could keep adding errors indefinitely;
- a PR touching twenty files could leave the tree two hundred findings worse
  off and still pass;
- warnings were not counted at all.

The push-to-`main` job did compare a tree-wide total, but the number was written
into the workflow (`1175`, with a `1200` ceiling) and nothing moved it. The
branch that noticed an improvement only printed a message — the improvement was
never locked in, so the next regression could spend it.

The result was a tree carrying roughly **3,000 errors and 5,700 warnings** while
CI reported success on every PR. A baseline without a ratchet is a suggestion.

## What it enforces

1. **No scope over budget.** Every scope in `scopes` has committed `errors` and
   `warnings` counts. A scope over either fails — with a small `noise` allowance
   (10) for run-to-run measurement variation, explained below.

2. **No stale slack.** A scope that comes in more than `tolerance` (currently 25)
   *under* budget also fails, with the message to run:

   ```bash
   php .github/scripts/phpcs-ratchet.php --update
   ```

   and commit the budget file. This is the half that makes it a ratchet: an
   improvement has to be recorded in the same PR, so it cannot be quietly given
   back later.

3. **Named sources carry their own budgets.** The security, database and
   repo-specific gate sniffs are budgeted individually, so a regression in
   `WordPress.Security.EscapeOutput` cannot hide inside a scope total that
   happens to have improved. These are the categories where growth is not
   interchangeable with style debt.

4. **Zero-tolerance sources must be zero.** Parse errors (`Internal.Exception`)
   are not debt. Neither is a rule the tree has already fully satisfied: the
   sanitize-at-entry and decision-scope sniffs are at zero, and the ratchet keeps
   them there. `--update` refuses to run while a zero-tolerance source is above
   zero, so a parse error cannot be "budgeted".

5. **No uncovered tree.** Paths are matched first-match-wins against the
   ordered `scopes` list, and the last scope (`uncovered`, empty `paths`) is the
   catch-all. It is committed at `0 / 0` on purpose: a file that belongs to no
   declared scope fails the gate, which is how a new top-level directory becomes
   a deliberate decision rather than a blind spot.

## Scopes

Order matters — specific prefixes come before general ones.

| Scope | Paths | Errors | Warnings |
|---|---|---|---|
| `includes` | `includes/` | 120 | 706 |
| `addons-pro` | `addons/pro/` | 444 | 2221 |
| `addons-other` | `addons/` | 270 | 916 |
| `tests` | `tests/` | 1843 | 1759 |
| `plugins` | `plugins/` | 106 | 13 |
| `core-trees` | `core/`, `shared/`, `.agents/`, `blueprints/` | 198 | 12 |
| `misc` | `examples/`, `.github/`, `mcp-ai-wpoos.php`, `mcp-ai-wpoos-base.php` | 28 | 31 |
| `uncovered` | *(catch-all)* | 0 | 0 |

`lib/`, `docker/`, `bin/`, `phpcs/`, `vendor/`, `assets/examples/`,
`tests/helpers/`, `tests/fixtures/`, the `nvoos-content-graph*` plugins and the
`nvoos-astro-bridge` / `nvoos-wifi` addons are excluded by `phpcs.xml.dist`
itself and therefore produce no messages to budget. They are enforced by their
own rulesets in the same workflow.

## Everyday use

```bash
composer run lint:ratchet          # check against the committed budget
composer run lint:ratchet:update   # record the current numbers
composer run lint                  # see the findings themselves
composer run format                # auto-fix the fixable ones
```

`--update` **refuses to raise a budget** unless you pass `--allow-increase`:

```bash
php .github/scripts/phpcs-ratchet.php --update --allow-increase
```

Raising a budget is a reviewed decision, so it should appear as a diff in the PR
with a reason. Lowering one is routine and expected.

Adding a source or scope is a deliberate edit to `.github/phpcs-budget.json`;
`--update` refreshes the values of keys that already exist and never invents new
ones. That keeps the set of budgeted categories reviewable.

## Adopting the gate on an existing tree

The baseline was measured on `alpha-working@0dfe12b535` and covers 5,588 files
with findings. If you introduce a new tree, add it to `scopes` with its measured
values in the same PR; the `uncovered` catch-all fails until you do.

## Debugging without a full scan

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist --report=json -q . > build/phpcs.json || true
php .github/scripts/phpcs-ratchet.php --check --input=build/phpcs.json
```

`--input` reads a saved PHPCS report instead of running PHPCS, which is useful
when a CI run fails and you want to inspect the aggregation locally. `--budget=`
points the gate at an alternative budget file, and `--standard=` at an
alternative ruleset.

## Determinism and the `noise` allowance

The first CI runs of this gate disagreed with each other by a few warnings on
the same tree. Two causes, both structural:

- a handful of WPCS sniffs are **order-sensitive**, and PHPCS's `--cache`
  changes which files are re-tokenised in a run, so a cached run and a cold run
  can differ;
- some sniffs tokenise slightly differently across **PHP minor versions**, so a
  PHP 8.3 checkout and the PHP 8.2 runner can differ.

Every **error** count and every **named source** count matched exactly across
every run; only generic style warnings moved, by up to 9 on a 5,600-warning
tree.

The gate therefore runs **without `--cache`**, and the over-budget check allows
`noise` (10). Noise is not slack: it is smaller than any real regression and is
applied only upward, so a scope that grows by a dozen warnings still fails. The
stale side keeps its separate `tolerance` (25).

The PHPCS version and `.gitattributes` line endings are not sources of drift
here: the lockfile pins PHPCS and the tree is LF in every checkout. If a future
PHPCS or WPCS upgrade changes a sniff's behaviour, the numbers move — run
`--update` (with `--allow-increase` only for a genuine increase) in the same PR
as the dependency bump, and say so in the description.
