/**
 * NV oOS Media Studio — Image Editor mode.
 *
 * Implements a non-destructive image editor on a react-konva canvas.
 * When a `src` prop is provided the image loads automatically.
 * The crop overlay uses react-image-crop; the underlying pixel transforms
 * (rotate / flip / brightness / contrast) run on a hidden <canvas> via
 * a helper drawn on the Konva stage and can be exported as PNG.
 *
 * Toolbar actions: Crop · Rotate CW/CCW · Flip H/V · Brightness ·
 *   Contrast · Reset · Download PNG
 *
 * @since 0.1.0
 */

import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Stage, Layer, Image as KonvaImage } from 'react-konva';
import ReactCrop, { type Crop } from 'react-image-crop';
import 'react-image-crop/dist/ReactCrop.css';
import type Konva from 'konva';

interface ImageEditorProps {
	src?: string;
	toolkit?: string;
}

interface ImageState {
	rotation: number;
	flipH: boolean;
	flipV: boolean;
	brightness: number; // -1.0 to 1.0
	contrast: number;   // -1.0 to 1.0
}

const DEFAULT_STATE: ImageState = {
	rotation: 0,
	flipH: false,
	flipV: false,
	brightness: 0,
	contrast: 0,
};

const STAGE_WIDTH = 800;
const STAGE_HEIGHT = 500;

/** Apply brightness/contrast filter on a Konva Image node. */
function applyFilters( node: Konva.Image, state: ImageState ) {
	const K = ( window as any ).Konva as typeof Konva | undefined;
	if ( ! K ) {
		return;
	}
	node.filters( [ K.Filters.Brighten, K.Filters.Contrast ] );
	node.brightness( state.brightness );
	node.contrast( state.contrast * 100 );
	node.cache();
	node.getLayer()?.batchDraw();
}

