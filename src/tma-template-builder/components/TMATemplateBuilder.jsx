/**
 * TMATemplateBuilder – WordPress admin component
 *
 * Renders a card-grid template picker with drag-to-reorder (via @dnd-kit/sortable)
 * and a live iframe preview pane. Aligned with the Pro Workflow Builder patterns:
 *  - @wordpress/element for React hooks
 *  - @wordpress/i18n for translations
 *  - @dnd-kit/sortable for drag-and-drop card ordering
 *
 * React Cosmos fixtures for each template are in ../fixtures/.
 *
 * @package WP_MCP_AI
 * @since   1.1.3
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	DndContext,
	closestCenter,
	PointerSensor,
	KeyboardSensor,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import {
	SortableContext,
	sortableKeyboardCoordinates,
	rectSortingStrategy,
	useSortable,
	arrayMove,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

/**
 * Toolkit badge colours matching the PHP template accent_color definitions.
 *
 * @type {Record<string,string>}
 */
const TOOLKIT_COLORS = {
	chat_channels:   '#4CAF50',
	ecommerce:       '#9c27b0',
	crm:             '#e65100',
	analytics:       '#00796b',
	calendar_booking:'#1565c0',
};

/* -------------------------------------------------------------------------- */
/*  Sortable template card                                                      */
/* -------------------------------------------------------------------------- */

/**
 * @param {Object}   props
 * @param {Object}   props.template      Template metadata from REST API.
 * @param {boolean}  props.isActive      Whether this template is selected.
 * @param {boolean}  props.isPreviewing  Whether this template's preview is open.
 * @param {Function} props.onSelect      Called when user clicks "Select".
 * @param {Function} props.onPreview     Called when user clicks "Preview".
 */
