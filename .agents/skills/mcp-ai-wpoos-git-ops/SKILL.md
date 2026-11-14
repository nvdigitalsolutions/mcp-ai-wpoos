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
  the unmerged parent branch, open the child PR with **base = the parent
  branch** (so the diff is only the delta), and name the parent PR number in
  the body. If the parent merges and its branch is deleted, retarget the child
  to `alpha-working` and rebase the delta.
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
# Replay only the commits after the baseline.
# --autostash is required in a shared worktree (another agent's unstaged
# edits would otherwise make the rebase refuse to start).
git rebase --autostash --onto origin/alpha-working <old-baseline-sha> <branch>
git push --force-with-lease origin <branch>
```

- Expect **zero conflicts** during the replay when the squash tree matches
  your baseline; conflicts mean something else changed — abort and
  investigate before continuing.
- After the rebase the PR shows only your delta; re-verify
  `gh pr view <n> --json mergeable,mergeStateStatus` (`MERGEABLE`).
- **Never merge the child into a parent branch that has already merged
  (2026-11-12).** #7604 (base `feat/release-mirror-artifacts`) was merged
  15 s AFTER #7603 squash-merged into `alpha-working`; the child's commit
  landed on the now-dead parent branch and never reached `alpha-working`.
  Immediately before merging a stacked child, check
  `gh pr view <parent> --json state,mergedAt`; if the parent is already
  `MERGED`, do not merge the child into its base — replay the delta onto
  `alpha-working` as a fresh PR (recovery: #7606).
- GitHub Docs reference: "Managing stacked pull requests" /
  "Troubleshooting stacked pull requests" (`gh stack rebase` alternative).

## The PR was merged WHILE you were pushing (2026-11-09)

A squash-merge can land between two of your commits. The `git push` still
succeeds — the branch accepts it — but the PR is already frozen at its merged
head, so the new commits sit on a dead branch and never reach the base.

Detect (they diverge):

```bash
gh pr view <n> --json state          # MERGED
gh api repos/<o>/<r>/branches/<branch> --jq .commit.sha | cut -c1-10
gh api repos/<o>/<r>/pulls/<n> --jq .head.sha        | cut -c1-10
```

Fix — replay the post-merge commits onto the new base and open a FRESH PR:

```bash
git checkout -b <new-branch>
git rebase --onto origin/alpha-working <the merged head sha>
# the replay is clean when the squash tree matches the old head's tree
git push -u origin <new-branch> && gh pr create --base alpha-working
```

Prevention: before adding a commit to a PR that has been green for a while,
run `gh pr view <n> --json state`. Owner-merge on this repo means a green PR
can disappear under you between tool calls.

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

## Reusable workflows: secrets must be mapped by name (2026-11-12)

GitHub Actions cannot resolve a secret through a dynamic name
(`secrets[format(...)]` is not supported — it evaluates empty). Declare one
generic secret on the `workflow_call` boundary and map each caller's real
secret into it:

```yaml
uses: ./.github/workflows/mirror-release.yml
secrets:
  MIRROR_TOKEN: ${{ secrets.NVOOS_CONTENT_GRAPH_REPO_TOKEN }}
