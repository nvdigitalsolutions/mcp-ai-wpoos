// ---------------------------------------------------------------------------
// PromoCodeField island — clean-room React component (D6), client:visible.
//
// Owner decision Q5: promo-code validation included in v1. The field calls
// the commerce contract's idempotent validation endpoint through the
// same-origin /api/* proxy (cookies/CORS never involved).
// ---------------------------------------------------------------------------

import { useState } from 'react';

const API_BASE = import.meta.env.PUBLIC_API_BASE || '/api';

interface ValidationResult {
	code: string;
	discount: number;
	expiresAt: string;
}

export default function PromoCodeField() {
	const [code, setCode] = useState('');
	const [result, setResult] = useState<ValidationResult | null>(null);
	const [error, setError] = useState<string | null>(null);
	const [busy, setBusy] = useState(false);

	const validate = async (): Promise<void> => {
		const trimmed = code.trim();
		if (!trimmed) {
			return;
		}
		setBusy(true);
		setError(null);
		setResult(null);
		try {
			const res = await fetch(
				`${API_BASE}/promocodes/validate?code=${encodeURIComponent(trimmed)}`,
				{ headers: { accept: 'application/json' } }
			);
			if (res.ok) {
				setResult((await res.json()) as ValidationResult);
			} else {
				setError(
					res.status === 404
						? 'Promo code not found.'
						: 'This promo code cannot be used.'
				);
			}
		} catch {
			setError('Could not reach the store. Try again.');
		} finally {
			setBusy(false);
		}
	};

	return (
		<div className="promo-field">
			<label htmlFor="promo-code">Promo code</label>
			<div className="promo-field__row">
				<input
					id="promo-code"
					type="text"
					value={code}
					onChange={(event) => setCode(event.target.value)}
					placeholder="e.g. SUMMER10"
				/>
				<button type="button" className="btn btn--ghost" onClick={validate} disabled={busy}>
					{busy ? 'Checking…' : 'Apply'}
				</button>
			</div>
			{result && (
				<p className="promo-field__ok" role="status">
					{result.code} applied — {result.discount}% off
					{result.expiresAt ? ` until ${result.expiresAt}` : ''}.
				</p>
			)}
			{error && (
				<p className="promo-field__err" role="alert">
					{error}
				</p>
			)}
		</div>
	);
}
