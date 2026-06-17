# Build Pipeline Split (Track A — WP.org vs Full Release)

**Since:** v1.1.17 (May 2026)

NV oOS now produces two distinct ZIP artifacts from a single build:

| Artifact | Flag | Contents | Purpose |
|----------|------|----------|---------|
| **WP.org submission ZIP** | `--wp-org` | Base plugin only — no `addons/`, `.zed/`, root `*.md` | WordPress.org plugin directory submission |
| **Full GitHub Release ZIP** | *(default)* | Full monorepo contents including all addons | GitHub Releases, direct download |

---

## Usage

```bash
# Build the WP.org-compliant base-only ZIP
bin/build-plugin-zip.sh --wp-org

# Build the full GitHub Release ZIP (default)
bin/build-plugin-zip.sh

# Build both
bin/build-plugin-zip.sh --all
```

---

## What is excluded from the WP.org ZIP

The `--wp-org` flag excludes the following paths:

- `addons/` — All addon directories (Pro, Chat SPA, Docs Hub, Canvas, etc.)
- `.zed/` — Zed editor configuration
- Root `*.md` files (AGENTS.md, CLAUDE.md, CONTRIBUTING.md, etc.) — kept out of the submission to reduce reviewer friction

The WP.org submission uses `mcp-ai-wpoos-base-{version}.zip` as the filename.

---

## CI Integration

GitHub Actions builds both artifacts on every release tag. The WP.org ZIP is uploaded as a release asset named `nvdigital-open-operator-system-oos-{version}.zip`.

---

## Background: Why the split?

WordPress.org plugin reviewers check the submission ZIP for compliance against WP Plugin Handbook guidelines. Including Pro addon code (which uses PHP 8.1+ enums and paid API integrations) in the WP.org submission ZIP would fail review immediately. The split lets the public WP.org listing stay on the base plugin while the full feature set ships through GitHub Releases.

See [`docs/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md`](compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md) for the compliance posture.
