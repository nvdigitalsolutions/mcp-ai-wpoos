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

import { readFileSync } from 'node:fs';

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
