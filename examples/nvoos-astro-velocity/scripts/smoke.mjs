#!/usr/bin/env node
// ---------------------------------------------------------------------------
// smoke.mjs — P6 end-to-end smoke: fixture contract server + BFF + built app.
//
// Boots scripts/fixture-server.mjs (:8123) and scripts/serve.mjs (:4321,
// NVOOS_TARGET_SITE → fixtures), then asserts:
//   - /                  renders the storefront shell
//   - an SSR PDP         carries JSON-LD in the initial HTML (P3, 104 §3.2)
//   - unknown ids 404    (104 publication/SSR guard)
//   - /api/products      proxies to the NV commerce contract (envelope)
//   - /sitemap.xml       lists the catalog
// ---------------------------------------------------------------------------

import { spawn } from 'node:child_process';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// Reuse servers that are already running (CI boots them for build/LHCI;
// Playwright webServer also reuses them) — otherwise spawn the hermetic pair.
async function reachable(url) {
	try {
		const res = await fetch(url);
		return res.status < 500;
	} catch {
		return false;
	}
}

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
				// Q3 (Proposal 104 §4.1): the same-deploy cache invalidation seam.
				NVOOS_REVALIDATE_TOKEN: 'smoke-revalidate-secret',
			},
			stdio: 'inherit',
		});

const checks = [
	{
		name: 'home shell',
		url: 'http://localhost:4321/',
		assert: (body) => body.includes('NV Store'),
	},
	{
		name: 'SSR PDP with JSON-LD (P3)',
		url: 'http://localhost:4321/products/1/aurora-3-seater-sofa/',
		assert: (body) =>
			body.includes('application/ld+json') && body.includes('Aurora 3-Seater Sofa'),
	},
	{
		name: 'unknown product id → 404 (104)',
		url: 'http://localhost:4321/products/999999/whatever/',
		assert: (_body, res) => res.status === 404,
	},
	{
		name: 'draft product id → 404 (104 publication guard)',
		url: 'http://localhost:4321/products/9901/unreleased-prototype-sofa/',
		assert: (_body, res) => res.status === 404,
	},
	{
		name: 'sitemap excludes drafts (104)',
		url: 'http://localhost:4321/sitemap.xml',
		assert: (body) => !body.includes('/products/9901/'),
	},
	{
		name: 'og card renders a PNG (104 Q5)',
		url: 'http://localhost:4321/og/product/1.png',
		assert: (_body, res) =>
			res.status === 200 &&
			String(res.headers.get('content-type')).startsWith('image/png'),
	},
	{
		name: 'revalidate rejects a bad bearer (104 Q3)',
		url: 'http://localhost:4321/internal/revalidate/',
		method: 'POST',
		headers: { authorization: 'Bearer wrong' },
		assert: (_body, res) => res.status === 401,
	},
	{
		name: 'revalidate purges tags with the shared secret (104 Q3)',
		url: 'http://localhost:4321/internal/revalidate/',
		method: 'POST',
		headers: { authorization: 'Bearer smoke-revalidate-secret' },
		body: JSON.stringify({ tags: ['products'] }),
		assert: (_body, res) => res.status === 200,
	},
	{
		name: 'wrong slug → 301 to canonical (104)',
		url: 'http://localhost:4321/products/1/wrong-slug/',
		// fetch follows redirects by default — assert the 301 itself.
		redirect: 'manual',
		assert: (_body, res) =>
			res.status === 301 &&
			String(res.headers.get('location') ?? '').includes('/products/1/aurora-3-seater-sofa/'),
	},
	{
		name: 'all-products PLP renders (104 P3)',
		url: 'http://localhost:4321/products/',
		assert: (body) => body.includes('/products/1/') && body.includes('All products'),
	},
	{
		name: 'contract proxy envelope (/api/products)',
		url: 'http://localhost:4321/api/products',
		assert: (body) => body.includes('"totalDocs"') && body.includes('"docs"'),
	},
	{
		name: 'sitemap lists the catalog',
		url: 'http://localhost:4321/sitemap.xml',
		assert: (body) => body.includes('<urlset') && body.includes('/products/1/'),
	},
	{
		name: 'media proxy forwards uploads',
		url: 'http://localhost:4321/media/2026/10/aurora-sofa.jpg',
		// The fixture origin has no real files — the point is that the request
		// reached the fixture server (its JSON 404 proves the proxy forwarded).
		assert: (body, res) => body.includes('fixture_not_found') || res.status === 200,
	},
	{
		name: 'PLP server-rendered with real links (P2)',
		url: 'http://localhost:4321/categories/1/sofas/',
		assert: (body) =>
			body.includes('/products/1/') && body.includes('Aurora 3-Seater Sofa'),
	},
	{
		name: 'PLP color filter narrows the grid + noindex',
		url: 'http://localhost:4321/categories/1/sofas/?color=4',
		assert: (body) =>
			body.includes('Solace Daybed') &&
			!body.includes('Aurora 3-Seater Sofa') &&
			body.includes('noindex, follow'),
	},
	{
		name: 'promocode validation endpoint (Q5)',
		url: 'http://localhost:4321/api/promocodes/validate?code=SUMMER10',
		assert: (body) => body.includes('SUMMER10') && body.includes('discount'),
	},
	{
		name: 'auth nonce endpoint (Q5)',
		url: 'http://localhost:4321/api/auth/nonce',
		assert: (body) => body.includes('nonce'),
	},
	{
		name: 'home emits runtime-optimized /_image URLs (Phase 1.7)',
		url: 'http://localhost:4321/',
		assert: (body) => body.includes('/_image/?href='),
	},
	{
		name: '/_image endpoint optimizes media on demand',
		url: 'http://localhost:4321/_image/?href=http%3A%2F%2Flocalhost%3A4321%2Fmedia%2F2026%2F10%2Faurora-sofa.jpg&w=400&f=webp',
		assert: (_body, res) =>
			res.status === 200 && String(res.headers.get('content-type')).startsWith('image/'),
	},
];

let failed = false;
try {
	await sleep(2500);

	for (const check of checks) {
		try {
			const res = await fetch(check.url, {
				redirect: check.redirect ?? 'follow',
				method: check.method ?? 'GET',
				headers: check.headers,
				body: check.body,
			});
			const body = await res.text();
			const pass = check.assert(body, res);
			console.log(`${pass ? 'PASS' : 'FAIL'}  ${check.name}  (${res.status})`);
			if (!pass) {
				failed = true;
				console.log(`       body head: ${body.slice(0, 200)}`);
			}
		} catch (err) {
			console.log(`FAIL  ${check.name}  (${err instanceof Error ? err.message : err})`);
			failed = true;
		}
	}
} finally {
	if (appServer) {
		appServer.kill();
	}
	if (fixtureServer) {
		fixtureServer.kill();
	}
}

process.exit(failed ? 1 : 0);
