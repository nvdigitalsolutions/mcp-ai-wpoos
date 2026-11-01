# NV oOS Forge Bridge — wp.org listing assets

This directory maps to the plugin's SVN `assets/` directory on
plugins.svn.wordpress.org. It never ships inside the plugin ZIP
(excluded via `.distignore` and the `plugin-check` CI job's staging list).

## Asset checklist

| File | Status | Notes |
|---|---|---|
| `assets/icon-128x128.png` | TODO — manual artwork | Required for the directory listing. See "Artwork" below. |
| `assets/icon-256x256.png` | TODO — manual artwork | Retina variant of the icon. |
| `assets/banner-772x250.png` | TODO — manual artwork | Optional but expected; shown on the plugin page. |
| `assets/banner-1544x500.png` | TODO — manual artwork | Retina variant of the banner. |
| `assets/screenshot-1.png` | ✅ captured | Settings page before configuration (warning notice). |
| `assets/screenshot-2.png` | ✅ captured | Settings page after configuring + saving (success notice, masked credentials). |

## Regenerating screenshots

The QA stack must be running with the plugin active:

```bash
# Symlink the plugin into the QA site's plugins dir (once):
docker exec oos-wp sh -c \
  'ln -sfn /var/www/html/wp-content/plugins/mcp-ai-wpoos/plugins/nvoos-forge-bridge \
   /var/www/html/wp-content/plugins/nvoos-forge-bridge'

# Activate (CLI container needs the repo bind mount for the symlink target):
MSYS_NO_PATHCONV=1 docker run --rm --user 33:33 --network oos-wp_default \
  -v oos-wp_wp_core:/var/www/html \
  -v F:/GITHUB/mcp-ai-wpoos:/var/www/html/wp-content/plugins/mcp-ai-wpoos \
  -e WORDPRESS_DB_HOST=oos-wp-db -e WORDPRESS_DB_NAME=wordpress \
  -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress \
  wordpress:cli-php8.2 sh -c \
  'php -d memory_limit=512M /usr/local/bin/wp plugin activate nvoos-forge-bridge --path=/var/www/html'

# Capture (plugin must be in the unconfigured state first; the script fills
# and saves the form for screenshot 2, so reset options between runs):
node bin/capture-nvoos-forge-bridge-screenshots.js
```

Reset the plugin state between capture runs (screenshot 1 needs the
unconfigured state):

```bash
MSYS_NO_PATHCONV=1 docker run --rm --user 33:33 --network oos-wp_default \
  -v oos-wp_wp_core:/var/www/html \
  -v F:/GITHUB/mcp-ai-wpoos:/var/www/html/wp-content/plugins/mcp-ai-wpoos \
  -e WORDPRESS_DB_HOST=oos-wp-db -e WORDPRESS_DB_NAME=wordpress \
  -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress \
  wordpress:cli-php8.2 sh -c \
  'php -d memory_limit=512M /usr/local/bin/wp option delete nvoos_forge_bridge_rebuild_url --path=/var/www/html; \
   php -d memory_limit=512M /usr/local/bin/wp option delete nvoos_forge_bridge_rebuild_enabled --path=/var/www/html'
```

## Git discipline

`*.png` is ignored per-worktree via `.git/info/exclude` — stage captures
with `git add -f`. When the capture re-runs, verify the diff is a pixel
change and nothing else (`git diff --stat` after staging).

## Artwork (icon + banner)

Manual design work — a forge/hammer glyph on the NV brand palette, sized:

- `source/` masters: 1024×1024 icon, 1344×768 banner
- Icon: 128×128 and 256×256 PNGs
- Banner: 772×250 and 1544×500 PNGs

## SVN upload runbook (post-submission)

1. `svn co https://plugins.svn.wordpress.org/nvoos-forge-bridge` (once).
2. Copy the plugin ZIP contents into `trunk/` and tag it
   `tags/0.1.0/` (Guideline 3 — trunk + tag per release).
3. Copy this directory's `assets/` into the SVN checkout's `assets/`.
4. Assets-only SVN commits do NOT change plugin visibility — the plugin
   goes public on the first `trunk/` code commit.
5. Commit per release, not per change (Guideline 14).

The full 18-guideline checklist and reviewer-reply playbook live in
`.agents/skills/mcp-ai-wpoos-wporg-submission/SKILL.md`.
