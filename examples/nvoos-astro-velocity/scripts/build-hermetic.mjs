#!/usr/bin/env node
// ---------------------------------------------------------------------------
// build-hermetic.mjs — build the app against the fixture contract server.
//
// Spawns scripts/fixture-server.mjs, waits for readiness, runs `astro build`
// with NVOOS_API_BASE pointing at it, then tears the fixture server down.
// This is the hermetic equivalent of the CI build (P5: prerendering must
// succeed against the golden fixtures with zero network).
// ---------------------------------------------------------------------------

import { spawn, spawnSync } from 'node:child_process';

const FIXTURE_PORT = process.env.FIXTURE_PORT || '8123';
// Local/test builds use the local origin so the runtime /_image endpoint
// resolves absolute hrefs through the local BFF. Production mirror builds
// (CI's second pass) override with the real canonical host.
const ASTRO_SITE = process.env.ASTRO_SITE || 'http://localhost:4321';
const API_BASE = `http://localhost:${FIXTURE_PORT}/wp-json/nvoos-commerce/v1`;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const server = spawn(process.execPath, ['scripts/fixture-server.mjs'], {
	env: { ...process.env, PORT: FIXTURE_PORT },
	stdio: 'inherit',
});

let exitCode = 0;
try {
	await sleep(1000);

	// The /manage surface's CMS bundle must be present before astro build
	// copies public/ into dist/.
	const manage = spawnSync(process.execPath, ['scripts/sync-manage-assets.mjs'], {
		stdio: 'inherit',
	});
	if (manage.status !== 0) {
		exitCode = manage.status ?? 1;
		process.exit(exitCode);
	}

	const build = spawn('npx astro build', {
		shell: true,
		stdio: 'inherit',
		env: {
			...process.env,
			NVOOS_API_BASE: API_BASE,
			ASTRO_SITE,
		},
	});

	exitCode = await new Promise((resolve) => {
		build.on('exit', (code) => resolve(code ?? 1));
	});
} finally {
	server.kill();
}

process.exit(exitCode);
