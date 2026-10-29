#!/usr/bin/env node
// ---------------------------------------------------------------------------
// lhci-local.mjs — run Lighthouse CI against the hermetic local servers.
//
// Boots the fixture contract server + BFF (reusing any already running),
// discovers Chromium (CHROME_PATH or the Playwright-installed build), runs
// `lhci collect` + `lhci assert`, and tears the servers down.
// ---------------------------------------------------------------------------

import { spawn } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { homedir } from 'node:os';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function reachable(url) {
	try {
		const res = await fetch(url);
		return res.status < 500;
	} catch {
		return false;
	}
}

function findChrome() {
	if (process.env.CHROME_PATH && existsSync(process.env.CHROME_PATH)) {
		return process.env.CHROME_PATH;
	}
	// Playwright-installed Chromium (Windows + Linux layouts).
	const roots = process.env.PLAYWRIGHT_BROWSERS_PATH
		? [process.env.PLAYWRIGHT_BROWSERS_PATH]
		: [join(homedir(), 'AppData', 'Local', 'ms-playwright'), join(homedir(), '.cache', 'ms-playwright')];
	const candidates = [
		join('chrome-win64', 'chrome.exe'),
		join('chrome-linux', 'chrome'),
		join('chrome-win64', 'headless_shell.exe'),
		join('chrome-linux', 'headless_shell'),
	];
	for (const root of roots) {
		if (!existsSync(root)) {
			continue;
		}
		// The install dir is versioned — try any child dir containing the binary.
		for (const dir of readdirSync(root)) {
			for (const candidate of candidates) {
				const full = join(root, dir, ...candidate.split('/'));
				if (existsSync(full)) {
					return full;
				}
			}
		}
	}
	return null;
}

const chrome = findChrome();
if (!chrome) {
	console.error('[lhci-local] Chromium not found — set CHROME_PATH or run `npx playwright install chromium`.');
	process.exit(1);
}
console.log(`[lhci-local] chrome: ${chrome}`);

const alreadyUp = await reachable('http://localhost:4321/');
const fixtureServer = alreadyUp
	? null
	: spawn(process.execPath, ['scripts/fixture-server.mjs'], {
			env: { ...process.env, PORT: '8123' },
			stdio: 'inherit',
		});
const appServer = alreadyUp
	? null
	: spawn(process.execPath, ['scripts/serve.mjs'], {
			env: {
				...process.env,
				PORT: '4321',
				NVOOS_TARGET_SITE: 'http://localhost:8123',
			},
			stdio: 'inherit',
		});

const run = (command, args) =>
	new Promise((resolve) => {
		const child = spawn(command, args, { shell: true, stdio: 'inherit' });
		child.on('exit', (code) => resolve(code ?? 1));
	});

let exitCode = 1;
try {
	await sleep(alreadyUp ? 1000 : 3000);
	const collectCode = await run(
		process.platform === 'win32' ? 'npx.cmd' : 'npx',
		['lhci', 'collect', '--config=lighthouserc.cjs', `--settings.chromePath=${chrome}`]
	);
	if (collectCode === 0) {
		exitCode = await run(process.platform === 'win32' ? 'npx.cmd' : 'npx', [
			'lhci',
			'assert',
			'--config=lighthouserc.cjs',
		]);
	} else {
		exitCode = collectCode;
	}
} finally {
	if (appServer) {
		appServer.kill();
	}
	if (fixtureServer) {
		fixtureServer.kill();
	}
}

process.exit(exitCode);
