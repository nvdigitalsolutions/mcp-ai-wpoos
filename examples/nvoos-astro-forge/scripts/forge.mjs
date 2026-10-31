#!/usr/bin/env node
// ---------------------------------------------------------------------------
// forge.mjs — the Forge CLI (Proposal 096 Phase 0).
//
//   node scripts/forge.mjs <site.json> --target <dir> [--template blog] [--check]
//
// <site.json> is an operator-written SiteProfile (allowlisted URL + collections).
// `--check` performs a live fetch against the source to report collection
// sizes and flag truncation under the configured maxPages — a build must
// never silently ship a partial catalog (096 §2.2).
//
// Requires Node >= 22.6 (native TypeScript type stripping).
// ---------------------------------------------------------------------------

import { readFile } from 'node:fs/promises';

import { scaffold } from '../lib/scaffold.ts';
import { WpClient } from '../lib/wp-client.ts';

function usage() {
	console.error('usage: node scripts/forge.mjs <site.json> --target <dir> [--template <name|path>] [--overwrite] [--check]');
	process.exit(2);
}

function parseArgs(argv) {
	const args = { profilePath: undefined, target: undefined, template: undefined, overwrite: false, check: false };
	for (let i = 0; i < argv.length; i++) {
		const arg = argv[i];
		if (arg === '--target') args.target = argv[++i];
		else if (arg === '--template') args.template = argv[++i];
		else if (arg === '--overwrite') args.overwrite = true;
		else if (arg === '--check') args.check = true;
		else if (!arg.startsWith('--') && !args.profilePath) args.profilePath = arg;
		else usage();
	}
	if (!args.profilePath || !args.target) usage();
	return args;
}

/** Live check: collection sizes vs caps. Reports, never mutates. */
async function checkProfile(profile) {
	const client = WpClient.fromProfile(profile);
	let clean = true;
	try {
		const root = await client.root();
		console.log(`\u2713 ${profile.url} — "${root.name}" (${root.namespaces.length} namespaces)`);
	} catch (error) {
		console.error(`\u2717 ${profile.url} — unreachable: ${error instanceof Error ? error.message : error}`);
		process.exit(1);
	}
	for (const collection of profile.collections) {
		const perPage = collection.perPage ?? 100;
		const maxPages = collection.maxPages ?? 50;
		try {
			const { headers } = await client.get(collection.restBase, { page: '1', per_page: '1' });
			const total = Number(headers.get('x-wp-total') ?? '0');
			const pages = Math.ceil(total / perPage);
			const state = pages > maxPages ? 'TRUNCATED' : 'ok';
			if (pages > maxPages) clean = false;
			console.log(
				`  ${state === 'ok' ? '\u2713' : '\u26a0'} ${collection.slug}: ${total} entries / ${pages} pages (cap ${maxPages}) — ${state}`
			);
		} catch (error) {
			clean = false;
			console.error(
				`  \u2717 ${collection.slug}: fetch failed — ${error instanceof Error ? error.message : error}`
			);
		}
	}
	return clean;
}

const args = parseArgs(process.argv.slice(2));

let profile;
try {
	profile = JSON.parse(await readFile(args.profilePath, 'utf8'));
} catch (error) {
	console.error(`Cannot read profile ${args.profilePath}: ${error instanceof Error ? error.message : error}`);
	process.exit(1);
}
if (!profile.url || !Array.isArray(profile.collections) || profile.collections.length === 0) {
	console.error('site.json must contain { url, collections: [...] }');
	process.exit(1);
}

if (args.check) {
	const clean = await checkProfile(profile);
	console.log(clean ? 'All collections fit within their page caps.' : 'Some collections exceed their caps — raise maxPages in the profile.');
	process.exit(clean ? 0 : 1);
}

const result = await scaffold({ profile, targetDir: args.target, template: args.template, overwrite: args.overwrite });
console.log(`\u2713 Scaffolded ${result.collections.length} collection(s) into ${result.targetDir}`);
for (const collection of result.manifest.collections) {
	console.log(`  - ${collection.slug} <- ${collection.restBase}`);
}
for (const warning of result.warnings) {
	console.warn(`\u26a0 ${warning}`);
}
console.log('Next: cd into the project, `npm install`, `npm run dev` (or `npm run build`).');
