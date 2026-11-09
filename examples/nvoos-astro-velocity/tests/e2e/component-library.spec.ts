// component-library.spec.ts — the Q6 evaluation gates: the adapted Incluud
// components (accordion/tabs/breadcrumbs) and the Zag islands (dialog/
// combobox) must pass axe-core and the keyboard contracts, or the adoption
// answer is "no".
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test('gallery page is axe-clean (both approaches)', async ({ page }) => {
	await page.goto('/components/');
	await expect(page.getByRole('heading', { level: 1 })).toHaveText('Component library evaluation');
	const results = await new AxeBuilder({ page }).analyze();
	expect(
		results.violations,
		`axe violations: ${JSON.stringify(results.violations, null, 2)}`
	).toEqual([]);
});

test('accordion opens via keyboard on the native summary', async ({ page }) => {
	await page.goto('/components/');

	const first = page.locator('details.ui-accordion-wrapper').first();
	const summary = first.locator('summary');
	await expect(summary).toBeVisible();

	// Native details behavior: Enter on the summary toggles open.
	await summary.focus();
	await summary.press('Enter');
	await expect(first).toHaveAttribute('open', '');
	await expect(first.locator('.ui-accordion-content')).toBeVisible();

	await summary.press('Enter');
	await expect(first).not.toHaveAttribute('open', '');
});

test('tabs follow the WAI-ARIA keyboard contract (arrows + Home/End)', async ({ page }) => {
	await page.goto('/components/');

	const tablist = page.getByRole('tablist');
	const tabs = tablist.getByRole('tab');
	await expect(tabs).toHaveCount(3);
	await expect(tabs.first()).toHaveAttribute('aria-selected', 'true');

	// ArrowRight moves selection and focus to the next tab.
	await tabs.first().focus();
	await tabs.first().press('ArrowRight');
	await expect(tabs.nth(1)).toBeFocused();
	await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');
	await expect(page.locator('#panel-content')).toHaveAttribute('aria-hidden', 'false');

	// End jumps to the last tab.
	await tabs.nth(1).press('End');
	await expect(tabs.nth(2)).toHaveAttribute('aria-selected', 'true');

	// Home jumps back to the first.
	await tabs.nth(2).press('Home');
	await expect(tabs.first()).toHaveAttribute('aria-selected', 'true');
});

test('Zag dialog traps focus and closes on Escape', async ({ page }) => {
	await page.goto('/components/');

	const trigger = page.getByRole('button', { name: 'Open dialog' });
	// Wait for island hydration — under full-suite load an interaction can
	// land before React attaches its listeners (the 107-session race).
	await expect(trigger).toHaveAttribute('data-hydrated', '');
	await trigger.click();

	const dialog = page.getByRole('dialog', { name: 'Zag dialog demo' });
	await expect(dialog).toBeVisible();
	await expect(dialog).toHaveAttribute('aria-modal', 'true');

	// Focus starts inside the dialog (on the close button, the first
	// focusable element in our markup).
	await expect(page.getByRole('button', { name: 'Close' })).toBeFocused();

	// Escape closes and returns focus to the trigger.
	await page.keyboard.press('Escape');
	await expect(dialog).toBeHidden();
	await expect(trigger).toBeFocused();
});

test('Zag combobox filters and selects via keyboard', async ({ page }) => {
	await page.goto('/components/');

	const input = page.getByRole('combobox', { name: 'Wood' });
	// Wait for island hydration first — see the dialog test.
	await expect(page.locator('.ui-combobox')).toHaveAttribute('data-hydrated', '');
	// Styling regression gate (2026-11-03): the island DOM never receives
	// Astro's scope attribute, so page-scoped selectors silently miss — the
	// deployed list showed default bullets with no hover highlight. The
	// page's island rules must stay :global(); toHaveCSS proves they apply.
	await expect(page.locator('.ui-combobox-list')).toHaveCSS('list-style-type', 'none');
	// Closed-state gate: the Zag content part carries the `hidden` attribute
	// — a missing content element left the listbox permanently visible and
	// unpositioned on the deployed page (2026-11-07 regression).
	await expect(page.locator('.ui-combobox-content')).toBeHidden();
	await input.fill('oa');

	// Opening via typing must reveal the popup again.
	await expect(page.locator('.ui-combobox-content')).toBeVisible();

	// Wait for the filtered collection to commit before the keyboard
	// sequence (the re-filter is a React state round-trip).
	const option = page.getByRole('option', { name: 'Oak' });
	await expect(option).toBeVisible();
	await input.press('ArrowDown');
	// The highlight assertion makes the selection deterministic under
	// parallel suite load — Enter selects whatever is highlighted.
	await expect(option).toHaveAttribute('data-highlighted', '');
	await input.press('Enter');
	await expect(input).toHaveValue('Oak');
});

