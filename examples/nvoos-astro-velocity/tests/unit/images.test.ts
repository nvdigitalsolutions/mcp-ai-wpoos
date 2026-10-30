import { describe, expect, it } from 'vitest';
import { fixedImage, renderOrigin, responsiveImage } from '../../src/lib/images';

const ORIGIN = 'http://localhost:4321';

describe('responsiveImage', () => {
	it('emits the runtime /_image endpoint URL with an absolute href', () => {
		const image = responsiveImage(ORIGIN, '/media/2026/10/x.jpg', {
			width: 400,
			height: 500,
			widths: [400, 800],
		});
		expect(image.src).toContain('/_image/?href=');
		expect(image.src).toContain('http%3A%2F%2Flocalhost%3A4321%2Fmedia%2F2026%2F10%2Fx.jpg');
		expect(image.src).toContain('w=400');
		expect(image.src).toContain('f=webp');
	});

	it('builds a sorted, deduplicated srcset', () => {
		const image = responsiveImage(ORIGIN, '/media/x.jpg', {
			width: 400,
			height: 500,
			widths: [800, 400, 400],
		});
		const parts = image.srcset.split(', ');
		expect(parts).toHaveLength(2);
		expect(parts[0]).toContain('400w');
		expect(parts[1]).toContain('800w');
	});

	it('keeps intrinsic dimensions for CLS-safe boxes', () => {
		const image = fixedImage(ORIGIN, '/media/x.jpg', 1200, 300);
		expect(image.width).toBe(1200);
		expect(image.height).toBe(300);
	});

	it('supports avif + quality overrides', () => {
		const image = responsiveImage(ORIGIN, '/media/x.jpg', {
			width: 400,
			height: 500,
			format: 'avif',
			quality: 75,
		});
		expect(image.src).toContain('f=avif');
		expect(image.src).toContain('q=75');
	});
});

describe('renderOrigin', () => {
	it('prefers the configured site over the request URL', () => {
		const url = new URL('http://localhost:4321/x');
		const site = new URL('https://store.nvoos.cloud');
		expect(renderOrigin(url, site)).toBe('https://store.nvoos.cloud');
	});

	it('falls back to the request origin without a site', () => {
		const url = new URL('http://localhost:4321/x');
		expect(renderOrigin(url, undefined)).toBe('http://localhost:4321');
	});
});
