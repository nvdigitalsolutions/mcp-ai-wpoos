// ---------------------------------------------------------------------------
// /og/{slug}.png — build-time social cards for posts (Proposal 104 §4.8 /
// Q5, the Cactus pattern). Scaffolded only when the ogImages surface is on
// (this `_og` directory is inert otherwise — Astro skips underscore-prefixed
// dirs under src/pages/, and the scaffold prunes it in static mode).
//
// Cards are branded from the --forge-* tokens baked into the marker span
// below at scaffold time. A render failure warns and falls back to a plain
// card — an OG card must never fail the build. Fonts resolve through
// createRequire against @fontsource/inter (the velocity route pattern).
// ---------------------------------------------------------------------------
import { createRequire } from 'node:module';
import { readFile } from 'node:fs/promises';

import { Resvg } from '@resvg/resvg-js';
import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import satori from 'satori';

import manifest from '../../../nvoos.json';

// Build-time cards in every render mode (server builds need the explicit
// flag — Astro 7 ignores getStaticPaths on dynamic endpoints otherwise).
export const prerender = true;

// FORGE:og-tokens
const OG_ACCENT = '#0f6b4f';
const OG_INK = '#1a1a1a';
const OG_SURFACE = '#ffffff';
// FORGE:og-tokens-end

const require = createRequire(import.meta.url);

const WIDTH = 1200;
const HEIGHT = 630;

async function loadFont(weight: number): Promise<Buffer> {
	const file = require.resolve(`@fontsource/inter/files/inter-latin-${weight}-normal.woff`);
	return readFile(file);
}

interface PostData {
	title: string;
	date?: string;
}

export async function getStaticPaths() {
	const posts = await getCollection('posts');
	// Astro 7 spread params are flat slash-joined strings — one PNG per post.
	return posts.map((post) => ({
		params: { slug: post.data.slug },
		props: { post: { title: post.data.title, date: post.data.date } as PostData },
	}));
}

/** Plain-object element tree — satori accepts it without a JSX runtime. */
function card(post: PostData): Record<string, unknown> {
	const siteName = manifest.name ?? 'Forge';
	const when = post.date ? post.date.slice(0, 10) : '';
	return {
		type: 'div',
		props: {
			style: {
				display: 'flex',
				flexDirection: 'column',
				justifyContent: 'space-between',
				width: '100%',
				height: '100%',
				padding: '72px',
				backgroundColor: OG_SURFACE,
				color: OG_INK,
			},
			children: [
				{
					type: 'div',
					props: {
						style: { display: 'flex', alignItems: 'center', gap: '16px' },
						children: [
							{
								type: 'div',
								props: {
									style: {
										display: 'flex',
										width: '18px',
										height: '18px',
										borderRadius: '9px',
										backgroundColor: OG_ACCENT,
									},
								},
							},
							{
								type: 'div',
								props: {
									style: { fontSize: '28px', fontWeight: 700 },
									children: siteName,
								},
							},
						],
					},
				},
				{
					type: 'div',
					props: {
						style: {
							display: 'flex',
							fontSize: '64px',
							lineHeight: 1.15,
							fontWeight: 700,
						},
						children: post.title,
					},
				},
				{
					type: 'div',
					props: {
						style: { display: 'flex', alignItems: 'center', gap: '24px', fontSize: '26px' },
						children: [
							{ type: 'div', props: { style: { color: OG_ACCENT }, children: when } },
							{ type: 'div', props: { children: siteName } },
						],
					},
				},
			],
		},
	};
}

/** The fallback card — never a build failure, always a valid PNG. */
function fallbackCard(): Record<string, unknown> {
	return {
		type: 'div',
		props: {
			style: {
				display: 'flex',
				alignItems: 'center',
				justifyContent: 'center',
				width: '100%',
				height: '100%',
				backgroundColor: OG_SURFACE,
				color: OG_INK,
				fontSize: '40px',
			},
			children: manifest.name ?? 'Forge',
		},
	};
}

async function render(element: Record<string, unknown>, fonts: Array<{ name: string; data: Buffer; weight: number; style: string }>): Promise<Buffer> {
	const svg = await satori(element as never, {
		width: WIDTH,
		height: HEIGHT,
		fonts: fonts as never,
	});
	return new Resvg(svg, { fitTo: { mode: 'width', value: WIDTH } }).render().asPng();
}

export const GET: APIRoute = async ({ props }) => {
	const post = (props as { post: PostData }).post;
	const headers = { 'content-type': 'image/png', 'cache-control': 'public, max-age=3600' };
	try {
		const fonts = [
			{ name: 'Inter', data: await loadFont(400), weight: 400, style: 'normal' },
			{ name: 'Inter', data: await loadFont(700), weight: 700, style: 'normal' },
		];
		return new Response(new Uint8Array(await render(card(post), fonts)), { headers });
	} catch (error) {
		console.warn(`[forge-og] card for "${post.title}" fell back: ${error instanceof Error ? error.message : error}`);
		try {
			// Fall back without the variable fonts — still a branded PNG.
			return new Response(new Uint8Array(await render(fallbackCard(), [])), { headers });
		} catch {
			return new Response('OG card unavailable', { status: 404, headers: { 'content-type': 'text/plain' } });
		}
	}
};
