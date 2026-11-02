/**
 * Settings tab — theme policy (Proposal 105 §1) and shop checkout globals
 * (Proposal 106 §1.3).
 *
 * Reads and writes the bridge's `globals/theme` and `globals/shop`
 * documents through the same `request()` helper the collection CRUD uses
 * (nonce retry + cookie auth come along for free). The storefront reads
 * both documents on its own — this surface is the CMS-side editor.
 */
import { useEffect, useState } from '@wordpress/element';
import { request } from '../hooks/useCollection';
import type { ShopGlobals, ThemePolicy } from '../types';

/** Radio presets for the policy ladder's plain values. */
const PRESETS = ['auto', 'light', 'dark', 'brand'] as const;
type Preset = (typeof PRESETS)[number];

const BUTTON_STYLE: Record<string, string | number> = {
	padding: '6px 12px',
	border: '1px solid #007cba',
	borderRadius: 3,
	background: '#007cba',
	color: '#fff',
	cursor: 'pointer',
	fontSize: 13,
};

const DISABLED_STYLE: Record<string, string | number> = {
	...BUTTON_STYLE,
	opacity: 0.6,
	cursor: 'default',
};

const FIELD_LABEL: Record<string, string | number> = {
	display: 'block',
	marginBottom: 4,
	fontWeight: 600,
};

const FIELD_GROUP: Record<string, string | number> = {
	marginBottom: 16,
};

/** Split `brand:<slug>` policies into their parts for the editor. */
function splitPolicy(policy: string): { preset: Preset; brandSlug: string } {
	if (policy.startsWith('brand:')) {
		return { preset: 'brand', brandSlug: policy.slice(6) };
	}
	if (policy === 'light' || policy === 'dark') {
		return { preset: policy, brandSlug: '' };
	}
	return { preset: 'auto', brandSlug: '' };
}

/** Inline pass/fail status line shared by both save actions. */
function StatusLine({ message, error }: { message: string | null; error: string | null }) {
	if (error) {
		return <p style={{ color: 'red', fontSize: 12, marginTop: 8 }}>{error}</p>;
	}
	if (message) {
		return <p style={{ color: '#1e7e34', fontSize: 12, marginTop: 8 }}>{message}</p>;
	}
	return null;
}

