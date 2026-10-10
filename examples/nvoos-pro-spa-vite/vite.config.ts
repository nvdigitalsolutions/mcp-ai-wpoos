import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';
import type { IncomingMessage, ServerResponse } from 'node:http';

/**
 * Standalone NV oOS Pro SPA — Vite shell.
 *
 * Reuses the spa-v2 React sources (addons/pro/assets/spa-v2/src) via alias
 * instead of forking them, so the standalone app tracks the plugin SPA.
 * The shell's only jobs: build the `window.NVOOS_PRO_SPA` runtime from user
 * input (see src/runtime-config.ts), shim `@wordpress/i18n`, and mount <App/>.
 *
 * Env:
 *   NVOOS_TARGET_SITE  — WordPress site to proxy in dev (cookie-auth mode),
 *                        e.g. http://localhost:8000 or https://example.com.
 *                        Read from the shell or a .env file (.env, .env.local,
 *                        .env.development, .env.development.local — later
 *                        files win); the shell takes precedence.
 *   NVOOS_WORKER_URL   — Design Stack media worker to proxy at /worker/* in
 *                        dev; same shell-or-.env resolution.
 */
const spaV2Src = fileURLToPath(
  new URL('../../addons/pro/assets/spa-v2/src', import.meta.url),
);
const repoRoot = fileURLToPath(new URL('../..', import.meta.url));
const envDir = fileURLToPath(new URL('.', import.meta.url));

interface ProxyLike {
  on(
    event: 'proxyRes',
    handler: (proxyRes: IncomingMessage, req: IncomingMessage, res: ServerResponse) => void,
  ): void;
}

/**
 * Headers Node manages itself — passing them through a self-handled response
 * would conflict with its own framing/connection handling.
 */
const HOP_BY_HOP = new Set([
  'connection',
  'keep-alive',
  'proxy-authenticate',
  'proxy-authorization',
  'te',
  'trailers',
  'upgrade',
  'transfer-encoding',
  'content-length',
]);

/**
 * Re-scope the wp-login.php page to the dev origin.
 *
 * WordPress renders the login form with an ABSOLUTE action (site_url) and an
 * absolute redirect_to value. If the browser follows them, the login POST and
 * the post-login redirect land on the WordPress origin — the auth cookies are
 * then set for the WordPress host and the dev origin never sees the session.
 * On localhost this is masked (cookies are scoped by host, not port), but it
 * breaks cookie auth on production hosts. Mirrors rewriteLoginPage in
 * scripts/serve.mjs — keep the two in sync.
 */
