/**
 * NV oOS Media Studio — Media Player mode.
 *
 * Universal video/audio/stream player powered by react-player.
 * Supports YouTube, Vimeo, HLS, DASH, MP4, MP3, and more via
 * react-player's provider system.
 *
 * Controls: Play/Pause · Seek slider · Volume · Mute · Duration ·
 *   Playback speed · Fullscreen toggle.
 *
 * @since 0.1.0  (initial)
 * @since 0.2.0  (playback speed, fullscreen, keyboard shortcuts)
 */

import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
// react-player v3 changed its type exports. Import as unknown and re-cast
// to avoid the TSC incompatibility with its `Omit<ReactPlayerProps,'ref'>` shape.
import ReactPlayerLib from 'react-player';
const ReactPlayer = ReactPlayerLib as any;

import { useKeyboardShortcuts, type Shortcut } from '../hooks/useKeyboardShortcuts';

interface ProgressState {
	played: number;
	playedSeconds: number;
	loaded: number;
	loadedSeconds: number;
}

interface MediaPlayerProps {
	src?: string;
	toolkit?: string;
}

const PLAYBACK_SPEEDS = [ 0.5, 0.75, 1, 1.25, 1.5, 2 ];

function formatTime( seconds: number ) {
	const m = Math.floor( seconds / 60 );
	const s = Math.floor( seconds % 60 );
	return `${ m }:${ String( s ).padStart( 2, '0' ) }`;
}

