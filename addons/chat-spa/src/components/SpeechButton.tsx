/**
 * NV oOS Chat SPA — Speech (TTS) button for assistant message bubbles.
 *
 * Renders a small icon button on each assistant message that triggers
 * text-to-speech playback via the `useSpeechPlayback` hook.
 *
 * Icon states:
 *   - idle:    🔈 (speaker)
 *   - loading: ⏳ (hourglass)
 *   - playing: 🔇 (mute / stop)
 *   - error:   ⚠ (warning)
 *
 * Mirrors the legacy `attachSpeechButton` pattern from `assets/js/chat.js`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX, useCallback } from 'react';
import type { SpeechState } from '../hooks/useSpeechPlayback';

export interface SpeechButtonProps {
	/** The text content to speak. */
	text: string;
	/** Current playback state for this text. */
	state: SpeechState;
	/** Play callback. */
	onPlay: ( text: string ) => void;
	/** Stop callback (called when clicking while playing). */
	onStop: () => void;
}

const ICONS: Record< SpeechState, string > = {
	idle: '🔈',
	loading: '⏳',
	playing: '🔊',
	error: '⚠️',
};

const LABELS: Record< SpeechState, string > = {
	idle: 'Listen',
	loading: 'Loading…',
	playing: 'Stop',
	error: 'Retry',
};

export function SpeechButton( {
	text,
	state,
	onPlay,
	onStop,
}: SpeechButtonProps ): JSX.Element | null {
	if ( ! text ) return null;

	const handleClick = useCallback( () => {
		if ( state === 'playing' ) {
			onStop();
		} else {
			onPlay( text );
		}
	}, [ text, state, onPlay, onStop ] );

	return (
		<button
			type="button"
			className={ `nvoos-chat-spa-speech-btn nvoos-chat-spa-speech-btn--${ state }` }
			aria-label={
				/* translators: Speech button state. */
				__( LABELS[ state ], 'nvoos-chat-spa' )
			}
			title={ __( LABELS[ state ], 'nvoos-chat-spa' ) }
			onClick={ handleClick }
			disabled={ state === 'loading' }
		>
			{ ICONS[ state ] }
		</button>
	);
}
