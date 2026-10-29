---
name: mcp-ai-wpoos-git-ops
description: Git/GitHub workflow discipline for the mcp-ai-wpoos repo — PRs against `alpha-working` with owner-merge, commit style, staged commits (no `git add -A`), the stacked-PR rebase recipe for when a parent PR gets squash-merged, conflict verification via `git merge-tree`, and gh CLI gotchas (`gh workflow run` 404 on non-default branches, long PHP lint checks). Use when creating/rebasing/fixing PRs, resolving PR conflicts, or dispatching workflows.
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

## gh CLI gotchas

- `gh workflow run <name>` **404s unless the workflow file exists on the
  default branch (`main`)** — for `alpha-working`-only workflows dispatch via
  the API (`POST /actions/workflows/<id>/dispatches` with `ref=alpha-working`)
  or push a trigger.
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
