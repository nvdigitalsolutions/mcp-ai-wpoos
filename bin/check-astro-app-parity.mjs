#!/usr/bin/env node
// ---------------------------------------------------------------------------
// check-astro-app-parity.mjs — cross-app contract drift detector
// (examples/nvoos-astro-forge ↔ examples/nvoos-astro-velocity).
//
// The two Astro products share a CONTRACT but not code: the forge GENERATES
// sites, the velocity storefront is hand-built. Neither app's own suite can see
// the other tree, so drift is invisible without this check. Same discipline as
// `bin/check-chat-parity.php` (legacy chat ↔ lib/core) and the base+pro → CG-Pro
// port verifier: compare implementations directly, and make every difference
// DELIBERATE and written down here.
//
// Run as: node bin/check-astro-app-parity.mjs
//
// Exit codes (mirroring check-chat-parity.php):
//   0 - parity: no drift
//   1 - drift: a contract difference that is not declared below
//
// Node >= 22.18 / 23 / 24 strips TypeScript types natively, so this imports the
// .ts sources directly — no build step, no tsconfig resolution, no vitest. The
// only runtime dependency (htmlparser2, via the forge's llms module) resolves
// from the forge tree, so `npm ci` there is enough.
// ---------------------------------------------------------------------------

import { existsSync, readFileSync, readdirSync } from 'node:fs';

import { SECTION_KINDS as FORGE_KINDS } from '../examples/nvoos-astro-forge/lib/types.ts';
import { composeLlmsTxt as forgeCompose } from '../examples/nvoos-astro-forge/lib/llms.ts';
import { SECTION_KINDS as VELOCITY_KINDS } from '../examples/nvoos-astro-velocity/src/lib/content/section-kinds.ts';
import { composeLlmsTxt as velocityCompose } from '../examples/nvoos-astro-velocity/src/lib/llms.ts';

const failures = [];
const check = (ok, label, detail) => {
	if (ok) {
		console.log(`  ok   ${label}`);
		return;
	}
	console.log(`  FAIL ${label}`);
	if (detail) console.log(`       ${detail}`);
	failures.push(label);
};

// ---------------------------------------------------------------------------
// 1. llms.txt format parity.
//
// The ONE declared behavioural difference: the default link target. The forge
// always emits markdown links (it generates those routes); velocity defaults to
// HTML because only products/pages/posts have a `.md` route there. Everything
// else must be byte-identical.
// ---------------------------------------------------------------------------

const ORIGIN = 'https://example.com';

const BASE_INPUT = {
	name: 'Acme',
	summary: 'A test site.',
	origin: ORIGIN,
	pages: [{ path: '/about/', title: 'About', note: 'Who we are' }],
	products: [{ path: '/p/1/', title: 'Sofa' }],
	posts: [{ path: '/blog/1/', title: 'Hello' }],
	optional: [{ path: '/contact/', title: 'Contact' }],
	extra: 'Extra prose.',
};

const FIXTURES = [
	['all sections', BASE_INPUT],
	['no optional section', { ...BASE_INPUT, optional: [] }],
	[
		'entry order does not affect output (sorted by path)',
		{
			...BASE_INPUT,
			pages: [
				{ path: '/z/', title: 'Z' },
				{ path: '/a/', title: 'A' },
				{ path: '/m/', title: 'M' },
			],
		},
	],
	[
		'long note is word-bounded and clipped',
		{
			...BASE_INPUT,
			pages: [{ path: '/long/', title: 'Long', note: `${'word '.repeat(40).trim()} end` }],
		},
	],
	['brackets are stripped from titles', { ...BASE_INPUT, pages: [{ path: '/b/', title: 'A [bracketed] title' }] }],
	[
		'blank title entries are dropped',
		{
			...BASE_INPUT,
			pages: [
				{ path: '/ok/', title: 'Kept' },
				{ path: '/blank/', title: '   ' },
			],
		},
	],
];

