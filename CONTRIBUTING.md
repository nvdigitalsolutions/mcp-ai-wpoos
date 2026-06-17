# Contributing to NV oOS

Thanks for taking the time to contribute! This guide summarises the steps you need to get a local environment running, configure API access, run automated checks, and extend the tool registry.

## Prerequisites

* PHP 8.1 or newer
* Composer 2.5+
* Node.js 18+ (optional, only required if you add build tooling)
* A WordPress environment where you can install custom plugins
* An OpenAI API key with access to the chat completions endpoint

## Getting Started

1. Fork the repository and clone it locally.
2. Install PHP dependencies via Composer:
   ```bash
   # For development (installs dev dependencies like PHPUnit, PHPCS)
   composer install
   ```
   
   **Note:** The repository itself is kept in a production-ready state with only production dependencies committed (using `composer install --no-dev`). This ensures the plugin can be cloned and used directly in production. When developing, you'll need to run `composer install` (without `--no-dev`) to get the dev dependencies for testing and linting.
   
3. Start your local WordPress environment and install/activate the plugin.
4. Copy the sample assistant export if you would like quick seed data:
   ```bash
   wp post create assets/examples/assistant-sample.json --post_type=mcp_ai_assistant
   ```
   The JSON file matches the structure expected by `WP_MCP_AI_Assistant_CPT::get_assistant_configuration()` so you can also import it manually through the REST API or the block editor.

## Configuring API Keys

1. In the WordPress admin dashboard, navigate to **Settings → NV oOS**.
2. Paste your OpenAI API key into the **OpenAI API Key** field.
3. (Optional) Update the default model, timeout, or choose a default assistant post.
4. Save the settings. They are stored in the `wp_mcp_ai_settings` option. You can also script this step via WP-CLI:
   ```bash
   wp option patch update wp_mcp_ai_settings openai_api_key "sk-your-key"
   ```

## Running Tests and Quality Checks

Composer provides helper scripts aligned with WordPress coding standards:

```bash
composer run lint        # Runs phpcs with the WordPress ruleset
composer run lint:compat # Runs PHPCompatibilityWP against PHP 7.4–8.3
composer run format      # Attempts to autofix coding standards violations
composer run pot         # Generates/updates languages/wp-mcp-ai.pot
```

These commands assume that `phpcs` and `wp` are available via Composer and WP-CLI respectively. If you are missing dependencies, run `composer install` and ensure WP-CLI is installed on your machine. When contributing, please make sure `composer run lint` and `composer run lint:compat` pass before opening a pull request and include any updates to the `.pot` file when strings change.

## Bootstrapping the WordPress Test Suite

