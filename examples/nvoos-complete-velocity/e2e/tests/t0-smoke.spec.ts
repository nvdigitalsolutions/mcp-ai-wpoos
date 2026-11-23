import { test, expect, type Page } from '@playwright/test';

/**
 * T0 console smoke against the Laravel runtime — Proposal 090-B, Phase 1
 * task 1.7, gate G1:
 *
 *   "5 T0 pages + chat render against Laravel; the network log shows zero
 *    WordPress requests."
 *
 * The session is established by `auth.setup.ts`; every test here starts
 * authenticated. Pages are addressed by hash route because that is the
 * deployed router shape (`HashRouter`), and each navigation is a real boot —
 * which is what makes the request-log gate meaningful.
 */

interface T0Page {
  route: string;
  name: string;
  heading: { name: string; level?: number };
}

/** The five T0 surfaces from `084` §4 (T0 = already a spa-v2 route). */
const T0_PAGES: T0Page[] = [
  { route: '/#/', name: 'Dashboard', heading: { name: 'Dashboard', level: 1 } },
  { route: '/#/settings', name: 'Settings', heading: { name: 'Settings' } },
  { route: '/#/diagnostics', name: 'Diagnostics', heading: { name: 'Diagnostics' } },
  { route: '/#/operations', name: 'Operations', heading: { name: 'Operations' } },
  { route: '/#/tools', name: 'Tools', heading: { name: 'Tools' } },
];

/**
 * Any request that would prove the console still talks to WordPress. The
 * Laravel profile has no wp-json / wp-admin / wp-login surfaces, so a hit
 * here is a client still building WordPress URLs.
 */
const WORDPRESS_PATH = /\/(wp-json|wp-admin|wp-login\.php|wp-includes)(\/|$)/;

function isWordPressRequest(url: string): boolean {
  return WORDPRESS_PATH.test(new URL(url).pathname);
}

/**
 * Navigate to a hash route and make the page content readable.
 *
 * The chat dock is open on first boot and covers the content area, so every
 * non-chat route closes it first — otherwise the page heading exists but is
 * behind the dock (and the shell's main element has no layout box). The chat
 * route turns the dock into the page itself, so it is left alone.
 */
async function visit(page: Page, route: string): Promise<void> {
  await page.goto(route);
  await expect(page.locator('#nvoos-console-main-content')).toBeAttached();

  if (!route.endsWith('/chat')) {
    const dockToggle = page.getByRole('button', { name: 'Chat dock', exact: true });
    if ((await dockToggle.getAttribute('aria-pressed')) === 'true') {
      await dockToggle.click();
    }
    await expect(page.locator('#nvoos-console-main-content')).toBeVisible();
  }
}

