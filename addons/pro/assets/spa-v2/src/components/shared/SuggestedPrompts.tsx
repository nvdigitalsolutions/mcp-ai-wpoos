/**
 * Pro SPA v2 — Suggested prompts component.
 * @package NV_oOS_Pro_Spa @since 0.9.0
 */

import { type JSX } from 'react';

export interface SuggestedPromptsProps {
	prompts?: string[];
	onSelect: ( prompt: string ) => void;
}

export function SuggestedPrompts( { prompts, onSelect }: SuggestedPromptsProps ): JSX.Element | null {
	if ( ! prompts || prompts.length === 0 ) return null;
	return (
		<div className="nvoos-pro-spa-prompts">
			{ prompts.map( ( p, i ) => (
				<button key={ i } type="button" className="nvoos-pro-spa-prompt-chip" onClick={ () => onSelect( p ) }>
					{ p }
				</button>
			) ) }
		</div>
	);
}
