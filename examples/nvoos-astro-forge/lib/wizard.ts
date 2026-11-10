// ---------------------------------------------------------------------------
// wizard.ts — setup_wizard: one command from URL to deployed (096 §6.1).
//
//   analyze → preset (suggested/confirmed, 104 §4.3) → register site →
//   scaffold → build (optional) → git init → emit deploy pipeline.
//
// The MCP `setup_wizard` tool and the CLI `forge wizard` command share this
// function. Every step is individually tolerant: an unreachable source
// fails the wizard (a profile without content is worse than no profile), a
// failed build returns `built: false` with warnings (never throws), and the
// deploy config emission is the same adapter-generic Phase 3.3 pipeline.
// Interactive prompts (clack) arrive through the optional `ui` hooks, so
// the shared path stays non-interactive for the MCP surface.
// ---------------------------------------------------------------------------

import { spawnSync } from 'node:child_process';
import { stat } from 'node:fs/promises';
import { join } from 'node:path';

import { emitDeployConfig, type DeployMode } from './deploy-config.ts';
import { analyzeDesignInput, fetchDesignInput } from './design.ts';
import { analyzeSite, profileFromDiscovery, type DiscoveryReport } from './discovery.ts';
import { applyPresetToProfile, isValidThemePolicy, PRESETS, suggestPreset, DEFAULT_THEME_POLICY, type PresetName, type RenderMode } from './presets.ts';
import { scaffold } from './scaffold.ts';
import { SitesStore } from './sites.ts';
import { assertSafeHttpUrl } from './ssrf.ts';
import type { ForgeDesign, SiteProfile } from './types.ts';
import { WpClient } from './wp-client.ts';

/**
 * Interactive prompt hooks (Proposal 104 §4.3). The CLI wires these to
 * @clack/prompts; the MCP surface leaves them unset so setup_wizard stays
 * non-interactive and the preset falls back to suggestPreset().
 */
export interface WizardUi {
	/** Ask which preset to use — null cancels the wizard. */
	selectPreset?: (suggested: PresetName, report: DiscoveryReport) => Promise<PresetName | null>;
	/** Ask static vs ssr (storefront preset only, 104 Q4) — null cancels. */
	selectRender?: (suggested: RenderMode, preset: PresetName) => Promise<RenderMode | null>;
	/** Ask the theme policy (105 §4, default 'auto') — null cancels. */
	selectThemePolicy?: ( suggested: string ) => Promise<string | null>;
	/** Confirm the extracted design language before it becomes the rebuild theme (114 P6). */
	confirmDesign?: ( design: ForgeDesign ) => Promise<boolean>;
	/** Final go/no-go before scaffolding — false cancels the wizard. */
	confirmScaffold?: ( preset: PresetName, targetDir: string ) => Promise<boolean>;
}

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
	/**
	 * Preset (104 §4.3). Absent → suggested from the site_analyze report
	 * (Woo/products detected → 'storefront'), or chosen interactively via
	 * ui.selectPreset when provided.
	 */
	preset?: PresetName;
	/**
	 * Rendering model (104 Q4). Absent → the preset's default ('static');
	 * the storefront preset offers the choice via ui.selectRender.
	 */
	render?: RenderMode;
	/** Enable the per-post OG cards surface (104 §4.8 / Q5). */
	ogImages?: boolean;
	/**
	 * Theme policy baked into the scaffold (105 §4):
	 * auto | light | dark | brand:<slug>. Default 'auto'.
	 */
	themePolicy?: string;
	/**
	 * Extract the source design language (114 P1) and apply it as the
	 * rebuild theme. Extraction failures degrade to warnings — a wizard
	 * never dies on a design pass.
	 */
	design?: boolean;
	/**
	/** Strict contrast mode (114 §3.5) — the scaffold's designAudit flips to
	 * 'strict' and the generated build fails on failing pairs.
	 */
	strictContrast?: boolean;
	/**
	 * Re-forge a target that is LOCKED for hand-editing (its profile carries
	 * `locked`, or the project carries `forge.lock.json`). Without it the
	 * scaffold fails closed — ownership was handed to a developer.
	 */
	unlock?: boolean;
	/** Interactive prompt hooks (CLI only). */
	ui?: WizardUi;
	/** Emit the AGENTS.md/.context stubs (default true; --no-agents flips it). */
	agents?: boolean;
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

	// 1b. Preset (104 §4.3): explicit → use it; otherwise suggest from the
	//     report and offer the interactive choice through the CLI ui hooks.
	const suggested = suggestPreset( report );
	let preset: PresetName = options.preset ?? suggested;
	if ( ! options.preset && options.ui?.selectPreset ) {
		const chosen = await options.ui.selectPreset( suggested, report );
		if ( chosen === null ) {
			throw new Error( 'Wizard cancelled.' );
		}
		preset = chosen;
	}
	if ( ! options.preset && options.ui?.confirmScaffold ) {
		const proceed = await options.ui.confirmScaffold( preset, options.targetDir );
		if ( ! proceed ) {
			throw new Error( 'Wizard cancelled.' );
		}
	}

	const profile = applyPresetToProfile( profileFromDiscovery( report ), preset );

	// 1c. Render mode (104 Q4): explicit flag wins; the storefront preset
	//     offers the interactive choice; every non-TTY path stays 'static'.
	let render: RenderMode = options.render ?? 'static';
	if ( ! options.render && preset === 'storefront' && options.ui?.selectRender ) {
		const chosen = await options.ui.selectRender( 'static', preset );
		if ( chosen === null ) {
			throw new Error( 'Wizard cancelled.' );
		}
		render = chosen;
	}

	// 1d. Theme policy (105 §4): explicit flag wins; the interactive choice
	//     defaults to 'auto'; every non-TTY path stays 'auto'.
	let themePolicy: string = options.themePolicy ?? DEFAULT_THEME_POLICY;
	if ( ! options.themePolicy && options.ui?.selectThemePolicy ) {
		const chosen = await options.ui.selectThemePolicy( themePolicy );
		if ( chosen === null ) {
			throw new Error( 'Wizard cancelled.' );
		}
		if ( ! isValidThemePolicy( chosen ) ) {
			throw new Error( `Invalid theme policy "${ chosen }" — use auto, light, dark or brand:<slug>.` );
		}
		themePolicy = chosen;
	}
	profile.url = options.url.replace( /\/+$/, '' );
	if ( options.name ) {
		profile.name = options.name;
	}

	// 1e. Design language (114 P1/P6) — extract from the source and attach the
	//     rebuild block. Deterministic analysis; the ui.confirmDesign hook is
	//     the interactive gate (TTY only). Failures warn, never cancel.
	if ( options.design ) {
		try {
			const design = analyzeDesignInput(
				await fetchDesignInput( profile.url, fetch, { allowLoopback: options.allowLoopback === true } )
			);
			const apply = options.ui?.confirmDesign ? await options.ui.confirmDesign( design ) : true;
			if ( apply ) {
				profile.design = design;
				warnings.push( `Design language applied: ${ design.concept }` );
			} else {
				warnings.push( 'Design language extracted but not applied (declined at the prompt).' );
			}
		} catch ( error ) {
			warnings.push(
				`Design extraction failed (continuing without): ${ error instanceof Error ? error.message : String( error ) }`
			);
		}
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
		await scaffold( {
			profile,
			targetDir: options.targetDir,
			template: options.template ?? PRESETS[ preset ].template,
			preset,
			render,
			surfaces: options.ogImages ? { ogImages: true } : undefined,
			agents: options.agents,
			themePolicy,
			strictContrast: options.strictContrast,
			unlock: options.unlock === true,
		} );
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