test.describe('T0 console smoke — Laravel runtime', () => {
  test('boots against the Laravel contract and serves every route it claims', async ({ page }) => {
    const featuresResponse = page.waitForResponse(
      (response) => new URL(response.url()).pathname === '/api/v1/features',
    );

    await visit(page, '/#/');

    const response = await featuresResponse;
    expect(response.ok()).toBe(true);

    const payload = (await response.json()) as {
      success: boolean;
      data: {
        backend: string;
        contract: string;
        endpoints: Record<string, boolean>;
        endpoint_gaps: string[];
      };
    };

    expect(payload.success).toBe(true);
    expect(payload.data.backend).toBe('laravel');
    expect(payload.data.contract).toBe('mcp-ai/v1');

    // `endpoint_gaps` is the subset this runtime is meant to serve and does
    // not — it must stay empty (090-i capability manifest v2).
    expect(payload.data.endpoint_gaps).toEqual([]);

    // The five T0 keys are part of the served set, not just "no gaps".
    for (const key of ['settings', 'diagnostics', 'operations', 'tools', 'chat']) {
      expect(payload.data.endpoints[key], `endpoint ${key} must be served`).toBe(true);
    }
  });

  test('renders the five T0 pages', async ({ page }) => {
    for (const target of T0_PAGES) {
      await test.step(target.name, async () => {
        await visit(page, target.route);
        await expect(page.getByRole('heading', target.heading)).toBeVisible();

        // No page may fall back to the shell's fatal state.
        await expect(
          page.getByRole('heading', { name: 'Unable to start the NV oOS Console' }),
        ).toHaveCount(0);
      });
    }
  });

  test('renders the chat surface with a usable composer', async ({ page }) => {
    await visit(page, '/#/chat');

    await expect(page).toHaveURL(/#\/chat$/);
    await expect(page.getByLabel('Chat conversation')).toBeVisible();

    const composer = page.locator('#nvoos-pro-spa-composer-input');
    await expect(composer).toBeVisible();
    await expect(composer).toHaveAttribute('placeholder', 'Type your message…');
  });

  test('makes zero WordPress requests across every T0 surface', async ({ page }) => {
    const wordpressRequests: string[] = [];
    const laravelRequests: string[] = [];

    page.on('request', (request) => {
      const url = request.url();
      if (isWordPressRequest(url)) {
        wordpressRequests.push(`${request.method()} ${url}`);
      } else {
        const path = new URL(url).pathname;
        if (path.startsWith('/api/')) {
          laravelRequests.push(path);
        }
      }
    });

    // Collect during the initial boot too, not only after it.
    for (const target of T0_PAGES) {
      await visit(page, target.route);
      await expect(page.getByRole('heading', target.heading)).toBeVisible();
    }

    await visit(page, '/#/chat');
    await expect(page.locator('#nvoos-pro-spa-composer-input')).toBeVisible();

    // G1: the Laravel runtime must be the only backend the console talks to.
    expect(
      wordpressRequests,
      `WordPress requests: ${wordpressRequests.join(', ')}`,
    ).toEqual([]);

    // And the sweep must have actually exercised the API, so an empty log
    // cannot pass by accident.
    expect(laravelRequests).toContain('/api/v1/features');
  });

  test('sends the selected estate on console API requests only', async ({ page }) => {
    // 149 §21: `ResolveApiTenant` resolves one estate per request from
    // `X-Tenant-Id` and 409s a multi-estate caller that sends nothing. The
    // identity endpoints are excluded on purpose — the picker validates a
    // stored id against the server's own answer.
    const scopedTenants: string[] = [];
    const identityRequests: string[] = [];

    page.on('request', (request) => {
      const url = new URL(request.url());
      if (!url.pathname.startsWith('/api/v1/')) {
        return;
      }
      const tenant = request.headers()['x-tenant-id'];
      if (url.pathname.startsWith('/api/v1/session/')) {
        if (tenant) {
          identityRequests.push(`${request.method()} ${url.pathname}`);
        }
        return;
      }
      if (tenant) {
        scopedTenants.push(tenant);
      }
    });

    await visit(page, '/#/');
    await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible();

    const stored = await page.evaluate(() =>
      window.localStorage.getItem('nvoos-console.tenant'),
    );
    expect(stored, 'the estate selection must be persisted for the next boot').toBeTruthy();

    expect(scopedTenants.length, 'scoped requests observed').toBeGreaterThan(0);
    expect(new Set(scopedTenants), 'every scoped request uses the stored estate').toEqual(
      new Set([stored]),
    );

    expect(identityRequests, 'identity endpoints never carry a selector').toEqual([]);
  });

  test('shows the T0 nav items the session is allowed to reach', async ({ page }) => {
    await visit(page, '/#/');

    const nav = page.getByRole('navigation', { name: 'Pages' });
    await expect(nav).toBeVisible();

    // Groups start collapsed; a disclosure toggle only hides children, so
    // expand every one before asserting the admin-visible items.
    const toggles = nav.locator('button.nvoos-console-nav__group-toggle');
    for (let index = 0; index < (await toggles.count()); index += 1) {
      const toggle = toggles.nth(index);
      if ((await toggle.getAttribute('aria-expanded')) === 'false') {
        await toggle.click();
      }
    }

    for (const label of ['Chat', 'Tools', 'Operations', 'Diagnostics', 'Settings']) {
      await expect(nav.getByRole('link', { name: label, exact: true })).toBeVisible();
    }
  });

  test('serves the backend profile as a runtime stamp, not a build artifact', async ({
    request,
  }) => {
    // One prebuilt bundle serves both runtimes: the proxy adds a same-origin
    // external script to index.html and answers it from NVOOS_BACKEND, so the
    // client profile can change with an env var instead of a rebuild. External
    // (not inline) keeps a future strict `script-src 'self'` CSP workable.
    const html = await (await request.get('/')).text();
    expect(html).toContain('/app-config.js');

    const config = await request.get('/app-config.js');
    expect(config.status()).toBe(200);
    expect(config.headers()['content-type']).toContain('text/javascript');
    expect(config.headers()['cache-control']).toContain('no-cache');
    // The e2e proxy runs with NVOOS_BACKEND=laravel (playwright.config.ts).
    expect(await config.text()).toContain('window.__NVOOS_BACKEND__="laravel"');
  });
});
