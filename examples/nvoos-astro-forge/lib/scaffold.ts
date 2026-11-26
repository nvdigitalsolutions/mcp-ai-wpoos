// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// scaffold.ts — materializes the Astro project from a site profile.
//
// What it does (096 §3):
//   1. copy a template (built-in `blog` or an explicit path) into the target
//   2. vendor the forge libs into `src/forge/` (self-contained customer repo)
//   3. rewrite `content.config.ts` between the FORGE markers (site + collections)
//   4. write `nvoos.json` manifest + `.env.example`, enforce `.gitignore`
//
// What it never does: write secrets into the project (auth comes from env),
// fetch arbitrary URLs (the profile is operator-configured), or push built
// assets (the .gitignore gate covers dist/ and .astro/).
// ---------------------------------------------------------------------------

import { cp, copyFile, mkdir, readFile, readdir, rename, rm, stat, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { builderUtilitiesFor, type BuilderUtility } from './bands.ts';
import { renderElementCss } from './element-css.ts';
import { isMaterializedContent, withMaterializedDefaults } from './content-source.ts';
import { DEFAULT_GEOMETRY_VIEWPORTS, carouselBoxFacts, columnBoxFacts, columnInnerFacts } from './geometry.ts';
import { animationRebuildCss, countAnimations, planAnimationRebuild, type ParallaxCarrier } from './animations.ts';
import { interactionSelector } from './interactions.ts';
import { auditWarnings, contrastAudit, readableInk } from './contrast.ts';
import { contrast, DEFAULT_FONT_STACK, designToTokens, inkForSurface, renderableBands, resolveSectionTheme, suggestContrastFixes } from './design.ts';
import { renderDirectFormsEndpointTs } from './forms-direct.ts';
import { resolveSiteShape, siteNavAnchors } from './inventory.ts';
import { readLock } from './lock.ts';
import { applyPresetToProfile, isValidThemePolicy, PRESETS, type PresetName, type PresetSurfaces, type RenderMode } from './presets.ts';
import { ECA_CONFIG_PATH, ecaCollection, ecaScaffoldWarnings, renderEcaGenerated } from './eca-scaffold.ts';
import { ecaDataPages, ecaRouteFiles } from './eca-ui.ts';
import { renderApiProxyTs, renderFormsProxyTs, renderHealthTs, renderManagedGenerated, renderMediaProxyTs, renderPreviewApiTs, renderPreviewPageTs, renderProxyHelperTs, renderRevalidateTs, renderServerShim, renderSyncDeployTree, renderVerifyDeployTree } from './managed-runtime.ts';
import { MANAGE_BOOT_FILE, renderManageCredentialTs, renderManageGeneratedTs, renderManageLoginTs, renderManageLogoutTs, renderManageMeTs, renderManagePageTs, renderManageSessionTs, renderManageBootJs } from './manage-runtime.ts';
import { resolveManageBundle, type ManageBundle } from './manage-bundle.ts';
import { renderFrontController, renderGateRuntime, renderGateStatic } from './gate-runtime.ts';
import { offPublication, resolveGateConfig, writeGateManifest } from './gate-publish.ts';
import { TURNSTILE_DIRECTIVES, TURNSTILE_ORIGIN, consentCategoryFor, cspDirectivesFor, isCookielessVendor } from './tracking.ts';
import type { BandBoxFacts, BandGeometry, BandTypeFact, ForgeBandFacts, ForgeColorModes, ForgeContact, ForgeDesign, ForgeGeometry, ForgeRedirect, ForgeSection, ForgeSectionColumn, GatePublication, ResponsiveLength, SiteProfile, WpCollection } from './types.ts';

/** Package root (lib/..). */
export const FORGE_ROOT = fileURLToPath(new URL('..', import.meta.url));

/** Files vendored into every generated project under `src/forge/`. */
export const FORGE_LIB_FILES = [
	// `types.ts` imports `ForgeEca` from here (the profile's `eca` block), so the
	// module must exist in EVERY project even when the profile is not an ECA site
	// — otherwise `astro check` fails ts(2307) in every generated site. The file
	// is self-contained with no runtime imports, so vendoring it costs 40 KB of
	// unused TypeScript in a blog rebuild and nothing at runtime.
	'eca.ts',
	'types.ts',
	// `types.ts` imports `ForgeVerify` from here, so the module must exist in the
	// customer repo even though only the CLI reads the policy at runtime —
	// otherwise `astro check` fails ts(2307) in every generated project.
	'gate-policy.ts',
	'wp-client.ts',
	'sanitize.ts',
	'wp-loader.ts',
	'fetch-proxy.ts',
	// The form payload contract (parse + message + idempotency digest) both
	// delivery modes share — `forms.fallback` needs both at once.
	'forms.ts',
	// The measured HOVER deltas mapped onto the rebuilt components — the renderer
	// reads it, so it ships with every project.
	'hover-vars.ts',
	// A footer LINE split at the anchors it carries (`chrome.footerLinks`) — the
	// renderer rebuilds the credit anchor from it.
	'footer-line.ts',
	// Proposal 114: the semantic classifier + the shared contrast math the
	// generated project's build-time audit endpoint consumes.
	'semantic.ts',
	'contrast.ts',
	// Proposal 136: the deterministic agent surfaces — llms.txt and the
	// markdown page variants are composed from facts, never written by a model.
	'llms.ts',
	// Direct form delivery — the provider HTTP API, used only when a profile
	// opts into forms.delivery: 'direct'.
	'mail.ts',
	// The deploy-branch build report. It is an ARTIFACT rather than a
	// hand-written file because mirror branches are force-pushed on every
	// build — anything committed to the branch by hand is destroyed.
	'readme.ts',
];

/**
 * Agent stubs every scaffold emits unless --no-agents (Proposal 104 §4.4,
 * the Astroplate pattern): AGENTS.md documents the layout/commands/contract,
 * .context/README.md is the customer-context hook directory. Both are
 * collected by lib/project-files.ts (CLI deploy + github_commit push them).
 */
/**
 * The collections the template routes by hand. Every OTHER collection a
 * profile declares is a WordPress CPT (portfolio, case-studies, …) and gets a
 * generated route pair instead.
 */
export const CORE_COLLECTIONS = new Set<string>([ 'posts', 'pages', 'products' ]);

/** `case-studies` → `Case Studies` — the generated listing heading. */
export function collectionTitle( slug: string ): string {
	return slug
		.split( /[-_]+/ )
		.filter( Boolean )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );
}

/** The generated listing route for a non-core collection. */
export function renderCollectionIndexRoute( slug: string, base: string, up = '../../' ): string {
	return [
		'---',
		'// Generated by nvoos-astro-forge for a non-core collection.',
		'// Edits are overwritten on re-scaffold — the component does the work.',
		`import CollectionIndex from '${ up }components/CollectionIndex.astro';`,
		'---',
		'',
		`<CollectionIndex collection={${ JSON.stringify( slug ) }} basePath={${ JSON.stringify( base ) }} title={${ JSON.stringify( collectionTitle( slug ) ) }} />`,
		'',
	].join( '\n' );
}

/** The generated entry route for a non-core collection. */
export function renderCollectionEntryRoute( slug: string, base: string, up = '../../' ): string {
	return [
		'---',
		'// Generated by nvoos-astro-forge for a non-core collection.',
		'// Edits are overwritten on re-scaffold — the component does the work.',
		`import EntryView from '${ up }components/EntryView.astro';`,
		"import { getCollection } from 'astro:content';",
		'',
		'export async function getStaticPaths() {',
		// Astro HOISTS this function out of the module scope, so a module-level
		// helper is not defined when it runs — build the accessor inside.
		'\tconst load = getCollection as unknown as ( name: string ) => Promise< Array< any > >;',
		`\tconst entries = await load( ${ JSON.stringify( slug ) } );`,
		'\treturn entries.map( ( entry: any ) => ( {',
		'\t\tparams: { parts: `${ entry.id }/${ entry.data.slug ?? entry.id }` },',
		'\t\tprops: { entry, body: entry.rendered?.html },',
		'\t} ) );',
		'}',
		'',
		'const { entry, body } = Astro.props;',
		'---',
		'',
		`<EntryView entry={entry} body={body} collection={${ JSON.stringify( slug ) }} basePath={${ JSON.stringify( base ) }} />`,
		'',
	].join( '\n' );
}

export const AGENTS_STUB = `# AGENTS.md — generated by nvoos-astro-forge

Astro 7 frontend generated from a WordPress/WooCommerce source. Content
flows through an Astro content-layer loader at build time — WordPress
stays the CMS; this repo owns the presentation.

## Project layout

- src/pages/ — routes (posts, pages, products, SEO surfaces)
- src/layouts/Base.astro — the shared chrome (head, nav, footer)
- src/components/ — UI components (SmartImage, design/ — the 114 semantic rebuild sections)
- src/styles/global.css — --forge-* design tokens + styles
- src/content.config.ts — the content layer (collections, WP auth env)
- src/forge/ — vendored forge libraries (loader, client, sanitizer, semantic classifier) — treat as generated
- src/copy/ — typed copy tables (en.ts is the reference table)
- src/lib/ — theme runtime (policy + registry + theme modules), SEO builders, design.generated.ts (the resolved design)
- nvoos.json — the forge manifest; configuration lives here
- scripts/postbuild.mjs — materializes dist/_redirects after build
- .context/ — drop customer context files here for agent sessions

Content lives in src/content/ when you opt into content_materialize; by
default the content layer pulls from WordPress via src/content.config.ts.
Configuration lives in nvoos.json — the forge rewrites it on re-scaffold.

## Commands

    npm install       # dependencies
    npm run dev       # dev server (the content layer refetches on reload)
    npm run check     # astro check
    npm run build     # build (dist/; a server bundle when scaffolded --render ssr)
    npm run preview   # preview the built site

## Runtime contract

This project must satisfy the storefront runtime contract (Proposal 104):
see the "Storefront runtime contract" section of README.md — no-FOUC
theming, PDP canonicals + 301 slug convergence, Product JSON-LD in initial
HTML, cache headers + tags on commerce routes.
`;

/** The .context hook file — customer context lives here, never in code. */
export const CONTEXT_STUB = `# .context — customer context hooks

Drop files here that agent sessions should load when working in this repo:
brand notes, content decisions, environment quirks. The forge scaffold
creates this directory; its contents are yours.
`;

export interface ScaffoldOptions {
	/** Operator-configured site profile (allowlisted URL). */
	profile: SiteProfile;
	/**
	 * The publication verdict (Proposal 165). Absent = off (the real site).
	 * The CLI resolves it from the bridge before scaffolding; tests pass it
	 * explicitly, so the scaffold itself never makes a network call.
	 */
	gate?: GatePublication;
	/** Where the Astro project is written. */
	targetDir: string;
	/**
	 * A materialized content directory (the output of `forge materialize`)
	 * copied into `src/content/`. A site that outlived its CMS ships its
	 * content beside the profile.
	 */
	contentDir?: string;
	/**
	 * A committed media snapshot (the output of `forge media-snapshot`) copied
	 * into `media-snapshot/` so the post-build localizer can replace a dead
	 * origin derivative instead of dropping it.
	 */
	mediaDir?: string;
	/** Built-in template name ('blog') or a path to a template directory. */
	template?: string;
	/**
	 * Additive variant applied AFTER the template copy (see TEMPLATE_VARIANTS).
	 * A variant never replaces the template — it overlays only the files it
	 * needs, so a base-template fix reaches every variant for free.
	 */
	variant?: TemplateVariant;
	/** Fail instead of clobbering an existing target. */
	overwrite?: boolean;
	/**
	 * Re-forge a target that carries a handoff lock (`forge.lock.json`).
	 * Without it the scaffold fails closed: once a developer owns the tree, a
	 * regenerate must never silently destroy their work.
	 */
	unlock?: boolean;
	/**
	 * Preset name (104 §4.3) — applies the collection-profile filter +
	 * surface toggles and marks them in the manifest.
	 */
	preset?: PresetName;
	/**
	 * Rendering model (104 Q4): 'static' (default) is today's template;
	 * 'ssr' swaps the static product pages for the on-demand route +
	 * server output. Defaults to the preset's render mode ('static').
	 */
	render?: RenderMode;
	/**
	 * Surface overrides merged over the preset's surfaces (e.g.
	 * `{ ogImages: true }` from --og-images; tests use it for feed/
	 * sitemap pruning).
	 */
	surfaces?: Partial<PresetSurfaces>;
	/** Emit the AGENTS.md + .context/ agent stubs (104 §4.4). Default true. */
	agents?: boolean;
	/**
	 * Theme policy baked into the generated project (105 §4):
	 * auto | light | dark | brand:<slug>. Invalid values fall back to
	 * 'auto' with a warning. Default 'auto'.
	 */
	themePolicy?: string;
	/**
	 * Strict contrast mode (114 §3.5): the manifest gets designAudit
	 * 'strict', and the generated project's build-time audit endpoint fails
	 * the build when any rendered pair fails WCAG. Default false ('warn').
	 */
	strictContrast?: boolean;
	/**
	 * The site profile's slug (the `sites/<slug>.json` stem). Recorded in the
	 * manifest so the generated README can print an exact rebuild command
	 * instead of guessing one from the display name.
	 */
	siteSlug?: string;
	/**
	 * Keep the template's placeholder post + page. They exist so a scaffold
	 * renders with no WordPress at all (106 P2 hybrid local), but the loader
	 * MERGES local MDX under the WP entries — so on a real site the
	 * placeholder would publish at `/posts/hello-world/`. Off by default;
	 * `--demo-content` keeps it for offline demos and the hybrid tests.
	 */
	demoContent?: boolean;
}

export interface ForgeManifest {
	version: 1;
	generator: 'nvoos-astro-forge';
	url: string;
	name: string;
	/** The `sites/<slug>.json` stem this project was generated from. */
	site?: string;
	/**
	 * How the site navigates — the resolved operator setting
	 * (`profile.shape` › `inventory.shape` › multi-page). The template renders
	 * anchor nav for a one-pager and page links otherwise.
	 */
	shape?: 'one-pager' | 'multi-page' | 'hybrid';
	/** In-page anchor targets for a one-pager header nav. */
	navAnchors?: string[];
	/** Page slug rendered at `/` (the WordPress static front page). */
	frontPage?: string;
	/** The source's own head description (131 §2.5) — the front page's
	 * description fallback when the page carries no SEO description. */
	seoDescription?: string;
	/** The site-level social image (absolute URL, mirrored at build time). */
	seoImage?: string;
	/** Applied design-token overrides (098 output, tokens_apply). */
	tokens?: Record<string, string>;
	/** The design rebuild block (114 §3.3) — provenance for verify + audit. */
	design?: ForgeDesign;
	/** Contrast gate mode: 'warn' (default) or 'strict' (fail the build). */
	designAudit?: 'warn' | 'strict';
	/**
	 * Route-conflict policy (2026-11-22) — `strictRoutes: true` makes the
	 * generated `astro.config.mjs` set `prerenderConflictBehavior: "error"`.
	 * Absent = Astro's `warn` default (the first path wins).
	 */
	build?: { strictRoutes?: boolean };
	/** The form bridge block (114 §3.9) — endpoint/searchForms consumed by
	 * the Form design component (env vars override at deploy time). */
	forms?: { endpoint?: string; origin?: string; searchForms?: string[] };
	/**
	 * Media provenance (139) — extra authorized image hosts and whether the
	 * post-build localizer runs. Read by astro.config.mjs, src/lib/media.ts
	 * and scripts/localize-media.mjs (env vars override at build time).
	 */
	media?: { hosts?: string[]; localize?: boolean; maxFileMb?: number; mode?: 'local' | 'proxy' | 'off'; snapshot?: boolean };
	/**
	 * Surface toggles (104 §4.3 preset output) — the template gates its
	 * chrome on these (search/feed links, sitemap pointer, og:image).
	 */
	surfaces?: { search: boolean; feed: boolean; sitemap: boolean; ogImages: boolean };
	/**
	 * Where the content came from (2026-11-15). `materialized` means the CMS is
	 * GONE: the scaffolded loader reads the committed snapshot and never touches
	 * the network, so the generated project is self-describing about it.
	 * Always emitted so the key is inferable from the JSON.
	 */
	contentSource?: 'wordpress' | 'materialized' | 'none';
	/** The contact band (issue #7549) — copy the front page renders at `#contact`. */
	contact?: ForgeContact;
	/** Rendering model (104 Q4) — informational; the project files embody it. */
	render?: 'static' | 'ssr';
	/**
	 * The deploy tier (135) — `managed` means the bridge/BFF is present at
	 * runtime (the `/media/*` proxy + `/api`). Always emitted so the key is
	 * inferable from the JSON; media handling keys off it (Proposal 139).
	 */
	runtime?: 'static' | 'managed';
	/**
	 * The staff surface (Proposal 165 §7) — emitted when `deploy.manage` is on.
	 * `sessionCookie` is the host-only cookie name the gate verifies; it is not
	 * a secret, so the manifest stays safe in a public repo. `bundle` appears
	 * only when the Astro CMS bundle was vendored (§7b); its `integrity` is the
	 * SRI token the page enforces, so a stale copy fails loudly instead of
	 * running mismatched code.
	 */
	admin?: {
		enabled: boolean;
		sessionCookie?: string;
		bundle?: { src: string; css: string; integrity: string; cssIntegrity: string };
	};
	/**
	 * Tracking provenance (Proposal 130) — vendor names and the consent mode
	 * only. No ids, no tokens: the manifest must stay safe in a public repo.
	 */
	tracking?: { enabled: boolean; vendors: string[]; consentMode: string; hasVerification: boolean; consentExempt: string[] };
	/**
	 * Operator-declared redirects (cutover leftovers). Public paths only, so the
	 * manifest stays safe in a public repo.
	 */
	redirects?: ForgeRedirect[];
	generatedAt: string;
	collections: Array<{
		slug: string;
		restBase: string;
		kind?: string;
		source?: string;
		authMode?: string;
		/** Proposal 131 — the wp/v2 SEO sidecar the SSR product route reads. */
		seoFrom?: string;
		/** URL prefix for an EXTRA collection's generated routes (default `/<slug>`). */
		basePath?: string;
		exclude?: string[];
	}>;
}

export interface ScaffoldResult {
	targetDir: string;
	manifest: ForgeManifest;
	collections: string[];
	/** WCAG/parse warnings collected during the token gate. */
	warnings: string[];
	/** Static wireframe routes emitted (Proposal 115 §3). */
	wireframePages?: string[];
}

/** Resolve a template: built-in name under templates/, or an explicit path. */
export async function resolveTemplateDir(template?: string): Promise<string> {
	if (!template || template === 'blog') {
		const builtIn = path.join(FORGE_ROOT, 'templates', template ?? 'blog');
		try {
			await readFile(path.join(builtIn, 'package.json'));
			return builtIn;
		} catch {
			throw new Error(`Built-in template "${template ?? 'blog'}" not found under ${builtIn}`);
		}
	}
	const explicit = path.resolve(template);
	try {
		await readFile(path.join(explicit, 'package.json'));
	} catch {
		throw new Error(`Template directory not found: ${explicit}`);
	}
	return explicit;
}

/**
 * The additive template variants this forge ships.
 *
 * A variant is NOT a template: `templates/blog` stays the single source of
 * truth and a variant overlays only the files it needs, so a base-template fix
 * reaches every variant for free. `templates/variants/<id>/variant.json` is the
 * machine-readable manifest — the same agent-readable discipline as presets.json.
 */
export const TEMPLATE_VARIANTS = ['product-3d', 'video-hero'] as const;
export type TemplateVariant = (typeof TEMPLATE_VARIANTS)[number];

/** True when `value` names a shipped variant. */
export function isTemplateVariant(value: unknown): value is TemplateVariant {
	return typeof value === 'string' && (TEMPLATE_VARIANTS as readonly string[]).includes(value);
}

/** Every file under a directory, as `/`-joined relative paths. */
async function relativeFiles(root: string, prefix = ''): Promise<string[]> {
	const files: string[] = [];
	for (const entry of await readdir(root, { withFileTypes: true })) {
		const rel = prefix ? `${prefix}/${entry.name}` : entry.name;
		if (entry.isDirectory()) {
			files.push(...(await relativeFiles(path.join(root, entry.name), rel)));
		} else {
			files.push(rel);
		}
	}
	return files.sort();
}

/**
 * Overlay a variant on a freshly scaffolded project.
 *
 * `fs.cp` merges directories, so this never deletes a template file — it only
 * adds (or deliberately replaces) the ones the variant owns. Returns the
 * relative paths it wrote, for the manifest and the CLI log line.
 */
export async function applyTemplateVariant(
	targetDir: string,
	variant: TemplateVariant
): Promise<string[]> {
	const source = path.join(FORGE_ROOT, 'templates', 'variants', variant);
	try {
		await readFile(path.join(source, 'variant.json'));
	} catch {
		throw new Error(`Template variant "${variant}" not found under ${source}`);
	}
	const files = await relativeFiles(source);
	await cp(source, targetDir, { recursive: true });
	return files;
}

/** Serialize one collection as a TS object literal for content.config.ts. */
function tsString(value: string): string {
	// `\n`/`\r`/`\u2028`/`\u2029` must be escaped: the top strip's address is
	// two lines (its `<br>` becomes a real newline in the fact), and a raw
	// newline inside a single-quoted literal is a syntax error in the generated
	// module.
	return `'${value
		.replace(/\\/g, '\\\\')
		.replace(/'/g, "\\'")
		.replace(/\r/g, '\\r')
		.replace(/\n/g, '\\n')
		.replace(/\u2028/g, '\\u2028')
		.replace(/\u2029/g, '\\u2029')}'`;
}

/** A TS object key: bare when it is a valid identifier, quoted otherwise. */
function tsKey( value: string ): string {
	return /^[A-Za-z_$][A-Za-z0-9_$]*$/.test( value ) ? value : tsString( value );
}

/**
 * Serialize a band length — a plain string, or the per-device map Elementor
 * uses when it scopes a pull to a device range.
 */
function tsLength( value: ResponsiveLength ): string {
	if ( typeof value === 'string' ) {
		return tsString( value );
	}
	const parts = ( [ 'desktop', 'tablet', 'mobile' ] as const )
		.filter( ( device ) => value[ device ] )
		.map( ( device ) => `${ device }: ${ tsString( value[ device ] as string ) }` );
	return `{ ${ parts.join( ', ' ) } }`;
}

/**
 * Serialize one measured typography fact. Absent keys are omitted rather than
 * emitted as `undefined`, so a band with only a size is one short line.
 *
 * The tablet/mobile overrides (what the source CHANGES below the desktop
 * breakpoint — a 55px hero headline is 22px on a phone) are emitted verbatim:
 * `diffTypeFact` already recorded only the differences, and a device that resets
 * an alignment to `left` must keep it.
 */
function tsTypeFact( fact: BandTypeFact ): string {
	const properties = ( value: Record< string, unknown >, dropDefaults: boolean ): string[] =>
		( [ 'size', 'weight', 'lineHeight', 'letterSpacing', 'transform', 'align', 'family', 'color', 'maxWidth', 'marginTop', 'marginBottom' ] as const )
			.filter( ( key ) => {
				const entry = value[ key ];
				if ( typeof entry !== 'string' || entry.length === 0 ) {
					return false;
				}
				return ! ( dropDefaults && ( entry === 'normal' || entry === 'none' || entry === 'start' ) );
			} )
			.map( ( key ) => `${ key }: ${ tsString( value[ key ] as string ) }` );
	const parts = properties( fact as unknown as Record< string, unknown >, true );
	for ( const device of [ 'tablet', 'mobile' ] as const ) {
		const override = fact[ device ];
		if ( ! override ) {
			continue;
		}
		const inner = properties( override as unknown as Record< string, unknown >, false );
		if ( inner.length > 0 ) {
			parts.push( `${ device }: { ${ inner.join( ', ' ) } }` );
		}
	}
	return `{ ${ parts.join( ', ' ) } }`;
}

/**
 * Serialize the band's type facts: per-heading-level rules plus the body scale,
 * and `title` = the band's LARGEST heading (its visual title). The renderer
 * emits `[data-section-id] h3 { … }` from these — the raw-HTML path otherwise
 * renders at the browser's default 1.17em-of-body heading scale.
 */
function tsBandType( type: { headings?: Record< string, BandTypeFact >; body?: BandTypeFact } ): string | undefined {
	const headings = Object.entries( type.headings ?? {} ).filter( ( [ , fact ] ) => Boolean( fact.size ) );
	const parts: string[] = [];
	if ( headings.length > 0 ) {
		parts.push(
			`headings: { ${ headings
				.map( ( [ tag, fact ] ) => `${ tsKey( tag ) }: ${ tsTypeFact( fact ) }` )
				.join( ', ' ) } }`
		);
		const largest = headings.reduce( ( best, entry ) =>
			( parseFloat( entry[ 1 ].size ?? '0' ) || 0 ) > ( parseFloat( best[ 1 ].size ?? '0' ) || 0 ) ? entry : best
		);
		parts.push( `title: ${ tsTypeFact( largest[ 1 ] ) }` );
		// The LEVEL of that title — the source's own tag. A band whose only
		// heading is an `<h3>` (virtuallyjamaica's contact form) must not be
		// promoted to the component's default `<h2>`: the outline is content,
		// and the level is measurable. The renderer maps an `h1` down to `h2`
		// (the page article owns the single h1).
		parts.push( `titleLevel: ${ tsString( largest[ 0 ] ) }` );
	}
	if ( type.body?.size ) {
		parts.push( `body: ${ tsTypeFact( type.body ) }` );
	}
	return parts.length > 0 ? `{ ${ parts.join( ', ' ) } }` : undefined;
}

/**
 * Drop a band's measured BODY INK when it would be unreadable on that band's
 * own surface.
 *
 * The measurement reads what the source RENDERS, and on a builder band whose
 * copy sits inside a card, that ink is the CARD's (black) while the band paints
 * something else. The rebuild has no card surface — the vendor sets it per
 * element in a stylesheet that never ships — so the ink landed black-on-black
 * and every word in those bands disappeared (monicarajiv.com's airport-transfer
 * tile band). Falling back to the band/theme ink keeps the copy readable; the
 * colour gate then reports the drift, which is the honest trade.
 */
/**
 * The colour a band's copy actually sits on.
 *
 * A flat band fill wins; otherwise the tail of its gradient (the part under the
 * copy); otherwise the page background. Shared by the type facts and the
 * eyebrow so both decide readability against the same surface.
 */
export function bandSurface( section: ForgeSection, profile: SiteProfile ): string {
	const flat = section.bgColor && section.bgColor !== 'transparent' ? section.bgColor : undefined;
	return flat ?? gradientTail( section.bgGradient ) ?? profile.pageBackground ?? '#ffffff';
}

/**
 * Below this contrast the measured ink is not "low contrast", it is INVISIBLE —
 * and therefore the wrong ink for this surface.
 *
 * The two cases are different and used to be conflated at 4.5:1:
 *
 *   - a band whose copy sits on a CARD the rebuild does not render records the
 *     CARD's ink, so black-on-black or white-on-white comes through (ratio ≈ 1);
 *     that colour must go, and the band/theme ink takes over.
 *   - a source that simply paints its body copy a light grey (`#747474` on
 *     `#f7fafc` is 4.24:1 — below AA, perfectly readable) is a DESIGN choice.
 *     Dropping it repainted eca.directory's grey copy near-black and the colour
 *     gate reported 29.5 ΔE of drift on five bands (2026-11-24).
 *
 * So the guard now only rescues the invisible case. A source that fails AA still
 * fails it in the rebuild — which is parity — and `contrastAudit` reports it as
 * a finding rather than the scaffold silently redesigning the band.
 */
const INVISIBLE_INK_RATIO = 2.5;

export function readableBandType< T extends { headings?: Record< string, BandTypeFact >; body?: BandTypeFact } >(
	type: T,
	section: ForgeSection,
	profile: SiteProfile
): T {
	const surface = bandSurface( section, profile );
	const readable = ( fact: BandTypeFact | undefined ): BandTypeFact | undefined => {
		if ( ! fact?.color ) {
			return fact;
		}
		const ratio = contrast( fact.color, surface );
		if ( ratio === undefined || ratio >= INVISIBLE_INK_RATIO ) {
			return fact;
		}
		const next = { ...fact };
		delete next.color;
		return next;
	};
	let changed = false;
	const headings: Record< string, BandTypeFact > = {};
	for ( const [ tag, fact ] of Object.entries( type.headings ?? {} ) ) {
		const next = readable( fact ) ?? fact;
		if ( next !== fact ) {
			changed = true;
		}
		headings[ tag ] = next;
	}
	const body = readable( type.body );
	if ( body !== type.body ) {
		changed = true;
	}
	if ( ! changed ) {
		return type;
	}
	return { ...type, ...( type.headings ? { headings } : {} ), ...( body ? { body } : {} ) };
}

/**
 * The ink a CARD's body copy inherits inside this band.
 *
 * `.ds-section .ds-card p { color: var(--forge-muted) }` exists because a
 * band-level ink harvested from a DARK PANEL repainted a white card
 * (virtuallysrilanka). But the blanket rule also breaks parity wherever the
 * card sits on the surface its ink was measured against: eca.directory's cards
 * are the page's own surface and the source paints their copy `#747474`, while
 * the `muted` token is Elementor's `#69727d` — ΔE00 6.4 on two bands, both
 * reported by the colour gate (2026-11-24).
 *
 * The test is a SURFACE test, not a contrast test: the measured ink is reused
 * for the band's cards only when the band and the card paint the same surface
 * (contrast < 1.15 — `#ffffff` against the template's `#f5f5f4` is 1.08). An
 * ink measured over a dark panel therefore never reaches a white card, and no
 * threshold has to judge what "readable" means twice.
 *
 * @return The card copy ink, or `undefined` when the template's `muted` token
 *         is the honest answer.
 */
export function cardInkFor(
	section: ForgeSection,
	type: { body?: BandTypeFact },
	profile: SiteProfile
): string | undefined {
	const ink = type.body?.color;
	if ( ! ink ) {
		return undefined;
	}
	const cardSurface = mergeTokens( profile.design, profile.tokens, profile.colors )[ 'surface-alt' ] ?? '#ffffff';
	const delta = contrast( bandSurface( section, profile ), cardSurface );
	return delta !== undefined && delta < 1.15 ? ink : undefined;
}

/**
 * Overlay an operator `section.type` pin on the MEASURED band type.
 *
 * A pin is an explicit correction, so it must not be re-judged by
 * `readableBandType`: that fallback compares the measured ink against the
 * SECTION's flat surface, which is the wrong surface when the copy sits on a
 * nested one the rebuild DOES ship. virtuallyjamaica's `1ff4891` cards sit on a
 * red `#dd3333` column (kept inline by the sanitizer): the source's white copy
 * was measured correctly, then dropped against the `#ededed` band, so the cards
 * rendered grey-on-red. The pin restores the measured ink; `verify --colors` is
 * the gate that reports it if the pin is wrong.
 *
 * Fields merge per heading level and per property, so a colour-only pin keeps
 * the measured sizes (a pin used to REPLACE the whole fact block).
 */
export function mergeBandType(
	measured: { headings?: Record< string, BandTypeFact >; body?: BandTypeFact },
	pinned: { headings?: Record< string, BandTypeFact >; body?: BandTypeFact } | undefined
): { headings?: Record< string, BandTypeFact >; body?: BandTypeFact } {
	if ( ! pinned ) {
		return measured;
	}
	const headings: Record< string, BandTypeFact > = { ...( measured.headings ?? {} ) };
	for ( const [ tag, fact ] of Object.entries( pinned.headings ?? {} ) ) {
		headings[ tag ] = { ...( headings[ tag ] ?? {} ), ...fact };
	}
	const body = pinned.body ? { ...( measured.body ?? {} ), ...pinned.body } : measured.body;
	return {
		...( Object.keys( headings ).length > 0 ? { headings } : {} ),
		...( body ? { body } : {} ),
	};
}

