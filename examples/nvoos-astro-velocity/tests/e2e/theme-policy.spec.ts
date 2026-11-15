// theme-policy.spec.ts — Proposal 105 P2 e2e gates.
//
// Runs in the ISOLATED serial 'theme-policy' project (own fixture + BFF pair,
// NVOOS_THEME_POLICY_TTL=0) because the spec PATCHes the policy doc and must
// never race the default project's theme assertions.
//
// Covers: forced light/dark/brand zero-FOUC on SSR pages, toggles disabled
// with aria-disabled, auto honours the stored pref, forced ignores storage,
// forced contrast, unknown brand fail-open, PATCH validation, axe.
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const BASE = 'http://localhost:4322';

async function patchPolicy(page: Page, patch: Record<string, string>) {
	const res = await page.request.patch('/api/globals/theme', { data: patch });
	return res;
}

test.beforeEach(async ({ context }) => {
	// The fixture's manager seam (mirrors /auth/me): PATCH requires the
	// nvoos_fixture_manager cookie.
	await context.addCookies([{ name: 'nvoos_fixture_manager', value: '1', url: BASE }]);
});

test.afterAll(async ({ request }) => {
	// Leave the isolated fixture on the default doc for any re-run.
	await request.patch(`${BASE}/api/globals/theme`, {
		headers: { cookie: 'nvoos_fixture_manager=1' },
		data: { policy: 'auto', contrast: 'auto', defaultTheme: 'dark' },
	});
});

test('PATCH validates policy values with typed 400s (105 §1)', async ({ page }) => {
	const bad = await patchPolicy(page, { policy: 'sepia' });
	expect(bad.status()).toBe(400);
	expect((await bad.json()) as { code?: string }).toMatchObject({
		code: 'astro_theme_policy_invalid_policy',
	});
	const unknown = await patchPolicy(page, { unknownField: 'x' });
	expect(unknown.status()).toBe(400);
});

test('PATCH without the manager cookie is a 403', async ({ browser }) => {
	// A cookie-less browser context — the fixture's manager seam fails closed.
	const context = await browser.newContext({ baseURL: BASE });
	try {
		const res = await context.request.patch('/api/globals/theme', {
			data: { policy: 'dark' },
		});
		expect(res.status()).toBe(403);
	} finally {
		await context.close();
	}
});

test('forced dark applies before paint on the SSR home page (zero-FOUC)', async ({ page }) => {
	const patched = await patchPolicy(page, { policy: 'dark' });
	expect(patched.status()).toBe(200);

	await page.goto('/');
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
	await expect
		.poll(() => page.locator('html').evaluate((el) => el.style.colorScheme))
		.toBe('dark');

	// The inline payload + boot ladder are in the initial HTML.
	const payload = await page
		.locator('script[data-theme-policy]')
		.textContent();
	expect(JSON.parse(payload ?? 'null')).toMatchObject({ doc: { policy: 'dark' } });
	const boot = await page.evaluate(() =>
		Array.from(document.querySelectorAll('script')).some((script) =>
			(script.textContent ?? '').includes('data-theme-policy')
		)
	);
	expect(boot).toBe(true);

	// Toggles disabled with aria labels; label shows the forced name.
	const toggle = page.locator('[data-theme-toggle]');
	await expect(toggle).toBeDisabled();
	await expect(toggle).toHaveAttribute('aria-disabled', 'true');
	await expect(page.locator('[data-theme-label]')).toHaveText('dark');
	await expect(page.locator('[data-theme-forced-note]')).toBeVisible();
});

test('forced light renders without data-theme and a light color-scheme', async ({ page }) => {
	await patchPolicy(page, { policy: 'light' });

	await page.goto('/tokens/?case=forced-light');
	await expect(page.locator('html')).not.toHaveAttribute('data-theme');
	await expect
		.poll(() => page.locator('html').evaluate((el) => el.style.colorScheme))
		.toBe('light');
	await expect(page.locator('[data-theme-toggle]')).toBeDisabled();
	await expect(page.locator('[data-theme-label]')).toHaveText('light');
});

