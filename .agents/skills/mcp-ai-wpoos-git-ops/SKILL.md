---
name: mcp-ai-wpoos-git-ops
description: Git/GitHub workflow discipline for the mcp-ai-wpoos repo — PRs against `alpha-working`, staged commits (no `git add -A`), the stacked-PR rebase recipe for when a parent PR gets squash-merged, the alpha-working → main squash-sync recipe (resolve-all-to-alpha, tree-identity verification), shared-worktree discipline for the multi-agent repo (never `reset --hard` over other agents' unstaged edits, `--autostash` rebases), and gh CLI gotchas (`gh workflow run` 404 on non-default branches). Use when creating/rebasing/fixing PRs, resolving PR conflicts, syncing alpha-working to main, or dispatching workflows.
---

# mcp-ai-wpoos — Git & PR ops

## Branch & PR discipline

- **PR base is `alpha-working`.** The owner merges manually — agents never
  merge their own PRs. Clusters land as one PR per cluster (see the
  `mcp-ai-wpoos-test-suite` skill for the cluster workflow).
- Branch naming: `feat/<track>-<descriptor>`; stack dependent work on top of
  the unmerged parent branch and say so in the PR body.
- **Stage explicitly.** Never `git add -A` — the worktree often holds build
  artifacts and local state (e.g. `.lighthouseci/`, `test-results/`). Use
  explicit paths, or pathspec exclusions:
  `git add examples/nvoos-astro-velocity ":(exclude)…/test-results"`.
- Commits: imperative subject ≤ 50 chars, body only when useful (wrap at 72).
  Run commits with `GIT_EDITOR=true git commit …`; read-only git with
  `--no-pager`; prefer `git --no-optional-locks status`.

## Stacked PRs after a squash merge (the recipe that works)

When the parent PR was **squash-merged**, a stacked branch shows add/add
conflicts on every file both sides "added" — even when the squashed tree is
byte-identical to your baseline. Fix by rebasing **only your delta** onto the
updated base:

```bash
git fetch origin alpha-working
# Optional diagnosis — exits 1 when conflicts exist:
git merge-tree --write-tree origin/alpha-working HEAD
# Verify the squash tree equals your old baseline (empty diff = clean replay ahead):
git diff <squash-merge-sha> <old-baseline-sha>
# Replay only the commits after the baseline:
git rebase --onto origin/alpha-working <old-baseline-sha> <branch>
git push --force-with-lease origin <branch>
```

- Expect **zero conflicts** during the replay when the squash tree matches
  your baseline; conflicts mean something else changed — abort and
  investigate before continuing.
- After the rebase the PR shows only your delta; re-verify
  `gh pr view <n> --json mergeable,mergeStateStatus` (`MERGEABLE`).
- GitHub Docs reference: "Managing stacked pull requests" /
  "Troubleshooting stacked pull requests" (`gh stack rebase` alternative).

## Freeze a PR branch once it is mergeable (the lost-commit trap, 2026-11-06)

Follow-up commits pushed to an OPEN PR branch silently vanish when the
owner squash-merges the PR **before those pushes** — the squash only
takes the branch state at merge time (observed: the forge-sites workflow
fix + the skills-doc commit were both lost this way and had to be
recovered as new PRs).

- Once a PR is mergeable, STOP pushing to its branch. Any new work goes
  on a fresh branch (stack it on the PR branch, then rebase after the
  merge).
- After a merge, **forensic the squash** before assuming it took
everything:
  `git diff <squash-sha> <branch-tip-sha>` — anything listed as
  additions-from-the-tip that the squash lacks was MISSED; recover it
  as a new PR. (The reverse direction shows rebase-at-merge artifacts —
  newer base content your tip predates — those are fine.)

## GitHub Actions expressions: `||` is `if:`-only (2026-11-06)

The `||` / `&&` operators exist ONLY inside `if:` conditionals. In
`env:` blocks, `${{ vars.X || 'default' }}` evaluates EMPTY — a workflow
that passes the result into a CLI flag silently sends `""` (the
forge-sites `--mirror-repo` failure). Default env values belong in the
shell: `VAR="${VAR:-default}"` in the `run:` block, with the raw variable
left in `env:` as the override point.

## alpha-working → main squash-sync (the release-sync recipe)

`main` mirrors `alpha-working` through periodic "Merge alpha-working into
main (squashed)" PRs (#7310, #7317, #7369 — head `merge/alpha-to-main-sync-*`,
base `main`). The acceptance test is **tree identity**: after the merge,
`git diff --stat origin/main origin/alpha-working` must be empty. Build
artifacts always resolve to **alpha-working's copies** (the #7117 "keep
alpha-working ZIP copies" convention — main's own workflows regenerate them
post-merge anyway).

