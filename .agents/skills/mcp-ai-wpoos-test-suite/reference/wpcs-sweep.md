# WPCS / phpcs sweep playbook

How the `tests/` commenting class went **878 → 0** in six PRs (2026-11-23)
without breaking the tree, and the traps that would otherwise be relearned.
Applies to any tree-wide phpcs cleanup gated by the budget ratchet.

## The gate you are feeding

- Budget: `.github/phpcs-budget.json`. Runner:
  `.github/scripts/phpcs-ratchet.php`. `composer run lint:ratchet` /
  `composer run lint:ratchet:update`.
- `errors` / `warnings` per scope are **ceilings**. Five rules: no scope over
  budget (`noise` 10 absorbs run-to-run drift), no stale slack beyond
  `tolerance` 25, named sources own budgets, `zero_tolerance` sniffs must be
  zero, and the `0/0` catch-all fails on any uncovered tree.
- **Local and CI agree exactly on errors**; warnings drift by ±1–2. Record the
  local error count. Leave warnings alone unless the change genuinely moved
  them — raising a committed budget to absorb drift is against the policy.

## 1. Measure, then do arithmetic

```bash
php -d memory_limit=2048M vendor/bin/phpcs --standard=phpcs.xml.dist \
  --report=json --report-file=.tmp-tests.json -q tests/
```

Read `totals.errors` / `totals.warnings` from the JSON. The summary
report's `A TOTAL OF …` line scrolls past in a long log — piping `tail`
over it loses the only number the arithmetic needs.

**The contract**: `previous_measured − slice_size == new_measured`. If it is
not, you introduced or missed something — and the delta is the only signal
that says so:

| PR | Predicted | Measured | What the delta actually was |
|---|---|---|---|
| #8123 | 1,298 | 1,300 | `@return` on a throwing stub (`InvalidNoReturn`) |
| #8126 | 1,136 | 1,137 | my own extra alignment space (`DocCommentAlignment`) |
| #8132 | 927 | 1,016 | an 87-error `ScopeIndent` cascade from a reindent helper |

A scoped sniff scan reported "0 remaining" in every one of those cases.

## 2. The sniff set is five, not four

`Squiz.Commenting.DocCommentAlignment` sits outside the obvious four and is
what catches a docblock whose `*` column has drifted:

```bash
phpcs --standard=phpcs.xml.dist \
  --sniffs=Squiz.Commenting.FunctionComment,Squiz.Commenting.InlineComment,Squiz.Commenting.VariableComment,Generic.Commenting.DocComment,Squiz.Commenting.DocCommentAlignment \
  --report=source --no-colors --warning-severity=0 <files>
```

`--sniffs` takes **full sniff codes** — not categories (`Squiz.Commenting`)
and not message codes (`….SuccessFalseArray`).

## 3. Never blind-`phpcbf` the array or signature sniffs

In this tree `WordPress.Arrays.ArrayDeclarationSpacing` and
`PEAR.Functions.FunctionCallSignature` re-emit tab-indented code as
**space-indented blocks with trailing whitespace**:

```
-			->willReturn( array(
+			->willReturn(
+                array(          <- spaces, not tabs
				'response' => '…',
-			) );
+                )               <- trailing whitespace
+            );
```

Always read the diff first:

```bash
phpcs --standard=phpcs.xml.dist --sniffs=<codes> --report=diff <paths>
```

`PSR12.Traits.UseDeclaration` was safe (a single blank line). Treat every
other "auto-fixable" sniff as suspect until its diff has been read.

### The restricted form is the trap, not `phpcbf`

`--sniffs=WordPress.Arrays.ArrayDeclarationSpacing` is the failing
combination, not the fixer itself. It expands the array but cannot indent
it, because `ArrayIndentation` and `MultipleStatementAlignment` are
excluded from the run: contents land at column 0 with no trailing comma.

Splitting the colliding closers afterwards by hand trades one error for
two classes — on the twelve-file slice, 173
`NormalizedArrays.CommaAfterLast.MissingMultiLine` and 101
`PEAR.Functions.FunctionCallSignature` appeared, a net **+100**.

