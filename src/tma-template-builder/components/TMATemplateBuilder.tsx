/**
 * TMATemplateBuilder — TypeScript edition.
 *
 * Card-grid template picker with drag-to-reorder and live preview.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import { __ } from '@wordpress/i18n';
import { DndContext, closestCenter, PointerSensor, KeyboardSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { SortableContext, sortableKeyboardCoordinates, rectSortingStrategy, useSortable, arrayMove } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import type { TemplateMeta, TemplateBuilderConfig, TemplateCardProps, TemplateEditorProps, PreviewPaneProps } from '../../shared/types';

// ── Constants ────────────────────────────────────────────────────────

const TOOLKIT_COLORS: Record< string, string > = {
	chat_channels: '#4CAF50', ecommerce: '#9c27b0', crm: '#e65100',
	analytics: '#00796b', calendar_booking: '#1565c0', health_wellness: '#2e7d32',
};

// ── Sortable Template Card ───────────────────────────────────────────

const SortableTemplateCard: React.FC< TemplateCardProps > = ( { template, isActive, isPreviewing, isEditing, onSelect, onPreview, onEdit } ) => {
	const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable( { id: template.slug } );
	const style: React.CSSProperties = {
		transform: CSS.Transform.toString( transform ),
		transition,
		opacity: isDragging ? 0.5 : 1,
		'--card-accent': template.accent_color || '#2481cc',
	} as React.CSSProperties;
	const toolkitColor = TOOLKIT_COLORS[ template.toolkit || '' ] || ( template.accent_color || '#2481cc' );

	return (
		<div ref={ setNodeRef } style={ style } className={ `tma-tpl-card${ isActive ? ' tma-tpl-card--active' : '' }${ isDragging ? ' tma-tpl-card--dragging' : '' }` }>
			<div className="tma-tpl-card__drag-handle" { ...listeners } { ...attributes } title={ __( 'Drag to reorder', 'mcp-ai-wpoos-pro' ) }>
				<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="2"/><circle cx="15" cy="5" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="9" cy="19" r="2"/><circle cx="15" cy="19" r="2"/></svg>
			</div>
			<div className="tma-tpl-card__icon">{ template.icon }</div>
			<div className="tma-tpl-card__body">
				<div className="tma-tpl-card__name">{ template.name }</div>
				<div className="tma-tpl-card__desc">{ template.description }</div>
				{ template.toolkit && <span className="tma-tpl-card__badge" style={ { background: toolkitColor } }>{ template.toolkit.replace( /_/g, ' ' ) }</span> }
			</div>
			<div className="tma-tpl-card__actions">
				<button type="button" className={ `tma-tpl-btn${ isActive ? ' tma-tpl-btn--selected' : ' tma-tpl-btn--select' }` } onClick={ () => onSelect( template.slug ) } disabled={ isActive }>
					{ isActive ? __( '✓ Selected', 'mcp-ai-wpoos-pro' ) : __( 'Select', 'mcp-ai-wpoos-pro' ) }
				</button>
				<button type="button" className={ `tma-tpl-btn tma-tpl-btn--preview${ isPreviewing ? ' tma-tpl-btn--preview-active' : '' }` } onClick={ () => onPreview( template.slug ) }>
					{ isPreviewing ? __( '✕ Close', 'mcp-ai-wpoos-pro' ) : __( '👁 Preview', 'mcp-ai-wpoos-pro' ) }
				</button>
				<button type="button" className={ `tma-tpl-btn tma-tpl-btn--edit${ isEditing ? ' tma-tpl-btn--edit-active' : '' }` } onClick={ () => onEdit( template.slug ) } title={ __( 'Edit template appearance and inject custom CSS', 'mcp-ai-wpoos-pro' ) }>
					{ isEditing ? __( '✕ Close', 'mcp-ai-wpoos-pro' ) : __( '✎ Edit', 'mcp-ai-wpoos-pro' ) }
				</button>
			</div>
		</div>
	);
};

// ── Template Editor Panel ────────────────────────────────────────────

interface EditorDraft { name: string; description: string; icon: string; accent_color: string; custom_css: string; }
interface StatusMsg { type: 'success' | 'error'; msg: string; }

const TemplateEditor: React.FC< TemplateEditorProps > = ( { template, config, onClose, onSaved } ) => {
	const [ activeTab, setActiveTab ] = useState< 'appearance' | 'css' >( 'appearance' );
	const [ draft, setDraft ] = useState< EditorDraft >( { name: template.name || '', description: template.description || '', icon: template.icon || '', accent_color: template.accent_color || '', custom_css: template.custom_css || '' } );
	const [ saving, setSaving ] = useState( false );
		const [ status, setStatus ] = useState< StatusMsg | null >( null );
	const cssRef = useRef< HTMLTextAreaElement >( null );

	useEffect( () => { setDraft( { name: template.name || '', description: template.description || '', icon: template.icon || '', accent_color: template.accent_color || '', custom_css: template.custom_css || '' } ); }, [ template ] );

	const baseUrl = ( config as TemplateBuilderConfig ).customizeUrl ? `${ ( config as TemplateBuilderConfig ).customizeUrl }/${ encodeURIComponent( template.slug ) }/customize` : null;
	const flash = ( type: StatusMsg[ 'type' ], msg: string ) => { setStatus( { type, msg } ); setTimeout( () => setStatus( null ), 4000 ); };

	const handleSave = useCallback( async () => { if ( ! baseUrl ) { return; } setSaving( true ); setStatus( null ); try { const res = await fetch( baseUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce }, body: JSON.stringify( draft ) } ); const data = await res.json(); if ( data.success !== false && ! data.code ) { flash( 'success', __( 'Template customizations saved.', 'mcp-ai-wpoos-pro' ) ); onSaved( data as TemplateMeta ); } else { flash( 'error', ( data as { message?: string } ).message || __( 'Could not save.', 'mcp-ai-wpoos-pro' ) ); } } catch { flash( 'error', __( 'Network error.', 'mcp-ai-wpoos-pro' ) ); } finally { setSaving( false ); } }, [ baseUrl, config.nonce, draft, onSaved ] );


	return (
		<div className="tma-tpl-editor">
			<div className="tma-tpl-editor__header"><span className="tma-tpl-editor__title">{ __( '✎ Edit Template:', 'mcp-ai-wpoos-pro' ) } <strong> { template.name }</strong></span><button type="button" className="tma-tpl-editor__close" onClick={ onClose } title={ __( 'Close editor', 'mcp-ai-wpoos-pro' ) }>✕</button></div>
			<div className="tma-tpl-editor__tabs">
				<button type="button" className={ `tma-tpl-editor__tab${ activeTab === 'appearance' ? ' tma-tpl-editor__tab--active' : '' }` } onClick={ () => setActiveTab( 'appearance' ) }>{ __( 'Appearance', 'mcp-ai-wpoos-pro' ) }</button>
				<button type="button" className={ `tma-tpl-editor__tab${ activeTab === 'css' ? ' tma-tpl-editor__tab--active' : '' }` } onClick={ () => setActiveTab( 'css' ) }>{ __( 'Custom CSS', 'mcp-ai-wpoos-pro' ) }</button>
			</div>
			{ activeTab === 'appearance' && (
				<div className="tma-tpl-editor__body">
					<div className="tma-tpl-editor__row"><label className="tma-tpl-editor__label" htmlFor="tma-tpl-icon">{ __( 'Icon / Emoji', 'mcp-ai-wpoos-pro' ) }</label><input id="tma-tpl-icon" type="text" className="tma-tpl-editor__input tma-tpl-editor__input--icon" value={ draft.icon } maxLength={ 10 } placeholder={ '📱' } onChange={ ( e ) => setDraft( ( p ) => ( { ...p, icon: e.target.value } ) ) } /></div>
					<div className="tma-tpl-editor__row"><label className="tma-tpl-editor__label" htmlFor="tma-tpl-display-name">{ __( 'Display Name', 'mcp-ai-wpoos-pro' ) }</label><input id="tma-tpl-display-name" type="text" className="tma-tpl-editor__input" value={ draft.name } maxLength={ 120 } onChange={ ( e ) => setDraft( ( p ) => ( { ...p, name: e.target.value } ) ) } /></div>
					<div className="tma-tpl-editor__row"><label className="tma-tpl-editor__label" htmlFor="tma-tpl-description">{ __( 'Description', 'mcp-ai-wpoos-pro' ) }</label><textarea id="tma-tpl-description" className="tma-tpl-editor__input tma-tpl-editor__textarea" value={ draft.description } maxLength={ 500 } rows={ 3 } onChange={ ( e ) => setDraft( ( p ) => ( { ...p, description: e.target.value } ) ) } /></div>
					<div className="tma-tpl-editor__row"><label className="tma-tpl-editor__label" htmlFor="tma-tpl-accent-color">{ __( 'Accent Color', 'mcp-ai-wpoos-pro' ) }</label><div className="tma-tpl-editor__color-row"><input id="tma-tpl-accent-color" type="color" className="tma-tpl-editor__color-swatch" value={ /^#[0-9a-f]{6}$/i.test( draft.accent_color ) ? draft.accent_color : '#2481cc' } onChange={ ( e ) => setDraft( ( p ) => ( { ...p, accent_color: e.target.value } ) ) } /><input type="text" className="tma-tpl-editor__input tma-tpl-editor__input--color" value={ draft.accent_color } maxLength={ 30 } placeholder="#2481cc" onChange={ ( e ) => setDraft( ( p ) => ( { ...p, accent_color: e.target.value } ) ) } /></div></div>
				</div>
			) }
			{ activeTab === 'css' && (
				<div className="tma-tpl-editor__body"><textarea ref={ cssRef } className="tma-tpl-editor__input tma-tpl-editor__textarea tma-tpl-editor__textarea--code" value={ draft.custom_css } rows={ 14 } placeholder={ '/* Example */\n:root { --tma-btn: #ff6b35; }' } onChange={ ( e ) => setDraft( ( p ) => ( { ...p, custom_css: e.target.value } ) ) } spellCheck={ false } /></div>
			) }
			<div className="tma-tpl-editor__footer">
				<button type="button" className="button button-primary" onClick={ handleSave } disabled={ saving }>{ saving ? __( 'Saving…', 'mcp-ai-wpoos-pro' ) : __( 'Save Changes', 'mcp-ai-wpoos-pro' ) }</button>
				{ status && <span className={ `tma-tpl-save-msg tma-tpl-save-msg--${ status.type === 'success' ? 'ok' : 'err' }` }>{ status.type === 'success' ? `✓ ${ status.msg }` : `✕ ${ status.msg }` }</span> }
			</div>
		</div>
	);
};

