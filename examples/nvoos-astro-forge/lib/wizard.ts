// ---------------------------------------------------------------------------
// wizard.ts — setup_wizard: one command from URL to deployed (096 §6.1).
//
//   analyze → register site → scaffold → build (optional) → git init →
//   emit deploy pipeline.
//
// The MCP `setup_wizard` tool and the CLI `forge wizard` command share this
// function. Every step is individually tolerant: an unreachable source
// fails the wizard (a profile without content is worse than no profile), a
// failed build returns `built: false` with warnings (never throws), and the
// deploy config emission is the same adapter-generic Phase 3.3 pipeline.
// ---------------------------------------------------------------------------

import { spawnSync } from 'node:child_process';
import { stat } from 'node:fs/promises';
import { join } from 'node:path';

import { emitDeployConfig, type DeployMode } from './deploy-config.ts';
import { analyzeSite, profileFromDiscovery } from './discovery.ts';
import { scaffold } from './scaffold.ts';
import { SitesStore } from './sites.ts';
import { assertSafeHttpUrl } from './ssrf.ts';
import type { SiteProfile } from './types.ts';
import { WpClient } from './wp-client.ts';

export interface WizardOptions {
	/** The WordPress source URL (https://…). */
	url: string;
	/** Registry name (slug) for the site. Default: derived from the hostname. */
	name?: string;
	/** Scaffold target directory (must not contain a foreign project). */
	targetDir: string;
	/** Deploy mode for the emitted pipeline. */
	mode?: DeployMode;
	/** Cloudflare Pages project name (cloudflare-pages mode). */
	cloudflareProject?: string;
	/** Template name or path (default 'blog'). */
	template?: string;
	/** Run npm install + astro build before finishing (CI/e2e use this). */
	build?: boolean;
	/** Skip registry registration (callers managing their own registry). */
	skipRegister?: boolean;
	/** CLI-only loopback opt-in for local dev sites (never set by the MCP surface). */
	allowLoopback?: boolean;
}

export interface WizardResult {
	siteName: string;
	profile: SiteProfile;
	targetDir: string;
	built: boolean;
	gitInitialized: boolean;
	deployFiles: string[];
	warnings: string[];
}

function slugFromUrl( url: string ): string {
	const host = new URL( url ).hostname.replace( /^www\./, '' );
	return host.replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, 32 ) || 'site';
}

function run( command: string, args: string[], cwd: string, env: Record<string, string> = {} ): { status: number | null; stdout: string } {
	const result = spawnSync( command, args, {
		cwd,
		shell: true,
		encoding: 'utf8',
		env: { ...process.env, ...env },
		timeout: 600_000,
	} );
	return { status: result.status, stdout: result.stdout ?? '' };
}

/**
 * Run the full wizard. Throws on an unreachable/unsafe source, an occupied
 * non-forge target, or a scaffold failure; everything downstream degrades
 * into warnings.
 */
export async function setupWizard( options: WizardOptions ): Promise<WizardResult> {
	const warnings: string[] = [];
	assertSafeHttpUrl( options.url, { allowLoopback: options.allowLoopback === true } );

	// 1. Discover — the polite site_analyze pass.
	const report = await analyzeSite( new WpClient( { baseUrl: options.url.replace( /\/+$/, '' ) } ) );
	const profile = profileFromDiscovery( report );
	profile.url = options.url.replace( /\/+$/, '' );
	if ( options.name ) {
		profile.name = options.name;
	}
	const siteName = options.name ?? slugFromUrl( options.url );

	// 2. Register (the operator allowlist entry).
	if ( ! options.skipRegister ) {
		const sites = new SitesStore();
		// The profile lives under the forge home so the registry entry is
		// self-contained: <forge-home>/profiles/<name>.json.
		const { mkdir, writeFile } = await import( 'node:fs/promises' );
		const { dirname, join: joinPath } = await import( 'node:path' );
		const profileDir = joinPath( dirname( sites.file ), 'profiles' );
		await mkdir( profileDir, { recursive: true } );
		const profilePath = joinPath( profileDir, `${ siteName }.json` );
		await writeFile( profilePath, `${ JSON.stringify( profile, null, '\t' ) }\n`, 'utf8' );
		await sites.add( siteName, { url: profile.url, profile: profilePath }, { isDefault: ( await sites.defaultName() ) === undefined } );
	}

	// 3. Scaffold (reuse an existing forge project in the target).
	let scaffolded = false;
	try {
		await stat( join( options.targetDir, 'nvoos.json' ) );
	} catch {
		await scaffold( { profile, targetDir: options.targetDir, template: options.template } );
		scaffolded = true;
	}

	// 4. Build (opt-in — the CLI prints next steps without it).
	let built = false;
	if ( options.build ) {
		const install = run( 'npm', [ 'install', '--no-audit', '--no-fund' ], options.targetDir );
		if ( install.status === 0 ) {
			const build = run( 'npm', [ 'run', 'build' ], options.targetDir, {
				ASTRO_SITE: profile.url,
				WP_SITE_URL: profile.url,
			} );
			if ( build.status === 0 ) {
				built = true;
			} else {
				warnings.push( `astro build failed in ${ options.targetDir } — see the project logs.` );
			}
		} else {
			warnings.push( `npm install failed in ${ options.targetDir } — see the project logs.` );
		}
	}

	// 5. git init (local only — the GitHub push is a separate, explicit step).
	let gitInitialized = false;
	try {
		await stat( join( options.targetDir, '.git' ) );
		gitInitialized = true;
	} catch {
		const init = run( 'git', [ 'init', '--quiet' ], options.targetDir );
		if ( init.status === 0 ) {
			gitInitialized = true;
			// An initial commit only when the operator has a git identity;
			// otherwise the repo is left clean and empty for them.
			const identity = run( 'git', [ 'config', '--get', 'user.email' ], options.targetDir );
			if ( identity.status === 0 && identity.stdout.trim() ) {
				const add = run( 'git', [ 'add', '.' ], options.targetDir );
				const commit = run( 'git', [ 'commit', '--quiet', '-m', 'Forge: generated site (setup_wizard)' ], options.targetDir );
				if ( add.status !== 0 || commit.status !== 0 ) {
					warnings.push( 'git init succeeded but the initial commit failed — files are staged, not committed.' );
				}
			} else {
				warnings.push( 'git identity not configured — skipped the initial commit.' );
			}
		} else {
			warnings.push( 'git init failed — deploy workflows are emitted but the repo is not initialized.' );
		}
	}

	// 6. Emit the deploy pipeline (Phase 3.3 — adapter-generic).
	const emitted = await emitDeployConfig( options.targetDir, {
		mode: options.mode ?? 'github-pages',
		siteUrl: profile.url,
		cloudflareProject: options.cloudflareProject,
	} );

	if ( scaffolded ) {
		warnings.push( 'Preview the site: cd into the project, `npm install`, `npm run dev`.' );
	}
	return {
		siteName,
		profile,
		targetDir: options.targetDir,
		built,
		gitInitialized,
		deployFiles: emitted.files,
		warnings,
	};
}
