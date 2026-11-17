// ---------------------------------------------------------------------------
// model-3d.spec.ts — the 3D product block's contracts.
//
// Four things are gated here, in the order they matter to a shopper:
//   1. the POSTER is the page (no-JS, reduced-data, and the first paint);
//   2. the viewer is a CUSTOM ELEMENT — no framework island, no hydration;
//   3. a missing runtime degrades to the poster instead of an empty box;
//   4. the model payload stays inside the byte budget.
//
// Runs SERIAL on the content-write fixture + BFF pair (8125/4323 — see
// playwright.config.ts) because it creates and deletes a post, which would
// race the shared listing counts of the parallel specs.
//
// Scene-level metrics (draw calls / triangles / VRAM) are deliberately NOT
// asserted here: CI runs Chromium with --disable-gpu, so a WebGL count would
// measure SwiftShader, not the device. Those numbers are a human devtool
// concern (renderer.info while authoring), not a pipeline gate.
// ---------------------------------------------------------------------------

import { expect, test } from '@playwright/test';

const FIXTURE = 'http://localhost:8125/wp-json/nvoos-commerce/v1';
const MANAGER_COOKIE = 'nvoos_fixture_manager=1';

/** A product model has to paint on a mid-range phone — 2 MiB is the ceiling. */
const MAX_MODEL_BYTES = 2 * 1024 * 1024;

const MODEL_BLOCK = {
	_type: 'model3d',
	src: '/models/fixtures/demo.gltf',
	poster: '/models/fixtures/demo-poster.svg',
	alt: 'Aurora sofa, three-quarter view',
	caption: 'Rotate to inspect the frame.',
};

/** Create + publish a post carrying the model block; returns its route parts. */
async function createModelPost(
	request: import('@playwright/test').APIRequestContext
): Promise<{ id: number; slug: string }> {
	const created = await request.post(`${FIXTURE}/content/posts`, {
		headers: { cookie: MANAGER_COOKIE },
		data: { title: 'Model-3D spec', content: '<p>three dimensions</p>', status: 'draft' },
	});
	expect(created.status()).toBe(200);
	const { id, slug } = (await created.json()) as { id: number; slug: string };

	const published = await request.patch(`${FIXTURE}/content/posts/${id}`, {
		headers: { cookie: MANAGER_COOKIE },
		data: { blocks: [MODEL_BLOCK], status: 'publish' },
	});
	expect(published.status()).toBe(200);

	return { id, slug };
}

async function deletePost(request: import('@playwright/test').APIRequestContext, id: number): Promise<void> {
	await request.delete(`${FIXTURE}/content/posts/${id}`, {
		headers: { cookie: MANAGER_COOKIE },
	});
}

test.describe('3D product block', () => {
	test('ships a poster-first custom element with no framework island', async ({ page }) => {
		const post = await createModelPost(page.request);
		try {
			await page.goto(`/blog/${post.id}/${post.slug}/`);

			const figure = page.locator('figure.block-model3d');
			await expect(figure).toHaveCount(1);

			// 1. The poster is real, described, and lazy — it is the fallback.
			const poster = figure.locator('.block-model3d__poster');
			await expect(poster).toBeVisible();
			await expect(poster).toHaveAttribute('alt', MODEL_BLOCK.alt);
			await expect(poster).toHaveAttribute('loading', 'lazy');
			expect(await poster.evaluate((el) => (el as HTMLImageElement).naturalWidth)).toBeGreaterThan(0);

			// 2. The viewer carries the contract without a hydration wrapper.
			const viewer = figure.locator('model-viewer');
			await expect(viewer).toHaveCount(1);
			await expect(viewer).toHaveAttribute('src', MODEL_BLOCK.src);
			await expect(viewer).toHaveAttribute('poster', MODEL_BLOCK.poster);
			await expect(viewer).toHaveAttribute('alt', MODEL_BLOCK.alt);
			// pan-y keeps vertical page scrolling usable on touch devices.
			await expect(viewer).toHaveAttribute('touch-action', 'pan-y');
			expect(await figure.evaluate((el) => el.closest('astro-island') === null)).toBe(true);
			// AR is opt-in — a model that is not AR-ready must not advertise it.
			expect(await viewer.evaluate((el) => el.hasAttribute('ar'))).toBe(false);

			// The caption is content, not decoration.
			await expect(figure.locator('figcaption')).toHaveText(MODEL_BLOCK.caption);
		} finally {
			await deletePost(page.request, post.id);
		}
	});

	test('falls back to the poster when the runtime never loads', async ({ page }) => {
		// Simulate a blocked/absent vendor bundle — the common failure mode
		// behind a strict CSP or a missed asset copy.
		await page.route('**/vendor/model-viewer.min.js', (route) => route.abort());

		const post = await createModelPost(page.request);
		try {
			await page.goto(`/blog/${post.id}/${post.slug}/`);

			const figure = page.locator('figure.block-model3d');
			await expect(figure).toHaveCount(1);
			await expect(figure.locator('.block-model3d__poster')).toBeVisible();
			// Never defined → CSS keeps it hidden, so no empty box is painted.
			await expect(figure.locator('model-viewer')).toBeHidden();
		} finally {
			await deletePost(page.request, post.id);
		}
	});

	test.describe('with prefers-reduced-motion: reduce', () => {
		test.use({ reducedMotion: 'reduce' });

		test('does not start the turntable', async ({ page }) => {
			const post = await createModelPost(page.request);
			try {
				await page.goto(`/blog/${post.id}/${post.slug}/`);

				const viewer = page.locator('figure.block-model3d model-viewer');
				await expect(viewer).toHaveCount(1);
				expect(await viewer.evaluate((el) => el.hasAttribute('auto-rotate'))).toBe(false);
			} finally {
				await deletePost(page.request, post.id);
			}
		});
	});

	test('keeps the model payload inside the byte budget', async ({ page }) => {
		const response = await page.request.get(MODEL_BLOCK.src);
		expect(response.status()).toBe(200);

		const bytes = (await response.body()).byteLength;
		expect(bytes, `${MODEL_BLOCK.src} must stay under ${MAX_MODEL_BYTES} bytes`).toBeLessThanOrEqual(
			MAX_MODEL_BYTES
		);
	});
});