function rewriteLoginPage(html: string): string {
  return html
    .replace(
      /(["'])https?:\/\/[^"']*?(\/[^"']*?wp-login\.php(?=[?"'\s]))/gi,
      '$1$2',
    )
    .replace(
      /(\bname=["']redirect_to["']\s+value=["'])https?:\/\/[^"']*?(\/.*?)?(["'])/gi,
      '$1$2$3',
    );
}

/**
 * Keep the browser on the dev origin when WordPress redirects.
 *
 * http-proxy forwards WordPress's 302 Location header as-is (an absolute URL
 * pointing at the target site). Following it would land the browser on the
 * target origin, where wp-login.php sets its auth cookies for the *target*
 * domain — so the proxied requests from the dev origin would never carry
 * them and cookie auth could not work. Rewrite:
 *
 *   1. Location: target-origin URLs become relative (resolve against the dev
 *      origin, i.e. stay inside the proxy).
 *   2. redirect_to=<encoded target origin> is stripped so the post-login
 *      redirect also stays inside the app.
 *   3. Set-Cookie: any Domain= attribute is removed so the cookie is
 *      host-only for the dev origin (WordPress core cookies are host-only by
 *      default; this only normalizes sites that set COOKIE_DOMAIN).
 */
function keepLoginOnDevOrigin(target: string, proxy: ProxyLike): void {
  const targetOrigin = new URL(target).origin;
  const encodedTarget = encodeURIComponent(targetOrigin);

  const stripDomain = (cookie: string): string =>
    cookie.replace(/;\s*domain=[^;]+/gi, '');

  proxy.on('proxyRes', (proxyRes) => {
    // Cast: http-proxy mutates these headers in place, and set-cookie may
    // arrive as a single string at runtime even though the Node types say
    // string[] — handle both.
    const headers = proxyRes.headers as Record<string, string | string[] | undefined>;

    const location = headers['location'];
    if (typeof location === 'string' && location.startsWith(targetOrigin)) {
      headers['location'] = location
        .slice(targetOrigin.length)
        .replace(`redirect_to=${encodedTarget}`, 'redirect_to=');
    }

    const cookies = headers['set-cookie'];
    if (Array.isArray(cookies)) {
      headers['set-cookie'] = cookies.map(stripDomain);
    } else if (typeof cookies === 'string') {
      headers['set-cookie'] = stripDomain(cookies);
    }
  });
}

/**
 * Proxy route for wp-login.php that also re-scopes the page body.
 *
 * selfHandleResponse makes http-proxy emit proxyRes without writing anything;
 * the route writes the response itself after buffering + rewriting the HTML
 * (see rewriteLoginPage), so the login POST and the post-login redirect stay
 * on the dev origin and the session cookie lands on the dev origin.
 */
function loginPageProxy(target: string) {
  return {
    target,
    changeOrigin: true,
    secure: false,
    selfHandleResponse: true,
    configure(proxy: ProxyLike): void {
      keepLoginOnDevOrigin(target, proxy);
      proxy.on('proxyRes', (proxyRes, req, res) => {
        const headers = { ...proxyRes.headers };
        for (const name of Object.keys(headers)) {
          if (HOP_BY_HOP.has(name.toLowerCase())) delete headers[name];
        }
        const status = typeof proxyRes.statusCode === 'number' ? proxyRes.statusCode : 502;
        const contentType = String(headers['content-type'] ?? '').toLowerCase();
        if (!contentType.includes('text/html') || headers['content-encoding']) {
          res.writeHead(status, proxyRes.statusMessage, headers);
          proxyRes.pipe(res);
          return;
        }
        const chunks: Buffer[] = [];
        proxyRes.on('data', (chunk) => chunks.push(chunk));
        proxyRes.on('end', () => {
          res.writeHead(status, proxyRes.statusMessage, headers);
          res.end(rewriteLoginPage(Buffer.concat(chunks).toString('utf8')));
        });
        proxyRes.on('error', () => res.destroy());
      });
    },
  };
}

/** Proxy route that keeps WordPress redirects inside the dev origin. */
function wordpressProxy(target: string, extra: Record<string, unknown> = {}) {
  return {
    target,
    changeOrigin: true,
    secure: false,
    ...extra,
    configure(proxy: ProxyLike): void {
      keepLoginOnDevOrigin(target, proxy);
    },
  };
}

export default defineConfig(({ mode }) => {
  // Vite does not expose .env values via process.env while this config file
  // is evaluated — load them explicitly. Prefix '' = all keys, so the
  // non-VITE_ NVOOS_* variables are included; shell environment variables
  // take precedence over .env files.
  const env = loadEnv(mode, envDir, '');
  const targetSite = env.NVOOS_TARGET_SITE || '';
  const workerTarget = env.NVOOS_WORKER_URL || '';

  return {
    plugins: [react()],
    define: {
      // The spa-v2 sources reference process.env.NODE_ENV (axe dev audit gate).
      'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'development'),
    },
    resolve: {
      alias: {
        // The whole SPA, imported from this app as `@nvoos/pro-spa-v2/...`.
        '@nvoos/pro-spa-v2': spaV2Src,
        // WordPress loads this from window.wp.i18n; the standalone app ships a
        // tiny drop-in instead (same trick as spa-v2's esbuild plugin).
        '@wordpress/i18n': fileURLToPath(new URL('./src/shims/wp-i18n.ts', import.meta.url)),
        // The spa-v2 sources have their own node_modules in the monorepo — and
        // NONE in the standalone repo. Force every importer (shell + SPA + its
        // deps) onto this app's single dependency tree: a second
        // react/jsx-runtime instance would break hooks, and in the standalone
        // mirror the spa-v2 sources have no node_modules of their own to
        // resolve from. Directory aliases so subpaths (react-dom/client,
        // react/jsx-runtime, react-router-dom/*) still work.
        react: fileURLToPath(new URL('./node_modules/react', import.meta.url)),
        'react-dom': fileURLToPath(new URL('./node_modules/react-dom', import.meta.url)),
        'react-router-dom': fileURLToPath(new URL('./node_modules/react-router-dom', import.meta.url)),
        zustand: fileURLToPath(new URL('./node_modules/zustand', import.meta.url)),
        marked: fileURLToPath(new URL('./node_modules/marked', import.meta.url)),
        dompurify: fileURLToPath(new URL('./node_modules/dompurify', import.meta.url)),
        '@ai-sdk/react': fileURLToPath(new URL('./node_modules/@ai-sdk/react', import.meta.url)),
        '@ai-sdk/ui-utils': fileURLToPath(new URL('./node_modules/@ai-sdk/ui-utils', import.meta.url)),
        // Knowledge-graph page deps: the vendored explorer (spa-v2 sources)
        // imports jquery + cytoscape; force them onto this app's single
        // dependency tree like react/zustand above (a second cytoscape
        // instance would break extension registration).
        jquery: fileURLToPath(new URL('./node_modules/jquery', import.meta.url)),
        cytoscape: fileURLToPath(new URL('./node_modules/cytoscape', import.meta.url)),
        'cytoscape-fcose': fileURLToPath(new URL('./node_modules/cytoscape-fcose', import.meta.url)),
      },
    },
    server: {
      port: 5199,
      open: false,
      fs: {
        // The spa-v2 sources live outside this app's root directory.
        allow: [spaV2Src, repoRoot],
      },
      proxy: {
        ...(targetSite
          ? {
              // Cookie-auth mode: everything same-origin through the dev server,
              // including wp-login.php so users can log in from the app. The
              // login page route re-scopes the form to the dev origin so the
              // session cookie lands here (see loginPageProxy).
              '/wp-json': wordpressProxy(targetSite),
              '/wp-login.php': loginPageProxy(targetSite),
              '/wp-admin': wordpressProxy(targetSite),
            }
          : {}),
        ...(workerTarget
          ? {
              // Media worker: /worker/* → worker /* (dev only; the deployed app
              // talks to the worker origin directly with X-Site-Token).
              '/worker': {
                target: workerTarget,
                changeOrigin: true,
                secure: false,
                rewrite: (path) => path.replace(/^\/worker/, ''),
              },
            }
          : {}),
      },
    },
  };
});
