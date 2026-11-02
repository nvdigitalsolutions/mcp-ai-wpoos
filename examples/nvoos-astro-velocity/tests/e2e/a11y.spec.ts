// ---------------------------------------------------------------------------
// a11y.spec.ts — axe-core gate (Proposal 104 D6). Machine-checkable WCAG
// 2.2 assertions per route: accessible names, landmarks, colour contrast.
// Lighthouse a11y ≥ 0.9 remains the CI baseline; axe adds per-rule,
// per-node reporting to the e2e suite.
// ---------------------------------------------------------------------------

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

async function expectNoViolations(page: import('@playwright/test').Page, route: string) {
	await page.goto(route);
	const results = await new AxeBuilder({ page }).analyze();
	expect(
		results.violations,
		`axe violations on ${route}: ${JSON.stringify(results.violations, null, 2)}`
	).toEqual([]);
}

test('axe: /products/ PLP has zero WCAG violations', async ({ page }) => {
	await expectNoViolations(page, '/products/');
});

test('axe: PDP has zero WCAG violations', async ({ page }) => {
	await expectNoViolations(page, '/products/1/aurora-3-seater-sofa/');
});

test('axe: category PLP has zero WCAG violations', async ({ page }) => {
	await expectNoViolations(page, '/categories/1/sofas/');
});
