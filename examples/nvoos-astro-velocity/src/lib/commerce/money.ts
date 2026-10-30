// ---------------------------------------------------------------------------
// Money formatting — Proposal 092 §3.4 / Q7 (currency: USD default, env-
// overridable per deployment until the contract grows a currency field).
// ---------------------------------------------------------------------------

const DEFAULT_CURRENCY = 'USD';

export function resolveCurrency(): string {
	return import.meta.env.NVOOS_CURRENCY || DEFAULT_CURRENCY;
}

let formatterCache: Intl.NumberFormat | null = null;

function formatter(): Intl.NumberFormat {
	if (!formatterCache) {
		formatterCache = new Intl.NumberFormat('en-US', {
			style: 'currency',
			currency: resolveCurrency(),
		});
	}
	return formatterCache;
}

/** Format a price with the deployment currency. */
export function formatPrice(price: number): string {
	return formatter().format(price);
}