export function SettingsView() {
	const [loading, setLoading] = useState(true);
	const [loadError, setLoadError] = useState<string | null>(null);

	// Theme policy editor state.
	const [policy, setPolicy] = useState<ThemePolicy | null>(null);
	const [preset, setPreset] = useState<Preset>('auto');
	const [brandSlug, setBrandSlug] = useState('');
	const [contrast, setContrast] = useState<'auto' | 'standard' | 'high'>('auto');
	const [defaultTheme, setDefaultTheme] = useState<'light' | 'dark'>('dark');
	const [savingTheme, setSavingTheme] = useState(false);
	const [themeMessage, setThemeMessage] = useState<string | null>(null);
	const [themeError, setThemeError] = useState<string | null>(null);

	// Shop editor state.
	const [shop, setShop] = useState<ShopGlobals | null>(null);
	const [checkoutUrl, setCheckoutUrl] = useState('');
	const [cartUrl, setCartUrl] = useState('');
	const [currency, setCurrency] = useState('USD');
	const [currencySymbol, setCurrencySymbol] = useState('$');
	const [savingShop, setSavingShop] = useState(false);
	const [shopMessage, setShopMessage] = useState<string | null>(null);
	const [shopError, setShopError] = useState<string | null>(null);

	useEffect(() => {
		let cancelled = false;
		setLoading(true);
		setLoadError(null);

		const load = async () => {
			const [themeDoc, shopDoc] = await Promise.all([
				request<ThemePolicy>('globals/theme'),
				request<ShopGlobals>('globals/shop'),
			]);
			if (cancelled) {
				return;
			}
			setPolicy(themeDoc);
			setPreset(splitPolicy(themeDoc.policy).preset);
			setBrandSlug(splitPolicy(themeDoc.policy).brandSlug);
			setContrast(themeDoc.contrast);
			setDefaultTheme(themeDoc.defaultTheme);

			setShop(shopDoc);
			setCheckoutUrl(shopDoc.checkoutUrl);
			setCartUrl(shopDoc.cartUrl);
			setCurrency(shopDoc.currency);
			setCurrencySymbol(shopDoc.currencySymbol);
		};

		load()
			.catch((error) => {
				if (!cancelled) {
					setLoadError((error as Error).message);
				}
			})
			.finally(() => {
				if (!cancelled) {
					setLoading(false);
				}
			});
		return () => {
			cancelled = true;
		};
	}, []);

	const saveTheme = async () => {
		setSavingTheme(true);
		setThemeError(null);
		setThemeMessage(null);
		try {
			const nextPolicy =
				preset === 'brand' ? `brand:${brandSlug.trim()}` : preset;
			const updated = await request<ThemePolicy>('globals/theme', {
				method: 'PATCH',
				data: {
					policy: nextPolicy,
					contrast,
					defaultTheme,
				},
			});
			setPolicy(updated);
			setThemeMessage('Theme policy saved.');
		} catch (error) {
			setThemeError((error as Error).message);
		} finally {
			setSavingTheme(false);
		}
	};

	const saveShop = async () => {
		setSavingShop(true);
		setShopError(null);
		setShopMessage(null);
		try {
			const updated = await request<ShopGlobals>('globals/shop', {
				method: 'PATCH',
				data: {
					checkoutUrl,
					cartUrl,
					currency,
					currencySymbol,
				},
			});
			setShop(updated);
			setShopMessage('Shop settings saved.');
		} catch (error) {
			setShopError((error as Error).message);
		} finally {
			setSavingShop(false);
		}
	};

	if (loading) {
		return <p>Loading settings…</p>;
	}

	return (
		<div>
			<h2 style={{ marginTop: 0 }}>Settings</h2>
			{loadError && <p style={{ color: 'red' }}>{loadError}</p>}

			<section aria-label="Theme policy" style={{ marginBottom: 32 }}>
				<h3 style={{ marginBottom: 8 }}>Theme policy</h3>
				<p style={{ marginTop: 0, fontSize: 13, opacity: 0.8 }}>
					Controls how the storefront resolves light/dark. Last saved by the store:{' '}
					{policy?.updatedAt ?? 'unknown'}
				</p>

				<div style={FIELD_GROUP}>
					<span style={FIELD_LABEL}>Policy preset</span>
					{PRESETS.map((value) => (
						<label
							key={value}
							style={{ display: 'block', marginBottom: 4, fontSize: 13 }}
						>
							<input
								type="radio"
								name="theme-policy-preset"
								value={value}
								checked={preset === value}
								onChange={() => setPreset(value)}
							/>
							{value === 'auto' && ' Auto (system preference)'}
							{value === 'light' && ' Force light'}
							{value === 'dark' && ' Force dark'}
							{value === 'brand' && ' Brand theme'}
						</label>
					))}
				</div>

				{preset === 'brand' && (
					<div style={FIELD_GROUP}>
						<label style={FIELD_LABEL} htmlFor="theme-policy-brand-slug">
							Brand slug
						</label>
						<input
							id="theme-policy-brand-slug"
							type="text"
							className="regular-text"
							value={brandSlug}
							onChange={(event) =>
								setBrandSlug((event.target as HTMLInputElement).value)
							}
							placeholder="aurora-home"
							style={{ maxWidth: 260 }}
						/>
						<p style={{ marginTop: 4, fontSize: 12, opacity: 0.8 }}>
							Names come from the storefront&apos;s ADS registry — an unknown slug
							falls back to auto.
						</p>
					</div>
				)}

				<div style={FIELD_GROUP}>
					<label style={FIELD_LABEL} htmlFor="theme-policy-contrast">
						Contrast
					</label>
					<select
						id="theme-policy-contrast"
						value={contrast}
						onChange={(event) =>
							setContrast(
								(event.target as HTMLSelectElement).value as
									| 'auto'
									| 'standard'
									| 'high'
							)
						}
					>
						<option value="auto">auto</option>
						<option value="standard">standard</option>
						<option value="high">high</option>
					</select>
				</div>

				<div style={FIELD_GROUP}>
					<label style={FIELD_LABEL} htmlFor="theme-policy-default-theme">
						Default theme
					</label>
					<select
						id="theme-policy-default-theme"
						value={defaultTheme}
						onChange={(event) =>
							setDefaultTheme(
								(event.target as HTMLSelectElement).value as 'light' | 'dark'
							)
						}
					>
						<option value="light">light</option>
						<option value="dark">dark</option>
					</select>
				</div>

				<div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
					<button
						type="button"
						onClick={saveTheme}
						disabled={savingTheme}
						style={savingTheme ? DISABLED_STYLE : BUTTON_STYLE}
					>
						{savingTheme ? 'Saving…' : 'Save theme policy'}
					</button>
					<StatusLine message={themeMessage} error={themeError} />
				</div>
			</section>

			<section aria-label="Shop checkout">
				<h3 style={{ marginBottom: 8 }}>Shop checkout</h3>
				<p style={{ marginTop: 0, fontSize: 13, opacity: 0.8 }}>
					Checkout handoff targets for the storefront cart. Last saved by the
					store: {shop?.updatedAt ?? 'unknown'}
				</p>

				<div style={FIELD_GROUP}>
					<label style={FIELD_LABEL} htmlFor="shop-checkout-url">
						Checkout URL
					</label>
					<input
						id="shop-checkout-url"
						type="url"
						className="regular-text"
						value={checkoutUrl}
						onChange={(event) =>
							setCheckoutUrl((event.target as HTMLInputElement).value)
						}
						style={{ maxWidth: 420 }}
					/>
				</div>

				<div style={FIELD_GROUP}>
					<label style={FIELD_LABEL} htmlFor="shop-cart-url">
						Cart URL
					</label>
					<input
						id="shop-cart-url"
						type="url"
						className="regular-text"
						value={cartUrl}
						onChange={(event) => setCartUrl((event.target as HTMLInputElement).value)}
						style={{ maxWidth: 420 }}
					/>
				</div>

				<div style={FIELD_GROUP}>
					<label style={FIELD_LABEL} htmlFor="shop-currency">
						Currency (ISO code)
					</label>
					<input
						id="shop-currency"
						type="text"
						className="regular-text"
						value={currency}
						onChange={(event) =>
							setCurrency((event.target as HTMLInputElement).value.toUpperCase())
						}
						style={{ maxWidth: 90 }}
					/>
				</div>

				<div style={FIELD_GROUP}>
					<label style={FIELD_LABEL} htmlFor="shop-currency-symbol">
						Currency symbol
					</label>
					<input
						id="shop-currency-symbol"
						type="text"
						className="regular-text"
						value={currencySymbol}
						onChange={(event) =>
							setCurrencySymbol((event.target as HTMLInputElement).value)
						}
						style={{ maxWidth: 90 }}
					/>
					<p style={{ marginTop: 4, fontSize: 12, opacity: 0.8 }}>
						Display hint only — no conversion happens in the CMS.
					</p>
				</div>

				<div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
					<button
						type="button"
						onClick={saveShop}
						disabled={savingShop}
						style={savingShop ? DISABLED_STYLE : BUTTON_STYLE}
					>
						{savingShop ? 'Saving…' : 'Save shop settings'}
					</button>
					<StatusLine message={shopMessage} error={shopError} />
				</div>
			</section>
		</div>
	);
}