export function MediaPlayer( { src, toolkit }: MediaPlayerProps ) {
	const [ playing, setPlaying ] = useState( false );
	const [ played, setPlayed ] = useState( 0 );
	const [ seeking, setSeeking ] = useState( false );
	const [ duration, setDuration ] = useState( 0 );
	const [ volume, setVolume ] = useState( 0.8 );
	const [ muted, setMuted ] = useState( false );
	const [ ready, setReady ] = useState( false );
	const [ playbackRate, setPlaybackRate ] = useState( 1 );
	const playerRef = useRef<any>( null );
	const wrapperRef = useRef<HTMLDivElement>( null );

	const handleProgress = useCallback(
		( p: ProgressState ) => {
			if ( ! seeking ) {
				setPlayed( p.played );
			}
		},
		[ seeking ],
	);

	const handleSeekMouseDown = () => setSeeking( true );

	const handleSeekChange = ( e: React.ChangeEvent<HTMLInputElement> ) =>
		setPlayed( parseFloat( e.target.value ) );

	const handleSeekMouseUp = ( e: React.MouseEvent<HTMLInputElement> ) => {
		setSeeking( false );
		playerRef.current?.seekTo?.( parseFloat( ( e.target as HTMLInputElement ).value ) );
	};

	const toggleFullscreen = useCallback( () => {
		const el = wrapperRef.current;
		if ( ! el ) {
			return;
		}
		if ( document.fullscreenElement ) {
			document.exitFullscreen().catch( () => {} );
		} else {
			el.requestFullscreen().catch( () => {} );
		}
	}, [] );

	const cyclePlaybackSpeed = useCallback( () => {
		setPlaybackRate( ( prev ) => {
			const idx = PLAYBACK_SPEEDS.indexOf( prev );
			return PLAYBACK_SPEEDS[ ( idx + 1 ) % PLAYBACK_SPEEDS.length ];
		} );
	}, [] );

	// ---- Keyboard shortcuts ----
	const shortcuts: Shortcut[] = useMemo( () => [
		{ key: ' ', ctrl: false, shift: false, label: 'Play/Pause', handler: () => setPlaying( ( p ) => ! p ) },
		{ key: 'f', ctrl: false, shift: false, label: 'Fullscreen', handler: toggleFullscreen },
		{ key: 'm', ctrl: false, shift: false, label: 'Mute', handler: () => setMuted( ( m ) => ! m ) },
	], [ toggleFullscreen ] );

	useKeyboardShortcuts( shortcuts, ready );

	// Forward playbackRate to react-player.
	useEffect( () => {
		const internal = playerRef.current?.getInternalPlayer?.();
		if ( internal && typeof internal.playbackRate !== 'undefined' ) {
			internal.playbackRate = playbackRate;
		}
	}, [ playbackRate, playing ] );

	if ( ! src ) {
		return (
			<div className="nvoos-ms-empty-state">
				<p>
					{ __( 'Pass a `src` URL (YouTube, Vimeo, MP4, MP3, HLS…) via the shortcode to load the player.', 'nvoos-media-studio' ) }
				</p>
				{ toolkit && <p className="nvoos-ms-toolkit-label">{ __( 'Toolkit: ', 'nvoos-media-studio' ) }{ toolkit }</p> }
			</div>
		);
	}

	return (
		<div className="nvoos-ms-media-player">
			<div className="nvoos-ms-player-wrapper" ref={ wrapperRef }>
				<ReactPlayer
					ref={ playerRef }
					url={ src }
					playing={ playing }
					volume={ volume }
					muted={ muted }
					width="100%"
					height="100%"
					playbackRate={ playbackRate }
					onReady={ () => setReady( true ) }
					onProgress={ handleProgress }
					onDuration={ setDuration }
				/>
			</div>
			<div
				className={ 'nvoos-ms-player-controls' + ( ! ready ? ' nvoos-ms-player-controls--loading' : '' ) }
				role="group"
				aria-label={ __( 'Player controls', 'nvoos-media-studio' ) }
			>
				{ /* Play/Pause */ }
				<button
					type="button"
					className="nvoos-ms-toolbar-btn"
					onClick={ () => setPlaying( ( p ) => ! p ) }
					aria-label={ playing ? __( 'Pause', 'nvoos-media-studio' ) : __( 'Play', 'nvoos-media-studio' ) }
					disabled={ ! ready }
				>
					{ playing ? '⏸' : '▶' }
				</button>

				{ /* Seek */ }
				<input
					type="range"
					className="nvoos-ms-seek"
					min={ 0 }
					max={ 1 }
					step={ 0.001 }
					value={ played }
					onMouseDown={ handleSeekMouseDown }
					onChange={ handleSeekChange }
					onMouseUp={ handleSeekMouseUp }
					aria-label={ __( 'Seek', 'nvoos-media-studio' ) }
					disabled={ ! ready }
				/>

				{ /* Time */ }
				<span className="nvoos-ms-time" aria-live="off">
					{ formatTime( played * duration ) } / { formatTime( duration ) }
				</span>

				{ /* Speed */ }
				<button
					type="button"
					className="nvoos-ms-toolbar-btn nvoos-ms-speed-btn"
					onClick={ cyclePlaybackSpeed }
					aria-label={ __( 'Playback speed', 'nvoos-media-studio' ) }
					title={ `Speed: ${ playbackRate }×` }
				>
					{ playbackRate }×
				</button>

				{ /* Volume */ }
				<button
					type="button"
					className={ 'nvoos-ms-toolbar-btn' + ( muted ? ' nvoos-ms-toolbar-btn--active' : '' ) }
					onClick={ () => setMuted( ( m ) => ! m ) }
					aria-pressed={ muted }
					aria-label={ muted ? __( 'Unmute', 'nvoos-media-studio' ) : __( 'Mute', 'nvoos-media-studio' ) }
				>
					{ muted ? '🔇' : '🔊' }
				</button>
				<input
					type="range"
					className="nvoos-ms-volume"
					min={ 0 }
					max={ 1 }
					step={ 0.05 }
					value={ muted ? 0 : volume }
					onChange={ ( e ) => {
						const v = parseFloat( e.target.value );
						setVolume( v );
						if ( v === 0 ) {
							setMuted( true );
						} else if ( muted ) {
							setMuted( false );
						}
					} }
					aria-label={ __( 'Volume', 'nvoos-media-studio' ) }
				/>

				{ /* Fullscreen */ }
				<button
					type="button"
					className="nvoos-ms-toolbar-btn"
					onClick={ toggleFullscreen }
					aria-label={ __( 'Fullscreen', 'nvoos-media-studio' ) }
					title="Fullscreen (F)"
				>
					⛶
				</button>
			</div>
		</div>
	);
}
