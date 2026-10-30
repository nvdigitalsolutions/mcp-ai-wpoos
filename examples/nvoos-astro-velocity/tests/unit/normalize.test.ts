import { describe, expect, it } from 'vitest';
import { normalizeMediaUrl, sanitizeDescription, toIsoDate } from '../../src/lib/commerce/normalize';

describe('normalizeMediaUrl', () => {
	it('rewrites the backend origin to the same-origin /media path', () => {
		expect(
			normalizeMediaUrl('https://backend.example/wp-content/uploads/2026/10/x.jpg')
		).toBe('/media/2026/10/x.jpg');
	});

	it('keeps query strings', () => {
		expect(
			normalizeMediaUrl('https://backend.example/wp-content/uploads/x.jpg?size=large')
		).toBe('/media/x.jpg?size=large');
	});

	it('handles non-upload paths by keeping the full path', () => {
		expect(normalizeMediaUrl('https://backend.example/other/a.png')).toBe(
			'/media/other/a.png'
		);
	});

	it('returns empty string for empty input', () => {
		expect(normalizeMediaUrl('')).toBe('');
	});

	it('passes through non-URL values defensively', () => {
		expect(normalizeMediaUrl('not-a-url')).toBe('not-a-url');
	});
});

describe('sanitizeDescription', () => {
	it('keeps allowed tags and strips scripts', () => {
		const input = '<p>Hello</p><script>alert(1)</script><img src="x" onerror="alert(2)">';
		const output = sanitizeDescription(input);
		expect(output).toContain('<p>Hello</p>');
		expect(output).not.toContain('script');
		expect(output).not.toContain('onerror');
	});

	it('strips disallowed tags but keeps text', () => {
		expect(sanitizeDescription('<h1>Title</h1><div>Body</div>')).toBe('TitleBody');
	});
});

describe('toIsoDate', () => {
	it('converts contract dates to ISO', () => {
		expect(toIsoDate('2026-10-20 09:30:00')).not.toBeNull();
	});

	it('returns null for invalid dates (sitemap safety)', () => {
		expect(toIsoDate('not-a-date')).toBeNull();
		expect(toIsoDate('')).toBeNull();
	});
});