test('forced brand theme applies on an SSR commerce page', async ({ page }) => {
	await patchPolicy(page, { policy: 'brand:example-brand' });

	// Unique query param: the PDP route cache is URL-keyed, so a fresh
	// policy needs a fresh cache key within this long-lived process.
	await page.goto('/products/1/aurora-3-seater-sofa/?case=forced-brand');
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'example-brand');
	await expect(page.locator('[data-theme-toggle]')).toBeDisabled();
	await expect(page.locator('[data-theme-label]')).toHaveText('example-brand');
});

test('forced dark ignores a stored light preference (forced wins over storage)', async ({ page }) => {
	await patchPolicy(page, { policy: 'dark' });
	await page.addInitScript(() => {
		localStorage.setItem('nvoos-theme', 'light');
	});

	await page.goto('/tokens/?case=forced-ignores-storage');
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});

test('auto policy honours the stored preference and keeps toggles enabled', async ({ page }) => {
	await patchPolicy(page, { policy: 'auto', contrast: 'auto', defaultTheme: 'dark' });
	await page.addInitScript(() => {
		localStorage.setItem('nvoos-theme', 'dark');
	});

	await page.goto('/tokens/?case=auto-stored');
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
	await expect(page.locator('[data-theme-toggle]')).toBeEnabled();
	await expect(page.locator('[data-theme-toggle]')).not.toHaveAttribute('aria-disabled');
});

test('forced contrast disables the contrast toggle and applies the axis', async ({ page }) => {
	await patchPolicy(page, { contrast: 'high' });

	await page.goto('/tokens/?case=forced-contrast');
	await expect(page.locator('html')).toHaveAttribute('data-contrast', 'high');
	await expect(page.locator('[data-contrast-toggle]')).toBeDisabled();
	await expect(page.locator('[data-contrast-toggle]')).toHaveAttribute('aria-disabled', 'true');
	await expect(page.locator('[data-contrast-label]')).toHaveText('High contrast');
	await expect(page.locator('[data-contrast-forced-note]')).toBeVisible();
	// The theme axis stays free.
	await expect(page.locator('[data-theme-toggle]')).toBeEnabled();
});

test('forced standard contrast clears a stored high axis', async ({ page }) => {
	await patchPolicy(page, { contrast: 'standard' });
	await page.addInitScript(() => {
		localStorage.setItem('nvoos-contrast', 'high');
	});

	await page.goto('/tokens/?case=forced-standard');
	await expect(page.locator('html')).not.toHaveAttribute('data-contrast');
	await expect(page.locator('[data-contrast-label]')).toHaveText('Contrast');
});

test('unknown brand slug fails open to the auto ladder', async ({ page }) => {
	await patchPolicy(page, { policy: 'brand:ghost-brand' });

	await page.goto('/tokens/?case=unknown-brand');
	// Auto ladder: no storage + media light → no data-theme; toggle live.
	await expect(page.locator('html')).not.toHaveAttribute('data-theme');
	await expect(page.locator('[data-theme-toggle]')).toBeEnabled();
	await expect(page.locator('[data-theme-label]')).not.toHaveText('ghost-brand');
	await expect(page.locator('[data-theme-forced-note]')).toBeHidden();
});

test('axe: a forced-policy page has zero WCAG violations', async ({ page }) => {
	await patchPolicy(page, { policy: 'dark' });

	// /tokens/ carries an h1 (the home page does not) and the policy payload
	// applies there like every BaseLayout page.
	await page.goto('/tokens/?case=axe-forced');
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
	const results = await new AxeBuilder({ page }).analyze();
	expect(
		results.violations,
		`axe violations: ${JSON.stringify(results.violations, null, 2)}`
	).toEqual([]);
});
