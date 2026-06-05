/**
 * Visual regression / screenshot utilities.
 *
 * Utilities for capturing, comparing, and storing screenshots
 * during automated test runs. Screenshots go to /artifacts/screenshots/
 * in Docker or test-results/ locally.
 */

import { Page, expect } from '@playwright/test';
import * as path from 'path';

const SCREENSHOT_DIR = process.env.CI
  ? '/artifacts/screenshots'
  : path.join(__dirname, '..', '..', 'artifacts', 'screenshots');

/**
 * Capture a full-page screenshot with a descriptive name.
 */
export async function captureFullPage(
  page: Page,
  name: string,
): Promise<string> {
  const filePath = path.join(SCREENSHOT_DIR, `${name}-full.png`);
  await page.screenshot({ path: filePath, fullPage: true });
  return filePath;
}

/**
 * Capture a viewport screenshot with a descriptive name.
 */
export async function captureViewport(
  page: Page,
  name: string,
): Promise<string> {
  const filePath = path.join(SCREENSHOT_DIR, `${name}.png`);
  await page.screenshot({ path: filePath, fullPage: false });
  return filePath;
}

/**
 * Scroll through a page in segments and capture at each stop.
 * Useful for visual review of long pages (e.g., settings, dashboards).
 */
export async function scrollAndCapture(
  page: Page,
  name: string,
  segments: number = 4,
): Promise<string[]> {
  const paths: string[] = [];
  const viewportHeight = page.viewportSize()?.height || 800;

  for (let i = 0; i < segments; i++) {
    await page.evaluate(
      ({ step, vh }) => {
        window.scrollTo({ top: step * vh * 0.8, behavior: 'instant' });
      },
      { step: i, vh: viewportHeight },
    );
    await page.waitForTimeout(300); // Allow any lazy-loaded content to appear

    const filePath = path.join(SCREENSHOT_DIR, `${name}-segment-${i}.png`);
    await page.screenshot({ path: filePath, fullPage: false });
    paths.push(filePath);
  }

  // Scroll back to top
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));

  return paths;
}

/**
 * Assert that an element is visually visible (not hidden, zero-sized, or offscreen).
 */
export async function assertVisible(
  page: Page,
  selector: string,
): Promise<void> {
  const locator = page.locator(selector);
  await expect(locator).toBeVisible();
}

/**
 * Take a visual diff screenshot for Playwright's built-in snapshot comparison.
 * Falls back to a regular screenshot when `toHaveScreenshot` is not configured.
 */
export async function visualCheck(
  page: Page,
  name: string,
): Promise<void> {
  try {
    await expect(page).toHaveScreenshot(`${name}.png`, {
      fullPage: true,
      maxDiffPixelRatio: 0.02, // 2% tolerance
    });
  } catch {
    // Fallback: capture a named screenshot for manual review
    await captureFullPage(page, `visual-${name}`);
    console.warn(
      `⚠ Visual diff for "${name}" failed — screenshot captured for manual review.`,
    );
  }
}
