/**
 * Smoke tests — critical-path E2E verification.
 *
 * These tests verify that the plugin loads, the REST API responds,
 * and basic WordPress integration works. Tagged @smoke for selective
 * CI execution.
 *
 * @group smoke
 */

import { test, expect } from '@playwright/test';
import { WPAdmin } from '../fixtures/wp-admin';
import { listAssistants, listTools } from '../utils/wp-helpers';

test.describe('Smoke Tests', { tag: '@smoke' }, () => {
  test('WordPress site loads', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).toBeVisible();
  });

  test('wp-admin is accessible', async ({ page }) => {
    const response = await page.goto('/wp-admin');
    // Should redirect to login if not authenticated, or show dashboard
    expect(response?.status()).toBe(200);
    await expect(page.locator('#loginform, #wpadminbar')).toBeVisible({
      timeout: 10_000,
    });
  });

  test('REST API base responds', async ({ request }) => {
    const response = await request.get('/wp-json');
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body).toHaveProperty('namespace');
  });

  test('MCP API tools/list requires authentication', async ({ request }) => {
    const response = await request.get('/wp-json/mcp-ai/v1/tools/list');
    // Should be 401 without authentication
    expect(response.status()).toBe(401);
  });

  test('MCP API tools/list responds with valid nonce', async ({ page, request }) => {
    const admin = new WPAdmin(page);
    await admin.login();
    const nonce = await admin.getRestNonce();

    const tools = await listTools(request, nonce);
    expect(Array.isArray(tools)).toBe(true);
    expect(tools.length).toBeGreaterThan(0);

    // Verify tool structure
    const firstTool = tools[0];
    expect(firstTool).toHaveProperty('slug');
    expect(firstTool).toHaveProperty('name');
    expect(firstTool).toHaveProperty('description');
  });

  test('MCP API assistants list responds with valid nonce', async ({ page, request }) => {
    const admin = new WPAdmin(page);
    await admin.login();
    const nonce = await admin.getRestNonce();

    const assistants = await listAssistants(request, nonce);
    expect(Array.isArray(assistants)).toBe(true);
  });
});
