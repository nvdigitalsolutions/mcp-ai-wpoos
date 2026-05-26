/**
 * NV oOS Media Studio — Image Editor mode.
 *
 * Non-destructive image editor on a react-konva canvas.
 *
 * Features:
 *   - Undo/redo (Ctrl+Z / Ctrl+Shift+Z)
 *   - Rotate CW/CCW, Flip H/V
 *   - Filters: Brightness, Contrast, Saturation, Blur, Hue, Grayscale, Sepia, Invert
 *   - Crop overlay (react-image-crop)
 *   - Text annotation (Konva.Text)
 *   - Zoom/pan (mouse wheel zoom, Shift+drag pan, toolbar controls)
 *   - Drawing tools: freehand pen (Konva.Line), rectangle (Konva.Rect), circle (Konva.Ellipse)
 *   - Save to WordPress Media Library (REST endpoint)
 *   - Export as PNG download
 *   - Responsive canvas (ResizeObserver)
 *   - Keyboard shortcuts
 *
 * @since 0.1.0  (initial)
 * @since 0.2.0  (undo/redo, enhanced filters, keyboard shortcuts, responsive, text)
 * @since 0.3.0  (zoom/pan, drawing tools, save to WP media library)
 */

import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
	Stage, Layer, Image as KonvaImage, Text as KonvaText,
	Line as KonvaLine, Rect as KonvaRect, Ellipse as KonvaEllipse,
} from 'react-konva';
import ReactCrop, { type Crop } from 'react-image-crop';
import 'react-image-crop/dist/ReactCrop.css';
import Konva from 'konva';

import { useHistory } from '../hooks/useHistory';
import { useKeyboardShortcuts, type Shortcut } from '../hooks/useKeyboardShortcuts';
import { useResponsiveCanvas } from '../hooks/useResponsiveCanvas';
import { useZoomPan, type ZoomPanState } from '../hooks/useZoomPan';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface ImageEditorProps {
	src?: string;
	toolkit?: string;
}

interface FilterState {
	brightness: number;
	contrast: number;
	saturation: number;
	blur: number;
	hue: number;
	grayscale: boolean;
	sepia: boolean;
	invert: boolean;
}

interface ImageState {
	rotation: number;
	flipH: boolean;
	flipV: boolean;
	filters: FilterState;
}

interface TextAnnotation {
	id: string;
	x: number;
	y: number;
	text: string;
	fontSize: number;
	fill: string;
	isSelected: boolean;
}

interface DrawingLine {
	id: string;
	points: number[];
	color: string;
	strokeWidth: number;
}

interface DrawingRect {
	id: string;
	x: number;
	y: number;
	width: number;
	height: number;
	color: string;
	strokeWidth: number;
}

interface DrawingEllipse {
	id: string;
	x: number;
	y: number;
	radiusX: number;
	radiusY: number;
	color: string;
	strokeWidth: number;
}

type DrawingTool = 'pen' | 'rect' | 'circle' | null;

// ---------------------------------------------------------------------------
// Defaults
// ---------------------------------------------------------------------------

const DEFAULT_FILTERS: FilterState = {
	brightness: 0, contrast: 0, saturation: 0, blur: 0, hue: 0,
	grayscale: false, sepia: false, invert: false,
};

const DEFAULT_STATE: ImageState = {
	rotation: 0, flipH: false, flipV: false,
	filters: { ...DEFAULT_FILTERS },
};

const STAGE_FALLBACK_WIDTH = 800;
const STAGE_FALLBACK_HEIGHT = 500;
const TOOLBAR_APPROX_HEIGHT = 42;
const FILTER_ROW_APPROX_HEIGHT = 34;
const DRAWING_TOOLBAR_APPROX_HEIGHT = 36;

const DRAWING_COLORS = [ '#ff0000', '#00aa00', '#0066ff', '#ffcc00', '#ff6600', '#cc00cc', '#ffffff', '#000000' ];
const STROKE_WIDTHS = [ 2, 4, 6, 10, 16 ];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function getKonvaFilters( state: ImageState ): any[] {
	const f: any[] = [];
	const fs = state.filters;
	if ( fs.blur > 0.1 ) f.push( Konva.Filters.Blur );
	if ( Math.abs( fs.brightness ) > 0.01 ) f.push( Konva.Filters.Brighten );
	if ( Math.abs( fs.contrast ) > 0.01 ) f.push( Konva.Filters.Contrast );
	if ( Math.abs( fs.saturation ) > 0.01 || fs.hue !== 0 ) f.push( Konva.Filters.HSL );
	if ( fs.grayscale ) f.push( Konva.Filters.Grayscale );
	if ( fs.sepia ) f.push( Konva.Filters.Sepia );
	if ( fs.invert ) f.push( Konva.Filters.Invert );
	return f;
}

function applyFiltersToNode( node: Konva.Image, state: ImageState ) {
	const fs = state.filters;
	node.brightness( fs.brightness );
	node.contrast( fs.contrast * 100 );
	node.saturation( fs.saturation );
	node.hue( fs.hue );
	node.blurRadius( fs.blur );
	node.filters( getKonvaFilters( state ) );
	node.cache();
	node.getLayer()?.batchDraw();
}