```bash
git fetch origin alpha-working main
git checkout -b merge/alpha-to-main-sync-<ver> origin/main
git merge --squash origin/alpha-working
```

1. **Expect a large conflict set.** Main's squashed history makes many files
   "both added"/"both modified" (skills, `.context/*`, docs) and main's build
   workflows add their own artifacts (SPA addon ZIP rebuilds, console `dist/`
   chunk-hash rotations, stale previous-version ZIPs). Main-line *twin* PRs
   (dictate fixes #7352/#7355/#7357) are tree-identical to their alpha twins
   and merge cleanly — never hand-merge them.
2. **Resolve everything to alpha's tree:** `git checkout origin/alpha-working -- .`
   stages alpha's version of every path and clears all conflicts.
3. **Sweep the leftovers — step 2 is not enough.** Two silent keepers survive:
   - modify/delete auto-resolutions: main modified a file alpha deleted, and
     `git merge --squash` keeps main's copy **without conflict**;
   - index-only files: paths in main's tree that alpha never had (old-hash
     `dist/` chunks, stale build ZIPs) stay staged by the checkout.
   Enumerate with `git diff --cached --name-only origin/alpha-working`; for
   each path, if it exists in alpha (`git cat-file -e origin/alpha-working:<path>`)
   re-checkout it, otherwise `git rm --ignore-unmatch` it. The 1.2.15 sync
   left 97 such files (78 old-hash console chunks + 19 stale 1.2.13 ZIPs).
4. **Verify before committing:** `git diff --stat origin/alpha-working` empty
   and `git diff --name-only --diff-filter=U` empty (tree identity).
5. Commit: `Merge alpha-working into main (squashed)` — **no skip-ci
   markers** (a marker in the squashed message suppresses every main
   workflow, and this push is what triggers the rebuilds).
6. Push, open the PR against **`main`** (the `chore/merge-alpha-working-into-main`
   branch is v1.1.49-era and stale — use a fresh name), squash-merge to one
   commit, then re-verify tree identity against the new `origin/main`.

Ownership: sync PRs are owner-merged (agents create + prepare them; ask
before merging one yourself). A docs catch-up pass usually triggers the
sync.

## Shared worktree discipline (multi-agent repo, learned 2026-10-30)

Parallel agents share the same checkout and switch branches between tasks.
Four rules, each from a session that cost a recovery:

