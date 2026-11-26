// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// deploy-config.ts — the generated deploy pipeline (Proposal 096 Phase 3.3).
//
// Emits into a forged project:
//
//   .github/workflows/deploy.yml   build (+ dispatch verification) + deploy,
//                                  one job; triggered by push, manual runs,
//                                  workflow_call, and the rebuild wrapper
//   .github/workflows/rebuild.yml  the bridge-plugin trigger surface —
//                                  repository_dispatch only, delegates to
//                                  deploy.yml
//   scripts/verify-dispatch.mjs    fail-closed signature verification: the
//                                  bridge plugin signs `client_payload` with
//                                  HMAC-SHA256 over "<time>.<nonce>" (the
//                                  X-Forge-Signature header cannot survive
//                                  GitHub's dispatch delivery, so the payload
//                                  carries its own proof). Stale (> 10 min)
//                                  or mismatched dispatches never build.
//
// Deploy modes are adapter-generic: the same file set with different deploy
// steps — GitHub Pages, Cloudflare Pages, or a Velocity connected-repo
// mirror (the 092 sync pattern, parameterized per site).
//
// The generator only ever sees configuration values — no secrets — so the
// emitted files cannot leak credentials by construction. Secrets referenced
// (FORGE_WEBHOOK_SECRET, CLOUDFLARE_*, VELOCITY_*) are operator-set in the
// target repo.
// ---------------------------------------------------------------------------

import { mkdir, writeFile } from 'node:fs/promises';
import { join, relative } from 'node:path';

/** Supported deploy targets. */
export type DeployMode = 'github-pages' | 'cloudflare-pages' | 'velocity';

/**
 * Supported deploy runtimes (Proposal 135).
 *
 *  - `static`  — prebuilt flat files; Velocity serves them with a no-op build
 *                (the historical shape — unchanged output).
 *  - `managed` — the same branch plus a Node entry (`server.js`); the site owns
 *                `/api/*`, `/manage`, `/mcp` and `/api/revalidate` behind an
 *                Astro `src/fetch.ts` BFF.
 */
export type DeployRuntime = 'static' | 'managed';

/** The runtime values the CLI and the emitter accept. */
export const DEPLOY_RUNTIMES: readonly DeployRuntime[] = [ 'static', 'managed' ];

/** Reject an unknown runtime before anything is written. */
export function assertRuntime( runtime: unknown ): asserts runtime is DeployRuntime | undefined {
	if ( runtime === undefined ) {
		return;
	}
	if ( typeof runtime !== 'string' || ! DEPLOY_RUNTIMES.includes( runtime as DeployRuntime ) ) {
		throw new Error( `Invalid deploy runtime "${ String( runtime ) }" — use static or managed.` );
	}
}

/**
 * The slice of a site profile the runtime requirement depends on.
 *
 * Structural rather than the full `ForgeProfile`, so tests can exercise the
 * gate without building a complete profile.
 */
export interface RuntimeProfile {
	deploy?: { runtime?: unknown; manage?: boolean };
	forms?: { delivery?: string; fallback?: string };
}

/** A derived runtime plus the reason, so refusals can name the feature. */
export interface RuntimeRequirement {
	runtime: DeployRuntime;
	reason: string;
}

/**
 * The runtime a profile's own configuration requires (Proposal 135).
 *
 * Derived, never declared: a site that names a managed-only feature must not be
 * emitted as a static tree. `scaffold.ts` enforced the `direct` form case
 * inline; this is the single source of truth so the emitter, the CLI and the
 * generated workflow cannot disagree.
 *
 * Precedence is most-restrictive first:
 * 1. `deploy.runtime: "managed"` — explicit.
 * 2. `deploy.manage: true` — `/manage` is a server-rendered surface.
 * 3. `forms.delivery: "direct"` — the provider key lives on the server.
 * 4. `forms.fallback: "direct"` — re-delivery needs the same runtime.
 *
 * Everything else (including `forms.delivery: "bridge"`, which POSTs from the
 * browser to WordPress, and `eca.booking.mode: "external"`) is static-safe.
 *
 * @param profile Site profile (or any object with the relevant keys).
 * @return The required runtime and a human-readable reason.
 */
