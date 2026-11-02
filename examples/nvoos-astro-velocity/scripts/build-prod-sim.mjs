#!/usr/bin/env node
// ---------------------------------------------------------------------------
// build-prod-sim.mjs — Proposal 104 §5.1 CI gate: a production-parity build
// with NO build-time commerce base (the mirror invariant — the 092 prerender
// baked the fixture server into dist, so the mirror shipped fixture PDPs).
// Fails the moment any route still fetches the contract at build time.
// The workflow's "Assemble mirror tree" step runs this same chain in CI.
// ---------------------------------------------------------------------------

delete process.env.NVOOS_API_BASE;
process.env.ASTRO_SITE = process.env.ASTRO_SITE || 'https://astro.nvoos.cloud';

import { spawnSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ASTRO_CLI = join(
	dirname(fileURLToPath(import.meta.url)),
	'..',
	'node_modules',
	'astro',
	'bin',
	'astro.mjs'
);

const steps = [
	[process.execPath, [ASTRO_CLI, 'check']],
	[process.execPath, ['scripts/sync-manage-assets.mjs']],
	[process.execPath, [ASTRO_CLI, 'build']],
];

for (const [cmd, args] of steps) {
	const result = spawnSync(cmd, args, { stdio: 'inherit' });
	if (result.status !== 0) {
		process.exit(result.status ?? 1);
	}
}

console.log('[build-prod-sim] hermetic production-parity build OK (no NVOOS_API_BASE)');
