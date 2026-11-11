# Release-window ledger (docs & release catch-up)

Moved out of `SKILL.md` on 2026-11-08, where it had grown to 39 KB of a
98 KB file against Zed's ~100 KB skill-load limit. This is the per-pass
catch-up narrative, newest first: the plan file for each pass plus the scope
rules that pass executed. Consult it when a later pass needs the precedent
behind a classification decision - how an uncovered PR, a superseded build
set, or a bundled-notes drift was handled in an earlier window.

- **1.2.19 pass** — `docs/project/plans/1.2.19-docs-catch-up.md` (PRs **#7531–#7580**): security-led window — **one canonical client-IP resolver** behind the new `wp_mcp_ai_trusted_proxies` filter (#7570 — 17 duplicated `get_client_ip()` implementations collapsed, `REMOTE_ADDR` authoritative, `X-Forwarded-For` walked right-to-left, `HTTP_CLIENT_IP` no longer read), **public write routes must authorize their target** (#7571 — the workflow webhook fails closed with `503 trigger_not_configured` instead of skipping HMAC, outbound booking rejects unpublished links, and a repo-wide `check-public-write-routes.php` gate reviews every `__return_true` write route), and `nvoos-forge-bridge` **0.3.1** from the wp.org T2 review (#7569); **SPDX licence headers on every source file + `bin/check-license-boundary.sh` + `license-boundary.yml`** (#7545 — 26 GPL declarations inside the MIT `lib/core` package and 9 GPL grants on Pro code corrected, and `.gitattributes` now `export-ignore`s `addons/pro/` so GitHub source ZIPs stop shipping the proprietary tree); the **Astro Forge managed tier + vendor-agnostic band extraction** (Proposals 135–137, app-side) with a managed deploy tree that ships its runtime deps behind a blocking boot gate; **EZuite CCT** descriptive columns (#7575); vendored Pro deps refreshed (#7580). New scope rules executed: **zero tool registrations** (the registry diff is the SPDX insert only) and **zero catalog change**; the **A5 skill fold-in was owed** again — #7562 added `nvoos-forge-deploy` and updated `AGENTS.md` + copilot-instructions to 83 but **not the README repo map** (82, no name); the **Zed description budget was at 50,590 B**, only ~400 B under the documented 51 KB drop point, so four of the largest NV-authored descriptions were trimmed to **49,972 B** (repo 46.4 KB + global 4.2 KB) with the bundled `dictation-app` copy re-synced byte-identical; **in-window skill edits verified current rather than assumed** (test-suite pattern 93, wporg target-authorization, perf-audit authoring mechanics, git-ops worktree traps all present); the window's only new release tag is `@since 1.2.19` ×5 in `helpers.php`, **correctly pre-tagged**, so **no new OI-1 group**; `lib/core`'s `@package … @since 1.0.0 / 2.0.0` SPDX tags are package convention, not plugin releases. Tool count **unchanged** (~358/~1,388/~1,745), skills **82 → 83**, addons **32** (unchanged), catalog unchanged, and the stale **1.2.17** build set removed (30 files — 9 + 2 + 19) plus the superseded `nvoos-forge-bridge-v0.3.0.zip`.

- **1.2.18 pass** — `docs/project/plans/1.2.18-docs-catch-up.md` (PRs **#7446** + **#7458–#7529**): the **PHP 8.1 baseline** (7.4 → 8.1, `WP_MCP_AI_MIN_PHP`, composer, Site Health) with **`lib/core` typed to PHPStan 8** and a **tool-schema contract CI gate** (446 tools), plus the packaging-parity fix (the combined build now ships `lib/`; GitHub's Download ZIP matches it — `export-ignore`/`.distignore` re-anchored) and the guzzle 7.15.2 vendor resync; **Proposal 129** (+10 Pro CRM admissibility tools + the signed `mcp-ai/v1/crm/unsubscribe` route); the **Astro Forge design-rebuild engine** (Proposal 114, app-side, with the `nvoos-forge-bridge` form bridge/redirects and 0.1.0 → 0.3.0); the shared `packages/nvoos-design-import` 0.1.0; the new **QuickBooks Desktop MCP** (Proposal 113) + its skill; the **Cognition memory layer**; gateway **0.3.2** + claude-gateway-plugin **0.1.1**; and the CI-enforced **knowledge-base facts** refresh. New scope rules executed: the **#6787 uncovered-PR rule fired once** (#7446 merged 15 minutes before the #7447 catch-up and the [1.2.17] changelog never covered it); a **pre-staged `[Unreleased]` block** (#7529 + #7490) was converted into the dated section and extended — #7529's own entry documented only the packaging fixes, so the pass added the PHP-floor, typed-engine and contract-gate content; the **A5 skill fold-in was owed** (a new `mcp-ai-wpoos-qb-desktop` never touched AGENTS.md / copilot-instructions / README → 81 → 82); the **Zed description budget was re-checked** (43.5 KB after the addition); the **bundled plugin-skill pair stayed byte-identical** and the RELEASE-NOTES companion gained v1.2.18 on both copies; the **ADDON_INVENTORY gateway row was factually stale** (0.3.1 vs the shipped 0.3.2 — #7446 never updated it) and the **README PHP badge still read 7.4** after the floor raise. OI-1: new **group 68** (+22 × `@since 3.3.0` across the Proposal 129 CRM files — the 3.2.0-family precedent, group 16). Tool count **+10 Pro** (~358/~1,388/~1,745), skills **81 → 82**, addons **32** (two version moves), catalog unchanged, PHP floor 8.1, and the stale **1.2.16** build set removed (30 files — 6 + 3 + 2 + 19).

- Plan templates: `docs/project/plans/v1.1.58-docs-catch-up.md`,
  `docs/project/plans/v1.1.59-docs-catch-up.md`,
  `docs/project/plans/v1.1.83-docs-catch-up.md`,
  `docs/project/plans/v1.1.83-post-docs-catch-up.md`,
  `docs/project/plans/v1.1.84-docs-catch-up.md`,
  `docs/project/plans/v1.1.85-docs-catch-up.md`,
  `docs/project/plans/v1.1.86-docs-catch-up.md`,
  `docs/project/plans/v1.1.87-docs-catch-up.md`,
  `docs/project/plans/v1.1.88-docs-catch-up.md`,
  `docs/project/plans/v1.1.89-docs-catch-up.md`,
  `docs/project/plans/v1.1.90-docs-catch-up.md`,
  `docs/project/plans/v1.1.91-docs-catch-up.md`,
  `docs/project/plans/v1.1.92-docs-catch-up.md`,
  `docs/project/plans/v1.1.93-docs-catch-up.md`,
  `docs/project/plans/v1.1.94-docs-catch-up.md`,
  `docs/project/plans/v1.1.95-docs-catch-up.md`,
  `docs/project/plans/v1.1.97-docs-catch-up.md`,
  `docs/project/plans/v1.1.98-docs-catch-up.md`,
  `docs/project/plans/v1.1.99-docs-catch-up.md`,
  `docs/project/plans/1.2.11-docs-catch-up.md`,
  `docs/project/plans/1.2.16-docs-catch-up.md` (this pass — the
1.2.16 pass over PRs #7364–#7393: the deep-research reliability wave (#7376/#7377/#7384 — validation gate, measurable depth, staged evidence, richer diagnostics, **+3 base tools** `research_status`/`research_result`/`research_cancel`, the new `deep_research_model` setting + `gpt-5.6-sol` fallback, and the shared `WP_MCP_AI_Tool_Research_Completion` trait across the ten Pro research tools), the abilities-bridge MCP-adapter interop (#7383 — raw `WP_Error` passthrough + pessimistic `destructiveHint` mapping, verified live), the gateway OAuth/ChatGPT go-live (#7371–#7375/#7381 — `GATEWAY_OAUTH_DEFAULT_SITES`, underscore tool names, gateway **0.3.0** + the OpenAI challenge route; Proposal 099 Phases 0–4 live) with the **new `chatgpt-gateway-plugin` addon 0.1.0** (Proposal 101) + chatgpt-plugin 0.2.0 (#7379), the dictate → **NV oOS Cognition v0.3.0** rebrand + first-party console window + screen OCR (sub-project, #7380/#7382/#7385–#7387, Proposals 100/102/103), the forge-bridge wp.org pre-submission (#7388 — POST-only verify, `edit_pages` minting, 30/30) + standalone framing (#7390) + forge npm 0.1.1 dist bins (#7391) + the public mirror, the Zed skill-catalog description-budget slimming (#7378), the npm-deprecate workflow, the glob 9/11/13 → 14.0.2 overrides (#7367), and the dictate release fix (#7364). New scope rules executed: the #6787 uncovered-PR rule fired **three times** (#7364/#7365/#7367 merged 09:30–09:31Z, 6–7 min before the #7368 catch-up merge, and the 1.2.15 changelog never covered them — cross-check the changelog for PR numbers during orientation, the tree diff alone misses them); direct commits (the dictate PTT fallback engage+revert — net-zero, the forge mirror, the npm-deprecate workflows) join the window without PR numbers; the gateway row's version column had lagged through 0.2.0 (pre-existing drift) while the in-window #7381 set 0.3.0 — corrected in-pass with the ChatGPT Plugin 0.1.0 → 0.2.0 in-window bump. Proposals README 100/101/102/103 registration stays deferred (another agent's staged 097 entry). OI-1: group-38 extension (+5 `@since 1.2.0` in the new `trait-wp-mcp-ai-tool-research-completion.php` — the Pro research folder's 1.2.0 trait family) + new group 66 (+12 `@since 1.2.0` in-window in base `class-wp-mcp-ai-tool-deep-research.php`; convention note: the three new research-* tools' `@since 1.1.0` ×3 mirror the sibling file's family). Tool count +3 base (~358/~1,378/~1,735), skill count 81 unchanged (in-place updates only), zero catalog diff (#7376 is a settings default), addons 30 → 31, and the stale 1.2.14 build-set removal (30 files — 9 + 2 + 19))

Preceding windows:
  `docs/project/plans/1.2.17-docs-catch-up.md` (this pass — the
1.2.17 pass over PRs #7395–#7445: the Astro storefront content & checkout
wave (Proposals 104–108 — runtime hardening + theme policy + `content_*`
MCP slice + Q6 component library + checkout/cart hardening), the embedded
checkout via the Store API (Proposal 110) with the prod-trace fixes + the
native→Woo catalog migration (Proposal 112, Woo-owned catalog, bridge
1.1.3), the Cognition Document OCR pipeline + DeepSeek-OCR tier (Proposals
109/111, sub-project), the November 2026 Track B model-catalog refresh
(v2026.11.03, 251 models, #7416/#7417/#7440), gateway 0.3.1 + the new
`claude-gateway-plugin` addon 0.1.0 (#7444/#7445), and the Pro Schedule
Manager Runs & Reports + fleet card (#7411/#7412). New scope rules
executed: a **feature-branch PR** (#7417 — merged into
`feat/model-catalog-november-2026`, tree reached alpha-working inside
#7416's merge) classifies with its parent PR; the **astro-bridge ZIP
supersession** (4 files — v1.0.1/v1.1.0/v1.1.1/v1.1.2 removed once v1.1.3
built, the v1.2.2 docs-hub completion precedent); the **1.2.16 pass's
bundled-RELEASE-NOTES miss** (the Zed plugin-skill companion gained the
v1.2.16 section but the base-bundled copy stayed on v1.2.15) and the
**pre-Cognition-rebrand bundled dictation-app copy** (two windows behind)
were both byte-identical re-synced in-pass; FOR_REVIEWERS drift corrected
(Funiq Bridge 1.0.0 → NV oOS Astro Bridge 1.1.3, MCP Gateway 0.1.1 →
0.3.1, 31 → 32 addons + the four missing addon rows); the README repo-map
addons row 30 → 32 (the 1.2.16 pass had left it at 30 — the #7019-type
lower-surface drift). OI-1: new group 67 (+1 `@since 1.2.0` in
`addons/pro/includes/rest/class-wp-mcp-ai-pro-schedule-rest-controller.php`
#7412 — the file's 1.0.0 family). Tool count unchanged
(~358/~1,378/~1,735), skill count 81 unchanged (in-place updates only),
addons 31 → 32, and the stale 1.2.15 build-set removal (30 files — 9 + 2
+ 19) + the superseded astro-bridge ZIPs (4 files))

Preceding windows:
  `docs/project/plans/1.2.15-docs-catch-up.md` (executed — the
1.2.15 pass over PRs #7308–#7363: Astro Forge Phases 0–4 (#7335–#7344, Proposal 096 — the MIT-licensed `examples/nvoos-astro-forge` generator, the 29-tool `/mcp` surface, and the new standalone `plugins/nvoos-forge-bridge` 0.1.0 sub-project), the Proposal 098 design-language import (#7331/#7334 — open-world `design_*` tools, bridge draft/publish products), the Proposal 095 completion (#7318/#7320/#7322/#7323/#7325 — CMS/ops slices + NDS DTCG round-trip + the Design tab + the bridge SPA MIT carve-out), console site-title branding + Records remote browsing (#7321/#7324/#7326/#7327/#7330), the npx-bridge manager-session bootstrap (#7328, 0.1.0-alpha.5), the gateway registry publish (#7345–#7349 — version-marker fix, `PUBLIC_SITE_SLUGS`), Proposal 099 + the PKCE e2e harness (#7361/#7362), the dictate release fixes (#7351/#7353/#7354/#7356 + main twins #7352/#7355/#7357), and the dependency sweeps (#7308/#7311/#7360). New scope rules executed: the #6787 uncovered-PR rule (#7308 — merged 13 min before the #7309 catch-up); main-line twins build-classified; a worktree branch-switch mid-pass → the linked-worktree recipe (`git worktree add -b … origin/alpha-working`, edit there, never the shared tree); the proposals-README registration stays deferred when the shared file carries another agent's uncommitted edits. A5 fired again: the README repo-map skill row was 76 while AGENTS.md/copilot-instructions were 81 in-window — the fold-in owed (plus the missing five names); FOR_REVIEWERS 76 → 81. Skill reconciliations beyond frontmatter: the #7336 client-scrub touched only the Zed copies — the six bundled copies of design-crm/design-email-marketing/design-seo-content (base + CG-AI platform) still carried client identifiers, the scrub ported in-pass (base pairs byte-identical); and the dictation-app skill gained the v1.2.15 release-pipeline hardening bullet (tauri CLI + softprops, conditional Apple signing env, tag-tree workflow evaluation, the Windows exe job). The 1.2.14 pass's v1.2.2 "demotion" had left the full entry in the Release History (13 full entries + the table row) — this pass removed BOTH v1.2.2 and v1.2.3 full entries to restore the 12-row invariant. OI-1: group-35 extension (+2 `@since 0.2.0` in the new `class-nvoos-nds-dtcg-importer.php` while the addon ships 0.3.0; the in-window NDS admin-page/plugin additions carry no tags — recorded as an addon-level missing-tags note) + a sub-project convention note (`plugins/nvoos-forge-bridge` carries no `@since` tags — own track). Tool count unchanged (~355/~1,378/~1,732), skill count 79 → 81, zero catalog diff, no addon bumps, and the stale 1.2.13 build-set removal (30 files — 9 + 2 + 19))

Preceding windows:
  `docs/project/plans/1.2.14-docs-catch-up.md` (executed — the
1.2.14 pass over PRs #7261–#7307: the Astro storefront + bridge rename + cookie-auth restoration (Proposals 092/095, #7274–#7307), F&B multi-connection Drive routing (Proposal 093, #7263/#7266/#7268), the gateway policy layer (Proposal 094, #7269 — addon 0.2.0), Google Workspace fallback + the settings grant (#7265/#7267), the commerce subtabs (#7262/#7264), the front-end asset-leak guards + OKF order fix (#7271/#7275), the dependency advisories (#7273/#7288), and three new skills (astro-storefront, git-ops, sdk-v2-server-ops — 76 → 79). Zero tool-count change, zero catalog diff, no addon bumps (bridge rename 1.0.1 + gateway 0.2.0 recorded), and the stale 1.2.12 build-set removal (30 files))

Preceding windows:
  `docs/project/plans/1.2.13-docs-catch-up.md` (executed — the
1.2.13 pass over PRs #7239–#7260: the Dify parity gap-fill (#7246/#7247, Proposal 091 — the workflow DSL codec + routes + CLI + Pro SPA panel, prompt versioning lite, the `lib/core` ProviderRouter fallback chains with assistant-meta precedence, the `gen_ai.*` spans + opt-in OTLP exporter, the annotation store, and the +1 Pro `export_annotation_dataset` with the #7256 preset/manifest fold-in), the console pagination + loading states (#7239/#7255 — true totals, the `/approvals` pending-depth envelope, keep-previous-data busy states) and admin-only gating (#7259), `GET /mcp-ai/v1/session/me` + the Breeze cache integration + the console stale-nonce fix (#7257), the OAuth `rawurlencode()` + bare-URL fallback (#7240/#7242), the Drive auto-fallback (#7244), the Remote Sites assistant-scoping wave (#7258 — 12 families, CG-Pro mirrors), the OTLP endpoint deny-list (#7251), and Proposal 092 (#7260, docs-only). New scope rules executed: the `[Unreleased]` conversion (#7260's pre-staged docs entry); the RELEASE-NOTES "unreleased — post-1.2.12" section conversion + the Zed/base-bundled plugin-skill byte-identical re-sync (the in-window PRs touched only the Zed copy); the toolkit-audit class 11 addition. OI-1: group 60 extension (+1 `2.4.0` in the Pro remote-site-manager) + the CG-AI Embeddings `1.1.0` ×3 sub-project convention note; `@since 1.2.13` ×110 correctly pre-tagged in-window. Tool count +1 Pro → ~355/~1,378/~1,732, skill count 76 unchanged, zero catalog diff, no addon bumps, and the stale 1.2.11 build-set removal (30 files — 9 + 2 + 19))

Preceding windows:
  `docs/project/plans/1.2.12-docs-catch-up.md` (executed — the
1.2.12 pass over PRs #7205–#7235: the magic-login REST controller (#7206 —
unauthenticated-by-design with a named permission callback, CSPRNG tokens
hashed at rest, rate-limited; #7208 adds the settings subtab), the native
addon islands (Proposal 087, #7213 — the vendored docs-hub IIFE + the
`mcp-ai-pro/v1/okf/docs` adapter; the three addon sources gain island APIs
**without version bumps** — shipped ZIPs stay current, the inventory note
refreshed), the registry-backed tool picker (Proposal 088, #7221/#7232 —
per-tool `flags` + the `scope=all` tools-route opt-out), the console
Connections page (`mcp-ai-pro/v1/remote-sites`, #7220), the Commerce
console surfaces (Proposal 089, #7231), the tool-schema bounds sweep
(#7210 — 763 integer fields to the 2^53−1 ceiling, zero tool-count
change), the profession-meta/team-relink + records `restBase` fixes
(#7219/#7233), the settings-coercion + dark-mode + author-403 fixes
(#7215/#7224–#7226), and Proposal 090 (#7230, docs-only). New scope rules
executed: the two-set build retention convention (each pass removes the
N−2 artifact set — 1.2.12 removed 1.2.10 while 1.2.11 stays as the
fallback); the correctly-pre-tagged new file (`@since 1.2.12` ×6, #7210 —
no action); proposals 087/088/089/090 shipped **unregistered** in-window
(the pass owns the README registration — unlike #7203's 086); in-window
skill updates (spa-ui/spa-rest-contract/test-suite ×4 PRs) still left the
commerce wiring row + ToolPicker note + the three new controllers missing
— the pass extended both skills in-pass. OI-1: group 60 extension (+46
`2.4.0`) + group 40 extension (+4 addon-level `0.1.0`) + new groups 63
(`2.1.2` ×23 OKF-docs), 64 (magic-login controller — missing tags), 65
(`1.9.6` ×1 team-repository), zero tool-count change, zero catalog diff,
three skill frontmatter reconciliations (spa-ui, spa-rest-contract,
test-suite → 1.2.12) + the plugin ×3 + RELEASE-NOTES 1.2.12 section, and
the stale 1.2.10 build-set removal (30 files — 9 + 2 + 19))

Preceding windows:
  `docs/project/plans/1.2.11-docs-catch-up.md` (executed — the
1.2.11 pass over PRs #7189–#7203: the Velocity caching layers + REST
cache opt-in (#7197 — both Velocity `serve.mjs` apps gain the caching
contract with the default-deny proxy micro-cache + `NVOOS_PROXY_CACHE`
knobs; `GET /mcp-ai/v1/features` emits `public, max-age=60, s-maxage=60`;
the new `WP_MCP_AI_REST_Cache_Control` opts out of WP 6.9's authenticated
no-cache injection only for explicitly public responses), the SSE long-run
offload Phase 4b (#7199 — `WP_MCP_AI_Chat_Stream_Offload_Worker` +
`job_offload` event + the extended continuation snapshot; the
`content_format_templates` seeding deferred to `wp_loaded`), the Complete
Console login/sign-out/session gate (#7190/#7192/#7196), the front-end
REST bridge-provider fix (#7189 — eager-require + lazy `load_provider()`
seam + 501 `WP_Error` branches), the profession ballooning fix (#7191 —
counts-cache invalidation + slug-match seeder dedupe, mirrored to
CG-AI-platform), the dashboards `disabled` map + Security Center UI +
workflow NodeEditor (#7193), the dependency sweep (#7195 — 67 of 74
alerts, deferred #7089/#7090/#7194 tracked), the continuation null-safety
+ risky-test repairs (#7201), the console mobile fixes (#7200/#7202),
Proposal 086 (#7203, docs-only), and the content-graph 1.0.12 upload prep
(#7198 — sub-project flag-not-edited, ZIP built). New scope rules
executed: the `[Unreleased]` pre-staging conversion (#6809 rule); the
in-window sub-project version bump corrected the ADDON_INVENTORY row
in-pass (1.0.11 → 1.0.12); the wporg-submission skill's frontmatter was
found two versions stale (1.1.99) despite its in-window content update.
OI-1 group 62 (new: `@since 1.2.10` ×7 one-behind) + group 60 extension
(+2 `2.4.x`), zero tool-count change, zero catalog diff, seven skill
reconciliations (plugin ×3 + RELEASE-NOTES, spa-rest-contract, spa-ui,
wporg-submission, test-suite, dependabot-loop, schedule-anything-ops,
content-graph-spa, checkout-integration → 1.2.11), and the stale 1.2.9
build-set removal (30 files — 9 + 2 + 19) + the superseded content-graph
v1.0.11 ZIP (2 files — v1.0.12 built in-window))

Preceding windows:
`docs/project/plans/1.2.10-docs-catch-up.md` (latest executed — the
1.2.10 pass over PRs #7178–#7186: the console-migration Phase 5b–5d
continuation (#7180, Proposal 084 — the orphaned cost/analytics
REST-controller registration fix in `includes/bootstrap/loader.php`,
the real Analytics page, the Unified Blueprints catalogue, the
Command-Center-into-Dashboard absorption, plus the semantic DataTable
+ grouped collapsible sidebar console polish), the console crash fixes
(#7184 — the blueprints 500 admin-context class gating, React error
#310 on four pages with the permanent `react-hooks/rules-of-hooks`
ESLint gate, the settings page + chat dock UX), the server
transcript-storage meter (#7185 — `get_storage_bytes()` + the
`storage_bytes` envelope field), the dependency advisory sweep
(#7186 — markdown-it 14.3.3 / MCP SDK 1.32.0 / launch-editor 2.14.2,
14 of 16 alerts), the full tracked-bundle rebuild (#7182, 16 files),
the standalone SPA endpoint sync (#7181), the rebuild-discipline
skills (#7183 — spa-ui + dependabot-loop Step 7), and the Cloudways
Anti-Bot Protection playbook (#7178). New scope rule executed: a
reverted-and-relanded PR pair (#7179 → #7180) counts once — the
reverted PR's tree is a strict subset of the re-land. OI-1 group 60
extension (+13 `@since 2.4.0` in the two Pro REST controllers,
recorded not fixed), zero tool-count change, zero catalog diff, five
in-window skill frontmatter reconciliations (spa-ui, dependabot-loop,
spa-rest-contract, test-suite, schedule-anything-ops → 1.2.10), and
the stale 1.2.8 build-set removal (30 files — 9 + 2 + 19))

Preceding windows:
  `docs/project/plans/1.2.9-docs-catch-up.md` (the
  `docs/project/plans/1.2.4-docs-catch-up.md` (executed — the
  1.2.4 pass over PRs #6998–#7010: the MCP client-quirk hardening
  (#7007, Proposal 066 — the `WP_MCP_AI_Tool_Schema_Auditor`, the
  pre-staged `[Unreleased]` converted, zero tool-count change), the
  nvoos-chart recipes + `create_chart_fence` (+1 base, #7003/#7005
  with the `design-chart-recipes` skill), Model Foundry Phase 1 (+3
  Pro exporters, Proposal 067, #7008 with the
  `mcp-ai-wpoos-model-foundry` skill), the Content Graph explorer
  page in the Pro SPA (#6998 — sub-project flag-not-edited for the
  CG plugin's `visual-config` route + credential auth; the
  `mcp-ai-wpoos-content-graph-spa` skill landed in-window), the
  standalone SPA deploy/auth hardening (#6999/#7000/#7002/#7004/#7006
  + the static-server headers #7009), the F&B toggle fix (#7010),
  the **user-directed partial `@since` reconciliation** (the literal
  `2.x.0` placeholder family — never recorded in OI-1 — corrected to
  `1.1.43`/`1.1.51`/`1.1.54`/`1.2.4`, blame-verified; model-foundry
  `1.6.0` + chart-fence `1.4.0` → `1.2.4`; the mcp-apps 1.9.x family
  + OI-1 groups 1–54 stay parked — the new A2 scope rule),
  skill-count 66 → 69 (three new skills in-window with the README
  repo-map fold-in owed — A5 fired again; the AGENTS.md §1.5
  design-* 34 → 35 correction), tool count +1 base +3 Pro →
  ~354/~1,342/~1,696, zero catalog diff, zero sub-project edits,
  and the stale 1.2.2 build-set removal (6 root files — the 1.2.3
  ZIP set landed in-window))

Preceding windows:
`docs/project/plans/1.2.3-docs-catch-up.md` (executed — the
  1.2.3 pass over PRs #6984–#6996: the CORS same-origin enforcement
  (#6986 — the 12th security class `WP_MCP_AI_CORS_Guard` overriding
  core's origin reflection, the `cors_allowed_origins` setting, zero
  tool-count change), the standalone-SPA WordPress login (#6993,
  Proposal 064 — the fourth auth mode via an Application Password +
  the OOS streaming-CORS fix), the SPA REST auth fixes (#6990 —
  credential-scoped transcripts/approvals + session-nonce re-bind,
  closes #6987/#6985), the schedule-manager multi-recipient
  `notify_email` + quick wins (#6991 — closes #6642/#6651; OI-5 closed,
  OI-4 item 5 fixed), the standalone-SPA cookie mode + `serve.mjs`
  proxy + chunk splitting (#6994), the `.env` loading (#6996), the npm
  ESM-dist fixes + Vite demo (#6984 — alpha.4 publish deferred), the
  CORS/Velocity docs (#6989) + triage snapshot (#6992), the build fixes
  (#6988 `git init -b main`; #6995 deploy-tree layout), **two changelog
  pre-staging anomalies caught-up** (the #6986 CORS block post-hoc
  inserted into the shipped [1.2.2] section — relocated into [1.2.3]
  and recorded as a new scope rule; the #6993 [Unreleased] section —
  converted), one new OI-1 group (54: `@since 1.2.2` ×14 — cors-guard
  ×12 + schedule-manager/plan-tool ×2, one-behind), the security-class
  count 11 → 12 surfaces (copilot-instructions ×2, CLAUDE.md ×2,
  FOR_REVIEWERS ×2 stale-10 corrections, EU_AI_ACT), three skill
  reconciliations (plugin ×3 + RELEASE-NOTES + the CORS entry version
  fix; spa-ui — the fourth auth mode + proxy/env/deploy notes; updates),
  the stale 1.2.1 build-set removal (30 files — 9 + 2 + 19) with the
  ollama-demo.json URL carry, zero tool-count change, zero catalog
  diff, and zero skill-count change (66))

Preceding windows:
  `docs/project/plans/1.2.2-docs-catch-up.md` (executed — the
  1.2.2 pass over PRs #6966–#6982 + the direct `c9d54bd273` CI commit: the
  chat-profile system (#6977, Proposal 015 — server-enforced read-only mode,
  the 11th security class, `GET/POST /mcp-ai/v1/chat-profile` deliberately
  not a tool, zero tool-count change), the F&B assistant packs (#6982,
  Proposal 062 Phase 4 — four importable bundles + the seeder, no new
  slugs), the Google OAuth production readiness (#6981 — `drive.file`
  unification, Calendar Minimal, upstream revocation), the Pro SPA v2 fixes
  (#6974 dead-class default assistant + non-admin visibility; #6976 sidebar
  toggle + live sessions; #6979 TS parity + full JS rebuild), the test drift
  fixes (#6978/#6980 — both lessons skill-encoded), the CI/build fixes
  (#6970 SPA tag-release + new release-spa-addons.yml; #6972 demo-videos
  manual-only), the docs-hub 0.5.3 pre-submission pass (#6966/#6967/#6968 —
  tag pushed + LIVE on the wp.org directory) and content-graph 1.0.10
  (#6973 — excluded-source pruning; run Rebuild Graph once), Proposal 063
  (#6969, docs only), three new OI-1 groups (51: `@since 2.2.0` ×84
  chat-profile wave; 52: `@since 2.1.1` ×1 pro helper; 53: `@since 1.2.0`
  ×1 oauth-manager) + the group-50 extension (#6982's 1.6.0 ×3), the
  OI-4 extension (#6974's pagination-fields fixture accumulation — **closed
  by the 1.2.3 window's #6991**),
  five skill reconciliations (plugin ×3 + RELEASE-NOTES + the stale
  Architecture count line; wporg-submission Fifth pass; checkout-integration
  0.5.3/1.0.10 refs; test-suite 68 → 69 patterns; updates), the
  FOR_REVIEWERS pre-existing drift corrections (Chat SPA 0.7.0, SaaS
  Controller 0.3.0, Comic Reader 0.5.0, MCP Gateway 0.1.1, Schedule
  Anything SPA 0.2.0, Design System rename, Media Studio 0.6.1, Toolkit
  Shell 0.3.0), the security-class count 10 → 11 surfaces
  (copilot-instructions ×2, CLAUDE.md ×2), the stale 1.1.99 build-set
  removal (30 files — 9 + 2 + 19) + the superseded docs-hub
  v0.5.1/v0.5.2 + content-graph v1.0.8 ZIPs (4 files — the tag-pending
  rule's completion), zero tool-count change, zero catalog diff, and zero
  skill-count change (66))

Preceding windows:
  `docs/project/plans/1.2.1-docs-catch-up.md` (executed — the
  1.2.1 pass over PRs #6954–#6964 + the five direct Proposal-061 doc
  commits: the **version-jump decision executed** (1.1.99 → 1.2.1 — the
  ROADMAP's planned 1.2.0 scope was delivered early across v1.1.1–v1.1.35,
  so the first 1.2-line release is numbered 1.2.1; the OI-1 "Blocked on"
  line records the decision), the Food & Beverage Management Pro Toolkit
  (#6962, Proposal 062 — 21 gated `fnb_*` Pro tools, 20/20 Check-totals
  oracle, phases 4/5b/6/8/9/10 deferred in the implementation plan), the
  Docs Hub content tools (#6964 — 5 `docs_hub_*` Pro tools), the MCP
  server identity tool (#6956 — `mcp_server_info` +1 base, `_meta`
  block, bridge-name seam; CG-AI sync = tracker Pending row), the Docs
  Hub checkout upsell (#6963 — addon 0.5.1 → 0.5.2; the v0.5.2 ZIP is
  tag-pending so `nvoos-docs-hub-v0.5.1.zip` stays until the new build
  lands), the skill-catalogue gap fixes (#6954 — notes pre-staged into
  the 1.1.99 section in-window), the Elementor autoload fix (#6961),
  content-graph 1.0.9 + checkout polish (#6959/#6958 — sub-project,
  flag-not-edited), three new coding-time skills in-window (spa-ui,
  toolkit-creation, checkout-integration — 63 → 66 with the count
  bookkeeping landing with the introducing PRs), two in-window skill
  updates (#6960 wporg-submission + test-suite 67 → 68 patterns),
  two new OI-1 groups (49: `@since 1.2.0` ×1 in mcp-server-info.php;
  50: `@since 1.6.0` ×63 across the new food-beverage folder) with
  convention notes (docs-hub 0.5.2 ×39 addon-version correct; CG 1.0.9
  sub-project correct; skill-catalogue 1.11.0 ×6 file family; doc-gen
  2.10.0 ×29 folder's 2.x family), OI-2 figure moved to ~353/~1,339/~1,692,
  OI-8 extended (+27 new slugs) and OI-10 extended (32nd toolkit), the
  stale 1.1.98 build-set removal (30 files — 9 + 2 + 19, no
  ollama-demo.json carry — already at 1.1.99), tool count +1 base
  +26 Pro, and the #6955/#6962/#6963 skill-count surfaces verified
  66 in-window)

Preceding windows:
  `docs/project/plans/v1.1.99-docs-catch-up.md` (the v1.1.99
  pass over PRs #6936 + #6938–#6952: the SPA UI stack enhancement (#6952,
  Proposal 060 — toolkit-shell 0.2.0 → 0.3.0 + schedule-anything-spa →
  0.2.0 with shipped vitest suites; the addon partial-bump rule fired on
  the three toolkit-shell test files still pinning 0.2.0 — fixed in-pass),
  the MCP Gateway OAuth 2.1 resource server (#6945, Phase 1 — RFC 9728
  metadata + challenges, zero-dep JWT, scope site-binding, no passthrough;
  Phases 2–5 deferred; gateway stays 0.1.1 with an Unreleased changelog
  section), the Docs Hub Playground demo + wp.org Live Preview
  (#6944/#6946–#6949), the gateway listing prep + icon + Claude Code
  plugin (#6941–#6943), the Figma skills catalogue (#6938 — the
  agent-skills.md catalogue-section fold-in owed), the Gmail body fixes
  (#6939), the Complete-ZIP blueprint stripping (#6936/#6940 — #6936
  merged 14 s before the #6935 catch-up and joined this window per the
  #6787 rule), the Fleet Operator token masking (#6950), the #6941
  deferral executed in-pass (.env.example `STATUS_EXTERNAL_TARGETS` +
  `.context/media-worker.md` external-targets notes), four skill
  reconciliations (test-suite description 66 → 67, playground-demos
  description + docs-hub demo, plugin ×3 + RELEASE-NOTES v1.1.99 section
  with the bundled-base sync, the gateway addon skill's OAuth section),
  the proposals-README 060 entry, the stale 1.1.97 build-set removal
  (6 root files) + the superseded nvoos-toolkit-shell-v0.2.0.zip (the
  v0.3.0 ZIP lands via the in-flight build-spa-addons run), zero
  tool-count change, and **no new OI-1 group** (the window's `@since
  0.3.0` ×12 are the toolkit-shell addon convention; #6937's post-window
  1.1.98/1.1.0 tags were already reconciled))

Preceding windows:
  `docs/project/plans/v1.1.98-docs-catch-up.md` (the v1.1.98
  pass over PRs #6927–#6934: the Figma-to-Elementor design-to-build pipeline
  (#6934, Proposals 057–059 — docs + the new `design-figma-to-elementor`
  skill, coding-time 62 → 63, base bundled 75 → 76, the README repo-map
  fold-in owed — A5's "the introducing PR may skip the count bookkeeping"
  rule fired again: AGENTS.md/copilot-instructions/agent-skills.md were
  updated in-window but the README row was missed; Phase 3 demo/verify
  deferred on live endpoints), the Unified Blueprints release-build + gating
  fixes (#6933 — root-anchored `examples` exclusions, toolkit-enablement
  gating), the gateway sync dispatch + changelog (#6927), the dependabot
  sweep (#6931 — 138/166 alerts via bounded in-major bumps, 28 deferred in
  #6930, dashboard dismissed same-day), three skill reconciliations (plugin
  ×3 + RELEASE-NOTES with the bundled-base sync of #6932's cross-reference,
  ai-assistant-admin bundled ← Zed — the bundled copy was still the
  pre-v1.1.79 rewrite, updates, dependabot-loop reference), the
  proposals-README 057/058/059 entries, the stale 1.1.96 build-set removal
  (30 files — 9 + 2 + 19) with the ollama-demo.json URL carry, zero
  tool-count change, and **no new OI-1 group** (zero added `@since` tags
  in-window))

Preceding windows:
  `docs/project/plans/v1.1.97-docs-catch-up.md` (the v1.1.97
  pass over PRs #6898–#6914: the ECC-inspired agent-harness enhancements
  (#6912, Proposal 056 — cascade routing on both engines via the lib/core
  `CascadeRouter` + the legacy `WP_MCP_AI_Cascade_Executor` gate with Pro Jev
  classifier wiring, the +2 base tools `run_assistant_eval` + `scan_assistant_security`,
  hook profiles, the session distiller's canonical `wp_mcp_ai_memory_stored`
  event, the +1 Pro `suggest_workflows_from_history` miner — all inert by
  default, CREDITS attribution), the OOS parity-gap closure (#6913 — 428/429
  gate envelopes via `translate_gate_exception()`, `apply_pre_response_render()`
  on all three surfaces, the agentic-iteration bridge, the parity script
  35 → 38 features + the `parity-check` CI job), the gateway protocol
  negotiation (#6914), the 23-package public npm publish wave (#6898/#6904–#6909/#6911
  — the v1.1.96-deferred publish executed), tool counts +2 base +1 Pro →
  ~352/~1,313/~1,665, four skill reconciliations (plugin ×3 + RELEASE-NOTES,
  test-suite 65 → 66 patterns with the parity-check gate, updates, the
  workflow-builder cross-reference), the proposals-README 056 entry, the
  stale 1.1.95 build-set removal (30 files — 9 + 2 + 19) with the
  ollama-demo.json URL carry, and **no new OI-1 group** (all 30 in-window
  `@since` tags correctly pre-tagged 1.1.97). **Post-window:** #6925 (branched off this catch-up and squash-merged — PR #6924 closed as superseded) added the non-force gateway mirror sync + bumped the gateway addon 0.1.0 → 0.1.1; the 0.1.1 pins were reconciled into the current-release surfaces by the `chore/1.1.97-post-gateway-bump-docs` follow-up — when a squash of a catch-up branch lands with an addon bump of its own, sweep the just-written version pins before closing the superseded PR)

Preceding windows:
  `docs/project/plans/v1.1.96-docs-catch-up.md` (the v1.1.96
  pass over PRs #6895–#6897: the `@nvdigitalsolutions/nvoos-mcp-bridge` npx
  package + Fleet Operator editor config generators + the `addons/mcp-gateway/`
  public fleet MCP endpoint (Proposals 054/055, addon 0.1.0 → inventory #31,
  addon count 29 → 30, the npm-publish/mirror/Velocity/directory deferrals
  noted), the per-request memory cut + bootstrap hardening (#6896 — the lazy
  tool registry with third-party-wins slug conflicts, the gated OOS pre-warm,
  the catalog-migration `filemtime` short-circuit, the `autoload=false` Pro
  blob options + one-time repair, the bootstrap-integrity guard + fail-soft
  loader, the updater `VERIFY_FILES` +17, the new Site Health
  `wp_mcp_ai_file_integrity` test, 9 dead FF/Yahoo registry entries removed,
  the `Inline_Async_Tick_Trait` double-declaration fatal fixed — 42 MB vs
  74 MB A/B), the worker runtime-version completion (#6895), a new OI-1 group
  (48: `@since 1.2.0` ×1 in the registry's `wp_mcp_ai_tools_init` docblock),
  three skill reconciliations (plugin ×3 + RELEASE-NOTES with the npx-bridge
  transport pointers, test-suite 64 → 65 patterns, updates), the
  FOR_REVIEWERS addon-count 29 → 30 + Fleet Operator 1.0.0 + MCP Gateway rows,
  the ADDON_INVENTORY Fleet Operator row correction (0.1.0 → 1.0.0) + new
  MCP Gateway row #31, the stale 1.1.94 build-set removal (30 files — 9 + 2
  + 19), and zero tool-count change (no registrations))

Preceding windows:
  `docs/project/plans/v1.1.95-docs-catch-up.md` (the v1.1.95
  pass over PRs #6874–#6893:the nine-toolkit hardening wave (#6875/#6876/
  #6882/#6883/#6885/#6886/#6887/#6889/#6892/#6893 — the per-toolkit audit loop
  under the new `mcp-ai-wpoos-toolkit-audit` skill, 62 skills; email-marketing
  verified clean #6891; confirmed fatals: unguarded `shell_exec()`, the
  nonexistent Gemini `generate_content()` + global `wp_mcp_ai_chat_completion()`,
  array-content `preg_match()`/`trim()` fatals, the media `year_month` traversal
  closed, the never-loadable loose incident tools), the real image processing
  for the four placeholder tools (#6881, issue #6877 — Media Worker 3.3.0 →
  3.4.0, the `/api/image/enhance|upscale|edit` routes + the two new Pro traits),
  the `list_mcp_tools` toolkit labels + Newsletter bootstrap fix (#6874 — the
  pre-staged [Unreleased] CHANGELOG converted), the inter-step SSE keepalives
  (#6879 + the Phase 4b offload proposal), the fixture swaps (#6886/#6890),
  a new OI-1 group (47: `@since 1.9.5` ×1 in the keepalive test — plus
  convention notes for the 1.4.0/2.10.0/2.7.0/2.1.0/1.5.0/1.1.55 additions),
  the OI-9 extension (the #6883 port-wide unguarded-resolver sweep queue),
  two skill reconciliations (test-suite description 63 → 64 patterns, the
  README repo-map 61 → 62 fold-in), the FOR_REVIEWERS addon-count 27 → 29 +
  Media Worker 3.4.0 corrections, the proposals-README SSE entry, the
  ADDON_INVENTORY Media Worker row, the stale 1.1.93 build-set removal
  (30 files — 9 + 2 + 19), and zero tool-count change (no registrations);
  post-window #6895 fixed the worker's three missed runtime version strings
  (the partial-bump miss now codified as an A2 scope rule))

Preceding windows:
  `docs/project/plans/v1.1.94-docs-catch-up.md` (the v1.1.94
  pass over PRs #6865–#6872: the ChatGPT plugin addon + OAuth 2.1
  resource-server contract (#6871 — `addons/chatgpt-plugin/` 0.1.0,
  `WP_MCP_AI_OAuth_Resource_Server` + the two `/.well-known/` endpoints, 401
  `WWW-Authenticate` on the MCP route, per-tool `securitySchemes`,
  `nvoos_get_profile` +1 base, RFC-9728 token acceptance, proposals 053,
  inventory #30), the Decision-Scope Guard (#6866, Proposal 052 — domain +
  authority ceilings, banned domains fail closed, the
  `WPMCPAI.Decisions.ScopeDeclared` severity-5 sniff), the toolkit MCP grant
  runtime enforcement + tool exposure + OOS chat parity (#6872 — the
  pre-staged [Unreleased] CHANGELOG converted), the FlowHub MCP OAuth login
  proxy carry (#6867), the safe-mode REST error masking admin opt-in +
  correlation `ref` (#6869, closes #6860), the Site Health fatal/false-positive
  fixes + Docker hardening (#6870), the markdown-it bounded-floor bump
  (#6868), the test-drift fixes (#6865), a new OI-1 group (46: `@since 1.4.0`
  ×1 in `assistant-cpt.php` — the mcp-servers folder's module tags are the
  convention, group-23 extension: `@since 2026.10` ×6 in the
  decision-scope-guard), the in-window readme.txt/mcp-ai-wpoos.php provider
  header 15 → 18 correction (v1.1.93-pass missed spot), four skill
  reconciliations (plugin ×3 + RELEASE-NOTES, elementor ×2,
  test-suite patterns 61–63, dependabot-loop bounded-floor case, updates),
  the in-window ADDON_INVENTORY #30 entry + header refresh, the proposals
  README 052 move + 053 addition, the stale 1.1.92 build-set removal
  (30 files — 9 + 2 + 19), and the OI-8 extension (`nvoos_get_profile` missing
  from tool-status.txt))

Preceding windows:
  `docs/project/plans/v1.1.93-docs-catch-up.md` (the v1.1.93
  pass over PRs #6849–#6863: the media-worker fleet status monitoring (#6849 —
  worker 3.2.0 → 3.3.0, the opt-in STATUS_ENABLED status module, +2 base tools
  `get_fleet_status`/`get_site_uptime`, `GET /mcp-ai/v1/status/sites`, the Pro
  Status Dashboard fleet section), the WP-CLI
  parity & hardening (#6852, Proposal 050 — dispatcher extraction, `tool
  call`, 11 new commands, `manage_options` gating, `docs/operations/wp-cli.md`),
  the FlowHub MCP Apps surfacing + proxy carry (#6854/#6861), the Financial
  Planner OpenStock parity (#6857, Proposal 051 — Finnhub provider, +3 Pro
  tools incl. the orphaned blueprint registration), the October 2026 Track B
  model-catalog refresh (#6863 — v2026.10.03, 238 models, **18 providers —
  Z.AI joins**, provider-count line 15 → 18 with the FOR_REVIEWERS
  provider-list fix), the Media Studio 0.6.1 `/ai/generate` fix
  (#6853/#6858), the schedule create/save trigger fixes (#6855/#6856), the
  validated-tool `auto` aspect-ratio fix (#6859), the undici 7.30.0 advisory
  (#6850), the preset/manifest repairs (#6851), the in-window dependabot-skill
  edit (#6862), four new OI-1 groups (42: `@since 1.1.90` financial wave ×62;
  43: `@since 1.3.0` Pro CLI ×25; 44: `@since 1.2.0` base CLI + tests ×11; 45:
  `@since 1.1.92` remote-site-manager/mcp-app-registry ×6 one-behind), six
  skill reconciliations (plugin ×3 + RELEASE-NOTES, elementor ×2,
  schedule-manager ×2 + drift repair, analytics-reporting/media-workflow/
  product-research CLI canonicalization ×2 each, test-suite patterns 58–60,
  updates), the in-window tool-reference partial count edit reconciled (+3
  Pro), the ADDON_INVENTORY Media Worker 3.2.0 → 3.3.0 row correction, the
  stale 1.1.91 build-set removal (6 files) + superseded media-studio and
  saas-controller addon ZIPs (5 files), and the OI-8 extension (5 new slugs
  missing from tool-status.txt)),
  `docs/project/plans/v1.1.92-docs-catch-up.md` (the v1.1.92
  pass over PRs #6839–#6847: the Media Studio fashion production suite
  (#6839/#6844 — the squash-merged chain; addon 0.1.0 → 0.6.0, 8 `fashion_*`
  Pro tools self-gated on the Media Studio AI service, the 10th Workflow
  Builder preset category, +8 Pro tool count), the inline vision data URLs +
  payload optimization (#6846, pre-staged [Unreleased] CHANGELOG block
  converted), the provider content/credential fixes (#6845), the Pro coverage
  manifest regeneration (#6847), four new OI-1 groups (38–41), five skill
  reconciliations, the stale 1.1.90 build-set removal (30 files), and the OI-8
  extension),
  `docs/project/plans/v1.1.89-docs-catch-up.md` (the v1.1.89
  pass over PRs #6802 + #6804–#6810: the Google Classroom ECA integration
  (proposal 046, 12 flag-gated Pro tools + base foundation + new webhook
  route), the Design System addon rename + token-driven email module
  (proposal 047/048, 8 addon-provided nds tools, 0.1.0 → 0.3.0), the Upwork
  MCP connection mode (#6804), the vision/remote/EZuite live-site fixes
  (#6805), the MCP legacy-dialect handshake cache (#6802), the CodeQL closure
  + dependency advisory patches (#6807/#6806), the coverage-manifest repair
  (#6808), three skill reconciliations (design-elementor-mcp-connection
  bundled sync + stamps, design-email-marketing bundled sync, design-crm MCP
  mode), the in-window ADDON_INVENTORY version correction (0.2.0 → 0.3.0),
  the stale 1.1.87 build-set removal (30 files), and +12 Pro tool-count)