test('content-block gallery is axe-clean', async ({ page }) => {
	await page.goto('/components/blocks/');
	await expect(page.getByRole('heading', { level: 1 })).toHaveText('Content blocks');
	const results = await new AxeBuilder({ page }).analyze();
	expect(
		results.violations,
		`axe violations: ${JSON.stringify(results.violations, null, 2)}`
	).toEqual([]);
});

test('every gallery band declares an in-vocabulary data-section', async ({ page }) => {
	await page.goto('/components/blocks/');

	// The vocabulary is the contract — a new value must be added to
	// src/lib/content/section-kinds.ts (and its unit test) before it can
	// appear here. Kept in sync deliberately, not derived: this literal is
	// the second opinion. The live-data kinds are listed but not rendered.
	const KINDS = [
		'hero',
		'image',
		'testimonial',
		'stats',
		'comparison',
		'steps',
		'team',
		'tabs',
		'faq',
	];

	const found = await page.locator('[data-section]').evaluateAll((nodes) =>
		nodes.map((node) => node.getAttribute('data-section') ?? '')
	);
	expect(found.length).toBeGreaterThanOrEqual(KINDS.length);
	for (const kind of found) {
		expect(KINDS, `unexpected data-section="${kind}"`).toContain(kind);
	}

	// Every kind appears at least once.
	for (const kind of KINDS) {
		expect(found, `no band rendered data-section="${kind}"`).toContain(kind);
	}
});

test('the previously untagged blocks now emit data-section', async ({ page }) => {
	await page.goto('/components/blocks/');

	// faq and tabs used to render only through the ui/ wrappers with no kind.
	await expect(page.locator('[data-section="faq"]')).toHaveCount(2);
	await expect(page.locator('[data-section="tabs"]')).toHaveCount(1);

	// The tabs behaviour still works from inside the tagged wrapper.
	const tabs = page.getByRole('tab');
	await expect(tabs).toHaveCount(2);
	await expect(tabs.first()).toHaveAttribute('aria-selected', 'true');
	await tabs.first().focus();
	await tabs.first().press('ArrowRight');
	await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');
});

// Proposal 104 D12 — the CSP is hashed per response, so inline scripts must be
// page-INVARIANT or a ClientRouter navigation is blocked by the SOURCE
// document's policy (the hash list belongs to the document, not the target).
// This is the coverage gap that shipped a broken tabs pattern: every other
// spec does a full goto(), which never exercises the client-side path.
test('tabs work after in-app navigation (CSP is page-invariant)', async ({ page }) => {
	const cspErrors: string[] = [];
	page.on('console', (msg) => {
		if (/Content Security Policy/i.test(msg.text())) cspErrors.push(msg.text());
	});

	await page.goto('/');
	await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

	// Navigate in-app — ClientRouter intercepts same-origin anchors.
	await page.evaluate(() => {
		const anchor = document.createElement('a');
		anchor.href = '/components/blocks/';
		anchor.textContent = 'gallery';
		document.body.appendChild(anchor);
		anchor.click();
	});
	await page.waitForURL('**/components/blocks/');
	await expect(page.getByRole('heading', { level: 1 })).toHaveText('Content blocks');

	const tabs = page.getByRole('tab');
	await expect(tabs).toHaveCount(2);
	await tabs.nth(1).click();
	await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');
	await expect(page.locator('[id$="-panel-1"]')).toHaveAttribute('aria-hidden', 'false');

	expect(cspErrors, `CSP violations on navigation:\n${cspErrors.join('\n')}`).toEqual([]);
});