const SortableTemplateCard = ( { template, isActive, isPreviewing, onSelect, onPreview } ) => {
	const {
		attributes,
		listeners,
		setNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable( { id: template.slug } );

	const style = {
		transform: CSS.Transform.toString( transform ),
		transition,
		opacity: isDragging ? 0.5 : 1,
		'--card-accent': template.accent_color || '#2481cc',
	};

	const toolkitColor = TOOLKIT_COLORS[ template.toolkit ] || ( template.accent_color || '#2481cc' );

	return (
		<div
			ref={ setNodeRef }
			style={ style }
			className={ `tma-tpl-card${ isActive ? ' tma-tpl-card--active' : '' }${ isDragging ? ' tma-tpl-card--dragging' : '' }` }
		>
			{ /* Drag handle */ }
			<div
				className="tma-tpl-card__drag-handle"
				{ ...listeners }
				{ ...attributes }
				title={ __( 'Drag to reorder', 'mcp-ai-wpoos-pro' ) }
			>
				<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
					<circle cx="9" cy="5" r="2"/><circle cx="15" cy="5" r="2"/>
					<circle cx="9" cy="12" r="2"/><circle cx="15" cy="12" r="2"/>
					<circle cx="9" cy="19" r="2"/><circle cx="15" cy="19" r="2"/>
				</svg>
			</div>

			<div className="tma-tpl-card__icon">{ template.icon }</div>

			<div className="tma-tpl-card__body">
				<div className="tma-tpl-card__name">{ template.name }</div>
				<div className="tma-tpl-card__desc">{ template.description }</div>
				{ template.toolkit && (
					<span
						className="tma-tpl-card__badge"
						style={ { background: toolkitColor } }
					>
						{ template.toolkit.replace( /_/g, ' ' ) }
					</span>
				) }
			</div>

			<div className="tma-tpl-card__actions">
				<button
					type="button"
					className={ `tma-tpl-btn${ isActive ? ' tma-tpl-btn--selected' : ' tma-tpl-btn--select' }` }
					onClick={ () => onSelect( template.slug ) }
					disabled={ isActive }
				>
					{ isActive
						? __( '✓ Selected', 'mcp-ai-wpoos-pro' )
						: __( 'Select', 'mcp-ai-wpoos-pro' ) }
				</button>
				<button
					type="button"
					className={ `tma-tpl-btn tma-tpl-btn--preview${ isPreviewing ? ' tma-tpl-btn--preview-active' : '' }` }
					onClick={ () => onPreview( template.slug ) }
				>
					{ isPreviewing
						? __( '✕ Close', 'mcp-ai-wpoos-pro' )
						: __( '👁 Preview', 'mcp-ai-wpoos-pro' ) }
				</button>
			</div>
		</div>
	);
};

/* -------------------------------------------------------------------------- */
/*  Preview pane                                                               */
/* -------------------------------------------------------------------------- */

/**
 * Renders an iframe pointed at the live Mini App endpoint with a
 * `?tma_preview=<slug>` param so the controller renders that specific template
 * without changing the stored setting.
 *
 * @param {Object} props
 * @param {string} props.slug           Template slug to preview.
 * @param {string} props.previewBaseUrl Base URL of the Mini App REST endpoint.
 */
const PreviewPane = ( { slug, previewBaseUrl } ) => {
	const url = previewBaseUrl
		? `${ previewBaseUrl }?tma_preview=${ encodeURIComponent( slug ) }`
		: null;

	return (
		<div className="tma-tpl-preview">
			<div className="tma-tpl-preview__header">
				<span className="tma-tpl-preview__label">
					{ __( 'Live Preview', 'mcp-ai-wpoos-pro' ) }
					<span className="tma-tpl-preview__slug"> – { slug }</span>
				</span>
				<span className="tma-tpl-preview__hint">
					{ __( 'Telegram WebView dimensions (390 × 844 px)', 'mcp-ai-wpoos-pro' ) }
				</span>
			</div>
			{ url ? (
				<div className="tma-tpl-preview__frame-wrap">
					{ /* eslint-disable-next-line jsx-a11y/iframe-has-title */ }
					<iframe
						className="tma-tpl-preview__frame"
						src={ url }
						title={ `${ slug } ${ __( 'template preview', 'mcp-ai-wpoos-pro' ) }` }
						sandbox="allow-scripts allow-same-origin allow-forms"
					/>
				</div>
			) : (
				<p className="tma-tpl-preview__no-url description">
					{ __( 'Preview URL not available. Save your bot token first.', 'mcp-ai-wpoos-pro' ) }
				</p>
			) }
		</div>
	);
};

/* -------------------------------------------------------------------------- */
/*  Main component                                                             */
/* -------------------------------------------------------------------------- */

/**
 * TMATemplateBuilder
 *
 * @param {Object}  props
 * @param {Object}  props.config         Config injected from PHP (see index.jsx).
 * @param {string}  [props.connectionId] Optional – per-connection override mode.
 * @param {string}  [props.initialSlug]  Overrides config.activeTemplate in per-connection mode.
 * @param {boolean} [props.embeddedMode] Compact mode (no save button) for the connection form.
 */
export const TMATemplateBuilder = ( {
	config,
	connectionId = null,
	initialSlug  = null,
	embeddedMode = false,
} ) => {
	const [ templates,   setTemplates   ] = useState( [] );
	const [ order,       setOrder       ] = useState( [] ); // slugs in display order
	const [ loading,     setLoading     ] = useState( true );
	const [ error,       setError       ] = useState( null );
	const [ activeSlug,  setActiveSlug  ] = useState( initialSlug ?? config.activeTemplate ?? 'default' );
	const [ previewSlug, setPreviewSlug ] = useState( null );
	const [ saving,      setSaving      ] = useState( false );
	const [ saveStatus,  setSaveStatus  ] = useState( null ); // 'success' | 'error'

	/* @dnd-kit sensors – pointer for mouse/touch, keyboard for a11y. */
	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 6 } } ),
		useSensor( KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates } ),
	);

	/* ── Fetch template metadata ── */
	useEffect( () => {
		if ( ! config.templatesUrl ) {
			setLoading( false );
			return;
		}
		fetch( config.templatesUrl, { headers: { 'X-WP-Nonce': config.nonce } } )
			.then( ( r ) => r.json() )
			.then( ( data ) => {
				const list = Array.isArray( data ) ? data : ( data.templates ?? [] );
				setTemplates( list );
				setOrder( list.map( ( t ) => t.slug ) );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( err.message );
				setLoading( false );
			} );
	}, [ config.templatesUrl, config.nonce ] );

	/* ── Drag end ── */
	const handleDragEnd = useCallback( ( event ) => {
		const { active, over } = event;
		if ( over && active.id !== over.id ) {
			setOrder( ( prev ) => {
				const oldIndex = prev.indexOf( active.id );
				const newIndex = prev.indexOf( over.id );
				return arrayMove( prev, oldIndex, newIndex );
			} );
		}
	}, [] );

	/* ── Select template ── */
	const handleSelect = useCallback( ( slug ) => {
		setActiveSlug( slug );
		// In embedded mode, write the value into the hidden input consumed by the
		// parent PHP form so it is submitted with the rest of the connection data.
		if ( embeddedMode ) {
			const hiddenInput = document.getElementById( 'telegram_mini_app_template' );
			if ( hiddenInput ) {
				hiddenInput.value = slug;
			}
		}
	}, [ embeddedMode ] );

	/* ── Toggle preview pane ── */
	const handlePreview = useCallback( ( slug ) => {
		setPreviewSlug( ( prev ) => ( prev === slug ? null : slug ) );
	}, [] );

	/* ── Save global template via REST ── */
	const saveGlobal = useCallback( async () => {
		if ( ! config.saveUrl ) {
			return;
		}
		setSaving( true );
		setSaveStatus( null );
		try {
			const res = await fetch( config.saveUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
				body:    JSON.stringify( { template: activeSlug } ),
			} );
			const data = await res.json();
			setSaveStatus( data.success !== false ? 'success' : 'error' );
		} catch {
			setSaveStatus( 'error' );
		} finally {
			setSaving( false );
			setTimeout( () => setSaveStatus( null ), 3000 );
		}
	}, [ config.saveUrl, config.nonce, activeSlug ] );

	/* ── States ── */
	if ( loading ) {
		return (
			<div className="tma-tpl-loading">
				<span className="spinner is-active" style={ { float: 'none', margin: '0 8px 0 0', verticalAlign: 'middle' } } />
				{ __( 'Loading templates…', 'mcp-ai-wpoos-pro' ) }
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="notice notice-error inline" style={ { margin: 0 } }>
				<p>{ __( 'Could not load templates:', 'mcp-ai-wpoos-pro' ) } { error }</p>
			</div>
		);
	}

	/* Build ordered template list for rendering. */
	const orderedTemplates = order
		.map( ( slug ) => templates.find( ( t ) => t.slug === slug ) )
		.filter( Boolean );

	return (
		<div
			className={ `tma-template-builder${ embeddedMode ? ' tma-template-builder--embedded' : '' }` }
			data-connection={ connectionId ?? '' }
		>
			{ ! embeddedMode && (
				<div className="tma-tpl-header">
					<h3 style={ { marginTop: 0 } }>{ __( '📱 Mini App Template Builder', 'mcp-ai-wpoos-pro' ) }</h3>
					<p className="description">
						{ __(
							'Choose a pre-built template for your Telegram Mini App. Each template is optimised for a specific Pro toolkit. Individual bot connections can override this global default. Drag cards to reorder.',
							'mcp-ai-wpoos-pro'
						) }
					</p>
				</div>
			) }

			<DndContext
				sensors={ sensors }
				collisionDetection={ closestCenter }
				onDragEnd={ handleDragEnd }
			>
				<SortableContext items={ order } strategy={ rectSortingStrategy }>
					<div className="tma-tpl-grid">
						{ orderedTemplates.map( ( tpl ) => (
							<SortableTemplateCard
								key={ tpl.slug }
								template={ tpl }
								isActive={ activeSlug === tpl.slug }
								isPreviewing={ previewSlug === tpl.slug }
								onSelect={ handleSelect }
								onPreview={ handlePreview }
							/>
						) ) }
					</div>
				</SortableContext>
			</DndContext>

			{ previewSlug && (
				<PreviewPane
					slug={ previewSlug }
					previewBaseUrl={ config.previewBaseUrl }
				/>
			) }

			{ ! embeddedMode && (
				<div className="tma-tpl-footer">
					<button
						type="button"
						className="button button-primary"
						onClick={ saveGlobal }
						disabled={ saving }
					>
						{ saving
							? __( 'Saving…', 'mcp-ai-wpoos-pro' )
							: __( 'Save Template', 'mcp-ai-wpoos-pro' ) }
					</button>
					{ saveStatus === 'success' && (
						<span className="tma-tpl-save-msg tma-tpl-save-msg--ok">
							✓ { __( 'Template saved successfully.', 'mcp-ai-wpoos-pro' ) }
						</span>
					) }
					{ saveStatus === 'error' && (
						<span className="tma-tpl-save-msg tma-tpl-save-msg--err">
							✕ { __( 'Could not save template. Please try again.', 'mcp-ai-wpoos-pro' ) }
						</span>
					) }
				</div>
			) }
		</div>
	);
};

export default TMATemplateBuilder;
