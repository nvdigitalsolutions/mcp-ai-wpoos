/**
 * NV oOS Media Studio — Media Player mode.
 *
 * Universal video/audio/stream player powered by react-player.
 * Supports YouTube, Vimeo, HLS, DASH, MP4, MP3, and more via
 * react-player's provider system.
 *
 * Controls: Play/Pause · Seek slider · Volume · Mute · Duration display.
 *
 * @since 0.1.0
 */

import { __ } from '@wordpress/i18n';
import { useCallback, useRef, useState } from 'react';
// react-player v3 changed its type exports. Import as unknown and re-cast
// to avoid the TSC incompatibility with its `Omit<ReactPlayerProps,'ref'>` shape.
import ReactPlayerLib from 'react-player';
const ReactPlayer = ReactPlayerLib as any;

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

export function MediaPlayer( { src, toolkit }: MediaPlayerProps ) {
const [ playing, setPlaying ] = useState( false );
const [ played, setPlayed ] = useState( 0 );
const [ seeking, setSeeking ] = useState( false );
const [ duration, setDuration ] = useState( 0 );
const [ volume, setVolume ] = useState( 0.8 );
const [ muted, setMuted ] = useState( false );
const [ ready, setReady ] = useState( false );
const playerRef = useRef<any>( null );

const handleProgress = useCallback(
( p: ProgressState ) => {
if ( ! seeking ) {
setPlayed( p.played );
}
},
[ seeking ]
);

const handleSeekMouseDown = () => setSeeking( true );

const handleSeekChange = ( e: React.ChangeEvent<HTMLInputElement> ) =>
setPlayed( parseFloat( e.target.value ) );

const handleSeekMouseUp = ( e: React.MouseEvent<HTMLInputElement> ) => {
setSeeking( false );
playerRef.current?.seekTo?.( parseFloat( ( e.target as HTMLInputElement ).value ) );
};

const formatTime = ( seconds: number ) => {
const m = Math.floor( seconds / 60 );
const s = Math.floor( seconds % 60 );
return `${ m }:${ String( s ).padStart( 2, '0' ) }`;
};

if ( ! src ) {
return (
<div className="nvoos-ms-empty-state">
<p>
Pass a <code>src</code> URL (YouTube, Vimeo, MP4, MP3, HLS…) via the
shortcode to load the player.
</p>
{ toolkit && <p className="nvoos-ms-toolkit-label">{ __( 'Toolkit: ', 'nvoos-media-studio' ) }{ toolkit }</p> }
</div>
);
}

return (
<div className="nvoos-ms-media-player">
<div className="nvoos-ms-player-wrapper">
<ReactPlayer
ref={ playerRef }
url={ src }
playing={ playing }
volume={ volume }
muted={ muted }
width="100%"
height="100%"
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
<button
type="button"
className="nvoos-ms-toolbar-btn"
onClick={ () => setPlaying( ( p ) => ! p ) }
aria-label={ playing ? __( 'Pause', 'nvoos-media-studio' ) : __( 'Play', 'nvoos-media-studio' ) }
disabled={ ! ready }
>
{ playing ? '⏸' : '▶' }
</button>
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
aria-label="Seek"
disabled={ ! ready }
/>
<span className="nvoos-ms-time" aria-live="off">
{ formatTime( played * duration ) } / { formatTime( duration ) }
</span>
<button
type="button"
className={ 'nvoos-ms-toolbar-btn' + ( muted ? ' nvoos-ms-toolbar-btn--active' : '' ) }
onClick={ () => setMuted( ( m ) => ! m ) }
aria-pressed={ muted }
aria-label={ muted ? 'Unmute' : 'Mute' }
>
{ muted ? '🔇' : '🔊' }
</button>
<input
type="range"
className="nvoos-ms-volume"
min={ 0 }
max={ 1 }
step={ 0.05 }
value={ volume }
onChange={ ( e ) => setVolume( parseFloat( e.target.value ) ) }
aria-label="Volume"
/>
</div>
</div>
);
}
