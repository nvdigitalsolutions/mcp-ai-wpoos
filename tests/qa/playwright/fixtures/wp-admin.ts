/**
 * WordPress Admin fixture.
 *
 * Provides a reusable `wpAdmin` object that handles login, nonce extraction,
 * and common admin operations. Use this in all admin-facing test specs.
 *
 * Usage:
 *   const admin = new WPAdmin(page);
 *   await admin.login('admin', 'password');
 *   const nonce = await admin.getRestNonce();
 */

import { Page, expect } from '@playwright/test';

export class WPAdmin {
  readonly page: Page;

  constructor(page: Page) {
    this.page = page;
  }

  /**
   * Log into the WordPress admin panel.
   */
  async login(
    username: string = process.env.WP_ADMIN_USER || 'admin',
    password: string = process.env.WP_ADMIN_PASSWORD || 'password',
  ): Promise<void> {
    await this.page.goto('/wp-admin');
    await this.page.fill('#user_login', username);
    await this.page.fill('#user_pass', password);
    await this.page.click('#wp-submit');
    await expect(this.page.locator('#wpadminbar')).toBeVisible({
      timeout: 15_000,
    });
  }

  /**
   * Extract the WordPress REST API nonce from the admin page.
   */
  async getRestNonce(): Promise<string> {
    const nonce: string = await this.page.evaluate(() => {
      const wpApiSettings = (window as any).wpApiSettings;
      return wpApiSettings?.nonce || '';
    });
    if (!nonce) {
      throw new Error(
        'wpApiSettings.nonce not found. Ensure you are logged into wp-admin.',
      );
    }
    return nonce;
  }

  /**
   * Navigate to a WordPress admin page by its slug.
   */
  async goToAdminPage(slug: string): Promise<void> {
    await this.page.goto(`/wp-admin/admin.php?page=${slug}`);
  }

  /**
   * Create a test post via the WordPress REST API.
   */
  async createTestPost(title: string, content: string = ''): Promise<number> {
    const nonce = await this.getRestNonce();
    const response = await this.page.request.post(
      '/wp-json/wp/v2/posts',
      {
        headers: {
          'X-WP-Nonce': nonce,
          'Content-Type': 'application/json',
        },
        data: {
          title,
          content,
          status: 'publish',
        },
      },
    );
    expect(response.status()).toBe(201);
    const body = await response.json();
    return body.id;
  }

  /**
   * Clean up a test post by ID.
   */
  async deleteTestPost(postId: number): Promise<void> {
    const nonce = await this.getRestNonce();
    await this.page.request.delete(`/wp-json/wp/v2/posts/${postId}?force=true`, {
      headers: { 'X-WP-Nonce': nonce },
    });
  }

  /**
   * Clean up a test user by ID.
   */
  async deleteTestUser(userId: number): Promise<void> {
    const nonce = await this.getRestNonce();
    await this.page.request.delete(`/wp-json/wp/v2/users/${userId}?force=true&reassign=1`, {
      headers: { 'X-WP-Nonce': nonce },
    });
  }
}