let idCounter = 0;
function makeId( prefix: string ): string {
	idCounter += 1;
	return `${ prefix }-${ idCounter }-${ Date.now() }`;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export function ImageEditor( { src, toolkit }: ImageEditorProps ) {
	// ---- Image loading ----
	const [ imgEl, setImgEl ] = useState<HTMLImageElement | null>( null );
	const [ loadError, setLoadError ] = useState( false );
	const [ state, setState ] = useState<ImageState>( DEFAULT_STATE );
	const imgNodeRef = useRef<Konva.Image | null>( null );
	const fileRef = useRef<HTMLInputElement>( null );
	const stageWrapperRef = useRef<HTMLDivElement>( null );
	const stageRef = useRef<any>( null );

	// ---- Crop ----
	const [ crop, setCrop ] = useState<Crop | undefined>( undefined );
	const [ cropMode, setCropMode ] = useState( false );

	// ---- Text annotations ----
	const [ annotations, setAnnotations ] = useState<TextAnnotation[]>( [] );
	const textInputRef = useRef<HTMLInputElement>( null );

	// ---- Drawing tools ----
	const [ drawingTool, setDrawingTool ] = useState<DrawingTool>( null );
	const [ drawColor, setDrawColor ] = useState( '#ff0000' );
	const [ drawStrokeWidth, setDrawStrokeWidth ] = useState( 4 );
	const [ lines, setLines ] = useState<DrawingLine[]>( [] );
	const [ rects, setRects ] = useState<DrawingRect[]>( [] );
	const [ ellipses, setEllipses ] = useState<DrawingEllipse[]>( [] );
	const [ currentLine, setCurrentLine ] = useState<DrawingLine | null>( null );
	const [ currentRect, setCurrentRect ] = useState<DrawingRect | null>( null );
	const [ currentEllipse, setCurrentEllipse ] = useState<DrawingEllipse | null>( null );
	const isDrawingRef = useRef( false );
	const drawingStartRef = useRef<{ x: number; y: number } | null>( null );

	// ---- Zoom/pan ----
	const zoomPan = useZoomPan();

	// ---- Undo/redo (for image state only) ----
	const history = useHistory<ImageState>();

	// ---- Responsive canvas ----
	const canvasSize = useResponsiveCanvas( stageWrapperRef, TOOLBAR_APPROX_HEIGHT + FILTER_ROW_APPROX_HEIGHT + DRAWING_TOOLBAR_APPROX_HEIGHT );

	// ---- Save-to-media state ----
	const [ saving, setSaving ] = useState( false );
	const [ saveMessage, setSaveMessage ] = useState( '' );

	// ---- Helper: push state to history then update ----
	const updateState = useCallback( ( updater: ( prev: ImageState ) => ImageState ) => {
		setState( ( prev ) => {
			const next = updater( prev );
			history.push( prev );
			return next;
		} );
	}, [ history ] );

	// ---- Image loading ----
	const loadSrc = useCallback( ( url: string ) => {
		setLoadError( false );
		setAnnotations( [] );
		setLines( [] );
		setRects( [] );
		setEllipses( [] );
		setDrawingTool( null );
		setState( DEFAULT_STATE );
		history.clear();
		zoomPan.reset();
		const img = new window.Image();
		img.crossOrigin = 'anonymous';
		img.onload = () => setImgEl( img );
		img.onerror = () => setLoadError( true );
		img.src = url;
	}, [ history, zoomPan ] );

	useEffect( () => { if ( src ) loadSrc( src ); }, [ src, loadSrc ] );

	const handleFileChange = useCallback( ( e: React.ChangeEvent<HTMLInputElement> ) => {
		const file = e.target.files?.[ 0 ];
		if ( ! file ) return;
		loadSrc( URL.createObjectURL( file ) );
	}, [ loadSrc ] );

	// ---- Apply filters ----
	useEffect( () => {
		const node = imgNodeRef.current;
		if ( ! node ) return;
		try { applyFiltersToNode( node, state ); } catch { /* ignore */ }
	}, [ state ] );

	// ---- Fit to view when image loads ----
	useEffect( () => {
		if ( imgEl && canvasSize.width && canvasSize.height ) {
			zoomPan.fitToView( canvasSize.width, canvasSize.height, imgEl.width, imgEl.height );
		}
	}, [ imgEl, canvasSize.width, canvasSize.height, zoomPan ] );

	// ---- Computed image dimensions ----
	const stageWidth = imgEl ? Math.min( canvasSize.width, imgEl.width ) : STAGE_FALLBACK_WIDTH;
	const stageHeight = imgEl ? Math.round( ( imgEl.height / imgEl.width ) * stageWidth ) : STAGE_FALLBACK_HEIGHT;

	// ---- Actions ----
	const rotate = ( delta: number ) => updateState( ( s ) => ( {
		...s, rotation: ( s.rotation + delta + 360 ) % 360,
	} ) );

	const toggleCropMode = () => { setCropMode( ( v ) => ! v ); setCrop( undefined ); };

	const handleDownload = () => {
		const stage = stageRef.current;
		if ( ! stage ) return;
		const dataUrl = stage.toDataURL( { mimeType: 'image/png' } );
		const a = document.createElement( 'a' );
		a.href = dataUrl;
		a.download = 'media-studio-export.png';
		a.click();
	};

	const handleSaveToMedia = async () => {
		const stage = stageRef.current;
		if ( ! stage ) return;
		setSaving( true );
		setSaveMessage( '' );
		try {
			const dataUrl = stage.toDataURL( { mimeType: 'image/png' } );
			const nonce = ( window as any ).NVOOS_MEDIA_STUDIO?.nonce || '';
			const apiUrl = ( window as any ).NVOOS_MEDIA_STUDIO?.apiUrl || '';
			const res = await fetch( `${ apiUrl }/upload`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify( { image: dataUrl, filename: 'media-studio-export.png' } ),
			} );
			const json = await res.json();
			if ( res.ok ) {
				setSaveMessage( __( 'Saved to Media Library!', 'nvoos-media-studio' ) );
			} else {
				setSaveMessage( json?.message || __( 'Save failed.', 'nvoos-media-studio' ) );
			}
		} catch {
			setSaveMessage( __( 'Network error. Please try again.', 'nvoos-media-studio' ) );
		} finally {
			setSaving( false );
		}
	};

	const handleReset = () => {
		updateState( () => DEFAULT_STATE );
		setLines( [] );
		setRects( [] );
		setEllipses( [] );
		zoomPan.reset();
	};

	const setFilter = useCallback( <K extends keyof FilterState>( key: K, value: FilterState[ K ] ) => {
		updateState( ( s ) => ( { ...s, filters: { ...s.filters, [ key ]: value } } ) );
	}, [ updateState ] );

	// ---- Drawing handlers ----
	const getPointerPos = ( stage: any ) => {
		const pos = stage.getPointerPosition();
		if ( ! pos ) return null;
		return {
			x: ( pos.x - zoomPan.zoomPan.offsetX ) / zoomPan.zoomPan.scale,
			y: ( pos.y - zoomPan.zoomPan.offsetY ) / zoomPan.zoomPan.scale,
		};
	};

	const handleDrawingMouseDown = useCallback( ( e: any ) => {
		if ( ! drawingTool || drawingTool === 'pen' ) {
			// Pen tool handled in handlePenMouseDown
			return;
		}
		const stage = e.target.getStage();
		const pos = getPointerPos( stage );
		if ( ! pos ) return;
		isDrawingRef.current = true;
		drawingStartRef.current = { x: pos.x, y: pos.y };

		if ( drawingTool === 'rect' ) {
			setCurrentRect( { id: makeId( 'rect' ), x: pos.x, y: pos.y, width: 0, height: 0, color: drawColor, strokeWidth: drawStrokeWidth } );
		} else if ( drawingTool === 'circle' ) {
			setCurrentEllipse( { id: makeId( 'ellipse' ), x: pos.x, y: pos.y, radiusX: 0, radiusY: 0, color: drawColor, strokeWidth: drawStrokeWidth } );
		}
	}, [ drawingTool, drawColor, drawStrokeWidth, zoomPan.zoomPan ] );

	const handleDrawingMouseMove = useCallback( ( e: any ) => {
		if ( ! isDrawingRef.current || ! drawingTool ) return;
		const stage = e.target.getStage();
		const pos = getPointerPos( stage );
		if ( ! pos || ! drawingStartRef.current ) return;

		if ( drawingTool === 'rect' && currentRect ) {
			const sx = drawingStartRef.current.x;
			const sy = drawingStartRef.current.y;
			setCurrentRect( {
				...currentRect,
				x: Math.min( sx, pos.x ),
				y: Math.min( sy, pos.y ),
				width: Math.abs( pos.x - sx ),
				height: Math.abs( pos.y - sy ),
			} );
		} else if ( drawingTool === 'circle' && currentEllipse ) {
			const sx = drawingStartRef.current.x;
			const sy = drawingStartRef.current.y;
			setCurrentEllipse( {
				...currentEllipse,
				radiusX: Math.abs( pos.x - sx ) / 2,
				radiusY: Math.abs( pos.y - sy ) / 2,
				x: ( sx + pos.x ) / 2,
				y: ( sy + pos.y ) / 2,
			} );
		}
	}, [ drawingTool, currentRect, currentEllipse, zoomPan.zoomPan ] );

	const handleDrawingMouseUp = useCallback( () => {
		if ( ! isDrawingRef.current ) return;
		isDrawingRef.current = false;
		drawingStartRef.current = null;

		if ( currentRect ) {
			setRects( ( prev ) => [ ...prev, currentRect ] );
			setCurrentRect( null );
		}
		if ( currentEllipse ) {
			setEllipses( ( prev ) => [ ...prev, currentEllipse ] );
			setCurrentEllipse( null );
		}
	}, [ currentRect, currentEllipse ] );

	// ---- Pen (freehand) drawing ----
	const handlePenMouseDown = useCallback( ( e: any ) => {
		if ( drawingTool !== 'pen' ) return;
		const stage = e.target.getStage();
		const pos = getPointerPos( stage );
		if ( ! pos ) return;
		isDrawingRef.current = true;
		setCurrentLine( {
			id: makeId( 'line' ),
			points: [ pos.x, pos.y ],
			color: drawColor,
			strokeWidth: drawStrokeWidth,
		} );
	}, [ drawingTool, drawColor, drawStrokeWidth, zoomPan.zoomPan ] );

	const handlePenMouseMove = useCallback( ( e: any ) => {
		if ( ! isDrawingRef.current || drawingTool !== 'pen' || ! currentLine ) return;
		const stage = e.target.getStage();
		const pos = getPointerPos( stage );
		if ( ! pos ) return;
		setCurrentLine( ( prev ) => prev ? { ...prev, points: [ ...prev.points, pos.x, pos.y ] } : prev );
	}, [ drawingTool, currentLine, zoomPan.zoomPan ] );

	const handlePenMouseUp = useCallback( () => {
		if ( ! isDrawingRef.current || drawingTool !== 'pen' ) return;
		isDrawingRef.current = false;
		if ( currentLine && currentLine.points.length > 2 ) {
			setLines( ( prev ) => [ ...prev, currentLine ] );
		}
		setCurrentLine( null );
	}, [ drawingTool, currentLine ] );

	// Combined mouse handlers based on tool
	const handleCanvasMouseDown = useCallback( ( e: any ) => {
		if ( drawingTool === 'pen' ) handlePenMouseDown( e );
		else handleDrawingMouseDown( e );
	}, [ drawingTool, handlePenMouseDown, handleDrawingMouseDown ] );

	const handleCanvasMouseMove = useCallback( ( e: any ) => {
		if ( drawingTool === 'pen' ) handlePenMouseMove( e );
		else handleDrawingMouseMove( e );
	}, [ drawingTool, handlePenMouseMove, handleDrawingMouseMove ] );

	const handleCanvasMouseUp = useCallback( () => {
		if ( drawingTool === 'pen' ) handlePenMouseUp();
		else handleDrawingMouseUp();
	}, [ drawingTool, handlePenMouseUp, handleDrawingMouseUp ] );

	// Clear all drawings
	const clearDrawings = () => {
		setLines( [] );
		setRects( [] );
		setEllipses( [] );
		setCurrentLine( null );
		setCurrentRect( null );
		setCurrentEllipse( null );
	};

	// ---- Text annotation placement ----
	const handleStageClick = useCallback( ( e: any ) => {
		if ( drawingTool ) return; // Don't place text while drawing
		if ( cropMode ) return;
		const stage = e.target.getStage();
		if ( ! stage ) return;
		const pointer = stage.getPointerPosition();
		if ( ! pointer ) return;
		const zp = zoomPan.zoomPan;
		const id = makeId( 'anno' );
		setAnnotations( ( prev ) => [
			...prev,
			{ id, x: ( pointer.x - zp.offsetX ) / zp.scale, y: ( pointer.y - zp.offsetY ) / zp.scale, text: '', fontSize: 24, fill: '#ffffff', isSelected: true },
		] );
		setAnnotations( ( prev ) => prev.map( ( a ) => ( a.id === id ? { ...a, isSelected: true } : { ...a, isSelected: false } ) ) );
		setTimeout( () => {
			( document.querySelector( '.nvoos-ms-text-input' ) as HTMLInputElement | null )?.focus();
		}, 50 );
	}, [ drawingTool, cropMode, zoomPan.zoomPan ] );

	const handleTextInputKeyDown = useCallback( ( e: React.KeyboardEvent<HTMLInputElement> ) => {
		if ( e.key === 'Enter' ) ( e.target as HTMLInputElement ).blur();
	}, [] );
	const handleTextInputChange = useCallback( ( e: React.ChangeEvent<HTMLInputElement> ) => {
		setAnnotations( ( prev ) => prev.map( ( a ) => ( a.isSelected ? { ...a, text: e.target.value } : a ) ) );
	}, [] );
	const handleDeleteSelectedAnnotation = useCallback( () => {
		setAnnotations( ( prev ) => prev.filter( ( a ) => ! a.isSelected ) );
	}, [] );

	// ---- Keyboard shortcuts ----
	const shortcuts: Shortcut[] = useMemo( () => [
		{ key: 'z', ctrl: true, shift: false, label: 'Undo', handler: () => { const r = history.undo(); if ( r ) setState( r ); } },
		{ key: 'z', ctrl: true, shift: true, label: 'Redo', handler: () => { const r = history.redo(); if ( r ) setState( r ); } },
		{ key: 's', ctrl: true, shift: false, label: 'Download PNG', handler: handleDownload },
		{ key: 'r', ctrl: false, shift: false, label: 'Rotate CW', handler: () => rotate( 90 ) },
		{ key: 'Delete', ctrl: false, shift: false, label: 'Delete selection', handler: handleDeleteSelectedAnnotation },
		{ key: 'Escape', ctrl: false, shift: false, label: 'Escape', handler: () => {
			setDrawingTool( null );
			setAnnotations( ( prev ) => prev.map( ( a ) => ( { ...a, isSelected: false } ) ) );
			if ( cropMode ) setCropMode( false );
		} },
		{ key: '0', ctrl: false, shift: false, label: 'Fit to view', handler: () => {
			if ( imgEl ) zoomPan.fitToView( canvasSize.width, canvasSize.height, imgEl.width, imgEl.height );
		} },
		{ key: '=', ctrl: false, shift: false, label: 'Zoom in', handler: () => zoomPan.setScale( zoomPan.zoomPan.scale * 1.25 ) },
		{ key: '-', ctrl: false, shift: false, label: 'Zoom out', handler: () => zoomPan.setScale( zoomPan.zoomPan.scale / 1.25 ) },
	], [ history, rotate, handleDownload, handleDeleteSelectedAnnotation, cropMode, imgEl, zoomPan, canvasSize ] );

	useKeyboardShortcuts( shortcuts, ! cropMode );

	const zoomPercent = Math.round( zoomPan.zoomPan.scale * 100 );

	return (
		<div className="nvoos-ms-image-editor">
			{ /* ---- Main Toolbar ---- */ }
			<div className="nvoos-ms-toolbar" role="toolbar" aria-label={ __( 'Image editor toolbar', 'nvoos-media-studio' ) }>
				{ /* File */ }
				<label className="nvoos-ms-toolbar-btn nvoos-ms-upload-label" title={ __( 'Open image', 'nvoos-media-studio' ) }>
					📂
					<input ref={ fileRef } type="file" accept="image/*" className="nvoos-ms-hidden-input" onChange={ handleFileChange } />
				</label>
				<span className="nvoos-ms-toolbar-sep" aria-hidden="true" />

				{ /* Undo/Redo */ }
				<button type="button" className="nvoos-ms-toolbar-btn" onClick={ () => { const r = history.undo(); if ( r ) setState( r ); } }
					disabled={ ! history.canUndo } title="Undo (Ctrl+Z)" aria-label={ __( 'Undo', 'nvoos-media-studio' ) }>↩</button>
				<button type="button" className="nvoos-ms-toolbar-btn" onClick={ () => { const r = history.redo(); if ( r ) setState( r ); } }
					disabled={ ! history.canRedo } title="Redo (Ctrl+Shift+Z)" aria-label={ __( 'Redo', 'nvoos-media-studio' ) }>↪</button>
				<span className="nvoos-ms-toolbar-sep" aria-hidden="true" />

				{ /* Transform */ }
				<button type="button" className="nvoos-ms-toolbar-btn" onClick={ () => rotate( -90 ) }
					title="Rotate CCW" aria-label={ __( 'Rotate counter-clockwise', 'nvoos-media-studio' ) }>↺</button>
				<button type="button" className="nvoos-ms-toolbar-btn" onClick={ () => rotate( 90 ) }
					title="Rotate CW (R)" aria-label={ __( 'Rotate clockwise', 'nvoos-media-studio' ) }>↻</button>
				<button type="button" className={ 'nvoos-ms-toolbar-btn' + ( state.flipH ? ' nvoos-ms-toolbar-btn--active' : '' ) }
					onClick={ () => updateState( ( s ) => ( { ...s, flipH: ! s.flipH } ) ) }
					aria-pressed={ state.flipH } title="Flip H" aria-label={ __( 'Flip horizontal', 'nvoos-media-studio' ) }>⇄</button>
				<button type="button" className={ 'nvoos-ms-toolbar-btn' + ( state.flipV ? ' nvoos-ms-toolbar-btn--active' : '' ) }
					onClick={ () => updateState( ( s ) => ( { ...s, flipV: ! s.flipV } ) ) }
					aria-pressed={ state.flipV } title="Flip V" aria-label={ __( 'Flip vertical', 'nvoos-media-studio' ) }>⇅</button>
				<span className="nvoos-ms-toolbar-sep" aria-hidden="true" />

				{ /* Crop & Text */ }
				<button type="button" className={ 'nvoos-ms-toolbar-btn' + ( cropMode ? ' nvoos-ms-toolbar-btn--active' : '' ) }
					onClick={ toggleCropMode } aria-pressed={ cropMode }
					title={ __( 'Crop', 'nvoos-media-studio' ) } aria-label={ __( 'Crop', 'nvoos-media-studio' ) }>✂</button>
				<button type="button" className={ 'nvoos-ms-toolbar-btn' + ( ! drawingTool && annotations.some( ( a ) => a.isSelected ) ? ' nvoos-ms-toolbar-btn--active' : '' ) }
					onClick={ () => { setDrawingTool( null ); setCropMode( false ); } }
					title={ __( 'Select/Move', 'nvoos-media-studio' ) } aria-label={ __( 'Select', 'nvoos-media-studio' ) }>↖</button>
				<span className="nvoos-ms-toolbar-sep" aria-hidden="true" />

				{ /* Zoom */ }
				<button type="button" className="nvoos-ms-toolbar-btn" onClick={ () => zoomPan.setScale( zoomPan.zoomPan.scale * 1.25 ) }
					title="Zoom in (+)" aria-label={ __( 'Zoom in', 'nvoos-media-studio' ) }>🔍+</button>
				<button type="button" className="nvoos-ms-toolbar-btn" onClick={ () => zoomPan.setScale( zoomPan.zoomPan.scale / 1.25 ) }
					title="Zoom out (-)" aria-label={ __( 'Zoom out', 'nvoos-media-studio' ) }>🔍-</button>
				<button type="button" className="nvoos-ms-toolbar-btn"
					onClick={ () => { if ( imgEl ) zoomPan.fitToView( canvasSize.width, canvasSize.height, imgEl.width, imgEl.height ); } }
					title="Fit to view (0)" aria-label={ __( 'Fit to view', 'nvoos-media-studio' ) }>⊞</button>
				<button type="button" className="nvoos-ms-toolbar-btn" onClick={ zoomPan.reset }
					title="Reset zoom (100%)" aria-label={ __( 'Reset zoom', 'nvoos-media-studio' ) }>1:1</button>
				<span className="nvoos-ms-zoom-label">{ zoomPercent }%</span>
				<span className="nvoos-ms-toolbar-sep" aria-hidden="true" />

				{ /* Actions */ }
				<button type="button" className="nvoos-ms-toolbar-btn" onClick={ handleReset }
					title={ __( 'Reset all adjustments', 'nvoos-media-studio' ) } aria-label={ __( 'Reset', 'nvoos-media-studio' ) }>↺</button>
				<button type="button" className="nvoos-ms-toolbar-btn nvoos-ms-toolbar-btn--primary" onClick={ handleDownload }
					disabled={ ! imgEl } title="Download PNG (Ctrl+S)" aria-label={ __( 'Download PNG', 'nvoos-media-studio' ) }>↓</button>
				<button type="button" className="nvoos-ms-toolbar-btn nvoos-ms-toolbar-btn--primary" onClick={ handleSaveToMedia }
					disabled={ ! imgEl || saving } title={ __( 'Save to Media Library', 'nvoos-media-studio' ) }
					aria-label={ __( 'Save to Media Library', 'nvoos-media-studio' ) }>
					{ saving ? '…' : '💾' }
				</button>
				{ saveMessage && <span className="nvoos-ms-save-msg" aria-live="polite">{ saveMessage }</span> }
			</div>

			{ /* ---- Filter controls ---- */ }
			{ imgEl && (
				<div className="nvoos-ms-filter-row" role="group" aria-label={ __( 'Image filters', 'nvoos-media-studio' ) }>
					<label className="nvoos-ms-slider-label" title={ __( 'Brightness', 'nvoos-media-studio' ) }>
						<span className="nvoos-ms-slider-icon">☀</span>
						<input type="range" min={ -1 } max={ 1 } step={ 0.05 } value={ state.filters.brightness }
							onChange={ ( e ) => setFilter( 'brightness', parseFloat( e.target.value ) ) } className="nvoos-ms-slider" />
					</label>
					<label className="nvoos-ms-slider-label" title={ __( 'Contrast', 'nvoos-media-studio' ) }>
						<span className="nvoos-ms-slider-icon">◑</span>
						<input type="range" min={ -1 } max={ 1 } step={ 0.05 } value={ state.filters.contrast }
							onChange={ ( e ) => setFilter( 'contrast', parseFloat( e.target.value ) ) } className="nvoos-ms-slider" />
					</label>
					<label className="nvoos-ms-slider-label" title={ __( 'Saturation', 'nvoos-media-studio' ) }>
						<span className="nvoos-ms-slider-icon">🌈</span>
						<input type="range" min={ -1 } max={ 1 } step={ 0.05 } value={ state.filters.saturation }
							onChange={ ( e ) => setFilter( 'saturation', parseFloat( e.target.value ) ) } className="nvoos-ms-slider" />
					</label>
					<label className="nvoos-ms-slider-label" title={ __( 'Hue', 'nvoos-media-studio' ) }>
						<span className="nvoos-ms-slider-icon">🎨</span>
						<input type="range" min={ 0 } max={ 360 } step={ 1 } value={ state.filters.hue }
							onChange={ ( e ) => setFilter( 'hue', parseInt( e.target.value, 10 ) ) } className="nvoos-ms-slider" />
					</label>
					<label className="nvoos-ms-slider-label" title={ __( 'Blur', 'nvoos-media-studio' ) }>
						<span className="nvoos-ms-slider-icon">💧</span>
						<input type="range" min={ 0 } max={ 20 } step={ 0.5 } value={ state.filters.blur }
							onChange={ ( e ) => setFilter( 'blur', parseFloat( e.target.value ) ) } className="nvoos-ms-slider" />
					</label>
					<span className="nvoos-ms-toolbar-sep" aria-hidden="true" />
					<button type="button" className={ 'nvoos-ms-toolbar-btn nvoos-ms-toggle-btn' + ( state.filters.grayscale ? ' nvoos-ms-toolbar-btn--active' : '' ) }
						onClick={ () => setFilter( 'grayscale', ! state.filters.grayscale ) } aria-pressed={ state.filters.grayscale }
						title={ __( 'Grayscale', 'nvoos-media-studio' ) }>G</button>
					<button type="button" className={ 'nvoos-ms-toolbar-btn nvoos-ms-toggle-btn' + ( state.filters.sepia ? ' nvoos-ms-toolbar-btn--active' : '' ) }
						onClick={ () => setFilter( 'sepia', ! state.filters.sepia ) } aria-pressed={ state.filters.sepia }
						title={ __( 'Sepia', 'nvoos-media-studio' ) }>S</button>
					<button type="button" className={ 'nvoos-ms-toolbar-btn nvoos-ms-toggle-btn' + ( state.filters.invert ? ' nvoos-ms-toolbar-btn--active' : '' ) }
						onClick={ () => setFilter( 'invert', ! state.filters.invert ) } aria-pressed={ state.filters.invert }
						title={ __( 'Invert', 'nvoos-media-studio' ) }>I</button>
				</div>
			) }

			{ /* ---- Drawing toolbar ---- */ }
			{ imgEl && ! cropMode && (
				<div className="nvoos-ms-drawing-toolbar" role="group" aria-label={ __( 'Drawing tools', 'nvoos-media-studio' ) }>
					<button type="button" className={ 'nvoos-ms-toolbar-btn' + ( drawingTool === 'pen' ? ' nvoos-ms-toolbar-btn--active' : '' ) }
						onClick={ () => setDrawingTool( drawingTool === 'pen' ? null : 'pen' ) }
						aria-pressed={ drawingTool === 'pen' } title={ __( 'Pen', 'nvoos-media-studio' ) }>✏</button>
					<button type="button" className={ 'nvoos-ms-toolbar-btn' + ( drawingTool === 'rect' ? ' nvoos-ms-toolbar-btn--active' : '' ) }
						onClick={ () => setDrawingTool( drawingTool === 'rect' ? null : 'rect' ) }
						aria-pressed={ drawingTool === 'rect' } title={ __( 'Rectangle', 'nvoos-media-studio' ) }>▭</button>
					<button type="button" className={ 'nvoos-ms-toolbar-btn' + ( drawingTool === 'circle' ? ' nvoos-ms-toolbar-btn--active' : '' ) }
						onClick={ () => setDrawingTool( drawingTool === 'circle' ? null : 'circle' ) }
						aria-pressed={ drawingTool === 'circle' } title={ __( 'Circle', 'nvoos-media-studio' ) }>◯</button>
					<span className="nvoos-ms-toolbar-sep" aria-hidden="true" />
					{ /* Color picker */ }
					{ DRAWING_COLORS.map( ( c ) => (
						<button key={ c } type="button"
							className={ 'nvoos-ms-color-btn' + ( drawColor === c ? ' nvoos-ms-color-btn--selected' : '' ) }
							style={ { backgroundColor: c } }
							onClick={ () => setDrawColor( c ) }
							aria-label={ `Color ${ c }` } />
					) ) }
					<span className="nvoos-ms-toolbar-sep" aria-hidden="true" />
					{ /* Stroke width */ }
					{ STROKE_WIDTHS.map( ( w ) => (
						<button key={ w } type="button"
							className={ 'nvoos-ms-toolbar-btn' + ( drawStrokeWidth === w ? ' nvoos-ms-toolbar-btn--active' : '' ) }
							onClick={ () => setDrawStrokeWidth( w ) }
							aria-label={ `Stroke width ${ w }px` }
							style={ { fontSize: `${ Math.min( w + 6, 14 ) }px`, fontWeight: 'bold' } }>●</button>
					) ) }
					<span className="nvoos-ms-toolbar-sep" aria-hidden="true" />
					<button type="button" className="nvoos-ms-toolbar-btn" onClick={ clearDrawings }
						title={ __( 'Clear all drawings', 'nvoos-media-studio' ) }
						disabled={ lines.length === 0 && rects.length === 0 && ellipses.length === 0 }>
						🗑
					</button>
				</div>
			) }

			{ /* ---- Text input for active annotation ---- */ }
			{ annotations.some( ( a ) => a.isSelected && a.text === '' ) && (
				<div className="nvoos-ms-text-input-bar" role="group" aria-label={ __( 'Text annotation', 'nvoos-media-studio' ) }>
					<input type="text" className="nvoos-ms-text-input"
						placeholder={ __( 'Type annotation text and press Enter…', 'nvoos-media-studio' ) }
						onKeyDown={ handleTextInputKeyDown } onChange={ handleTextInputChange } />
					<button type="button" className="nvoos-ms-toolbar-btn" onClick={ handleDeleteSelectedAnnotation }
						aria-label={ __( 'Delete annotation', 'nvoos-media-studio' ) }>✕</button>
				</div>
			) }

			{ /* ---- Canvas area ---- */ }
			{ loadError ? (
				<p className="nvoos-ms-error" role="alert">
					{ __( 'Failed to load image. Check the URL or try another file.', 'nvoos-media-studio' ) }
				</p>
			) : cropMode && imgEl ? (
				<div className="nvoos-ms-crop-overlay">
					<ReactCrop crop={ crop } onChange={ ( c ) => setCrop( c ) } aspect={ undefined }>
						<img src={ imgEl.src } alt={ __( 'Crop preview', 'nvoos-media-studio' ) }
							style={ { maxWidth: stageWidth, transform: `rotate(${ state.rotation }deg) scaleX(${ state.flipH ? -1 : 1 }) scaleY(${ state.flipV ? -1 : 1 })` } } />
					</ReactCrop>
					<button type="button" className="nvoos-ms-toolbar-btn nvoos-ms-crop-done" onClick={ () => setCropMode( false ) }>
						{ __( 'Done', 'nvoos-media-studio' ) }
					</button>
				</div>
			) : (
				<div className="nvoos-ms-stage-wrapper" ref={ stageWrapperRef }>
					<Stage
						ref={ stageRef }
						width={ stageWidth }
						height={ stageHeight }
						scaleX={ zoomPan.zoomPan.scale }
						scaleY={ zoomPan.zoomPan.scale }
						x={ zoomPan.zoomPan.offsetX }
						y={ zoomPan.zoomPan.offsetY }
						onWheel={ ( e ) => zoomPan.handleWheel( e.target.getStage(), e ) }
						onMouseDown={ ( e ) => {
							if ( drawingTool ) { handleCanvasMouseDown( e ); return; }
							if ( zoomPan.isPanKey( e ) ) {
								const stage = e.target.getStage();
								if ( stage ) { stage.draggable( true ); stage.startDrag(); }
							} else if ( ! drawingTool ) {
								handleStageClick( e );
							}
						} }
						onMouseMove={ handleCanvasMouseMove }
						onMouseUp={ ( e ) => {
							handleCanvasMouseUp();
							const stage = e.target.getStage();
							if ( stage ) stage.draggable( false );
						} }
						onTap={ ( e ) => { if ( ! drawingTool ) handleStageClick( e ); } }
						draggable={ false }
					>
						{ /* Image layer */ }
						<Layer>
							{ imgEl ? (
								<KonvaImage
									ref={ ( node ) => {
										imgNodeRef.current = node;
										if ( node ) { try { applyFiltersToNode( node, state ); } catch { /* ignore */ } }
									} }
									image={ imgEl }
									x={ stageWidth / 2 } y={ stageHeight / 2 }
									width={ stageWidth } height={ stageHeight }
									rotation={ state.rotation }
									offsetX={ stageWidth / 2 } offsetY={ stageHeight / 2 }
									scaleX={ state.flipH ? -1 : 1 } scaleY={ state.flipV ? -1 : 1 }
									listening={ ! drawingTool }
								/>
							) : null }
						</Layer>

						{ /* Drawing layer */ }
						<Layer>
							{ lines.map( ( l ) => (
								<KonvaLine key={ l.id } points={ l.points } stroke={ l.color } strokeWidth={ l.strokeWidth }
									tension={ 0.5 } lineCap="round" lineJoin="round" globalCompositeOperation="source-over" />
							) ) }
							{ rects.map( ( r ) => (
								<KonvaRect key={ r.id } x={ r.x } y={ r.y } width={ r.width } height={ r.height }
									stroke={ r.color } strokeWidth={ r.strokeWidth } />
							) ) }
							{ ellipses.map( ( e ) => (
								<KonvaEllipse key={ e.id } x={ e.x } y={ e.y } radiusX={ e.radiusX } radiusY={ e.radiusY }
									stroke={ e.color } strokeWidth={ e.strokeWidth } />
							) ) }
							{ /* Current drawing preview */ }
							{ currentLine && (
								<KonvaLine points={ currentLine.points } stroke={ currentLine.color } strokeWidth={ currentLine.strokeWidth }
									tension={ 0.5 } lineCap="round" lineJoin="round" />
							) }
							{ currentRect && (
								<KonvaRect x={ currentRect.x } y={ currentRect.y } width={ currentRect.width } height={ currentRect.height }
									stroke={ currentRect.color } strokeWidth={ currentRect.strokeWidth } dash={ [ 4, 4 ] } />
							) }
							{ currentEllipse && (
								<KonvaEllipse x={ currentEllipse.x } y={ currentEllipse.y } radiusX={ currentEllipse.radiusX } radiusY={ currentEllipse.radiusY }
									stroke={ currentEllipse.color } strokeWidth={ currentEllipse.strokeWidth } dash={ [ 4, 4 ] } />
							) }
						</Layer>

						{ /* Annotations layer */ }
						<Layer>
							{ annotations.map( ( a ) => (
								<KonvaText key={ a.id } text={ a.text || __( '(text)', 'nvoos-media-studio' ) }
									x={ a.x } y={ a.y } fontSize={ a.fontSize } fill={ a.fill }
									draggable={ ! drawingTool }
									onClick={ () => { if ( ! drawingTool ) setAnnotations( ( prev ) => prev.map( ( an ) => ( { ...an, isSelected: an.id === a.id } ) ) ); } }
									onDblClick={ () => { setAnnotations( ( prev ) => prev.map( ( an ) => ( an.id === a.id ? { ...an, text: '' } : an ) ) ); setTimeout( () => ( document.querySelector( '.nvoos-ms-text-input' ) as HTMLInputElement | null )?.focus(), 50 ); } }
									onDragEnd={ ( e ) => { setAnnotations( ( prev ) => prev.map( ( an ) => an.id === a.id ? { ...an, x: e.target.x(), y: e.target.y() } : an ) ); } }
								/>
							) ) }
						</Layer>
					</Stage>

					{ ! imgEl && (
						<div className="nvoos-ms-empty-state">
							<p>{ __( 'Open an image file or pass a src URL via the shortcode.', 'nvoos-media-studio' ) }</p>
							{ toolkit && <p className="nvoos-ms-toolkit-label">{ __( 'Toolkit: ', 'nvoos-media-studio' ) }{ toolkit }</p> }
						</div>
					) }
					{ drawingTool && (
						<div className="nvoos-ms-text-tool-hint" aria-live="polite">
							{ drawingTool === 'pen' ? __( 'Draw freely on the canvas', 'nvoos-media-studio' ) :
								drawingTool === 'rect' ? __( 'Drag to draw a rectangle', 'nvoos-media-studio' ) :
								__( 'Drag to draw a circle', 'nvoos-media-studio' ) }
						</div>
					) }
				</div>
			) }
		</div>
	);
}