/** The end colour of a CSS gradient — the surface a painted card ends on. */
function gradientTail( gradient: string | undefined ): string | undefined {
	if ( ! gradient ) {
		return undefined;
	}
	const colors = gradient.match( /(?:#[0-9a-f]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\))/gi );
	return colors && colors.length > 0 ? colors[ colors.length - 1 ] : undefined;
}

/** Serialize one builder utility (base + its device-scoped declarations). */
function tsUtility( utility: BuilderUtility ): string {
	const parts = ( [ 'base', 'tablet', 'mobile' ] as const )
		.filter( ( device ) => utility[ device ] )
		.map( ( device ) => `${ device }: ${ tsString( utility[ device ] as string ) }` );
	return `{ ${ parts.join( ', ' ) } }`;
}

/**
 * Resolve a collection's REST base.
 *
 * `source: 'local'` has a canonical home on the bridge
 * (`nvoos-commerce/v1/local`), so a profile may omit `restBase` entirely —
 * one less thing to get wrong across 20 site profiles.
 */
function collectionRestBase( collection: WpCollection ): string {
	if ( collection.restBase ) {
		return collection.restBase;
	}
	return collection.source === 'local' ? 'nvoos-commerce/v1/local' : '';
}

/**
 * Write the generated-artifact drift manifest (Proposal 126 §drift).
 *
 * MUST run after every generated write in the scaffold: the managed tier
 * rewrites `src/lib/managed.generated.ts` late in the sequence, and a
 * manifest hashed before that reports a phantom drift on every managed site.
 */
async function writeGeneratedManifest( targetDir: string ): Promise<void> {
	const generatedFiles = [
		'src/styles/global.css',
		'src/lib/design.generated.ts',
		'src/lib/sections.generated.ts',
		'src/lib/pages.generated.ts',
		'src/lib/chrome.generated.ts',
		'src/lib/behaviors.generated.ts',
		'src/lib/animations.generated.ts',
		'src/lib/vocabulary.generated.ts',
		'src/lib/tracking.generated.ts',
		// Present only on an ECA profile — the loop tolerates a missing file.
		'src/lib/eca.generated.ts',
		// Present only when deploy.manage is on — the loop tolerates a missing file.
		'src/lib/manage-session.ts',
		// Present only when deploy.manageBundle is on (§7b).
		'src/lib/manage-credential.ts',
		// The bundle's SRI/sha provenance. The 900 KB binaries are NOT hashed here:
		// reading them as text is lossy, and the SRI token in this file already pins
		// their bytes.
		'src/lib/manage.generated.ts',
		// The external /manage boot (no inline script on the page).
		MANAGE_BOOT_FILE,
		'src/lib/managed.generated.ts',
		'public/tokens.dtcg.json',
		'public/_headers',
		// Proposal 165 — the publication verdict (generated, never hand-edited).
		'gate.generated.json',
	];
	const files: Record<string, string> = {};
	for ( const file of generatedFiles ) {
		const content = await readFile( path.join( targetDir, file ), 'utf8' ).catch( () => null );
		if ( content !== null ) {
			files[ file ] = createHash( 'sha256' ).update( content ).digest( 'hex' );
		}
	}
	await writeFile(
		path.join( targetDir, 'nvoos.generated.json' ),
		JSON.stringify( { scaffoldedAt: new Date().toISOString(), files }, null, 2 ) + '\n',
		'utf8'
	);
}

function collectionLiteral(collection: WpCollection, indent = '\t'): string {
	const fields = collection.fields.map((f) => tsString(f)).join(', ');
	const lines = [
		`${indent}slug: ${tsString(collection.slug)},`,
		`${indent}restBase: ${tsString(collectionRestBase(collection))},`,
		`${indent}fields: [${fields}],`,
		`${indent}embed: ${collection.embed === false ? 'false' : 'true'},`,
		`${indent}perPage: ${collection.perPage ?? 100},`,
		`${indent}maxPages: ${collection.maxPages ?? 50},`,
	];
	// Extra REST keys the collection needs verbatim (Proposal 163). Emitted
	// explicitly: a passthrough field that never reaches the generated config is
	// a silently missing dataset, not a build error.
	if (collection.passthrough && collection.passthrough.length > 0) {
		lines.push(`${indent}passthrough: [${collection.passthrough.map((field) => tsString(field)).join(', ')}],`);
	}
	if (collection.exclude && collection.exclude.length > 0) {
		lines.push(`${indent}exclude: [${collection.exclude.map((s) => tsString(s)).join(', ')}],`);
	}
	if (collection.kind) {
		lines.push(`${indent}kind: ${tsString(collection.kind)},`);
	}
	if (collection.source) {
		lines.push(`${indent}source: ${tsString(collection.source)},`);
	}
	if (collection.authMode) {
		lines.push(`${indent}authMode: ${tsString(collection.authMode)},`);
	}
	if (collection.optional) {
		lines.push(`${indent}optional: true,`);
	}
	if (collection.seoFrom) {
		lines.push(`${indent}seoFrom: ${tsString(collection.seoFrom)},`);
	}
	return `{\n${lines.join('\n')}\n${indent}}`;
}

/**
 * Hybrid-local sources (Proposal 106 P2) — the collections whose WP loader
 * also merges local MDX files (glob over src/content/<slug>/*.mdx).
 */
export const LOCAL_CONTENT_SOURCES: Readonly<Record<string, HybridLocalSource>> = {
	posts: { pattern: '**/*.mdx', base: './src/content/posts' },
	pages: { pattern: '**/*.mdx', base: './src/content/pages' },
};

/** One local MDX glob merged under a WP collection. */
export interface HybridLocalSource {
	pattern: string;
	base: string;
}

/** Generate the content.config.ts body between the FORGE markers. */
/**
 * The contact band the front page renders at `#contact` (issue #7549).
 *
 * An ECA profile already names its people once, in `eca.contacts`, and `/help/`
 * renders them from there. Copying the same four people into a second list
 * would drift the moment someone changes an email, so the band is DERIVED.
 * An explicit `profile.contact` always wins (a site may want different people
 * on the home band than on the help page), and a profile with neither gets no
 * contact block — never an empty one.
 */
export function resolveContactBlock( profile: SiteProfile ): ForgeContact | undefined {
	if ( profile.contact ) {
		return profile.contact;
	}
	const contacts = profile.eca?.contacts ?? [];
	if ( contacts.length === 0 ) {
		return undefined;
	}
	// A person without an email is still worth naming, but NOT as a dead mailto:
	// the component only links the ones that can be written to.
	const people = contacts.map( ( contact ) => ( {
		label: contact.label,
		...( contact.role ? { role: contact.role } : {} ),
		...( contact.email ? { email: contact.email } : {} ),
	} ) );
	const primary = people.find( ( person ) => person.email );
	return {
		heading: 'Questions?',
		// The enquiry band this replaces is a form; a form that cannot post is a
		// dead end, so say what works instead of shipping the shell of one.
		blurb: 'Send the ECA team an email and we will get back to you.',
		...( primary?.email ? { email: primary.email } : {} ),
		people,
		links: [ { label: 'Booking steps and answers', href: '/help/' } ],
	};
}

export function renderContentConfig(profile: SiteProfile): string {
	// Pages the ECA preset owns as DATA VIEWS (a day timetable, the full list).
	// They get their own static route, so the generic `[slug]` page must not also
	// claim the URL — one canonical route per page is a hard gate.
	const ecaDataSlugs = ecaDataPages( profile ).map( ( page ) => page.slug );
	const block: string[] = [];
	block.push(`// FORGE:site — generated by nvoos-astro-forge; edits are overwritten on re-scaffold.`);
	block.push(
		`const siteUrl = (import.meta.env.WP_SITE_URL ?? ${tsString(profile.url.replace(/\/+$/, ''))}).replace(/\\/+$/, '');`
	);
	block.push(`const siteName = ${tsString(profile.name ?? 'Forge Site')};`);
	block.push('');
	block.push(`const applicationAuth: WpAuth | undefined =`);
	block.push(`\timport.meta.env.WP_APPLICATION_USER && import.meta.env.WP_APPLICATION_PASSWORD`);
	block.push(`\t\t? { type: 'basic', username: import.meta.env.WP_APPLICATION_USER, password: import.meta.env.WP_APPLICATION_PASSWORD }`);
	block.push(`\t\t: undefined;`);
	block.push(`const wcAuth: WpAuth | undefined =`);
	block.push(`\timport.meta.env.WP_WC_CONSUMER_KEY && import.meta.env.WP_WC_CONSUMER_SECRET`);
	block.push(`\t\t? { type: 'basic', username: import.meta.env.WP_WC_CONSUMER_KEY, password: import.meta.env.WP_WC_CONSUMER_SECRET }`);
	block.push(`\t\t: undefined;`);
	block.push('');
	block.push(`const site: SiteProfile = { url: siteUrl, name: siteName, collections: [] };`);
	block.push('');
	for (const collection of profile.collections) {
		const effective =
			collection.slug === 'pages' && ecaDataSlugs.length > 0
				? { ...collection, exclude: [ ...( collection.exclude ?? [] ), ...ecaDataSlugs ] }
				: collection;
		block.push(`const ${collection.slug}Collection: WpCollection = ${collectionLiteral(effective)};`);
	}
	block.push('');
	block.push(`export const collections = {`);
	// A materialized profile (the CMS is gone) reads ONLY the snapshot: every
	// collection gets its `src/content/<slug>/` glob, and `localOnly` keeps the
	// loader off the network entirely. `posts`/`pages` keep their canonical
	// globs; any extra collection (a CPT, a storefront catalogue) resolves the
	// same way, so `forge materialize` output works for it too.
	const materialized = isMaterializedContent(profile);
	// A profile-declared cap is only honoured when it is a real positive integer:
	// a typo must not become `|| 0` (falsy → default, silently) or a negative
	// number handed to AbortSignal.timeout (which throws).
	const declaredTimeout = Number(profile.fetch?.timeoutMs);
	const timeoutFallback =
		Number.isFinite(declaredTimeout) && declaredTimeout > 0
			? Math.min(Math.floor(declaredTimeout), 600_000)
			: null;
	const timeoutExpr = timeoutFallback
		? `Number(import.meta.env.WP_FETCH_TIMEOUT_MS) || ${timeoutFallback}`
		: 'Number(import.meta.env.WP_FETCH_TIMEOUT_MS) || undefined';
	for (const collection of profile.collections) {
		const authVar = collection.authMode === 'wc-consumer' ? 'wcAuth' : 'applicationAuth';
		// Hybrid local (106 P2): posts/pages merge the local MDX glob under the
		// WP loader — WP wins on collision, local fills the gaps.
		const localSource = materialized
			? LOCAL_CONTENT_SOURCES[collection.slug] ?? {
					pattern: '**/*.mdx',
					base: `./src/content/${collection.slug}`,
			  }
			: LOCAL_CONTENT_SOURCES[collection.slug];
		const localOption = localSource
			? `, local: { pattern: ${tsString(localSource.pattern)}, base: ${tsString(localSource.base)} }`
			: '';
		const localOnlyOption = materialized ? ', localOnly: true' : '';
		// A big Elementor page tree is megabytes of markup in ONE REST page; a
		// cheap host can take 30s+ to serialise it. The transport cap is
		// therefore a build knob (`WP_FETCH_TIMEOUT_MS`), not a hard 15s — and
		// the profile's own `fetch.timeoutMs` is baked in as the FALLBACK, so a
		// host that knows nothing about the source still gets a cap that fits it
		// (eca.directory needs >26s; the fleet runner aborted at 15s).
		block.push(
			`\t${collection.slug}: defineCollection({ loader: createWpLoader({ site, collection: ${collection.slug}Collection, auth: ${authVar}${localOption}${localOnlyOption}, userAgent: import.meta.env.WP_USER_AGENT, timeoutMs: ${timeoutExpr} }) }),`
		);
	}
	block.push(`};`);
	return block.join('\n');
}

/**
 * The generated policy stub path (relative to the project root) — the baked
 * theme policy the layout boot script and the toggle read (Proposal 105 §4).
 */
export const THEME_POLICY_GENERATED_PATH = 'src/lib/theme/policy.generated.ts';

/**
 * Render the generated policy stub (repo style: tabs + single quotes). The
 * policy module itself (policy.ts) ships verbatim with the template copy.
 */
export function renderPolicyGenerated(policy: string, updatedAt: string = new Date().toISOString()): string {
	return [
		'// ---------------------------------------------------------------------------',
		'// policy.generated.ts — generated by nvoos-astro-forge (Proposal 105 §4);',
		'// edits are overwritten on re-scaffold. The baked theme policy is the',
		'// build-time source of truth — the layout boot reads it, never the network.',
		'// ---------------------------------------------------------------------------',
		'',
		"import type { PolicyDoc } from './policy.ts';",
		'',
		'export const generatedPolicy: PolicyDoc = {',
		`\tpolicy: '${policy}',`,
		`\tcontrast: 'auto',`,
		`\tdefaultTheme: 'dark',`,
		`\tupdatedAt: '${updatedAt}',`,
		'\t$extensions: {},',
		'};',
		'',
	].join('\n');
}

/** Rewrite the FORGE:site..FORGE:collections-end span in content.config.ts.
 * Marker matching is line-anchored, so prose mentioning the markers cannot
 * be mistaken for them.
 */
export function rewriteContentConfig(source: string, profile: SiteProfile): string {
	const lines = source.split(/\r?\n/);
	let startLine = -1;
	let endLine = -1;
	for (let i = 0; i < lines.length; i++) {
		const trimmed = lines[i].trim();
		if (trimmed === '// FORGE:site') startLine = i;
		if (trimmed === '// FORGE:collections-end') {
			endLine = i;
			break;
		}
	}
	if (startLine === -1 || endLine === -1 || endLine <= startLine) {
		throw new Error('content.config.ts is missing the FORGE:site / FORGE:collections-end markers — the template is corrupt.');
	}
	const replacement = renderContentConfig(profile);
	return [...lines.slice(0, startLine), replacement, ...lines.slice(endLine)].join('\n');
}

/** Default `--forge-*` values (ADS core palette) — overrides merge on top. */
const DEFAULT_TOKENS: Record<string, string> = {
	accent: '#0f6b4f',
	'accent-ink': '#ffffff',
	ink: '#1a1a1a',
	muted: '#6b6b6b',
	surface: '#ffffff',
	'surface-alt': '#f5f5f4',
	line: '#e4e4e2',
	// The 3:1-audited functional border (the ADS line-strong additive —
	// decorative hairlines keep `line`; inputs use this).
	'line-strong': '#767676',
	radius: '8px',
	'radius-sm': '4px',
	'radius-lg': '12px',
	font: "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
	'font-display': "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
	// The nav/menu face. Degrades to the body face so a two-family source
	// renders exactly as before.
	'font-ui': 'var(--forge-font)',
	// The class-driven accent face; degrades to the display role.
	'font-accent': 'var(--forge-font-display)',
	space: '1rem',
	'space-sm': '0.5rem',
	'space-lg': '2rem',
	// The site container width — a SITE measure, not a prose measure
	// (72ch ≈ 470px at 13px body text squeezed the rebuilt bands).
	measure: '75rem',
	'scale-h1': '2.25rem',
	'scale-h2': '1.75rem',
	'scale-h3': '1.25rem',
	'scale-h4': '1.125rem',
	'scale-body': '1rem',
	'scale-small': '0.875rem',
	'shadow-card': '0 1px 2px rgba(0, 0, 0, 0.06), 0 4px 12px rgba(0, 0, 0, 0.06)',
};

/** The template's dark-mode overrides — audited alongside the light pairs. */
export const DARK_TOKENS: Record<string, string> = {
	ink: '#f5f5f4',
	muted: '#a3a3a3',
	surface: '#171717',
	'surface-alt': '#262626',
	line: '#3f3f3f',
	'line-strong': '#6b6b6b',
};

/** High-contrast mode collapses muted/line onto ink (the CSS var refs). */
export function highTokensFor( tokens: Record<string, string> ): Record<string, string> {
	const ink = tokens.ink ?? '#1a1a1a';
	return { muted: ink, line: ink, 'line-strong': ink };
}

/**
 * The rendered body ink from a colour baseline, when one exists.
 *
 * The design extraction reads CSS DECLARATIONS; the browser reads what actually
 * paints. On virtuallyjamaica the extracted `text` role is `#2a2a2a` (a
 * heading/declaration colour) while body copy renders `#626262` — a 18.79 ΔE00
 * miss that only the colour gate could see. `ink` is body copy, so the measured
 * body colour wins over the declared one. `accent-ink` is NOT overridden: it is
 * contrast-derived for text ON the accent and would fail AA if it followed the
 * body ink.
 */
function measuredBodyInk( colors?: ForgeColorModes | Record< string, { text?: string } > ): string | undefined {
	if ( ! colors ) {
		return undefined;
	}
	const modes = colors as Record< string, unknown >;
	// Current shape (mode → region → colours); legacy flat shape (region → colours).
	const light = ( modes.light ?? modes ) as Record< string, { text?: string } | undefined >;
	const text = light?.body?.text;
	return typeof text === 'string' && /^#[0-9a-f]{6}$/i.test( text ) ? text : undefined;
}

/**
 * Merge the token stack (114 §3.3): template defaults ← design-block
 * derivation (tokens_apply v2) ← rendered body ink (when measured) ← explicit
 * profile.tokens overrides. Explicit operator overrides always win.
 */
/**
 * The ACCENT family a profile ALREADY measured, no re-analysis needed.
 *
 * `design.typography.accent` postdates most committed profiles, so derive it
 * from the measured heading facts instead: the one family that is neither the
 * body, the display role nor the menu. monicarajiv records Dancing Script on
 * its `h3.alt-font` facts, and the builder’s own `alt-font` utility points
 * at the resulting token.
 */
export function accentFamilyOf( profile: SiteProfile ): string | undefined {
	const design = profile.design;
	if ( design?.typography.accent ) {
		return design.typography.accent;
	}
	const known = new Set(
		[ design?.typography.body, design?.typography.display, design?.typography.ui ]
			.map( ( name ) => ( name ?? '' ).trim().toLowerCase() )
			.filter( Boolean )
	);
	const families: string[] = [];
	const add = ( name?: string ): void => {
		const clean = ( name ?? '' ).trim().replace( /["']/g, '' );
		if ( clean && ! known.has( clean.toLowerCase() ) && ! families.some( ( family ) => family.toLowerCase() === clean.toLowerCase() ) ) {
			families.push( clean );
		}
	};
	const addFacts = ( facts?: { headings?: Record< string, { family?: string } >; body?: { family?: string } } ): void => {
		for ( const fact of Object.values( facts?.headings ?? {} ) ) {
			add( fact?.family );
		}
		add( facts?.body?.family );
	};
	for ( const section of design?.sections ?? [] ) {
		addFacts( section.type );
	}
	for ( const facts of Object.values( profile.bandFacts ?? {} ) ) {
		addFacts( facts );
	}
	for ( const page of Object.values( profile.pageBands ?? {} ) ) {
		for ( const facts of Object.values( page.bandFacts ?? {} ) ) {
			addFacts( facts );
		}
	}
	return families[ 0 ];
}

export function mergeTokens(
	design?: ForgeDesign,
	tokens?: Record< string, string >,
	colors?: ForgeColorModes | Record< string, { text?: string } >,
	/** The ACCENT family a profile already measured (see `accentFamilyOf`).
	 * It has to arrive here rather than in `scaffoldProject`, because the
	 * token-block AUDIT compares the on-disk span against this function — two
	 * derivations drift apart (the audit failed on its first outing). */
	accent?: string
): Record< string, string > {
	const merged = { ...DEFAULT_TOKENS, ...( design ? designToTokens( design ) : {} ), ...( tokens ?? {} ) };
	if ( accent && ! ( tokens && tokens[ 'font-accent' ] ) ) {
		merged[ 'font-accent' ] = `'${ accent }', ${ DEFAULT_FONT_STACK }`;
	}
	const ink = measuredBodyInk( colors );
	if ( ink && ! ( tokens && typeof tokens.ink === 'string' && tokens.ink.trim() ) ) {
		merged.ink = ink;
	}
	return merged;
}

/** Render the :root token block (defaults + profile overrides). */
export function renderTokenBlock( tokens?: Record<string, string> ): string {
	const merged = { ...DEFAULT_TOKENS, ...( tokens ?? {} ) };
	const lines = Object.entries( merged ).map( ( [ name, value ] ) => `\t--forge-${ name }: ${ value };` );
	return `:root {\n${ lines.join( '\n' ) }\n}`;
}

/** Rewrite the FORGE:tokens span in global.css (line-anchored markers). */
export function rewriteGlobalCss(source: string, tokens?: Record<string, string>): string {
	const lines = source.split(/\r?\n/);
	let start = -1;
	let end = -1;
	for (let i = 0; i < lines.length; i++) {
		const trimmed = lines[i].trim();
		if (trimmed === '/* FORGE:tokens */') start = i;
		if (trimmed === '/* FORGE:tokens-end */') {
			end = i;
			break;
		}
	}
	if (start === -1 || end === -1 || end <= start) {
		throw new Error('global.css is missing the FORGE:tokens markers — the template is corrupt.');
	}
	// Keep both marker lines — the span stays re-writable and self-documenting.
	return [
		...lines.slice(0, start),
		lines[start],
		renderTokenBlock(tokens),
		lines[end],
		...lines.slice(end + 1),
	].join('\n');
}

/**
 * Audit a generated project's token span against what scaffold would write for
 * the same profile. This is the "buttons turned green" class: a tree whose
 * global.css never got the rewrite (a half-scaffolded project, a template
 * copied over it by hand, a stale deploy) still builds — with the template
 * defaults (#0f6b4f accent, template CTA pad/radius) instead of the site's.
 *
 * Returns `markers: false` when the span is absent entirely (nothing was ever
 * applied), otherwise the tokens whose on-disk value differs from the profile.
 */
export function auditTokenBlock(
	source: string,
	tokens?: Record<string, string>
): {
	markers: boolean;
	drifted: Array<{ name: string; expected: string; actual: string | null }>;
} {
	const lines = source.split(/\r?\n/);
	const start = lines.findIndex((line) => line.trim() === '/* FORGE:tokens */');
	const end = lines.findIndex((line) => line.trim() === '/* FORGE:tokens-end */');
	if (start === -1 || end === -1 || end <= start) {
		return { markers: false, drifted: [] };
	}

	const actual = new Map<string, string>();
	for (const line of lines.slice(start + 1, end)) {
		const match = line.match(/^\s*--forge-([a-z0-9-]+)\s*:\s*([^;]+);?\s*$/i);
		if (match) {
			actual.set(match[1], match[2].trim());
		}
	}

	const expected = { ...DEFAULT_TOKENS, ...(tokens ?? {}) };
	const drifted = Object.entries(expected)
		.filter(([name, value]) => actual.get(name) !== value.trim())
		.map(([name, value]) => ({ name, expected: value, actual: actual.get(name) ?? null }));

	return { markers: true, drifted };
}

/**
 * Render the generated design module (114 §3.3) — src/lib/design.generated.ts.
 * The single source of truth stays the profile's design block; this file is
 * the deterministic build artifact the template reads (audit endpoint,
 * design components). Repo style: tabs + single quotes via tsString.
 */
export function renderDesignGenerated( profile: SiteProfile, tokens: Record<string, string> ): string {
	const design = profile.design;
	const header = [
		'// ---------------------------------------------------------------------------',
		'// design.generated.ts — generated by nvoos-astro-forge (Proposal 114 §3.3);',
		'// edits are overwritten on re-scaffold. The resolved design module: the',
		'// single source of truth is the site profile\'s design block (nvoos.json);',
		'// this file is the build artifact the template reads — tokens for the audit',
		'// endpoint, typography/spacing/radii for the design components, and the',
		'// adaptation brief for attribution. Deterministic: same profile, same bytes.',
		'// ---------------------------------------------------------------------------',
		'',
		'export interface ResolvedDesign {',
		'\tsource: { url: string; analyzedAt?: string };',
		'\tconcept?: string;',
		'\ttokens: Record<string, string>;',
		'\tdarkTokens: Record<string, string>;',
		'\thighTokens: Record<string, string>;',
		'\ttypography: { display?: string; body?: string; scalePx: number[] };',
		'\tspacing: { scalePx: number[] };',
		'\tradii: { valuesPx: number[] };',
		'\tcomponents: Record<string, number>;',
		'\tadaptation?: {',
		'\t\tkeep: string[];',
		'\t\treplace: string[];',
		'\t\tremix: string[];',
		'\t\tlicensingNote: string;',
		'\t\tattribution: string[];',
		'\t};',
		'}',
		'',
		'export const resolvedDesign: ResolvedDesign = {',
	];
	const body: string[] = [];
	body.push(
		`\tsource: { url: ${ tsString( profile.url.replace( /\/+$/, '' ) ) }${ design ? `, analyzedAt: ${ tsString( design.source.analyzedAt ) }` : '' } },`
	);
	if ( design?.concept ) {
		body.push( `\tconcept: ${ tsString( design.concept ) },` );
	}
	body.push( '\ttokens: {' );
	for ( const [ name, value ] of Object.entries( tokens ) ) {
		body.push( `\t\t${ tsString( name ) }: ${ tsString( value ) },` );
	}
	body.push( '\t},' );
	body.push( '\tdarkTokens: {' );
	for ( const [ name, value ] of Object.entries( DARK_TOKENS ) ) {
		body.push( `\t\t${ tsString( name ) }: ${ tsString( value ) },` );
	}
	body.push( '\t},' );
	body.push( '\thighTokens: {' );
	for ( const [ name, value ] of Object.entries( highTokensFor( tokens ) ) ) {
		body.push( `\t\t${ tsString( name ) }: ${ tsString( value ) },` );
	}
	body.push( '\t},' );
	body.push(
		`\ttypography: {${ design?.typography.display ? ` display: ${ tsString( design.typography.display ) },` : '' }${ design?.typography.body ? ` body: ${ tsString( design.typography.body ) },` : '' } scalePx: ${ JSON.stringify( design?.typography.scalePx ?? [ 14, 16, 20, 28, 40 ] ) } },`
	);
	body.push( `\tspacing: { scalePx: ${ JSON.stringify( design?.spacing.scalePx ?? [ 4, 8, 16, 24, 48 ] ) } },` );
	body.push( `\tradii: { valuesPx: ${ JSON.stringify( design?.radii.valuesPx ?? [ 4, 8 ] ) } },` );
	body.push( `\tcomponents: ${ JSON.stringify( design?.components ?? {} ) },` );
	if ( design?.adaptation ) {
		body.push( '\tadaptation: {' );
		body.push( `\t\tkeep: ${ JSON.stringify( design.adaptation.keep ) },` );
		body.push( `\t\treplace: ${ JSON.stringify( design.adaptation.replace ) },` );
		body.push( `\t\tremix: ${ JSON.stringify( design.adaptation.remix ) },` );
		body.push( `\t\tlicensingNote: ${ tsString( design.adaptation.licensingNote ) },` );
		body.push( `\t\tattribution: ${ JSON.stringify( design.adaptation.attribution ) },` );
		body.push( '\t},' );
	}
	body.push( '};' );
	body.push( '' );
	// The source webfonts (125 §2) — Base.astro loads them from Google
	// Fonts when the harvested family is not a system stack. The rebuilt
	// site otherwise renders the fallback stack (Segoe UI/Helvetica) and
	// the source's type rhythm never lands (jayainc: Rubik).
	body.push( '// The source webfonts (125 §2) — Base.astro loads them from Google Fonts' );
	body.push( '// when the harvested family is not a system stack (jayainc: Rubik).' );
	body.push( 'export const webFonts: Array<{ family: string; weights: number[] }> = [' );
	const systemFontRe = /^(system-ui|ui-sans-serif|ui-serif|ui-monospace|sans-serif|serif|monospace|cursive|fantasy|arial|helvetica|georgia|times|garamond|courier|verdana|tahoma|trebuchet|segoe|menlo|consolas|impact)$/i;
	// EVERY family the rebuild RENDERS, not just the two roles. A source can wear
	// a third face on its headings (monicarajiv: a Dancing Script `alt-font` h3
	// beside a Karma h1) and a fourth on its menu. Loading only display+body left
	// those rules pointing at a family with no @font-face — the browser fell back
	// and the per-level rule silently did nothing.
	const familyWeights = new Map< string, { family: string; weights: Set< number >; ladder: boolean } >();
	const addFamily = ( name: string | undefined, weight?: string, ladder = false ): void => {
		const clean = ( name ?? '' ).trim().replace( /["']/g, '' );
		// An icon font is never requested from Google Fonts (it is not there).
		if ( ! clean || systemFontRe.test( clean ) || /icon|awesome|dashicons|glyph|symbol/i.test( clean ) ) {
			return;
		}
		const key = clean.toLowerCase();
		const entry = familyWeights.get( key ) ?? { family: clean, weights: new Set< number >(), ladder: false };
		if ( ladder ) {
			// The two ROLE families keep the full ladder: body/display also render
			// prose and UI the measurements never sample (bold, small print).
			entry.ladder = true;
		}
		// A measured weight is kept for EVERY family: a role can sit outside the
		// 300-700 ladder (jayainc's body is 300), and an accent only needs the
		// weight it actually wears.
		const parsed = parseInt( weight ?? '', 10 );
		entry.weights.add( parsed >= 100 && parsed <= 900 ? parsed : 400 );
		familyWeights.set( key, entry );
	};
	addFamily( design?.typography.display, undefined, true );
	addFamily( design?.typography.body, design?.typography.bodyWeight, true );
	addFamily( design?.typography.ui );
	addFamily( design?.typography.accent );
	const addTypeFacts = ( facts?: { headings?: Record< string, { family?: string; weight?: string } >; body?: { family?: string; weight?: string } } ): void => {
		for ( const fact of Object.values( facts?.headings ?? {} ) ) {
			addFamily( fact?.family, fact?.weight );
		}
		addFamily( facts?.body?.family, facts?.body?.weight );
	};
	for ( const section of design?.sections ?? [] ) {
		addTypeFacts( section.type );
	}
	// The site-level measurement (the front page) + every sub-page: each carries
	// its own measured headings, and a band can wear a family the roles never see.
	for ( const facts of Object.values( profile.bandFacts ?? {} ) ) {
		addTypeFacts( facts );
	}
	for ( const page of Object.values( profile.pageBands ?? {} ) ) {
		for ( const facts of Object.values( page.bandFacts ?? {} ) ) {
			addTypeFacts( facts );
		}
	}
	const bodyWeight = parseInt( design?.typography.bodyWeight ?? '', 10 );
	for ( const entry of familyWeights.values() ) {
		const weights = entry.ladder ? new Set< number >( [ 300, 400, 500, 600, 700, ...entry.weights ] ) : new Set< number >( entry.weights );
		const sorted = [ ...weights ].sort( ( a, b ) => a - b );
		body.push( `\t{ family: ${ tsString( entry.family ) }, weights: [ ${ sorted.join( ', ' ) } ] },` );
	}
	body.push( '];' );
	body.push( '' );
	return [ ...header, ...body ].join( '\n' );
}

/**
 * Render the DTCG 2025.10 token document (126 §dtcg) —
 * public/tokens.dtcg.json. The interchange artifact: the same core→semantic
 * group shape the ADS (velocity) and the NDS importer consume (the design
 * tokens format module 2025.10 — $type/$value groups, {ref} aliases), emitted
 * beside the flat CSS vars so a forged site ships a machine-readable design
 * contract. Only hex colors are DTCG-safe — non-hex token values stay
 * CSS-only (the CSS var remains authoritative). Deterministic.
 */
export function renderTokensDtcg( profile: SiteProfile, tokens: Record<string, string> ): string {
	const design = profile.design;
	const hexOnly = ( value: string | undefined ): string | undefined =>
		value && /^#[0-9a-f]{3,8}$/i.test( value ) ? value.toLowerCase() : undefined;
	const colorGroup = ( names: string[] ): Record<string, unknown> => {
		const group: Record<string, unknown> = {};
		for ( const name of names ) {
			const value = hexOnly( tokens[ name ] );
			if ( value ) {
				group[ name ] = { $value: value };
			}
		}
		return group;
	};
	const dim = ( px: number | undefined ): { value: number; unit: 'px' } | undefined =>
		typeof px === 'number' ? { value: px, unit: 'px' } : undefined;
	const spaceStops = [ 'xs', 'sm', 'md', 'lg', 'xl' ];
	const space: Record<string, unknown> = {};
	( design?.spacing.scalePx ?? [ 4, 8, 16, 24, 48 ] ).forEach( ( px, index ) => {
		const name = spaceStops[ index ] ?? `step-${ index + 1 }`;
		const value = dim( px );
		if ( value ) {
			space[ name ] = { $value: value };
		}
	} );
	const radiusStops = [ 'sm', 'md', 'lg' ];
	const radius: Record<string, unknown> = {};
	( design?.radii.valuesPx ?? [ 4, 8 ] ).forEach( ( px, index ) => {
		const name = radiusStops[ index ] ?? `step-${ index + 1 }`;
		const value = dim( px );
		if ( value ) {
			radius[ name ] = { $value: value };
		}
	} );
	const dtcg = {
		$schema: 'https://www.designtokens.org/schemas/2025.10/format.json',
		core: {
			color: { $type: 'color', ...colorGroup( [ 'accent', 'accent-ink', 'ink', 'muted', 'surface', 'surface-alt', 'line', 'line-strong' ] ) },
			font: {
				$type: 'fontFamily',
				display: { $value: design?.typography.display ?? tokens[ 'font-display' ] },
				body: { $value: design?.typography.body ?? tokens.font },
			},
			space: { $type: 'dimension', ...space },
			radius: { $type: 'dimension', ...radius },
		},
		semantic: {
			color: { $type: 'color', ...colorGroup( [ 'ink', 'muted', 'accent-ink', 'line', 'line-strong' ] ) },
			typography: {
				body: {
					$type: 'typography',
					$value: {
						fontFamily: '{core.font.body}',
						fontSize: dim( design?.typography.scalePx?.[ 1 ] ) ?? { value: 16, unit: 'px' },
						fontWeight: parseInt( design?.typography.bodyWeight ?? '', 10 ) || 400,
						lineHeight: 1.6,
					},
				},
			},
		},
	};
	return JSON.stringify( dtcg, null, 2 ) + '\n';
}

/**
 * The template's own side gutter at the desktop breakpoint
 * (`clamp(1rem, 4vw, 2rem)` = 32px per side). A measured container width is a
 * CONTENT width, so the band's scoped `max-width` must add the two gutters back.
 *
 * LEGACY ONLY — a profile measured before `ForgeBandFacts.container` existed has
 * no per-device gutter to use, and this desktop-derived compensation was wrong
 * at every other breakpoint (see `measuredContainer`). Re-analysing the site
 * replaces it with the measured fact.
 */
const BAND_GUTTER_PX = 64;

/**
 * A measured container wider than this is FLUID (`.container-fluid`: 1410px of a
 * 1440px viewport), not boxed (`.container`: 1140px).
 *
 * LEGACY ONLY, like `BAND_GUTTER_PX`: it is an ABSOLUTE width applied at every
 * viewport, which is precisely the per-viewport bug `ForgeBandFacts.container`
 * fixes. It survives solely so an un-re-analysed profile scaffolds as it did.
 */
const FLUID_CONTAINER_MIN = 1300;

/**
 * Below this the band is a strip (a logo rail, a spacer, a one-line banner)
 * whose height is content-driven anyway; mirroring it would pin a value the
 * rebuild's own type scale should decide.
 *
 * That reasoning only holds when the band HAS content. A decoration-only band
 * (jayainc's dff3f1a is a lone `elementor-widget-spacer`) has nothing whose
 * scale could decide its height, so its measured height is the whole design
 * and is mirrored at any size — see `contentlessBand` below.
 */
const MIN_MIRRORED_BAND_HEIGHT = 120;

/**
 * True when the band carries no content the rebuild can lay out: no heading,
 * no body copy, no media and no control. Builder sources use such a band as a
 * spacer (an Elementor `widget-spacer`), and its measured height is the only
 * thing there is to reproduce.
 *
 * `interactions` is part of the test so a band whose sole content is a button
 * is NOT mistaken for decoration.
 */
function contentlessBand( facts: ForgeBandFacts | undefined ): boolean {
	if ( ! facts ) {
		return false;
	}
	return (
		Object.keys( facts.headings ?? {} ).length === 0 &&
		! facts.body &&
		Object.keys( facts.media ?? {} ).length === 0 &&
		( facts.interactions ?? [] ).length === 0
	);
}

/**
 * The band's MEASURED padding (114 §3.6), per device.
 *
 * Builder sources express band rhythm through classes — Brando's
 * `padding-four-all`, `no-padding-tb`, `padding-ten-lr` — whose stylesheet never
 * ships with the rebuild, so a band that carries 130px of vertical padding in
 * the source renders flush here. The computed box is the only faithful source,
 * and it is captured per viewport so a band that tightens on mobile stays
 * tightened.
 *
 * Returns a plain string when all three viewports agree (the common case) and a
 * per-device map when they differ, which is the shape `SectionTheme.padding`
 * and `responsiveDeviceCss` already handle. A band measured FLUSH returns `0`
 * (a fact — it overrides the template rhythm); only a band with no measurement
 * at all returns `undefined`.
 */
/**
 * The band's lanes as the BROWSER measured them, merged with the declared
 * column facts.
 *
 * `design.sections[].columns` states the builder's INTENT — `col-33` on every
 * lane, plus whatever surfaces a static parse could attribute. The geometry pass
 * renders the band and records the real width and the PAINTED layer, so it wins
 * where the two disagree. The declared `bgImage` URL is kept, because the
 * measured fact records only that an image is painted, not which.
 *
 * Returns undefined unless at least two lanes were measured, so a band with no
 * column grid keeps the declared path.
 */
function measuredLaneColumns(
	bandFacts: ForgeBandFacts | undefined,
	declared: ForgeSectionColumn[] | undefined,
	contentWidth?: number
): ForgeSectionColumn[] | undefined {
	const lanes = bandFacts?.columns?.desktop;
	if ( ! lanes || lanes.length < 2 ) {
		return undefined;
	}
	// Only override the declared columns when the measurement carries something
	// the declaration cannot: a rendered width or a painted surface. Otherwise a
	// grid measured for its heights alone would replace a good declaration with
	// empty objects.
	if ( ! lanes.some( ( lane ) => lane.width || lane.bg || lane.image ) ) {
		return undefined;
	}
	const total = lanes.reduce( ( sum, lane ) => sum + ( lane.width ?? 0 ), 0 );
	// A lane set where EVERY lane fills the content column is a STACK, not a row:
	// eca.directory's about-us CTA measures two 1280px lanes inside a 1280px
	// content column. Normalising those to 50/50 laid them side by side; recording
	// each as 100% lets the renderer derive ONE track (see lib/lane-tracks.ts).
	const stacked =
		contentWidth !== undefined &&
		contentWidth > 0 &&
		lanes.every( ( lane ) => ( lane.width ?? 0 ) >= contentWidth * 0.95 );
	const declaredImages = ( declared ?? [] )
		.map( ( column ) => column.bgImage )
		.filter( ( url ): url is string => Boolean( url ) );
	const used = new Set< string >();
	return lanes.map( ( lane, index ) => {
		const column: ForgeSectionColumn = {};
		if ( stacked ) {
			column.width = 100;
		} else if ( lane.width && total > 0 ) {
			// A one-decimal share keeps the lanes summing to 100.
			column.width = Math.round( ( lane.width / total ) * 1000 ) / 10;
		}
		if ( lane.bg ) {
			column.bgColor = lane.bg;
			// A painted lane's copy needs the ink that slab requires — the card's
			// default (`--forge-muted`) is unreadable on a black or red panel.
			column.ink = inkForSurface( lane.bg );
		}
		if ( lane.image ) {
			const atIndex = declared?.[ index ]?.bgImage;
			const url =
				atIndex && ! used.has( atIndex )
					? atIndex
					: declaredImages.find( ( candidate ) => ! used.has( candidate ) );
			if ( url ) {
				used.add( url );
				column.bgImage = url;
			}
		}
		// The measurement knows GEOMETRY, the declaration knows per-device INTENT.
		// A responsive visibility class (`elementor-hidden-mobile`) is still in the
		// desktop DOM and a width override lives in a media block, so neither is
		// measurable from the desktop render — and dropping them here silently
		// un-hid a lane the source hides. Carry them across positionally.
		const declaredAt = declared?.[ index ];
		if ( declaredAt ) {
			if ( declaredAt.widthTablet !== undefined ) {
				column.widthTablet = declaredAt.widthTablet;
			}
			if ( declaredAt.widthMobile !== undefined ) {
				column.widthMobile = declaredAt.widthMobile;
			}
			if ( declaredAt.reverseMobile ) {
				column.reverseMobile = true;
			}
			if ( declaredAt.reverseTablet ) {
				column.reverseTablet = true;
			}
			if ( declaredAt.hiddenMobile ) {
				column.hiddenMobile = true;
			}
			if ( declaredAt.hiddenTablet ) {
				column.hiddenTablet = true;
			}
			if ( declaredAt.hiddenDesktop ) {
				column.hiddenDesktop = true;
			}
		}
		return column;
	} );
}

/**
 * Reconcile an EXPLICIT `section.padding` fact with the per-viewport
 * measurement.
 *
 * The explicit fact wins by policy, but it is a single DESKTOP shorthand: a
 * string is emitted inline (see `wrapStyle`), and an inline `padding` outranks
 * the media queries that would narrow it. virtuallysrilanka's hardware band
 * measures `100px 0 0 0` at desktop and flush (`0`) at both narrow viewports,
 * but the profile pins the desktop shorthand — so the rebuild carried the 100px
 * down to tablet and mobile and ran 161px / 233px taller than its source.
 *
 * Keep the explicit value for the viewport it describes and take the narrower
 * overrides from the measurement, only where they actually differ. An explicit
 * per-device map is already responsive and is returned untouched.
 */
export function resolveBandPadding(
	explicit: ResponsiveLength | undefined,
	measured: ResponsiveLength | undefined
): ResponsiveLength | undefined {
	if ( ! explicit ) {
		return measured;
	}
	if ( typeof explicit !== 'string' || ! measured || typeof measured === 'string' ) {
		return explicit;
	}
	const resolved: { desktop: string; tablet?: string; mobile?: string } = { desktop: explicit };
	if ( measured.tablet && measured.tablet !== explicit ) {
		resolved.tablet = measured.tablet;
	}
	if ( measured.mobile && measured.mobile !== explicit ) {
		resolved.mobile = measured.mobile;
	}
	return resolved.tablet || resolved.mobile ? resolved : explicit;
}

/**
 * Add a container's BLOCK padding onto a band's own padding fact.
 *
 * The builder often puts the copy's top/bottom inset on the inner container
 * (`e-con-inner { padding: 60px 0 80px }`) and leaves the band's own padding at
 * zero, so a rebuild that reads only the band's padding starts the copy 60–80px
 * high and every band below it shifts up (eca.directory's about-us benefits band
 * measured an ink top of 88px against the rebuild's 5, 2026-11-25). The shorthand
 * is normalised to four sides, the two block values are summed and re-emitted.
 */
export function addBlockPadding(
	padding: ResponsiveLength | undefined,
	block: { top?: number; bottom?: number } | undefined
): ResponsiveLength | undefined {
	const top = Math.round( block?.top ?? 0 );
	const bottom = Math.round( block?.bottom ?? 0 );
	if ( top <= 0 && bottom <= 0 ) {
		return padding;
	}
	const bump = ( value: string | undefined ): string => {
		if ( typeof value !== 'string' || value.trim() === '' ) {
			return `${ top }px 0px ${ bottom }px 0px`;
		}
		const parts = value.trim().split( /\s+/ );
		const four =
			parts.length === 1
				? [ parts[ 0 ], parts[ 0 ], parts[ 0 ], parts[ 0 ] ]
				: parts.length === 2
					? [ parts[ 0 ], parts[ 1 ], parts[ 0 ], parts[ 1 ] ]
					: parts.length === 3
						? [ parts[ 0 ], parts[ 1 ], parts[ 2 ], parts[ 1 ] ]
						: parts.slice( 0, 4 );
		const add = ( token: string, extra: number ): string => {
			const match = /^(\d+(?:\.\d+)?)px$/.exec( token );
			return match ? `${ Math.round( Number( match[ 1 ] ) + extra ) }px` : token;
		};
		return [ add( four[ 0 ], top ), four[ 1 ], add( four[ 2 ], bottom ), four[ 3 ] ].join( ' ' );
	};
	if ( typeof padding === 'string' || padding === undefined ) {
		return bump( padding );
	}
	return {
		desktop: bump( padding.desktop ),
		...( padding.tablet ? { tablet: bump( padding.tablet ) } : {} ),
		...( padding.mobile ? { mobile: bump( padding.mobile ) } : {} ),
	};
}

function measuredBandPadding(
	section: ForgeSection,
	geometry?: ForgeGeometry
): ResponsiveLength | undefined {
	if ( ! section.id || ! geometry ) {
		return undefined;
	}
	const box = geometry[ section.id ];
	if ( ! box ) {
		return undefined;
	}
	const shorthand = ( viewport: string ): string | undefined => {
		const band = box[ viewport ];
		if ( ! band ) {
			return undefined;
		}
		// A band the measurement could not box carries no padding fields; emitting
		// `Math.round( undefined )` wrote `NaNpx` into the theme (invalid CSS the
		// browser drops silently) — a fixture caught it, a partial page measurement
		// would have shipped it.
		if ( ! Number.isFinite( band.paddingTop ) || ! Number.isFinite( band.paddingBottom ) ) {
			return undefined;
		}
		const top = Math.round( band.paddingTop );
		const right = Math.round( band.paddingRight ?? 0 );
		const bottom = Math.round( band.paddingBottom );
		const left = Math.round( band.paddingLeft ?? 0 );
		if ( top === 0 && right === 0 && bottom === 0 && left === 0 ) {
			// A band measured FLUSH is a fact, not an absence: `padding: 0`
			// overrides the template's own rhythm, which a missing value leaves
			// in place (virtuallyjamaica's hero is `no-padding-tb` and rendered
			// with 32px of template padding the source never had).
			return '0';
		}
		return `${ top }px ${ right }px ${ bottom }px ${ left }px`;
	};
	const desktop = shorthand( 'desktop' );
	if ( ! desktop ) {
		return undefined;
	}
	const tablet = shorthand( 'tablet' );
	const mobile = shorthand( 'mobile' );
	if ( tablet === desktop && mobile === desktop ) {
		return desktop;
	}
	return {
		desktop,
		...( tablet ? { tablet } : {} ),
		...( mobile ? { mobile } : {} ),
	};
}

/**
 * Collapse a per-device length to a plain string when no device that was
 * actually measured disagrees with desktop.
 *
 * An ABSENT device is not a disagreement: the desktop rule is unconditional, so
 * `{ desktop: '1170px' }` and `'1170px'` emit the same value — the plain form
 * just stays inline and needs no style-block entry.
 */
function collapseDevice( desktop?: string, tablet?: string, mobile?: string ): ResponsiveLength | undefined {
	if ( ! desktop ) {
		return undefined;
	}
	const differs = ( value?: string ): boolean => value !== undefined && value !== desktop;
	if ( ! differs( tablet ) && ! differs( mobile ) ) {
		return desktop;
	}
	return { desktop, ...( tablet ? { tablet } : {} ), ...( mobile ? { mobile } : {} ) };
}

/**
 * The band's inner container, per device (`ForgeBandFacts.container`).
 *
 * Two lengths are needed and neither is a constant. `measure` is the container's
 * BORDER-BOX width, because the rebuild's `max-width` includes the padding;
 * `paddingX` is the container's own side gutter. Bootstrap (15px), Elementor
 * (10px) and Brando (30px) differ from each other, and each differs across
 * viewports: the old `containerWidth + 64` compensated for a 32px desktop gutter
 * at 390px, where the template's own gutter is 16, and the fluid branch set 0px
 * where the source had 15 — so a fluid band rendered 30px wider than its source
 * on a phone, and wrapped differently.
 *
 * A profile analysed before the per-device fact existed falls back to that old
 * desktop-derived compensation, so re-scaffolding an old profile is unchanged.
 */
function measuredContainer(
	bandFacts: ForgeBandFacts | undefined,
	fallbackPadX?: ResponsiveLength,
	measured?: Record< string, BandGeometry >
): { measure?: ResponsiveLength; paddingX?: ResponsiveLength; blockPad?: { top: number; bottom: number } } {
	const at = ( device: 'desktop' | 'tablet' | 'mobile' ): { measure: string; padX: string; padTop?: number; padBottom?: number } | undefined => {
		const entry = bandFacts?.container?.[ device ];
		if ( entry && entry.content > 0 ) {
			// 0 is a fact (a genuinely flush container), not an absence — it must
			// override the template's clamp, exactly as the band padding does.
			const padX = Number.isFinite( entry.padX ) && entry.padX > 0 ? entry.padX : 0;
			// A band whose grid cancels the gutter renders FLUSH so its columns span the
			// container's border-box; the per-column padding is already in the `box`
			// facts. Applying `padX` there would re-inset a band the source lets bleed
			// (virtuallyjamaica's #wifi grew 29px taller when it was narrowed by 30px).
			const sectionPadX = entry.flush ? 0 : padX;
			return {
				measure: `${ Math.round( entry.content + padX * 2 ) }px`,
				padX: `${ Math.round( sectionPadX * 100 ) / 100 }px`,
				...( entry.padTop ? { padTop: entry.padTop } : {} ),
				...( entry.padBottom ? { padBottom: entry.padBottom } : {} ),
			};
		}
		// The BAND's own measured content column.
		//
		// Builder 4.x containers (`e-con` / `e-con-inner`) are not `.container`
		// or `.row`, so `bandFacts.container` is absent and `containerWidth`
		// below reports the band's PADDING box instead — eca.directory's hero
		// rebuilt 1380px wide against a 1280px source container, and every day
		// page 1380 against 1328 (2026-11-24). The measured content box is the
		// column the copy actually occupies, which is exactly what
		// `--ds-measure` means, so it replaces the gutter guess.
		const box = measured?.[ device ];
		const contentWidth = box?.content?.width;
		if ( typeof contentWidth === 'number' && contentWidth > 0 && typeof box?.width === 'number' ) {
			const available = box.width - ( box.paddingLeft ?? 0 ) - ( box.paddingRight ?? 0 );
			// A partial slab reports the SLAB, and constraining a band to it would
			// re-centre a surface the source paints flush; `theme.panel` already
			// carries that case. Below 60% of the row it is a slab, not a container.
			//
			// POSITION is the other half of the test: a container is CENTRED in its
			// band, a slab is not. eca.directory's contact form is a 924px white slab
			// starting at x=516 (not the 258 a centred 924 box would start at), and
			// constraining the band to it squeezed the photo lane away (2026-11-25).
			const offset = typeof box.content?.x === 'number' ? box.content.x : 0;
			const centred = Math.abs( offset - ( box.width - contentWidth ) / 2 ) <= 8;
			if ( available > 0 && centred && contentWidth >= available * 0.6 && contentWidth <= available + 1 ) {
				return {
					measure: `${ Math.round( contentWidth ) }px`,
					padX: '0px',
					...( box.content?.padTop ? { padTop: box.content.padTop } : {} ),
					...( box.content?.padBottom ? { padBottom: box.content.padBottom } : {} ),
				};
			}
		}
		return undefined;
	};
	const desktop = at( 'desktop' );
	if ( desktop ) {
		const tablet = at( 'tablet' );
		const mobile = at( 'mobile' );
		const blockPad =
			desktop.padTop || desktop.padBottom
				? { top: desktop.padTop ?? 0, bottom: desktop.padBottom ?? 0 }
				: undefined;
		return {
			measure: collapseDevice( desktop.measure, tablet?.measure, mobile?.measure ),
			paddingX: collapseDevice( desktop.padX, tablet?.padX, mobile?.padX ),
			...( blockPad ? { blockPad } : {} ),
		};
	}
	const width = bandFacts?.containerWidth;
	if ( typeof width !== 'number' || width <= 0 ) {
		return {};
	}
	// `containerWidth` is the CONTENT width (the container's own padding is
	// subtracted at measurement). The rebuild's measure is the BORDER-BOX width,
	// so the gutter it will apply must be added back. A known analyzed `paddingX`
	// states that gutter exactly; the legacy constant approximates the template's
	// clamp (~32px per side) when the source reports none.
	if ( typeof fallbackPadX === 'string' && /^\d+(?:\.\d+)?px$/.test( fallbackPadX.trim() ) ) {
		const pad = Number.parseFloat( fallbackPadX );
		return { measure: `${ Math.round( width + pad * 2 ) }px`, paddingX: fallbackPadX };
	}
	if ( width >= FLUID_CONTAINER_MIN ) {
		// A FLUID band has no separate container: `containerWidth` IS the band’s own
		// inner width, so the +64 gutter compensation would push the measure past
		// the viewport and leave the copy flush to the edge (eca.directory’s
		// /about-us/ title band measured 1504px in a 1440px viewport, 2026-11-24).
		return { measure: `${ Math.round( width ) }px`, paddingX: '0px' };
	}
	return {
		measure: `${ Math.round( width ) + BAND_GUTTER_PX }px`,
	};
}

/**
 * One `SectionTheme` entry — shared by the homepage module and each page’s
 * positional list (`renderPagesGenerated`). Every fact is read from the
 * `profile` passed in, so a per-page view (its own `bandFacts`/`geometry`/
 * builder) renders through exactly the same emitter as the front page.
 */
/**
 * The ink a captured ELEMENT surface needs.
 *
 * A raw band's copy is painted by the band's own ink, but a card inside it is a
 * different surface: monicarajiv's tiles are `rgb(240,240,240)`, and the band's
 * white ink left every word invisible on them. Whichever of black/white reads
 * better against the captured colour wins.
 */
function elementInk( bg: string | undefined ): string | undefined {
	const prefix = 'background-color:';
	if ( ! bg || ! bg.startsWith( prefix ) ) {
		return undefined;
	}
	return readableInk( bg.slice( prefix.length ) );
}

/**
 * A canonical `r,g,b` key for a CSS colour (hex or an opaque `rgb()`), so a
 * MEASURED element fill can be compared with the band's own surface. `undefined`
 * for anything else (a translucent fill, a gradient, a keyword) — those never
 * compare equal, which is the safe direction.
 */
function colourKey( value: string | undefined ): string | undefined {
	if ( ! value ) {
		return undefined;
	}
	const hex = value.trim().match( /^#([0-9a-f]{3}|[0-9a-f]{6})$/i );
	if ( hex ) {
		const digits = hex[ 1 ].length === 3 ? hex[ 1 ].split( '' ).map( ( c ) => c + c ).join( '' ) : hex[ 1 ];
		const n = Number.parseInt( digits, 16 );
		return `${ ( n >> 16 ) & 255 },${ ( n >> 8 ) & 255 },${ n & 255 }`;
	}
	const rgb = value.trim().match( /^rgba?\(\s*([0-9.]+)[\s,]+([0-9.]+)[\s,]+([0-9.]+)\s*(?:[,/]\s*([0-9.]+)\s*)?\)$/i );
	if ( ! rgb ) {
		return undefined;
	}
	const alpha = rgb[ 4 ] === undefined ? 1 : Number( rgb[ 4 ] );
	if ( ! ( alpha >= 0.95 ) ) {
		return undefined;
	}
	return `${ Math.round( Number( rgb[ 1 ] ) ) },${ Math.round( Number( rgb[ 2 ] ) ) },${ Math.round( Number( rgb[ 3 ] ) ) }`;
}

function renderSectionThemeEntry( section: ForgeSection, profile: SiteProfile ): string {
	const theme = resolveSectionTheme( section );
	const entry: string[] = [];
	if ( section.id ) {
		entry.push( `id: ${ tsString( section.id ) }` );
	}
	if ( section.kind ) {
		entry.push( `kind: ${ tsString( section.kind ) }` );
	}
	entry.push( `theme: ${ tsString( theme ) }` );
	if ( section.anchor ) {
		entry.push( `anchor: ${ tsString( section.anchor ) }` );
	}
	if ( theme === 'hero' ) {
		if ( section.bgImage ) {
			entry.push( `bgImage: ${ tsString( section.bgImage ) }` );
		}
		// The hero tint (the section's own color shows through the photo).
		if ( section.bgColor && section.bgColor !== 'transparent' ) {
			entry.push( `bg: ${ tsString( section.bgColor ) }` );
		}
		if ( section.overlay && section.overlay !== 'transparent' ) {
			entry.push( `overlay: ${ tsString( section.overlay ) }` );
		}
	} else {
		const bg = section.bgGradient ?? section.bgColor;
		if ( bg && bg !== 'transparent' ) {
			entry.push( `bg: ${ tsString( bg ) }` );
		}
		// A LAYER image rides a non-hero band too (`bandLayerCss` renders it on a
		// faded `::before`, and `wrapStyle` leaves it off the wrap). A full-strength
		// band photo stays hero-only, as before.
		if ( section.bgImage && section.bgLayerOpacity !== undefined ) {
			entry.push( `bgImage: ${ tsString( section.bgImage ) }` );
		}
	}
	// The image's BOX (`ForgeSection.bgSize`). Emitted for every theme, hero or
	// not: an image without it paints at native scale.
	for ( const key of [ 'bgSize', 'bgRepeat', 'bgPosition' ] as const ) {
		const value = section[ key ];
		if ( value ) {
			entry.push( `${ key }: ${ tsString( value ) }` );
		}
	}
	if ( section.bgLayerOpacity !== undefined ) {
		entry.push( `bgLayerOpacity: ${ section.bgLayerOpacity }` );
	}
	if ( section.bgBlendMode ) {
		entry.push( `bgBlendMode: ${ tsString( section.bgBlendMode ) }` );
	}
	// The band's MARK STRIP (analyzer-measured logo wall — see
	// `ForgeSection.logos`). Emitted for every theme: a logo wall is as often a
	// transparent band as a light one.
	if ( section.logos && section.logos.length > 0 ) {
		entry.push( `logos: ${ JSON.stringify( section.logos ) }` );
	}
	// The band's COMPARISON MATRIX (analyzer-measured from the RAW html — see
	// `ForgeSection.comparison`). The build-time classifier cannot see it because
	// sanitisation strips the check/cross `<svg>`, so the renderer appends it from
	// this fact instead of trying to detect it.
	if ( section.comparison && section.comparison.rows.length > 0 ) {
		entry.push( `comparison: ${ JSON.stringify( section.comparison ) }` );
	}
	// MEASURED STRUCTURE — the generic channel (see `ForgeSection.structure`).
	// A profile recorded before the channel existed carries only `comparison`, so
	// synthesise the entry rather than emitting two shapes the renderer must know.
	const structure =
		section.structure && section.structure.length > 0
			? section.structure
			: section.comparison && section.comparison.rows.length > 0
				? [ { type: 'comparison' as const, data: section.comparison } ]
				: [];
	if ( structure.length > 0 ) {
		entry.push( `structure: ${ JSON.stringify( structure ) }` );
	}
	// The band's PANEL surface (`ForgeSection.panel`) — a literal colour + its
	// legible ink, so the item group renders on the source's slab.
	const bandFacts = section.id ? profile.bandFacts?.[ section.id ] : undefined;
	// The band's SLAB. `design.ts` records it on the SECTION for the homepage;
	// the geometry pass records it as a BAND fact, which is the only path a
	// sub-page takes. ONE emission: this block used to be three, and a band that
	// carried both shapes emitted `panel:` twice in the same object literal.
	const panel =
		bandFacts?.panel && ( bandFacts.panel.width || bandFacts.panel.align )
			? bandFacts.panel
			: section.panel ?? bandFacts?.panel;
	if ( panel ) {
		// A slab that fills only PART of the band carries its own box: the source
		// halves the band and centres the heading on that half, so the rebuild has
		// to constrain the section — a full-bleed slab centred the eyebrow on the
		// viewport instead (virtuallysrilanka's "How it Works").
		const box = [
			panel.width && `width: ${ tsString( panel.width ) }`,
			panel.align && `align: ${ tsString( panel.align ) }`,
		]
			.filter( Boolean )
			.join( ', ' );
		entry.push(
			`panel: { bg: ${ tsString( panel.bg ) }, ink: ${ tsString( panel.ink ) }${ box ? `, ${ box }` : '' } }`
		);
	}
	// The band's measured CAROUSEL geometry — the slider's own slides-per-view and
	// gutter. Without it a logo wall that the source ROTATES renders as a static
	// row (virtuallysrilanka's hardware band is a 7-slide Swiper, and the rebuild
	// drew all seven in one row). `bandFacts` is declared above this point.
	const carouselFact = bandFacts?.carousel?.desktop?.[ 0 ];
	if ( carouselFact && ( carouselFact.perView ?? 0 ) > 1 ) {
		entry.push( `carousel: ${ JSON.stringify( carouselFact ) }` );
	}
	// The measured EYEBROW and TITLE text. The classifier reads tag level alone,
	// so a kicker longer than its six-word heuristic is promoted to title and the
	// real title demoted to body copy (virtuallysrilanka's hardware band). The
	// browser measurement is the only pass that can see the sizes.
	if ( bandFacts?.eyebrow ) {
		// The EYEBROW's ink gets the same readability guard as the band type.
		//
		// A builder paints its kicker as white type on a coloured pill, and the
		// rebuild has no pill — the vendor rule that filled it never ships — so
		// the measured white landed on a light band and the eyebrow vanished
		// (eca.directory's /about-us/: "Our Benefits" was invisible, 2026-11-24).
		const eyebrow = { ...bandFacts.eyebrow };
		if ( eyebrow.color ) {
			const ratio = contrast( eyebrow.color, bandSurface( section, profile ) );
			if ( ratio !== undefined && ratio < INVISIBLE_INK_RATIO ) {
				delete eyebrow.color;
			}
		}
		entry.push( `eyebrow: ${ JSON.stringify( eyebrow ) }` );
	}
	if ( bandFacts?.titleText ) {
		entry.push( `titleText: ${ tsString( bandFacts.titleText ) }` );
	}
	// The container measure + side gutter, MEASURED per device (114 §3.6). An
	// explicit profile fact wins; otherwise the source's own container decides.
	const container = measuredContainer( bandFacts, section.paddingX, section.id ? profile.geometry?.[ section.id ] : undefined );
	// The band's PADDING: its own fact plus the container's block padding (see
	// `addBlockPadding`). Emitted here because the container fact is read above.
	const padding = addBlockPadding(
		resolveBandPadding( section.padding, measuredBandPadding( section, profile.geometry ) ),
		container.blockPad
	);
	if ( padding ) {
		entry.push( `padding: ${ tsLength( padding ) }` );
	}
	// An analyzed `section.measure` can be the THEME's container width applied to
	// a FULL-WIDTH band (jayainc's services band: the CSS says 1140, the live
	// `.elementor-container` is 1440, so its columns rendered 524 instead of the
	// source's 660 and the pinned height showed 255px of dead space). The
	// measured container is the truth when it is WIDER; a NARROWER measurement
	// means the CSS cap is real (a boxed 1140 band measures 1140).
	const pxWidth = ( value?: ResponsiveLength ): number | undefined => {
		// A per-device map is the normal shape (the container is 1440 on desktop,
		// the viewport at tablet/mobile); compare its DESKTOP value.
		const text = typeof value === 'string' ? value : value?.desktop;
		return typeof text === 'string' && /^\d+(?:\.\d+)?px$/.test( text.trim() ) ? Number.parseFloat( text ) : undefined;
	};
	const sectionWidth = pxWidth( section.measure );
	const containerWidth = pxWidth( container.measure );
	// An operator PIN on the band's measure always wins: it is the escape hatch
	// for a band whose content is capped narrower than its container (the
	// contact band: the container is full-width, the form inside is not).
	const pinnedMeasure = ( profile.pins ?? [] ).some( ( pin ) => pin.path === `sections.${ section.id }.measure` );
	const measure = pinnedMeasure
		? section.measure ?? container.measure
		: sectionWidth !== undefined && containerWidth !== undefined && containerWidth > sectionWidth
			? container.measure
			: section.measure ?? container.measure;
	if ( measure ) {
		entry.push( `measure: ${ tsLength( measure ) }` );
	}
	const paddingX = section.paddingX ?? container.paddingX;
	if ( paddingX ) {
		entry.push( `paddingX: ${ tsLength( paddingX ) }` );
	}
	if ( section.marginTop ) {
		entry.push( `marginTop: ${ tsLength( section.marginTop ) }` );
	}
	if ( section.marginBottom ) {
		entry.push( `marginBottom: ${ tsLength( section.marginBottom ) }` );
	}
	if ( section.zIndex ) {
		entry.push( `zIndex: ${ tsString( section.zIndex ) }` );
	}
	// The band's MEASURED source height (114 §3.6). Builder sources declare
	// band heights through classes (`full-screen`, `min-height-800`) or an
	// inline min-height the sanitizer drops, so the only faithful source is
	// the browser measurement. Emitted as a floor — a band whose rebuilt
	// content is already taller keeps its own height. Per device, because a
	// band that stacks at tablet/mobile is taller there than on desktop.
	const box = section.id ? profile.geometry?.[ section.id ] : undefined;
	// A decoration-only band is a spacer: its measured height IS its content, so
	// the strip threshold does not apply (jayainc's dff3f1a renders a lone
	// Elementor spacer and collapsed to 0px, shifting every band below it).
	const minimumHeight = contentlessBand( bandFacts ) ? 1 : MIN_MIRRORED_BAND_HEIGHT;
	// A band measured at EXACTLY the viewport height on every device is a
	// full-SCREEN stage (Brando's `.full-screen`, Elementor's `100vh` hero).
	// The measured pixels are that device's viewport at capture time, so
	// re-emitting them freezes the stage: a 900px band is right on a 1440×900
	// laptop and overflows on a 1440×800 window, where the source still fits
	// the screen and centres its copy. Emit the semantic height instead; the
	// renderer adds `ds-section-wrap--full`, whose height chain fills the
	// viewport and lets the copy track centre (see global.css).
	const bandHeight = ( device: string ): number | undefined => {
		const height = box?.[ device ]?.height;
		return typeof height === 'number' ? height : undefined;
	};
	const fullViewport = [ 'desktop', 'tablet', 'mobile' ].every( ( device ) => {
		const height = bandHeight( device );
		const viewport = DEFAULT_GEOMETRY_VIEWPORTS.find( ( entry ) => entry.name === device )?.height;
		return height !== undefined && viewport !== undefined && Math.abs( height - viewport ) <= 2;
	} );
	const isFullViewport = section.fullViewport ?? fullViewport;
	// An explicit profile fact (or an operator `sections.<id>.minHeight` pin)
	// wins over the measurement — the same contract as `section.padding` above.
	// The measured box stays provenance; the authored floor is the operator's
	// call (jayainc's catalogue band is one control in a 520px measured box).
	const explicitHeight = section.minHeight;
	if ( isFullViewport ) {
		entry.push( 'fullViewport: true' );
	}
	if ( explicitHeight ) {
		entry.push( `minHeight: ${ tsLength( explicitHeight ) }` );
	} else if ( isFullViewport ) {
		entry.push( `minHeight: ${ tsString( '100vh' ) }` );
	}
	const heightOf = ( viewport: string ): string | undefined => {
		const height = box?.[ viewport ]?.height;
		return typeof height === 'number' && height >= minimumHeight
			? `${ Math.round( height ) }px`
			: undefined;
	};
	const desktopHeight = isFullViewport || Boolean( explicitHeight ) ? undefined : heightOf( 'desktop' );
	if ( desktopHeight ) {
		const tabletHeight = heightOf( 'tablet' );
		const mobileHeight = heightOf( 'mobile' );
		const value =
			tabletHeight === desktopHeight && mobileHeight === desktopHeight
				? desktopHeight
				: {
						desktop: desktopHeight,
						...( tabletHeight ? { tablet: tabletHeight } : {} ),
						...( mobileHeight ? { mobile: mobileHeight } : {} ),
					};
		entry.push( `minHeight: ${ tsLength( value ) }` );
	}
	// The band's measured typography (see `tsBandType`). The readability
	// fallback applies to the MEASURED facts only; an operator pin on
	// `design.sections[].type` then overlays them field by field (see
	// `mergeBandType`) — an explicit ink choice is never silently dropped.
	const measuredType = readableBandType( bandFacts ?? {}, section, profile );
	const bandType = mergeBandType( measuredType, section.type );
	const typeLiteral = tsBandType( bandType );
	if ( typeLiteral ) {
		entry.push( `type: ${ typeLiteral }` );
	}
	// The ink the band's CARDS paint their copy in (see `cardInkFor`). Emitted
	// only when the band and the card share a surface, so the template's blanket
	// `.ds-card p` rule is corrected exactly where it is wrong and nowhere else.
	const cardInk = cardInkFor( section, bandType, profile );
	if ( cardInk ) {
		entry.push( `cardInk: ${ tsString( cardInk ) }` );
	}
	// The subtrees the source hides at rest (see `signaturesHiddenAtBoth`).
	const hidden = section.hidden ?? bandFacts?.hidden ?? [];
	if ( hidden.length > 0 ) {
		entry.push( `hidden: [${ hidden.map( ( selector ) => tsString( selector ) ).join( ', ' ) }]` );
	}
	// The source's crop anchor for this band's cover photos (an operator pin
	// wins over the measurement).
	const mediaPosition = section.mediaPosition ?? bandFacts?.mediaPosition;
	if ( mediaPosition ) {
		entry.push( `mediaPosition: ${ tsString( mediaPosition ) }` );
	}
	// The hero track's measured column split (see `collectBandSplitFacts`) —
	// the ratio lives in the vendor stylesheet and the markup carries no width
	// class, so a guessed ratio changes the copy column's line breaks.
	const split = section.split ?? bandFacts?.splitColumns;
	if ( split && split.length >= 2 ) {
		entry.push( `split: [${ split.map( ( value ) => String( value ) ).join( ', ' ) }]` );
	}
	// The source's own vertical-centring device (see `collectBandLayoutFacts`).
	// A measured `false` is emitted so a full-height band the source TOP-aligns
	// (virtuallyjamaica's contact band) can opt out of the hero default; an
	// absent fact (an older profile, an unmeasured page) keeps the default.
	const centered = section.centered ?? bandFacts?.centeredContent;
	if ( centered === true ) {
		entry.push( 'centered: true' );
	} else if ( centered === false ) {
		entry.push( 'centered: false' );
	}
	// The builder's presentation utilities this band actually uses. The
	// sanitizer keeps the class and the vendor sheet never ships, so an
	// unstated utility is inert (a `text-center` step rendered left-aligned).
	// Trimmed by the measured class list, never emitted wholesale.
	const utilities = builderUtilitiesFor( profile.design?.builder, bandFacts?.classes );
	const utilityEntries = Object.entries( utilities );
	if ( utilityEntries.length > 0 ) {
		entry.push(
			`utilities: { ${ utilityEntries
				.map( ( [ name, utility ] ) => `${ tsKey( name ) }: ${ tsUtility( utility ) }` )
				.join( ', ' ) } }`
		);
	}
	// The MEASURED box of the classes this band keeps (see
	// `collectBandBoxFacts`). The builder's numeric spacing scale cannot be
	// expressed as a static class table — `padding-four-all` is 3.4rem on
	// desktop and `40px 15.5938px` at 390px — so the computed box is re-stated
	// verbatim. This is what keeps a raw-HTML band's column padding and its
	// cropped media box faithful at every device.
	const boxFacts = bandFacts?.box;
	const boxEntries = Object.entries( boxFacts ?? {} );
	if ( boxEntries.length > 0 ) {
		entry.push(
			`box: { ${ boxEntries
				.map( ( [ name, box ] ) => `${ tsKey( name ) }: ${ tsUtility( box ) }` )
				.join( ', ' ) } }`
		);
	}
	// Cropped-media heights, emitted as `img.<class>` only — see the split in
	// `collectBandBoxFacts` (a class on both an image and a paragraph).
	const mediaEntries = Object.entries( bandFacts?.media ?? {} );
	if ( mediaEntries.length > 0 ) {
		entry.push(
			`media: { ${ mediaEntries
				.map( ( [ name, media ] ) => `${ tsKey( name ) }: ${ tsUtility( media ) }` )
				.join( ', ' ) } }`
		);
	}
	// Per-ELEMENT surfaces + pinned stage heights (see `collectBandElementFacts`),
	// keyed by a structural path because a class name repeats across bands. Raw
	// bands keep the source markup verbatim, so the path resolves to the same
	// node in the rebuild.
	const elementFacts = section.elements ?? bandFacts?.elements ?? [];
	if ( elementFacts.length > 0 ) {
		entry.push(
			`elements: [ ${ elementFacts
				.map( ( element ) => {
					const parts = [ `path: ${ tsString( element.path ) }` ];
					if ( element.bg ) {
						parts.push( `bg: ${ tsString( element.bg ) }` );
						// A stage that IS the band's own surface must not re-derive its ink.
						// The derived black/white is a FALLBACK for a card whose own fill the
						// band ink was never measured against — but the rule it emits
						// (`… :is(h1…h6,p,li,span)`, 0,3,3) outranks the band's MEASURED type
						// facts (`[data-section-id] h3`, 0,1,1). On krishandramikasavethe.world
						// the hero's own `#f0f0f0` banner derived black and repainted a
						// `#578050` heading and `#4a4a4a` body (2 colour-gate failures,
						// 2026-11-19). Equal fill → the measured ink is the faithful copy.
						const fill = colourKey( element.bg.slice( 'background-color:'.length ) );
						const bandFill = colourKey( section.bgColor );
						const ink =
							fill !== undefined && bandFill !== undefined && fill === bandFill
								? undefined
								: elementInk( element.bg );
						if ( ink ) {
							parts.push( `fg: ${ tsString( ink ) }` );
						}
					}
					// A translucent VEIL (Flatsome's `.overlay`) stacks over the surface
					// instead of replacing it, so it is emitted as its own declaration.
					if ( element.veil ) {
						parts.push( `veil: ${ tsString( element.veil ) }` );
					}
					if ( typeof element.height === 'number' ) {
						parts.push( `height: ${ element.height }` );
					} else if ( element.height ) {
						const map = Object.entries( element.height )
							.filter( ( [ , value ] ) => typeof value === 'number' )
							.map( ( [ device, value ] ) => `${ device }: ${ value }` );
						if ( map.length > 0 ) {
							parts.push( `height: { ${ map.join( ', ' ) } }` );
						}
					}
					// The measured WIDTH (a builder sizes images on the wrapper, not on
					// the `width="1"` attribute the markup carries).
					if ( typeof element.width === 'number' ) {
						parts.push( `width: ${ element.width }` );
					} else if ( element.width ) {
						const widthMap = Object.entries( element.width )
							.filter( ( [ , value ] ) => typeof value === 'number' )
							.map( ( [ device, value ] ) => `${ device }: ${ value }` );
						if ( widthMap.length > 0 ) {
							parts.push( `width: { ${ widthMap.join( ', ' ) } }` );
						}
					}
					return `{ ${ parts.join( ', ' ) } }`;
				} )
				.join( ', ' ) } ]`
		);
	}
	// The band's measured COLUMN geometry (see `collectBandColumnFacts`): the
	// rendered height floor and a fixed photo's attachment/size, scoped to the
	// column's own class. The sanitizer keeps the class but drops the inline
	// min-height, and the vendor sheet never ships — without this the image
	// column collapses to its content and its "fixed" photo scrolls.
	const innerEntries = Object.entries( columnInnerFacts( bandFacts?.columns ?? {} ) );
	if ( innerEntries.length > 0 ) {
		entry.push(
			`columnInner: { ${ innerEntries
				.map( ( [ name, box ] ) => `${ tsKey( name ) }: ${ tsUtility( box ) }` )
				.join( ', ' ) } }`
		);
	}
	const columnEntries = Object.entries( columnBoxFacts( bandFacts?.columns ?? {} ) );
	if ( columnEntries.length > 0 ) {
		entry.push(
			`columnBox: { ${ columnEntries
				.map( ( [ name, column ] ) => `${ tsKey( name ) }: ${ tsUtility( column ) }` )
				.join( ', ' ) } }`
		);
	}
	// The band's measured CAROUSELS (see `collectBandCarouselFacts`): slides per
	// view, the gutter and the track's own scroll behaviour, scoped to the
	// carousel's class. The vendor slider's `items` option lives nowhere else, so
	// without this every gallery rebuilds as an equal split of the track —
	// 7 slides at 129px each where the source shows 5 at 222px.
	const carouselEntries = Object.entries( carouselBoxFacts( bandFacts?.carousel ?? {} ) );
	if ( carouselEntries.length > 0 ) {
		entry.push(
			`carouselBox: { ${ carouselEntries
				.map( ( [ name, carousel ] ) => `${ tsKey( name ) }: ${ tsUtility( carousel ) }` )
				.join( ', ' ) } }`
		);
	}
	// The controls' measured STATE deltas (see `measureInteractions`). The
	// vendor sheet never ships, so a rebuilt control loses every hover/focus/
	// active effect the source expressed in CSS. The SELECTOR is baked here
	// (not re-derived in the template) so escaping lives in one place.
	const interactionEntries = section.interactions ?? bandFacts?.interactions ?? [];
	if ( interactionEntries.length > 0 ) {
		entry.push(
			`interactions: [${ interactionEntries
				.map( ( fact ) => {
					const fields = [ `selector: ${ tsString( interactionSelector( fact ) ) }` ];
					if ( typeof fact.count === 'number' && fact.count > 1 ) {
						fields.push( `count: ${ fact.count }` );
					}
					for ( const key of [ 'base', 'transition', 'hover', 'focus', 'focusVisible', 'active' ] as const ) {
						if ( fact[ key ] ) {
							fields.push( `${ key }: ${ tsString( fact[ key ] as string ) }` );
						}
					}
					// Overlay children: the states a child draws while the control is
					// hovered (`.aux-overlay`), emitted as `<control>:hover > <child>`.
					if ( fact.children && fact.children.length > 0 ) {
						fields.push(
							`children: [${ fact.children
								.map( ( child ) => {
									const childFields = [ `selector: ${ tsString( child.selector ) }` ];
									for ( const key of [ 'base', 'pseudo', 'transition', 'hover', 'focus', 'focusVisible', 'active' ] as const ) {
										if ( child[ key ] ) {
											childFields.push( `${ key }: ${ tsString( child[ key ] as string ) }` );
										}
									}
									return `{ ${ childFields.join( ', ' ) } }`;
								} )
								.join( ', ' ) }]`
						);
					}
					return `{ ${ fields.join( ', ' ) } }`;
				} )
				.join( ', ' ) }]`
		);
	}
	// The MEASURED lanes win over the profile's declared columns. The declaration
	// states the builder's INTENT (`col-33` on all three), while the measurement
	// holds what the browser actually renders: the real widths (virtuallysrilanka's
	// services row is 432 / 432 / 571, not equal thirds) and the surfaces only a
	// browser can see — a builder scopes the slab to a nested wrapper, which is
	// how the black third column and its photo column went unrecorded.
	const measuredColumns = measuredLaneColumns( bandFacts, section.columns, box?.desktop?.content?.width );
	if ( measuredColumns ) {
		const lanes = measuredColumns.map( ( column ) => {
			const parts: string[] = [];
			if ( column.width !== undefined ) {
				parts.push( `width: ${ column.width }` );
			}
			if ( column.bgColor ) {
				parts.push( `bgColor: ${ tsString( column.bgColor ) }` );
			}
			if ( column.bgImage ) {
				parts.push( `bgImage: ${ tsString( column.bgImage ) }` );
			}
			if ( column.ink ) {
				parts.push( `ink: ${ tsString( column.ink ) }` );
			}
			if ( column.widthTablet !== undefined ) {
				parts.push( `widthTablet: ${ column.widthTablet }` );
			}
			if ( column.widthMobile !== undefined ) {
				parts.push( `widthMobile: ${ column.widthMobile }` );
			}
			if ( column.reverseMobile ) {
				parts.push( 'reverseMobile: true' );
			}
			if ( column.reverseTablet ) {
				parts.push( 'reverseTablet: true' );
			}
			if ( column.hiddenMobile ) {
				parts.push( 'hiddenMobile: true' );
			}
			if ( column.hiddenTablet ) {
				parts.push( 'hiddenTablet: true' );
			}
			if ( column.hiddenDesktop ) {
				parts.push( 'hiddenDesktop: true' );
			}
			return `{ ${ parts.join( ', ' ) } }`;
		} );
		entry.push( `columns: [${ lanes.join( ', ' ) }]` );
	} else if ( section.columns && section.columns.length > 0 ) {
		const columns = section.columns.map( ( column ) => {
			const parts: string[] = [];
			if ( column.width !== undefined ) {
				parts.push( `width: ${ column.width }` );
			}
			if ( column.widthTablet !== undefined ) {
				parts.push( `widthTablet: ${ column.widthTablet }` );
			}
			if ( column.widthMobile !== undefined ) {
				parts.push( `widthMobile: ${ column.widthMobile }` );
			}
			if ( column.reverseMobile ) {
				parts.push( 'reverseMobile: true' );
			}
			if ( column.reverseTablet ) {
				parts.push( 'reverseTablet: true' );
			}
			if ( column.hiddenMobile ) {
				parts.push( 'hiddenMobile: true' );
			}
			if ( column.hiddenTablet ) {
				parts.push( 'hiddenTablet: true' );
			}
			if ( column.hiddenDesktop ) {
				parts.push( 'hiddenDesktop: true' );
			}
			if ( column.bgColor ) {
				parts.push( `bgColor: ${ tsString( column.bgColor ) }` );
			}
			if ( column.bgImage ) {
				parts.push( `bgImage: ${ tsString( column.bgImage ) }` );
			}
			return `{ ${ parts.join( ', ' ) } }`;
		} );
		entry.push( `columns: [${ columns.join( ', ' ) }]` );
	}
	// The rows NESTED inside the band (see `ForgeBandRowFact`). A raw band loses
	// the builder's flex layout, so a 3-card team row stacked three full-width
	// cards down the page; these widths give each row its tracks back.
	const nestedRows = bandFacts?.rows;
	if ( nestedRows && nestedRows.length > 0 ) {
		entry.push(
			`rows: [${ nestedRows
				.map( ( row ) => `{ id: ${ tsString( row.id ) }, widths: [${ row.widths.join( ', ' ) }] }` )
				.join( ', ' ) }]`
		);
	}
	// Whether the band's OWN row is a ROW or a STACK, and the gap between its
	// lanes (`ForgeBandRowLayout`). The lane widths alone cannot say: two
	// full-width lanes are a column, and normalising them into a two-track grid
	// laid eca.directory's about-us CTA beside itself (2026-11-25).
	const measuredRowLayout = bandFacts?.rowLayout;
	if ( measuredRowLayout?.desktop ) {
		const base = measuredRowLayout.desktop;
		const parts = [ `direction: ${ tsString( base.direction ) }` ];
		if ( base.gap ) {
			parts.push( `gap: ${ base.gap }` );
		}
		if ( base.justify ) {
			parts.push( `justify: ${ tsString( base.justify ) }` );
		}
		if ( base.align ) {
			parts.push( `align: ${ tsString( base.align ) }` );
		}
		if ( base.labelInk ) {
			parts.push( `labelInk: ${ tsString( base.labelInk ) }` );
		}
		// A device override only when the SOURCE changes direction there — an
		// unchanged value would double the stylesheet for nothing.
		for ( const device of [ 'tablet', 'mobile' ] as const ) {
			const override = measuredRowLayout[ device ];
			if ( ! override || override.direction === base.direction ) {
				continue;
			}
			const overrideParts = [ `direction: ${ tsString( override.direction ) }` ];
			if ( override.gap ) {
				overrideParts.push( `gap: ${ override.gap }` );
			}
			parts.push( `${ device }: { ${ overrideParts.join( ', ' ) } }` );
		}
		entry.push( `rowLayout: { ${ parts.join( ', ' ) } }` );
	}
	// The NARROW, centred containers nested in the band (`ForgeBandNarrowBox`).
	// The wrapper has no id, so the fact names its parent and the measured width.
	if ( bandFacts?.narrowBoxes && bandFacts.narrowBoxes.length > 0 ) {
		entry.push(
			`narrowBoxes: [${ bandFacts.narrowBoxes
				.map(
					( box ) =>
						`{ parentId: ${ tsString( box.parentId ) }, width: ${ box.width }, align: ${ tsString(
							box.align
						) }${ box.alignItems ? `, alignItems: ${ tsString( box.alignItems ) }` : '' } }`
				)
				.join( ', ' ) }]`
		);
	}
	// WHERE the source stops splitting its own row.
	//
	// The measured lanes say it directly: at the device where their widths sum
	// to more than the band's content column, they cannot be side by side, so
	// the builder stacked them. eca.directory's team band is 286 / 954 on a
	// desktop and 774 / 774 at 834px — two full-width lanes — while the rebuild
	// kept a 179px copy column beside 595px of cards (2026-11-24).
	const laneStacksAt = ( device: 'tablet' | 'mobile' ): boolean => {
		const lanes = bandFacts?.columns?.[ device ] ?? [];
		if ( lanes.length < 2 ) {
			return false;
		}
		const total = lanes.reduce( ( sum, lane ) => sum + ( lane.width ?? 0 ), 0 );
		const availableText =
			typeof container.measure === 'string' ? container.measure : container.measure?.[ device ];
		const available = availableText ? Number.parseFloat( availableText ) : NaN;
		return Number.isFinite( available ) && available > 0 && total > available * 1.05;
	};
	if ( laneStacksAt( 'tablet' ) ) {
		entry.push( `stackAt: 'tablet'` );
	} else if ( laneStacksAt( 'mobile' ) ) {
		entry.push( `stackAt: 'mobile'` );
	}
	// The split-hero + collage facts (125 §1) — layout, divider, heading
	// type, backdrop shape, per-photo offsets, body columns.
	if ( section.layout ) {
		entry.push( `layout: ${ tsString( section.layout ) }` );
	}
	if ( section.mediaSide ) {
		entry.push( `mediaSide: ${ tsString( section.mediaSide ) }` );
	}
	// The split's COPY MEASURE (`ForgeBandFacts.copyWidth`) — the builder's own
	// cap on the lede's line length. Emitted for the band list, not only heroes:
	// any two-lane band whose copy is pinned narrower than its lane needs it.
	if ( bandFacts?.copyWidth ) {
		entry.push( `copyWidth: ${ bandFacts.copyWidth }` );
	}
	// A SPACED row's per-item widths (absolute px), for the component that rebuilds
	// it. The source spreads four counters across 1100px with `space-between`, and
	// each states its own width (151 / 253 / 197 / 163); an equal-cell grid renders
	// four 275px cells and wraps every label differently (eca.directory's stats
	// band, 2026-11-25). A lane that fills the row is a heading the component
	// renders itself, so it is left out.
	if ( bandFacts?.rowLayout?.desktop?.justify === 'space-between' ) {
		const lanes = ( bandFacts.columns?.desktop ?? [] )
			.map( ( lane ) => Math.round( lane.width ?? 0 ) )
			.filter( ( width ) => width > 0 );
		const widest = lanes.length > 0 ? Math.max( ...lanes ) : 0;
		const items = lanes.filter( ( width ) => width < widest * 0.9 );
		if ( items.length >= 2 && widest > 0 ) {
			entry.push( `statsWidths: [${ items.join( ', ' ) }]` );
		}
	}
	// The page HERO, derived from MEASURED facts rather than markup: the band
	// that owns the h1 and splits into a copy column beside a media column.
	//
	// A builder paints the hero's photo as a `background-image` on a spacer
	// div, so the semantic pass (which reads `<img src>`) sees an empty media
	// slot and the band falls back to its kind's own component — eca.directory's
	// hero rebuilt as an icon-list with no picture at all (2026-11-24). The
	// measured columns carry the truth: which side is the media, and how wide
	// each side is. The h1 gate is what keeps a two-column TEAM band (h2, three
	// avatars in its wide lane) from becoming a hero collage.
	if ( ! section.layout && ! section.bgImage && bandFacts?.headings?.h1 ) {
		const lanes = bandFacts?.columns?.desktop ?? [];
		if ( lanes.length === 2 && lanes.some( ( lane ) => lane.image ) ) {
			entry.push( `layout: 'split'` );
		}
	}
	// The hero's COLLAGE, layer by layer.
	//
	// eca.directory's hero media column is five spacer divs, each painted with a
	// background image (a green blob, two shapes and the student photo) that the
	// vendor sheet overlaps with transforms. One image lost the composition — the
	// blob and the shapes ARE the design — so every painted element of the media
	// lane is emitted in DOM order with its measured height, and the renderer
	// stacks them as layers.
	if ( entry.some( ( line ) => line.startsWith( 'layout: ' ) ) ) {
		const lanes = bandFacts?.columns?.desktop ?? [];
		const mediaIndex = lanes.findIndex( ( lane ) => lane.image );
		const lane = lanes[ mediaIndex ];
		// A lane's element path carries its position as the 2nd segment when the
		// band wraps its lanes in a container row (`e-con-inner`), and as the 1st
		// when the lanes ARE the band's children. Both shapes occur.
		const laneOf = ( path: string ): number | undefined => {
			const segments = path.split( ' > ' );
			const index = ( segment: string | undefined ): number | undefined => {
				const match = segment ? /^div:nth-child\((\d+)\)$/.exec( segment ) : null;
				return match ? Number( match[ 1 ] ) : undefined;
			};
			return index( segments[ 1 ] ) ?? index( segments[ 0 ] );
		};
		type Layer = { src: string; height?: number };
		const layers = mediaIndex < 0
			? []
			: ( bandFacts?.elements ?? [] )
					.filter( ( element ) => laneOf( element.path ) === mediaIndex + 1 )
					.map( ( element ): Layer | undefined => {
						const src = /background-image:\s*url\(\s*["']?([^"')]+)["']?\s*\)/i.exec( element.bg ?? '' )?.[ 1 ];
						if ( ! src || ! /\.(?:png|jpe?g|webp|svg|gif|avif)(?:\?|$)/i.test( src ) ) {
							return undefined;
						}
						const height =
							typeof element.height === 'number' ? element.height : element.height?.desktop;
						return { src, height };
					} )
					.flatMap( ( layer ): Layer[] => ( layer ? [ layer ] : [] ) )
					// The BOTTOM layer (a blob) is painted first in DOM order and the photo
					// last; keep that order, the renderer stacks from it.
					.slice( 0, 6 );
		if ( layers.length > 0 ) {
			entry.push(
				`mediaLayers: [${ layers
					.map( ( layer ) => `{ src: ${ tsString( layer.src ) }${ layer.height ? `, height: ${ Math.round( layer.height ) }` : '' } }` )
					.join( ', ' ) }]`
			);
		}
		// The media LANE's measured height, always — not only for a layered
		// collage. A single photo rendered at its intrinsic aspect made the whole
		// band taller than the source (eca.directory's about-us hero: 544px built
		// against 420px, so its copy sat 59px low, 2026-11-25). The source crops the
		// picture to this box with `cover`; the rebuild must too.
		if ( lane?.height ) {
			entry.push( `mediaHeight: ${ Math.round( lane.height ) }` );
		}
	}
	if ( section.titleDivider ) {
		const divider = section.titleDivider;
		entry.push(
			`titleDivider: { width: ${ tsString( divider.width ) }, height: ${ tsString( divider.height ) }, background: ${ tsString( divider.background ) }${ divider.shadow ? `, shadow: ${ tsString( divider.shadow ) }` : '' } }`
		);
	}
	if ( section.titleStyle ) {
		const style = section.titleStyle;
		const parts: string[] = [];
		if ( style.size ) {
			parts.push( `size: ${ tsString( style.size ) }` );
		}
		if ( style.weight ) {
			parts.push( `weight: ${ tsString( style.weight ) }` );
		}
		if ( style.lineHeight ) {
			parts.push( `lineHeight: ${ tsString( style.lineHeight ) }` );
		}
		if ( style.letterSpacing ) {
			parts.push( `letterSpacing: ${ tsString( style.letterSpacing ) }` );
		}
		for ( const device of [ 'tablet', 'mobile' ] as const ) {
			const lineHeight = style[ device ]?.lineHeight;
			if ( lineHeight ) {
				parts.push( `${ device }: { lineHeight: ${ tsString( lineHeight ) } }` );
			}
		}
		entry.push( `titleStyle: { ${ parts.join( ', ' ) } }` );
	}
	if ( section.titleAlign ) {
		entry.push( `titleAlign: ${ tsString( section.titleAlign ) }` );
	}
	if ( section.cardStyle ) {
		const card = section.cardStyle;
		const parts = ( [ 'gap', 'padding', 'textPadding' ] as const )
			.filter( ( key ) => card[ key ] )
			.map( ( key ) => `${ key }: ${ tsString( card[ key ] as string ) }` );
		if ( parts.length > 0 ) {
			entry.push( `cardStyle: { ${ parts.join( ', ' ) } }` );
		}
	}
	if ( section.iconRowStyle ) {
		const row = section.iconRowStyle;
		// padTop/padBottom/padX are per-device (the source pads 63px on desktop,
		// 36/50 at tablet/mobile); everything else is a single measured value.
		const responsive = new Set< string >( [ 'padTop', 'padBottom', 'padX' ] );
		const parts = ( [ 'padTop', 'padBottom', 'padX', 'rowGap', 'titleSize', 'titleLineHeight', 'markerSize', 'markerGlyph', 'markerBackground', 'markerInk' ] as const )
			.filter( ( key ) => row[ key ] !== undefined )
			.map( ( key ) =>
				`${ key }: ${
					responsive.has( key ) ? tsLength( row[ key ] as ResponsiveLength ) : tsString( row[ key ] as string )
				}`
			);
		if ( parts.length > 0 ) {
			entry.push( `iconRowStyle: { ${ parts.join( ', ' ) } }` );
		}
	}
	if ( section.mediaShape ) {
		const shape = section.mediaShape;
		entry.push(
			`mediaShape: { width: ${ tsString( shape.width ) }, height: ${ tsString( shape.height ) }, background: ${ tsString( shape.background ) }${ shape.radius ? `, radius: ${ tsString( shape.radius ) }` : '' } }`
		);
	}
	if ( section.mediaOffsets && section.mediaOffsets.length > 0 ) {
		const offsets = section.mediaOffsets.map( ( offset ) => {
			const parts: string[] = [];
			if ( offset.top ) {
				parts.push( `top: ${ tsString( offset.top ) }` );
			}
			if ( offset.right ) {
				parts.push( `right: ${ tsString( offset.right ) }` );
			}
			if ( offset.bottom ) {
				parts.push( `bottom: ${ tsString( offset.bottom ) }` );
			}
			if ( offset.left ) {
				parts.push( `left: ${ tsString( offset.left ) }` );
			}
			if ( offset.shadow ) {
				parts.push( `shadow: ${ tsString( offset.shadow ) }` );
			}
			return `{ ${ parts.join( ', ' ) } }`;
		} );
		entry.push( `mediaOffsets: [${ offsets.join( ', ' ) }]` );
	}
	if ( section.bodyColumns ) {
		entry.push( `bodyColumns: ${ section.bodyColumns }` );
	}
	if ( section.catalog ) {
		entry.push(
			`catalog: { ${ section.catalog.title ? `title: ${ tsString( section.catalog.title ) }, ` : '' }source: ${ tsString( section.catalog.source ) } }`
		);
	}
	return `	{ ${ entry.join( ', ' ) } },`;
}

/**
 * Render the generated section-theme module (117 §2) —
 * src/lib/sections.generated.ts. The source section backgrounds (profile
 * design.sections) bake into a deterministic array keyed by section id,
 * each entry carrying its resolved theme (hero/dark/alt/light). The
 * renderer looks up the classifier-carried section id to re-apply the
 * source band theming positionally.
 */
export function renderSectionsGenerated( profile: SiteProfile ): string {
	// Elementor sections when present; otherwise the promoted outline — the
	// only band model a WPBakery/Divi/Bricks/Gutenberg source has.
	const source = renderableBands( profile.design );
	// The analyzer records where the bands came from (`outline` for non-Elementor
	// sources). It is load-bearing even after promotion fills `sections`: outline
	// bands carry no element ids, so the renderer must map them POSITIONALLY.
	// Deriving the flag from `sections.length` alone silently flipped promoted
	// outlines to id-matching, and WPBakery pages then rendered template
	// defaults (virtuallyjamaica's red #dd3333 hero came out white).
	const bandSource =
		profile.design?.bandSource ??
		( profile.design?.sections && profile.design.sections.length > 0 ? 'sections' : 'outline' );
	const header = [
		'// ---------------------------------------------------------------------------',
		'// sections.generated.ts — generated by nvoos-astro-forge (Proposal 117 §2);',
		'// edits are overwritten on re-scaffold. The source band theming: each entry',
		'// keys the source Elementor section id to its background facts and the',
		'// resolved rebuild theme. The DesignRenderer matches rebuilt sections by id',
		'// and wraps them accordingly. Deterministic: same profile, same bytes.',
		'// ---------------------------------------------------------------------------',
		'',
		'',
		"export type BandSpacing = 'template' | 'source';",
		'',
		'/** Whether a band with no source padding fact keeps the template rhythm',
		' * or renders flush (the source\u2019s own 0). From profile.bandSpacing. */',
		`export const bandSpacing: BandSpacing = '${ profile.bandSpacing === 'source' ? 'source' : 'template' }';`,
		'',
		'/** Where the band list came from. `outline` = no Elementor ids exist, so',
		' * the renderer maps bands POSITIONALLY (section index → band index). */',
		`export const bandSource: 'sections' | 'outline' = '${ bandSource }';`,
		'',
		'/** The measured PAGE background (a builder paints the body; transparent',
		' * bands and the margins between bands show it). Undefined → the template\u2019s',
		' * own surface. */',
		`export const pageBackground: string | undefined = ${
			profile.pageBackground ? tsString( profile.pageBackground ) : 'undefined'
		 };`,
		'',
		"export type SectionThemeName = 'hero' | 'dark' | 'alt' | 'light';",
		'',
		'\t/** One measured typography fact (a heading level or the body paragraph).',
		'\t * Values are the browser\u2019s COMPUTED styles: px, already resolved through',
		'\t * the source builder\u2019s own stylesheet. */',
		'export interface BandTypeFact {',
		'\tsize?: string;',
		'\tweight?: string;',
		'\tlineHeight?: string;',
		'\tletterSpacing?: string;',
		'\ttransform?: string;',
		'\talign?: string;',
		'\t/** The first family of the computed `font-family` \u2014 the source\u2019s',
		'\t * own face for this LEVEL. A band can wear several: monicarajiv\u2019s h1 is',
		'\t * Karma while the `alt-font` h3 beside it is Dancing Script, so the family',
		'\t * belongs to the heading level, not to the band. */',
		'\tfamily?: string;',
		'\t/** Computed `color` \u2014 the source\u2019s own ink. A builder writes it as an',
		'\t * inline `style` the sanitizer drops, so this is the only surviving copy. */',
		'\tcolor?: string;',
		'\t/** Computed `max-width` \u2014 a heading the source deliberately narrows. */',
		'\tmaxWidth?: string;',
		'\t/** Computed `margin-top` / `margin-bottom` — the builder\u2019s heading rhythm.',
		'\t * An UNCLASSED heading has no class for a box fact to hang on, so without',
		'\t * this the rebuilt heading falls back to the template\u2019s prose margins. */',
		'\tmarginTop?: string;',
		'\tmarginBottom?: string;',
		'\t/** What the source CHANGES below the desktop breakpoint (and only that). */',
		'\ttablet?: BandTypeProperties;',
		'\tmobile?: BandTypeProperties;',
		'}',
		'',
		'/** A BandTypeFact without its own device overrides. */',
		"export type BandTypeProperties = Omit<BandTypeFact, 'tablet' | 'mobile'>;",
		'',
		'',
		'export interface SectionTheme {',
		'\tid?: string;',
		'\t/** The band\'s classified kind (Proposal 136 §4.2). Advisory: emitted as',
		'\t * `data-section`, which is what the per-band fact gate compares. */',
		'\tkind?: string;',
		'\ttheme: SectionThemeName;',
		'\t/** The source section anchor (the chrome nav links to it). */',
		'\tanchor?: string;',
		'\t/** The source band background (gradient or flat color). */',
		'\tbg?: string;',
		'\t/** The hero photo (hero sections only). */',
		'\tbgImage?: string;',
		'\tbgSize?: string;',
		'\tbgRepeat?: string;',
		'\tbgPosition?: string;',
		'\tbgLayerOpacity?: number;',
		'\tbgBlendMode?: string;',
		'\t/** The overlay tint over the hero photo. */',
		'\toverlay?: string;',
		'\t/** The band\u2019s MARK STRIP \u2014 logos the source publishes as carousel',
		'\t * `background-image` slides, which the classifier (reading `<img>` only)',
		'\t * cannot see. Rendered as a `.ds-logos` row inside the band. */',
		'\tlogos?: Array<{ src: string; alt?: string }>;',
		'/\*\* The band\u2019s COMPARISON MATRIX \u2014 role columns against feature rows,\n			 * recorded at analyze time from the raw HTML because sanitisation strips\n			 * the check/cross `<svg>` the build-time classifier would need. Rendered\n			 * as a real table AFTER the band\u2019s own component (the band usually holds\n			 * cards too). */',
		'\tcomparison?: { columns: string[]; rows: Array<{ label: string; values: string[] }>; caption?: string; hiddenAt?: Array<"tablet" | "mobile"> };',
		'/\*\* MEASURED STRUCTURE (see `ForgeSection.structure`). One entry per\n			 * measured structure; rendered IN ADDITION to the band\u2019s classified kind,\n			 * never instead of it. `comparison` above is the typed fast path. */',
		'\tstructure?: Array<{ type: string; data?: { columns: string[]; rows: Array<{ label: string; values: string[] }>; caption?: string; hiddenAt?: Array<"tablet" | "mobile"> }; source?: "vision"; model?: string; confidence?: number; note?: string }>;',
		'\t/** The band\u2019s measured PAGED carousel (`bandFacts[].carousel`) \u2014',
		'\t * slides-per-view and the gutter the source renders. A logo wall is as',
		'\t * often a slider as a static row, and the difference is only measurable',
		'\t * in the browser: the markup names the vendor, not the geometry. */',
		'\tcarousel?: { selector?: string; count?: number; perView?: number; gap?: number; dots?: number };',
		'\t/** The band\u2019s PANEL surface \u2014 the source sets its content on ONE',
		'\t * coloured slab (see `ForgeSection.panel`). `ink` is the legible foreground',
		'\t * on that slab; `width`/`align` place a slab that fills only PART of the',
		'\t * band (the heading sits on it too). */',
		"\tpanel?: { bg: string; ink: string; width?: string; align?: 'start' | 'center' | 'end' };",
		'\t/** The ink a CARD\u2019s body copy inherits inside this band. Emitted only',
		' * when the band paints the same surface the card does, i.e. when the',
		' * measured body ink was measured ON the card: the template\u2019s blanket',
		' * `.ds-section .ds-card p` rule would otherwise paint the band\u2019s cards',
		' * with the `muted` token instead of the source\u2019s own ink. */',
		'\tcardInk?: string;',
		'\t/** The measured EYEBROW — the small label the source stacks above the\n\t * title. Rendered above the heading at its own measured size: a builder\n\t * publishes the kicker as a real heading (often the SAME tag at a fraction\n\t * of the size), which no tag-level heuristic can separate from the title. */',
		'\teyebrow?: { text: string; level?: number; size?: string; weight?: string; lineHeight?: string; letterSpacing?: string; transform?: string; align?: string; family?: string; color?: string; maxWidth?: string; marginTop?: string; marginBottom?: string };',
		'\t/** The measured TITLE text — the LARGEST heading the band renders. */',
		'\ttitleText?: string;',
		'\t/** The source band padding (the declared padding fact). */',
		'\tpadding?: string | { desktop?: string; tablet?: string; mobile?: string };',
		'\t/** The boxed container width (the source constrains its content —',
		'\t * jayainc\'s Elementor 1140px default). Absent → the site measure. A',
		'\t * per-device map is emitted as scoped CSS: an inline value would',
		'\t * outrank its own media queries. */',
		'\tmeasure?: string | { desktop?: string; tablet?: string; mobile?: string };',
		'\t/** The container side gutter (the source\'s own padding, measured per',
		'\t * device). Absent → the template clamp. */',
		'\tpaddingX?: string | { desktop?: string; tablet?: string; mobile?: string };',
		'\t/** Section-level pull facts (124 §4) — negative margins overlap',
		'\t * the neighbor band; z-index stacks the overlap. A length may be a',
		'\t * per-device map where the source scopes the pull to a range. */',
		'\tmarginTop?: string | { desktop?: string; tablet?: string; mobile?: string };',
		'\tmarginBottom?: string | { desktop?: string; tablet?: string; mobile?: string };',
		'	zIndex?: string;',
		'\t/** The band\u2019s MEASURED source height (`analyze --geometry`) \u2014 applied as a',
		'\t * CSS min-height FLOOR, never a lock: content that grows past it is never',
		'\t * clipped. A per-device map when the source band changes height per',
		'\t * breakpoint. Absent \u2192 the band sizes to its content. */',
		'\tminHeight?: string | { desktop?: string; tablet?: string; mobile?: string };',
		'\t/** The band was measured at EXACTLY the viewport height on every device',
		' * (`minHeight` is then `100vh`). The wrap carries `ds-section-wrap--full`,',
		' * which fills the viewport and stretches its single-file wrapper chain so the',
		' * copy track centres vertically — the source’s own full-screen behaviour,',
		' * which its vendor stylesheet used to supply. */',
		'\tfullViewport?: boolean;',
		'\t/** The measured column split of the structural hero track (`--ds-split`).',
		' * The copy column keeps the source\u2019s width, so its line breaks match. */',
		'\tsplit?: number[];',
		'\t/** The band measured the source\u2019s own vertical-centring device;',
		' * the wrap takes `ds-section-wrap--centered`. */',
		'\tcentered?: boolean;',
		'\t/** The band\u2019s measured TYPOGRAPHY (`analyze --geometry`) \u2014 the source\u2019s',
		'\t * computed heading sizes per level plus the body paragraph. A raw-HTML band',
		'\t * otherwise renders at the browser default heading scale (a 40px source',
		'\t * `<h3>` came out at 15.21px = 1.17em of the 13px body). `title` is the',
		'\t * band\u2019s LARGEST heading \u2014 the visual title the components size from. */',
		'\ttype?: { headings?: Record<string, BandTypeFact>; body?: BandTypeFact; title?: BandTypeFact; titleLevel?: string; /** The page owns this band’s h1 (front page) — see title-tag.ts. */ allowH1?: boolean };',
		'\t/** Selectors for subtrees the SOURCE renders hidden at rest (accordion',
		'\t * panels, tab panes) — emitted as scoped `display:none` rules. */',
		'\thidden?: string[];',
		'\t/** The source\u2019s crop anchor for this band\u2019s cover photos',
		'\t * (`object-position`, e.g. `63% 90%`) — emitted as `--ds-media-pos` on',
		'\t * the band wrapper. The source writes it in a `<style>` block the',
		'\t * sanitizer drops, so without this fact every photo re-crops to centre. */',
		'\tmediaPosition?: string;',
		'\t/** The builder\u2019s presentation utilities this band uses, as',
		'\t * class \u2192 declarations (Bootstrap/Brando `text-center`,',
		'\t * `padding-four-all`), with their device-scoped variants. Trimmed to the',
		'\t * classes the page carries. */',
		'\tutilities?: Record<string, { base?: string; tablet?: string; mobile?: string }>;',
		'\t/** The MEASURED box (padding/margin/cropped-media height) of the classes',
		'\t * this band keeps, per device. The builder spacing scale is not a ladder,',
		'\t * so these are computed values, re-stated verbatim. Emitted AFTER',
		'\t * `utilities`, so a measured value outranks a hand-authored approximation. */',
		'\tbox?: Record<string, { base?: string; tablet?: string; mobile?: string }>;',
		'\t/** Cropped-media heights, emitted as `img.<class>` only. */',
		'\tmedia?: Record<string, { base?: string; tablet?: string; mobile?: string }>;',
		'\t/** Per-ELEMENT surfaces + pinned heights inside a raw builder band,',
		'\t * keyed by a STRUCTURAL path relative to the band (a class name repeats',
		'\t * across bands and elements). Raw bands keep the source markup, so the',
		'\t * path resolves to the same node in the rebuild. */',
		'\telements?: Array<{ path: string; bg?: string; veil?: string; fg?: string; height?: number | { desktop?: number; tablet?: number; mobile?: number }; width?: number | { desktop?: number; tablet?: number; mobile?: number } }>;',
		'\t/** The band\u2019s measured COLUMN geometry, keyed by the column\u2019s',
		'\t * unique class: a rendered `min-height` floor plus a fixed background',
		'\t * photo\u2019s attachment/size. Emitted as band-scoped `.class` rules \u2014 the',
		'\t * sanitizer keeps the class but drops the inline layout, and the vendor',
		'	 * sheet never ships. */',
		'	columnBox?: Record<string, { base?: string; tablet?: string; mobile?: string }>;',
		'	/** The per-column INNER padding (targets `.<class> > .col-inner`). */',
		'	columnInner?: Record<string, { base?: string; tablet?: string; mobile?: string }>;',
		'	/** The band\u2019s measured CAROUSEL geometry, keyed by the carousel\u2019s',
		'	 * unique class: slides-per-view, gutter and the track\u2019s scroll',
		'	 * behaviour. A vendor slider keeps `items` in its JS options, so the',
		'	 * markup cannot say how many slides are on screen. */',
		'	carouselBox?: Record<string, { base?: string; tablet?: string; mobile?: string }>;',
		'\t/** The band\u2019s measured interaction STATES (hover / focus /',
		'\t * focus-visible / active) per control signature. `selector` is the',
		'\t * signature\u2019s CSS form (`a[class~="btn"]`), already escaped, so the',
		'\t * renderer only has to scope and emit it. `transition` is the source\u2019s',
		'\t * own easing, emitted under `prefers-reduced-motion: no-preference`; the',
		'\t * `hover`/`active` rules live under `@media (hover: hover)` so touch',
		'\t * devices never keep a sticky hover. */',
		'\tinteractions?: Array<{ selector: string; count?: number; base?: string; transition?: string; hover?: string; focus?: string; focusVisible?: string; active?: string; children?: Array<{ selector: string; base?: string; pseudo?: string; transition?: string; hover?: string; focus?: string; focusVisible?: string; active?: string }> }>;',
		'\t/** The top-level column responsive facts (124 §6) — widths per',
		'\t * device + order/visibility; the split components re-measure. */',
		'\tcolumns?: Array<{',
		'\t\twidth?: number;',
		'\t\twidthTablet?: number;',
		'\t\twidthMobile?: number;',
		'\t\treverseMobile?: boolean;',
		'\t\treverseTablet?: boolean;',
		'\t\thiddenMobile?: boolean;',
		'\t\thiddenTablet?: boolean;',
		'\t\thiddenDesktop?: boolean;',
		'\t\t/** The column\u2019s own painted SURFACE (see ForgeSectionColumn) \u2014 a',
		'\t\t * builder panel. Measured, never guessed: the vendor stylesheet never',
		'\t\t * ships and the sanitiser drops the inline style. */',
		'\t\tbgColor?: string;',
		'\t\tbgImage?: string;',
		'\t\t/** The ink that lane\u2019s own surface needs (`inkForSurface`). */',
		'\t\tink?: string;',
		'\t}>;',
		'\t/** The rows NESTED inside the band (`ForgeBandRowFact`): a container',
		'\t * that lays two or more lanes out side by side, with the lane widths the',
		'\t * source rendered. A raw band keeps the markup but never the vendor\u2019s',
		'\t * flex CSS, so without these every nested row stacked. */',
		'\trows?: Array<{ id: string; widths: number[] }>;',
		'\t/** How the band\u2019s OWN row lays its lanes out (`ForgeBandRowLayout`):',
		'\t * `row` lays them side by side, `column` stacks them, and `gap` is the',
		'\t * space between the stacked lanes. The lane widths alone cannot say',
		'\t * which — two full-width lanes are a stack. */',
		'\trowLayout?: {',
		"\t\tdirection: 'row' | 'column';",
		'\t\tgap?: number;',
		'\t\tjustify?: string;',
		'\t\talign?: string;',
		'\t\t/** The band\u2019s LABEL ink (a counter title / dd), when one is present:',
		'\t\t * the muted default is unreadable on a dark panel. */',
		'\t\tlabelInk?: string;',
		"\t\ttablet?: { direction: 'row' | 'column'; gap?: number };",
		"\t\tmobile?: { direction: 'row' | 'column'; gap?: number };",
		'\t};',
		'\t/** The NARROW containers nested in the band, measured: a builder insets',
		'\t * copy by wrapping it in a smaller centred container (500px inside 1280).',
		'\t * The wrapper has no builder id, so the fact names its PARENT. */',
		"\tnarrowBoxes?: Array<{ parentId: string; width: number; align: 'start' | 'center' | 'end'; alignItems?: string }>;",
		'\t/** The breakpoint at which the SOURCE stops laying its own lanes side',
		'\t * by side (`analyze --geometry`, measured): the rebuild stacks there',
		'\t * instead of squeezing a copy column. Absent → the template\u2019s mobile',
		'\t * breakpoint, which is where almost every builder stacks. */',
		'\tstackAt?: \'tablet\' | \'mobile\';',
		'\t/** The hero collage, layer by layer (`analyze --geometry` element facts):',
		'\t * a builder stacks the blob, the shapes and the photo as separate painted',
		'\t * spacers, so one image loses the composition. In DOM order, bottom first. */',
		'\tmediaLayers?: Array<{ src: string; height?: number }>;',
		'\t/** The media lane\u2019s measured height, so the layers have a box to sit in. */',
		'\tmediaHeight?: number;',
		'\t/** The content rhythm (125 §1) — \'split\' = a copy column beside a',
		'\t * media collage column (the source\'s two-column hero bands). */',
		"\tlayout?: 'split';",
		'\t/** Which side of the split holds the media (`left` | `right`) — measured',
		'\t * from the lane order, so a mirror-image design is not rebuilt flipped. */',
		'\tmediaSide?: \'left\' | \'right\';',
		'\t/** The split\u2019s COPY lane measure in px (`ForgeBandFacts.copyWidth`):',
		'\t * the builder pinned the lede narrower than its lane, and the rule',
		'\t * that did it never ships. Applied as a max-width on the copy body. */',
		'\tcopyWidth?: number;',
		'\t/** A spaced row\u2019s per-item widths in px (`statsWidths`): the source',
		'\t * states each counter\u2019s own width, so an equal-cell grid wraps the',
		'\t * labels differently. Applied to the stats list in DOM order. */',
		'\tstatsWidths?: number[];',
		'\t/** The heading\'s underline divider facts (width/height/background/shadow). */',
		'\ttitleDivider?: { width: string; height: string; background: string; shadow?: string };',
		'\t/** The heading typography (size/weight/line-height/letter-spacing). */',
		'\ttitleStyle?: { size?: string; weight?: string; lineHeight?: string; letterSpacing?: string; tablet?: { lineHeight?: string }; mobile?: { lineHeight?: string } };',
		'\t/** The heading is centered (the source\'s aux-widget-inner centering). */',
		"\ttitleAlign?: 'center';",
		'\t/** The media collage backdrop shape (width/height/background/radius). */',
		'\tmediaShape?: { width: string; height: string; background: string; radius?: string };',
		'\t/** The card box model of a media grid band (measured; template defaults when absent). */',
		'\tcardStyle?: { gap?: string; padding?: string; textPadding?: string };',
		'\ticonRowStyle?: { padTop?: string | { desktop?: string; tablet?: string; mobile?: string }; padBottom?: string | { desktop?: string; tablet?: string; mobile?: string }; padX?: string | { desktop?: string; tablet?: string; mobile?: string }; rowGap?: string; titleSize?: string; titleLineHeight?: string; markerSize?: string; markerGlyph?: string; markerBackground?: string; markerInk?: string };',
		'\t/** Per-image collage offsets (absolute positioning against the collage box). */',
		'\tmediaOffsets?: Array<{ top?: string; right?: string; bottom?: string; left?: string; shadow?: string }>;',
		'\t/** The body paragraphs render in N columns (the source\'s sub-column split). */',
		'\tbodyColumns?: number;',
		'\t/** The band\'s flipbook catalog (125 §4) — the PDF the sanitizer',
		'\t * would otherwise lose with the dflip script; the rebuild renders',
		'\t * a titled open-catalogue CTA. */',
		'\tcatalog?: { title?: string; source: string };',
		'}',
		'',
		'export const sections: SectionTheme[] = [',
	];
	const body: string[] = [];
	for ( const section of source ) {
		body.push( renderSectionThemeEntry( section, profile ) );
	}
	body.push( '];' );
	body.push( '' );
	return [ ...header, ...body ].join( '\n' );
}

/**
 * Render the per-page band module (`src/lib/pages.generated.ts`) — the same
 * measurement the homepage gets, one POSITIONAL list per page slug
 * (`analyze --geometry --pages`).
 *
 * A page whose bands were not measured is omitted; the renderer then keeps the
 * homepage list positionally, which is the pre-existing behaviour. The entry
 * emitter is shared with `renderSectionsGenerated`, so a sub-page carries every
 * fact the front page does (surfaces, measured box heights, typography, crop
 * anchors, utilities, states).
 */
export function renderPagesGenerated( profile: SiteProfile ): string {
	const pages = profile.pageBands ?? {};
	const header = [
		'// ---------------------------------------------------------------------------',
		'// pages.generated.ts — generated by nvoos-astro-forge (Proposal 115 ext.);',
		'// edits are overwritten on re-scaffold. One positional SectionTheme list per',
		'// sub-page, measured from THAT page’s own bands. The renderer applies the list',
		'// positionally, exactly like the homepage list. Deterministic: same profile,',
		'// same bytes.',
		'// ---------------------------------------------------------------------------',
		'',
		"import type { SectionTheme } from './sections.generated.ts';",
		'',
		'export const pageBands: Record<string, SectionTheme[]> = {',
	];
	const body: string[] = [];
	for ( const [ slug, page ] of Object.entries( pages ).sort( ( [ a ], [ b ] ) => a.localeCompare( b ) ) ) {
		// A page view: the site profile with THIS page's measured facts. Only
		// `bandFacts`/`geometry`/`design.builder` are read by the emitter.
		const view = {
			...profile,
			bandFacts: page.bandFacts,
			geometry: page.geometry,
			design: {
				...profile.design,
				builder: page.builder ?? profile.design?.builder,
			},
		} as SiteProfile;
		body.push( `\t${ tsString( slug ) }: [` );
		for ( const section of page.sections ) {
			body.push( `\t${ renderSectionThemeEntry( section, view ) }` );
		}
		body.push( '\t],' );
	}
	body.push( '};' );
	body.push( '' );
	return [ ...header, ...body ].join( '\n' );
}

/**
 * A chrome href, made LOCAL.
 *
 * The nav is MEASURED from the live site, so every menu item carries the CMS's
 * absolute URL — and clicking it left the rebuilt front end for the WordPress
 * install it is meant to replace (eca.directory: Home / About Us / Contact Us,
 * 2026-11-24). A same-origin URL becomes its path plus hash; an off-site URL is
 * a genuine outbound link and is left exactly as it is.
 */
function localHref( href: string | undefined, origin: string | undefined ): string | undefined {
	if ( ! href || ! origin ) {
		return href;
	}
	try {
		const base = new URL( origin );
		const target = new URL( href, base );
		if ( target.origin !== base.origin ) {
			return href;
		}
		return `${ target.pathname }${ target.search }${ target.hash }`;
	} catch {
		return href;
	}
}

/**
 * Render the generated site-chrome module (120 §2) —
 * src/lib/chrome.generated.ts. The source header/footer facts (profile
 * design.chrome) bake into a deterministic module the layout reads; absent
 * chrome bakes `undefined` and the layout keeps its default chrome.
 */
export function renderChromeGenerated( profile: SiteProfile ): string {
	const chrome = profile.design?.chrome;
	const header = [
		'// ---------------------------------------------------------------------------',
		'// chrome.generated.ts — generated by nvoos-astro-forge (Proposal 120 §2);',
		'// edits are overwritten on re-scaffold. The source site chrome: topbar lines,',
		'// logo, tagline, nav, call button, footer blocks, copyright — facts only, the',
		'// layout rebuilds them with its own components. Deterministic: same profile,',
		'// same bytes.',
		'// ---------------------------------------------------------------------------',
		'',
		'export interface ForgeChromeStyle {',
		'	padding?: string;',
		'	radius?: string;',
		'	weight?: string;',
		'	size?: string;',
		'	transform?: string;',
		'	letterSpacing?: string;',
		'}',
		'',
		'export interface ForgeChrome {',
		'\ttopbar?: string[];',
		'\t/** The top strip STRUCTURE: text (with the source\u2019s own line breaks),',
		' * icon glyph class and link per item. Wins over `topbar` when present. */',
		'\ttopbarItems?: Array<{ text: string; icon?: string; href?: string }>;',
		'\t/** Where the items sit in the bar (measured against its content edges). */',
		"\ttopbarAlign?: 'start' | 'center' | 'end';",
		'\ttopbarHeight?: string;',
		'\ttopbarIconSize?: string;',
		'\ttopbarIconInk?: string;',
		'\ttopbarIconGap?: string;',
		'\ttopbarItemGap?: string;',
		'\t/** Icon-only links in the strip (the source\u2019s leading social glyphs). */',
		'\ttopbarSocials?: Array<{ icon: string; href?: string }>;',
		'	topbarBg?: string;',
		'	topbarInk?: string;',
		'	logo?: { src?: string; alt?: string };',
		'	tagline?: string;',
		'	nav?: Array<{ label: string; href: string }>;',
		'	navStyle?: ForgeChromeStyle;',
		'	navInk?: string;',
		'	/** The source\u2019s own mobile nav disclosure, when it ships one. `visible`',
		'	 * is the MEASURED presence per viewport, so the rebuild collapses exactly',
		'	 * where the source does instead of guessing a breakpoint. */',
		'	navToggle?: {',
		'		label?: string;',
		'		visible?: { desktop?: boolean; tablet?: boolean; mobile?: boolean };',
		'	};',
		'\theaderBg?: string;',
		'\theaderInk?: string;',
		'\t/** The MEASURED resting header is fully transparent (it floats over the',
		'\t * hero); `headerBg` is then absent on purpose. */',
		'\theaderTransparent?: boolean;',
		'	call?: { label: string; href: string; bg?: string; ink?: string; style?: ForgeChromeStyle };',
		'\tfooterBlocks?: Array<{ title?: string; items: string[] }>;',
		'\t/** The links a footer LINE carries, as `{ text, href }`. A builder',
		'\t * publishes the \u00a9 statement as one line with only the credit linked, so',
		'\t * the anchor is a SUFFIX inside a line, not a line of its own. */',
		'\tfooterLinks?: Array<{ text: string; href: string }>;',
		'\tfooterHeadingStyle?: ForgeChromeStyle;',
		'\tcopyright?: string;',
		'\t/** The footer social links (label/href plus the source icon class).',
		'\t * `href` is absent for a badge the source drives from JS. */',
		'\tsocial?: Array<{ label?: string; href?: string; icon?: string }>;',
		'	/** The site favicon (the first <link rel="icon"> href, absolute). */',
		'	favicon?: string;',
		'\t/** The source icon-font stylesheet (the icons CSS link, e.g. the theme\'s',
		'\t * auxin-icon.css) — the rebuild\'s icon markers carry the source glyph',
		'\t * classes and this sheet draws them (the customer\'s own asset). */',
		'\ticonCss?: string;',
		'\t/** The brand-icon sheet (FontAwesome) that draws the footer socials\u2019',
		'\t * `fab fa-*` glyphs. */',
		'\tsocialIconCss?: string;',
		'\t/** The subfooter text color (the source paints white-ish text on its',
		'\t * dark footer). */',
		'\tfooterInk?: string;',
		'	/** The footer brand logo (the footer-widget media image). `blockIndex` is',
		'	 * set when the source renders it as the first widget of a footer column',
		'	 * (the brand column), so the layout puts it INSIDE that block. */',
		'\tfooterLogo?: { src?: string; alt?: string; blockIndex?: number };',
		'	/** The footer background color (resolved from the footer-widget CSS). */',
		'	footerBg?: string;',
		'	/** The copyright bar\'s own background (a translucent navy on some sources). */',
		'	footerBarBg?: string;',
		'	/** The copyright bar\'s text color (usually translucent white). */',
		'	footerBarInk?: string;',
		'\t/** The chrome base font-size (the source chrome runs at its own size). */',
		'\tchromeSize?: string;',
		'\t/** The opened mobile menu\u2019s surface + rhythm (measured at 390px). */',
		'\tmobileNav?: {',
		'\t\tbackground?: string;',
		'\t\tink?: string;',
		'\t\tpadX?: number;',
		'\t\tpadY?: number;',
		'\t\titemPitch?: number;',
		'\t\toverlay?: boolean;',
		'\t\tfullWidth?: boolean;',
		'\t};',
		'\t/** The header\u2019s BOX per viewport (measured): the container\u2019s cap,',
		'\t * gutter and the brand mark\u2019s rendered height. `measure` is present only',
		'\t * where the cap is binding. */',
		'\t/** The header trailing controls: icon links and labelled CTA pills. */',
		'\theaderActions?: Array<{ icon?: string; label?: string; href?: string; text?: string; fill?: string; ink?: string; hideMobile?: boolean; style?: { padding?: string; radius?: string; size?: string; weight?: string; transform?: string; letterSpacing?: string } }>;',
		'\tgeometry?: {',
		"\t\twide?: { measure?: number; padX?: number; logoHeight?: string; logoAlign?: 'start' | 'center' | 'end' };",
		"\t\tdesktop?: { measure?: number; padX?: number; logoHeight?: string; logoAlign?: 'start' | 'center' | 'end' };",
		"\t\ttablet?: { measure?: number; padX?: number; logoHeight?: string; logoAlign?: 'start' | 'center' | 'end' };",
		"\t\tmobile?: { measure?: number; padX?: number; logoHeight?: string; logoAlign?: 'start' | 'center' | 'end' };",
		'\t};',
		'\t/** The footer brand ROW: where the brand mark and the social icons sit',
		'\t * inside it, per viewport (measured at 1920/1440/834/390). */',
		'\tfooterBrand?: {',
		"\t\twide?: { logo?: 'start' | 'center' | 'end'; socials?: 'start' | 'center' | 'end'; logoHeight?: string };",
		"\t\tdesktop?: { logo?: 'start' | 'center' | 'end'; socials?: 'start' | 'center' | 'end'; logoHeight?: string };",
		"\t\ttablet?: { logo?: 'start' | 'center' | 'end'; socials?: 'start' | 'center' | 'end'; logoHeight?: string };",
		"\t\tmobile?: { logo?: 'start' | 'center' | 'end'; socials?: 'start' | 'center' | 'end'; logoHeight?: string };",
		'\t\t/** The sub-footer row paints a hairline above the copyright bar. */',
		'\t\tdivider?: boolean;',
		'\t\t/** The social BADGE box (a builder\u2019s filled style is a rounded',
		'\t\t * SQUARE, not the template\u2019s 2.25rem circle). */',
		'\t\tsocial?: { size?: string; radius?: string; bg?: string; ink?: string };',
		'\t\t/** The footer\u2019s © row type + alignment (the template renders footer',
		'\t\t * blocks as left-aligned body copy). */',
		'\t\ttext?: { size?: string; weight?: string; ink?: string; linkInk?: string; align?: \'start\' | \'center\' | \'end\' };',
		'\t};',
		'\t/** True when the SOURCE exposes a theme / colour-mode control; absent',
		'\t * means the site has none, so the toggle is not rendered. */',
		'\tthemeToggle?: boolean;',
		'}',
		'',
	];
	if ( ! chrome ) {
		return [ ...header, 'export const chrome: ForgeChrome | undefined = undefined;', '' ].join( '\n' );
	}
	const entry: string[] = [];
	if ( chrome.topbar && chrome.topbar.length > 0 ) {
		entry.push( `topbar: ${ JSON.stringify( chrome.topbar ) }` );
	}
	if ( chrome.topbarItems && chrome.topbarItems.length > 0 ) {
		entry.push(
			`topbarItems: [${ chrome.topbarItems
				.map( ( item ) => {
					const parts = [ `text: ${ tsString( item.text ) }` ];
					if ( item.icon ) parts.push( `icon: ${ tsString( item.icon ) }` );
					if ( item.href ) parts.push( `href: ${ tsString( item.href ) }` );
					return `{ ${ parts.join( ', ' ) } }`;
				} )
				.join( ', ' ) }]`
		);
	}
	if ( chrome.topbarAlign ) {
		entry.push( `topbarAlign: ${ tsString( chrome.topbarAlign ) }` );
	}
	if ( chrome.topbarHeight ) {
		entry.push( `topbarHeight: ${ tsString( chrome.topbarHeight ) }` );
	}
	if ( chrome.topbarIconSize ) {
		entry.push( `topbarIconSize: ${ tsString( chrome.topbarIconSize ) }` );
	}
	if ( chrome.topbarIconInk ) {
		entry.push( `topbarIconInk: ${ tsString( chrome.topbarIconInk ) }` );
	}
	if ( chrome.topbarIconGap ) {
		entry.push( `topbarIconGap: ${ tsString( chrome.topbarIconGap ) }` );
	}
	if ( chrome.topbarItemGap ) {
		entry.push( `topbarItemGap: ${ tsString( chrome.topbarItemGap ) }` );
	}
	if ( chrome.topbarSocials && chrome.topbarSocials.length > 0 ) {
		entry.push(
			`topbarSocials: [${ chrome.topbarSocials
				.map( ( social ) =>
					`{ icon: ${ tsString( social.icon ) }${ social.href ? `, href: ${ tsString( social.href ) }` : '' } }`
				)
				.join( ', ' ) }]`
		);
	}
	if ( chrome.topbarBg ) {
		entry.push( `topbarBg: ${ tsString( chrome.topbarBg ) }` );
	}
	if ( chrome.topbarInk ) {
		entry.push( `topbarInk: ${ tsString( chrome.topbarInk ) }` );
	}
	if ( chrome.logo ) {
		const logo: string[] = [];
		if ( chrome.logo.src ) {
			logo.push( `src: ${ tsString( chrome.logo.src ) }` );
		}
		if ( chrome.logo.alt ) {
			logo.push( `alt: ${ tsString( chrome.logo.alt ) }` );
		}
		entry.push( `logo: { ${ logo.join( ', ' ) } }` );
	}
	if ( chrome.tagline ) {
		entry.push( `tagline: ${ tsString( chrome.tagline ) }` );
	}
	if ( chrome.nav && chrome.nav.length > 0 ) {
		entry.push(
			`nav: [${ chrome.nav
				.map( ( item ) => `{ label: ${ tsString( item.label ) }, href: ${ tsString( localHref( item.href, profile.url ) ?? item.href ?? '/' ) } }` )
				.join( ', ' ) }]`
		);
	}
	if ( chrome.navStyle ) {
		entry.push( `navStyle: ${ renderChromeStyle( chrome.navStyle ) }` );
	}
	if ( chrome.navInk ) {
		entry.push( `navInk: ${ tsString( chrome.navInk ) }` );
	}
	if ( chrome.mobileNav ) {
		const mobile = chrome.mobileNav;
		const parts: string[] = [];
		if ( mobile.background ) {
			parts.push( `background: ${ tsString( mobile.background ) }` );
		}
		if ( mobile.ink ) {
			parts.push( `ink: ${ tsString( mobile.ink ) }` );
		}
		for ( const key of [ 'padX', 'padY', 'itemPitch' ] as const ) {
			if ( typeof mobile[ key ] === 'number' ) {
				parts.push( `${ key }: ${ mobile[ key ] }` );
			}
		}
		if ( typeof mobile.overlay === 'boolean' ) {
			parts.push( `overlay: ${ mobile.overlay }` );
		}
		if ( typeof mobile.fullWidth === 'boolean' ) {
			parts.push( `fullWidth: ${ mobile.fullWidth }` );
		}
		if ( parts.length > 0 ) {
			entry.push( `mobileNav: { ${ parts.join( ', ' ) } }` );
		}
	}
	if ( chrome.geometry ) {
		const devices: string[] = [];
		for ( const device of [ 'wide', 'desktop', 'tablet', 'mobile' ] as const ) {
			const box = chrome.geometry[ device ];
			if ( ! box ) {
				continue;
			}
			const fields: string[] = [];
			if ( typeof box.measure === 'number' ) {
				fields.push( `measure: ${ box.measure }` );
			}
			if ( typeof box.padX === 'number' ) {
				fields.push( `padX: ${ box.padX }` );
			}
			if ( box.logoHeight ) {
				fields.push( `logoHeight: ${ tsString( box.logoHeight ) }` );
			}
			if ( box.logoAlign ) {
				fields.push( `logoAlign: ${ tsString( box.logoAlign ) }` );
			}
			if ( fields.length > 0 ) {
				devices.push( `${ device }: { ${ fields.join( ', ' ) } }` );
			}
		}
		if ( devices.length > 0 ) {
			entry.push( `geometry: { ${ devices.join( ', ' ) } }` );
		}
	}
	// The trailing ICON links and the labelled CTA PILLS, beside the per-viewport
	// boxes. A glyph comes from the vendor font (which never ships) so the
	// rebuild re-draws it; a pill carries its own measured fill/ink/box. Both are
	// `headerActions` to the template — it renders a pill when `text` is present.
	const actionFacts = [ ...( chrome.geometry?.actions ?? [] ), ...( chrome.headerActions ?? [] ) ].filter(
		( action, index, list ) =>
			list.findIndex( ( other ) => ( other.text ?? other.icon ?? other.label ) === ( action.text ?? action.icon ?? action.label ) ) === index
	);
	if ( actionFacts.length > 0 ) {
		const actions = actionFacts.map( ( action ) => {
			const parts: string[] = [];
			if ( action.icon ) {
				parts.push( `icon: ${ tsString( action.icon ) }` );
			}
			if ( action.label ) {
				parts.push( `label: ${ tsString( action.label ) }` );
			}
			if ( action.href ) {
				parts.push( `href: ${ tsString( action.href ) }` );
			}
			// A labelled pill (the source's own header CTA) plus its measured
			// fill/ink/box — the template renders it as a button, not a glyph.
			if ( action.text ) {
				parts.push( `text: ${ tsString( action.text ) }` );
			}
			if ( action.fill ) {
				parts.push( `fill: ${ tsString( action.fill ) }` );
			}
			if ( action.ink ) {
				parts.push( `ink: ${ tsString( action.ink ) }` );
			}
			if ( action.style ) {
				parts.push( `style: ${ JSON.stringify( action.style ) }` );
			}
			// The source hides its conversion pills on phones.
			if ( ( action as { hideMobile?: boolean } ).hideMobile ) {
				parts.push( 'hideMobile: true' );
			}
			return `{ ${ parts.join( ', ' ) } }`;
		} );
		entry.push( `headerActions: [ ${ actions.join( ', ' ) } ]` );
	}
	if ( chrome.footerBrand ) {
		const parts: string[] = [];
		for ( const device of [ 'wide', 'desktop', 'tablet', 'mobile' ] as const ) {
			const align = chrome.footerBrand[ device ];
			if ( ! align ) {
				continue;
			}
			const fields: string[] = [];
			if ( align.logo ) {
				fields.push( `logo: ${ tsString( align.logo ) }` );
			}
			if ( align.socials ) {
				fields.push( `socials: ${ tsString( align.socials ) }` );
			}
			if ( align.logoHeight ) {
				fields.push( `logoHeight: ${ tsString( align.logoHeight ) }` );
			}
			if ( fields.length > 0 ) {
				parts.push( `${ device }: { ${ fields.join( ', ' ) } }` );
			}
		}
		if ( typeof chrome.footerBrand.divider === 'boolean' ) {
			parts.push( `divider: ${ chrome.footerBrand.divider }` );
		}
		if ( chrome.footerBrand.social ) {
			const social = chrome.footerBrand.social;
			const fields = ( [ 'size', 'radius', 'bg', 'ink' ] as const )
				.filter( ( key ) => social[ key ] )
				.map( ( key ) => `${ key }: ${ tsString( social[ key ] as string ) }` );
			if ( fields.length > 0 ) {
				parts.push( `social: { ${ fields.join( ', ' ) } }` );
			}
		}
		if ( chrome.footerBrand.text ) {
			const text = chrome.footerBrand.text;
			const fields = ( [ 'size', 'weight', 'ink', 'linkInk', 'align' ] as const )
				.filter( ( key ) => text[ key ] )
				.map( ( key ) => `${ key }: ${ tsString( text[ key ] as string ) }` );
			if ( fields.length > 0 ) {
				parts.push( `text: { ${ fields.join( ', ' ) } }` );
			}
		}
		if ( parts.length > 0 ) {
			entry.push( `footerBrand: { ${ parts.join( ', ' ) } }` );
		}
	}
	if ( chrome.navToggle ) {
		const toggle: string[] = [];
		if ( chrome.navToggle.label ) {
			toggle.push( `label: ${ tsString( chrome.navToggle.label ) }` );
		}
		const visible = chrome.navToggle.visible;
		if ( visible ) {
			const devices = ( [ 'desktop', 'tablet', 'mobile' ] as const )
				.filter( ( device ) => typeof visible[ device ] === 'boolean' )
				.map( ( device ) => `${ device }: ${ visible[ device ] ? 'true' : 'false' }` );
			if ( devices.length > 0 ) {
				toggle.push( `visible: { ${ devices.join( ', ' ) } }` );
			}
		}
		if ( toggle.length > 0 ) {
			entry.push( `navToggle: { ${ toggle.join( ', ' ) } }` );
		}
	}
	if ( chrome.headerBg ) {
		entry.push( `headerBg: ${ tsString( chrome.headerBg ) }` );
	}
	if ( chrome.headerInk ) {
		entry.push( `headerInk: ${ tsString( chrome.headerInk ) }` );
	}
	if ( chrome.headerTransparent ) {
		entry.push( 'headerTransparent: true' );
	}
	if ( chrome.themeToggle ) {
		entry.push( 'themeToggle: true' );
	}
	if ( chrome.call ) {
		const call: string[] = [ `label: ${ tsString( chrome.call.label ) }`, `href: ${ tsString( chrome.call.href ) }` ];
		if ( chrome.call.bg ) {
			call.push( `bg: ${ tsString( chrome.call.bg ) }` );
		}
		if ( chrome.call.ink ) {
			call.push( `ink: ${ tsString( chrome.call.ink ) }` );
		}
		if ( chrome.call.style ) {
			call.push( `style: ${ renderChromeStyle( chrome.call.style ) }` );
		}
		entry.push( `call: { ${ call.join( ', ' ) } }` );
	}
	if ( chrome.footerBlocks && chrome.footerBlocks.length > 0 ) {
		entry.push(
			`footerBlocks: [${ chrome.footerBlocks
				.map( ( block ) => {
					const parts: string[] = [];
					if ( block.title !== undefined ) {
						parts.push( `title: ${ tsString( block.title ) }` );
					}
					parts.push( `items: [${ block.items.map( ( item ) => tsString( item ) ).join( ', ' ) }]` );
					return `{ ${ parts.join( ', ' ) } }`;
				} )
				.join( ', ' ) }]`
		);
	}
	if ( chrome.footerLinks && chrome.footerLinks.length > 0 ) {
		entry.push(
			`footerLinks: [ ${ chrome.footerLinks
				.map( ( link ) => `{ text: ${ tsString( link.text ) }, href: ${ tsString( link.href ) } }` )
				.join( ', ' ) } ]`
		);
	}
	if ( chrome.copyright ) {
		entry.push( `copyright: ${ tsString( chrome.copyright ) }` );
	}
	if ( chrome.social && chrome.social.length > 0 ) {
		const links = chrome.social.map( ( link ) => {
			const parts: string[] = [];
			if ( link.href ) {
				parts.push( `href: ${ tsString( link.href ) }` );
			}
			if ( link.label ) {
				parts.push( `label: ${ tsString( link.label ) }` );
			}
			if ( link.icon ) {
				parts.push( `icon: ${ tsString( link.icon ) }` );
			}
			return `{ ${ parts.join( ', ' ) } }`;
		} );
		entry.push( `social: [ ${ links.join( ', ' ) } ]` );
	}
	if ( chrome.favicon ) {
		entry.push( `favicon: ${ tsString( chrome.favicon ) }` );
	}
	if ( chrome.iconCss ) {
		entry.push( `iconCss: ${ tsString( chrome.iconCss ) }` );
	}
	if ( chrome.socialIconCss ) {
		entry.push( `socialIconCss: ${ tsString( chrome.socialIconCss ) }` );
	}
	if ( chrome.footerInk ) {
		entry.push( `footerInk: ${ tsString( chrome.footerInk ) }` );
	}
	if ( chrome.footerLogo?.src ) {
		const logo: string[] = [ `src: ${ tsString( chrome.footerLogo.src ) }` ];
		if ( chrome.footerLogo.alt ) {
			logo.push( `alt: ${ tsString( chrome.footerLogo.alt ) }` );
		}
		if ( chrome.footerLogo.blockIndex !== undefined ) {
			logo.push( `blockIndex: ${ chrome.footerLogo.blockIndex }` );
		}
		entry.push( `footerLogo: { ${ logo.join( ', ' ) } }` );
	}
	if ( chrome.footerBg ) {
		entry.push( `footerBg: ${ tsString( chrome.footerBg ) }` );
	}
	if ( chrome.footerHeadingStyle ) {
		entry.push( `footerHeadingStyle: ${ renderChromeStyle( chrome.footerHeadingStyle ) }` );
	}
	if ( chrome.footerBarBg ) {
		entry.push( `footerBarBg: ${ tsString( chrome.footerBarBg ) }` );
	}
	if ( chrome.footerBarInk ) {
		entry.push( `footerBarInk: ${ tsString( chrome.footerBarInk ) }` );
	}
	if ( chrome.chromeSize ) {
		entry.push( `chromeSize: ${ tsString( chrome.chromeSize ) }` );
	}
	return [ ...header, `export const chrome: ForgeChrome | undefined = { ${ entry.join( ', ' ) } };`, '' ].join( '\n' );
}

/** Render a ForgeChromeStyle object literal (tabs + single quotes). */
function renderChromeStyle( style: { padding?: string; radius?: string; weight?: string; size?: string; transform?: string; letterSpacing?: string } ): string {
	const parts: string[] = [];
	if ( style.padding ) {
		parts.push( `padding: ${ tsString( style.padding ) }` );
	}
	if ( style.radius ) {
		parts.push( `radius: ${ tsString( style.radius ) }` );
	}
	if ( style.weight ) {
		parts.push( `weight: ${ tsString( style.weight ) }` );
	}
	if ( style.size ) {
		parts.push( `size: ${ tsString( style.size ) }` );
	}
	if ( style.transform ) {
		parts.push( `transform: ${ tsString( style.transform ) }` );
	}
	if ( style.letterSpacing ) {
		parts.push( `letterSpacing: ${ tsString( style.letterSpacing ) }` );
	}
	return `{ ${ parts.join( ', ' ) } }`;
}

/**
 * Render the generated tracking module (130) — src/lib/tracking.generated.ts.
 * The profile's tracking block is the single source of truth; this artifact is
 * what the template's Tracking.astro + consent runtime read. Disabled/absent
 * tracking bakes an inert config, so a profile without trackers produces no
 * tracking markup at all.
 */
export function renderTrackingGenerated( profile: SiteProfile ): string {
	const tracking = profile.tracking;
	const vendors = ( tracking?.trackers ?? [] ).filter(
		( tracker ) => tracker.enabled && tracker.category !== 'verification' && tracker.category !== 'consent'
	);
	const consent = tracking?.consent;
	const lines: string[] = [
		'// ---------------------------------------------------------------------------',
		'// tracking.generated.ts — generated by nvoos-astro-forge (Proposal 130);',
		'// edits are overwritten on re-scaffold. The measured tracking surface: the',
		'// enabled vendors with their PUBLIC ids, the consent configuration, the',
		'// verification tokens and the delivery/ecommerce rules. The template',
		'// rebuilds every vendor with first-party delivery — consent-gated for a',
		'// cookie-setting tag, static for an operator-exempt cookieless beacon — and',
		'// never re-uses the source\'s snippets.',
		'// ---------------------------------------------------------------------------',
		'',
		'export type TrackingConsentCategory = \'analytics\' | \'advertising\' | \'functional\' | \'personalization\';',
		'',
		'export interface TrackingVendor {',
		'\tvendor: string;',
		'\tcategory: string;',
		'\tconsentCategory: TrackingConsentCategory;',
		'\t/** Cookieless audience measurement — stores nothing on the device. */',
		'\tcookieless?: boolean;',
		'\t/** Operator-declared basis to load without a consent decision. */',
		'\tconsentExempt?: boolean;',
		'\tids: Record<string, string>;',
		'}',
		'',
		'export interface TrackingConfig {',
		'\tenabled: boolean;',
		'\tvendors: TrackingVendor[];',
		'\tconsent: {',
		'\t\tmode: \'forge\' | \'external-cmp\' | \'consent-mode-only\' | \'none\';',
		'\t\tdefault: \'denied\' | \'granted\';',
		'\t\tstrategy: \'basic\' | \'advanced\';',
		'\t\twaitForUpdate: number;',
		'\t\tgpc: boolean;',
		'\t\tcmp?: { vendor?: string; script?: string; cookieName?: string };',
		'\t\tpolicy?: { privacy?: string; cookies?: string };',
		'\t\tbanner?: { position?: string; theme?: string };',
		'\t\tenforceCsp?: boolean;',
		'\t};',
		'\tverification?: Record<string, string>;',
		'\t/** SPA route measurement for the beacon; false for a static MPA build. */',
		'\tspa: boolean;',
		'\tdelivery: { mode: \'deferred\' | \'inline\' | \'partytown\' };',
		'\tecommerce?: { events?: \'ga4\' | \'none\'; purchaseSource?: \'wordpress\' | \'forge\' };',
		'}',
		'',
		'export const tracking: TrackingConfig = {',
		`\tenabled: ${ vendors.length > 0 },`,
		`\tspa: ${ tracking?.spa === true },`,
	];
	if ( vendors.length === 0 ) {
		lines.push( '\tvendors: [],' );
	} else {
		lines.push( '\tvendors: [' );
		for ( const vendor of vendors ) {
			const ids = Object.entries( vendor.ids )
				.map( ( [ key, value ] ) => `${ tsKey( key ) }: ${ tsString( value ) }` )
				.join( ', ' );
			// The consent exemption is reachable ONLY for a cookieless vendor. A
			// cookie-setting tag marked exempt is a compliance bug, so the flag is
			// dropped here rather than trusted — nothing downstream can re-enable it.
			const cookieless = isCookielessVendor( vendor.vendor );
			const exempt = cookieless && vendor.consentExempt === true;
			const fields = [
				`vendor: ${ tsString( vendor.vendor ) }`,
				`category: ${ tsString( vendor.category ) }`,
				`consentCategory: ${ tsString( consentCategoryFor( vendor.category ) ) }`,
				...( cookieless ? [ 'cookieless: true' ] : [] ),
				...( exempt ? [ 'consentExempt: true' ] : [] ),
				`ids: { ${ ids } }`,
			].join( ', ' );
			lines.push( `\t\t{ ${ fields } },` );
		}
		lines.push( '\t],' );
	}
	lines.push( '\tconsent: {' );
	lines.push( `\t\tmode: ${ tsString( consent?.mode ?? 'none' ) },` );
	lines.push( `\t\tdefault: ${ tsString( consent?.default ?? 'denied' ) },` );
	lines.push( `\t\tstrategy: ${ tsString( consent?.strategy ?? 'basic' ) },` );
	lines.push( `\t\twaitForUpdate: ${ Number( consent?.waitForUpdate ?? 500 ) },` );
	lines.push( `\t\tgpc: ${ consent?.gpc !== false },` );
	if ( consent?.cmp ) {
		const cmp = [
			consent.cmp.vendor ? `vendor: ${ tsString( consent.cmp.vendor ) }` : '',
			consent.cmp.script ? `script: ${ tsString( consent.cmp.script ) }` : '',
			consent.cmp.cookieName ? `cookieName: ${ tsString( consent.cmp.cookieName ) }` : '',
		]
			.filter( Boolean )
			.join( ', ' );
		lines.push( `\t\tcmp: { ${ cmp } },` );
	}
	if ( consent?.policy && ( consent.policy.privacy || consent.policy.cookies ) ) {
		const policy = [
			consent.policy.privacy ? `privacy: ${ tsString( consent.policy.privacy ) }` : '',
			consent.policy.cookies ? `cookies: ${ tsString( consent.policy.cookies ) }` : '',
		]
			.filter( Boolean )
			.join( ', ' );
		lines.push( `\t\tpolicy: { ${ policy } },` );
	}
	if ( consent?.banner ) {
		const banner = [
			consent.banner.position ? `position: ${ tsString( consent.banner.position ) }` : '',
			consent.banner.theme ? `theme: ${ tsString( consent.banner.theme ) }` : '',
		]
			.filter( Boolean )
			.join( ', ' );
		lines.push( `\t\tbanner: { ${ banner } },` );
	}
	if ( consent?.enforceCsp ) {
		lines.push( '\t\tenforceCsp: true,' );
	}
	lines.push( '\t},' );
	const verification = tracking?.verification;
	if ( verification ) {
		const entries = ( [ 'google', 'bing', 'yandex', 'pinterest', 'facebook' ] as const )
			.filter( ( key ) => Boolean( verification[ key ] ) )
			.map( ( key ) => `${ key }: ${ tsString( String( verification[ key ] ) ) }` );
		if ( entries.length > 0 ) {
			lines.push( `\tverification: { ${ entries.join( ', ' ) } },` );
		}
	}
	lines.push( `\tdelivery: { mode: ${ tsString( tracking?.delivery?.mode ?? 'deferred' ) } },` );
	if ( tracking?.ecommerce ) {
		const ecommerce = [
			tracking.ecommerce.events ? `events: ${ tsString( tracking.ecommerce.events ) }` : '',
			tracking.ecommerce.purchaseSource ? `purchaseSource: ${ tsString( tracking.ecommerce.purchaseSource ) }` : '',
		]
			.filter( Boolean )
			.join( ', ' );
		lines.push( `\tecommerce: { ${ ecommerce } },` );
	}
	lines.push( '};' );
	lines.push( '' );
	return lines.join( '\n' );
}

/** The verification proof files (normalized, traversal-safe). */
export function trackingVerificationFiles( profile: SiteProfile ): Array< { path: string; content: string } > {
	return ( profile.tracking?.verification?.files ?? [] )
		.map( ( file ) => ( {
			path: file.path.startsWith( '/' ) ? file.path : `/${ file.path }`,
			content: file.content,
		} ) )
		.filter( ( file ) => ! file.path.includes( '..' ) && /^\/[\w./-]+$/.test( file.path ) );
}

/**
 * Render `public/_headers` (Proposal 130 §2.11) — the CSP Cloudflare Pages /
 * Netlify read from the build root. Deliberately:
 *  • Report-Only unless the profile opts in (`consent.enforceCsp`), because an
 *    enforcing policy that misses one host takes a live site down;
 *  • no `default-src`, so frames/media/forms the forge cannot enumerate stay
 *    unrestricted — only the risky directives are constrained;
 *  • hosts from the ENABLED vendors only, plus the WordPress origin for the
 *    forms bridge POST and the CDN image passthrough, plus Cloudflare
 *    Turnstile's origin whenever a widget renders (2026-11-15).
 * Undefined when the site needs no host at all (no tracker, no widget).
 */
export function renderTrackingHeaders( profile: SiteProfile ): string | undefined {
	const tracking = profile.tracking;
	const turnstile = Boolean( profile.forms?.turnstile?.siteKey );
	const anyTracker = Boolean( tracking?.trackers.some( ( tracker ) => tracker.enabled ) );
	// A widget needs its origin allowed even on a site with no analytics —
	// otherwise the first enforcing policy silently kills the contact form.
	if ( ! anyTracker && ! turnstile ) {
		return undefined;
	}
	const origin = originOf( profile.url );
	const directives = cspDirectivesFor( tracking?.trackers ?? [], origin ? [ origin ] : [] );
	if ( turnstile ) {
		for ( const directive of TURNSTILE_DIRECTIVES ) {
			if ( ! directives[ directive ].includes( TURNSTILE_ORIGIN ) ) {
				directives[ directive ].push( TURNSTILE_ORIGIN );
			}
		}
	}
	if ( directives[ 'script-src' ].length === 0 && directives[ 'img-src' ].length === 0 && directives[ 'connect-src' ].length === 0 ) {
		return undefined;
	}
	const list = ( hosts: string[] ) => hosts.map( ( host ) => ` ${ host }` ).join( '' );
	const policy = [
		`script-src 'self' 'unsafe-inline'${ list( directives[ 'script-src' ] ) }`,
		`style-src 'self' 'unsafe-inline' https://fonts.googleapis.com`,
		`img-src 'self' data:${ list( directives[ 'img-src' ] ) }`,
		`connect-src 'self'${ list( directives[ 'connect-src' ] ) }`,
		// Only emitted when something needs framing: an absent frame-src leaves
		// frames unrestricted, and narrowing it for every site would break
		// embeds the forge cannot enumerate.
		...( directives[ 'frame-src' ].length > 0 ? [ `frame-src 'self'${ list( directives[ 'frame-src' ] ) }` ] : [] ),
		`font-src 'self' data: https://fonts.gstatic.com`,
		`object-src 'none'`,
		`base-uri 'self'`,
	].join( '; ' );
	const header = tracking?.consent?.enforceCsp ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
	return [
		'# Generated by nvoos-astro-forge (Proposal 130) — edit the profile, not this file.',
		'# Hosts are derived from the ENABLED vendors and any Turnstile widget. The',
		'# policy stays in Report-Only until tracking.consent.enforceCsp = true.',
		'/*',
		`  ${ header }: ${ policy }`,
		'',
	].join( '\n' );
}

/** `hostname` from a site URL, or undefined when unparseable. */
function hostnameOf( value: string ): string | undefined {
	try {
		return new URL( value ).hostname;
	} catch {
		return undefined;
	}
}

/** `https://host` from a site URL, or undefined when unparseable. */
function originOf( value: string ): string | undefined {
	try {
		const url = new URL( value );
		return url.origin;
	} catch {
		return undefined;
	}
}

/** `.env.example` lines for the tracking block (ids are public, not secrets). */
export function renderTrackingEnvLines( profile: SiteProfile ): string[] {
	const enabled = ( profile.tracking?.trackers ?? [] ).filter( ( tracker ) => tracker.enabled );
	if ( enabled.length === 0 ) {
		return [];
	}
	const lines = [
		'',
		'# Tracking ids are PUBLIC identifiers (they already ship in the page), never',
		'# secrets. Set these only to override the baked value per deploy (staging).',
	];
	for ( const tracker of enabled ) {
		const key = tracker.vendor.replace( /[^a-z0-9]+/gi, '_' ).toUpperCase();
		for ( const [ idKey, value ] of Object.entries( tracker.ids ) ) {
			const envKey = `PUBLIC_${ key }_${ idKey.replace( /([a-z])([A-Z])/g, '$1_$2' ).toUpperCase() }`;
			lines.push( `# ${ tracker.vendor } → ${ idKey }` );
			lines.push( `${ envKey }=${ value }` );
		}
	}
	return lines;
}

/**
 * Render the generated scroll-behavior module (121 §2.3) —
 * src/lib/behaviors.generated.ts. The source motion (profile design.behavior)
 * bakes into a deterministic array keyed by section id; the renderer looks
 * sections up and adds the behavior classes, and the layout reads the
 * headerSticky flag. Absent behaviors bake an empty pass.
 */
/**
 * MOTION.md — the motion contract written down (Proposal 136 §4.4).
 *
 * The same facts `behaviors.generated.ts` bakes, rendered as a table so an
 * operator (or an agent) can review what moves, how far, and what happens under
 * `prefers-reduced-motion` before approving a rebuild. Deterministic: no
 * timestamps, rows in source order.
 */
export function renderMotionDoc( profile: SiteProfile ): string {
	const entries = ( profile.design?.behavior?.entries ?? [] ) as Array<{
		id: string;
		kind: string;
		effect?: string;
		speed?: number;
		pieces?: Array<{ depth?: number; origin?: string; off?: number; direction?: string; speed?: number; axis?: string }>;
	}>;
	const header = [
		'# Motion contract',
		'',
		'Every band is complete at rest. Motion is additive and CSS-only;',
		'`prefers-reduced-motion` returns the page to its at-rest state.',
		'',
	];
	if ( entries.length === 0 ) {
		const stickyOnly = profile.stickyHeader?.sticky;
		if ( ! stickyOnly ) {
			// A site can have no scroll behaviour but still carry CSS keyframes /
			// transitions. Report the animated effects instead of claiming the
			// source has no motion at all.
			const animationLines = animationDocLines( profile );
			if ( animationLines.length > 0 ) {
				return [ ...header, ...animationLines, ...pageAnimationDocLines( profile ), '' ].join( '\n' );
			}
			return [ ...header, 'The source declares no scroll behaviour, so the rebuild has none.', '' ].join( '\n' );
		}
	}
	const sticky = profile.stickyHeader;
	const stickyLines: string[] = [];
	if ( sticky?.sticky ) {
		const changes: string[] = [];
		if ( sticky.bgChanged ) {
			changes.push(`background ${ sticky.restBg ?? 'transparent' } → ${ sticky.pinnedBg ?? '—' }` );
		}
		if ( sticky.heightChanged ) {
			changes.push( `height ${ sticky.restHeight }px → ${ sticky.pinnedHeight }px` );
		}
		stickyLines.push(
			`**Sticky header** — pins on scroll${ changes.length > 0 ? `, ${ changes.join( ', ' ) }` : '' }. ` +
				'Rebuilt as a `scroll(root)` timeline; the resting state is the base.'
		);
		stickyLines.push( '' );
	}
	if ( entries.length === 0 ) {
		return [ ...header, ...stickyLines, ...animationDocLines( profile ), '' ].join( '\n' );
	}
	const rows = entries.map( ( entry ) => {
		const behaviour = entry.effect && entry.kind !== 'sticky' ? `${ entry.kind } (${ entry.effect })` : entry.kind;
		const horizontal = entry.kind === 'parallax' && ( entry.pieces ?? [] ).some( ( piece ) => piece.axis === 'x' );
		const baseRange =
			entry.kind === 'parallax' && entry.speed
				? `±${ Math.min( entry.speed, 4 ) * 24 }px${ horizontal ? ' (horizontal)' : '' }`
				: '—';
		// A piece-level parallax band names its sources: depth/origin/off decide
		// direction and the mobile gate, so a reviewer can check the source facts.
		const range =
			entry.kind === 'parallax' && entry.pieces && entry.pieces.length > 0
				? `${ baseRange } × ${ entry.pieces.length } piece${ entry.pieces.length === 1 ? '' : 's' }`
				: baseRange;
		return `| \`${ entry.id }\` | ${ behaviour } | ${ range } | static |`;
	} );
	return [
		...header,
		...stickyLines,
		'| Band | Behaviour | Range | Reduced motion |',
		'| --- | --- | --- | --- |',
		...rows,
		'',
		...animationDocLines( profile ),
		...pageAnimationDocLines( profile ),
		'Generated from `profile.design.behavior` by nvoos-astro-forge — re-scaffolding overwrites this file.',
		'',
	].join( '\n' );
}

/**
 * The animated-effects table (2026-11-12) — the measured inventory and the
 * rebuild decision. Infinite motion and JS runtimes are named as deferred,
 * never silently dropped.
 */
function animationDocLines( profile: SiteProfile ): string[] {
	const inventory = profile.animations;
	if ( ! inventory || ( ( inventory.facts?.length ?? 0 ) === 0 && ( inventory.libraries?.length ?? 0 ) === 0 ) ) {
		return [];
	}
	const facts = planAnimationRebuild( inventory.facts ?? [], profile.design?.behavior, {
		allowInfinite: true,
	} );
	const lines = [ '## Animated effects', '' ];
	if ( inventory.libraries && inventory.libraries.length > 0 ) {
		lines.push( `Detected runtimes: ${ inventory.libraries.join( ', ' ) }.`, '' );
	}
	lines.push( '| Band | Effect | Target | Timing | Rebuild |', '| --- | --- | --- | --- | --- |' );
	for ( const fact of facts.slice( 0, 60 ) ) {
		const target =
			fact.target === 'media' && fact.mediaIndex !== undefined ? `media #${ fact.mediaIndex + 1 }` : fact.target ?? '—';
		const timing = [ fact.duration, fact.easing, fact.iterations ].filter( Boolean ).join( ' ' );
		const rebuild =
			fact.rebuild === 'css'
				? fact.infinite
					? 'CSS keyframes (Pause motion control)'
					: 'CSS keyframes'
				: fact.rebuild === 'mapped'
					? 'mapped'
					: `deferred${ fact.note ? ` — ${ fact.note }` : '' }`;
		lines.push( `| \`${ fact.bandId ?? '—' }\` | ${ fact.name } | ${ target } | ${ timing || '—' } | ${ rebuild } |` );
	}
	lines.push(
		'',
		'Infinite motion ships only with the page Pause motion control; JS runtimes are reported, never shipped.',
		''
	);
	return lines;
}

/**
 * The per-page animation summary (`analyze --animations-pages`). The generated
 * CSS is built from the HOMEPAGE inventory; a sub-page's facts are reported so
 * the operator can see what moved without pretending the homepage band ids
 * apply there.
 */
function pageAnimationDocLines( profile: SiteProfile ): string[] {
	const pages = profile.pageAnimations;
	if ( ! pages || Object.keys( pages ).length === 0 ) {
		return [];
	}
	const lines = [
		'## Per-page animated effects',
		'',
		'| Page | Facts | CSS rebuild | Deferred |',
		'| --- | --- | --- | --- |',
	];
	for ( const [ slug, inventory ] of Object.entries( pages ) ) {
		const facts = planAnimationRebuild( inventory.facts ?? [], profile.design?.behavior, { allowInfinite: true } );
		const counts = countAnimations( facts );
		lines.push( `| \`/${ slug }/\` | ${ facts.length } | ${ counts[ 'rebuild:css' ] ?? 0 } | ${ counts[ 'rebuild:deferred' ] ?? 0 } |` );
	}
	lines.push( '', 'The homepage inventory drives animations.generated.ts; per-page facts are reports.', '' );
	return lines;
}

export function renderBehaviorsGenerated( profile: SiteProfile ): string {
	const behavior = profile.design?.behavior;
	const header = [
		'// ---------------------------------------------------------------------------',
		'// behaviors.generated.ts — generated by nvoos-astro-forge (Proposal 121);',
		'// edits are overwritten on re-scaffold. The source scroll behavior: sticky,',
		'// reveal-on-scroll, and parallax entries keyed by the source section id, plus',
		'// the header-sticky flag. The template rebuilds the motion with its own',
		'// CSS-native runtime — facts only, never the source\'s JS.',
		'// ---------------------------------------------------------------------------',
		'',
		'export type BehaviorKind = \'sticky\' | \'reveal\' | \'parallax\';',
		'export type RevealEffect = \'fade-up\' | \'fade-down\' | \'fade-left\' | \'fade-right\' | \'scale-up\' | \'blur\' | \'fade\';',
		'',
		'/** One moving piece inside a parallax band (Proposal 121 + the element pass).',
		' * Phlox `aux-parallax-piece` (`depth`/`origin`/`off`) and Elementor',
		' * Motion Effects (`direction`/`speed`/`range`/`devices`) both land here.',
		' * Facts only — the template rebuilds them with its CSS scroll timeline. */',
		'export interface ParallaxPiece {',
		'\tid?: string;',
		'\tsrc?: string;',
		'\tindex?: number;',
		'	type?: \'position\' | \'background\';',
		'	/** The axis the piece travels on; absent = vertical. */',
		'	axis?: \'x\' | \'y\';',
		'\tdepth?: number;',
		'\torigin?: \'top\' | \'bottom\' | \'middle\';',
		'\toff?: number;',
		'\tdirection?: \'negative\' | \'positive\';',
		'\tspeed?: number;',
		'\trange?: { start?: number; end?: number };',
		'\tdevices?: string[];',
		'}',
		'',
		'export interface ScrollBehaviorEntry {',
		'\tid?: string;',
		'\tkind: BehaviorKind;',
		'\teffect?: RevealEffect;',
		'\tspeed?: number;',
		'\t/** The band’s moving pieces, source order (parallax only). */',
		'\tpieces?: ParallaxPiece[];',
		'}',
		'',
		'export const behaviors: {',
		'\theaderSticky: boolean;',
		'\t/** The source\u2019s own resting `position` \u2014 `fixed` means the header',
		'\t * floats OVER the first band instead of holding a row in the flow. */',
		'\theaderPosition?: string;',
		'\t/** The pinned header\u2019s measured state (`analyze --geometry`). Present only',
		'\t * when the source CHANGES the header on scroll; absent \u2192 the header keeps',
		'\t * its resting appearance when pinned. */',
		'\theaderPinnedBg?: string;',
		'\theaderPinnedInk?: string;',
		'\theaderPinnedShadow?: string;',
		'\t/** The measured resting and pinned header heights (a shrinking header). */',
		'\theaderRestHeight?: string;',
		'\theaderPinnedHeight?: string;',
		'\tentries: ScrollBehaviorEntry[];',
		'} = {',
		'\tentries: [',
	];
	const entries = behavior?.entries ?? [];
	const body: string[] = [];
	for ( const entry of entries ) {
		const parts: string[] = [];
		if ( entry.id ) {
			parts.push( `id: ${ tsString( entry.id ) }` );
		}
		parts.push( `kind: ${ tsString( entry.kind ) }` );
		if ( entry.effect ) {
			parts.push( `effect: ${ tsString( entry.effect ) }` );
		}
		if ( entry.speed !== undefined ) {
			parts.push( `speed: ${ entry.speed }` );
		}
		if ( entry.pieces && entry.pieces.length > 0 ) {
			const pieces = entry.pieces.map( ( piece ) => {
				const fields: string[] = [];
				if ( piece.id ) {
					fields.push( `id: ${ tsString( piece.id ) }` );
				}
				if ( piece.src ) {
					fields.push( `src: ${ tsString( piece.src ) }` );
				}
				if ( piece.index !== undefined ) {
					fields.push( `index: ${ piece.index }` );
				}
				if ( piece.type ) {
					fields.push( `type: ${ tsString( piece.type ) }` );
				}
				if ( piece.axis ) {
					fields.push( `axis: ${ tsString( piece.axis ) }` );
				}
				if ( piece.depth !== undefined ) {
					fields.push( `depth: ${ piece.depth }` );
				}
				if ( piece.origin ) {
					fields.push( `origin: ${ tsString( piece.origin ) }` );
				}
				if ( piece.off !== undefined ) {
					fields.push( `off: ${ piece.off }` );
				}
				if ( piece.direction ) {
					fields.push( `direction: ${ tsString( piece.direction ) }` );
				}
				if ( piece.speed !== undefined ) {
					fields.push( `speed: ${ piece.speed }` );
				}
				if ( piece.range && ( piece.range.start !== undefined || piece.range.end !== undefined ) ) {
					const rangeFields = [
						piece.range.start !== undefined ? `start: ${ piece.range.start }` : '',
						piece.range.end !== undefined ? `end: ${ piece.range.end }` : '',
					].filter( Boolean );
					fields.push( `range: { ${ rangeFields.join( ', ' ) } }` );
				}
				if ( piece.devices && piece.devices.length > 0 ) {
					fields.push( `devices: [${ piece.devices.map( ( device ) => tsString( device ) ).join( ', ' ) }]` );
				}
				return `{ ${ fields.join( ', ' ) } }`;
			} );
			parts.push( `pieces: [${ pieces.join( ', ' ) }]` );
		}
		body.push( `\t\t{ ${ parts.join( ', ' ) } },` );
	}
	body.push( '\t\t],' );
	// The measured sticky header (browser fact). A source that pins its header
	// counts as sticky even when no CSS class said so statically — the theme may
	// apply the class from JavaScript.
	const sticky = profile.stickyHeader;
	// Facts only for a header that actually pins — a pinned colour on a static
	// header is a contradiction the emitter must not publish.
	const pinned = sticky?.sticky ? sticky : undefined;
	body.push( `\t\theaderSticky: ${ behavior?.headerSticky || sticky?.sticky ? 'true' : 'false' },` );
	if ( pinned?.position ) {
		body.push( `\t\theaderPosition: ${ tsString( pinned.position ) },` );
	}
	if ( pinned?.pinnedBg ) {
		body.push( `\t\theaderPinnedBg: ${ tsString( pinned.pinnedBg ) },` );
	}
	if ( pinned?.pinnedInk ) {
		body.push( `\t\theaderPinnedInk: ${ tsString( pinned.pinnedInk ) },` );
	}
	if ( pinned?.shadow ) {
		body.push( `\t\theaderPinnedShadow: ${ tsString( pinned.shadow ) },` );
	}
	if ( pinned?.restHeight ) {
		body.push( `\t\theaderRestHeight: ${ tsString( `${ pinned.restHeight }px` ) },` );
	}
	if ( pinned?.pinnedHeight && pinned.pinnedHeight !== pinned.restHeight ) {
		body.push( `\t\theaderPinnedHeight: ${ tsString( `${ pinned.pinnedHeight }px` ) },` );
	}
	body.push( '\t};' );
	body.push( '' );
	return [ ...header, ...body ].join( '\n' );
}

/**
 * The media carriers a parallax band also animates, keyed by band + ordinal.
 *
 * A HERO band is excluded: global.css deliberately keeps `.ds-section-wrap--hero`
 * static (125 §5 — a band translate overlapped the flush band below it), so
 * composing the parallax animation into a hero carrier would silently undo that
 * decision. `piece.type === 'background'` is excluded too — the renderer skips
 * it rather than faking `background-position` with a transform.
 */
function parallaxCarriersOf( profile: SiteProfile ): ParallaxCarrier[] {
	const entries = profile.design?.behavior?.entries ?? [];
	const heroIds = new Set(
		( profile.design?.sections ?? [] )
			.filter( ( section ) => section.kind === 'hero' && section.id )
			.map( ( section ) => section.id as string )
	);

	const carriers: ParallaxCarrier[] = [];
	for ( const entry of entries ) {
		if ( entry.kind !== 'parallax' || ! entry.id || heroIds.has( entry.id ) ) {
			continue;
		}
		// `parallaxPieceCss` falls back to the piece's ARRAY POSITION, so mirror it.
		entry.pieces?.forEach( ( piece, position ) => {
			if ( piece.type === 'background' ) {
				return;
			}
			carriers.push( { bandId: entry.id as string, mediaIndex: piece.index ?? position } );
		} );
	}
	return carriers;
}

/**
 * Render `src/lib/animations.generated.ts` — the finite, layout-safe CSS
 * keyframe rebuild (2026-11-12). JS runtimes and infinite motion never reach
 * this file; they live in MOTION.md as deferred facts.
 */
export function renderAnimationsGenerated( profile: SiteProfile ): string {
	const facts = planAnimationRebuild( profile.animations?.facts ?? [], profile.design?.behavior, {
		allowInfinite: true,
	} );
	const css = animationRebuildCss( facts, parallaxCarriersOf( profile ) );
	const hasInfiniteMotion = facts.some( ( fact ) => fact.rebuild === 'css' && fact.infinite === true );
	return [
		'// ---------------------------------------------------------------------------',
		'// animations.generated.ts — generated by nvoos-astro-forge (analyze --animations);',
		'// edits are overwritten on re-scaffold. Finite, layout-safe CSS keyframes the',
		'// source runs, rebuilt as scoped CSS. JS runtimes are in MOTION.md, never',
		'// shipped. Infinite motion ships only with the page Pause motion control.',
		'// ---------------------------------------------------------------------------',
		'',
		`export const animationCss = ${ tsString( css ) };`,
		'',
		`export const hasInfiniteMotion = ${ hasInfiniteMotion ? 'true' : 'false' };`,
		'',
	].join( '\n' );
}

/**
 * WCAG contrast gate over the pairs the template renders — warns, never
 * blocks (114 §3.5: strict mode is the manifest flag, not this function).
 * With a design block present, every failing pair gets a suggested one-token
 * fix from the source's own unassigned pool.
 */
export function tokenWarnings(
	tokens?: Record< string, string >,
	design?: ForgeDesign,
	colors?: ForgeColorModes | Record< string, { text?: string } >
): string[] {
	const merged = mergeTokens( design, tokens, colors );
	const audit = contrastAudit( merged, DARK_TOKENS, highTokensFor( merged ) );
	const warnings = auditWarnings( audit );
	if ( design ) {
		const fixes = suggestContrastFixes(
			design,
			audit.failures.map( ( pair ) => ( {
				token: pair.token,
				label: pair.label,
				ratio: pair.ratio ?? 0,
				background:
					pair.mode === 'dark' ? DARK_TOKENS[ pair.background ] ?? merged[ pair.background ] : merged[ pair.background ],
				required: pair.required,
			} ) )
		);
		for ( const fix of fixes ) {
			if ( fix.suggested ) {
				warnings.push(
					`Suggest: set --forge-${ fix.token } to ${ fix.suggested } (from the source's unassigned pool) — ${ fix.label } becomes ${ fix.suggestedRatio?.toFixed( 2 ) }:1.`
				);
			}
		}
	}
	return warnings;
}

/** Ensure the target .gitignore covers the built-artifact gate. */
async function ensureGitignore(targetDir: string): Promise<void> {
	const gitignorePath = path.join(targetDir, '.gitignore');
	let content = '';
	try {
		content = await readFile(gitignorePath, 'utf8');
	} catch {
		content = '';
	}
	const required = ['node_modules/', 'dist/', '.astro/', '.env'];
	const missing = required.filter((entry) => !content.split(/\r?\n/).includes(entry));
	if (missing.length === 0) {
		return;
	}
	await writeFile(
		gitignorePath,
		`${content}${content.endsWith('\n') || content === '' ? '' : '\n'}# FORGE: never push built assets\n${missing.join('\n')}\n`,
		'utf8'
	);
}

/**
 * Dev dependencies injected into the GENERATED package.json only when the
 * ogImages surface is on (104 §4.8 / Q5) — the default scaffold stays
 * zero-dep for OG. Versions are pinned here; bump them together.
 */
export const OG_DEV_DEPENDENCIES: Record<string, string> = {
	'satori': '^0.63.2',
	'@resvg/resvg-js': '^2.6.2',
	'@fontsource/inter': '^5.3.0',
};

/**
 * Rewrite the generated astro.config.mjs for the render mode (104 Q4).
 * Line-anchored markers: the adapter import and the output/adapter config
 * exist only in 'ssr' mode; 'static' removes both lines. The template
 * carries the markers — matching is line-anchored like the FORGE:site span.
 */
export function renderAstroConfig(source: string, render: RenderMode, managed = false, frontController = false): string {
	const lines = source.split(/\r?\n/);
	const importLine = lines.findIndex((line) => line.trim() === '// FORGE:adapter-import');
	const configLine = lines.findIndex((line) => line.trim() === '// FORGE:render-config');
	if (importLine === -1 || configLine === -1) {
		throw new Error('astro.config.mjs is missing the FORGE:adapter-import / FORGE:render-config markers — the template is corrupt.');
	}
	const out: string[] = [];
	for (let i = 0; i < lines.length; i++) {
		if (managed && lines[i].trim() === "import { defineConfig } from 'astro/config';") {
			out.push("import { defineConfig, memoryCache } from 'astro/config';");
			continue;
		}
		if (i === importLine) {
			if (render === 'ssr') {
				out.push("import node from '@astrojs/node';");
			}
			continue;
		}
		if (i === configLine) {
			if (render === 'ssr') {
				out.push("\toutput: 'server',");
				out.push(
					frontController
						? "\tadapter: node({ mode: 'middleware' }),"
						: "\tadapter: node({ mode: 'standalone' }),"
				);
			}
			if (managed) {
				// Proposal 135: tag-based route caching backs the revalidate route.
				out.push('\t// Managed runtime (Proposal 135) — tag-based route caching.');
				out.push('\tcache: { provider: memoryCache() },');
				out.push('\trouteRules: {');
				out.push("\t\t'/products/**': { maxAge: 300, swr: 600 },");
				out.push("\t\t'/posts/**': { maxAge: 300, swr: 600 },");
				out.push("\t\t'/manage/**': { maxAge: 0 },");
				out.push("\t\t'/api/**': { maxAge: 0 },");
				out.push('\t},');
			}
			continue;
		}
		out.push(lines[i]);
	}
	return out.join('\n');
}

/**
 * Bake the OG card brand (accent/ink/surface) from the applied tokens —
 * the same merged values the FORGE:tokens span gets (098 output over the
 * ADS defaults), so the cards always match the site chrome.
 */
export function renderOgTokenBlock(tokens?: Record<string, string>): string {
	const merged = { ...DEFAULT_TOKENS, ...(tokens ?? {}) };
	return [
		`const OG_ACCENT = ${JSON.stringify(merged.accent)};`,
		`const OG_INK = ${JSON.stringify(merged.ink)};`,
		`const OG_SURFACE = ${JSON.stringify(merged.surface)};`,
	].join('\n');
}

/** Rewrite the FORGE:og-tokens span in the OG endpoint (line-anchored). */
export function rewriteOgTokens(source: string, tokens?: Record<string, string>): string {
	const lines = source.split(/\r?\n/);
	let start = -1;
	let end = -1;
	for (let i = 0; i < lines.length; i++) {
		const trimmed = lines[i].trim();
		if (trimmed === '// FORGE:og-tokens') start = i;
		if (trimmed === '// FORGE:og-tokens-end') {
			end = i;
			break;
		}
	}
	if (start === -1 || end === -1 || end <= start) {
		throw new Error('the OG endpoint is missing the FORGE:og-tokens markers — the template is corrupt.');
	}
	return [...lines.slice(0, start), renderOgTokenBlock(tokens), ...lines.slice(end + 1)].join('\n');
}

/** Read + write the generated package.json (JSON round-trip, tab-indented). */
async function mutatePackageJson(targetDir: string, mutate: (pkg: Record<string, unknown>) => void): Promise<void> {
	const pkgPath = path.join(targetDir, 'package.json');
	const pkg = JSON.parse(await readFile(pkgPath, 'utf8')) as Record<string, unknown>;
	mutate(pkg);
	await writeFile(pkgPath, `${JSON.stringify(pkg, null, '\t')}\n`, 'utf8');
}

/**
 * Inject `export const prerender = true;` right after the opening frontmatter
 * fence of an Astro page (104 Q4 — the SSR variant keeps the chrome static).
 * Files without a frontmatter fence are left untouched.
 */
async function addPrerender(file: string): Promise<void> {
	let source: string;
	try {
		source = await readFile(file, 'utf8');
	} catch {
		return; // pruned surface — nothing to inject into.
	}
	const lines = source.split(/\r?\n/);
	const fence = lines.findIndex((line) => line.trim() === '---');
	if (fence === -1) {
		return;
	}
	const close = lines.findIndex((line, index) => index > fence && line.trim() === '---');
	if (close === -1) {
		return; // no closing fence — not a frontmatter page.
	}
	if (lines.slice(0, close).some((line) => line.trim().startsWith('export const prerender'))) {
		return; // already declared — never double-inject.
	}
	lines.splice(fence + 1, 0, 'export const prerender = true;');
	await writeFile(file, lines.join('\n'), 'utf8');
}

/** Scaffold the Astro project. */
/**
 * Render the generated vocabulary module (116 §3) — src/lib/vocabulary.generated.ts.
 * The profile's learned widget/block roles; empty by default (the seeded
 * classifier dictionaries apply). Deterministic: same profile, same bytes.
 */
function renderVocabularyGenerated( profile: SiteProfile ): string {
	const widgetRoles = profile.vocabulary?.widgetRoles ?? {};
	const blockRoles = profile.vocabulary?.blockRoles ?? {};
	return [
		'// ---------------------------------------------------------------------------',
		'// vocabulary.generated.ts — generated by nvoos-astro-forge (Proposal 116);',
		'// edits are overwritten on re-scaffold. The profile\'s LEARNED widget/block',
		'// roles, merged over the classifier\'s seeded defaults at build time.',
		'// ---------------------------------------------------------------------------',
		'',
		"import type { BlockRole, WidgetRole } from '../forge/semantic.ts';",
		'',
		'export const vocabulary: {',
		'	widgetRoles: Record<string, WidgetRole>;',
		'	blockRoles: Record<string, BlockRole>;',
		'} = {',
		`	widgetRoles: ${ JSON.stringify( widgetRoles ) } as Record<string, WidgetRole>,`,
		`	blockRoles: ${ JSON.stringify( blockRoles ) } as Record<string, BlockRole>,`,
		'};',
		'',
	].join( '\n' );
}

/** Slugs that must never become wireframe routes (template routes + SEO surfaces). */
const RESERVED_WIREFRAME_SLUGS = new Set( [
	'index', 'posts', 'products', 'search', '404', 'sitemap', 'feed', 'redirects', 'og', 'rss',
] );

/**
 * Render the generated wireframe module (115 §3) — src/lib/wireframes.generated.ts.
 * The single source of truth stays the profile's wireframe block; this file is
 * the deterministic build artifact the template reads. The data payload is
 * plain JSON (valid TS) — nested slot structures do not round-trip through
 * the tsString helper, so the payload is documented as such.
 */
function renderWireframesGenerated( profile: SiteProfile ): string {
	const header = [
		'// ---------------------------------------------------------------------------',
		'// wireframes.generated.ts — generated by nvoos-astro-forge (Proposal 115);',
		'// edits are overwritten on re-scaffold. The wireframe block from the site',
		'// profile: per-page semantic blueprints for pages the REST collections do',
		'// not cover. Deterministic: same profile, same bytes.',
		'// ---------------------------------------------------------------------------',
		'',
		"import type { SemanticSection } from '../forge/semantic.ts';",
		'',
		'export interface WireframeResponsive {',
		'	breakpoints: string[];',
		'	columns?: Record<string, number>;',
		"	columnSource: 'explicit' | 'inferred';",
		'}',
		'',
		'export interface WireframeSection {',
		'	section: SemanticSection;',
		'	responsive: WireframeResponsive;',
		'}',
		'',
		'export interface Wireframe {',
		'	title: string;',
		'	slug: string;',
		'	source: string;',
		'	extractedAt: string;',
		"	status: 'captured' | 'placeholder';",
		'	danglingAnchors?: string[];',
		'	sections: WireframeSection[];',
		'}',
		'',
		'export const wireframes: Record<string, Wireframe> = ',
	].join( '\n' );
	return `${ header }${ JSON.stringify( profile.wireframes ?? {}, null, '\t' ) };\n`;
}

/** The per-wireframe static page (115 §3) — one route per source page. */
function wireframePage( slug: string ): string {
	return `---
// FORGE:wireframe-page — generated from the site profile's wireframe block (Proposal 115).
// The source site exposes this page but the REST collections do not serve it:
// the page rebuilds the captured wireframe — or a content-first placeholder
// when the source no longer serves the URL. Re-scaffold to regenerate.
import Base from '../layouts/Base.astro';
import DesignRenderer from '../components/design/DesignRenderer.astro';
import { wireframes } from '../lib/wireframes.generated.ts';

const wf = wireframes['${ slug }'];
const title = wf?.title ?? '${ slug }';
const sections = wf?.sections ?? [];
---

<Base title={title}>
\t<article class="post">
\t\t<h1>{title}</h1>
\t\t{wf?.status === 'placeholder' && (
\t\t\t<p class="ds-note">Placeholder page — the source site links here but no longer serves it. Rebuild the content in WordPress and the next forge run will capture it.</p>
\t\t)}\n\t\t<DesignRenderer wireframeSections={sections} />
\t</article>
</Base>
`;
}

/**
 * Emit the wireframe module + one static page per wireframe (115 §3).
 * Returns the written page routes and the skipped wireframes (reserved or
 * invalid slugs — the front page and template routes are never shadowed).
 */
async function emitWireframes(
	targetDir: string,
	profile: SiteProfile
): Promise<{ files: string[]; skipped: Array<{ slug: string }> }> {
	const files: string[] = [];
	const skipped: Array<{ slug: string }> = [];
	await writeFile(
		path.join( targetDir, 'src', 'lib', 'wireframes.generated.ts' ),
		renderWireframesGenerated( profile ),
		'utf8'
	);
	for ( const slug of Object.keys( profile.wireframes ?? {} ) ) {
		if ( ! /^[a-z0-9][a-z0-9-]*$/.test( slug ) || RESERVED_WIREFRAME_SLUGS.has( slug ) || slug === profile.frontPage ) {
			skipped.push( { slug } );
			continue;
		}
		const pagePath = path.join( targetDir, 'src', 'pages', `${ slug }.astro` );
		await writeFile( pagePath, wireframePage( slug ), 'utf8' );
		files.push( `src/pages/${ slug }.astro` );
	}
	return { files, skipped };
}

export async function scaffold(options: ScaffoldOptions): Promise<ScaffoldResult> {
	const finalDir = path.resolve(options.targetDir);

	// Safety: never clobber a non-empty directory that isn't a forge project.
	let targetExists = false;
	let targetEmpty = false;
	let existingManifest = false;
	try {
		await stat(finalDir);
		targetExists = true;
		targetEmpty = (await readdir(finalDir)).length === 0;
	} catch {
		// Missing target — fine.
	}
	if (targetExists && !targetEmpty) {
		try {
			await stat(path.join(finalDir, 'nvoos.json'));
			existingManifest = true;
		} catch {
			// No manifest.
		}
	}
	if (targetExists && !targetEmpty && !existingManifest && !options.overwrite) {
		throw new Error(
			`${finalDir} exists but is not a forge project. Pass overwrite: true to replace it.`
		);
	}
	// The handoff lock is fail-closed: a locked project belongs to its developer,
	// not the forge. Overwriting it would destroy hand-written work silently —
	// exactly the failure the codegen-ownership convention exists to prevent.
	// The handoff lock is fail-closed: a locked project belongs to its developer,
	// not the forge. Overwriting it would destroy hand-written work silently —
	// exactly the failure the codegen-ownership convention exists to prevent.
	//
	// The profile's `locked` flag covers the CI path: the runner scaffolds into a
	// FRESH temp target every time, so there is no forge.lock.json to find — the
	// ownership record travels with the committed profile instead.
	if (options.profile.locked && !options.unlock) {
		const lock = options.profile.locked;
		const owner = `${lock.by ?? 'an operator'} since ${lock.at}${lock.reason ? ` — ${lock.reason}` : ''}`;
		throw new Error(
			`This site profile is LOCKED for hand-editing (${owner}). ` +
				'The forge will not re-generate it. Run `forge unlock <profile> --target <dir> --yes` to return ownership, ' +
				'or pass --unlock to force this one run (destroys the custom work).'
		);
	}
	if (targetExists && existsSync(path.join(finalDir, 'forge.lock.json')) && !options.unlock) {
		const lock = await readLock(finalDir);
		const owner = lock
			? `${lock.lockedBy ?? 'an operator'} since ${lock.lockedAt}${lock.reason ? ` — ${lock.reason}` : ''}`
			: 'unknown (unreadable lock)';
		throw new Error(
			`${finalDir} is LOCKED for hand-editing (${owner}). ` +
				'The forge will not overwrite it. Re-run with --unlock to re-forge (this destroys the custom work), ' +
				'or scaffold into a new target.'
		);
	}

	// Scaffold BESIDE the target and swap once it is complete. Deleting the
	// target in place made a part-way failure destructive: an rmdir that hits
	// a lock (a dev server holding dist/ open on Windows) aborted an
	// --overwrite run after the old tree was already gone, which left a
	// half-written project that still built — with template-default tokens
	// instead of the site's.
	const stagingDir = `${finalDir}.forge-tmp-${process.pid}-${Date.now()}`;
	await rm(stagingDir, { recursive: true, force: true });
	try {
		const result = await scaffoldProject(options, stagingDir);
		await swapStagedProject(stagingDir, finalDir);
		return { ...result, targetDir: finalDir };
	} catch (error) {
		await rm(stagingDir, { recursive: true, force: true }).catch(() => undefined);
		throw error;
	}
}

/** Swap the finished staging tree into place, keeping the old tree alive until
 * the new one is committed. A locked target (a dev server, watcher or editor
 * holding it) fails the rename BEFORE anything is destroyed, with an
 * actionable message rather than a half-written project. */
/**
 * Rename with a bounded retry for the Windows lock window.
 *
 * A just-written 900 KB bundle (the §7b vendor step) can still be held by
 * Defender or the search indexer for a moment, and the rename of the staging
 * directory then answers EPERM/EBUSY. Retrying is the documented remedy; the
 * LAST error still surfaces, so a real lock is never masked.
 */
async function renameWithRetry( from: string, to: string, attempts = 5 ): Promise< void > {
	let last: unknown;
	for ( let attempt = 0; attempt < attempts; attempt += 1 ) {
		try {
			await rename( from, to );
			return;
		} catch ( error ) {
			last = error;
			const code = ( error as NodeJS.ErrnoException ).code ?? '';
			if ( code !== 'EPERM' && code !== 'EBUSY' && code !== 'EACCES' ) {
				throw error;
			}
			await new Promise( ( resolve ) => setTimeout( resolve, 50 * ( attempt + 1 ) ) );
		}
	}
	throw last;
}

async function swapStagedProject(stagingDir: string, finalDir: string): Promise<void> {
	const backupDir = `${finalDir}.forge-old-${process.pid}-${Date.now()}`;
	let hadTarget = false;
	try {
		await stat(finalDir);
		hadTarget = true;
	} catch {
		// First scaffold — nothing to replace.
	}

	if (hadTarget) {
		try {
			await rename(finalDir, backupDir);
		} catch (error) {
			throw new Error(
				`cannot replace ${finalDir}: something is holding it open (a dev server, a watcher or an editor). ` +
					`Stop it and re-run — the existing project was left untouched. (${String(error)})`
			);
		}
	}

	try {
		await renameWithRetry(stagingDir, finalDir);
	} catch (error) {
		if (hadTarget) {
			await rename(backupDir, finalDir).catch(() => undefined);
		}
		throw error;
	}

	if (hadTarget) {
		// Best effort: Windows can keep a just-released tree briefly locked.
		// The new project is already in place, so this must not fail the
		// scaffold — a leftover `<target>.forge-old-*` is the worst case.
		await rm(backupDir, { recursive: true, force: true }).catch(() => undefined);
	}
}

/** Write the whole project into `targetDir` (a staging path). */
async function scaffoldProject(
	options: ScaffoldOptions,
	targetDir: string
): Promise<ScaffoldResult> {
	const preset = PRESETS[options.preset ?? 'blog'];
	// A variant must be compatible with the PRESET — `product-3d` is a commerce
	// surface, and dropping a viewer (plus a vendored runtime) into a site with
	// no products is a silent misconfiguration. Fail before any write.
	if (options.variant && !preset.variants.includes(options.variant)) {
		const allowed = preset.variants.length > 0 ? preset.variants.join(', ') : 'none';
		throw new Error(
			`Template variant "${options.variant}" is not compatible with the "${preset.name}" preset (allowed: ${allowed}).`
		);
	}
	// The preset's collection-profile half applies here so a preset scaffold
	// is self-contained (idempotent when the caller already applied it —
	// applyPresetToProfile re-filters to the same result).
	// A materialized profile (the CMS is gone) then makes every collection
	// absent-tolerant: the snapshot defines what exists, and a collection it
	// does not carry is EMPTY, not a failed build.
	const profile = withMaterializedDefaults(applyPresetToProfile(options.profile, options.preset ?? 'blog'));
	// Proposal 135: the managed tier needs a Node process (the BFF + the
	// revalidate endpoint), so it requires server output. Infer it from the
	// runtime tier; an explicit conflicting --render still fails closed BEFORE
	// any writes — a half-scaffolded managed project is worse than an error.
	// `deploy.manage` is itself a managed-only surface (Proposal 165 §7), so it
	// implies the same tier even when `runtime` is omitted.
	const managed = profile.deploy?.runtime === 'managed' || profile.deploy?.manage === true;
	const render: RenderMode = options.render ?? (managed ? 'ssr' : preset.render);
	if (managed && render !== 'ssr') {
		throw new Error('A managed site uses server output — scaffold it with --render ssr (Proposal 135).');
	}
	// Proposal 165 §7b — the vendored bundle only makes sense inside the manage
	// page, and the manage page only exists on the managed tier. Refuse rather
	// than emit a project that half-has an editor.
	if ( profile.deploy?.manageBundle === true && ! ( managed && profile.deploy?.manage === true ) ) {
		throw new Error(
			'deploy.manageBundle requires BOTH deploy.runtime: "managed" and deploy.manage: true — the bundle is served by the /manage page (Proposal 165 §7b).'
		);
	}
	// Resolved inside the managed block below; hoisted because the manifest (§4)
	// records its fingerprints after every generated write has landed.
	let manageBundle: ManageBundle | undefined;
	const templateDir = await resolveTemplateDir(options.template);

	// 1. Copy the template (never copy its build artifacts or lockfile state).
	//    NOTE: filter by directory basename — a `\.astro$`-style regex would
	//    silently drop every `.astro` page file.
	await cp(templateDir, targetDir, {
		recursive: true,
		filter: (src) => {
			const base = path.basename(src);
			return base !== 'node_modules' && base !== 'dist' && base !== '.astro';
		},
	});

	// 1b. Optional template variant — an ADDITIVE overlay on the copy above, so
	//     the base template stays the single source of truth. Placement matters:
	//     it lands BEFORE the FORGE-marker rewrites, which must still win over
	//     any file a variant ships.
	if (options.variant) {
		if (!isTemplateVariant(options.variant)) {
			throw new Error(
				`Unknown template variant "${options.variant}" — expected one of: ${TEMPLATE_VARIANTS.join(', ')}`
			);
		}
		await applyTemplateVariant(targetDir, options.variant);
	}

	// 2. Vendor the forge libs so the customer repo builds standalone.
	const forgeDir = path.join(targetDir, 'src', 'forge');
	await mkdir(forgeDir, { recursive: true });
	for (const file of FORGE_LIB_FILES) {
		await copyFile(path.join(FORGE_ROOT, 'lib', file), path.join(forgeDir, file));
	}
	// The ECA normaliser is already vendored above (FORGE_LIB_FILES) because
	// `types.ts` imports its config type in every project.

	// 2b. Ship the NOTICE — MIT attribution for the vendored libs plus the
	//     generated-output rule (the customer owns the site).
	await copyFile(path.join(FORGE_ROOT, 'NOTICE'), path.join(targetDir, 'NOTICE'));

	// 3. Rewrite the content config for this profile.
	const configPath = path.join(targetDir, 'src', 'content.config.ts');
	const source = await readFile(configPath, 'utf8');
	await writeFile(configPath, rewriteContentConfig(source, profile), 'utf8');

	// 3a. ECA catalogue (Proposal 163 §P1) — the site config module. Grounded on
	//     `profile.eca` so a non-ECA rebuild is byte-identical to today's output.
	//     The routes that consume it land in §P2.
	if (profile.eca) {
		await writeFile(path.join(targetDir, ECA_CONFIG_PATH), renderEcaGenerated(profile), 'utf8');
	}

	// 3a0. Tracker & measurement capture (Proposal 130) — bake the measured
	//      tracking surface into src/lib/tracking.generated.ts and drop the
	//      ownership-verification proof files into public/ (Astro copies that
	//      directory verbatim, so no redirect chain and no 404). A profile
	//      without a tracking block leaves the template's inert stub alone.
	let hasTrackingModule = false;
	try {
		await stat( path.join( targetDir, 'src', 'lib', 'tracking.generated.ts' ) );
		hasTrackingModule = true;
		if ( profile.tracking ) {
			await writeFile(
				path.join( targetDir, 'src', 'lib', 'tracking.generated.ts' ),
				renderTrackingGenerated( profile ),
				'utf8'
			);
			for ( const file of trackingVerificationFiles( profile ) ) {
				const destination = path.join( targetDir, 'public', file.path.replace( /^\//, '' ) );
				await mkdir( path.dirname( destination ), { recursive: true } );
				await writeFile( destination, file.content, 'utf8' );
			}
		}
		// The CSP header file (130 §2.11 / Turnstile) — Report-Only by default;
		// static hosts (Cloudflare Pages / Netlify) read `_headers` from dist
		// root. Deliberately OUTSIDE the tracking guard: a widget needs its
		// origin allowed even when no tracker is enabled.
		const headers = renderTrackingHeaders( profile );
		if ( headers ) {
			await mkdir( path.join( targetDir, 'public' ), { recursive: true } );
			await writeFile( path.join( targetDir, 'public', '_headers' ), headers, 'utf8' );
		}
	} catch {
		// Template without the tracking module — nothing to write.
	}

	// 3a. Apply the design language (tokens_apply v2 — Proposal 114 §3.3) over
	//     the --forge-* defaults, gate the rendered pairs against WCAG, and
	//     write the resolved design module the audit endpoint reads.
	const mergedTokens = mergeTokens( profile.design, profile.tokens, profile.colors, accentFamilyOf( profile ) );
	const cssPath = path.join( targetDir, 'src', 'styles', 'global.css' );
	const css = await readFile( cssPath, 'utf8' );
	await writeFile( cssPath, rewriteGlobalCss( css, mergedTokens ), 'utf8' );
	// The source builder's PER-ELEMENT rules (`lib/element-css.ts`), already
	// gated + scoped to a band. The layout imports this after global.css, so a
	// recovered `#text-box-x{width:20%}` out-ranks the class-convention shim the
	// same way the vendor stylesheet did on the source.
	await writeFile(
		path.join( targetDir, 'src', 'styles', 'elements.generated.css' ),
		renderElementCss( profile.design?.elementCss ),
		'utf8'
	);
	// The resolved design module — templates without it (the builtin test
	// fixture) skip the write; the real template always ships it.
	try {
		await stat( path.join( targetDir, 'src', 'lib', 'design.generated.ts' ) );
		await writeFile(
			path.join( targetDir, 'src', 'lib', 'design.generated.ts' ),
			renderDesignGenerated( profile, mergedTokens ),
			'utf8'
		);
		// The learned vocabulary module (116 §3) ships alongside — the
		// template placeholder is overwritten with the profile's roles.
		await writeFile(
			path.join( targetDir, 'src', 'lib', 'vocabulary.generated.ts' ),
			renderVocabularyGenerated( profile ),
			'utf8'
		);
	// The section-theme module (117 §2) — the source band theming keyed
		// by section id, which the renderer re-applies per rebuilt section.
		await writeFile(
			path.join( targetDir, 'src', 'lib', 'sections.generated.ts' ),
			renderSectionsGenerated( profile ),
			'utf8'
		);
		// The per-page band module (multi-page geometry) — one positional list per
		// measured sub-page so a page never inherits the front page's band heights.
		await writeFile(
			path.join( targetDir, 'src', 'lib', 'pages.generated.ts' ),
			renderPagesGenerated( profile ),
			'utf8'
		);
		// The site-chrome module (120 §2) — the source header/footer facts
		// the layout rebuilds with its own components.
		await writeFile(
			path.join( targetDir, 'src', 'lib', 'chrome.generated.ts' ),
			renderChromeGenerated( profile ),
			'utf8'
		);
		await writeFile(
			path.join( targetDir, 'src', 'lib', 'behaviors.generated.ts' ),
			renderBehaviorsGenerated( profile ),
			'utf8'
		);
		// The animation inventory's safe CSS rebuild (2026-11-12) — templates
		// without the module (the builtin fixture) skip the write.
		try {
			await stat( path.join( targetDir, 'src', 'lib', 'animations.generated.ts' ) );
			await writeFile(
				path.join( targetDir, 'src', 'lib', 'animations.generated.ts' ),
				renderAnimationsGenerated( profile ),
				'utf8'
			);
		} catch {
			// Template without the animations module.
		}
		// MOTION.md — the human/agent-readable motion contract (136 §4.4). The
		// behaviours are already baked into behaviors.generated.ts; this is the
		// same facts written down so an operator (or an agent) can review what
		// moves before approving a rebuild.
		await writeFile( path.join( targetDir, 'MOTION.md' ), renderMotionDoc( profile ), 'utf8' );
		// The DTCG interchange document (126 §dtcg) — public/ ships verbatim
		// so the static build serves /tokens.dtcg.json: the machine-readable
		// design contract (NDS importer, ADS tooling) beside the flat CSS vars.
		await mkdir( path.join( targetDir, 'public' ), { recursive: true } );
		await writeFile(
			path.join( targetDir, 'public', 'tokens.dtcg.json' ),
			renderTokensDtcg( profile, mergedTokens ),
			'utf8'
		);
		// The generated-artifact drift manifest (126 §drift) is written at the
		// END of the scaffold (step 3g) — see writeGeneratedManifest(). It used
		// to be written here, BEFORE 3f rewrote src/lib/managed.generated.ts,
		// which made every managed site fail `npm run check` with a phantom
		// "drifted" report.
	} catch {
		// No design module in the template — nothing to write.
	}
	const warnings = tokenWarnings( mergedTokens, profile.design, profile.colors );
	// ECA scaffold advisories (Proposal 163 §P1) — a missing term list withholds
	// every .ics feed and a missing hand-off renders the register CTA as copy.
	// Both behaviours are correct but surprising, so the operator is told now.
	if (profile.eca) {
		warnings.push(...ecaScaffoldWarnings(profile));
	}

	// 3a2. Wireframe pages (Proposal 115 §3) — every page the profile's
	//      wireframe block captures becomes a static route, so the rebuild
	//      covers the full source site structure (missing REST pages and
	//      deleted pages included). Gated on the template's design renderer.
	const wireframePages: string[] = [];
	if ( profile.wireframes && Object.keys( profile.wireframes ).length > 0 ) {
		let hasDesignRenderer = false;
		try {
			await stat( path.join( targetDir, 'src', 'components', 'design', 'DesignRenderer.astro' ) );
			hasDesignRenderer = true;
		} catch {
			// No renderer — nothing to wire into.
		}
		if ( hasDesignRenderer ) {
			const emitted = await emitWireframes( targetDir, profile );
			wireframePages.push( ...emitted.files );
			for ( const skip of emitted.skipped ) {
				warnings.push( `Wireframe "${ skip.slug }" skipped — reserved or invalid page slug.` );
			}
		} else {
			warnings.push( 'The profile carries wireframes but the template has no design components — wireframe pages skipped.' );
		}
	}

	// 3b. Bake the theme policy (105 §4) — the policy module ships verbatim
	//     with the template copy; only the generated stub is rewritten. Custom
	//     templates without the policy module skip the stub.
	const themePolicy = options.themePolicy && isValidThemePolicy(options.themePolicy) ? options.themePolicy : 'auto';
	if (options.themePolicy !== undefined && themePolicy !== options.themePolicy) {
		warnings.push(
			`Unknown theme policy "${options.themePolicy}" — fell back to "auto" (use auto, light, dark or brand:<slug>).`
		);
	}
	let policyModuleExists = false;
	try {
		await stat(path.join(targetDir, 'src', 'lib', 'theme', 'policy.ts'));
		policyModuleExists = true;
	} catch {
		// No policy module — nothing to bake into.
	}
	if (policyModuleExists) {
		await writeFile(
			path.join(targetDir, THEME_POLICY_GENERATED_PATH),
			renderPolicyGenerated(themePolicy),
			'utf8'
		);
	}

	// 3c. Prune template pages for collections the profile doesn't declare
	//     (e.g. a blog-only profile must not ship product routes that would
	//     fail the build with an undefined collection).
	//     (e.g. a blog-only profile must not ship product routes that would
	//     fail the build with an undefined collection).
	const hasProducts = profile.collections.some((c) => c.slug === 'products');
	if (!hasProducts) {
		await rm(path.join(targetDir, 'src', 'pages', 'products'), { recursive: true, force: true });
	}

	// 3c-2. EXTRA collections — an arbitrary WordPress CPT (e.g. `portfolio`).
	//       `renderContentConfig` already defines a collection for every profile
	//       entry, but without a route an extra collection loads and publishes
	//       nothing. Emit a thin route pair that delegates to the generic
	//       components, so the core routes stay untouched.
	for (const collection of profile.collections) {
		if (CORE_COLLECTIONS.has(collection.slug)) {
			continue;
		}
		// The `eca` preset ships its own catalogue, landings and detail page
		// (Proposal 163 §P2) — the generic pair would emit conflicting dynamic
		// routes for the same passthrough.
		if (profile.eca && collection.slug === ecaCollection(profile).slug) {
			continue;
		}
		// `basePath` lets an extra collection keep the source's URLs
		// (/blog/portfolio/…); the route directory follows it, so the
		// component imports need the matching depth.
		const base = (collection.basePath ?? `/${collection.slug}`).replace(/\/+$/, '') || `/${collection.slug}`;
		const segments = base.split('/').filter(Boolean);
		const up = '../'.repeat(segments.length + 1);
		const dir = path.join(targetDir, 'src', 'pages', ...segments);
		await mkdir(dir, { recursive: true });
		await writeFile(path.join(dir, 'index.astro'), renderCollectionIndexRoute(collection.slug, base, up), 'utf8');
		await writeFile(path.join(dir, '[...parts].astro'), renderCollectionEntryRoute(collection.slug, base, up), 'utf8');
	}

	// 3c-3. The ECA front-end (Proposal 163 §P2) — catalogue, facet rail, detail
	//       page, four landing families and the .ics endpoint. Written as whole
	//       files because none of them is a variation on a core template route.
	if (profile.eca) {
		for (const file of ecaRouteFiles(profile)) {
			const destination = path.join(targetDir, file.path);
			await mkdir(path.dirname(destination), { recursive: true });
			await writeFile(destination, file.content, 'utf8');
		}
	}

	// 3d. Preset surface toggles + render mode (104 §4.3 / Q4) — the minimal
	//     preset drops the search page + Pagefind step; feed/sitemap pruning
	//     removes the endpoints; the render mode swaps the product pages and
	//     rewrites astro.config.mjs; the ogImages surface ships the OG
	//     endpoint + its devDeps. The manifest.surfaces flags gate the
	//     template chrome.
	const surfaces: PresetSurfaces = { ...preset.surfaces, ...(options.surfaces ?? {}) };
	if (!surfaces.search) {
		await rm(path.join(targetDir, 'src', 'pages', 'search.astro'), { force: true });
		await mutatePackageJson(targetDir, (pkg) => {
			const scripts = (pkg.scripts ?? {}) as Record<string, unknown>;
			if (typeof scripts.build === 'string') {
				scripts.build = scripts.build.replace(' && pagefind --site dist', '');
			}
		});
	}
	if (!surfaces.feed) {
		await rm(path.join(targetDir, 'src', 'pages', 'feed.xml.ts'), { force: true });
	}
	if (!surfaces.sitemap) {
		await rm(path.join(targetDir, 'src', 'pages', 'sitemap.xml.ts'), { force: true });
	}
	if (hasProducts) {
		if (render === 'ssr') {
			// Swap the static product pages for the on-demand route (the
			// _ssr dir is inert in the template — Astro skips underscore
			// dirs under src/pages/).
			await rm(path.join(targetDir, 'src', 'pages', 'products', '[...parts].astro'), { force: true });
			await rename(path.join(targetDir, 'src', 'pages', 'products', '_ssr'), path.join(targetDir, 'src', 'pages', 'products', '[id]'));
		} else {
			await rm(path.join(targetDir, 'src', 'pages', 'products', '_ssr'), { recursive: true, force: true });
		}
	}
	if (render === 'ssr') {
		// Pagefind indexes the client bundle in server-output mode (the
		// prerendered HTML lives under dist/client/, not dist/).
		await mutatePackageJson(targetDir, (pkg) => {
			const scripts = (pkg.scripts ?? {}) as Record<string, unknown>;
			if (typeof scripts.build === 'string') {
				scripts.build = scripts.build.replace('pagefind --site dist', 'pagefind --site dist/client');
			}
		});
		// Astro 7 server mode no longer prerenders getStaticPaths pages by
		// default — the SSR variant mirrors Proposal 104 P2: only the PDP
		// is on-demand; the rest of the chrome stays build-time static (so
		// pagefind, sitemap and the SEO surfaces keep working).
		for (const relative of [
			'src/pages/index.astro',
			'src/pages/[slug].astro',
			'src/pages/posts/[...parts].astro',
			...(hasProducts ? ['src/pages/products/index.astro'] : []),
			...(surfaces.search ? ['src/pages/search.astro'] : []),
		]) {
			await addPrerender(path.join(targetDir, ...relative.split('/')));
		}
	}
	if (surfaces.ogImages) {
		// Ship the OG endpoint: move it into place, bake the brand tokens,
		// and inject the render deps into the generated package.json.
		await rename( path.join( targetDir, 'src', 'pages', '_og' ), path.join( targetDir, 'src', 'pages', 'og' ) );
		const ogPath = path.join( targetDir, 'src', 'pages', 'og', '[slug].png.ts' );
		await writeFile( ogPath, rewriteOgTokens( await readFile( ogPath, 'utf8' ), mergedTokens ), 'utf8' );
		await mutatePackageJson(targetDir, (pkg) => {
			const devDeps = (pkg.devDependencies ?? {}) as Record<string, unknown>;
			for (const [name, version] of Object.entries(OG_DEV_DEPENDENCIES)) {
				devDeps[name] = version;
			}
			pkg.devDependencies = devDeps;
		});
	} else {
		await rm(path.join(targetDir, 'src', 'pages', '_og'), { recursive: true, force: true });
	}

	// 3e. Render-mode config rewrite (104 Q4) — server output + the node
	//     adapter only in ssr mode; the static config is untouched. Skipped
	//     when the template carries no config (the builtin test fixture).
	//     A profile with `gatePublish` gets the FRONT CONTROLLER (Proposal 165):
	//     middleware mode leaves no listener of its own, so `server.js` can gate
	//     before the router and still serve dist/client.
	const frontController = managed && Boolean( profile.gatePublish );
	const astroConfigPath = path.join(targetDir, 'astro.config.mjs');
	try {
		await writeFile(astroConfigPath, renderAstroConfig(await readFile(astroConfigPath, 'utf8'), render, managed, frontController), 'utf8');
	} catch (error) {
		if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
			throw error;
		}
	}

	// 3f. Managed runtime (Proposal 135 Phase 2) — the server-to-server proxy
	//     routes, the token-gated revalidate endpoint, the Velocity entry and
	//     the deploy-tree assembler. Only emitted for runtime: managed; the
	//     static tier is untouched. No secrets are written (env comes from the
	//     Velocity app).
	// Direct delivery needs a server to hold the provider key and to run the
	// per-IP throttle. A static build has neither, so refuse loudly rather
	// than emit an endpoint that cannot exist.
	const directDelivery = profile.forms?.delivery === 'direct';
	if (directDelivery && !managed) {
		throw new Error(
			'forms.delivery: "direct" requires deploy.runtime: "managed" — a static site has nowhere to keep the provider key. Use delivery: "bridge" (WordPress owns the mailer) or set the managed runtime.'
		);
	}
	if ( directDelivery && !profile.forms?.mail ) {
		throw new Error(
			'forms.delivery: "direct" requires forms.mail { provider, from, to } so the endpoint knows who sends and who receives.'
		);
	}
	// The Turnstile site key is PUBLIC (it is rendered into the page), so it
	// belongs in the profile — but a placeholder or a half-pasted key must not
	// reach a build. A wrong key is not a build failure: it is a form whose
	// widget never renders and whose submits all answer 400, discovered by the
	// first visitor (2026-11-20: a Sri Lanka profile was about to reuse the
	// Jamaica key, and Cloudflare scopes a widget to its hostnames). Refuse it
	// here, where the message can say which key and why.
	const turnstileKey = ( profile.forms?.turnstile?.siteKey ?? '' ).trim();
	if ( turnstileKey !== '' && ! /^(?:0x|1x)[A-Za-z0-9_-]{20,}$/.test( turnstileKey ) ) {
		const shown = turnstileKey.length > 24 ? `${ turnstileKey.slice( 0, 21 ) }…` : turnstileKey;
		throw new Error(
			`forms.turnstile.siteKey "${ shown }" is not a Turnstile site key — Cloudflare keys start "0x" (test keys "1x") and are 24 characters. Copy it from Cloudflare → Turnstile → the widget whose hostnames include this site.`
		);
	}
	// `forms.fallback` is the bridge-mode companion: the provider key still has
	// to live on a server, and the fallback cannot name a sender without `mail`.
	const directFallback = profile.forms?.fallback === 'direct';
	if (directFallback && !managed) {
		throw new Error(
			'forms.fallback: "direct" requires deploy.runtime: "managed" — a static site has nowhere to keep the provider key.'
		);
	}
	if (directFallback && !profile.forms?.mail) {
		throw new Error(
			'forms.fallback: "direct" requires forms.mail { provider, from, to } so the fallback knows who sends and who receives.'
		);
	}

	if (managed) {
		// §7b — resolve the bundle ONCE, before any of its consumers are written.
		// `resolveManageBundle` throws a named error when the bridge checkout is
		// absent, so a profile that opted in can never emit a /manage page whose
		// bundle 404s (runbook §4, rule 1).
		manageBundle = profile.deploy?.manageBundle === true ? await resolveManageBundle() : undefined;
		await mkdir(path.join(targetDir, 'src', 'lib'), { recursive: true });
		await writeFile(path.join(targetDir, 'src', 'lib', 'managed.generated.ts'), renderManagedGenerated(true), 'utf8');
		await writeFile(path.join(targetDir, 'src', 'lib', 'managed-proxy.ts'), renderProxyHelperTs(), 'utf8');

		await mkdir(path.join(targetDir, 'src', 'pages', 'api', 'forms'), { recursive: true });
		await writeFile(
			path.join(targetDir, 'src', 'pages', 'api', '[...path].ts'),
			renderApiProxyTs({ manageCredentials: Boolean(manageBundle) }),
			'utf8'
		);
		// `direct` sends through the provider API instead of the WordPress
		// bridge; the validation above guarantees this is the managed tier.
		await writeFile(
			path.join(targetDir, 'src', 'pages', 'api', 'forms', 'submit.ts'),
			directDelivery
				? renderDirectFormsEndpointTs({
						siteKey: profile.forms?.turnstile?.siteKey,
						action: profile.forms?.turnstile?.action,
						hostname: hostnameOf(profile.deploy?.publicOrigin ?? profile.url),
						onProviderError: profile.forms?.turnstile?.onProviderError,
					})
				: renderFormsProxyTs({ fallback: profile.forms?.fallback }),
			'utf8'
		);
		await writeFile(path.join(targetDir, 'src', 'pages', 'api', 'revalidate.ts'), renderRevalidateTs(), 'utf8');
		// The diagnostic surface: one request answers "is the BFF wired, and what
		// does the CMS say?" without shell access to the running app
		// (2026-11-26 — the only copy of the real upstream error was in PM2 stdout).
		await writeFile(path.join(targetDir, 'src', 'pages', 'api', 'health.ts'), renderHealthTs(), 'utf8');
		// Proposal 135 Phase 5 — the preview exchange + the draft-aware page.
		// `/manage` mints the token on the CMS; this route only transports it
		// into a host-only cookie and redirects to /preview/<slug>/.
		await writeFile(path.join(targetDir, 'src', 'pages', 'api', 'preview.ts'), renderPreviewApiTs(), 'utf8');
		await mkdir(path.join(targetDir, 'src', 'pages', 'preview'), { recursive: true });
		await writeFile(path.join(targetDir, 'src', 'pages', 'preview', '[...slug].astro'), renderPreviewPageTs(), 'utf8');

		// Proposal 165 §7 — the staff surface. `deploy.manage: true` emits a
		// self-contained sign-in (WordPress Application Password, verified
		// server-to-server) that mints the host-only session the gate trusts.
		// It works without the commerce bridge; when the bridge is installed the
		// same page keeps working and the bundle can be layered on top.
		if ( profile.deploy?.manage === true ) {
			const gate = resolveGateConfig( profile );
			const manage = {
				cookieName: gate.session.cookieName,
				ttlSeconds: gate.bypass.ttlSeconds,
				targetSite: ( profile.url ?? '' ).replace( /\/+$/, '' ),
				...( manageBundle
					? { bundle: { integrity: manageBundle.integrity, cssIntegrity: manageBundle.cssIntegrity } }
					: {} ),
			};
			const withBundle = Boolean( manageBundle );
			await mkdir(path.join(targetDir, 'src', 'pages', 'manage'), { recursive: true });
			await mkdir(path.join(targetDir, 'src', 'pages', 'api', 'manage'), { recursive: true });
			await writeFile(path.join(targetDir, 'src', 'lib', 'manage-session.ts'), renderManageSessionTs( manage ), 'utf8');
			await writeFile(path.join(targetDir, 'src', 'pages', 'manage', 'index.astro'), renderManagePageTs( manage ), 'utf8');
			await writeFile(path.join(targetDir, 'src', 'pages', 'api', 'manage', 'login.ts'), renderManageLoginTs({ bundle: withBundle }), 'utf8');
			await writeFile(path.join(targetDir, 'src', 'pages', 'api', 'manage', 'logout.ts'), renderManageLogoutTs({ bundle: withBundle }), 'utf8');
			await writeFile(path.join(targetDir, 'src', 'pages', 'api', 'manage', 'me.ts'), renderManageMeTs(), 'utf8');

			if ( manageBundle ) {
				// The credential the BFF holds, the provenance module, and the two
				// vendored files. Copied into the CUSTOMER project only — never into
				// templates/blog/, which the public forge repo mirrors.
				await writeFile(path.join(targetDir, 'src', 'lib', 'manage-credential.ts'), renderManageCredentialTs(), 'utf8');
				await writeFile(
					path.join(targetDir, 'src', 'lib', 'manage.generated.ts'),
					renderManageGeneratedTs({
						integrity: manageBundle.integrity,
						cssIntegrity: manageBundle.cssIntegrity,
						jsSha256: manageBundle.jsSha256,
						cssSha256: manageBundle.cssSha256,
						jsBytes: manageBundle.js.length,
						cssBytes: manageBundle.css.length,
					}),
					'utf8'
				);
				await mkdir(path.join(targetDir, 'public', 'manage-assets'), { recursive: true });
				await writeFile(path.join(targetDir, 'public', 'manage-assets', 'astro-admin.js'), manageBundle.js);
				await writeFile(path.join(targetDir, 'public', 'manage-assets', 'astro-admin.css'), manageBundle.css);
				await writeFile(
					path.join(targetDir, MANAGE_BOOT_FILE),
					renderManageBootJs({ integrity: manageBundle.integrity, cssIntegrity: manageBundle.cssIntegrity }),
					'utf8'
				);
			}
		}

		await mkdir(path.join(targetDir, 'src', 'pages', 'media'), { recursive: true });
		await writeFile(path.join(targetDir, 'src', 'pages', 'media', '[...path].ts'), renderMediaProxyTs(), 'utf8');

		await writeFile(path.join(targetDir, 'server.js'), frontController ? renderFrontController() : renderServerShim(), 'utf8');
		if ( frontController ) {
			await mkdir(path.join(targetDir, 'forge-gate'), { recursive: true });
			await writeFile(path.join(targetDir, 'forge-gate', 'runtime.mjs'), renderGateRuntime(), 'utf8');
			await writeFile(path.join(targetDir, 'forge-gate', 'static.mjs'), renderGateStatic(), 'utf8');
		}
		await writeFile(path.join(targetDir, 'scripts', 'sync-deploy-tree.mjs'), renderSyncDeployTree(), 'utf8');
		await writeFile(path.join(targetDir, 'scripts', 'verify-deploy-tree.mjs'), renderVerifyDeployTree(), 'utf8');
		await mutatePackageJson(targetDir, (pkg) => {
			const scripts = (pkg.scripts ?? {}) as Record<string, unknown>;
			scripts['sync-deploy-tree'] = 'node scripts/sync-deploy-tree.mjs';
			scripts['verify-deploy-tree'] = 'node scripts/verify-deploy-tree.mjs';
			pkg.scripts = scripts;
		});
	}

	// Proposal 165 — the publication verdict every tier reads (the static
	// postbuild, the managed front controller). Written BEFORE the drift
	// manifest so it is hashed with the other generated files, and even when the
	// gate is off: it is the runtime's boot state, so a cold start during a gate
	// still knows to gate before its first poll.
	await writeGateManifest( targetDir, options.gate ?? offPublication(), profile );

	// 3g. Generated-artifact drift manifest (Proposal 126 §drift) — sha256 of
	//     every generated/rewritten file. Written LAST, after every other
	//     generated write has landed (the managed block above rewrites
	//     src/lib/managed.generated.ts). The template's
	//     scripts/check-generated.mjs re-hashes on `npm run check` and fails on
	//     hand-edits or stale scaffolds.
	try {
		await writeGeneratedManifest(targetDir);
	} catch {
		// No design module in the template — nothing to manifest.
	}

	// 4. Manifest + env example + gitignore gate.
	const forms = profile.forms
		? {
				endpoint: profile.forms.endpoint ?? `${ profile.url.replace( /\/+$/, '' ) }/wp-json/nvoos-forge/v1`,
				origin: profile.forms.origin,
				searchForms: profile.forms.searchForms,
				delivery: profile.forms.delivery ?? 'bridge',
				mail: profile.forms.mail,
				turnstile: profile.forms.turnstile,
		  }
		: undefined;
	const contactBlock = resolveContactBlock( profile );
	const manifest: ForgeManifest = {
		version: 1,
		generator: 'nvoos-astro-forge',
		url: profile.url.replace(/\/+$/, ''),
		name: profile.name ?? 'Forge Site',
		...( options.siteSlug ? { site: options.siteSlug } : {} ),
		shape: resolveSiteShape( profile ),
		navAnchors: siteNavAnchors( profile ),
		frontPage: profile.frontPage ?? '',
		// Always emitted (never omitted): the template reads it directly and a
		// missing key makes `astro check` fail on the inferred JSON type.
		seoDescription: profile.seo?.description ?? '',
		// The site-level social image (`seo.ogImage`). Emitted as an ABSOLUTE
		// source URL: the post-build localizer mirrors it and rewrites the meta
		// tag to the public origin (a root-relative og:image is invalid).
		seoImage: profile.seo?.ogImage ?? '',
		contentSource: profile.content?.source ?? 'wordpress',
		// Route-conflict policy (2026-11-22): opt-in strictness for the
		// generated astro.config.mjs. Absent = Astro's `warn` default.
		...( profile.build?.strictRoutes ? { build: { strictRoutes: true } } : {} ),
		tokens: profile.tokens,
		design: profile.design,
		designAudit: options.strictContrast ? 'strict' : 'warn',
		forms,
		// Proposal 165 §7 — the staff surface. `bundle` appears only when the
		// bundle was vendored, and it is the provenance the drift/audit path reads.
		...( profile.deploy?.manage === true
			? {
					admin: {
						enabled: true,
						sessionCookie: resolveGateConfig( profile ).session.cookieName,
						...( manageBundle
							? {
									bundle: {
										src: '/manage-assets/astro-admin.js',
										css: '/manage-assets/astro-admin.css',
										integrity: manageBundle.integrity,
										cssIntegrity: manageBundle.cssIntegrity,
									},
							  }
							: {} ),
					},
			  }
			: {} ),
		// Proposal 139 — the media policy, emitted only when the profile sets
		// one: an absent block IS the default (CMS host, localization on).
		...( profile.media
			? {
					media: {
						...( profile.media.hosts && profile.media.hosts.length > 0
							? { hosts: profile.media.hosts }
							: {} ),
						...( profile.media.localize !== undefined ? { localize: profile.media.localize } : {} ),
						...( profile.media.maxFileMb !== undefined ? { maxFileMb: profile.media.maxFileMb } : {} ),
						...( profile.media.mode !== undefined ? { mode: profile.media.mode } : {} ),
						...( profile.media.snapshot !== undefined ? { snapshot: profile.media.snapshot } : {} ),
					},
			  }
			: {} ),
		// Issue #7549 — a theme-injected contact band cannot come from content;
		// the profile declares its copy so the front page can render `#contact`.
		// An ECA profile keeps its people in ONE place (`eca.contacts`, shared with
		// `/help/`), so the contact band is derived from them rather than copied
		// into a second list that drifts. An explicit `contact` block still wins.
		...( contactBlock ? { contact: contactBlock } : {} ),
		surfaces,
		render,
		// Always emitted (139): the media pass reads it to choose between
		// mirroring the library (static) and rewriting uploads to the
		// same-origin `/media/*` proxy (managed).
		runtime: managed ? 'managed' : 'static',
		// (The staff surface block is emitted ABOVE — a second `admin` key here
		// would silently win and drop the bundle provenance.)
		...( hasTrackingModule && profile.tracking
			? {
					tracking: {
						enabled: profile.tracking.trackers.some( ( tracker ) => tracker.enabled ),
						vendors: profile.tracking.trackers.filter( ( tracker ) => tracker.enabled ).map( ( tracker ) => tracker.vendor ),
						consentMode: profile.tracking.consent.mode,
						hasVerification: Boolean( profile.tracking.verification ),
						// Cookieless vendors the operator exempted from the decision. Mirrors
						// the generator's guard: the flag is inert for a cookie-setting tag.
						consentExempt: profile.tracking.trackers
							.filter( ( tracker ) => tracker.enabled && tracker.consentExempt === true && isCookielessVendor( tracker.vendor ) )
							.map( ( tracker ) => tracker.vendor ),
					},
					redirects: profile.redirects ?? [],
			  }
			: {} ),
		generatedAt: new Date().toISOString(),
		collections: profile.collections.map((c) => ({
			slug: c.slug,
			restBase: collectionRestBase(c),
			kind: c.kind,
			// Explicit loader defaults keep both keys present in the JSON so
			// the manifest's inferred type exposes them (the SSR route reads
			// source/authMode/seoFrom at request time). The wc/v3 default points
			// at Woo's product CPT so a hand-written profile still gets SEO meta.
			source: c.source ?? 'wp',
			authMode: c.authMode ?? 'application-password',
			seoFrom: c.seoFrom ?? (c.kind === 'product' && c.restBase.startsWith('wc/') ? 'wp/v2/product' : ''),
			...( c.basePath ? { basePath: c.basePath } : {} ),
			exclude: c.exclude,
		})),
	};
	await writeFile(
		path.join(targetDir, 'nvoos.json'),
		`${JSON.stringify(manifest, null, '\t')}\n`,
		'utf8'
	);
	// The profile is the single source of truth for everything that is NOT a
	// secret: the CMS origin, the public origin and the form envelope are all
	// already in it, so the env example is FILLED IN and the operator supplies
	// only credentials. (virtuallyjamaica, 2026-11-09: a fresh scaffold still
	// shipped MAIL_FROM= blank and NVOOS_TARGET_SITE= blank while the profile
	// held both values.)
	const publicOrigin = ( profile.deploy?.publicOrigin ?? profile.url ?? '' ).replace( /\/+$/, '' );
	const cmsOrigin = ( profile.url ?? '' ).replace( /\/+$/, '' );
	const mail = profile.forms?.mail;
	const mailProvider = mail?.provider ?? 'brevo';
	await writeFile(
		path.join(targetDir, '.env.example'),
		[
			'# The WordPress source this frontend is generated from.',
			`WP_SITE_URL=${profile.url.replace(/\/+$/, '')}`,
			'',
			'# Proposal 139 — extra image hosts Astro may optimize and the build may',
			'# mirror (a CDN, an image proxy, or a second domain the media lives on).',
			'# Comma separated. The CMS host is always authorized; a stale',
			'# /wp-content/ URL is repaired to it instead of hotlinked.',
			`NVOOS_FORGE_MEDIA_HOSTS=${( profile.media?.hosts ?? [] ).join( ',' )}`,
			'',
			'# Proposal 139 — after the build, mirror every remote image still',
			'# referenced into dist/media so the deployed site never hotlinks the CMS.',
			'# Empty (default) = on; 0 = off.',
			`NVOOS_FORGE_LOCALIZE_MEDIA=${profile.media?.localize === false ? '0' : ''}`,
			'# Per-document cap in MB for linked files (catalogues, price lists).',
			'NVOOS_FORGE_MEDIA_MAX_DOC_MB=',
			'# Media strategy: local (mirror into dist/media), proxy (rewrite uploads',
			'# to the managed runtime\'s same-origin /media proxy), or off. Empty =',
			'# the profile\'s media.mode, else local for the static tier and proxy',
			'# when the managed bridge is deployed.',
			`NVOOS_FORGE_MEDIA_MODE=${profile.media?.mode ?? ''}`,
			...( profile.media?.maxFileMb !== undefined
				? [ `NVOOS_FORGE_MEDIA_MAX_MB=${profile.media.maxFileMb}` ]
				: [] ),
			'',
			'# Proposal 140 — re-encode mirrored raster images to WebP before they',
			'# ship. WebP 82 is the measured visual equivalent of JPEG 80.',
			'# Empty (default) = 82; 0 disables re-encoding.',
			'NVOOS_FORGE_MEDIA_WEBP_QUALITY=',
			'',
			'# Proposal 140 — self-host the Google Fonts stylesheet and its woff2',
			'# files, so the page carries no render-blocking cross-origin chain.',
			'# Empty (default) = self-host; 0 keeps the remote <link>.',
			'NVOOS_FORGE_LOCALIZE_FONTS=',
			'',
			'# Build identity (2026-11-15) — stamped into <meta name="forge-build"> and',
			'# deploy.json, so `forge deployed` can prove the live site is this build.',
			'# CI sets it from the commit + run id; locally use a unique value.',
			'FORGE_BUILD_ID=',
			'',
			'# Optional — WordPress Application Passwords for private content.',
			'# Never commit real credentials; copy to .env (git-ignored).',
			'WP_APPLICATION_USER=',
			'WP_APPLICATION_PASSWORD=',
			'',
			'# Optional — WooCommerce REST consumer key/secret (wc/v3 collections,',
			'# created under WooCommerce → Settings → Advanced → REST API).',
			'WP_WC_CONSUMER_KEY=',
			'WP_WC_CONSUMER_SECRET=',
			'',
			'# Form bridge (Proposal 114 §3.9) — the rebuilt forms POST submissions',
			'# to the NV oOS Forge Bridge on WordPress, which dispatches them to the',
			'# native JetFormBuilder / Elementor Pro handler. Defaults to the value',
			'# in nvoos.json forms.endpoint; set this to override per deploy.',
			`NVOOS_FORGE_FORMS_ENDPOINT=${ profile.forms?.endpoint ?? '' }`,
			'',
			'# The static origin serving this site — enter the SAME value in the',
			'# bridge plugin\'s Settings → Forge Bridge → Forms origin allowlist.',
			`NVOOS_FORGE_FORMS_ORIGIN=${ profile.forms?.origin ?? publicOrigin }`,
			'',
			// Direct delivery only. Deliverability is a DOMAIN concern: SPF, DKIM
			// and DMARC must be published for MAIL_FROM's domain or the message is
			// sent and then filtered. Use an address on the provider domain
			// (e.g. forms@mail.yourdomain.com), not a site-only mailbox.
			...( profile.forms?.delivery === 'direct'
				? [
					`MAIL_PROVIDER=${ mailProvider }`,
					`MAIL_FROM=${ mail?.from ?? '' }`,
					`MAIL_FROM_NAME=${ mail?.fromName ?? '' }`,
					`MAIL_TO=${ mail?.to ?? '' }`,
					`MAIL_SUBJECT=${ mail?.subject ?? 'New website enquiry' }`,
					'',
					'# The provider secret lives in the host environment, never in the repo.',
					mailProvider === 'mailjet' ? '# BREVO_API_KEY=' : 'BREVO_API_KEY=',
					mailProvider === 'mailjet' ? 'MAILJET_API_KEY=' : '# MAILJET_API_KEY=',
					mailProvider === 'mailjet' ? 'MAILJET_SECRET_KEY=' : '# MAILJET_SECRET_KEY=',
					'',
				  ]
				: [] ),
		// Turnstile — the widget is chrome, `siteverify` is the boundary, and a
		// MISSING secret makes the endpoint accept every submission UNVERIFIED
		// (it warns once and continues). An operator who never sees the name
		// cannot set it, so the secret is emitted beside the key that needs it.
		...( profile.forms?.turnstile?.siteKey
			? [
				'# Cloudflare Turnstile — the SECRET paired with forms.turnstile.siteKey.',
				'# Unset accepts every form submission unverified (the endpoint warns once).',
				'TURNSTILE_SECRET=',
				'',
			  ]
			: [] ),
			'# Stored redirects (Proposal 132) — the build reads the redirect rules',
			'# your WordPress redirect plugin already stores (Redirection / Rank Math)',
			'# from the Forge Bridge, so legacy URLs keep resolving after cutover.',
			'# Defaults to WP_SITE_URL (or the manifest URL); set this only to point',
			'# at a different bridge, e.g. a staging source.',
			'NVOOS_FORGE_REDIRECTS_ENDPOINT=',
			'',
			'# Optional — only when the bridge\'s stored-redirects export is token-gated.',
			'NVOOS_FORGE_REDIRECTS_TOKEN=',
			'',
			'# Build-time fetch proxy (bot-protected sources): route ALL build',
			'# fetches through an allowlisted proxy (http://user:pass@host:port).',
			'# Unset = direct fetches.',
			'WP_FETCH_PROXY=',
			'',
			'# Page gate (Proposal 152) — consumed by `forge analyze` / `forge gate`',
			'# runs (export them in the shell), NOT by the generated build. A',
			'# pre-launch site answers 503 or a coming-soon page on HTML while',
			'# /wp-json/ stays open. Fill ONE of:',
			'#   WP_GATE_USERNAME + WP_GATE_PASSWORD  (a user the gate\'s allowlist accepts)',
			'#   WP_GATE_COOKIE                       (a captured session; 2FA sites)',
			'#   WP_GATE_STORAGE_STATE                (a Playwright storageState file)',
			'# Leave blank on a public site: the forge then never sends credentials.',
			'WP_GATE_USERNAME=',
			'WP_GATE_PASSWORD=',
			'WP_GATE_COOKIE=',
			'WP_GATE_STORAGE_STATE=',
			'',
			'# Edge gate (optional): Cloudflare Access service token, or staging',
			'# HTTP Basic auth (WP_GATE_HTTP_AUTH="user:pass").',
			'CF_ACCESS_CLIENT_ID=',
			'CF_ACCESS_CLIENT_SECRET=',
			'WP_GATE_HTTP_AUTH=',
			...(managed
				? [
						'',
						...(isMaterializedContent(profile)
							? [
									'# Managed runtime (Proposal 135) — this site has NO CMS (its content is',
									'# materialized), so the BFF has no upstream to proxy. Leave this blank:',
									'# pointing it at the public origin would make /api proxy to itself.',
									'NVOOS_TARGET_SITE=',
							  ]
							: [
									'# Managed runtime (Proposal 135) — the WordPress origin the BFF proxies',
									'# /api, /media and /api/forms/submit to (server-to-server; the CMS host',
									'# is never called by a browser).',
									`NVOOS_TARGET_SITE=${ cmsOrigin }`,
							  ]),
						'',
						'# Shared secret for POST /api/revalidate (tag-based cache invalidation).',
						'# Unset disables the route (503).',
						'NVOOS_REVALIDATE_TOKEN=',
						...( profile.deploy?.manage === true
							? [
									'',
									'# Manage sign-in (Proposal 165) — signs the host-only session the gate',
									'# trusts. Unset falls back to NVOOS_FORGE_GATE_SECRET; both unset disables',
									'# sign-in (the site stays gated and /manage says so).',
									'NVOOS_MANAGE_SECRET=',
									'',
									'# Operator bypass links for an active gate (?nvoos-gate=...).',
									'NVOOS_FORGE_GATE_SECRET=',
							  ]
							: [] ),
				  ]
				: []),
			...renderTrackingEnvLines( profile ),
			'',
		].join('\n'),
		'utf8'
	);
	await ensureGitignore(targetDir);

	// 4b. Agent stubs (104 §4.4) — AGENTS.md + the .context hook directory.
	//     Skipped with --no-agents.
	if (options.agents !== false) {
		await writeFile(path.join(targetDir, 'AGENTS.md'), AGENTS_STUB, 'utf8');
		await mkdir(path.join(targetDir, '.context'), { recursive: true });
		await writeFile(path.join(targetDir, '.context', 'README.md'), CONTEXT_STUB, 'utf8');
	}

	// 4c. Demo content — removed unless explicitly requested. The template
	//     ships a placeholder post + page so a project renders with no
	//     WordPress at all, but `wp-loader.ts` merges the local MDX glob UNDER
	//     the WP entries (WP wins on collision, local fills). On a real site
	//     that publishes "Hello from the forge" at /posts/hello-world/.
	if (options.demoContent !== true) {
		for (const relative of ['src/content/posts/hello-world.mdx', 'src/content/pages/README.md']) {
			await rm(path.join(targetDir, relative), { force: true });
		}
	}

	// A snapshot is a PROMISE: the profile claims the site survives a CMS
	// outage. Missing content would leave it with nothing at the moment that
	// matters, so fail here rather than at 3 a.m. during an outage. (A CMS-less
	// `materialized` profile keeps the softer warning below — it is
	// bootstrapped by running `forge materialize` right after.)
	if ( profile.content?.snapshot === true ) {
		const source = options.contentDir ? path.resolve( options.contentDir ) : '';
		if ( source === '' || ! existsSync( source ) ) {
			throw new Error(
				`content.snapshot: true needs a materialized snapshot beside the profile (sites/<name>.content/, written by "forge materialize") — ${ source || 'none was found' }. Run "forge materialize" first, or drop the flag.`
			);
		}
	}

	// 4d. Materialized content (2026-11-15): a site whose CMS is gone ships its
	//     content beside the profile (`sites/<name>.content/`, written by
	//     `forge materialize`). Copied AFTER the demo removal so real content
	//     always wins.
	if (options.contentDir) {
		const source = path.resolve(options.contentDir);
		if (existsSync(source)) {
			await cp(source, path.join(targetDir, 'src', 'content'), { recursive: true });
		} else if (isMaterializedContent(profile)) {
			// The profile says the CMS is gone. Without the snapshot there is
			// nothing at all behind the site, so say exactly what is missing and
			// how to get it — an empty build is otherwise baffling.
			warnings.push(
				`content.source is "materialized" but no content snapshot was found at ${source} — the site will build EMPTY. Run "forge materialize <profile> --from <built project>" (or commit the snapshot) first.`
			);
		} else {
			warnings.push(`contentDir not found: ${source}`);
		}
	} else if (isMaterializedContent(profile)) {
		warnings.push(
			'content.source is "materialized" but no content directory was supplied — the site will build EMPTY. Scaffold through the CLI (it derives sites/<name>.content/ from the profile path) or pass --content <dir>.'
		);
	}

	// 4e. Media snapshot (2026-11-15): the CMS-less counterpart to the content
	//     snapshot. A cold rebuild otherwise loses a different set of CDN
	//     derivatives on every run (virtuallyjamaica's icon font and hero
	//     photos, measured 2026-11-15). The localizer reads this directory from
	//     the project root; it is never served.
	if ( profile.media?.snapshot === true ) {
		const source = options.mediaDir ? path.resolve( options.mediaDir ) : '';
		if ( source === '' || ! existsSync( source ) ) {
			throw new Error(
				`media.snapshot: true needs a media snapshot beside the profile (sites/<name>.media/, written by "forge media-snapshot") — ${ source || 'none was found' }. Run "forge media-snapshot" first, or drop the flag.`
			);
		}
	}
	if ( options.mediaDir ) {
		const source = path.resolve( options.mediaDir );
		if ( existsSync( source ) ) {
			await cp( source, path.join( targetDir, 'media-snapshot' ), { recursive: true } );
		} else {
			warnings.push( `mediaDir not found: ${ source }` );
		}
	}

	return {
		targetDir,
		manifest,
		collections: profile.collections.map((c) => c.slug),
		warnings,
		...( wireframePages.length > 0 ? { wireframePages } : {} ),
	};
}

/**
 * The generated modules a RE-FORGE can rewrite in place.
 *
 * A profile fact often changes without the template changing at all: a
 * re-analyze adds a measured fact, an operator pins a colour, the day-range
 * parser learns a new shape. Re-scaffolding for that is a wrecking ball — it
 * swaps the whole target (and on Windows it fails with EPERM whenever an editor
 * or watcher holds the directory), so the operator either hand-copies files or
 * leaves the site stale. `forge regen` rewrites exactly the derived modules and
 * touches nothing else.
 */
export const REGEN_MODULES = [
	'design',
	'sections',
	'pages',
	'chrome',
	'behaviors',
	'animations',
	'tracking',
	'vocabulary',
	'eca',
] as const;

export type RegenModule = ( typeof REGEN_MODULES )[ number ];

export interface RegenResult {
	written: string[];
	/** Modules whose target file does not exist in this project. */
	skipped: string[];
}

/** Rewrite the generated modules from `profile` without re-scaffolding. */
export async function regenGenerated(
	profile: SiteProfile,
	targetDir: string,
	only?: readonly string[]
): Promise< RegenResult > {
	const requested = ( only && only.length > 0 ? only : REGEN_MODULES ) as readonly string[];
	const unknown = requested.filter( ( name ) => ! ( REGEN_MODULES as readonly string[] ).includes( name ) );
	if ( unknown.length > 0 ) {
		throw new Error( `unknown regen module(s): ${ unknown.join( ', ' ) } — expected ${ REGEN_MODULES.join( '|' ) }` );
	}
	const lib = ( file: string ): string => path.join( targetDir, 'src', 'lib', file );
	const tokens = mergeTokens( profile.design, profile.tokens, profile.colors, accentFamilyOf( profile ) );
	const renderers: Record< RegenModule, () => { file: string; contents: string } > = {
		design: () => ( { file: lib( 'design.generated.ts' ), contents: renderDesignGenerated( profile, tokens ) } ),
		sections: () => ( { file: lib( 'sections.generated.ts' ), contents: renderSectionsGenerated( profile ) } ),
		pages: () => ( { file: lib( 'pages.generated.ts' ), contents: renderPagesGenerated( profile ) } ),
		chrome: () => ( { file: lib( 'chrome.generated.ts' ), contents: renderChromeGenerated( profile ) } ),
		behaviors: () => ( { file: lib( 'behaviors.generated.ts' ), contents: renderBehaviorsGenerated( profile ) } ),
		animations: () => ( { file: lib( 'animations.generated.ts' ), contents: renderAnimationsGenerated( profile ) } ),
		tracking: () => ( { file: lib( 'tracking.generated.ts' ), contents: renderTrackingGenerated( profile ) } ),
		vocabulary: () => ( { file: lib( 'vocabulary.generated.ts' ), contents: renderVocabularyGenerated( profile ) } ),
		eca: () => ( { file: path.join( targetDir, ECA_CONFIG_PATH ), contents: renderEcaGenerated( profile ) } ),
	};
	const result: RegenResult = { written: [], skipped: [] };
	const writtenFiles: string[] = [];
	for ( const name of requested as RegenModule[] ) {
		const { file, contents } = renderers[ name ]();
		// Only rewrite a module this project actually ships: the minimal preset has
		// no `animations.generated.ts`, and writing one would add a file the
		// template never imports (and `check-generated.mjs` would then flag it).
		try {
			await stat( file );
		} catch {
			result.skipped.push( name );
			continue;
		}
		await writeFile( file, contents, 'utf8' );
		result.written.push( name );
		writtenFiles.push( file );
	}
	// Keep the DRIFT GATE honest: a regenerated module is not a hand edit, and
	// `check-generated.mjs` would fail the next build on the stale hash. The
	// manifest is the scaffold's, so only its entries are touched.
	if ( writtenFiles.length > 0 ) {
		const manifestPath = path.join( targetDir, 'nvoos.generated.json' );
		try {
			const manifest = JSON.parse( await readFile( manifestPath, 'utf8' ) ) as {
				regeneratedAt?: string;
				files?: Record< string, string >;
			};
			manifest.files ??= {};
			for ( const file of writtenFiles ) {
				const relative = path.relative( targetDir, file ).split( path.sep ).join( '/' );
				const contents = await readFile( file, 'utf8' );
				manifest.files[ relative ] = createHash( 'sha256' ).update( contents ).digest( 'hex' );
			}
			manifest.regeneratedAt = new Date().toISOString();
			await writeFile( manifestPath, JSON.stringify( manifest, null, 2 ) + '\n', 'utf8' );
		} catch {
			// No manifest (a LOCKED or minimal project) — nothing to keep honest.
		}
	}
	return result;
}
