# `.devcontainer/` — NV oOS WordPress plugin dev container

> **First, read [`AGENTS.md`](../AGENTS.md) and [`CLAUDE.md`](../CLAUDE.md).** They describe the
> agent inventory, layering rule, naming conventions, PHP-compat floors (PHP 7.4+ base,
> PHP 8.1+ Pro), and security expectations that apply inside this container too. After the
> container is up, your first command should be `composer run test:install` (see "Common
> tasks" below).

This folder defines the [Dev Container](https://containers.dev/) used by Zed,
VS Code, GitHub Codespaces, and any other devcontainer-aware tool to give
contributors a reproducible Linux PHP/WordPress environment without polluting
the host machine.

## Files

| File | Purpose |
|------|---------|
| [`devcontainer.json`](devcontainer.json) | Devcontainer spec — points at the local `Dockerfile`, adds the Node.js feature, forwards port 80, runs `post-create.sh` after first build. |
| [`Dockerfile`](Dockerfile) | Custom image: `mcr.microsoft.com/devcontainers/php:8.2` + `subversion`, `default-mysql-client`, `jq`, and WP-CLI. |
| [`post-create.sh`](post-create.sh) | Idempotent bootstrap — installs WP-CLI (no-op if baked into image), downloads WordPress core into a sibling `wordpress/` folder, generates `wp-config.php`, attempts a `wp core install`, and symlinks this plugin into `wp-content/plugins/`. |

## Why a custom image instead of just `image:`?

The repo's own scripts depend on a few OS packages that the bare
`mcr.microsoft.com/devcontainers/php:8.2` image does **not** ship:

- `bin/install-wp-tests.sh` uses `svn export` to fetch the WordPress PHPUnit
  test framework, and `mysqladmin` to create the test database.
- WP-CLI is required by `post-create.sh` and by most day-to-day plugin work.
- `npm run lint:js` and the Jest suite require Node.js.

Baking these into the image (plus the Node feature) means the first
`postCreateCommand` run is offline-friendly and the container starts up the
same way every time.

## Common tasks inside the container

```bash
# Install PHP + JS dependencies (run once after the container builds)
composer install
npm install

# Bootstrap the WordPress PHPUnit test suite (uses svn + mysqladmin)
composer run test:install

# Run the test + lint matrix
composer run lint:base
composer run test
npm run lint:js
npm test
```

## Windows host notes

- Docker Desktop must be in **Linux containers** mode (the default) and using
  the WSL 2 backend.
- The repo's `.gitattributes` pins `*.sh text eol=lf`, so `post-create.sh`
  stays Unix-formatted on Windows checkouts. If you ever see
  `bash: ...post-create.sh: /usr/bin/env: bad interpreter` errors, run
  `git rm --cached .devcontainer/post-create.sh && git checkout -- .devcontainer/post-create.sh`.
- For best file-I/O performance, clone the repo inside WSL
  (`\\wsl$\Ubuntu\home\<you>\mcp-ai-wpoos`) rather than under `C:\` or `F:\`.

## Rebuilding after Dockerfile changes

In Zed: **`devcontainer: Rebuild Container`** from the command palette.
In VS Code: **`Dev Containers: Rebuild Container`**.
On the CLI with the `devcontainer` tool:

```bash
devcontainer build --workspace-folder .
devcontainer up --workspace-folder . --remove-existing-container
```
