/**
 * Pro SPA v2 — Capability flag badges.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { type JSX } from 'react';

export interface CapabilityFlagBadgesProps {
	flags?: string[] | null;
}

const KNOWN_FLAGS: Record< string, string > = {
	voice: 'Voice', audio: 'Audio', vision: 'Vision',
	'tool-use': 'Tools', 'function-calling': 'Tools', tools: 'Tools',
	code: 'Code', search: 'Search', reasoning: 'Reasoning', planning: 'Planning',
	// Tool-level capability flags (v0.9.0).
	'read-only': 'Read-only', 'local-only': 'Local',
	write: 'Write', 'state-changing': 'State-changing', 'modifies-data': 'Modifies',
	'requires-capability': 'Auth', 'requires-credentials': 'Auth',
	'external-api': 'External', 'network-dependent': 'Network',
	'consumes-tokens': 'Tokens', async: 'Async', 'background-only': 'Background',
	'non-deterministic': 'Non-determ', idempotent: 'Idempotent', cacheable: 'Cached',
	pro: 'Pro',
};

function flagDisplayName( flag: string ): string {
	return KNOWN_FLAGS[ flag.toLowerCase() ] ?? flag;
}

export function CapabilityFlagBadges( { flags }: CapabilityFlagBadgesProps ): JSX.Element | null {
	if ( ! flags || flags.length === 0 ) return null;
	const unique = [ ...new Set( flags.map( ( f ) => f.toLowerCase() ) ) ];
	return (
		<div className="nvoos-pro-spa-capabilities">
			{ unique.map( ( flag ) => (
				<span key={ flag } className={ `nvoos-pro-spa-capability nvoos-pro-spa-capability--${ flag }` }>
					{ flagDisplayName( flag ) }
				</span>
			) ) }
		</div>
	);
}
