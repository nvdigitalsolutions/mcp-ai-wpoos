/**
 * Pro SPA v2 — Usage badges (token count + cost).
 *
 * Mirrors chat-spa's UsageBadges with pro text domain and BEM prefix.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { type JSX } from 'react';

export interface UsageData {
	promptTokens?: number;
	completionTokens?: number;
	totalTokens?: number;
	costUsd?: number;
	isEstimated?: boolean;
	model?: string;
	provider?: string;
}

export interface UsageBadgesProps {
	usage: UsageData | null | undefined;
}

function formatTokens( n: number | undefined ): string {
	if ( n === undefined || n === null || Number.isNaN( n ) ) return '';
	if ( n >= 1_000_000 ) return ( n / 1_000_000 ).toFixed( 1 ) + 'M';
	if ( n >= 1_000 ) return ( n / 1_000 ).toFixed( 1 ) + 'k';
	return String( n );
}

function formatCost( usd: number | undefined ): string {
	if ( usd === undefined || usd === null || Number.isNaN( usd ) ) return '';
	if ( usd === 0 ) return '$0.00';
	if ( usd < 0.01 ) return '<$0.01';
	return '$' + usd.toFixed( 2 );
}

function buildTokenTooltip( usage: UsageData ): string {
	const parts: string[] = [];
	if ( typeof usage.promptTokens === 'number' ) {
		parts.push( sprintf( __( 'Prompt: %s tokens', 'nvoos-pro-spa' ), formatTokens( usage.promptTokens ) ) );
	}
	if ( typeof usage.completionTokens === 'number' ) {
		parts.push( sprintf( __( 'Completion: %s tokens', 'nvoos-pro-spa' ), formatTokens( usage.completionTokens ) ) );
	}
	if ( typeof usage.totalTokens === 'number' ) {
		parts.push( sprintf( __( 'Total: %s tokens', 'nvoos-pro-spa' ), formatTokens( usage.totalTokens ) ) );
	}
	return parts.join( '\n' );
}

export function UsageBadges( { usage }: UsageBadgesProps ): JSX.Element | null {
	if ( ! usage ) return null;

	const hasTokens = ( typeof usage.totalTokens === 'number' && ! Number.isNaN( usage.totalTokens ) ) || ( typeof usage.promptTokens === 'number' && ! Number.isNaN( usage.promptTokens ) );
	const hasCost = typeof usage.costUsd === 'number' && ! Number.isNaN( usage.costUsd );
	const hasModel = typeof usage.model === 'string' && usage.model.length > 0;
	if ( ! hasTokens && ! hasCost && ! hasModel ) return null;

	const tokenLabel = usage.isEstimated
		? '~' + formatTokens( usage.totalTokens ?? usage.completionTokens )
		: formatTokens( usage.totalTokens ?? usage.completionTokens );

	return (
		<div className="nvoos-pro-spa-usage-badges">
			{ hasTokens && tokenLabel && (
				<span className="nvoos-pro-spa-usage-badge nvoos-pro-spa-usage-badge--tokens" title={ buildTokenTooltip( usage ) }>
					<span className="nvoos-pro-spa-usage-badge__label">{ __( 'Tokens', 'nvoos-pro-spa' ) }</span>
					<span className="nvoos-pro-spa-usage-badge__value">{ tokenLabel }</span>
				</span>
			) }
			{ hasCost && (
				<span className="nvoos-pro-spa-usage-badge nvoos-pro-spa-usage-badge--cost" title={ __( 'Cost', 'nvoos-pro-spa' ) }>
					<span className="nvoos-pro-spa-usage-badge__value">{ formatCost( usage.costUsd ) }</span>
				</span>
			) }
			{ hasModel && (
				<span className="nvoos-pro-spa-usage-badge nvoos-pro-spa-usage-badge--model">
					<span className="nvoos-pro-spa-usage-badge__value">{ usage.model }</span>
				</span>
			) }
		</div>
	);
}
