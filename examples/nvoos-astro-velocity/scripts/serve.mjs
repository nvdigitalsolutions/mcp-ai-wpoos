#!/usr/bin/env node
// ---------------------------------------------------------------------------
// nvoos-astro-velocity — BFF server (Proposal 092 §5.5 / D2).
//
// Mounts the Astro standalone SSR handler and falls through to reverse-proxy
// rules for the backend prefixes, so the storefront, the NV commerce
// contract (/api/*), media (/media/*) and the backend admin all live on ONE
// origin — cookie auth works with no CORS configuration (the proven console
// BFF pattern).
//
// Routing order (first match wins):
//   /api/*            → ${NVOOS_TARGET_SITE}/wp-json/nvoos-commerce/v1/*
//   /media/*          → ${NVOOS_TARGET_SITE}/wp-content/uploads/*
//   /wp-json/*        → ${NVOOS_TARGET_SITE}/wp-json/*
//   /wp-admin/*       → ${NVOOS_TARGET_SITE}/wp-admin/*
//   /wp-login.php     → ${NVOOS_TARGET_SITE}/wp-login.php
//   /wp-includes/*    → ${NVOOS_TARGET_SITE}/wp-includes/*
//   everything else   → Astro SSR handler
//
// Micro-cache (default-deny): proxied GET responses are cached only when
// the backend marks them `Cache-Control: public` (bridge B1 does this for
// public commerce GETs) with a TTL capped by NVOOS_PROXY_CACHE_MAX_TTL.
// Responses with Set-Cookie or Vary: Cookie are never cached.
// ---------------------------------------------------------------------------

import { createServer } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { request as httpRequest } from 'node:http';
import { URL } from 'node:url';

const PORT = Number(process.env.PORT) || 4321;
const TARGET_SITE = (process.env.NVOOS_TARGET_SITE || '').replace(/\/+$/, '');
const PROXY_CACHE_DISABLED = process.env.NVOOS_PROXY_CACHE === '0';
const PROXY_CACHE_MAX_TTL = Number(process.env.NVOOS_PROXY_CACHE_MAX_TTL) || 300;

if (!TARGET_SITE) {
	console.error('[serve] NVOOS_TARGET_SITE is not set — the /api and admin proxies have no backend.');
	// Continue booting: the Astro layer still serves (REST islands will fail).
}

// The standalone entry auto-starts its own listener unless disabled — the
// BFF owns the listening socket instead (official @astrojs/node switch).
process.env.ASTRO_NODE_AUTOSTART = 'disabled';

// SSR renders self-fetch the commerce contract through THIS listener
// (http://127.0.0.1:<PORT>/api/*). On Velocity the public self-request
// (https://astro.nvoos.cloud/api/*) fails at the network layer, so the
// commerce client prefers this loopback origin when present
// (incident 2026-10-29 #2).
process.env.NVOOS_SSR_API_ORIGIN = `http://127.0.0.1:${PORT}`;

const { handler: astroHandler } = await import('../dist/server/entry.mjs');

// ---------------------------------------------------------------------------
// Proxy core.
// ---------------------------------------------------------------------------

const HOP_BY_HOP = new Set([
	'connection',
	'keep-alive',
	'proxy-authenticate',
	'proxy-authorization',
	'proxy-connection',
	'te',
	'trailer',
	'transfer-encoding',
	'upgrade',
]);

const microCache = new Map();

function cacheKey(req) {
	return req.method + ' ' + req.url;
}

function stripDomainFromSetCookie(setCookies) {
	if (!Array.isArray(setCookies)) {
		return setCookies;
	}
	return setCookies.map((cookie) => cookie.replace(/;\s*Domain=[^;]+/gi, ''));
}

function forwardHeaders(headers, { dropHost = true } = {}) {
	const out = {};
	for (const [key, value] of Object.entries(headers)) {
		const lower = key.toLowerCase();
		if (HOP_BY_HOP.has(lower)) {
			continue;
		}
		if (dropHost && lower === 'host') {
			continue;
		}
		out[key] = value;
	}
	return out;
}

function proxyTo(target, req, res) {
	const targetUrl = new URL(target);
	const client = targetUrl.protocol === 'https:' ? httpsRequest : httpRequest;

	const upstream = client(
		{
			protocol: targetUrl.protocol,
			hostname: targetUrl.hostname,
			port: targetUrl.port || undefined,
			path: targetUrl.pathname + targetUrl.search,
			method: req.method,
			headers: forwardHeaders(req.headers),
		},
		(upstreamRes) => {
			const status = upstreamRes.statusCode ?? 502;
			const setCookies = stripDomainFromSetCookie(upstreamRes.headers['set-cookie']);
			const headers = forwardHeaders(upstreamRes.headers, { dropHost: false });
			if (setCookies) {
				headers['set-cookie'] = setCookies;
			}
			res.writeHead(status, headers);
			upstreamRes.pipe(res);
		}
	);

	upstream.on('error', () => {
		if (!res.headersSent) {
			res.writeHead(502, {
				'content-type': 'application/json',
				'cache-control': 'no-store',
			});
		}
		res.end(JSON.stringify({ code: 'nvoos_bad_gateway' }));
	});

	req.pipe(upstream);
}

