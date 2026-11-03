/**
 * Sidebar nav contract — the APG Disclosure Navigation behaviour:
 * collapsible section groups driven by aria-expanded/aria-controls buttons,
 * a persisted collapse map, active-item aria-current, and the read-only
 * suffix derived from the server-authored user block.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { Sidebar } from '../Sidebar';

afterEach(() => {
	cleanup();
});

beforeEach(() => {
	window.localStorage.clear();
	delete (window as any).AstroAdminConfig;
});

describe('Sidebar', () => {
	it('renders the four section groups with their items', () => {
		render(<Sidebar activeSlug="products" onSelect={() => {}} />);

		expect(screen.getByRole('button', { name: 'Catalog' })).toBeTruthy();
		expect(screen.getByRole('button', { name: 'Content' })).toBeTruthy();
		expect(screen.getByRole('button', { name: 'Access' })).toBeTruthy();
		expect(screen.getByRole('button', { name: 'System' })).toBeTruthy();

		// Items are nav buttons inside the section lists.
		expect(screen.getByRole('button', { name: 'Products' })).toBeTruthy();
		expect(screen.getByRole('button', { name: 'Pages' })).toBeTruthy();
		expect(screen.getByRole('button', { name: 'Posts' })).toBeTruthy();
		expect(screen.getByRole('button', { name: 'Managers' })).toBeTruthy();
		expect(screen.getByRole('button', { name: 'Design System' })).toBeTruthy();
	});

	it('marks the active item with aria-current="page"', () => {
		render(<Sidebar activeSlug="products" onSelect={() => {}} />);
		expect(
			screen.getByRole('button', { name: 'Products' }).getAttribute('aria-current')
		).toBe('page');
	});

	it('collapses a section on toggle (APG disclosure contract)', () => {
		render(<Sidebar activeSlug="products" onSelect={() => {}} />);

		const toggle = screen.getByRole('button', { name: 'Catalog' });
		expect(toggle.getAttribute('aria-expanded')).toBe('true');

		fireEvent.click(toggle);

		expect(toggle.getAttribute('aria-expanded')).toBe('false');
		const panel = document.getElementById('astro-admin-nav-catalog');
		expect(panel?.hasAttribute('hidden')).toBe(true);
		// Hidden from the accessibility tree once collapsed.
		expect(screen.queryByRole('button', { name: 'Products' })).toBeNull();
	});

	it('persists the collapsed map and restores it on a fresh mount', () => {
		const first = render(<Sidebar activeSlug="products" onSelect={() => {}} />);
		fireEvent.click(screen.getByRole('button', { name: 'Access' }));
		first.unmount();

		render(<Sidebar activeSlug="products" onSelect={() => {}} />);
		expect(
			screen.getByRole('button', { name: 'Access' }).getAttribute('aria-expanded')
		).toBe('false');
		expect(screen.queryByRole('button', { name: 'Managers' })).toBeNull();
	});

	it('suffixes read-only collections from the server-authored user block', () => {
		(window as any).AstroAdminConfig = {
			root: 'https://example.test/wp-json/funiq/v1/',
			user: { readOnlyCollections: ['statuses'] },
		};
		render(<Sidebar activeSlug="products" onSelect={() => {}} />);
		expect(screen.getByRole('button', { name: 'Statuses (read-only)' })).toBeTruthy();
	});
});
