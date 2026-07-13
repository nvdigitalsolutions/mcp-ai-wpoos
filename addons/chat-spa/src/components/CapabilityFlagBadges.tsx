/**
 * NV oOS Chat SPA — Capability flag badges.
 *
 * Renders small colored pills below an assistant message to indicate
 * which AI capabilities were exercised during the turn (e.g. "voice",
 * "vision", "function-calling").
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { type JSX } from 'react';

export interface CapabilityFlagBadgesProps {
	flags?: string[] | null;
}

/**
 * Map of capability flag keys → display label and colour hint.
 * The colour is applied via CSS class `nvoos-chat-spa-capability--<key>`.
 */
const KNOWN_FLAGS: Record< string, string > = {
	voice: 'Voice',
	audio: 'Audio',
	vision: 'Vision',
	'tool-use': 'Tools',
	'function-calling': 'Tools',
	tools: 'Tools',
	code: 'Code',
	search: 'Search',
	reasoning: 'Reasoning',
	planning: 'Planning',
	// Tool-level capability flags (v0.9.0).
	'read-only': 'Read-only',
	'local-only': 'Local',
	write: 'Write',
	'state-changing': 'State-changing',
	'modifies-data': 'Modifies',
	'requires-capability': 'Auth',
	'requires-credentials': 'Auth',
	'external-api': 'External',
	'network-dependent': 'Network',
	'consumes-tokens': 'Tokens',
	async: 'Async',
	'background-only': 'Background',
	'non-deterministic': 'Non-determ',
	idempotent: 'Idempotent',
	cacheable: 'Cached',
	pro: 'Pro',
};

function flagDisplayName( flag: string ): string {
	return KNOWN_FLAGS[ flag.toLowerCase() ] ?? flag;
}

export function CapabilityFlagBadges( {
	flags,
}: CapabilityFlagBadgesProps ): JSX.Element | null {
	if ( ! flags || flags.length === 0 ) return null;

	const unique = [ ...new Set( flags.map( ( f ) => f.toLowerCase() ) ) ];

	return (
		<span className="nvoos-chat-spa-capabilities">
			{ unique.map( ( flag ) => (
				<span
					key={ flag }
					className={ `nvoos-chat-spa-capability nvoos-chat-spa-capability--${ flag }` }
				>
					{ flagDisplayName( flag ) }
				</span>
			) ) }
		</span>
	);
}