console.log('llms.txt format parity (forge === velocity with variant: "markdown")');
for (const [label, input] of FIXTURES) {
	const forge = forgeCompose(input);
	const velocity = velocityCompose({ ...input, variant: 'markdown' });
	check(velocity === forge, label, velocity === forge ? '' : firstDiff(forge, velocity));
}

console.log('default variant differs ONLY in link targets');
{
	const html = velocityCompose(BASE_INPUT);
	const markdown = velocityCompose({ ...BASE_INPUT, variant: 'markdown' });
	const normalise = (doc) => doc.replace(/\]\([^)]*\)/g, '](LINK)');
	check(
		normalise(html) === normalise(markdown),
		'format is identical once link targets are normalised',
		firstDiff(normalise(html), normalise(markdown))
	);
	check(!/\/index\.md\)/.test(html), 'default variant links HTML (no /index.md)');
	check(/\/index\.md\)/.test(markdown), 'markdown variant links /index.md');
}

// ---------------------------------------------------------------------------
// 2. data-section vocabulary parity.
//
// Each app declares its own list — deliberately: the forge does not generate
// faq/tabs/product-grid/promotion bands, and velocity does not generate
// cta/logo-cloud/form bands. The relation must be exactly:
//     each list = (shared) ∪ (its own declared extras)
// Adding a kind to EITHER app therefore fails until it is declared here, which
// is the point: a new kind is a cross-product decision (`stats` vs `stat` and
// `product-grid` vs `productGrid` were both made deliberately).
// ---------------------------------------------------------------------------

const SHARED = ['hero', 'testimonial', 'stats', 'comparison', 'steps', 'team'];

const FORGE_ONLY = [
	'feature-grid',
	'icon-list',
	'logo-cloud',
	'cta',
	'gallery',
	'text',
	'form',
	'raw',
	'pricing',
	'marquee',
	'content',
];

const VELOCITY_ONLY = ['image', 'faq', 'tabs', 'product-grid', 'promotion'];

const sameSet = (a, b) => {
	const as = [...a].sort();
	const bs = [...b].sort();
	return as.length === bs.length && as.every((value, index) => value === bs[index]);
};

console.log('data-section vocabulary parity');
check(
	sameSet(FORGE_KINDS, [...SHARED, ...FORGE_ONLY]),
	'forge kinds = shared + forge-only',
	`forge has [${[...FORGE_KINDS].sort().join(', ')}]`
);
check(
	sameSet(VELOCITY_KINDS, [...SHARED, ...VELOCITY_ONLY]),
	'velocity kinds = shared + velocity-only',
	`velocity has [${[...VELOCITY_KINDS].sort().join(', ')}]`
);
check(SHARED.length > 0, 'the shared set is non-empty');
check(
	SHARED.every((kind) => FORGE_KINDS.includes(kind) && VELOCITY_KINDS.includes(kind)),
	'shared kinds exist on both sides and are spelled identically'
);
check(
	[...FORGE_ONLY, ...VELOCITY_ONLY].every((kind) => !SHARED.includes(kind)),
	'no kind is declared as both shared and app-only'
);

// ---------------------------------------------------------------------------
// 3. Shared form-delivery module — BYTE identity.
//
// `mail.ts` is the provider transport BOTH products ship: the forge vendors it
// into a generated site (`forms.delivery: 'direct'`) and velocity imports it at
// src/lib/mail.ts. It is deliberately a COPY, not a shared package — a generated
// site must build standalone, and a published storefront must not depend on the
// forge tree. Copies drift, so this asserts byte identity: edit one, copy it to
// the other, and the gate stays green.
//
// What is NOT gated is the endpoint around it. The forge's is emitted as source
// (lib/forms-direct.ts); velocity's is hand-written
// (src/pages/forms/submit.ts). Same guards, different frame — the divergence is
// declared in reference/email-delivery.md.
// ---------------------------------------------------------------------------