The **full standard** converges in one pass: it adds the commas and reflows
the enclosing call so the PEAR signature rules stay satisfied. Verify with
`git diff --check` (clean), `php -l`, and a per-source delta that shows no
source count rising. Restricting `--sniffs` is right for measuring; wrong
for fixing.

## 4. Split deterministic from manual

- **Deterministic** (safe to drive from the JSON report): append a full stop to
  a flagged `//` comment; capitalise the first letter of a short description.
  Fix only the flagged lines — `--report=json`, then rewrite by line number.
- **Manual**: `FunctionComment.Missing`, `DocComment.MissingShort`,
  `VariableComment.Missing` / `MissingVar`, `WrongStyle`, any `@param`.

Behaviour that surprises people:

- **A comment-only catch still counts as empty**
  (`Generic.CodeAnalysis.EmptyStatement.DetectedCatch`). The natural fix —
  leaving `// Expected: …` inside the block — does not suppress it, because
  the sniff treats a body with no statement as empty whatever the comment
  says. It needs the ignore on the `catch` line itself. Append it as a
  trailing comment rather than inventing a second convention: 72 sites in
  `tests/` already used that form, so check the family first and match it.
  Confirm placement on a file that is already clean before applying it at
  scale — an ignore on the following line only works if it is *above* the
  `catch`, which reads as a stray comment in the try block.
- **A sniff stops at the first problem in a docblock.** Capitalising the short
  description can reveal a `LongNotCapital` long description on the next run,
  so the count may not fall by exactly the number you fixed.
- **`WrongStyle` usually means "no docblock at all"** — a blank line above the
  function, not a formatting complaint. Same fix as `Missing`.
- **`@return` on a throwing function is an error** (`InvalidNoReturn`). Use
  `@throws`. A stub that declares `@return array` and returns a string is not
  flagged; only the no-return case is.
- **`VariableComment.MissingVar`** is a docblock that has a description but no
  `@var`; add the tag, not the description. Note that `WrongStyle` and
  `Missing` share the message suffix `Missing`, so the JSON `source` field is
  the reliable discriminator.

## 5. Structural edit recipes

- **Docblock indentation comes from the `/**` line**, never from a content
  line. A content line's indent already includes the alignment space; deriving
  from it yields two spaces and trips `DocCommentAlignment`.
- **Repeated identical stubs defeat text anchors.** Three anonymous classes
  with the same `get_tool()` need a **line-targeted, bottom-up** insert driven
  by the phpcs line numbers (bottom-up keeps earlier line numbers valid), with
  indentation read from the anchor line.
- **Do not write a "reindent every `*` line" helper.** One attempt rewrote 87
  lines and introduced 87 `ScopeIndent.IncorrectExact` errors to fix 8.
  Replace exact lines with exact text instead.
- **A docblock edit can match twice.** Two identical `get_parameters_schema()`
  docblocks in one file: anchor on the body that differs (`throw …`), not on
  the docblock.

## 6. Platform notes (Windows / MSYS)

- `git show origin/branch:path` is path-mangled (the colon becomes `;`). Use
  `git grep -n '<pat>' origin/branch -- path`.
- `/tmp` is not writable by native Windows PHP — keep helper scripts
  repo-local and delete them before committing.
- ~1,200 path arguments exceed the shell limit. Run `--report=json` over the
  directory and filter with a small PHP script instead.
- `phpcs` OOMs at the default 128 MB on a large scope: always
  `-d memory_limit=2048M`.
- phpcs has **no `total` report class**. Use `--report=summary` and read its
  tail, `--report=source` for per-sniff counts, `--report=full -s` for
  `file:line:source`.

## 7. Slice cadence that worked

1. Rank with `--report=json` plus a filter script (root files vs
   subdirectories).
2. Take 10–20 files / ~80–200 violations. Subdirectories first — once done
   they never resurface.
3. Deterministic pass from the report.
4. Manual pass, file by file; `php -l` after each batch.
5. Five-sniff scan of the slice → must exit 0.
6. Full-scope measurement; assert the arithmetic from §1.
7. Budget edit in the same commit; the PR body states `prev → new`.
8. Merge the fix-PR before the sweep-PR when the sweep depends on it — see
   `mcp-ai-wpoos-git-ops` § "Sequencing a fix-PR against a sweep-PR".
