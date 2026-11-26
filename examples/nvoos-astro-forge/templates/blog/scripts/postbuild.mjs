// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// postbuild.mjs — materialize the host-agnostic redirect map (Proposal 096 §0).
//
// Astro ignores underscore-prefixed files in src/pages/ (the partial
// convention), so the redirect document is emitted as /redirects.json and
// this script converts it into `dist/_redirects` — the publish-root format
// understood by Netlify, Cloudflare Pages, and self-hosted static servers.
// Runs after `astro build` (wired into the template's build script).
//
// `redirects.json` keeps the FAITHFUL status from the source site (Proposal
// 132). `_redirects` is a projection onto the codes the big two hosts both
// honour:
//   * Netlify documents 301/302/404/200 and states 307 is unsupported.
//   * Cloudflare Pages documents 301/302/303/307/308 (+200) and no 404/410.
// 301/302 are the safe intersection; method-preserving permanent codes map
// onto 301, temporary ones onto 302, and 410 onto 404. Nothing is guessed:
// an unknown status is omitted, and every projection is reported.
// ---------------------------------------------------------------------------

import { createHash } from 'node:crypto';
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const distDir = resolve(process.cwd(), 'dist');
// Static builds emit into dist/; server builds (--render ssr) put the
// prerendered documents under dist/client/ — materialize _redirects next
// to whichever map exists.
const candidates = [resolve(distDir, 'redirects.json'), resolve(distDir, 'client', 'redirects.json')];
const mapPath = candidates.find((candidate) => existsSync(candidate));

const targetPath = mapPath ? resolve(dirname(mapPath), '_redirects') : null;

// --- CSS provenance -------------------------------------------------------
// Stamp a fingerprint of this build’s stylesheets into every document, so a
// deploy that serves a DIFFERENT stylesheet than the artifact is detectable
// rather than merely suspected. See lib/build-identity.ts.
function stampStyleFingerprint() {
	const styleNames = [];
	const documents = [];
	const walk = (dir) => {
		if (!existsSync(dir)) {
			return;
		}
		for (const entry of readdirSync(dir, { withFileTypes: true })) {
			const path = resolve(dir, entry.name);
			if (entry.isDirectory()) {
				walk(path);
				continue;
			}
			if (entry.name.endsWith('.css')) {
				styleNames.push(entry.name);
			}
			if (entry.name === 'index.html') {
				documents.push(path);
			}
		}
	};
	walk(distDir);
	if (documents.length === 0) {
		return;
	}
	const shared = [...styleNames].sort().join('\n');
	for (const document of documents) {
		let html = readFileSync(document, 'utf8');
		if (html.includes('name="forge-css"')) {
			continue;
		}
		// Band CSS is INLINED per document, so the shared asset names alone
		// cannot tell two pages apart and miss every fact-driven change. Fold in
		// this document's own <style> blocks.
		const inline = [...html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/gi)]
			.map((match) => match[1])
			.join('\n');
		const fingerprint = createHash('sha256')
			.update(shared)
			.update(inline)
			.digest('hex')
			.slice(0, 16);
		const marker = `<meta name="forge-css" content="${fingerprint}">`;
		// Beside the build id when there is one, so the two read together.
		const anchor = /<meta name="forge-build"[^>]*>/i.exec(html);
		html = anchor
			? html.replace(anchor[0], `${anchor[0]}${marker}`)
			: html.replace(/<head[^>]*>/i, (head) => `${head}${marker}`);
		writeFileSync(document, html, 'utf8');
	}
	console.log(`[forge-postbuild] forge-css on ${documents.length} document(s), per page.`);
}

stampStyleFingerprint();