export function requiredRuntime( profile: RuntimeProfile ): RuntimeRequirement {
	assertRuntime( profile.deploy?.runtime );

	if ( profile.deploy?.runtime === 'managed' ) {
		return { runtime: 'managed', reason: 'deploy.runtime is "managed"' };
	}

	if ( profile.deploy?.manage === true ) {
		return {
			runtime: 'managed',
			reason: 'deploy.manage serves /manage from the managed runtime (a static tree has no server routes)',
		};
	}

	if ( profile.forms?.delivery === 'direct' ) {
		return {
			runtime: 'managed',
			reason: 'forms.delivery "direct" sends from the managed runtime (the provider key never reaches the client)',
		};
	}

	if ( profile.forms?.fallback === 'direct' ) {
		return {
			runtime: 'managed',
			reason: 'forms.fallback "direct" re-delivers from the managed runtime',
		};
	}

	return { runtime: 'static', reason: 'no managed-only feature is configured' };
}

/**
 * Resolve the runtime to emit, refusing a static tree for a managed profile.
 *
 * `managed` is a superset of `static`, so an explicit `managed` is always
 * allowed. The reverse is refused: a static artifact published for a managed
 * Velocity app boots nothing, which is the failure this gate exists to prevent.
 *
 * @param profile   Site profile.
 * @param requested Runtime from the CLI/emitter (defaults to the requirement).
 * @return The runtime to emit.
 * @throws When the profile requires `managed` and static was requested.
 */
export function resolveRuntime( profile: RuntimeProfile, requested?: unknown ): DeployRuntime {
	assertRuntime( requested );

	const required = requiredRuntime( profile );

	if ( required.runtime === 'managed' && requested !== 'managed' ) {
		throw new Error(
			`This profile requires the managed runtime: ${ required.reason }. ` +
				`Refusing to emit "${ String( requested ?? 'static' ) }". Re-run with --runtime managed.`
		);
	}

	return requested === 'managed' ? 'managed' : required.runtime;
}

/**
 * The canonical public origin the build bakes into `ASTRO_SITE` (Proposal 135).
 *
 * Precedence: explicit override → `profile.deploy.publicOrigin` → `profile.url`.
 * `profile.url` is the WordPress/CMS origin, which equals the public origin
 * only for single-origin installs; the deploy block separates them.
 */
export function resolveSiteUrl(
	profile: { url?: string; deploy?: { publicOrigin?: string } },
	override?: string
): string {
	const candidate = override ?? profile.deploy?.publicOrigin ?? profile.url ?? '';
	return String( candidate ).replace( /\/+$/, '' );
}

/** Default dispatch event type — the bridge plugin's DEFAULT_EVENT. */
export const DEFAULT_EVENT_TYPE = 'nvoos-forge-content-changed';

/** Dispatch freshness window (seconds) — matches the bridge's 10-min debounce. */
export const DEFAULT_REFRESH_WINDOW_SECONDS = 600;

export interface DeployConfig {
	/** Deploy target. */
	mode: DeployMode;
	/** Canonical site URL (baked into ASTRO_SITE). Optional — the repo's Pages
	 *  URL is used as the default fallback by the template itself. */
	siteUrl?: string;
	/** Cloudflare Pages project name (cloudflare-pages mode). */
	cloudflareProject?: string;
	/** Shared mirror repo (owner/repo) — branch-per-site velocity deploys.
	 *  Set together with `siteBranch`: one mirror repo hosts every client,
	 *  one branch per site, dist/ at the branch ROOT (Velocity app:
	 *  Branch = site/<name>, Root directory = repo root). */
	mirrorRepo?: string;
	/** The per-site branch on the shared mirror (e.g. `site/jayainc`). */
	siteBranch?: string;
	/** repository_dispatch event type to accept. */
	eventType?: string;
	/** Max age of a dispatch before it is refused (seconds). */
	refreshWindowSeconds?: number;
	/**
	 * Runtime shape of the mirrored branch (Proposal 135). Default `static`.
	 * `managed` emits a real start command and fails closed when the deploy tree
	 * has no `server.js` (a managed branch that cannot boot is never pushed).
	 */
	runtime?: DeployRuntime;
}