const MAIL_FORGE = 'examples/nvoos-astro-forge/lib/mail.ts';
const MAIL_VELOCITY = 'examples/nvoos-astro-velocity/src/lib/mail.ts';
const FORGE_ENDPOINT_SRC = 'examples/nvoos-astro-forge/lib/forms-direct.ts';
const VELOCITY_ENDPOINT = 'examples/nvoos-astro-velocity/src/pages/forms/submit.ts';
const VELOCITY_GUARDS = 'examples/nvoos-astro-velocity/src/lib/forms.ts';

console.log('shared form-delivery module (byte identity)');
{
	const forgeMail = readFileSync(new URL(`../${MAIL_FORGE}`, import.meta.url), 'utf8');
	const velocityMail = readFileSync(new URL(`../${MAIL_VELOCITY}`, import.meta.url), 'utf8');
	check(
		velocityMail === forgeMail,
		'mail.ts is byte-identical in both apps',
		velocityMail === forgeMail
			? ''
			: `${MAIL_FORGE} and ${MAIL_VELOCITY} have diverged — copy one over the other`
	);

	// The endpoint must keep reading the RUNTIME environment: import.meta.env is
	// a build-time snapshot, so a key injected after the build would be invisible.
	const endpoint = readFileSync(new URL(`../${VELOCITY_ENDPOINT}`, import.meta.url), 'utf8');
	check(
		/runtimeEnv\(\)/.test(endpoint),
		'velocity endpoint reads the runtime environment',
		`${VELOCITY_ENDPOINT} no longer calls runtimeEnv()`
	);
}

// ---------------------------------------------------------------------------
// 4. Guard-sequence parity.
//
// The endpoints are NOT shared source — the forge's is emitted, velocity's is
// hand-written — but they must carry the same guards. Order may differ; a guard
// that exists on one side only is the drift this catches, because it is exactly
// how "we fixed the spam problem" becomes true for one product and false for the
// other. Adding a guard therefore means adding it in BOTH trees (and the marker
// here).
// ---------------------------------------------------------------------------

const GUARD_MARKERS = [
	[/honeypot/, 'honeypot field'],
	[/timestamp/, 'time-trap timestamp'],
	[/64 \* 1024/, '64 KB body cap'],
	[/429/, 'throttle response'],
	[/idempotenc/i, 'idempotency key'],
	[/replyTo/i, 'reply-to'],
];

console.log('form-endpoint guard parity');
{
	const forge = readFileSync(new URL(`../${FORGE_ENDPOINT_SRC}`, import.meta.url), 'utf8');
	const velocity = [
		readFileSync(new URL(`../${VELOCITY_ENDPOINT}`, import.meta.url), 'utf8'),
		readFileSync(new URL(`../${VELOCITY_GUARDS}`, import.meta.url), 'utf8'),
	].join('\n');

	for (const [pattern, label] of GUARD_MARKERS) {
		const inForge = pattern.test(forge);
		const inVelocity = pattern.test(velocity);
		check(
			inForge && inVelocity,
			`both endpoints keep the ${label}`,
			`missing from ${[inForge ? '' : 'forge', inVelocity ? '' : 'velocity'].filter(Boolean).join(' and ')}`
		);
	}
}

// ---------------------------------------------------------------------------
// 5. MCP surface parity.
//
// Both apps expose an MCP server, but they are DIFFERENT products: the forge's
// tools drive site generation (`site_*`, `scaffold_*`, `deploy*`), velocity's
// drive a live storefront (`cms_*`, `content_*`, `theme_*`). Identical tool
// lists are therefore not the contract. The contract is the RUNTIME they share,
// because an agent must get the same meaning from either mount:
//
//   a. the annotation presets (`READ_ONLY_TOOL` … `DESTRUCTIVE_WRITE_TOOL`) must
//      carry the same hints on both sides — `readOnlyHint` may never mean
//      different things in two products,
//   b. the request pipeline must keep the same fail-closed ORDER (rate limit →
//      Host → Origin → write auth) and the same JSON-RPC codes,
//   c. a tool NAME present in both apps is a cross-product decision: either it
//      is genuinely the same tool (`SHARED_MCP_TOOLS` → identical title +
//      annotations) or the collision is declared with a reason.
//
// (c) is the forward guard: when the managed forge tier ships its `/mcp`
// surface (the velocity design/ops slices are the intended source), every
// tool it copies must move into `SHARED_MCP_TOOLS` here — otherwise this gate
// fails the moment a name appears on both sides unclassified.
// ---------------------------------------------------------------------------

