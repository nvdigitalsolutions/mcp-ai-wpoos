// ---------------------------------------------------------------------------
// OG card builder — Proposal 104 §4.8 (owner Q5, now approved).
//
// Pure element construction for the per-product Open Graph card; the route
// renders it with satori + resvg. Branded from the RESOLVED ADS semantic
// layer (light mode values), so runtime brand themes are reflected in shared
// previews automatically.
// ---------------------------------------------------------------------------

import React from 'react';
import type { Product } from '../commerce/types';
import { semantic as semanticTokens } from '../tokens.generated';
import { formatPrice } from '../commerce/money';

export interface OgCardTokens {
	ink: string;
	muted: string;
	surface: string;
	accent: string;
	'accent-ink': string;
	line: string;
}

/** The semantic layer, coerced for the card (generated values are strings). */
export function ogCardTokens(): OgCardTokens {
	const tokens = semanticTokens as unknown as Record<string, string>;
	return {
		ink: tokens.ink ?? '#1a1a1a',
		muted: tokens.muted ?? '#6b6b6b',
		surface: tokens.surface ?? '#ffffff',
		accent: tokens.accent ?? '#0f6b4f',
		'accent-ink': tokens['accent-ink'] ?? '#ffffff',
		line: tokens.line ?? '#e4e4e2',
	};
}

/** The React element satori renders (unit-tested for product fields). */
export function ogCardElement(product: Product, tokens: OgCardTokens): React.ReactElement {
	const price = formatPrice(product.price);
	const subtitle = [product.brand?.name, product.category?.name]
		.filter(Boolean)
		.join(' · ');

	return React.createElement(
		'div',
		{
			style: {
				display: 'flex',
				flexDirection: 'column',
				justifyContent: 'space-between',
				width: '100%',
				height: '100%',
				background: tokens.surface,
				padding: '64px',
				fontFamily: 'Inter',
				border: `1px solid ${tokens.line}`,
			},
		},
		React.createElement(
			'div',
			{
				style: {
					display: 'flex',
					flexDirection: 'column',
					flexGrow: 1,
					justifyContent: 'flex-end',
				},
			},
			React.createElement(
				'div',
				{ style: { display: 'flex', alignItems: 'center', gap: '16px' } },
				React.createElement(
					'div',
					{
						style: {
							display: 'flex',
							background: tokens.accent,
							color: tokens['accent-ink'],
							fontSize: '24px',
							fontWeight: 700,
							borderRadius: '8px',
							padding: '8px 20px',
						},
					},
					'NV Store'
				),
				subtitle
					? React.createElement(
						'div',
						{ style: { display: 'flex', color: tokens.muted, fontSize: '24px' } },
						subtitle
					)
					: null
			),
			React.createElement(
				'div',
				{ style: { display: 'flex', color: tokens.ink, fontSize: '64px', fontWeight: 700, marginTop: '24px' } },
				product.name
			),
			React.createElement(
				'div',
				{ style: { display: 'flex', color: tokens.accent, fontSize: '40px', fontWeight: 700, marginTop: '12px' } },
				price
			)
		)
	);
}
