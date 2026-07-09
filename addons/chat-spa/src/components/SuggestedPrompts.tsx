/**
 * NV oOS Chat SPA — Suggested prompts component.
 *
 * Renders clickable prompt chips above the composer when the shortcode
 * config includes a `suggestedPrompts` array. Mirrors the legacy
 * `renderSuggestedPrompts` from `assets/js/chat.js`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.9.0
 */

import { type JSX } from 'react';

export interface SuggestedPromptsProps {
	prompts?: string[];
	onSelect: ( prompt: string ) => void;
}

export function SuggestedPrompts( {
	prompts,
	onSelect,
}: SuggestedPromptsProps ): JSX.Element | null {
	if ( ! prompts || prompts.length === 0 ) return null;

	return (
		<div className="nvoos-chat-spa-prompts">
			{ prompts.map( ( prompt, i ) => (
				<button
					key={ i }
					type="button"
					className="nvoos-chat-spa-prompt-chip"
					onClick={ () => onSelect( prompt ) }
				>
					{ prompt }
				</button>
			) ) }
		</div>
	);
}
