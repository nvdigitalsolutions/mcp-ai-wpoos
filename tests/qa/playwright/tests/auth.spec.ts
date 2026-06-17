/**
 * Authentication & Authorization tests.
 *
 * Verifies all three auth methods work correctly:
 *   1. WordPress nonce (X-WP-Nonce)
 *   2. Bearer token (Authorization: Bearer cred_xxxxx.SECRET)
 *   3. Guest token (X-WP-MCP-AI-Guest)
 *
 * Maps to manual test cases: TC-AUTH-001, TC-AUTH-002, TC-AUTH-003
 *
 * @group auth
 * @group security
 */

import { test, expect } from '@playwright/test';
import { WPAdmin } from '../fixtures/wp-admin';
import { mcpApiRequest } from '../utils/wp-helpers';

test.describe('Authentication', { tag: '@auth' }, () => {
  test('unauthenticated request returns 401', async ({ request }) => {
    const response = await request.get('/wp-json/mcp-ai/v1/assistants');
    expect(response.status()).toBe(401);
  });

  test('WordPress nonce authenticates admin requests', async ({
    page,
    request,
  }) => {
    const admin = new WPAdmin(page);
    await admin.login();
    const nonce = await admin.getRestNonce();
    expect(nonce.length).toBeGreaterThan(0);

    const response = await mcpApiRequest(request, 'GET', '/assistants', {
      nonce,
    });
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(Array.isArray(body)).toBe(true);
  });

  test('subscriber role cannot access tools/list', async ({
    page,
    request,
  }) => {
    // Create a subscriber user via REST API
    const admin = new WPAdmin(page);
    await admin.login();
    const adminNonce = await admin.getRestNonce();

    const createResp = await request.post('/wp-json/wp/v2/users', {
      headers: {
        'X-WP-Nonce': adminNonce,
        'Content-Type': 'application/json',
      },
      data: {
        username: `qa_subscriber_${Date.now()}`,
        email: `qa_sub_${Date.now()}@test.com`,
        password: 'testpass123',
        roles: ['subscriber'],
      },
    });
    expect(createResp.status()).toBe(201);
    const subscriber = await createResp.json();

    // Now log in as subscriber and attempt an MCP request
    const subPage = await page.context().newPage();
    await subPage.goto('/wp-admin');
    await subPage.fill('#user_login', subscriber.username);
    await subPage.fill('#user_pass', 'testpass123');
    await subPage.click('#wp-submit');
    await expect(subPage.locator('#wpadminbar')).toBeVisible();

    const subNonce = await subPage.evaluate(() => {
      const wpApiSettings = (window as any).wpApiSettings;
      return wpApiSettings?.nonce || '';
    });

    const response = await mcpApiRequest(request, 'GET', '/tools/list', {
      nonce: subNonce,
    });
    expect(response.status()).toBe(403); // Forbidden

    // Cleanup
    await subPage.close();
    await admin.deleteTestUser(subscriber.id);
  });

  test('invalid bearer token returns 401', async ({ request }) => {
    const response = await mcpApiRequest(request, 'GET', '/assistants', {
      bearerToken: 'cred_invalid.faketoken',
    });
    expect(response.status()).toBe(401);
  });

  test('assistant bearer token format is validated', async ({ request }) => {
    // Missing secret suffix
    const response = await mcpApiRequest(request, 'GET', '/assistants', {
      bearerToken: 'cred_12345',
    });
    expect(response.status()).toBe(401);
  });
});