/** Tools both apps expose with IDENTICAL semantics (title + annotations). */
const SHARED_MCP_TOOLS = [];

/** Same name, deliberately different products/semantics — reason required. */
const MCP_NAME_COLLISIONS = [
	{
		name: 'rebuild_trigger',
		reason:
			'forge: POSTs a signed request to a site running nvoos-forge-bridge (vault-held URL/secret, open-world write). velocity: POSTs {} to NVOOS_REBUILD_WEBHOOK_URL (idempotent write). Same verb, different backend and different credential source.',
	},
];

/** `export const X: ToolAnnotations = { … };` → hint map. */
function parseAnnotationPresets( source ) {
	const presets = new Map();
	for ( const match of source.matchAll( /export const ([A-Z][A-Z0-9_]*):\s*ToolAnnotations\s*=\s*\{([\s\S]*?)\};/g ) ) {
		const body = match[ 2 ];
		const hint = ( key ) => {
			const found = new RegExp( `${ key }:\\s*(true|false)` ).exec( body );
			return found ? found[ 1 ] === 'true' : undefined;
		};
		presets.set( match[ 1 ], {
			readOnlyHint: hint( 'readOnlyHint' ),
			destructiveHint: hint( 'destructiveHint' ),
			idempotentHint: hint( 'idempotentHint' ),
			openWorldHint: hint( 'openWorldHint' ),
		} );
	}
	return presets;
}

/** Read every `.ts` under a directory, concatenated (source order). */
function readSources( relativeDir ) {
	const dir = new URL( `../${ relativeDir }/`, import.meta.url );
	return readdirSync( dir )
		.filter( ( file ) => file.endsWith( '.ts' ) )
		.sort()
		.map( ( file ) => readFileSync( new URL( file, dir ), 'utf8' ) )
		.join( '\n' );
}

/** Forge tool literals: `name: '…', title: '…', …, annotations: PRESET,`. */
function parseForgeMcpTools( source ) {
	const tools = new Map();
	for ( const chunk of source.split( /\n\t\tname:\s*'/ ).slice( 1 ) ) {
		const end = chunk.indexOf( "'" );
		if ( end < 0 ) continue;
		const name = chunk.slice( 0, end );
		if ( ! /^[a-z][a-z0-9_]*$/.test( name ) ) continue;
		tools.set( name, {
			title: /title:\s*'((?:\\.|[^'\\])*)'/.exec( chunk )?.[ 1 ],
			annotations: /annotations:\s*([A-Z][A-Z0-9_]*)/.exec( chunk )?.[ 1 ],
		} );
	}
	return tools;
}

/** Velocity tools: `server.registerTool('name', { title: '…', … annotations: PRESET,`. */
function parseVelocityMcpTools( source ) {
	const tools = new Map();
	for ( const chunk of source.split( /registerTool\(\s*/ ).slice( 1 ) ) {
		const name = /^'([a-z][a-z0-9_]*)'/.exec( chunk )?.[ 1 ];
		if ( ! name ) continue;
		tools.set( name, {
			title: /title:\s*'((?:\\.|[^'\\])*)'/.exec( chunk )?.[ 1 ],
			annotations: /annotations:\s*([A-Z][A-Z0-9_]*)/.exec( chunk )?.[ 1 ],
		} );
	}
	return tools;
}

