/**
 * NV oOS Media Studio — Audio Waveform mode.
 *
 * Waveform visualization powered by wavesurfer.js 7.
 * Renders the audio waveform into a container div, then exposes
 * Play/Pause, Seek-by-click, Zoom, and time display.
 *
 * @since 0.1.0
 */

import { __ } from '@wordpress/i18n';
import { useEffect, useRef, useState } from 'react';
import WaveSurfer from 'wavesurfer.js';

interface AudioWaveformProps {
	src?: string;
	toolkit?: string;
}

export function AudioWaveform( { src, toolkit }: AudioWaveformProps ) {
	const containerRef = useRef<HTMLDivElement>( null );
	const wsRef = useRef<WaveSurfer | null>( null );
	const [ playing, setPlaying ] = useState( false );
	const [ currentTime, setCurrentTime ] = useState( 0 );
	const [ totalDuration, setTotalDuration ] = useState( 0 );
	const [ zoom, setZoom ] = useState( 20 );
	const [ loading, setLoading ] = useState( false );
	const [ loadError, setLoadError ] = useState( false );

	useEffect( () => {
		if ( ! containerRef.current || ! src ) {
			return;
		}
		setLoading( true );
		setLoadError( false );
		setPlaying( false );
		setCurrentTime( 0 );
		setTotalDuration( 0 );

		const ws = WaveSurfer.create( {
			container: containerRef.current,
			waveColor: 'rgba(0, 100, 210, 0.6)',
			progressColor: 'rgba(0, 100, 210, 1)',
			height: 128,
			barWidth: 2,
			barGap: 1,
			barRadius: 2,
		} );
		wsRef.current = ws;

		ws.on( 'ready', () => {
			setTotalDuration( ws.getDuration() );
			setLoading( false );
		} );
		ws.on( 'audioprocess', () => setCurrentTime( ws.getCurrentTime() ) );
		ws.on( 'play', () => setPlaying( true ) );
		ws.on( 'pause', () => setPlaying( false ) );
		ws.on( 'error', () => {
			setLoadError( true );
			setLoading( false );
		} );

		ws.load( src );

		return () => {
			ws.destroy();
			wsRef.current = null;
		};
	}, [ src ] );

	// Apply zoom when slider changes.
	useEffect( () => {
		if ( wsRef.current ) {
			wsRef.current.zoom( zoom );
		}
	}, [ zoom ] );

	const formatTime = ( s: number ) =>
		`${ Math.floor( s / 60 ) }:${ String( Math.floor( s % 60 ) ).padStart( 2, '0' ) }`;

	if ( ! src ) {
		return (
			<div className="nvoos-ms-empty-state">
				<p>
					Pass an audio <code>src</code> URL via the shortcode to render the
					waveform.
				</p>
				{ toolkit && <p className="nvoos-ms-toolkit-label">{ __( 'Toolkit: ', 'nvoos-media-studio' ) }{ toolkit }</p> }
			</div>
		);
	}

	return (
		<div className="nvoos-ms-audio-waveform">
			{ /* Waveform canvas */ }
			<div
				ref={ containerRef }
				className={ 'nvoos-ms-waveform-canvas' + ( loading ? ' nvoos-ms-waveform-canvas--loading' : '' ) }
				role="img"
				aria-label="Audio waveform"
			/>
			{ loadError && (
				<p className="nvoos-ms-error" role="alert">
					Failed to load audio. Check the URL or file format.
				</p>
			) }
			{ /* Controls */ }
			<div className="nvoos-ms-player-controls" role="group" aria-label="Waveform player controls">
				<button
					type="button"
					className="nvoos-ms-toolbar-btn"
					onClick={ () => wsRef.current?.playPause() }
					disabled={ loading || loadError }
					aria-label={ playing ? __( 'Pause', 'nvoos-media-studio' ) : __( 'Play', 'nvoos-media-studio' ) }
				>
					{ playing ? '⏸' : '▶' }
				</button>
				<span className="nvoos-ms-time" aria-live="off">
					{ formatTime( currentTime ) } / { formatTime( totalDuration ) }
				</span>
				<label className="nvoos-ms-slider-label" aria-label={ __( 'Zoom', 'nvoos-media-studio' ) }>
					🔍
					<input
						type="range"
						min={ 1 }
						max={ 200 }
						step={ 1 }
						value={ zoom }
						onChange={ ( e ) => setZoom( Number( e.target.value ) ) }
						className="nvoos-ms-slider"
						aria-label="Waveform zoom"
					/>
				</label>
			</div>
		</div>
	);
}