/** The mode-specific deploy steps appended to the shared build job. */
function deploySteps( config: DeployConfig ): string[] {
	if ( config.mode === 'github-pages' ) {
		return [
			'      - name: Configure Pages',
			'        uses: actions/configure-pages@v5',
			'',
			'      - name: Upload Pages artifact',
			'        uses: actions/upload-pages-artifact@v3',
			'        with:',
			'          path: dist',
			'',
			'      - name: Deploy to GitHub Pages',
			'        id: deployment',
			'        uses: actions/deploy-pages@v4',
			...deployedCheckStep( config ),
		];
	}
	if ( config.mode === 'cloudflare-pages' ) {
		const project = config.cloudflareProject ?? 'FORGE_PROJECT_NAME';
		return [
			'      - name: Publish to Cloudflare Pages',
			'        uses: cloudflare/wrangler-action@v3',
			'        with:',
			'          apiToken: ${{ secrets.CLOUDFLARE_API_TOKEN }}',
			'          accountId: ${{ secrets.CLOUDFLARE_ACCOUNT_ID }}',
			`          command: pages deploy dist --project-name=${ project }`,
			...deployedCheckStep( config ),
		];
	}
	// velocity — two shapes:
	//  - shared mirror (mirrorRepo + siteBranch): dist/ is force-pushed to the
	//    site's own branch at the branch ROOT — one branch per client site,
	//    each Velocity app selects its branch (Root directory = repo root).
	//    The heavy lifting lives in scripts/mirror-push.mjs (emitted alongside
	//    the workflows) so the CLI can run the exact same push locally.
	//  - per-site connected repo (legacy): dist/ lands on the `deploy` branch
	//    of the mirror repo under `site/` (the 092 sync pattern, per-client).
	if ( config.mirrorRepo && config.siteBranch ) {
		const steps = [
			'      - name: Mirror dist to the shared site branch',
			'        env:',
			'          MIRROR_URL: ${{ secrets.VELOCITY_MIRROR_URL }}',
			'          MIRROR_TOKEN: ${{ secrets.VELOCITY_MIRROR_TOKEN }}',
			'          FORGE_BUILD_ID: ${{ github.sha }}-${{ github.run_id }}',
			`          SITE_BRANCH: ${ config.siteBranch }`,
			`          RUNTIME: ${ config.runtime ?? 'static' }`,
		];
		if ( config.runtime === 'managed' ) {
			// Proposal 135: the managed branch carries the BFF deploy tree
			// (dist/ + server.js), assembled before the push.
			return [
				...steps,
				'          SOURCE_DIR: .deploy',
				'        run: |',
				'          node scripts/sync-deploy-tree.mjs',
				'          node scripts/mirror-push.mjs',
				...deployedCheckStep( config ),
			];
		}
		return [ ...steps, '        run: node scripts/mirror-push.mjs', ...deployedCheckStep( config ) ];
	}
	return [
		'      - name: Mirror dist to the connected repo',
		'        env:',
		'          MIRROR_URL: ${{ secrets.VELOCITY_MIRROR_URL }}',
		'          MIRROR_TOKEN: ${{ secrets.VELOCITY_MIRROR_TOKEN }}',
		'        run: |',
		'          set -euo pipefail',
		'          if [ -z "${MIRROR_URL}" ] || [ -z "${MIRROR_TOKEN}" ]; then',
		'            echo "VELOCITY_MIRROR_URL / VELOCITY_MIRROR_TOKEN are not set — skipping mirror deploy."',
		'            exit 0',
		'          fi',
		'          AUTHED_URL=$(node -e "const u = new URL(process.env.MIRROR_URL); u.username = \'x-access-token\'; u.password = process.env.MIRROR_TOKEN; process.stdout.write(u.href)")',
		'          git clone --depth 1 --branch deploy "${AUTHED_URL}" mirror-tmp',
		'          rm -rf mirror-tmp/site',
		'          mkdir -p mirror-tmp/site',
		'          cp -r dist/. mirror-tmp/site/',
		'          cd mirror-tmp',
		'          git config user.name "nvoos-forge"',
		'          git config user.email "forge@nvdigitalsolutions.com"',
		'          git add -A',
		'          git commit -m "Forge deploy: ${GITHUB_SHA}" --allow-empty || true',
		'          git push origin deploy',
		...deployedCheckStep( config ),
	];
}