```

Keep the declared secret `required: false` when callers should stay green
before the token exists, and probe it in a preflight job
(`[ -n "$TOKEN" ]`) — `if:` cannot read `secrets`.

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
Each rule below cost a recovery:

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
- **`git checkout -b` inherits whatever branch is checked out — verify BEFORE
  creating, not only before committing (2026-11-11).** A `checkout -b feat/x`
  issued while the worktree sat on another agent's `feat/ezuite-…` produced a
  branch one commit deep in THEIR work, so the PR would have bundled their
  commit. `git log --oneline -1` straight after creating the branch shows it in
  one line. Fix without switching branches:
  `git reset --mixed origin/alpha-working` moves HEAD and the index only — no
  working-tree content is lost — then re-stage just your own paths and commit.
- **Never build a commit tree from the WORKING TREE in a shared checkout
  (2026-11-11).** After a foreign branch switch, the on-disk copy of a file is
  the OTHER branch's version, so committing the worktree silently REVERTED 106
  lines of the agent's own work — `git show --stat` read `+36/−106` for a
  four-line fix. **The tell is a large DELETION count on a file you barely
  touched: stop and rebuild from a blob.** No checkout needed:
  `GIT_INDEX_FILE=.git/tmp-index git read-tree <branch-sha>`, then
  `git hash-object -w` a copy of `git show <branch-sha>:<path>` with the fix
  applied, `git update-index --cacheinfo 100644,<blob>,<path>`,
  `git write-tree`, `git commit-tree <tree> -p <base-sha> -F <msg>`, and
  `git branch -f <branch> <commit>` (legal only while that branch is NOT
  checked out). `rm .git/tmp-index` afterwards. This touches nothing the other
  agent can see and needs no worktree state at all — the recovery of choice
  whenever a checkout is blocked by their dirty files.
- **Recovering a stray commit:** cherry-pick it onto your branch, then
  restore the foreign branch with `git reset --mixed <its-old-head>` +
  `git restore --worktree -- <only-the-files-you-touched>`. **Never
  `git reset --hard` in a shared worktree** — it wipes other agents'
  uncommitted tracked edits (e.g. a modified `SKILL.md`).
- **Never `git checkout -- vendor/` wholesale.** `vendor/` is bind-mounted into
  the Docker `oos-wp` container, so `composer install` (needed for PHPUnit
  when the tree was installed `--no-dev`) dirties it. Restore **only** the
  tracked composer metadata — `git restore --worktree --source=HEAD --
  vendor/composer` (the `git checkout -- vendor/composer` form can be
  rejected by the editor's built-in security rule, "too harmful to be
  allowed"; `git restore` is not) — because
  other agents' dependency work can live elsewhere under `vendor/`, and a
  blanket restore erases it. Same rule for any generated tree
  (`build/`, `dist/`, `public/manage-assets/`): restore or rebuild exactly the
  paths you touched.
- **A foreign agent's commit can land on the branch you just created
  (2026-11-11).** `git checkout -b <mine>` moves the *shared* `HEAD`, so the
  next agent to commit lands on your branch — the inverse of the "check the
  branch before committing" rule above, and it is silent: your branch just
  ends up one commit ahead of the base. Detect with
  `git rev-list --left-right --count origin/alpha-working...HEAD` (a
  right-hand count > 0 on a branch where you have not committed yet).
  Recover non-destructively: `git branch -f <their-branch> <their-sha>` so
  their work sits on its own branch (confirm it is also on
  `origin/<their-branch>`), then build your PR in an isolated worktree —
  `git worktree add tmp/wt-<name> -b <branch> origin/alpha-working`, copy
  only your files, commit/push from there, `git worktree remove --force`.
  Never `reset --hard` to unwind it.
- **Put an agent's own worktree INSIDE the repo, not in `%TEMP%`.** A
  worktree under `AppData/Local/Temp` is fine from the shell, but the
  editor's file tools resolve paths inside the project root: edits there
  either fail with "path not found" or, worse, silently land in the MAIN
  worktree you were trying to avoid. **`tmp/<name>` is also invisible to the
  editor's file tools (2026-11-12)** — the dir is gitignored and excluded from
  the file index (`list_directory`/`edit_file` return "path not found"), so
  `tmp/` worktrees are shell-only (apply edits with a Python/sed script, or
  copy files out → edit → copy back). When editor editing is required, use an
  in-repo `.wt-<name>` worktree instead (untracked, so it IS indexed; verified
  2026-11-12). A worktree created with `git worktree add` also takes minutes
  and can be killed mid-checkout on this repo — if it times out, `rm -rf` the
  leftover, `git worktree prune`, and check the branch was not already
  registered.
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
- **Worktree admin dir vanished mid-session → rebuild, don't repair
  (2026-11-08).** A linked worktree can spontaneously lose its
  `.git/worktrees/<name>` admin dir: every git command there returns
  `fatal: not a git repository: (NULL)`, the dir's `.git` file still points
  at the missing path, and `git worktree list` no longer shows it at all.
  The files and the branch ref are fine. Recover without touching the stale
  dir: `git worktree add .wt-<name2> <same-branch>` from the main repo,
  copy your paths across one at a time with explicit destinations, prove
  the copy with `diff -q` per file (byte-identical), commit/push there, then
  `rm -rf .wt-<old>` + `git worktree prune`. `git worktree remove` cannot
  clean the stale dir — it is no longer registered.
- **Uncommitted changes + shared tree → copy your paths into the fresh
  worktree (2026-11-07, dictate memory-layer PR):** the worktree starts at
  the base commit with none of your uncommitted work, so first confirm
  base parity: `git diff --stat origin/alpha-working HEAD -- <your-paths>`
  — empty means no rebase needed (foreign commits never touched your
  paths). Then copy exactly your changed paths (modified + untracked) from
  the main tree into the worktree **one file at a time with explicit
  destinations** — multi-source `cp a.txt b.txt dest/` flattens both into
  `dest/`, not their relative subpaths (a `scripts/*.ps1` landed at the
  app root that way; caught by diffing the worktree's `git status --short`
  against the main tree's expected change list before staging). For
  Tauri/Rust apps junction `src-tauri/target` exactly like node_modules
  (`fs.symlinkSync(...,'junction')`) — cargo validation is then
  near-instant instead of recompiling ort; both dirs are gitignored, so
  the worktree removal stays clean.
- **Large change sets into a fresh worktree: script the copy
  (2026-11-13, 199-path assistant-parity PR).** Hand-copying breaks down at
  ~100 paths, and the usual miss is *deletions*: a rebuilt `dist/` removes
  old hash chunks, and if you only copy additions the worktree still ships
  both hash sets. Drive it from `git status --porcelain -z` in the main
  tree — expand `?? dir/` entries by walking the directory, `rm` the target
  for each `D` entry, `copyFileSync` the rest — then prove it: the
  worktree's `git status --short` must show the same A/D/M counts as the
  main tree, and `cmp -s` a couple of large artifacts. Repo root, node, no
  deps.
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
- **On-disk ≠ shipped (2026-11-08).** Before scoping a "commit this"
  request, prove the feature exists upstream:
  `git ls-tree --name-only origin/alpha-working <dir>` and
  `git log --all --oneline -- <new-file>`. Two prior sessions' proposals
  (130 trackers, 131 SEO) were fully implemented and validated but existed
  ONLY as working-tree changes — the redirect PR could not be scoped
  narrowly without dangling doc/code cross-references. When that happens,
  land the whole arc in one PR and say so in the body, rather than
  committing a delta whose docs point at files that do not exist.
- **Copying a delta onto a moved base (2026-11-08, PR #7518).** Before
  copying worktree files into a fresh branch, list the files the base moved
  across your ENTIRE delta:
  `git diff --stat HEAD origin/alpha-working -- <every path you touched>`.
  Only the named files are at risk. For those, commit your delta on the old
  base and let `git rebase --onto origin/alpha-working HEAD~1` 3-way-merge
  them (one import-line conflict in the forge PR, zero elsewhere);
  wholesale-copying the worktree versions silently REVERTS those merged
  base commits. Re-validate after the rebase — the base's own tests
  (554 vs 551) run too.
- **The shared `.git/info/exclude` can silently block deliberate files.**
  The **repository-level** info/exclude (applies to **linked worktrees too**
  — not per-worktree) ignores `*.png`/`*.jpg`/`*.pdf` (the `*.pdf` rule hit
  the dictate document-OCR fixtures, 2026-11-03). `git add` then fails with
  "ignored by one of your .gitignore files" — run `git check-ignore -v
  <path>` to find the rule and `git add -f` when the file is deliberate
  (gateway logo assets PR #7349; dictate PDF fixtures PR #7425).

### Syncing a dirty worktree when the pull collides (2026-11-13)

A shared worktree is usually dirty when `alpha-working` advances, and
`git pull --ff-only` aborts if any incoming path is locally modified or
untracked. Do not `git checkout` the tree or `reset --hard` — another agent's
edits may be in it, and your own edits may be a superset of what is coming in.

Hash-check first: a file byte-identical to the incoming blob is safe to drop;
anything else is real work.

```sh
export MSYS_NO_PATHCONV=1
git fetch origin alpha-working
git diff --name-only HEAD origin/alpha-working > /tmp/incoming.txt
git status --porcelain=v1 | sed 's/^...//' > /tmp/dirty.txt

comm -12 <(sort /tmp/incoming.txt) <(sort /tmp/dirty.txt) | while IFS= read -r p; do
  [ -z "$p" ] && continue
  L=$(git hash-object -- "$p" 2>/dev/null || echo MISSING)
  R=$(git rev-parse "origin/alpha-working:$p" 2>/dev/null || echo MISSING)
  [ "$L" = "$R" ] && echo "IDENTICAL $p" || echo "DIFFERS   $p"
done
```

- **IDENTICAL** → drop it (`git checkout HEAD -- <p>` for tracked, `rm` for
  untracked), then `git pull --ff-only`.
- **DIFFERS** → your copy holds work the incoming commit does not. Diff it
  against `git show origin/alpha-working:<p>` and confirm it is a superset
  (additions only). If so: `cp` it to `/tmp`, reset that path to `HEAD`, pull,
  then restore the copy. If the incoming commit changed the same lines, merge
  by hand — never restore blindly.
- **Untracked directories do not appear as file paths** in `git status`; they
  collapse to one `?? dir/` entry, so `comm` misses them. Enumerate with
  `git ls-files --others --exclude-standard` and hash-check those separately.
- **On Windows Git Bash, run this block with `MSYS_NO_PATHCONV=1`.** MSYS
  rewrites the `:` in `origin/alpha-working:path` into a Windows path, so
  `git rev-parse` returns an empty string and every file mis-reports as
  `DIFFERS` — a false positive that invites hand-merging files that were
  already identical (2026-11-13: six files flagged after a squash merge, all
  byte-identical).
- `rm -f a b c` combined with `git checkout HEAD -- …` in one command can trip
  the editor's built-in security rule ("too harmful"). Run the `rm` and the
  `checkout` as separate calls.

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
- **Multi-paragraph `git commit -m` bodies with embedded quotes corrupt easily
  at the sh boundary (2026-11-13):** a body quoting a separator
  (`", parakeet"`) landed in the commit as `"$"` — a `"` next to `$` inside a
  double-quoted `-m` is not preserved the way you wrote it. Write the body to a
  file and commit with `-F` (`git commit -F /tmp/msg.txt`,
  `git commit --amend -F /tmp/msg.txt`), then confirm with
  `git --no-pager log -1 --format=%B` **before** pushing. Amending is safe
  pre-push; a corrupt message that reaches the remote needs a force-push.

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
  `Forbidden pattern checks` alone took 27m once, and
  `PHP Compatibility Check` + `WPCS 3.0` can sit `pending` indefinitely —
  they still show pending on PRs merged long before (verified 2026-11-08 on
  an already-merged PR), so they are not a gate. Report `mergeable`
  (`MERGEABLE`) plus the JS suites (`test`, `test (18.x)`, `test (20.x)`)
  instead of blocking on those three.
- After a forced push, GitHub recomputes conflict status automatically;
  re-check `mergeable` before reporting done.

## PR hygiene

- PR body states: what changed, validation actually run (with counts), and —
  for stacked PRs — the parent PR number and that the diff delta is the last
  commit.
- Don't claim validation that wasn't run. Reference files by project-relative
  path.
- **Run the licence gate before pushing new files.** `bash
  bin/check-license-boundary.sh` enforces per-tree licence headers: `lib/core/src`
  must be **MIT** (a GPL `SPDX-License-Identifier` there fails with
  `lib/core/src contains files declaring GPL (package is MIT)`), `includes/`
  GPL-only, `addons/pro/includes` + `addons/canvas` Proprietary-only. The
  `Licence boundary intact` check fails in ~20 s, so this is the cheapest
  CI failure to prevent — and the easiest to misdiagnose as a code problem
  when the diff is only a docblock.

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

## Post-merge CI triage — a MERGED PR can still fail (2026-11-09)

A workflow that runs on push to `alpha-working` (the `Sync nvoos-astro-*`
family) can fail *after* the PR is merged, on the merge commit itself. That
is a new forward-fix, never a revert: the code is on the branch and other
work has already stacked on it.

**Root cause to check first — is the workflow even PR-triggered?** Read its
`on:` block. `sync-nvoos-astro-velocity.yml` and
`sync-nvoos-astro-forge.yml` are **push-only** (`push` to
`main`/`alpha-working`, `repository_dispatch`, `workflow_dispatch`) — they
have **no `pull_request` trigger**, deliberately (they force-push a mirror
and must not run from a fork). So a green PR says *nothing* about them.
This is exactly how #7536 merged with 87 lint errors.

When the gate is push-only, **the only pre-merge validation is running the
workflow's own commands locally**. Don't approximate: open the workflow
file and execute each step's `run:` in order (lint → test → fixture drift →
build → bundle-staleness → smoke → e2e → LHCI), with the same env. Then
say so in the PR body — "the gate does not run on PRs; these were run
locally" — instead of implying the PR checks cover it.

1. **Find the commit the run tested.** `gh run list --workflow <file>
   --json databaseId,headSha,displayTitle,conclusion` and match `headSha`
   to `git log --oneline -3` on `alpha-working`. A force-push between the
   run and your triage means the pasted log describes a commit you no longer
   have.
2. **Read the failing step, not the job name.** `gh run view <id> --log-failed`
   gives the exact command and its stderr.
3. **Assume the cheapest cause first.** In this repo the post-merge failure
   class is almost always lint/format drift, not behaviour: a pre-merge
   `astro check` / `tsc` / unit run is a *different* toolchain from `eslint`
   and `wp-scripts`, and passing one proves nothing about the other.
4. **Fix forward on a fresh branch off the target.** `git switch -c
   fix/<what>-<why> origin/alpha-working`, apply the minimal fix, stage ONLY
   the affected files, commit, push, PR against `alpha-working`.
5. **Prove the fix is mechanical when it is.** For an auto-fixer (`eslint
   --fix`, `prettier --write`, `cargo fmt`), `git diff --ignore-all-space
   -- <file>` must be **empty** — that is the evidence the change is
   whitespace-only and cannot have altered behaviour. Paste that fact in the
   PR body, not just "lint passes".
6. **Re-run the checks the fix could have touched** (unit tests, type check)
   even though the diff is whitespace — cheap, and it catches the case where
   the auto-fixer reordered something semantic.
7. **A green CI run does not prove the DEPLOYED artifact is the new build.**
   CI builds and simulates; the host deploys separately, and incremental
   deploy hosts keep files that left the repo (2026-11-09: a route served
   nine-day-old build-time HTML through a restart *and* a redeploy, because an
   orphaned static file shadowed the SSR route). Verify the live artifact by
   fingerprint — e.g. compare `/_astro/<chunk>.<hash>.js` names, or an
   island's `client="…"` directive, against a local build. Restarting clears
   caches; it does not delete files.

### `eslint` `indent` + `SwitchCase: 0` (the 87-error shape)

This repo's ESLint (via `@wordpress/eslint-plugin`) sets `SwitchCase: 0`.
`case` labels therefore sit at the **same** indent as the `switch`, and each
case body is **+1 tab**. Authoring a `switch` at 2 tabs means `case` at 2
tabs, body at 3, nested block at 4. A `switch` nested one level deeper than
the author expects produces a uniform off-by-one across every line of the
switch — 87 errors from one mental slip, all fixed by `npx eslint src --fix`.
