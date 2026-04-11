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

import { useState, useEffect, useCallback, useRef } from 'react';
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
	health_wellness: '#2e7d32',
};

/* -------------------------------------------------------------------------- */
/*  Sortable template card                                                      */
/* -------------------------------------------------------------------------- */

/**
 * @param {Object}   props
 * @param {Object}   props.template      Template metadata from REST API.
 * @param {boolean}  props.isActive      Whether this template is selected.
 * @param {boolean}  props.isPreviewing  Whether this template's preview is open.
 * @param {boolean}  props.isEditing     Whether this template's editor is open.
 * @param {Function} props.onSelect      Called when user clicks "Select".
 * @param {Function} props.onPreview     Called when user clicks "Preview".
 * @param {Function} props.onEdit        Called when user clicks "Edit".
 */
const SortableTemplateCard = ( { template, isActive, isPreviewing, isEditing, onSelect, onPreview, onEdit } ) => {
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
				{ template.has_customizations && (
					<span className="tma-tpl-card__badge tma-tpl-card__badge--custom">
						{ __( '✎ Customized', 'mcp-ai-wpoos-pro' ) }
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
				<button
					type="button"
					className={ `tma-tpl-btn tma-tpl-btn--edit${ isEditing ? ' tma-tpl-btn--edit-active' : '' }` }
					onClick={ () => onEdit( template.slug ) }
					title={ __( 'Edit template appearance and inject custom CSS', 'mcp-ai-wpoos-pro' ) }
				>
					{ isEditing
						? __( '✕ Close', 'mcp-ai-wpoos-pro' )
						: __( '✎ Edit', 'mcp-ai-wpoos-pro' ) }
				</button>
			</div>
		</div>
	);
};

/* -------------------------------------------------------------------------- */
/*  Template editor panel                                                      */
/* -------------------------------------------------------------------------- */

/**
 * Inline editor panel that lets admins customise a template's appearance and
 * inject custom CSS that is applied every time the Mini App is rendered.
 *
 * Tabs:
 *  - Appearance: name, description, icon (emoji), accent colour
 *  - Custom CSS: free-form CSS textarea
 *
 * @param {Object}   props
 * @param {Object}   props.template      Template metadata (from REST list).
 * @param {Object}   props.config        Builder config (nonce, customizeUrl).
 * @param {Function} props.onClose       Called when the panel is dismissed.
 * @param {Function} props.onSaved       Called with updated template data after save.
 * @param {Function} props.onReset       Called with updated template data after reset.
 */
const TemplateEditor = ( { template, config, onClose, onSaved, onReset } ) => {
	const [ activeTab, setActiveTab   ] = useState( 'appearance' );
	const [ draft,     setDraft       ] = useState( {
		name:         template.name         || '',
		description:  template.description  || '',
		icon:         template.icon         || '',
		accent_color: template.accent_color || '',
		custom_css:   template.custom_css   || '',
	} );
	const [ saving,    setSaving      ] = useState( false );
	const [ resetting, setResetting   ] = useState( false );
	const [ status,    setStatus      ] = useState( null ); // { type: 'success'|'error', msg: string }
	const cssRef = useRef( null );

	// Keep draft in sync if parent refreshes template data (e.g. after save or reset).
	// `template` object reference changes whenever the parent calls updateTemplate(), so
	// this correctly re-initialises the draft to the freshly-saved/reset values.
	useEffect( () => {
		setDraft( {
			name:         template.name         || '',
			description:  template.description  || '',
			icon:         template.icon         || '',
			accent_color: template.accent_color || '',
			custom_css:   template.custom_css   || '',
		} );
	}, [ template ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const baseUrl = config.customizeUrl
		? `${ config.customizeUrl }/${ encodeURIComponent( template.slug ) }/customize`
		: null;

	const flash = ( type, msg ) => {
		setStatus( { type, msg } );
		setTimeout( () => setStatus( null ), 4000 );
	};

	const handleSave = useCallback( async () => {
		if ( ! baseUrl ) {
			return;
		}
		setSaving( true );
		setStatus( null );
		try {
			const res = await fetch( baseUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
				body:    JSON.stringify( draft ),
			} );
			const data = await res.json();
			if ( data.success !== false && ! data.code ) {
				flash( 'success', __( 'Template customizations saved.', 'mcp-ai-wpoos-pro' ) );
				onSaved( data );
			} else {
				flash( 'error', data.message || __( 'Could not save. Please try again.', 'mcp-ai-wpoos-pro' ) );
			}
		} catch {
			flash( 'error', __( 'Network error. Please try again.', 'mcp-ai-wpoos-pro' ) );
		} finally {
			setSaving( false );
		}
	}, [ baseUrl, config.nonce, draft, onSaved ] );

	const handleReset = useCallback( async () => {
		if ( ! baseUrl ) {
			return;
		}
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Reset all customizations for this template to defaults?', 'mcp-ai-wpoos-pro' ) ) ) {
			return;
		}
		setResetting( true );
		setStatus( null );
		try {
			const res = await fetch( baseUrl, {
				method:  'DELETE',
				headers: { 'X-WP-Nonce': config.nonce },
			} );
			const data = await res.json();
			if ( data.success !== false && ! data.code ) {
				flash( 'success', __( 'Template reset to defaults.', 'mcp-ai-wpoos-pro' ) );
				onReset( data );
			} else {
				flash( 'error', data.message || __( 'Could not reset. Please try again.', 'mcp-ai-wpoos-pro' ) );
			}
		} catch {
			flash( 'error', __( 'Network error. Please try again.', 'mcp-ai-wpoos-pro' ) );
		} finally {
			setResetting( false );
		}
	}, [ baseUrl, config.nonce, onReset ] );

	return (
		<div className="tma-tpl-editor">
			{ /* Editor header */ }
			<div className="tma-tpl-editor__header">
				<span className="tma-tpl-editor__title">
					{ __( '✎ Edit Template:', 'mcp-ai-wpoos-pro' ) }
					<strong> { template.base_name || template.name }</strong>
				</span>
				<button
					type="button"
					className="tma-tpl-editor__close"
					onClick={ onClose }
					title={ __( 'Close editor', 'mcp-ai-wpoos-pro' ) }
				>
					✕
				</button>
			</div>

			{ /* Tabs */ }
			<div className="tma-tpl-editor__tabs">
				<button
					type="button"
					className={ `tma-tpl-editor__tab${ activeTab === 'appearance' ? ' tma-tpl-editor__tab--active' : '' }` }
					onClick={ () => setActiveTab( 'appearance' ) }
				>
					{ __( 'Appearance', 'mcp-ai-wpoos-pro' ) }
				</button>
				<button
					type="button"
					className={ `tma-tpl-editor__tab${ activeTab === 'css' ? ' tma-tpl-editor__tab--active' : '' }` }
					onClick={ () => setActiveTab( 'css' ) }
				>
					{ __( 'Custom CSS', 'mcp-ai-wpoos-pro' ) }
				</button>
			</div>

			{ /* Tab: Appearance */ }
			{ activeTab === 'appearance' && (
				<div className="tma-tpl-editor__body">
					<div className="tma-tpl-editor__row">
						<label className="tma-tpl-editor__label" htmlFor="tma-tpl-icon">
							{ __( 'Icon / Emoji', 'mcp-ai-wpoos-pro' ) }
							<span className="tma-tpl-editor__hint">
								{ `(${ __( 'default:', 'mcp-ai-wpoos-pro' ) } ${ template.base_icon || '' })` }
							</span>
						</label>
						<input
							id="tma-tpl-icon"
							type="text"
							className="tma-tpl-editor__input tma-tpl-editor__input--icon"
							value={ draft.icon }
							maxLength={ 10 }
							placeholder={ template.base_icon || '📱' }
							onChange={ ( e ) => setDraft( ( p ) => ( { ...p, icon: e.target.value } ) ) }
						/>
					</div>

					<div className="tma-tpl-editor__row">
						<label className="tma-tpl-editor__label" htmlFor="tma-tpl-display-name">
							{ __( 'Display Name', 'mcp-ai-wpoos-pro' ) }
							<span className="tma-tpl-editor__hint">
								{ `(${ __( 'default:', 'mcp-ai-wpoos-pro' ) } ${ template.base_name || '' })` }
							</span>
						</label>
						<input
							id="tma-tpl-display-name"
							type="text"
							className="tma-tpl-editor__input"
							value={ draft.name }
							maxLength={ 120 }
							placeholder={ template.base_name }
							onChange={ ( e ) => setDraft( ( p ) => ( { ...p, name: e.target.value } ) ) }
						/>
					</div>

					<div className="tma-tpl-editor__row">
						<label className="tma-tpl-editor__label" htmlFor="tma-tpl-description">
							{ __( 'Description', 'mcp-ai-wpoos-pro' ) }
							<span className="tma-tpl-editor__hint">
								{ `(${ __( 'default:', 'mcp-ai-wpoos-pro' ) } ${ template.base_description || '' })` }
							</span>
						</label>
						<textarea
							id="tma-tpl-description"
							className="tma-tpl-editor__input tma-tpl-editor__textarea"
							value={ draft.description }
							maxLength={ 500 }
							rows={ 3 }
							placeholder={ template.base_description }
							onChange={ ( e ) => setDraft( ( p ) => ( { ...p, description: e.target.value } ) ) }
						/>
					</div>

					<div className="tma-tpl-editor__row">
						<label className="tma-tpl-editor__label" htmlFor="tma-tpl-accent-color">
							{ __( 'Accent Color', 'mcp-ai-wpoos-pro' ) }
							<span className="tma-tpl-editor__hint">
								{ `(${ __( 'default:', 'mcp-ai-wpoos-pro' ) } ${ template.base_accent_color || '#2481cc' })` }
							</span>
						</label>
						<div className="tma-tpl-editor__color-row">
							<input
								id="tma-tpl-accent-color"
								type="color"
								className="tma-tpl-editor__color-swatch"
								value={ /^#[0-9a-f]{6}$/i.test( draft.accent_color ) ? draft.accent_color : ( template.base_accent_color || '#2481cc' ) }
								onChange={ ( e ) => setDraft( ( p ) => ( { ...p, accent_color: e.target.value } ) ) }
							/>
							<input
								type="text"
								className="tma-tpl-editor__input tma-tpl-editor__input--color"
								value={ draft.accent_color }
								maxLength={ 30 }
								placeholder={ template.base_accent_color || '#2481cc' }
								onChange={ ( e ) => setDraft( ( p ) => ( { ...p, accent_color: e.target.value } ) ) }
							/>
						</div>
					</div>
				</div>
			) }

			{ /* Tab: Custom CSS */ }
			{ activeTab === 'css' && (
				<div className="tma-tpl-editor__body">
					<p className="tma-tpl-editor__desc description">
						{ __(
							'CSS entered here is injected into the Mini App page <head> every time this template is rendered. Use it to override colours, fonts, layout or any visual aspect.',
							'mcp-ai-wpoos-pro'
						) }
					</p>
					<textarea
						ref={ cssRef }
						className="tma-tpl-editor__input tma-tpl-editor__textarea tma-tpl-editor__textarea--code"
						value={ draft.custom_css }
						rows={ 14 }
						placeholder={ '/* Example: change the accent color */\n:root { --tma-btn: #ff6b35; --tma-btn-text: #ffffff; }' }
						onChange={ ( e ) => setDraft( ( p ) => ( { ...p, custom_css: e.target.value } ) ) }
						spellCheck={ false }
					/>
				</div>
			) }

			{ /* Footer: save / reset / status */ }
			<div className="tma-tpl-editor__footer">
				<button
					type="button"
					className="button button-primary"
					onClick={ handleSave }
					disabled={ saving || resetting }
				>
					{ saving
						? __( 'Saving…', 'mcp-ai-wpoos-pro' )
						: __( 'Save Changes', 'mcp-ai-wpoos-pro' ) }
				</button>
				{ template.has_customizations && (
					<button
						type="button"
						className="button tma-tpl-editor__reset-btn"
						onClick={ handleReset }
						disabled={ saving || resetting }
					>
						{ resetting
							? __( 'Resetting…', 'mcp-ai-wpoos-pro' )
							: __( '↺ Reset to Defaults', 'mcp-ai-wpoos-pro' ) }
					</button>
				) }
				{ status && (
					<span className={ `tma-tpl-save-msg tma-tpl-save-msg--${ status.type === 'success' ? 'ok' : 'err' }` }>
						{ status.type === 'success' ? `✓ ${ status.msg }` : `✕ ${ status.msg }` }
					</span>
				) }
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
	const [ editingSlug, setEditingSlug ] = useState( null );
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

	/* ── Toggle edit panel ── */
	const handleEdit = useCallback( ( slug ) => {
		setEditingSlug( ( prev ) => ( prev === slug ? null : slug ) );
		// Close preview when opening editor for better UX.
		setPreviewSlug( null );
	}, [] );

	/* ── Update a single template in state (used after save/reset) ── */
	const updateTemplate = useCallback( ( updatedMeta ) => {
		setTemplates( ( prev ) =>
			prev.map( ( t ) => ( t.slug === updatedMeta.slug ? { ...t, ...updatedMeta } : t ) )
		);
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
								isEditing={ editingSlug === tpl.slug }
								onSelect={ handleSelect }
								onPreview={ handlePreview }
								onEdit={ handleEdit }
							/>
						) ) }
					</div>
				</SortableContext>
			</DndContext>

			{ /* Inline template editor – shown below the card grid */ }
			{ editingSlug && ( () => {
				const tplToEdit = templates.find( ( t ) => t.slug === editingSlug );
				return tplToEdit ? (
					<TemplateEditor
						key={ editingSlug }
						template={ tplToEdit }
						config={ config }
						onClose={ () => setEditingSlug( null ) }
						onSaved={ ( updated ) => updateTemplate( updated ) }
						onReset={ ( updated ) => updateTemplate( updated ) }
					/>
				) : null;
			} )() }

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