/** The shared build job — verification runs only for dispatch events. */
function buildJob( config: DeployConfig ): string {
	const eventType = config.eventType ?? DEFAULT_EVENT_TYPE;
	const windowSeconds = config.refreshWindowSeconds ?? DEFAULT_REFRESH_WINDOW_SECONDS;
	const siteEnv = config.siteUrl ? `          ASTRO_SITE: ${ config.siteUrl }` : '';
	const lines = [
		'  build-and-deploy:',
		'    runs-on: ubuntu-latest',
		'    permissions:',
		'      contents: read',
		'      pages: write',
		'      id-token: write',
		'    steps:',
		'      - name: Checkout',
		'        uses: actions/checkout@v4',
		'',
		'      - name: Setup Node',
		'        uses: actions/setup-node@v4',
		'        with:',
		'          node-version: 24',
		'',
		'      - name: Install dependencies',
		'        run: npm ci',
		'',
		'      - name: Verify dispatch signature',
		'        if: github.event_name == \'repository_dispatch\'',
		'        env:',
		'          FORGE_WEBHOOK_SECRET: ${{ secrets.FORGE_WEBHOOK_SECRET }}',
		'          DISPATCH_PAYLOAD: ${{ toJSON(github.event.client_payload) }}',
		`        run: node scripts/verify-dispatch.mjs --event-type ${ eventType } --max-age ${ windowSeconds }`,
		'',
		'      - name: Build',
		'        run: npm run build',
		'        env:',
		'          FORGE_BUILD_ID: ${{ github.sha }}-${{ github.run_id }}',
	];
	if ( siteEnv ) {
		lines.push( siteEnv );
	} else {
		lines.push( '          ASTRO_SITE: ${{ vars.FORGE_SITE_URL }}' );
	}
	lines.push( '', ...deploySteps( config ) );
	return lines.join( '\n' );
}

/** deploy.yml — the whole pipeline (push/manual/call/dispatch via wrapper). */
function deployWorkflow( config: DeployConfig ): string {
	return [
		'# Generated by NV oOS Astro Forge — deploy pipeline (Phase 3.3).',
		'# Builds the static site and ships it to the configured target. The',
		'# repository_dispatch path (via rebuild.yml) additionally verifies the',
		'# bridge-plugin HMAC signature before anything builds.',
		'',
		'name: Forge Deploy',
		'',
		'on:',
		'  push:',
		'    branches: [main]',
		'  workflow_dispatch:',
		'  workflow_call:',
		'',
		'jobs:',
		buildJob( config ),
		'',
	].join( '\n' );
}

/** rebuild.yml — the thin repository_dispatch wrapper (bridge → deploy). */
function rebuildWorkflow( config: DeployConfig ): string {
	const eventType = config.eventType ?? DEFAULT_EVENT_TYPE;
	return [
		'# Generated by NV oOS Astro Forge — the bridge-plugin trigger surface.',
		'# The nvoos-forge-bridge plugin fires this repository_dispatch event on',
		'# content changes; deploy.yml verifies the signature and builds.',
		'',
		'name: Forge Rebuild',
		'',
		'on:',
		'  repository_dispatch:',
		`    types: [${ eventType }]`,
		'',
		'jobs:',
		'  rebuild:',
		'    permissions:',
		'      contents: read',
		'      pages: write',
		'      id-token: write',
		'    uses: ./.github/workflows/deploy.yml',
		'    secrets: inherit',
		'',
	].join( '\n' );
}

/**
 * The standalone verifier emitted into every generated project. Reads the
 * dispatch payload from DISPATCH_PAYLOAD, recomputes HMAC-SHA256 over
 * "<time>.<nonce>" with FORGE_WEBHOOK_SECRET, and refuses stale or
 * mismatched dispatches. No dependencies — plain node.
 */
function verifyScript(): string {
	return [
		'#!/usr/bin/env node',
		'// verify-dispatch.mjs — fail-closed dispatch verification (Forge Phase 3.3).',
		'// Contract with nvoos-forge-bridge: client_payload carries time (unix',
		'// seconds), nonce, and signature = HMAC-SHA256(secret, "<time>.<nonce>").',
		'// Anything stale (> max-age) or mismatched exits non-zero: no build.',
		'',
		"import { createHmac, timingSafeEqual } from 'node:crypto';",
		'',
		'function arg( name, fallback ) {',
		"\tconst i = process.argv.indexOf( name );",
		'\treturn i !== -1 && process.argv[ i + 1 ] !== undefined ? process.argv[ i + 1 ] : fallback;',
		'}',
		'',
		'const eventType = arg( "--event-type", "nvoos-forge-content-changed" );',
		'const maxAge = Number( arg( "--max-age", "600" ) );',
		'const secret = process.env.FORGE_WEBHOOK_SECRET ?? "";',
		'',
		'function fail( message ) {',
		'\tconsole.error( `verify-dispatch: ${ message }` );',
		'\tprocess.exit( 1 );',
		'}',
		'',
		'let payload;',
		'try {',
		'\tpayload = JSON.parse( process.env.DISPATCH_PAYLOAD ?? "{}" );',
		'} catch {',
		'\tfail( "DISPATCH_PAYLOAD is not valid JSON — refusing to build." );',
		'}',
		'',
		'const { time, nonce, signature, type } = payload ?? {};',
		'if ( type !== eventType ) {',
		'\tfail( `unexpected event type "${ type }" (wanted ${ eventType }) — refusing to build.` );',
		'}',
		'if ( ! secret ) {',
		'\tfail( "FORGE_WEBHOOK_SECRET is not set — refusing to build." );',
		'}',
		'if ( ! time || ! nonce || ! signature ) {',
		'\tfail( "payload is missing time/nonce/signature — refusing to build." );',
		'}',
		'const age = Math.abs( Date.now() / 1000 - Number( time ) );',
		'if ( ! Number.isFinite( age ) || age > maxAge ) {',
		'\tfail( `dispatch is ${ Math.round( age ) }s old (max ${ maxAge }s) — refusing to build.` );',
		'}',
		'const expected = createHmac( "sha256", secret ).update( `${ time }.${ nonce }` ).digest( "hex" );',
		'const a = Buffer.from( expected, "hex" );',
		'const b = Buffer.from( String( signature ), "hex" );',
		'if ( a.length === 0 || a.length !== b.length || ! timingSafeEqual( a, b ) ) {',
		'\tfail( "signature mismatch — refusing to build." );',
		'}',
		'console.log( "verify-dispatch: signature verified." );',
		'',
	].join( '\n' );
}