export function ImageEditor( { src, toolkit }: ImageEditorProps ) {
	const [ imgEl, setImgEl ] = useState<HTMLImageElement | null>( null );
	const [ state, setState ] = useState<ImageState>( DEFAULT_STATE );
	const [ crop, setCrop ] = useState<Crop | undefined>( undefined );
	const [ cropMode, setCropMode ] = useState( false );
	const [ loadError, setLoadError ] = useState( false );
	const imgNodeRef = useRef<Konva.Image | null>( null );
	const fileRef = useRef<HTMLInputElement>( null );

	/** Load image from URL or after upload. */
	const loadSrc = useCallback( ( url: string ) => {
		setLoadError( false );
		const img = new window.Image();
		img.crossOrigin = 'anonymous';
		img.onload = () => setImgEl( img );
		img.onerror = () => setLoadError( true );
		img.src = url;
	}, [] );

	useEffect( () => {
		if ( src ) {
			loadSrc( src );
		}
	}, [ src, loadSrc ] );

	const handleFileChange = useCallback(
		( e: React.ChangeEvent<HTMLInputElement> ) => {
			const file = e.target.files?.[ 0 ];
			if ( ! file ) {
				return;
			}
			const url = URL.createObjectURL( file );
			loadSrc( url );
		},
		[ loadSrc ]
	);

	const rotate = ( delta: number ) => {
		setState( ( s ) => ( { ...s, rotation: ( s.rotation + delta + 360 ) % 360 } ) );
	};

	const toggleCropMode = () => {
		setCropMode( ( v ) => ! v );
		setCrop( undefined );
	};

	const handleDownload = () => {
		const stage = imgNodeRef.current?.getStage();
		if ( ! stage ) {
			return;
		}
		const dataUrl = stage.toDataURL( { mimeType: 'image/png' } );
		const a = document.createElement( 'a' );
		a.href = dataUrl;
		a.download = 'media-studio-export.png';
		a.click();
	};

	const imgWidth = imgEl ? Math.min( imgEl.width, STAGE_WIDTH ) : STAGE_WIDTH;
	const imgHeight = imgEl
		? Math.round( ( imgEl.height / imgEl.width ) * imgWidth )
		: STAGE_HEIGHT;

	return (
		<div className="nvoos-ms-image-editor">
			{ /* Toolbar */ }
			<div className="nvoos-ms-toolbar" role="toolbar" aria-label="Image editor toolbar">
				<label className="nvoos-ms-toolbar-btn nvoos-ms-upload-label">
					Open
					<input
						ref={ fileRef }
						type="file"
						accept="image/*"
						className="nvoos-ms-hidden-input"
						onChange={ handleFileChange }
					/>
				</label>
				<button
					type="button"
					className={ 'nvoos-ms-toolbar-btn' + ( cropMode ? ' nvoos-ms-toolbar-btn--active' : '' ) }
					onClick={ toggleCropMode }
					aria-pressed={ cropMode }
					aria-label={ __( 'Crop', 'nvoos-media-studio' ) }
				>
					Crop
				</button>
				<button
					type="button"
					className="nvoos-ms-toolbar-btn"
					onClick={ () => rotate( -90 ) }
					aria-label={ __( 'Rotate counter-clockwise', 'nvoos-media-studio' ) }
				>
					↺ 90°
				</button>
				<button
					type="button"
					className="nvoos-ms-toolbar-btn"
					onClick={ () => rotate( 90 ) }
					aria-label={ __( 'Rotate clockwise', 'nvoos-media-studio' ) }
				>
					↻ 90°
				</button>
				<button
					type="button"
					className={ 'nvoos-ms-toolbar-btn' + ( state.flipH ? ' nvoos-ms-toolbar-btn--active' : '' ) }
					onClick={ () => setState( ( s ) => ( { ...s, flipH: ! s.flipH } ) ) }
					aria-pressed={ state.flipH }
					aria-label="Flip horizontal"
				>
					⇄ Flip H
				</button>
				<button
					type="button"
					className={ 'nvoos-ms-toolbar-btn' + ( state.flipV ? ' nvoos-ms-toolbar-btn--active' : '' ) }
					onClick={ () => setState( ( s ) => ( { ...s, flipV: ! s.flipV } ) ) }
					aria-pressed={ state.flipV }
					aria-label="Flip vertical"
				>
					⇅ Flip V
				</button>
				<span className="nvoos-ms-toolbar-sep" aria-hidden="true" />
				<label className="nvoos-ms-slider-label" aria-label={ __( 'Brightness', 'nvoos-media-studio' ) }>
					☀
					<input
						type="range"
						min={ -1 }
						max={ 1 }
						step={ 0.05 }
						value={ state.brightness }
						onChange={ ( e ) =>
							setState( ( s ) => ( { ...s, brightness: parseFloat( e.target.value ) } ) )
						}
						className="nvoos-ms-slider"
					/>
				</label>
				<label className="nvoos-ms-slider-label" aria-label={ __( 'Contrast', 'nvoos-media-studio' ) }>
					◑
					<input
						type="range"
						min={ -1 }
						max={ 1 }
						step={ 0.05 }
						value={ state.contrast }
						onChange={ ( e ) =>
							setState( ( s ) => ( { ...s, contrast: parseFloat( e.target.value ) } ) )
						}
						className="nvoos-ms-slider"
					/>
				</label>
				<span className="nvoos-ms-toolbar-sep" aria-hidden="true" />
				<button
					type="button"
					className="nvoos-ms-toolbar-btn"
					onClick={ () => setState( DEFAULT_STATE ) }
					aria-label={ __( 'Reset adjustments', 'nvoos-media-studio' ) }
				>
					Reset
				</button>
				<button
					type="button"
					className="nvoos-ms-toolbar-btn nvoos-ms-toolbar-btn--primary"
					onClick={ handleDownload }
					disabled={ ! imgEl }
					aria-label={ __( 'Download PNG', 'nvoos-media-studio' ) }
				>
					↓ PNG
				</button>
			</div>

			{ /* Canvas area */ }
			{ loadError ? (
				<p className="nvoos-ms-error" role="alert">
					Failed to load image. Check the URL or try another file.
				</p>
			) : cropMode && imgEl ? (
				<div className="nvoos-ms-crop-overlay">
					<ReactCrop
						crop={ crop }
						onChange={ ( c ) => setCrop( c ) }
						aspect={ undefined }
					>
						<img
							src={ imgEl.src }
							alt={ __( 'Crop preview', 'nvoos-media-studio' ) }
							style={ {
								maxWidth: STAGE_WIDTH,
								transform: `rotate(${ state.rotation }deg) scaleX(${ state.flipH ? -1 : 1 }) scaleY(${ state.flipV ? -1 : 1 })`,
							} }
						/>
					</ReactCrop>
					<button
						type="button"
						className="nvoos-ms-toolbar-btn nvoos-ms-crop-done"
						onClick={ () => setCropMode( false ) }
					>
						Done
					</button>
				</div>
			) : (
				<div className="nvoos-ms-stage-wrapper">
					<Stage width={ imgEl ? imgWidth : STAGE_WIDTH } height={ imgEl ? imgHeight : STAGE_HEIGHT }>
						<Layer>
							{ imgEl ? (
								<KonvaImage
									ref={ ( node ) => {
										imgNodeRef.current = node;
										if ( node ) {
											applyFilters( node, state );
										}
									} }
									image={ imgEl }
									x={ imgWidth / 2 }
									y={ imgHeight / 2 }
									width={ imgWidth }
									height={ imgHeight }
									rotation={ state.rotation }
									offsetX={ imgWidth / 2 }
									offsetY={ imgHeight / 2 }
									scaleX={ state.flipH ? -1 : 1 }
									scaleY={ state.flipV ? -1 : 1 }
								/>
							) : (
								<>{ /* empty stage — waiting for image */ }</>
							) }
						</Layer>
					</Stage>
						{ ! imgEl && (
						<div className="nvoos-ms-empty-state">
							<p>{ __( 'Open an image file or pass a src URL via the shortcode.', 'nvoos-media-studio' ) }</p>
							{ toolkit && (
								<p className="nvoos-ms-toolkit-label">{ __( 'Toolkit: ', 'nvoos-media-studio' ) }{ toolkit }</p>
							) }
						</div>
					) }
				</div>
			) }
		</div>
	);
}
