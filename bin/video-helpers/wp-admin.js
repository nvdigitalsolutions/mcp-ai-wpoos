/**
 * WordPress Admin helper for demo video scripts.
 *
 * CommonJS port of tests/qa/playwright/fixtures/wp-admin.ts.
 * Provides login, admin page navigation, nonce extraction, and
 * test data creation/cleanup utilities.
 */

const { VIDEO_CONFIG } = require('./video-utils');

class WPAdmin {
	/**
	 * @param {import('playwright').Page} page
	 * @param {string} [baseUrl] - Base URL for the WordPress site (default: VIDEO_CONFIG.baseUrl).
	 */
	constructor(page, baseUrl) {
		this.page = page;
		this.baseUrl = (baseUrl || VIDEO_CONFIG.baseUrl).replace(/\/$/, '');
		this.adminUrl = `${this.baseUrl}/wp-admin`;
	}

	/**
	 * Log into the WordPress admin panel.
	 *
	 * @param {string} [username] - Defaults to WP_ADMIN_USER env var or 'admin'.
	 * @param {string} [password] - Defaults to WP_ADMIN_PASS env var or 'password'.
	 */
	async login(
		username = process.env.WP_ADMIN_USER || 'admin',
		password = process.env.WP_ADMIN_PASS || 'password'
	) {
		await this.page.goto(`${this.adminUrl}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });

		// Already logged in?
		const adminBar = await this.page.$('#wpadminbar');
		if (adminBar) {
			return;
		}

		await this.page.fill('#user_login', username);
		await this.page.fill('#user_pass', password);
		await this.page.click('#wp-submit');
		await this.page.waitForSelector('#wpadminbar', { timeout: 15000 });
	}

	/**
	 * Extract the WordPress REST API nonce from the admin page.
	 *
	 * @returns {Promise<string>}
	 */
	async getRestNonce() {
		const nonce = await this.page.evaluate(() => {
			const wpApiSettings = window.wpApiSettings;
			return wpApiSettings ? wpApiSettings.nonce : '';
		});
		if (!nonce) {
			throw new Error(
				'wpApiSettings.nonce not found. Ensure you are logged into wp-admin.'
			);
		}
		return nonce;
	}

	/**
	 * Navigate to a WordPress admin page by its slug.
	 *
	 * @param {string} slug - The admin page slug (e.g., 'wp-mcp-ai-dashboard').
	 * @param {object} [options]
	 * @param {string} [options.tab] - Optional tab query parameter.
	 */
	async goToAdminPage(slug, options = {}) {
		let url = `${this.adminUrl}/admin.php?page=${slug}`;
		if (options.tab) {
			url += `&tab=${options.tab}`;
		}
		await this.page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
	}

	/**
	 * Navigate to the edit list for a custom post type.
	 *
	 * @param {string} postType - The post type slug (e.g., 'mcp_ai_assistant').
	 */
	async goToPostTypeList(postType) {
		await this.page.goto(`${this.adminUrl}/edit.php?post_type=${postType}`, {
			waitUntil: 'domcontentloaded',
			timeout: 30000,
		});
	}

	/**
	 * Navigate to the "Add New" screen for a custom post type.
	 *
	 * @param {string} postType - The post type slug.
	 */
	async goToPostTypeNew(postType) {
		await this.page.goto(`${this.adminUrl}/post-new.php?post_type=${postType}`, {
			waitUntil: 'domcontentloaded',
			timeout: 30000,
		});
	}

	/**
	 * Create a test post via the WordPress REST API.
	 *
	 * @param {string} title
	 * @param {string} [content]
	 * @returns {Promise<number>} Post ID.
	 */
	async createTestPost(title, content = '') {
		const nonce = await this.getRestNonce();
		const response = await this.page.request.post(`${this.baseUrl}/wp-json/wp/v2/posts`, {
			headers: {
				'X-WP-Nonce': nonce,
				'Content-Type': 'application/json',
			},
			data: { title, content, status: 'publish' },
		});
		if (response.status() !== 201) {
			const body = await response.text();
			throw new Error(`Failed to create test post: ${response.status()} ${body}`);
		}
		const body = await response.json();
		return body.id;
	}

	/**
	 * Clean up a test post by ID.
	 *
	 * @param {number} postId
	 */
	async deleteTestPost(postId) {
		const nonce = await this.getRestNonce();
		await this.page.request.delete(
			`${this.baseUrl}/wp-json/wp/v2/posts/${postId}?force=true`,
			{ headers: { 'X-WP-Nonce': nonce } }
		);
	}
}

module.exports = { WPAdmin };