Before running automated tests you need a copy of the WordPress PHPUnit test suite installed locally. The repository bundles a helper script that mirrors the [official instructions](https://github.com/WordPress/wordpress-develop/blob/trunk/tests/phpunit/README.md):

```bash
composer run test:install
```

The default script installs WordPress into a local `wordpress_test` database using the `root` user with no password on `localhost`. Adjust the database credentials and WordPress version by calling the script directly:

```bash
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

If you already have a Docker or Codespaces environment with MySQL available, run the same command inside that container so the database is created alongside WordPress. Replace the database credentials with the values configured in your container.

## Running PHPUnit

Once the test suite is installed you can execute the plugin's test suite with Composer:

```bash
composer run test
```

Alternatively call PHPUnit directly if you prefer more control over the CLI options:

```bash
vendor/bin/phpunit
```

## Extending the Tool Registry

The plugin exposes a registry of MCP-compatible tools through the `WP_MCP_AI_Tool_Registry` singleton (`includes/class-wp-mcp-ai-tool-registry.php`). You can register additional tools by hooking into `wp_mcp_ai_register_tools`:

```php
add_action( 'wp_mcp_ai_register_tools', function ( WP_MCP_AI_Tool_Registry $registry ) {
    require_once __DIR__ . '/includes/tools/class-my-custom-tool.php';
    $registry->register_tool( 'My_Custom_Tool_Class' );
} );
```

Each tool must implement `WP_MCP_AI_Tool_Interface` (`includes/tools/class-wp-mcp-ai-tool-interface.php`) and return a unique slug, a human-readable name, a description, and the JSON schema used to describe the tool to the model. Review the existing tools in `includes/tools/` for reference implementations.

## Submitting Changes

1. Create a feature branch from `main`.
2. Make your changes and commit with clear messages.
3. Run the Composer quality checks listed above.
4. Submit a pull request that explains the motivation for the change and any testing details.
5. Respond to review feedback – we appreciate collaborative iteration!

## Adding or Updating a Dependency

Whenever you add or upgrade a third-party library — Composer, npm, or a hand-vendored copy — every upstream owner must continue to be acknowledged. Run through this checklist before opening the PR:

1. **Update the manifest:** `composer.json` (base or Pro), `package.json` (base, Pro, or addon), or replace the file under `**/assets/**/vendor/`. Re-run `composer install` / `npm install` / your bundling step.
2. **Update [`CREDITS.md`](CREDITS.md)** at the repo root — add/refresh the row for the package with version, license, upstream URL, and copyright holder.
3. **Update [`docs/THIRD_PARTY_ASSETS.md`](docs/THIRD_PARTY_ASSETS.md)** for any JavaScript dependency that ships in the bundle.
4. **Update the addon's `README.md` `## Credits` section** if the dependency is bundled inside an addon (`addons/algorave/`, `addons/canvas/`, `addons/cornerstone3d/`, `addons/embedded/`, `addons/fantasy-football/`, `addons/graphify/`, `addons/pro/`).
5. **Update the Pro Packages admin page** (`addons/pro/includes/admin/class-wp-mcp-ai-pro-packages-settings-page.php`) `get_package_definitions()` if the dependency is a user-relevant Pro npm package — the page renders `homepage` / `license` / `copyright` fields directly from that array.
6. **For bundled Agent Skills curated from an upstream catalogue:** update the matching `THIRD_PARTY_NOTICES.md` (under `includes/bundled-skills/` for base or `addons/pro/includes/bundled-skills/` for Pro) with the upstream commit, original author, and license. Do **not** duplicate this in `CREDITS.md` — link to it instead.
7. **Run `bin/verify-credits.sh`** before pushing — it cross-checks Composer / npm manifests against `CREDITS.md` and surfaces any package missing an attribution entry.
8. **Run a vulnerability check for new dependencies** — `composer audit` for PHP, `npm audit` for JavaScript — and address any high-severity findings before merging. Maintainers using GitHub's MCP toolchain may also use the `gh-advisory-database` MCP tool for the same check.

## Project Management

### GitHub Labels

We use a comprehensive label system to organize issues and PRs. See [LABEL_STRATEGY.md](docs/LABEL_STRATEGY.md) for the complete taxonomy.

**Key label categories:**
- **Type:** `type:bug`, `type:feature`, `type:enhancement`, `type:documentation`
- **Priority:** `priority:critical`, `priority:high`, `priority:medium`, `priority:low`
- **Area:** `area:core`, `area:pro`, `area:tools`, `area:admin-ui`, `area:frontend`, `area:api`
- **Status:** `status:needs-triage`, `status:in-progress`, `status:review-needed`

Labels are applied automatically via GitHub Actions for common patterns.

### Milestones

All issues are assigned to milestones for release planning:
- **v1.0.x** - Patch releases (bug fixes, security)
- **v1.x.0** - Minor releases (new features)
- **vX.0.0** - Major releases (breaking changes)
- **Backlog** - Approved but not scheduled

See [MILESTONE_STRATEGY.md](docs/MILESTONE_STRATEGY.md) for details.

### Roadmap

Check [ROADMAP.md](docs/ROADMAP.md) for planned features and release dates. Vote on features by adding 👍 reactions to issues.

### Release Process

Maintainers follow [RELEASE_PROCESS.md](docs/RELEASE_PROCESS.md) for all releases.

---

## GSD × BMAD Development Methodology

NV oOS uses a hybrid **GSD (Get Shit Done) + BMAD (Breakthrough Method for Agile AI-Driven Development)** methodology for AI-assisted feature development. This workflow is fully documented in [docs/project/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md](docs/project/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md).

### Workflow Summary (10 Phases)

| Phase | Name | Lead | Purpose |
|-------|------|------|---------|
| **0** | Context Init | Scrum Master | Load context files, initialize feature context, set token budget |
| **1** | Discovery | Analyst | Domain research, Project Brief |
| **2** | Planning | Product Manager | PRD, epics, stories, tool/API definitions |
| **3** | Architecture | Architect | System design, data model, file map, security model |
| **4** | Story Breakdown | Scrum Master | Atomic stories with embedded architecture context |
| **5** | Implementation | Developer | Isolated GSD-style story execution, atomic commits |
| **6** | Validation | QA Engineer | PHPUnit, PHPCS, ESLint, CodeQL, acceptance criteria |
| **7** | Release | Scrum Master | Version bump, CHANGELOG, Git tag, GitHub Release |
| **8** | Monitoring | QA Engineer | 48–72 hour post-release health monitoring |
| **9** | Retrospective | Scrum Master | Context harvest, learnings persisted, context archived |

### Scale-Adaptive Usage

Not every change requires the full workflow. Choose based on complexity:

| Change Size | Phases Required |
|------------|----------------|
| **Patch / Bug Fix** | 5, 6, 7 |
| **Small Feature** | 0, 4, 5, 6, 7, 9 |
| **Medium Feature** | 0, 1, 2, 3, 4, 5, 6, 7, 9 |
| **Major Feature / Integration** | 0–9 (all phases) |

### Infrastructure

- **Agent definitions:** `.bmad/agents/` — YAML role definitions for each BMAD agent
- **Team compositions:** `.bmad/teams/feature-development.yaml` — Multi-agent team configuration
- **Context files:** `.context/` — GSD context engineering files (conventions, security, subsystem guides)
- **Templates:** `docs/project/proposals/templates/` — Project Brief, PRD, Architecture Spec templates

### Phase-Completion Checklists

#### Pre-Implementation Gate (Before Phase 5)

- [ ] Project Brief approved (Phase 1 complete)
- [ ] PRD complete with all acceptance criteria (Phase 2 complete)
- [ ] Architecture Specification reviewed and Architecture Review Checklist checked (Phase 3 complete)
- [ ] Stories broken down and sequenced in task plan (Phase 4 complete)
- [ ] Security model defined
- [ ] Test strategy defined

#### Per-Story Gate (During Phase 5/6, Before Merging)

- [ ] All acceptance criteria met
- [ ] PHPUnit tests pass: `composer run test`
- [ ] PHPCS clean: `composer run lint`
- [ ] ESLint clean (if JS changes): `npm run lint:js`
- [ ] CodeQL scan passes
- [ ] PHPDoc blocks on all new classes and methods
- [ ] Security checklist verified:
  - [ ] Input sanitized (`sanitize_text_field()`, `absint()`, etc.)
  - [ ] Output escaped (`esc_html()`, `esc_url()`, etc.)
  - [ ] Capabilities checked before privileged operations
  - [ ] Nonces verified for state-changing requests
  - [ ] ABSPATH guard on all new PHP files
- [ ] Documentation updated if needed
- [ ] Base vs Pro gating correct
- [ ] If `.github/agents/*.agent.md` was added or changed, the Agent Inventory in `AGENTS.md` §1 was updated in the same PR, and the file follows the layering rule in `AGENTS.md` §2 (no duplicated naming/security/PHP-compat content)

#### Release Gate (Phase 7)

- [ ] All stories in milestone complete
- [ ] Full test suite passes
- [ ] `composer run build:autoload` completed and classmap committed
- [ ] Version bumped consistently (plugin header, `composer.json`, `package.json`, `WP_MCP_AI_VERSION`)
- [ ] `CHANGELOG.md` entry with affected files and API changes
- [ ] Git tag created matching plugin header version
- [ ] GitHub Release drafted and published
- [ ] WordPress.org plugin check passes (for base plugin changes)
- [ ] Backward compatibility verified

Thank you for helping improve NV oOS. 🚀
