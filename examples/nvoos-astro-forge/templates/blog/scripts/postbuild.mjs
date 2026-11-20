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
	const reportPath = resolve(process.cwd(), '.forge-cache', 'media-localize.json');
	if (!existsSync(reportPath)) {
		return;
	}
	let report;
	try {
		report = JSON.parse(readFileSync(reportPath, 'utf8'));
	} catch {
		return;
	}
	const localized = Array.isArray(report?.localized) ? report.localized : [];
	const map = localized
		.filter((entry) => entry?.url && entry?.file)
		.map((entry) => [entry.url, entry.file]);
	if (map.length === 0) {
		return;
	}
	// Structured data wants an ABSOLUTE url; the report's `file` is root-relative.
	const origin = (() => {
		try {
			return new URL(JSON.parse(readFileSync(resolve(process.cwd(), 'nvoos.json'), 'utf8')).url).origin;
		} catch {
			return '';
		}
	})();

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

localizeStructuredData();

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
