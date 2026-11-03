/**
 * SettingsView consumption contract (Proposals 105/106) — the request()
 * helper is mocked, so this suite pins what the tab asks the bridge for:
 * a parallel mount read of globals/theme + globals/shop, one PATCH per
 * save action with only the editable fields in the body.
 */
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { SettingsView } from '../SettingsView';

const { requestMock } = vi.hoisted(() => ({ requestMock: vi.fn() }));

vi.mock('../../hooks/useCollection', () => ({
	request: (...args: unknown[]) => requestMock(...args),
}));

afterEach(() => {
	cleanup();
});

const THEME_DOC = {
	policy: 'auto',
	contrast: 'auto',
	defaultTheme: 'dark',
	updatedAt: '2026-11-03T09:00:00Z',
	$extensions: {},
};

const SHOP_DOC = {
	checkoutUrl: 'https://nvoos.pro/checkout/',
	cartUrl: 'https://nvoos.pro/cart/',
	returnUrl: 'https://nvoos.pro/checkout/order-received/',
	currency: 'USD',
	currencySymbol: '$',
	updatedAt: '2026-11-03T09:00:00Z',
};

beforeEach(() => {
	requestMock.mockReset();
	requestMock.mockImplementation(async (endpoint: string) => {
		if (endpoint === 'globals/theme') {
			return THEME_DOC;
		}
		if (endpoint === 'globals/shop') {
			return SHOP_DOC;
		}
		throw new Error(`Unexpected endpoint ${endpoint}`);
	});
});

describe('SettingsView mount', () => {
	it('reads globals/theme and globals/shop in parallel and renders both sections', async () => {
		render(<SettingsView />);

		expect(await screen.findByRole('heading', { name: 'Settings' })).toBeInTheDocument();

		await waitFor(() => {
			expect(screen.getByRole('heading', { name: 'Theme policy' })).toBeInTheDocument();
			expect(screen.getByRole('heading', { name: 'Shop checkout' })).toBeInTheDocument();
		});

		expect(requestMock).toHaveBeenCalledWith('globals/theme');
		expect(requestMock).toHaveBeenCalledWith('globals/shop');
	});

	it('shows the brand slug input only when the brand preset is selected', async () => {
		render(<SettingsView />);

		await screen.findByRole('heading', { name: 'Theme policy' });
		expect(screen.queryByLabelText('Brand slug')).not.toBeInTheDocument();

		fireEvent.click(screen.getByRole('radio', { name: /Brand theme/ }));
		expect(screen.getByLabelText('Brand slug')).toBeInTheDocument();
	});
});

describe('SettingsView writes', () => {
	it('PATCHes the theme policy with the selected fields', async () => {
		requestMock.mockImplementation(async (endpoint: string, options?: Record<string, unknown>) => {
			if (endpoint === 'globals/theme' && options?.method === 'PATCH') {
				const data = options.data as { policy?: string; contrast?: string; defaultTheme?: string };
				return {
					...THEME_DOC,
					policy: data.policy,
					contrast: data.contrast,
					defaultTheme: data.defaultTheme,
				};
			}
			if (endpoint === 'globals/shop') {
				return SHOP_DOC;
			}
			return THEME_DOC;
		});

		render(<SettingsView />);
		await screen.findByRole('heading', { name: 'Theme policy' });

		fireEvent.click(screen.getByRole('radio', { name: /Force dark/ }));
		fireEvent.click(screen.getByRole('button', { name: 'Save theme policy' }));

		await waitFor(() => {
			expect(requestMock).toHaveBeenCalledWith('globals/theme', {
				method: 'PATCH',
				data: { policy: 'dark', contrast: 'auto', defaultTheme: 'dark' },
			});
		});
		expect(await screen.findByText('Theme policy saved.')).toBeInTheDocument();
	});

	it('PATCHes the shop globals with the edited fields', async () => {
		requestMock.mockImplementation(async (endpoint: string, options?: Record<string, unknown>) => {
			if (endpoint === 'globals/theme') {
				return THEME_DOC;
			}
			if (endpoint === 'globals/shop' && options?.method === 'PATCH') {
				const data = options.data as { currency?: string; currencySymbol?: string };
				return {
					...SHOP_DOC,
					currency: data.currency,
					currencySymbol: data.currencySymbol,
				};
			}
			return SHOP_DOC;
		});

		render(<SettingsView />);
		await screen.findByRole('heading', { name: 'Shop checkout' });

		fireEvent.change(screen.getByLabelText('Currency (ISO code)'), {
			target: { value: 'eur' },
		});
		fireEvent.change(screen.getByLabelText('Currency symbol'), {
			target: { value: '€' },
		});
		fireEvent.click(screen.getByRole('button', { name: 'Save shop settings' }));

		await waitFor(() => {
			expect(requestMock).toHaveBeenCalledWith('globals/shop', {
				method: 'PATCH',
				data: {
					checkoutUrl: SHOP_DOC.checkoutUrl,
					cartUrl: SHOP_DOC.cartUrl,
					returnUrl: SHOP_DOC.returnUrl,
					currency: 'EUR',
					currencySymbol: '€',
				},
			});
		});
		expect(await screen.findByText('Shop settings saved.')).toBeInTheDocument();
	});
});
