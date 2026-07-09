/**
 * NV oOS Chat SPA — Usage badges (token count + cost).
 *
 * Renders compact token-count and cost badges below an assistant
 * message.  Mirrors the `attachUsageBadges()` pattern from the legacy
 * `assets/js/chat.js` client.
 *
 * When `isEstimated` is true, the token count is annotated with "~".
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { type JSX } from 'react';

export interface UsageData {
	promptTokens?: number;
	completionTokens?: number;
	totalTokens?: number;
	costUsd?: number;
	/** True when tokens were estimated (not reported by the provider). */
	isEstimated?: boolean;
	/** Model name used for this turn (e.g. "gpt-4o"). */
	model?: string;
	/** Provider name (e.g. "openai", "gemini"). */
	provider?: string;
}

export interface UsageBadgesProps {
	usage: UsageData | null | undefined;
}

function formatTokens( n: number | undefined ): string {
	if ( n === undefined || n === null ) return '';
	if ( n >= 1_000_000 ) return ( n / 1_000_000 ).toFixed( 1 ) + 'M';
	if ( n >= 1_000 ) return ( n / 1_000 ).toFixed( 1 ) + 'k';
	return String( n );
}

function formatCost( usd: number | undefined ): string {
	if ( usd === undefined || usd === null ) return '';
	if ( usd === 0 ) return '$0.00';
	if ( usd < 0.01 ) return '<$0.01';
	return '$' + usd.toFixed( 2 );
}

/**
 * Build a tooltip string describing the full token breakdown.
 */
function buildTokenTooltip( usage: UsageData ): string {
	const parts: string[] = [];
	if ( typeof usage.promptTokens === 'number' ) {
		parts.push(
			sprintf(
				/* translators: %s: token count. */
				__( 'Prompt: %s tokens', 'nvoos-chat-spa' ),
				formatTokens( usage.promptTokens )
			)
		);
	}
	if ( typeof usage.completionTokens === 'number' ) {
		parts.push(
			sprintf(
				/* translators: %s: token count. */
				__( 'Completion: %s tokens', 'nvoos-chat-spa' ),
				formatTokens( usage.completionTokens )
			)
		);
	}
	if ( typeof usage.totalTokens === 'number' ) {
		parts.push(
			sprintf(
				/* translators: %s: token count. */
				__( 'Total: %s tokens', 'nvoos-chat-spa' ),
				formatTokens( usage.totalTokens )
			)
		);
	}
	return parts.join( '\n' );
}

export function UsageBadges( { usage }: UsageBadgesProps ): JSX.Element | null {
	if ( ! usage ) return null;

	const hasTokens =
		typeof usage.totalTokens === 'number' ||
		typeof usage.promptTokens === 'number' ||
		typeof usage.completionTokens === 'number';
	const hasCost = typeof usage.costUsd === 'number';
	const hasModel = typeof usage.model === 'string' && usage.model;

	if ( ! hasTokens && ! hasCost && ! hasModel ) return null;

	const tokenLabel = usage.isEstimated
		? '~' + formatTokens( usage.totalTokens ?? usage.completionTokens )
		: formatTokens( usage.totalTokens ?? usage.completionTokens );

	const tokenTooltip = buildTokenTooltip( usage );

	return (
		<span className="nvoos-chat-spa-usage-badges" aria-label={ __( 'Usage information', 'nvoos-chat-spa' ) }>
			{ hasTokens && tokenLabel && (
				<span
					className="nvoos-chat-spa-usage-badge nvoos-chat-spa-usage-badge--tokens"
					title={ tokenTooltip }
				>
					<span className="nvoos-chat-spa-usage-badge-label">
						{ __( 'Tokens', 'nvoos-chat-spa' ) }
					</span>
					<span className="nvoos-chat-spa-usage-badge-value">{ tokenLabel }</span>
				</span>
			) }
			{ hasCost && (
				<span
					className="nvoos-chat-spa-usage-badge nvoos-chat-spa-usage-badge--cost"
					title={
						usage.isEstimated
							? __( 'Estimated cost', 'nvoos-chat-spa' )
							: __( 'Cost', 'nvoos-chat-spa' )
					}
				>
					<span className="nvoos-chat-spa-usage-badge-value">
						{ formatCost( usage.costUsd ) }
					</span>
				</span>
			) }
			{ hasModel && (
				<span className="nvoos-chat-spa-usage-badge nvoos-chat-spa-usage-badge--model"
					title={ usage.provider ? `${ usage.provider } / ${ usage.model }` : usage.model }
				>
					<span className="nvoos-chat-spa-usage-badge-value">{ usage.model }</span>
				</span>
			) }
		</span>
	);
}