const FORGE_MCP_UTIL = 'examples/nvoos-astro-forge/mcp/util.ts';
const VELOCITY_MCP_UTIL = 'examples/nvoos-astro-velocity/mcp/tools/util.ts';
const FORGE_MCP_BFF = 'examples/nvoos-astro-forge/mcp/bff.ts';
const VELOCITY_MCP_BFF = 'examples/nvoos-astro-velocity/mcp/bff.ts';

console.log( 'MCP annotation presets' );
{
	const forge = parseAnnotationPresets( readFileSync( new URL( `../${ FORGE_MCP_UTIL }`, import.meta.url ), 'utf8' ) );
	const velocity = parseAnnotationPresets( readFileSync( new URL( `../${ VELOCITY_MCP_UTIL }`, import.meta.url ), 'utf8' ) );
	check( forge.size > 0 && velocity.size > 0, 'both apps define annotation presets' );
	check( sameSet( [ ...forge.keys() ], [ ...velocity.keys() ] ), 'the preset set is identical', `forge=[${[ ...forge.keys() ].sort()}] velocity=[${[ ...velocity.keys() ].sort()}]` );
	for ( const [ name, hints ] of forge ) {
		const other = velocity.get( name );
		check( Boolean( other ), `${ name } exists on both sides` );
		if ( ! other ) continue;
		const same = [ 'readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint' ].every( ( key ) => hints[ key ] === other[ key ] );
		check( same, `${ name } carries the same hints`, `forge=${JSON.stringify( hints )} velocity=${JSON.stringify( other )}` );
		check( hints.readOnlyHint !== undefined && hints.openWorldHint !== undefined, `${ name } declares every hint` );
	}
}