- **Check the branch before committing.** `git branch --show-current` and
  `git log --oneline -1` before `git add`/`commit` — a commit lands on
  whatever branch is checked out, and the worktree may have been switched
  under you (a build fix once landed on another agent's
  `feat/mcp-sdk-skill-updates`). **The merged-PR variant (2026-11-03, the
  #7400 CI hotfix):** once your PR squash-merges, the shared worktree is
  often left sitting on `alpha-working` — a follow-up fix committed
  without re-checking lands directly on the mainline. When fixing CI on a
  just-merged PR, either re-verify the branch first or deliberately
  commit to a `fix/` branch + PR; a direct mainline hotfix (e.g. `cb9158501f`,
  the #7400 fixture-sync crash fix) should still be recorded by a small
  follow-up PR for the audit trail.
- **Recovering a stray commit:** cherry-pick it onto your branch, then
  restore the foreign branch with `git reset --mixed <its-old-head>` +
  `git restore --worktree -- <only-the-files-you-touched>`. **Never
  `git reset --hard` in a shared worktree** — it wipes other agents'
  uncommitted tracked edits (e.g. a modified `SKILL.md`).
- **Rebasing with foreign unstaged edits present:** plain `git rebase`
  refuses ("You have unstaged changes"); `git rebase --autostash` stashes
  only tracked changes and pops them back. **Safer variant (2026-11-03,
  astro 105/106 session):** when only a couple of foreign tracked files
  block the rebase, stash exactly those paths —
  `git stash push -m "foreign-agent-work-preserved" -- <path1> <path2>` —
  rebase, then `git stash pop` immediately. Autostash sweeps the *whole*
  shared worktree into a stash (other agents' edits included); the targeted
  stash keeps every unrelated file live on disk.
- **"Skipped previously applied commit" during rebase = the parent PR was
  squash-merged already.** `git merge-base --is-ancestor <old-commit>
  origin/alpha-working` is the WRONG test for this — a squash merge creates
  a new commit hash, so the old head is never an ancestor. The rebase's
  skip message (and `gh pr close` answering "can't be closed because it was
  already merged") are the reliable signals. When it happens: pop any
  targeted stash, abandon the now-identical branch, and branch the new work
  off `origin/alpha-working` directly (2026-11-03: PR #7395 was already
  merged; the rebase skip revealed it).
- **Merged PRs freeze their head.** A squash-merged (closed) PR stops
  tracking branch pushes — `gh pr view` keeps showing the stale `head_sha`.
  After the owner merges, run the stacked-PR recipe above and open a fresh
  PR for the delta (PRs #7302 → #7303).
- **Multiple linked worktrees exist — aim every git command.** Other
  agents keep `git worktree add` checkouts (see `git worktree list`). A
  `git cherry-pick` run from the wrong directory acts on the wrong
  worktree (2026-10-31: one silently no-op'd in the main worktree while
  the target was a linked one — caught by the "cherry-pick is now empty"
  message). Check `git rev-parse --show-toplevel` first, or use
  `git -C <abs-worktree-path>` for any command aimed at another worktree.
- **Dirty shared worktree + moved base → `git worktree add`, never a
  checkout.** When `origin/alpha-working` advanced and other agents'
  uncommitted files block `git checkout -b` ("would be overwritten by
  checkout"), don't stash their work: `git worktree add ../<name> -b
  <branch> origin/alpha-working`, then operate there via `git -C` (and
  `npm pack <abs-dir>` rather than `--prefix`). `git worktree remove`
  when done — the shared checkout was never touched.
- **In-repo worktree variant when editor tools need the files (2026-11-04):**
  if your editing tools can only reach paths under the project root, add
  the linked worktree INSIDE the repo (`git worktree add
  F:/GITHUB/mcp-ai-wpoos/.wt-<name> -b <branch> origin/alpha-working` —
  git is fine with it; the dir shows as untracked in the main tree and is
  removed wholesale afterwards). For validation runs, junction the
  node_modules instead of reinstalling: node
  `fs.symlinkSync('<main>/node_modules', '<wt>/node_modules', 'junction')`
  (no admin rights needed); `cd` the terminal into the worktree dir for
  npm/astro/vitest/playwright. `git worktree remove --force .wt-<name>`
  when done — verified 2026-11-04 for the 110 version-bump PR (lint,
  astro check, vitest, hermetic build and home e2e all ran inside the
  worktree).
- **De-stack a PR without `reset --hard`:** move the branch back with
  `git reset --mixed <parent>` (worktree untouched), restore only your own
  paths with `git restore --worktree -- <your-paths>` (a file you created
  becomes untracked again — plain `rm`), then
  `git push --force-with-lease`. Other agents' unstaged edits survive
  byte-for-byte. Verified 2026-10-31 de-stacking PR #7325.
- **Sibling PRs editing the same file (the #7348 ↔ #7349 case, 2026-11-01):**
  two open PRs touch the same docs file in overlapping hunks; the later one
  merges first and the earlier one reports merge conflicts at merge time.
  Fix: `git fetch origin` → `git checkout <branch>` →
  `GIT_EDITOR=true git rebase --autostash origin/alpha-working`, resolve by
  **keeping both PRs' intents** (the merged PR's new text usually wins for
  shared lines; your PR's unique additions stay), then
  `git push --force-with-lease`. A successful rebase replay is proof the
  conflict is gone — by construction the branch is ahead of the base.
- **The shared `.git/info/exclude` can silently block deliberate files.**
  The **repository-level** info/exclude (applies to **linked worktrees too**
  — not per-worktree) ignores `*.png`/`*.jpg`/`*.pdf` (the `*.pdf` rule hit
  the dictate document-OCR fixtures, 2026-11-03). `git add` then fails with
  "ignored by one of your .gitignore files" — run `git check-ignore -v
  <path>` to find the rule and `git add -f` when the file is deliberate
  (gateway logo assets PR #7349; dictate PDF fixtures PR #7425).

## Windows reserved-name artifacts (learned 2026-10-29)

A stray untracked file literally named `nul` (Windows reserved device name)
can appear in the repo root. Two failure modes follow:

- **`git stash push -u` aborts partway** with `failed to remove nul:
  Permission denied` — the stash IS saved, but tracked files stay modified
  and the untracked files get hidden in the stash's third parent.
  Recovery: list with `git ls-tree -r --name-only "stash@{N}^3"` and restore
  with `git checkout "stash@{N}^3" -- <paths>` (exclude `test-results/`).
  When only a few tracked files block a branch switch onto a moved base,
  the lighter tool is a pathspec stash: `git stash push -m <msg> -- <paths>`,
  switch, then `git checkout stash@{0} -- <paths>`.
- **Deleting the file itself:** normal `rm`/`del` fail (device semantics).
  Write a tiny `.ps1` using `Remove-Item -LiteralPath "\\?\<abs-path>"`
  (forward slashes OK after the prefix) and run it with
  `powershell -NoProfile -ExecutionPolicy Bypass -File` — inline `-Command`
  calls get the backslashes mangled by the sh layer. Never stage it —
  explicit staging (above) keeps it out of commits.

## Windows sh-shell gotchas (learned 2026-11-01, gateway session)

- The terminal runs **sh, not cmd**: `copy` does not exist — use `cp`;
  `del`/`ren` are `rm`/`mv`.
- **`powershell -Command` inline strings get `$var` interpolated away by the
  sh layer** (every `$i` vanished → parser errors). Write a `.ps1` file and
  run `powershell -NoProfile -ExecutionPolicy Bypass -File`, or do it in
  node instead (e.g. JPEG/PNG dimension sniffing from the file header — no
  deps needed).
- **Inline `python -c` with backslash strings gets mangled the same way
  (2026-11-04):** `'\\'` in an inline `python -c` arrives as `'\'` and
  the script dies mid-parse (SyntaxError). Write the script to a `.py`
  file and run `python <file>` — same pattern as the `.ps1` rule (the
  110 bridge-zip packaging script was built this way).
- **Never pipe a validation gate through `grep`/`head` — the exit code is
  the gate (2026-11-04 CI-blocker):** `astro check 2>&1 | grep ... | head -3`
  masked a real failure (3 `className` type errors in an `.astro` template
  shipped to alpha-working; the sync workflow's `astro check` step failed
  on them) because the pipe chain exited 0 and the error lines were beyond
  `head`. Also: a BARE `npx astro build` does NOT type-check — only the
  full `npm run build` chain (or `astro check` itself) does. Discipline:
  `cmd > log 2>&1; echo "exit: $?"; tail log` and assert 0 explicitly
  before claiming a gate green.
- **`2>nul` under sh creates a real `nul` FILE (2026-11-04):** a Windows
  habit like `dir ... 2>nul` in the sh shell redirects stderr to an
  untracked file literally named `nul` in the repo root. It never rides
  commits (pathspec discipline), but remove it: PowerShell
  `Test-Path -LiteralPath '\\?\<abs>\nul'` returns FALSE (the
  MSYS-created file is not the device path) — node removes it:
  `node -e "fs.rmSync('nul',{force:true})"`.
- `findstr /C:"..."` quoting breaks under sh — pipe to node for filtering
  or use the terminal tool's `tail_lines`.
- `node --test <directory>` does **not** recurse on this setup (runs one
  file) — pass explicit files or the package's `npm test` script.
- **Windows-reserved names:** see the `nul`-artifact section above (same
  `powershell -File` pattern applies).
- **Files whose names begin with `-`** (a stray `--target` from a mis-parsed
  `npm run build -- --target`): plain `rm --target` fails with
  `rm: unknown option` — use the double-dash form `rm -- --target` (the
  error message itself suggests it).

## Pathspec commits, shared-index races, workflow YAML (learned 2026-11-02, Cognition rebrand session)

- **`git commit -- <paths>` traps:** options (`-m`) must come BEFORE the
  `--` separator — `git commit -m "msg" -- <paths>`; after `--` they are
  parsed as pathspecs. New **untracked** files must be `git add`-ed first:
  the pathspec commit form only covers tracked/staged paths.
- **The shared index can change mid-session.** Another agent can stage files
  between two of your `git status` checks (the proposals README went from
  unstaged to staged mid-session). Commit with explicit pathspecs even when
  you staged explicitly — foreign staged files can never ride along.
- **A foreign staged file blocks `git cherry-pick`** — the merge machinery
  requires a clean index (unstaged edits on unrelated paths are fine; ANY
  staged file refuses with "your local changes would be overwritten by
  cherry-pick"). Workaround that never touches the other agent's staging:
  `git diff <parent> <commit> | git apply --check -` (prove it first), then
  `git diff <parent> <commit> | git apply -` + an explicit-pathspec commit.
- **The owner can merge before your last push lands** — the squash then
  captures the pre-push head and your fix misses the merge (the #7389
  follow-up was exactly this). Before assuming "branch head = merged",
  verify the merged blob (`MSYS_NO_PATHCONV=1 git show
  origin/<base>:<path>`); carry any delta in a fresh PR from the updated
  base.
- **MSYS mangles `rev:path` colons even inside double quotes** —
  `git show "branch:path"` becomes `branch;path` and fails as ambiguous.
  Use `MSYS_NO_PATHCONV=1 git show "branch:path"`.
- **Fuzzy file edits can splice YAML silently.** A fuzzy edit on a workflow
  YAML duplicated a step mapping (left a `- name:` with no `run`/`uses`);
  only the PR's workflow-parse check caught it. After editing any
  `.github/workflows/*.yml`, re-read the **branch blob**
  (`MSYS_NO_PATHCONV=1 git show <branch>:<file>`) and parse-validate with
  `node -e` + `js-yaml` (available in the repo root's node_modules) — assert
  every step has `run` or `uses`. Cheap insurance before pushing.
- **Dispatching a workflow that exists only on a non-default branch:** the
  filename form of the workflow id accepts any ref —
  `gh api -X POST "repos/<o>/<r>/actions/workflows/<file>.yml/dispatches"
  -f ref=<branch>` (reinforces the `gh workflow run` 404 gotcha below).
  **BUT the workflow must be REGISTERED** (2026-11-02, npm-deprecate.yml):
  a dispatch-ONLY workflow on a non-default ref 404s on dispatch even via
  the API form — GitHub never registers it because no push event ever runs
  it. Fix: give it a self-referential push trigger
  (`push: branches: [main, alpha-working], paths:
  ['.github/workflows/<file>.yml']`) and guard the real step with
  `if: github.event_name == 'workflow_dispatch'` — then dispatch on
  `ref=alpha-working` succeeds.
- **`gh api -F` treats values starting with `@` as file paths** — a scope
  name like `inputs[package]=@nvdigitalsolutions/...` fails with
  `error parsing "inputs[package]" value: open nvdigitalsolutions/...`.
  Use `--input -` with a JSON body on stdin instead:
  `gh api --method POST "<endpoint>" --input - <<'JSON' … JSON`.

## gh CLI gotchas

- **`git archive` strips `package.json` and `bin/` repo-wide** — the root
  `.gitattributes` export-ignores them (meant for the plugin's release
  zips). Never `git archive <rev> packages/<pkg>` to extract package
  content (silently incomplete tar) — copy from a worktree checkout
  instead.
- `gh workflow run <name> --ref <branch>` resolves and dispatches against
  `<branch>` even when the workflow file is NOT on the default branch
  (verified 2026-11-02 — dispatched a branch-only sync workflow this way).
  The 404-on-default-branch behaviour still applies to the no-`--ref` form;
  the API filename form (`POST /actions/workflows/<file>/dispatches` with
  `ref=<branch>`) remains the fallback.
- `gh pr checks <n>` shows queued runs; the repo-wide **PHP Linting workflow
  takes ~20–30 minutes** — don't retry or "fix" it while it's simply running.
- After a forced push, GitHub recomputes conflict status automatically;
  re-check `mergeable` before reporting done.

## PR hygiene

- PR body states: what changed, validation actually run (with counts), and —
  for stacked PRs — the parent PR number and that the diff delta is the last
  commit.
- Don't claim validation that wasn't run. Reference files by project-relative
  path.

### Format-check drift - verify the toolchain and the commit (2026-11-04)

- A "fmt green" claim goes stale when files are edited after the last
  `cargo fmt` run - the dictate CI fmt failure (#7425 follow-up) was
  exactly this, not a rustfmt version mismatch. Before opening a PR,
  re-run `cargo fmt --check` with the same toolchain the CI action uses
  (`dtolnay/rust-toolchain@stable` == rustup stable). A blocked rustup
  self-update silently pins you to an older toolchain - delete the stale
  `~/.cargo/bin/cargo-miri.exe` hardlink and re-run `rustup update stable`.
- When triaging a failed run's diff, confirm which commit it actually
  tested: `gh run list --workflow <file> --json headSha,displayTitle,
  conclusion` - a force-push between the run and your triage makes a
  pasted diff describe a commit you no longer have.
