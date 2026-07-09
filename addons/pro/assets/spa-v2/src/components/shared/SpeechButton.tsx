/**
 * Pro SPA v2 — Speech (TTS) button for messages.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX, useCallback } from 'react';

export type SpeechState = 'idle' | 'loading' | 'playing' | 'error';

export interface SpeechButtonProps {
	text: string;
	state: SpeechState;
	onPlay: ( text: string ) => void;
	onStop: () => void;
}

const ICONS: Record< SpeechState, string > = { idle: '🔈', loading: '⏳', playing: '🔊', error: '⚠️' };
const LABELS: Record< SpeechState, string > = { idle: 'Listen', loading: 'Loading…', playing: 'Stop', error: 'Retry' };

export function SpeechButton( { text, state, onPlay, onStop }: SpeechButtonProps ): JSX.Element | null {
	if ( ! text ) return null;
	const handleClick = useCallback( () => {
		if ( state === 'playing' ) { onStop(); } else { onPlay( text ); }
	}, [ text, state, onPlay, onStop ] );

	return (
		<button type="button" className={ `nvoos-pro-spa-speech-btn nvoos-pro-spa-speech-btn--${ state }` }
			aria-label={ __( LABELS[ state ], 'nvoos-pro-spa' ) } onClick={ handleClick } disabled={ state === 'loading' }>
			{ ICONS[ state ] }
		</button>
	);
}