console.log( 'MCP request-pipeline parity (fail-closed order)' );
{
	const ORDER = [
		[ /limiter\.allow\(/, 'per-IP rate limit' ],
		[ /validateHost\(/, 'Host validation' ],
		[ /validateOrigin\(/, 'Origin validation' ],
		[ /authorizeRequest\(/, 'write-auth gate' ],
	];
	for ( const [ path, label ] of [
		[ FORGE_MCP_BFF, 'forge' ],
		[ VELOCITY_MCP_BFF, 'velocity' ],
	] ) {
		const source = readFileSync( new URL( `../${ path }`, import.meta.url ), 'utf8' );
		let previous = -1;
		let ordered = true;
		for ( const [ pattern, gate ] of ORDER ) {
			const index = source.search( pattern );
			check( index >= 0, `${ label } keeps the ${ gate }`, `${ path } no longer matches ${ pattern }` );
			if ( index <= previous ) ordered = false;
			previous = index;
		}
		check( ordered, `${ label } runs the gates in order (rate → Host → Origin → auth)` );
		check( source.includes( '-32029' ), `${ label } returns -32029 for rate limiting` );
		check( source.includes( '-32001' ), `${ label } returns -32001 for unauthorized writes` );
	}
}

console.log( 'MCP tool inventory (shared ∪ declared collisions)' );
{
	const forge = parseForgeMcpTools( readSources( 'examples/nvoos-astro-forge/mcp/tools' ) );
	const velocity = parseVelocityMcpTools( readSources( 'examples/nvoos-astro-velocity/mcp/tools' ) );
	check( forge.size > 0, `forge tools parsed (${ forge.size })`, 'the forge tool literals no longer match the extractor' );
	check( velocity.size > 0, `velocity tools parsed (${ velocity.size })`, 'the velocity registerTool calls no longer match the extractor' );

	const missingMeta = [ ...forge ].filter( ( [ , tool ] ) => ! tool.title || ! tool.annotations ).map( ( [ name ] ) => name );
	check( missingMeta.length === 0, 'every forge tool declares a title + annotations', missingMeta.join( ', ' ) );
	const missingMetaVelocity = [ ...velocity ].filter( ( [ , tool ] ) => ! tool.title || ! tool.annotations ).map( ( [ name ] ) => name );
	check( missingMetaVelocity.length === 0, 'every velocity tool declares a title + annotations', missingMetaVelocity.join( ', ' ) );

	const collisions = MCP_NAME_COLLISIONS.map( ( entry ) => entry.name );
	check( collisions.every( ( name ) => ! SHARED_MCP_TOOLS.includes( name ) ), 'no tool is both shared and a declared collision' );
	check( MCP_NAME_COLLISIONS.every( ( entry ) => entry.reason && entry.reason.length > 20 ), 'every collision carries a reason' );

	const intersection = [ ...forge.keys() ].filter( ( name ) => velocity.has( name ) ).sort();
	check(
		sameSet( intersection, [ ...SHARED_MCP_TOOLS, ...collisions ] ),
		'every tool name on both sides is declared shared or a collision',
		`undeclared overlap: ${ intersection.filter( ( name ) => ! SHARED_MCP_TOOLS.includes( name ) && ! collisions.includes( name ) ).join( ', ') || 'none' }`
	);

	for ( const name of SHARED_MCP_TOOLS ) {
		check( forge.has( name ) && velocity.has( name ), `${ name } is exposed by both apps` );
		if ( ! forge.has( name ) || ! velocity.has( name ) ) continue;
		const a = forge.get( name );
		const b = velocity.get( name );
		check( a.title === b.title, `${ name } keeps the same title`, `forge=${JSON.stringify( a.title )} velocity=${JSON.stringify( b.title )}` );
		check( a.annotations === b.annotations, `${ name } keeps the same annotation preset`, `forge=${a.annotations} velocity=${b.annotations}` );
	}
}

// The ASYMMETRY is the decision: velocity serves /mcp from its own Node
// process; a forged site does not ship an assistant surface at all. Static
// output cannot host one, and giving the managed tier one is a cross-product
// decision — so it is declared here rather than appearing silently. Flip these
// three checks (and move the copied tools into SHARED_MCP_TOOLS) when the
// managed tier ports the design/ops slices.
console.log( 'MCP surface asymmetry (declared)' );
{
	const velocityMcp = existsSync( new URL( '../examples/nvoos-astro-velocity/mcp/', import.meta.url ) );
	const forgeTemplateMcp = existsSync( new URL( '../examples/nvoos-astro-forge/templates/blog/src/pages/mcp/', import.meta.url ) );
	const managedRuntime = readFileSync( new URL( '../examples/nvoos-astro-forge/lib/managed-runtime.ts', import.meta.url ), 'utf8' );
	check( velocityMcp, 'velocity ships an MCP server' );
	check( ! forgeTemplateMcp, 'a forged site does not ship a /mcp route (yet — port it deliberately)' );
	// Match EMITTED code only (block + line comments stripped) so prose can
	// mention MCP freely without tripping the gate.
	const emitsMcp = /\bMcpServer\b|@modelcontextprotocol|src\/pages\/mcp|createMcpHandler/.test(
		managedRuntime.replace( /\/\*[\s\S]*?\*\//g, '' ).replace( /\/\/.*$/gm, '' )
	);
	check(
		! emitsMcp,
		'the managed runtime emits no MCP route (flip this when the port lands)',
		'lib/managed-runtime.ts gained an MCP route without updating this declaration'
	);
}

// ---------------------------------------------------------------------------

console.log('');
if (failures.length > 0) {
	console.error(`astro app parity: ${failures.length} drift(s) — ${failures.join('; ')}`);
	process.exit(1);
}
console.log('astro app parity: clean');
process.exit(0);

/** First differing line, for a readable failure without dumping the document. */
function firstDiff(a, b) {
	const left = String(a).split('\n');
	const right = String(b).split('\n');
	const max = Math.max(left.length, right.length);
	for (let i = 0; i < max; i += 1) {
		if (left[i] !== right[i]) {
			return `line ${i + 1}: forge=${JSON.stringify(left[i] ?? '')} velocity=${JSON.stringify(right[i] ?? '')}`;
		}
	}
	return 'documents differ but no line-level diff found';
}
