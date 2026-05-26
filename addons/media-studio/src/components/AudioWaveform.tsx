/**
 * NV oOS Media Studio — Audio Waveform mode.
 *
 * Waveform visualization powered by wavesurfer.js 7.
 * Renders the audio waveform into a container div, then exposes
 * Play/Pause, Seek-by-click, Zoom, Playback speed, and time display.
 *
 * @since 0.1.0  (initial)
 * @since 0.2.0  (playback speed)
 */

import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from 'react';
import WaveSurfer from 'wavesurfer.js';

interface AudioWaveformProps {
	src?: string;
	toolkit?: string;
}

const PLAYBACK_SPEEDS = [ 0.5, 0.75, 1, 1.25, 1.5, 2 ];

const formatTime = ( s: number ) =>
	`${ Math.floor( s / 60 ) }:${ String( Math.floor( s % 60 ) ).padStart( 2, '0' ) }`;

export function AudioWaveform( { src, toolkit }: AudioWaveformProps ) {
	const containerRef = useRef<HTMLDivElement>( null );
	const wsRef = useRef<WaveSurfer | null>( null );
	const [ playing, setPlaying ] = useState( false );
	const [ currentTime, setCurrentTime ] = useState( 0 );
	const [ totalDuration, setTotalDuration ] = useState( 0 );
	const [ zoom, setZoom ] = useState( 20 );
	const [ playbackRate, setPlaybackRate ] = useState( 1 );
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
		setPlaybackRate( 1 );

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

	// Apply playback rate.
	useEffect( () => {
		if ( wsRef.current ) {
			wsRef.current.setPlaybackRate( playbackRate );
		}
	}, [ playbackRate ] );

	const cyclePlaybackSpeed = useCallback( () => {
		setPlaybackRate( ( prev ) => {
			const idx = PLAYBACK_SPEEDS.indexOf( prev );
			return PLAYBACK_SPEEDS[ ( idx + 1 ) % PLAYBACK_SPEEDS.length ];
		} );
	}, [] );

	if ( ! src ) {
		return (
			<div className="nvoos-ms-empty-state">
				<p>
					{ __( 'Pass an audio `src` URL via the shortcode to render the waveform.', 'nvoos-media-studio' ) }
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
				aria-label={ __( 'Audio waveform', 'nvoos-media-studio' ) }
			/>
			{ loadError && (
				<p className="nvoos-ms-error" role="alert">
					{ __( 'Failed to load audio. Check the URL or file format.', 'nvoos-media-studio' ) }
				</p>
			) }
			{ /* Controls */ }
			<div className="nvoos-ms-player-controls" role="group" aria-label={ __( 'Waveform player controls', 'nvoos-media-studio' ) }>
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

				{ /* Speed */ }
				<button
					type="button"
					className="nvoos-ms-toolbar-btn nvoos-ms-speed-btn"
					onClick={ cyclePlaybackSpeed }
					disabled={ loading || loadError }
					aria-label={ __( 'Playback speed', 'nvoos-media-studio' ) }
					title={ `Speed: ${ playbackRate }×` }
				>
					{ playbackRate }×
				</button>

				<label className="nvoos-ms-slider-label" aria-label={ __( 'Zoom', 'nvoos-media-studio' ) }>
					{ __( 'Zoom', 'nvoos-media-studio' ) }
					<input
						type="range"
						min={ 1 }
						max={ 200 }
						step={ 1 }
						value={ zoom }
						onChange={ ( e ) => setZoom( Number( e.target.value ) ) }
						className="nvoos-ms-slider"
						aria-label={ __( 'Waveform zoom', 'nvoos-media-studio' ) }
					/>
				</label>
			</div>
		</div>
	);
}