/**
 * The mirror-push runner emitted next to the workflows in shared-mirror
 * mode. Plain node, no dependencies: clones `main` of the mirror, checks out
 * an ORPHAN site branch (flat single-commit history), replaces the content
 * with the build output, and force-pushes. Unset MIRROR_URL/MIRROR_TOKEN
 * REFUSES unless FORGE_ALLOW_MIRROR_SKIP=1 is set: a hand deploy that silently
 * skips looks exactly like a successful publish. The CLI runs the same emitted
 * file for the operator staging path, so workflow and CLI cannot drift.
 */
function mirrorPushScript( runtime: DeployRuntime = 'static' ): string {
	return [
		'#!/usr/bin/env node',
		'// mirror-push.mjs — force-push a built dist/ to a per-site branch of the',
		'// shared forge mirror repo (velocity shared-mirror mode). One branch per',
		'// client site; each Velocity app connects to the mirror repo and selects',
		'// its branch (Root directory = repo root). Flat history: every push',
		'// replaces the branch content.',
		'//',
		'// Contract: MIRROR_URL (https URL of the mirror repo), MIRROR_TOKEN',
		'// (repo-scoped deploy token), SITE_BRANCH (e.g. site/jayainc), and',
		'// optionally SOURCE_DIR (default: ./dist). Unset URL/token REFUSES unless',
		'// FORGE_ALLOW_MIRROR_SKIP=1 — on a hand deploy a silent skip is',
		'// indistinguishable from a successful publish.',
		'',
		"import { execFileSync } from 'node:child_process';",
		"import { cpSync, existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';",
		"import { tmpdir } from 'node:os';",
		"import { join, resolve } from 'node:path';",
		'',
		'function git( args, cwd ) {',
		"	return execFileSync( 'git', args, { cwd, stdio: [ 'ignore', 'pipe', 'pipe' ] } ).toString();",
		'}',
		'',
		"const url = process.env.MIRROR_URL ?? '';",
		"const token = process.env.MIRROR_TOKEN ?? '';",
		"const branch = process.env.SITE_BRANCH ?? '';",
		"const source = resolve( process.cwd(), process.env.SOURCE_DIR ?? 'dist' );",
		"const runtime = process.env.RUNTIME ?? '" + runtime + "';",
		"const managed = runtime === 'managed';",
		'',
		"if ( runtime !== 'static' && runtime !== 'managed' ) {",
		"\tconsole.error( 'mirror-push: invalid RUNTIME ' + runtime + ' — use static or managed.' );",
		'\tprocess.exit( 1 );',
		'}',
		'',
		'if ( ! url || ! token ) {',
		"	if ( process.env.FORGE_ALLOW_MIRROR_SKIP === '1' ) {",
		"		console.log( 'mirror-push: MIRROR_URL / MIRROR_TOKEN are not set — skipping (FORGE_ALLOW_MIRROR_SKIP=1).' );",
		'		process.exit( 0 );',
		'	}',
		"	console.error( 'mirror-push: MIRROR_URL / MIRROR_TOKEN are not set — refusing to publish. Set them, or pass FORGE_ALLOW_MIRROR_SKIP=1 to skip deliberately.' );",
		'	process.exit( 1 );',
		'}',
		'if ( ! branch ) {',
		"	console.error( 'mirror-push: SITE_BRANCH is not set — refusing to push.' );",
		'	process.exit( 1 );',
		'}',
		'if ( ! existsSync( source ) ) {',
		"\tconsole.error( 'mirror-push: ' + source + ' does not exist — run the build first.' );",
		'\tprocess.exit( 1 );',
		'}',
		'',
		"if ( managed && ! existsSync( join( source, 'server.js' ) ) ) {",
		"\tconsole.error( 'mirror-push: runtime=managed but ' + source + '/server.js is missing.' );",
		"\tconsole.error( '  A managed site needs the BFF deploy tree (Proposal 135 Phase 2).' );",
		"\tconsole.error( '  Build it with SOURCE_DIR=.deploy (scripts/sync-deploy-tree.mjs), or' );",
		"\tconsole.error( '  set deploy.runtime=static in the site profile.' );",
		'\tprocess.exit( 1 );',
		'}',
		'',
		'// Fail fast, BEFORE the clone: a managed tree without a dependency manifest',
		'// cannot boot on Velocity. `npm install` would install nothing, `node',
		'// server.js` would throw ERR_MODULE_NOT_FOUND, the release would never',
		'// promote, and the app would keep serving an older build while CI stayed',
		'// green (incident 2026-11-10 — Virtually Jamaica a day behind).',
		'if ( managed ) {',
		"\tconst sourcePkgPath = join( source, 'package.json' );",
		'\tif ( ! existsSync( sourcePkgPath ) ) {',
		"\t\tconsole.error( 'mirror-push: the managed tree carries no package.json — run scripts/sync-deploy-tree.mjs first.' );",
		'\t\tprocess.exit( 1 );',
		'\t}',
		'\tlet sourcePkg;',
		'\ttry {',
		"\t\tsourcePkg = JSON.parse( readFileSync( sourcePkgPath, 'utf8' ) );",
		'\t} catch ( error ) {',
		"\t\tconsole.error( 'mirror-push: the managed tree package.json is not valid JSON — ' + String( error ) );",
		'\t\tprocess.exit( 1 );',
		'\t}',
		'\tif ( ! sourcePkg.dependencies || Object.keys( sourcePkg.dependencies ).length === 0 ) {',
		"\t\tconsole.error( 'mirror-push: the managed tree declares no runtime dependencies — refusing to publish a tree that cannot boot.' );",
		"\t\tconsole.error( '  Regenerate it with scripts/sync-deploy-tree.mjs (it copies the project dependencies + lockfile).' );",
		'\t\tprocess.exit( 1 );',
		'\t}',
		'}',
		'',
		'const authed = new URL( url );',
		"authed.username = 'x-access-token';",
		'authed.password = token;',
		'',
		"const tmp = mkdtempSync( join( tmpdir(), 'forge-mirror-' ) );",
		'try {',
		"	git( [ 'clone', '--depth', '1', '--branch', 'main', '--', authed.href, tmp ] );",
		"	git( [ 'config', 'user.name', 'nvoos-forge' ], tmp );",
		"	git( [ 'config', 'user.email', 'forge@nvdigitalsolutions.com' ], tmp );",
		'	// An orphan branch: the site branch is a single root commit (flat).',
		"	git( [ 'checkout', '--orphan', branch ], tmp );",
		"	git( [ 'rm', '-rf', '.' ], tmp );",
		'	cpSync( source, tmp, { recursive: true } );',
		'// Velocity-compat (2026-11-06): Velocity is build-centric and REQUIRES',
		'// a build command — this no-op package.json makes `npm install` +',
		'// `npm run build` succeed on a repo that ships PREBUILT files (the',
		'// site IS the output). Point the Velocity app\'s Output Directory at',
		'// the repo root (`.`).',
		'//',
		'// Managed tier (Proposal 135): a real start command. The app owns',
		'// /api/*, /manage, /mcp and /api/revalidate through src/fetch.ts.',
		'if ( managed ) {',
		'\t// Normalize the manifest sync-deploy-tree emitted (already validated',
		'\t// above, before the clone) — never drop its dependencies.',
		"\tconst pkgPath = join( tmp, 'package.json' );",
		"\tconst managedPkg = JSON.parse( readFileSync( pkgPath, 'utf8' ) );",
		"\tmanagedPkg.name = managedPkg.name ?? 'nvoos-forge-site';",
		"\tmanagedPkg.version = managedPkg.version ?? '1.0.0';",
		'\tmanagedPkg.private = true;',
		"\tmanagedPkg.type = managedPkg.type ?? 'module';",
		"\tmanagedPkg.scripts = { build: 'node -e \"process.exit(0)\"', start: 'node server.js', ...( managedPkg.scripts ?? {} ) };",
		"\tmanagedPkg.engines = { node: '>=22.23.4', ...( managedPkg.engines ?? {} ) };",
		"\twriteFileSync( pkgPath, JSON.stringify( managedPkg, null, 2 ) + '\\n' );",
		'} else {',
		'\twriteFileSync( join( tmp, \'package.json\' ), JSON.stringify( { name: \'nvoos-forge-site\', version: \'1.0.0\', private: true, scripts: { build: \'node -e "console.log(\\\'prebuilt static site\\\')"\' } }, null, 2 ) + \'\\n\' );',
		'}',
		'',
		'// deploy.json — the branch-level deploy contract. Documents the Velocity',
		'// settings (Entry file, Output Directory) next to the code; it cannot',
		'// apply them (no deployment-settings API) but it makes them reproducible.',
		"writeFileSync( join( tmp, 'deploy.json' ), JSON.stringify( { runtime, entry: managed ? 'server.js' : null, outputDirectory: '.', generator: 'nvoos-astro-forge', proposal: 135, buildId: process.env.FORGE_BUILD_ID ?? '', generatedAt: new Date().toISOString() }, null, 2 ) );",
		"\tgit( [ 'add', '-A' ], tmp );",
		"	const message = 'Forge deploy: ' + ( process.env.GITHUB_SHA ?? 'local' );",
		'	try {',
		"		git( [ 'commit', '-m', message ], tmp );",
		'	} catch {',
		"		git( [ 'commit', '--allow-empty', '-m', message ], tmp );",
		'	}',
		'// GitHub intermittently rejects orphan-branch force pushes with',
		"// 'remote: fatal error in commit_refs' — transient; a retry clears it.",
		'let lastError;',
		'for ( let attempt = 1; attempt <= 3; attempt++ ) {',
		'	try {',
		"		git( [ 'push', '--force', 'origin', branch ], tmp );",
		'		lastError = undefined;',
		'		break;',
		'	} catch ( error ) {',
		'		lastError = error;',
		"		console.warn( 'mirror-push: attempt ' + attempt + ' of 3 failed, retrying in 5s — ' + ( error && error.stderr ? error.stderr.toString().trim() : String( error ) ) );",
		'		Atomics.wait( new Int32Array( new SharedArrayBuffer( 4 ) ), 0, 0, 5000 );',
		'	}',
		'}',
		'if ( lastError ) {',
		'	throw lastError;',
		'}',
		'} catch ( error ) {',
		"\tconsole.error( 'mirror-push failed: ' + ( error && error.stderr ? error.stderr.toString() : String( error ) ) );",
		'\tprocess.exit( 1 );',
		'} finally {',
		'	rmSync( tmp, { recursive: true, force: true } );',
		'}',
		"console.log( 'mirror-push: ' + branch + ' updated.' );",
		'',
	].join( '\n' );
}