// ── Preview Pane ─────────────────────────────────────────────────────

const PreviewPane: React.FC< PreviewPaneProps > = ( { slug, previewBaseUrl } ) => {
	const url = previewBaseUrl ? `${ previewBaseUrl }?tma_preview=${ encodeURIComponent( slug ) }` : null;
	return (
		<div className="tma-tpl-preview">
			<div className="tma-tpl-preview__header"><span className="tma-tpl-preview__label">{ __( 'Live Preview', 'mcp-ai-wpoos-pro' ) }<span className="tma-tpl-preview__slug"> – { slug }</span></span></div>
			{ url ? <div className="tma-tpl-preview__frame-wrap"><iframe className="tma-tpl-preview__frame" src={ url } title={ `${ slug } template preview` } sandbox="allow-scripts allow-same-origin allow-forms" /></div> : <p className="tma-tpl-preview__no-url description">{ __( 'Preview URL not available.', 'mcp-ai-wpoos-pro' ) }</p> }
		</div>
	);
};

// ── Main Component ───────────────────────────────────────────────────

export interface TMATemplateBuilderProps {
	config: TemplateBuilderConfig;
	connectionId?: string | null;
	initialSlug?: string | null;
	embeddedMode?: boolean;
}

export const TMATemplateBuilder: React.FC< TMATemplateBuilderProps > = ( { config, connectionId = null, initialSlug = null, embeddedMode = false } ) => {
	const [ templates, setTemplates ] = useState< TemplateMeta[] >( [] );
	const [ order, setOrder ] = useState< string[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ activeSlug, setActiveSlug ] = useState( initialSlug ?? config.activeTemplate ?? 'default' );
	const [ previewSlug, setPreviewSlug ] = useState< string | null >( null );
	const [ editingSlug, setEditingSlug ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ saveStatus, setSaveStatus ] = useState< 'success' | 'error' | null >( null );

	const sensors = useSensors( useSensor( PointerSensor, { activationConstraint: { distance: 6 } } ), useSensor( KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates } ) );

	useEffect( () => { if ( ! config.templatesUrl ) { setLoading( false ); return; } fetch( config.templatesUrl, { headers: { 'X-WP-Nonce': config.nonce } } ).then( ( r ) => r.json() ).then( ( data: TemplateMeta[] | { templates: TemplateMeta[] } ) => { const list = Array.isArray( data ) ? data : ( data.templates ?? [] ); setTemplates( list ); setOrder( list.map( ( t ) => t.slug ) ); setLoading( false ); } ).catch( ( err: Error ) => { setError( err.message ); setLoading( false ); } ); }, [ config.templatesUrl, config.nonce ] );

	const handleDragEnd = useCallback( ( event: DragEndEvent ) => { const { active, over } = event; if ( over && String(active.id) !== String(over.id) ) { setOrder( ( prev ) => { const oldIndex = prev.indexOf( String( active.id ) ); const newIndex = prev.indexOf( String( over.id ) ); return arrayMove( prev, oldIndex, newIndex ); } ); } }, [] );
	const handleSelect = useCallback( ( slug: string ) => { setActiveSlug( slug ); if ( embeddedMode ) { const el = document.getElementById( 'telegram_mini_app_template' ) as HTMLInputElement | null; if ( el ) { el.value = slug; } } }, [ embeddedMode ] );
	const handlePreview = useCallback( ( slug: string ) => { setPreviewSlug( ( prev ) => ( prev === slug ? null : slug ) ); }, [] );
	const handleEdit = useCallback( ( slug: string ) => { setEditingSlug( ( prev ) => ( prev === slug ? null : slug ) ); setPreviewSlug( null ); }, [] );
	const updateTemplate = useCallback( ( updated: TemplateMeta ) => { setTemplates( ( prev ) => prev.map( ( t ) => ( t.slug === updated.slug ? { ...t, ...updated } : t ) ) ); }, [] );
	const saveGlobal = useCallback( async () => { if ( ! config.saveUrl ) { return; } setSaving( true ); setSaveStatus( null ); try { const res = await fetch( config.saveUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce }, body: JSON.stringify( { template: activeSlug } ) } ); const data = await res.json(); setSaveStatus( data.success !== false ? 'success' : 'error' ); } catch { setSaveStatus( 'error' ); } finally { setSaving( false ); setTimeout( () => setSaveStatus( null ), 3000 ); } }, [ config.saveUrl, config.nonce, activeSlug ] );

	if ( loading ) { return <div className="tma-tpl-loading"><span className="spinner is-active" style={ { float: 'none', margin: '0 8px 0 0', verticalAlign: 'middle' } } />{ __( 'Loading templates…', 'mcp-ai-wpoos-pro' ) }</div>; }
	if ( error ) { return <div className="notice notice-error inline" style={ { margin: 0 } }><p>{ __( 'Could not load templates:', 'mcp-ai-wpoos-pro' ) } { error }</p></div>; }

	const orderedTemplates = order.map( ( slug ) => templates.find( ( t ) => t.slug === slug ) ).filter( Boolean ) as TemplateMeta[];

	return (
		<div className={ `tma-template-builder${ embeddedMode ? ' tma-template-builder--embedded' : '' }` } data-connection={ connectionId ?? '' }>
			{ ! embeddedMode && <div className="tma-tpl-header"><h3 style={ { marginTop: 0 } }>{ __( '📱 Mini App Template Builder', 'mcp-ai-wpoos-pro' ) }</h3></div> }
			<DndContext sensors={ sensors } collisionDetection={ closestCenter } onDragEnd={ handleDragEnd }>
				<SortableContext items={ order } strategy={ rectSortingStrategy }>
					<div className="tma-tpl-grid">
						{ orderedTemplates.map( ( tpl ) => <SortableTemplateCard key={ tpl.slug } template={ tpl } isActive={ activeSlug === tpl.slug } isPreviewing={ previewSlug === tpl.slug } isEditing={ editingSlug === tpl.slug } onSelect={ handleSelect } onPreview={ handlePreview } onEdit={ handleEdit } /> ) }
					</div>
				</SortableContext>
			</DndContext>
			{ editingSlug && ( () => { const tpl = templates.find( ( t ) => t.slug === editingSlug ); return tpl ? <TemplateEditor key={ editingSlug } template={ tpl } config={ config } onClose={ () => setEditingSlug( null ) } onSaved={ ( u ) => updateTemplate( u ) } onReset={ ( u ) => updateTemplate( u ) } /> : null; } )() }
			{ previewSlug && <PreviewPane slug={ previewSlug } previewBaseUrl={ config.previewBaseUrl } /> }
			{ ! embeddedMode && <div className="tma-tpl-footer"><button type="button" className="button button-primary" onClick={ saveGlobal } disabled={ saving }>{ saving ? __( 'Saving…', 'mcp-ai-wpoos-pro' ) : __( 'Save Template', 'mcp-ai-wpoos-pro' ) }</button>{ saveStatus === 'success' && <span className="tma-tpl-save-msg tma-tpl-save-msg--ok">✓ { __( 'Template saved successfully.', 'mcp-ai-wpoos-pro' ) }</span> }{ saveStatus === 'error' && <span className="tma-tpl-save-msg tma-tpl-save-msg--err">✕ { __( 'Could not save template.', 'mcp-ai-wpoos-pro' ) }</span> }</div> }
		</div>
	);
};