function shouldCache(status, headers) {
	if (!status || status < 200 || status >= 300) {
		return false;
	}
	const cacheControl = String(headers['cache-control'] ?? '');
	if (!cacheControl.includes('public')) {
		return false;
	}
	if (headers['set-cookie'] || String(headers['vary'] ?? '').toLowerCase().includes('cookie')) {
		return false;
	}
	const match = /s-maxage=(\d+)/.exec(cacheControl);
	if (!match) {
		return false;
	}
	return Math.min(Number(match[1]), PROXY_CACHE_MAX_TTL);
}

function proxyWithCache(target, req, res) {
	if (PROXY_CACHE_DISABLED || req.method !== 'GET') {
		return proxyTo(target, req, res);
	}

	const key = cacheKey(req);
	const hit = microCache.get(key);
	if (hit && hit.expires > Date.now()) {
		res.writeHead(hit.status, {
			...hit.headers,
			'x-cache-status': 'HIT',
		});
		res.end(hit.body);
		return;
	}

	// Capture the upstream body to decide on caching.
	const targetUrl = new URL(target);
	const client = targetUrl.protocol === 'https:' ? httpsRequest : httpRequest;
	const chunks = [];

	const upstream = client(
		{
			protocol: targetUrl.protocol,
			hostname: targetUrl.hostname,
			port: targetUrl.port || undefined,
			path: targetUrl.pathname + targetUrl.search,
			method: req.method,
			headers: forwardHeaders(req.headers),
		},
		(upstreamRes) => {
			upstreamRes.on('data', (chunk) => chunks.push(chunk));
			upstreamRes.on('end', () => {
				const body = Buffer.concat(chunks);
				const status = upstreamRes.statusCode ?? 502;
				const setCookies = stripDomainFromSetCookie(upstreamRes.headers['set-cookie']);
				const headers = forwardHeaders(upstreamRes.headers, { dropHost: false });
				if (setCookies) {
					headers['set-cookie'] = setCookies;
				}

				const ttl = shouldCache(status, headers);
				if (ttl > 0) {
					microCache.set(key, { status, headers, body, expires: Date.now() + ttl * 1000 });
					headers['x-cache-status'] = 'MISS';
				} else {
					headers['x-cache-status'] = 'BYPASS';
				}

				res.writeHead(status, headers);
				res.end(body);
			});
		}
	);

	upstream.on('error', () => {
		if (!res.headersSent) {
			res.writeHead(502, {
				'content-type': 'application/json',
				'cache-control': 'no-store',
			});
		}
		res.end(JSON.stringify({ code: 'nvoos_bad_gateway' }));
	});

	req.pipe(upstream);
}

// ---------------------------------------------------------------------------
// Routing.
// ---------------------------------------------------------------------------

function route(req, res) {
	const url = new URL(req.url ?? '/', `http://${req.headers.host ?? 'localhost'}`);
	const path = url.pathname;

	if (path.startsWith('/api/')) {
		const rest = path.slice('/api/'.length);
		return proxyWithCache(`${TARGET_SITE}/wp-json/nvoos-commerce/v1/${rest}${url.search}`, req, res);
	}
	if (path.startsWith('/media/')) {
		const rest = path.slice('/media/'.length);
		return proxyWithCache(`${TARGET_SITE}/wp-content/uploads/${rest}${url.search}`, req, res);
	}
	if (path.startsWith('/wp-json/')) {
		return proxyWithCache(`${TARGET_SITE}${path}${url.search}`, req, res);
	}
	if (path.startsWith('/wp-admin/') || path === '/wp-admin') {
		return proxyWithCache(`${TARGET_SITE}${path}${url.search}`, req, res);
	}
	if (path === '/wp-login.php') {
		return proxyWithCache(`${TARGET_SITE}${path}${url.search}`, req, res);
	}
	if (path.startsWith('/wp-includes/')) {
		return proxyWithCache(`${TARGET_SITE}${path}${url.search}`, req, res);
	}

	// Astro handles everything else (pages, /_astro assets, sitemap, robots).
	return astroHandler(req, res, () => {
		if (!res.headersSent) {
			res.writeHead(404, { 'content-type': 'text/plain' });
		}
		res.end('Not found');
	});
}

const server = createServer(route);

server.listen(PORT, () => {
	console.log(`[serve] nvoos-astro-velocity listening on :${PORT}`);
	console.log(
		`[serve] proxy origin: ${TARGET_SITE || '(unset — /api and admin proxying disabled)'}`
	);
});