/**
 * verify-deployed.mjs — is the LIVE site the build this run published?
 *
 * The mirror push proves the branch; Velocity keeps the last good release when
 * a deploy fails, so a green job can still serve yesterday's artifact
 * (2026-11-15: virtuallyjamaica's gray band copy came from an old release while
 * the local build was correct). The page carries the run's FORGE_BUILD_ID.
 *
 * Non-blocking by design (`continue-on-error: true` in the workflow): a DNS
 * issue must not fail a legitimate deploy, but the warning is visible.
 */
function verifyLiveBuildScript() {
	return [
		'#!/usr/bin/env node',
		'// verify-deployed.mjs — compare the live page marker to this build (2026-11-15).',
		'',
		"const url = process.env.FORGE_SITE_URL ?? '';",
		"const expected = ( process.env.FORGE_BUILD_ID ?? '' ).trim();",
		'',
		'function parseMarker( html ) {',
		'\tconst patterns = [',
		'\t\t/<meta\\b[^>]*\\bname\\s*=\\s*["\']forge-build["\'][^>]*\\bcontent\\s*=\\s*["\']([^"\']*)["\'][^>]*>/i,',
		'\t\t/<meta\\b[^>]*\\bcontent\\s*=\\s*["\']([^"\']*)["\'][^>]*\\bname\\s*=\\s*["\']forge-build["\'][^>]*>/i,',
		'\t];',
		'\tfor ( const pattern of patterns ) {',
		"\t\tconst match = pattern.exec( String( html ?? '' ) );",
		'\t\tif ( match ) return match[ 1 ].trim();',
		'\t}',
		'\treturn undefined;',
		'}',
		'',
		'if ( ! url || ! expected ) {',
		"\tconsole.log( 'verify-deployed: skipped (FORGE_SITE_URL or FORGE_BUILD_ID is not set).' );",
		'\tprocess.exit( 0 );',
		'}',
		'',
		'try {',
		'\t// Velocity auto-deploy is asynchronous: poll before calling it stale.',
		'\tfor ( let attempt = 1; attempt <= 6; attempt++ ) {',
		'\t\tconst probe = new URL( url );',
		"\t\tprobe.searchParams.set( 'forge-build-check', String( Date.now() ) );",
		"\t\tconst response = await fetch( probe, { redirect: 'follow', signal: AbortSignal.timeout( 20000 ), headers: { accept: 'text/html', 'cache-control': 'no-cache' } } );",
		'\t\tif ( response.ok ) {',
		'\t\t\tconst live = parseMarker( await response.text() );',
		'\t\t\tif ( live === expected ) {',
		'\t\t\t\tconsole.log( `verify-deployed: live release ${ live } matches this build.` );',
		'\t\t\t\tprocess.exit( 0 );',
		'\t\t\t}',
		'\t\t\tif ( attempt === 6 ) {',
		"\t\t\t\tconsole.warn( live ? `::warning title=Forge live build::live release is ${ live }, this build is ${ expected } — stale deploy.` : `::warning title=Forge live build::live page has no forge-build marker (expected ${ expected }) — it predates the marker or serves an older release.` );",
		'\t\t\t}',
		'\t\t} else if ( attempt === 6 ) {',
		'\t\t\tconsole.warn( `::warning title=Forge live build::${ url } returned HTTP ${ response.status }` );',
		'\t\t}',
		'\t\tif ( attempt < 6 ) {',
		'\t\t\tawait new Promise( ( resolve ) => setTimeout( resolve, 5000 ) );',
		'\t\t}',
		'\t}',
		'\tprocess.exit( 1 );',
		'} catch ( error ) {',
		"\tconsole.warn( `::warning title=Forge live build::could not verify ${ url } (${ error instanceof Error ? error.message : error }).` );",
		'\tprocess.exit( 1 );',
		'}',
	].join( '\n' );
}

