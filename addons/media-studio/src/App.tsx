/**
 * NV oOS Media Studio — root component.
 *
 * Dispatches to the requested media surface:
 *   - `image-editor`    — react-konva canvas + react-image-crop overlay.
 *   - `media-player`    — react-player universal video/audio player.
 *   - `audio-waveform`  — wavesurfer.js waveform visualizer.
 *
 * @since 0.1.0
 */

import type { ReactElement } from 'react';
import { __ } from '@wordpress/i18n';
import { ImageEditor } from './components/ImageEditor';
import { MediaPlayer } from './components/MediaPlayer';
import { AudioWaveform } from './components/AudioWaveform';

export type MediaMode = 'image-editor' | 'media-player' | 'audio-waveform';

interface AppProps {
	config: {
		toolkit?: string;
		theme?: string;
		height?: string;
		mode?: MediaMode;
		/** URL of the media file to load (image / video / audio). */
		src?: string;
	};
}

const ALLOWED_MODES: MediaMode[] = [ 'image-editor', 'media-player', 'audio-waveform' ];

export function App( { config }: AppProps ) {
	const mode: MediaMode =
		config.mode && ( ALLOWED_MODES as string[] ).includes( config.mode )
			? config.mode
			: 'image-editor';
	const heightStyle = config.height ? { height: config.height } : undefined;

	let surface: ReactElement;
	switch ( mode ) {
		case 'media-player':
			surface = <MediaPlayer src={ config.src } toolkit={ config.toolkit } />;
			break;
		case 'audio-waveform':
			surface = <AudioWaveform src={ config.src } toolkit={ config.toolkit } />;
			break;
		case 'image-editor':
		default:
			surface = <ImageEditor src={ config.src } toolkit={ config.toolkit } />;
	}

	return (
		<div
			className="nvoos-media-studio-app"
			data-theme={ config.theme ?? 'auto' }
			data-mode={ mode }
			style={ heightStyle }
		>
			<a className="nvoos-skip-link" href="#nvoos-media-main-content">
				{ __( 'Skip to main content', 'nvoos-media-studio' ) }
			</a>
			<div id="nvoos-media-main-content" tabIndex={ -1 }>
				{ surface }
			</div>
		</div>
	);
}

