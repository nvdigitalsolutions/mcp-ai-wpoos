#!/usr/bin/env node
// ---------------------------------------------------------------------------
// sync-dist.mjs — assemble the deploy tree for the public mirror
// (nvoos-astro-velocity). Mirrors the console pattern with one difference:
// the Astro SSR runtime keeps real dependencies, so package.json retains
// them and only build-adjacent scripts become no-op shims. dist/ is built
// by CI (or locally) before this script runs.
// ---------------------------------------------------------------------------

import { cpSync, mkdirSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..');
const STAGE = join(HERE, '.mirror-staging');

if (!readFileSync(join(ROOT, 'package.json'), 'utf8')) {
	process.exit(1);
}

// Fail loudly unless the build output exists — a stale mirror is worse than
// a failed sync.
try {
	readFileSync(join(ROOT, 'dist', 'server', 'entry.mjs'));
} catch {
	console.error('[sync-dist] dist/server/entry.mjs missing — run `npm run build` first.');
	process.exit(1);
}

rmSync(STAGE, { recursive: true, force: true });
mkdirSync(STAGE, { recursive: true });

// package.json — keep real deps (SSR runtime), no-op the dev scripts.
const pkg = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8'));
pkg.scripts = {
	start: 'node server.js',
	serve: 'node scripts/serve.mjs',
	build: 'echo "(prebuilt dist — no build on Velocity)"',
	test: 'echo "(tests run in the monorepo CI)"',
	lint: 'echo "(lint runs in the monorepo CI)"',
	check: 'echo "(check runs in the monorepo CI)"',
	dev: 'echo "(dev only in the monorepo)"',
};
writeFileSync(join(STAGE, 'package.json'), `${JSON.stringify(pkg, null, '\t')}\n`);

// Deploy artifacts.
cpSync(join(ROOT, 'dist'), join(STAGE, 'dist'), { recursive: true });
cpSync(join(ROOT, 'server.js'), join(STAGE, 'server.js'));
cpSync(join(HERE, 'serve.mjs'), join(STAGE, 'scripts', 'serve.mjs'));
// Runtime libs imported by serve.mjs (proxy-headers et al.) — the mirror
// must boot the same module graph the monorepo smoke-tests.
cpSync(join(HERE, 'lib'), join(STAGE, 'scripts', 'lib'), { recursive: true });
cpSync(join(ROOT, 'README.md'), join(STAGE, 'README.md'));
cpSync(join(ROOT, 'LICENSE'), join(STAGE, 'LICENSE'));

console.log(`[sync-dist] mirror tree assembled in ${STAGE}`);

// Boot smoke: the assembled tree must start. This catches missing runtime
// modules in the mirror (storefront 502 incident 2026-10-29 — serve.mjs
// gained ./lib/proxy-headers.mjs but sync-dist didn't ship it).
{
	const child = spawn(process.execPath, [join(STAGE, 'server.js')], {
		env: { ...process.env, PORT: '4399' },
		stdio: 'ignore',
	});

	await new Promise((resolve) => setTimeout(resolve, 1500));

	if (child.exitCode !== null) {
		console.error(
			`[sync-dist] staged tree exited at boot (code ${child.exitCode}) — the mirror would 502. ` +
				'Check that every runtime import of scripts/serve.mjs is copied into the deploy tree.'
		);
		process.exit(1);
	}

	child.kill();
	console.log('[sync-dist] staged tree boot check passed');
}
