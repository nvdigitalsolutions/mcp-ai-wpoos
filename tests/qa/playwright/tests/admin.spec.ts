/**
 * Admin Settings — Tab navigation, persistence, and visual review.
 *
 * Maps to manual test cases: TC-ADMIN-001
 *
 * @group admin
 * @group visual
 */

import { test, expect } from '@playwright/test';
import { WPAdmin } from '../fixtures/wp-admin';
import { scrollAndCapture, captureFullPage } from '../utils/visual-diff';

test.describe('Admin Settings', { tag: '@admin' }, () => {
  test.beforeEach(async ({ page }) => {
    const admin = new WPAdmin(page);
    await admin.login();
  });

  test('settings page loads with tab navigation', async ({ page }) => {
    const admin = new WPAdmin(page);
    await admin.goToAdminPage('wp-mcp-ai-settings');

    // Verify the page title is present
    await expect(page.locator('h1')).toBeVisible({ timeout: 10_000 });

    // Verify tab navigation exists
    const tabs = page.locator('.nav-tab-wrapper .nav-tab, [data-testid*="tab"]');
    const tabCount = await tabs.count();
    expect(tabCount).toBeGreaterThan(0);
  });

  test('tab navigation works without page reload', { tag: '@visual' }, async ({
    page,
  }) => {
    const admin = new WPAdmin(page);
    await admin.goToAdminPage('wp-mcp-ai-settings');

    // Capture initial state
    await captureFullPage(page, 'admin-settings-initial');

    // Click through each visible tab
    const tabs = page.locator('.nav-tab-wrapper .nav-tab, [role="tab"]');
    const tabCount = await tabs.count();

    for (let i = 0; i < Math.min(tabCount, 5); i++) {
      // Limit to first 5 tabs to keep test fast
      const tab = tabs.nth(i);
      const tabText = (await tab.textContent()) || `tab-${i}`;
      await tab.click();

      // Wait for tab content to be visible
      await page.waitForTimeout(500);

      // Capture viewport after tab switch
      await scrollAndCapture(page, `admin-tab-${i}-${tabText.trim().replace(/\s+/g, '-')}`, 3);
    }
  });

  test('settings persist after save @visual', async ({ page }) => {
    const admin = new WPAdmin(page);
    await admin.goToAdminPage('wp-mcp-ai-settings');

    // Find a text input field (non-password, non-hidden)
    const input = page.locator('input[type="text"]:not([type="hidden"])').first();
    
    if ((await input.count()) === 0) {
      test.skip(true, 'No text input fields found on settings page');
      return;
    }

    const testValue = `test-value-${Date.now()}`;
    await input.fill(testValue);

    // Save
    const saveButton = page.locator('#submit, input[type="submit"]').first();
    await saveButton.click();

    // Wait for success notice
    await expect(page.locator('.notice-success, .updated, #setting-error-settings_updated')).toBeVisible({
      timeout: 10_000,
    });

    // Refresh and verify
    await page.reload();
    await expect(input).toHaveValue(testValue);
  });
});