/** A post-deploy identity step (non-blocking) for the emitted pipeline. */
function deployedCheckStep( config: DeployConfig ) {
	const site = config.siteUrl ? `          FORGE_SITE_URL: ${ config.siteUrl }` : '          FORGE_SITE_URL: ${{ vars.FORGE_SITE_URL }}';
	return [
		'',
		'      - name: Verify the live build id',
		'        continue-on-error: true',
		'        env:',
		site,
		'          FORGE_BUILD_ID: ${{ github.sha }}-${{ github.run_id }}',
		'        run: node scripts/verify-deployed.mjs',
	];
}

/**
 * Emit the deploy pipeline into a forged project directory. Returns the
 * relative paths written. Existing files are overwritten (re-emit is the
 * deploy-config update path).
 */
export async function emitDeployConfig( dir: string, config: DeployConfig ): Promise<{ files: string[] }> {
	const workflows = join( dir, '.github', 'workflows' );
	const scripts = join( dir, 'scripts' );
	await mkdir( workflows, { recursive: true } );
	await mkdir( scripts, { recursive: true } );

	assertRuntime( config.runtime );

	if ( Boolean( config.mirrorRepo ) !== Boolean( config.siteBranch ) ) {
		throw new Error( 'mirrorRepo and siteBranch must be set together (shared-mirror velocity deploys).' );
	}

	const files: { path: string; content: string }[] = [
		{ path: join( workflows, 'deploy.yml' ), content: deployWorkflow( config ) },
		{ path: join( workflows, 'rebuild.yml' ), content: rebuildWorkflow( config ) },
		{ path: join( scripts, 'verify-dispatch.mjs' ), content: verifyScript() },
		{ path: join( scripts, 'verify-deployed.mjs' ), content: verifyLiveBuildScript() },
	];
	if ( config.mirrorRepo && config.siteBranch ) {
		files.push( { path: join( scripts, 'mirror-push.mjs' ), content: mirrorPushScript( config.runtime ) } );
	}
	for ( const file of files ) {
		await writeFile( file.path, file.content, 'utf8' );
	}
	// Relative paths with forward slashes (Windows-safe). `relative` (not a
	// slice on `dir.length`) keeps the report correct for `./`-prefixed
	// targets — `join()` normalizes the prefix away from the written path.
	return { files: files.map( ( f ) => relative( dir, f.path ).split( '\\' ).join( '/' ) ) };
}
