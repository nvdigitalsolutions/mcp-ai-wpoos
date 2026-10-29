// ---------------------------------------------------------------------------
// Optimized images — Proposal 092 Phase 1.7.
//
// Industry pattern (Astro Image Service API): with an adapter configured,
// Astro serves a runtime `/_image` endpoint that optimizes on demand
// (sharp is bundled into the standalone node build). Emitting `/_image`
// URLs keeps `astro build` fully hermetic — prerendered PDPs never fetch
// remote bytes at build time — while every visitor still gets
// WebP-formatted, correctly-sized images at request time.
//
// The endpoint requires the `href` to be an ABSOLUTE same-origin URL (a
// relative href is treated as a local dist/ file and 404s — verified
// against the built endpoint source). The caller therefore passes the
// render origin: Astro.site for prerendered pages, Astro.url for SSR.
//
// Docs: https://docs.astro.build/en/reference/image-service-reference/
// ---------------------------------------------------------------------------

export type ImageFormat = 'webp' | 'avif' | 'png' | 'jpg';

export interface OptimizedImage {
	/** Optimized src (smallest width, WebP by default). */
	src: string;
	/** Responsive srcset: one /_image URL per width. */
	srcset: string;
	sizes: string;
	width: number;
	height: number;
}

function endpointHref(
	origin: string,
	src: string,
	width: number,
	format: ImageFormat,
	quality?: number
): string {
	const params = new URLSearchParams();
	params.set('href', new URL(src, origin).toString());
	params.set('w', String(width));
	params.set('f', format);
	if (quality) {
		params.set('q', String(quality));
	}
	// The standalone node adapter mounts the endpoint at /_image/ (trailing
	// slash) — verified against the built route pattern ^\/_image\/$.
	return `/_image/?${params.toString()}`;
}

/**
 * Build a responsive, runtime-optimized image descriptor.
 *
 * `origin` is the render origin (Astro.site for prerendered pages,
 * Astro.url.origin for SSR) — the endpoint fetches the href server-side
 * through the same-origin BFF /media proxy.
 */
export function responsiveImage(
	origin: string,
	src: string,
	options: {
		width: number;
		height: number;
		widths?: number[];
		sizes?: string;
		format?: ImageFormat;
		quality?: number;
	}
): OptimizedImage {
	const {
		width,
		height,
		widths = [width],
		sizes = `${width}px`,
		format = 'webp',
		quality,
	} = options;

	const unique = [...new Set(widths)].sort((a, b) => a - b);
	const srcset = unique
		.map((w) => `${endpointHref(origin, src, w, format, quality)} ${w}w`)
		.join(', ');

	return {
		src: endpointHref(origin, src, unique[0], format, quality),
		srcset,
		sizes,
		width,
		height,
	};
}

/** Fixed-size optimized image (thumbnails, banner). */
export function fixedImage(
	origin: string,
	src: string,
	width: number,
	height: number,
	format: ImageFormat = 'webp'
): OptimizedImage {
	return responsiveImage(origin, src, { width, height, widths: [width], format });
}

/** The render origin: configured site first, request origin fallback. */
export function renderOrigin(url: URL | undefined, site: URL | undefined): string {
	return site ? site.origin : url ? url.origin : 'http://localhost:4321';
}
