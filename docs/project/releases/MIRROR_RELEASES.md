# Standalone Mirror Releases

The monorepo publishes several standalone plugins that also live in their own
repositories (synced one-way from a subtree). Before this pattern existed, the
release ZIPs were attached only to `nvdigitalsolutions/mcp-ai-wpoos` releases,
so anyone installing from the standalone repo had to jump back to the
monorepo to download a build.

The same assets are now mirrored into the standalone repo's own GitHub
Release. Both copies are byte-identical; the monorepo remains the build
authority.

## How it works

[`.github/workflows/mirror-release.yml`](../../../.github/workflows/mirror-release.yml)
is a `workflow_call` workflow that:

1. Downloads the assets from the monorepo release (`*.zip`, `*.sha256` by
   default) using the workflow's read-only `GITHUB_TOKEN`.
2. Creates (or updates) the release in the mirror repo under the same tag,
   targeting the mirror's `main` — the branch every `sync-*.yml` workflow
   force-pushes.
3. Uploads the assets with `--clobber`, making re-runs idempotent.
4. When `version_file` is set, waits up to 60 s for the mirror's plugin
   header to match the release version (the sync normally lands first), then
   warns instead of failing if `main` is still behind.

The release body gets a provenance footer pointing at the monorepo tag and
the source commit.

## Wired workflows

| Build workflow | Mirror repo | Secret |
| --- | --- | --- |
| `build-nvoos-content-graph.yml` | `nvdigitalsolutions/nvoos-content-graph` | `NVOOS_CONTENT_GRAPH_REPO_TOKEN` |
| `build-nvoos-content-graph-ai.yml` | `nvdigitalsolutions/nvoos-content-graph-ai` | `NVOOS_CONTENT_GRAPH_AI_REPO_TOKEN` |
| `build-nvoos-content-graph-ai-platform.yml` | `nvdigitalsolutions/nvoos-content-graph-ai-platform` | `NVOOS_CONTENT_GRAPH_AI_PLATFORM_REPO_TOKEN` |
| `build-spa-addons.yml`, `release-spa-addons.yml` (docs-hub only) | `nvdigitalsolutions/nvoos-docs-hub` | `NVOOS_DOCS_HUB_REPO_TOKEN` |

The token is the same PAT the corresponding `sync-*.yml` workflow already
uses to push `main`. It needs `contents: write` on the mirror repo. When the
secret is missing, the preflight job emits a warning and skips the mirror
publish — the caller stays green.

## Adding a mirror

1. Make sure the package already produces a monorepo GitHub Release (a
   `softprops/action-gh-release` step). The mirror step copies that release;
   it does not build anything.
2. Add a job to the release-producing workflow:

   ```yaml
   mirror-release:
     name: Mirror Release
     needs: [build, release]
     if: needs.release.result == 'success'
     uses: ./.github/workflows/mirror-release.yml
     with:
       mirror_repo: nvdigitalsolutions/<mirror>
       tag: <series>-v${{ needs.build.outputs.version }}
       source_tag: <series>-v${{ needs.build.outputs.version }}
       release_name: <human title> v${{ needs.build.outputs.version }}
       version_file: <plugin-entry-file.php>
       body: |
         <same notes as the monorepo release>
     secrets:
       MIRROR_TOKEN: ${{ secrets.<MIRROR>_REPO_TOKEN }}
   ```

3. Use `needs.<job>.outputs.version` (or a small version-resolving job when
   the release job is a matrix — matrix step outputs are not addressable
   from downstream jobs).

## Not covered

- **`nvoos-content-graph-pro`** — the mirror is sync-only; no standalone ZIP
  build exists yet, so there is no release to mirror.
- **npm packages** (`nvoos-core`, adapters, `nvoos-astro-forge`) — npm is the
  artifact channel; no GitHub Release is produced in the monorepo.
- **`nvoos-cognition`** — the desktop app's updater feed points at the
  monorepo release URL (`tauri.conf.json`), so mirroring its assets needs a
  separate decision about which repo owns the update feed.