// --- structured data ------------------------------------------------------
// The localizer rewrites ASSET attributes and deliberately leaves every other
// absolute URL alone — `og:image` must stay absolute after a domain move. JSON-LD
// is the exception: a `logo`/`image` URL that still names a RETIRED CMS is a dead
// link a search engine will index, and virtuallyjamaica's three documents each
// shipped one (the localizer had already fetched and hashed the file, so the
// built page carried both the local copy and the dead URL).
//
// The rewrite runs from the localizer's OWN report, so the mapping cannot drift
// from the file it actually wrote, and it is confined to
// `<script type="application/ld+json">` bodies so nothing else is touched.
function collectDocuments() {
	const documents = [];
	const walk = (dir) => {
		if (!existsSync(dir)) {
			return;
		}
		for (const entry of readdirSync(dir, { withFileTypes: true })) {
			const path = resolve(dir, entry.name);
			if (entry.isDirectory()) {
				walk(path);
			} else if (entry.name === 'index.html') {
				documents.push(path);
			}
		}
	};
	walk(distDir);
	return documents;
}

function localizeStructuredData() {
	const map = localizeReport();
	if (!map) {
		return;
	}
	const origin = siteOrigin();

	let pages = 0;
	let rewrites = 0;
	for (const document of collectDocuments()) {
		let html = readFileSync(document, 'utf8');
		let changed = false;
		html = html.replace(
			/(<script[^>]*type=["']application\/ld\+json["'][^>]*>)([\s\S]*?)(<\/script>)/gi,
			(match, open, body, close) => {
				let next = body;
				for (const [url, file] of map) {
					if (!next.includes(url)) {
						continue;
					}
					const target = origin ? `${origin}${file}` : file;
					rewrites += next.split(url).length - 1;
					next = next.split(url).join(target);
				}
				if (next !== body) {
					changed = true;
				}
				return open + next + close;
			}
		);
		if (changed) {
			writeFileSync(document, html, 'utf8');
			pages += 1;
		}
	}
	if (rewrites > 0) {
		console.log(`[forge-postbuild] localized ${rewrites} structured-data URL(s) on ${pages} document(s).`);
	}
}

/**
 * The localizer's own report — `url -> root-relative file`.
 *
 * @returns {Map<string,string>|null} Null when the localizer did not run or
 *          wrote nothing worth mapping.
 */
function localizeReport() {
	const reportPath = resolve(process.cwd(), '.forge-cache', 'media-localize.json');
	if (!existsSync(reportPath)) {
		return null;
	}
	let report;
	try {
		report = JSON.parse(readFileSync(reportPath, 'utf8'));
	} catch {
		return null;
	}
	const localized = Array.isArray(report?.localized) ? report.localized : [];
	const entries = localized
		.filter((entry) => entry?.url && entry?.file)
		.map((entry) => [entry.url, entry.file]);
	return entries.length ? new Map(entries) : null;
}

/** This site's public origin, from the scaffolded manifest. */
function siteOrigin() {
	try {
		return new URL(JSON.parse(readFileSync(resolve(process.cwd(), 'nvoos.json'), 'utf8')).url).origin;
	} catch {
		return '';
	}
}

localizeStructuredData();

// --- root-relative CMS assets ---------------------------------------------
// The localizer resolves ABSOLUTE urls, so a ROOT-RELATIVE `/wp-content/...`
// reference survives every pass: invisible to the retired-cms gate, which
// matches `//host/wp-content/`, yet dead the moment the CMS is retired
// (virtuallyjamaica ships seven lightbox hrefs of exactly this shape while the
// destination file is already mirrored beside them). Resolve the path against
// the profile origin and let the report's mapping win.
function localizeRootRelativeAssets() {
	const map = localizeReport();
	const origin = siteOrigin();
	if (!map || !origin) {
		return;
	}

	let pages = 0;
	let rewrites = 0;
	for (const document of collectDocuments()) {
		const html = readFileSync(document, 'utf8');
		const next = html.replace(
			/(["'(=\s])(\/wp-(?:content|includes)\/[^"'\s)]+)/gi,
			(match, lead, assetPath) => {
				const file = map.get(`${origin}${assetPath}`);
				if (!file) {
					return match;
				}
				rewrites += 1;
				return `${lead}${file}`;
			}
		);
		if (next !== html) {
			writeFileSync(document, next, 'utf8');
			pages += 1;
		}
	}
	if (rewrites > 0) {
		console.log(`[forge-postbuild] localized ${rewrites} root-relative CMS asset(s) on ${pages} document(s).`);
	}
}

localizeRootRelativeAssets();

// ---------------------------------------------------------------------------
// Proposal 165 — publication gate.
//
// The scaffold writes `gate.generated.json` from the bridge's verdict. When the
// CMS is gated, a STATIC artifact cannot answer a dynamic status code, so the
// honest options are: replace the documents with the gate page (default), or
// keep the site live and stage the page for an edge that can 503 (`keep`).
// A pre-launch site always replaces — a placeholder must never be published.
// ---------------------------------------------------------------------------
function applyPublicationGate() {
	const manifestPath = resolve(process.cwd(), 'gate.generated.json');
	if (!existsSync(manifestPath)) {
		return;
	}

	let gate;
	try {
		gate = JSON.parse(readFileSync(manifestPath, 'utf8'));
	} catch (error) {
		console.error(`[forge-postbuild] gate.generated.json is unreadable: ${error instanceof Error ? error.message : error}`);
		process.exit(1);
	}

	const mode = gate?.mode;
	if (mode !== 'maintenance' && mode !== 'coming_soon') {
		return;
	}

	// Static builds emit into dist/; a server build keeps the prerendered
	// documents under dist/client/ — the same split `_redirects` follows.
	const docRoot = existsSync(resolve(distDir, 'client', 'index.html')) ? resolve(distDir, 'client') : distDir;
	const page = mode === 'coming_soon' ? 'coming-soon' : 'maintenance';
	const pageFile = resolve(docRoot, page, 'index.html');
	// A server build has a front controller that answers the gate dynamically —
	// and serves the real document to a signed-in manager. Replacing the files
	// there would leave the manager staring at the holding page (2026-11-26: an
	// ECA managed deploy did exactly that). Replacement is the STATIC fallback.
	const serverBuild = existsSync( resolve( distDir, 'server', 'entry.mjs' ) );
	const replace = ! serverBuild && ( gate.policy !== 'keep' || mode === 'coming_soon' );

	if (!existsSync(pageFile)) {
		if (replace) {
			console.error(`[forge-postbuild] gate=${mode} but ${page}/index.html is missing — refusing to publish the real site.`);
			process.exit(1);
		}
		console.warn(`[forge-postbuild] gate=${mode} policy=keep but ${page}/index.html is missing; nothing staged.`);
		return;
	}

	const gateHtml = readFileSync(pageFile, 'utf8');

	if (replace) {
		const documents = [];
		const walk = (dir) => {
			for (const entry of readdirSync(dir, { withFileTypes: true })) {
				const path = resolve(dir, entry.name);
				if (entry.isDirectory()) {
					walk(path);
				} else if (entry.name.endsWith('.html')) {
					documents.push(path);
				}
			}
		};
		if (existsSync(docRoot)) {
			walk(docRoot);
		}

		const keep = new Set([resolve(docRoot, 'maintenance', 'index.html'), resolve(docRoot, 'coming-soon', 'index.html')]);
		let replaced = 0;
		for (const document of documents) {
			if (keep.has(document)) {
				continue;
			}
			writeFileSync(document, gateHtml, 'utf8');
			replaced += 1;
		}

		// Advertising the replaced URLs (or the CMS's own sitemap) is a soft-404
		// signal, so the map is emptied and robots stays crawlable: a `Disallow`
		// would hide the page's `noindex` from the crawler.
		writeFileSync(resolve(docRoot, 'robots.txt'), 'User-agent: *\nAllow: /\n', 'utf8');
		writeFileSync(
			resolve(docRoot, 'sitemap.xml'),
			'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>\n',
			'utf8'
		);
		if (existsSync(resolve(docRoot, 'feed.xml'))) {
			writeFileSync(
				resolve(docRoot, 'feed.xml'),
				'<?xml version="1.0" encoding="UTF-8"?>\n<rss version="2.0"><channel><title>Maintenance</title><link>/</link><description></description></channel></rss>\n',
				'utf8'
			);
		}

		const headerPath = resolve(docRoot, '_headers');
		const headerBlock = '/*\n  Cache-Control: no-store, no-cache, must-revalidate, max-age=0\n  CDN-Cache-Control: no-store\n';
		const existing = existsSync(headerPath) ? readFileSync(headerPath, 'utf8').trimEnd() : '';
		writeFileSync(headerPath, `${existing ? `${existing}\n\n` : ''}${headerBlock}`, 'utf8');

		console.log(
			`[forge-postbuild] gate=${mode} source=${gate.source ?? 'build'} policy=replace — replaced ${replaced} document(s).`
		);
		if (mode === 'maintenance') {
			console.log('[forge-postbuild] a static host cannot return 503; indexed URLs now see a 200 holding page. Prefer policy "keep" + the edge rule for long windows.');
		}
	} else {
		console.log(`[forge-postbuild] gate=${mode} source=${gate.source ?? 'build'} policy=keep — the site stays live; ${page}/index.html is staged.`);
	}

	// Diagnostic, secret-free (the managed runtime reads the same document).
	writeFileSync(
		resolve(distDir, 'gate.json'),
		JSON.stringify(
			{
				version: gate.version ?? 1,
				mode,
				source: gate.source ?? 'build',
				plugin: gate.plugin ?? null,
				retryAfter: gate.retryAfter ?? null,
				checkedAt: gate.checkedAt ?? null,
				policy: gate.policy ?? 'replace',
				page,
				buildId: process.env.FORGE_BUILD_ID ?? '',
			},
			null,
			2
		) + '\n',
		'utf8'
	);
}

applyPublicationGate();

if (!targetPath) {
	console.log('[forge-postbuild] no redirects.json — skipping _redirects.');
	process.exit(0);
}

/** Faithful status → the portable code a static host will actually honour. */
const PORTABLE_CODES = new Map([
	[301, 301],
	[302, 302],
	[303, 302],
	[307, 302],
	[308, 301],
	[404, 404],
	[410, 404],
]);

try {
	const entries = JSON.parse(readFileSync(mapPath, 'utf8'));
	let projected = 0;
	const lines = [];

	for (const entry of entries) {
		const code = PORTABLE_CODES.get(entry.status);
		if (code === undefined) {
			continue;
		}
		if (code !== entry.status) {
			projected += 1;
		}
		// 404/410 rules carry no target; the host still expects a placeholder
		// in the third column, so emit `/`.
		lines.push(`${entry.from}\t${entry.to || '/'}\t${code}`);
	}

	const notes = ['# Generated by @nvdigitalsolutions/nvoos-astro-forge.'];
	if (projected > 0) {
		notes.push(`# ${projected} rule(s) projected onto the portable status set (303/307→302, 308→301, 410→404).`);
	}
	if (entries.some((entry) => entry.status === 404 || entry.status === 410)) {
		notes.push('# Cloudflare Pages ignores 404/410 rules; an absent path already returns 404.');
	}

	writeFileSync(targetPath, `${notes.join('\n')}\n\n${lines.join('\n')}\n`, 'utf8');
	const projectedNote = projected > 0 ? ` (${projected} projected)` : '';
	console.log(`[forge-postbuild] wrote ${lines.length} redirect(s) to ${targetPath}${projectedNote}.`);
} catch (error) {
	console.error(`[forge-postbuild] failed: ${error instanceof Error ? error.message : error}`);
	process.exit(1);
}
