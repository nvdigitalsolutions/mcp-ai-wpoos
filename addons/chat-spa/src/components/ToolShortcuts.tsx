/**
 * NV oOS Chat SPA — Tool shortcuts component.
 *
 * Renders action buttons above the composer for common tools (e.g.
 * `/search`, `/research`). Clicking a button inserts the tool payload
 * into the composer.
 *
 * Mirrors the legacy `renderToolShortcuts` from `assets/js/chat.js`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.9.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX, useState } from 'react';

export interface ToolShortcut {
	label: string;
	tool?: string;
	payload?: string;
	description?: string;
}

export interface ToolShortcutsProps {
	shortcuts?: ToolShortcut[];
	onInsert: ( text: string ) => void;
}

export function ToolShortcuts( {
	shortcuts,
	onInsert,
}: ToolShortcutsProps ): JSX.Element | null {
	const [ expanded, setExpanded ] = useState( false );

	if ( ! shortcuts || shortcuts.length === 0 ) return null;

	return (
		<div className="nvoos-chat-spa-tool-shortcuts">
			<button
				type="button"
				className="nvoos-chat-spa-tool-shortcuts-toggle"
				aria-expanded={ expanded }
				onClick={ () => setExpanded( ( e ) => ! e ) }
			>
				{ expanded
					? __( 'Hide shortcuts', 'nvoos-chat-spa' )
					: __( 'Tools', 'nvoos-chat-spa' ) }
			</button>
			{ expanded && (
				<div className="nvoos-chat-spa-tool-shortcuts-list">
					{ shortcuts.map( ( s, i ) => (
						<button
							key={ i }
							type="button"
							className="nvoos-chat-spa-tool-shortcut-btn"
							title={ s.description ?? s.label }
							onClick={ () => onInsert( s.payload ?? `/${ s.tool ?? '' }` ) }
						>
							{ s.label }
						</button>
					) ) }
				</div>
			) }
		</div>
	);
}
