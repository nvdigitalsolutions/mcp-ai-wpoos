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
// Admin proxy hardening (research-backed; console BFF parity):
//   - /wp-login.php HTML bodies are re-scoped to the app origin (absolute
//     form action + hidden redirect_to → relative), so the login POST and
//     the post-login redirect stay inside the proxy and the host-only auth
//     cookies round-trip. Identity body via Accept-Encoding strip upstream.
//   - Location headers on /wp-admin and /wp-login.php responses become
//     RELATIVE with redirect_to re-scoped (nginx proxy_redirect pattern —
//     no backend host leak, no broken cookie handshake).
//   - Set-Cookie Domain attributes are stripped (OWASP: host-only cookies).
//   - X-Robots-Tag: noindex; admin responses are never micro-cached.
//   - Upstream requests carry overwritten x-forwarded-proto/host (spoof-safe)
//     and drop origin/referer (no app-origin leak into backend logs).
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

import {
	HOP_BY_HOP,
	applyAdminResponseHeaders,
	buildUpstreamHeaders,
	requestOrigin,
	rewriteLoginPageHtml,
	stripDomainFromSetCookie,
} from './lib/proxy-headers.mjs';

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

// Cap on body size for the login-page rewrite. wp-login.php pages are a few
// tens of KB; anything larger is passed through unchanged instead of running
// the regexes over a huge body.
const MAX_REWRITE_BYTES = 4 * 1024 * 1024;

const microCache = new Map();

function cacheKey(req) {
	return req.method + ' ' + req.url;
}

/** True for the admin surface — Location rewriting + noindex + no cache. */
function isAdminProxy(path) {
	return path.startsWith('/wp-admin') || path === '/wp-login.php';
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

function proxyTo(target, req, res, { admin = false, loginPage = false } = {}) {
	const targetUrl = new URL(target);
	const client = targetUrl.protocol === 'https:' ? httpsRequest : httpRequest;

	const upstreamHeaders = buildUpstreamHeaders(req.headers, {
		targetHost: targetUrl.host,
		origin: requestOrigin(req),
	});
	// Identity body so the login page can be rewritten (nginx sub_filter
	// equivalent: Accept-Encoding "" upstream).
	if (loginPage) {
		delete upstreamHeaders['accept-encoding'];
	}

	const upstream = client(
		{
			protocol: targetUrl.protocol,
			hostname: targetUrl.hostname,
			port: targetUrl.port || undefined,
			path: targetUrl.pathname + targetUrl.search,
			method: req.method,
			headers: upstreamHeaders,
		},
		(upstreamRes) => {
			const status = upstreamRes.statusCode ?? 502;
			let headers = forwardHeaders(upstreamRes.headers, { dropHost: false });
			if (admin) {
				headers = applyAdminResponseHeaders(headers, {
					targetSite: TARGET_SITE,
				});
			}
			const setCookies = stripDomainFromSetCookie(upstreamRes.headers['set-cookie']);
			if (setCookies) {
				headers['set-cookie'] = setCookies;
			}

			// Buffer the login page so the form can be re-scoped to the app
			// origin (see rewriteLoginPageHtml). The Domain-stripped cookies
			// are already in place; the rewritten body goes out chunked.
			const contentType = String(upstreamRes.headers['content-type'] ?? '').toLowerCase();
			const canRewrite =
				loginPage &&
				contentType.includes('text/html') &&
				!upstreamRes.headers['content-encoding'];

			if (!canRewrite) {
				res.writeHead(status, headers);
				upstreamRes.pipe(res);
				return;
			}

			const chunks = [];
			let size = 0;
			upstreamRes.on('data', (chunk) => {
				size += chunk.length;
				chunks.push(chunk);
			});
			upstreamRes.on('end', () => {
				let body = Buffer.concat(chunks).toString('utf8');
				if (size <= MAX_REWRITE_BYTES) {
					body = rewriteLoginPageHtml(body);
					delete headers['content-length'];
				}
				res.writeHead(status, headers);
				res.end(body);
			});
			upstreamRes.on('error', () => res.destroy());
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

function proxyWithCache(target, req, res, { admin = false, loginPage = false } = {}) {
	// Admin responses are never cached — login/dashboard state must not stick.
	if (PROXY_CACHE_DISABLED || req.method !== 'GET' || admin) {
		return proxyTo(target, req, res, { admin, loginPage });
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
			headers: buildUpstreamHeaders(req.headers, {
				targetHost: targetUrl.host,
				origin: requestOrigin(req),
			}),
		},
		(upstreamRes) => {
			upstreamRes.on('data', (chunk) => chunks.push(chunk));
			upstreamRes.on('end', () => {
				const body = Buffer.concat(chunks);
				const status = upstreamRes.statusCode ?? 502;
				let headers = forwardHeaders(upstreamRes.headers, { dropHost: false });
				if (admin) {
					headers = applyAdminResponseHeaders(headers, {
						targetSite: TARGET_SITE,
					});
				}
				const setCookies = stripDomainFromSetCookie(upstreamRes.headers['set-cookie']);
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
	if (path.startsWith('/wp-admin/') || path === '/wp-admin' || path === '/wp-login.php') {
		return proxyWithCache(`${TARGET_SITE}${path}${url.search}`, req, res, {
			admin: isAdminProxy(path),
			loginPage: path === '/wp-login.php',
		});
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
