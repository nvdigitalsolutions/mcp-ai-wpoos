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
		return [
			'      - name: Mirror dist to the shared site branch',
			'        env:',
			'          MIRROR_URL: ${{ secrets.VELOCITY_MIRROR_URL }}',
			'          MIRROR_TOKEN: ${{ secrets.VELOCITY_MIRROR_TOKEN }}',
			`          SITE_BRANCH: ${ config.siteBranch }`,
			'        run: node scripts/mirror-push.mjs',
		];
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
 * skips with a warning (never a failed build). The CLI runs the same emitted
 * file for the operator staging path, so workflow and CLI cannot drift.
 */
function mirrorPushScript(): string {
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
		'// optionally SOURCE_DIR (default: ./dist). Unset URL/token skips with a',
		'// warning — never a failed build.',
		'',
		"import { execFileSync } from 'node:child_process';",
		"import { cpSync, existsSync, mkdtempSync, rmSync } from 'node:fs';",
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
		'',
		'if ( ! url || ! token ) {',
		"	console.log( 'mirror-push: MIRROR_URL / MIRROR_TOKEN are not set — skipping mirror deploy.' );",
		'	process.exit( 0 );',
		'}',
		'if ( ! branch ) {',
		"	console.error( 'mirror-push: SITE_BRANCH is not set — refusing to push.' );",
		'	process.exit( 1 );',
		'}',
		'if ( ! existsSync( source ) ) {',
		"	console.error( 'mirror-push: ' + source + ' does not exist — run the build first.' );",
		'	process.exit( 1 );',
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
		"	git( [ 'add', '-A' ], tmp );",
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
 * Emit the deploy pipeline into a forged project directory. Returns the
 * relative paths written. Existing files are overwritten (re-emit is the
 * deploy-config update path).
 */
export async function emitDeployConfig( dir: string, config: DeployConfig ): Promise<{ files: string[] }> {
	const workflows = join( dir, '.github', 'workflows' );
	const scripts = join( dir, 'scripts' );
	await mkdir( workflows, { recursive: true } );
	await mkdir( scripts, { recursive: true } );

	if ( Boolean( config.mirrorRepo ) !== Boolean( config.siteBranch ) ) {
		throw new Error( 'mirrorRepo and siteBranch must be set together (shared-mirror velocity deploys).' );
	}

	const files: { path: string; content: string }[] = [
		{ path: join( workflows, 'deploy.yml' ), content: deployWorkflow( config ) },
		{ path: join( workflows, 'rebuild.yml' ), content: rebuildWorkflow( config ) },
		{ path: join( scripts, 'verify-dispatch.mjs' ), content: verifyScript() },
	];
	if ( config.mirrorRepo && config.siteBranch ) {
		files.push( { path: join( scripts, 'mirror-push.mjs' ), content: mirrorPushScript() } );
	}
	for ( const file of files ) {
		await writeFile( file.path, file.content, 'utf8' );
	}
	// Relative paths with forward slashes (Windows-safe). `relative` (not a
	// slice on `dir.length`) keeps the report correct for `./`-prefixed
	// targets — `join()` normalizes the prefix away from the written path.
	return { files: files.map( ( f ) => relative( dir, f.path ).split( '\\' ).join( '/' ) ) };
}
