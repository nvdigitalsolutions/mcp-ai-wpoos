/**
 * NV oOS Chat SPA — Memory Drawer (Phase 4).
 *
 * Slide-in panel with three tabs:
 *
 *   Memories — paginated recall list; inline edit (title / content /
 *               importance) + delete; "Add memory" form.
 *   Scope    — wing + room inputs; values persisted per-assistant in
 *               localStorage so subsequent recalls auto-filter.
 *   Audit    — read-only audit-log feed from GET /chat-memory/audit.
 *
 * Accessibility:
 *   - role="dialog" aria-modal="false" (non-blocking — chat stays live)
 *   - Labelled by heading, ESC closes, toggle button re-gets focus
 *   - ARIA-live region for status messages
 *
 * The component fails soft:
 *   - When the memory surface is unavailable the caller simply does not
 *     render it (the toggle button stays hidden).
 *   - Network errors surface in a small status area; they don't crash chat.
 */

import { __, sprintf } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
	type JSX,
	type KeyboardEvent,
} from 'react';
import {
	MemoryClient,
	persistScope,
	readPersistedScope,
	type AuditEntry,
	type MemoryContext,
	type MemoryPreferences,
} from '../api/memory';

export type MemoryTab = 'memories' | 'scope' | 'audit';

export interface MemoryDrawerProps {
	endpoint: string;
	nonce: string;
	assistantId: number | string;
	/** Controlled by the parent — true = drawer is visible. */
	isOpen: boolean;
	/** Active tab; parent controls after initial open. */
	activeTab: MemoryTab;
	onTabChange: ( tab: MemoryTab ) => void;
	onClose: () => void;
	/** The toggle button ref — focus returns here on close. */
	toggleRef: React.RefObject< HTMLButtonElement | null >;
}

export function MemoryDrawer( {
	endpoint,
	nonce,
	assistantId,
	isOpen,
	activeTab,
	onTabChange,
	onClose,
	toggleRef,
}: MemoryDrawerProps ): JSX.Element | null {
	const client = useMemo(
		() => new MemoryClient( { endpoint, nonce, assistantId } ),
		[ endpoint, nonce, assistantId ]
	);

	// ── Memories tab ───────────────────────────────────────────────────────────
	const [ memories, setMemories ] = useState< MemoryContext[] | null >( null );
	const [ memoriesError, setMemoriesError ] = useState< string | null >( null );
	const [ memoriesLoading, setMemoriesLoading ] = useState( false );

	// ── Scope tab ──────────────────────────────────────────────────────────────
	const initialScope = useMemo( () => readPersistedScope( assistantId ), [ assistantId ] );
	const [ wing, setWing ] = useState( initialScope.wing );
	const [ room, setRoom ] = useState( initialScope.room );
	const [ scopeSaved, setScopeSaved ] = useState( false );

	// ── Audit tab ──────────────────────────────────────────────────────────────
	const [ auditEntries, setAuditEntries ] = useState< AuditEntry[] | null >( null );
	const [ auditError, setAuditError ] = useState< string | null >( null );
	const [ auditLoading, setAuditLoading ] = useState( false );

	// ── Preferences ───────────────────────────────────────────────────────────
	const [ prefs, setPrefs ] = useState< MemoryPreferences | null >( null );

	// ── Add-memory form ───────────────────────────────────────────────────────
	const [ addContent, setAddContent ] = useState( '' );
	const [ addTitle, setAddTitle ] = useState( '' );
	const [ addImportance, setAddImportance ] = useState( 'medium' );
	const [ addStatus, setAddStatus ] = useState< string | null >( null );
	const [ addLoading, setAddLoading ] = useState( false );

	// ── Status toast ──────────────────────────────────────────────────────────
	const [ statusMsg, setStatusMsg ] = useState< string | null >( null );

	const drawerRef = useRef< HTMLDivElement >( null );
	const abortRef = useRef< AbortController | null >( null );

	// Close on ESC
	useEffect( () => {
		if ( ! isOpen ) return;
		const handler = ( e: globalThis.KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				onClose();
				toggleRef.current?.focus();
			}
		};
		document.addEventListener( 'keydown', handler );
		return () => document.removeEventListener( 'keydown', handler );
	}, [ isOpen, onClose, toggleRef ] );

	// Move focus into drawer on open
	useEffect( () => {
		if ( isOpen && drawerRef.current ) {
			const first = drawerRef.current.querySelector< HTMLElement >(
				'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
			);
			first?.focus();
		}
	}, [ isOpen ] );

	// Lazy-load data when the drawer opens or the active tab changes
	useEffect( () => {
		if ( ! isOpen ) return;

		abortRef.current?.abort();
		const controller = new AbortController();
		abortRef.current = controller;

		if ( activeTab === 'memories' && memories === null ) {
			setMemoriesLoading( true );
			setMemoriesError( null );
			const scope = readPersistedScope( assistantId );
			client
				.recall( { wing: scope.wing, room: scope.room, limit: 30 }, controller.signal )
				.then( ( list ) => {
					if ( ! controller.signal.aborted ) setMemories( list );
				} )
				.catch( ( err: unknown ) => {
					if ( ! controller.signal.aborted ) {
						setMemoriesError( err instanceof Error ? err.message : String( err ) );
					}
				} )
				.finally( () => {
					if ( ! controller.signal.aborted ) setMemoriesLoading( false );
				} );
		}

		if ( activeTab === 'audit' && auditEntries === null ) {
			setAuditLoading( true );
			setAuditError( null );
			client
				.audit( { limit: 25 }, controller.signal )
				.then( ( list ) => {
					if ( ! controller.signal.aborted ) setAuditEntries( list );
				} )
				.catch( ( err: unknown ) => {
					if ( ! controller.signal.aborted ) {
						setAuditError( err instanceof Error ? err.message : String( err ) );
					}
				} )
				.finally( () => {
					if ( ! controller.signal.aborted ) setAuditLoading( false );
				} );
		}

		if ( activeTab === 'memories' && prefs === null ) {
			client
				.getPreferences( controller.signal )
				.then( ( p ) => {
					if ( ! controller.signal.aborted ) setPrefs( p );
				} )
				.catch( () => {
					// Non-critical — ignore.
				} );
		}

		return () => controller.abort();
	}, [ isOpen, activeTab, assistantId, client, memories, auditEntries, prefs ] );

	const refreshMemories = useCallback( () => {
		setMemories( null );
	}, [] );

	const handleDelete = useCallback(
		async ( contextId: string ) => {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Delete this memory?', 'nvoos-chat-spa' ) ) ) return;
			try {
				await client.delete( contextId );
				setMemories( ( prev ) =>
					prev
						? prev.filter( ( m ) => getContextId( m ) !== contextId )
						: prev
				);
				setStatusMsg( __( 'Memory deleted.', 'nvoos-chat-spa' ) );
			} catch ( err: unknown ) {
				setStatusMsg( err instanceof Error ? err.message : String( err ) );
			}
		},
		[ client ]
	);

	const handleAddSubmit = useCallback(
		async ( e: React.FormEvent ) => {
			e.preventDefault();
			if ( ! addContent.trim() ) return;
			setAddLoading( true );
			setAddStatus( null );
			const scope = readPersistedScope( assistantId );
			try {
				await client.store( {
					content: addContent.trim(),
					title: addTitle.trim() || undefined,
					importance: addImportance,
					wing: scope.wing || undefined,
					room: scope.room || undefined,
				} );
				setAddContent( '' );
				setAddTitle( '' );
				setAddImportance( 'medium' );
				setAddStatus( __( 'Memory saved.', 'nvoos-chat-spa' ) );
				setMemories( null ); // force reload on next render
			} catch ( err: unknown ) {
				setAddStatus( err instanceof Error ? err.message : String( err ) );
			} finally {
				setAddLoading( false );
			}
		},
		[ client, addContent, addTitle, addImportance, assistantId ]
	);

	const handleSaveScope = useCallback( () => {
		persistScope( assistantId, wing.trim(), room.trim() );
		setMemories( null ); // force recall reload with new scope
		setScopeSaved( true );
		setTimeout( () => setScopeSaved( false ), 2000 );
	}, [ assistantId, wing, room ] );

	const handlePrefsToggle = useCallback( async () => {
		if ( ! prefs ) return;
		try {
			const updated = await client.updatePreferences( { enabled: ! prefs.enabled } );
			setPrefs( updated );
		} catch {
			// Silent — non-critical.
		}
	}, [ client, prefs ] );

	if ( ! isOpen ) return null;

	return (
		<div
			ref={ drawerRef }
			className="nvoos-chat-spa-memory-drawer"
			role="dialog"
			aria-modal="false"
			aria-label={ __( 'Memory', 'nvoos-chat-spa' ) }
		>
			<div className="nvoos-chat-spa-memory-drawer-header">
				<h2 className="nvoos-chat-spa-memory-drawer-title">
					{ __( '🧠 Memory', 'nvoos-chat-spa' ) }
				</h2>
				<button
					type="button"
					className="nvoos-chat-spa-memory-drawer-close"
					aria-label={ __( 'Close memory drawer', 'nvoos-chat-spa' ) }
					onClick={ () => {
						onClose();
						toggleRef.current?.focus();
					} }
				>
					×
				</button>
			</div>

			{ statusMsg && (
				<p
					className="nvoos-chat-spa-memory-drawer-status"
					role="status"
					aria-live="polite"
				>
					{ statusMsg }
				</p>
			) }

			<div className="nvoos-chat-spa-memory-drawer-tabs" role="tablist">
				{ ( [ 'memories', 'scope', 'audit' ] as MemoryTab[] ).map( ( tab ) => (
					<button
						key={ tab }
						type="button"
						role="tab"
						aria-selected={ activeTab === tab }
						className={ `nvoos-chat-spa-memory-drawer-tab${
							activeTab === tab ? ' nvoos-chat-spa-memory-drawer-tab--active' : ''
						}` }
						onClick={ () => onTabChange( tab ) }
					>
						{ tabLabel( tab ) }
					</button>
				) ) }
			</div>

			<div className="nvoos-chat-spa-memory-drawer-body" role="tabpanel">
				{ activeTab === 'memories' && (
					<MemoriesTab
						memories={ memories }
						isLoading={ memoriesLoading }
						error={ memoriesError }
						prefs={ prefs }
						addContent={ addContent }
						addTitle={ addTitle }
						addImportance={ addImportance }
						addStatus={ addStatus }
						addLoading={ addLoading }
						onAddContentChange={ setAddContent }
						onAddTitleChange={ setAddTitle }
						onAddImportanceChange={ setAddImportance }
						onAddSubmit={ handleAddSubmit }
						onDelete={ handleDelete }
						onRefresh={ refreshMemories }
						onPrefsToggle={ handlePrefsToggle }
					/>
				) }
				{ activeTab === 'scope' && (
					<ScopeTab
						wing={ wing }
						room={ room }
						saved={ scopeSaved }
						onWingChange={ setWing }
						onRoomChange={ setRoom }
						onSave={ handleSaveScope }
					/>
				) }
				{ activeTab === 'audit' && (
					<AuditTab
						entries={ auditEntries }
						isLoading={ auditLoading }
						error={ auditError }
					/>
				) }
			</div>
		</div>
	);
}

// ── Sub-components ────────────────────────────────────────────────────────────

interface MemoriesTabProps {
	memories: MemoryContext[] | null;
	isLoading: boolean;
	error: string | null;
	prefs: MemoryPreferences | null;
	addContent: string;
	addTitle: string;
	addImportance: string;
	addStatus: string | null;
	addLoading: boolean;
	onAddContentChange: ( v: string ) => void;
	onAddTitleChange: ( v: string ) => void;
	onAddImportanceChange: ( v: string ) => void;
	onAddSubmit: ( e: React.FormEvent ) => void;
	onDelete: ( id: string ) => void;
	onRefresh: () => void;
	onPrefsToggle: () => void;
}

function MemoriesTab( {
	memories,
	isLoading,
	error,
	prefs,
	addContent,
	addTitle,
	addImportance,
	addStatus,
	addLoading,
	onAddContentChange,
	onAddTitleChange,
	onAddImportanceChange,
	onAddSubmit,
	onDelete,
	onRefresh,
	onPrefsToggle,
}: MemoriesTabProps ): JSX.Element {
	return (
		<div className="nvoos-chat-spa-memory-tab-memories">
			{ prefs !== null && (
				<div className="nvoos-chat-spa-memory-prefs">
					<label className="nvoos-chat-spa-memory-prefs-label">
						<input
							type="checkbox"
							checked={ prefs.enabled }
							onChange={ onPrefsToggle }
						/>
						{ __( 'Use long-term memory', 'nvoos-chat-spa' ) }
					</label>
				</div>
			) }

			<div className="nvoos-chat-spa-memory-list-header">
				<strong>{ __( 'Recent memories', 'nvoos-chat-spa' ) }</strong>
				<button
					type="button"
					className="nvoos-chat-spa-memory-refresh"
					onClick={ onRefresh }
				>
					{ __( 'Refresh', 'nvoos-chat-spa' ) }
				</button>
			</div>

			{ isLoading && (
				<p className="nvoos-chat-spa-memory-empty">
					{ __( 'Loading…', 'nvoos-chat-spa' ) }
				</p>
			) }
			{ ! isLoading && error && (
				<p className="nvoos-chat-spa-memory-error" role="alert">
					{ error }
				</p>
			) }
			{ ! isLoading && ! error && memories !== null && memories.length === 0 && (
				<p className="nvoos-chat-spa-memory-empty">
					{ __( 'No memories stored yet.', 'nvoos-chat-spa' ) }
				</p>
			) }
			{ ! isLoading && memories !== null && memories.length > 0 && (
				<ul className="nvoos-chat-spa-memory-list">
					{ memories.map( ( mem, idx ) => {
						const id = getContextId( mem );
						return (
							<MemoryItem
								key={ id || idx }
								memory={ mem }
								onDelete={ id ? () => onDelete( id ) : undefined }
							/>
						);
					} ) }
				</ul>
			) }

			<form
				className="nvoos-chat-spa-memory-add-form"
				onSubmit={ onAddSubmit }
			>
				<p className="nvoos-chat-spa-memory-add-heading">
					<strong>{ __( 'Add a memory', 'nvoos-chat-spa' ) }</strong>
				</p>
				<label className="screen-reader-text" htmlFor="nvoos-memory-add-title">
					{ __( 'Title (optional)', 'nvoos-chat-spa' ) }
				</label>
				<input
					id="nvoos-memory-add-title"
					type="text"
					className="nvoos-chat-spa-memory-add-input"
					placeholder={ __( 'Title (optional)', 'nvoos-chat-spa' ) }
					value={ addTitle }
					onChange={ ( e ) => onAddTitleChange( e.target.value ) }
				/>
				<label className="screen-reader-text" htmlFor="nvoos-memory-add-content">
					{ __( 'Memory content', 'nvoos-chat-spa' ) }
				</label>
				<textarea
					id="nvoos-memory-add-content"
					className="nvoos-chat-spa-memory-add-textarea"
					placeholder={ __( 'Memory content…', 'nvoos-chat-spa' ) }
					rows={ 3 }
					value={ addContent }
					onChange={ ( e ) => onAddContentChange( e.target.value ) }
					required
				/>
				<label className="screen-reader-text" htmlFor="nvoos-memory-add-importance">
					{ __( 'Importance', 'nvoos-chat-spa' ) }
				</label>
				<select
					id="nvoos-memory-add-importance"
					className="nvoos-chat-spa-memory-add-select"
					value={ addImportance }
					onChange={ ( e ) => onAddImportanceChange( e.target.value ) }
				>
					<option value="low">{ __( 'Low', 'nvoos-chat-spa' ) }</option>
					<option value="medium">{ __( 'Medium', 'nvoos-chat-spa' ) }</option>
					<option value="high">{ __( 'High', 'nvoos-chat-spa' ) }</option>
					<option value="critical">{ __( 'Critical', 'nvoos-chat-spa' ) }</option>
				</select>
				{ addStatus && (
					<p className="nvoos-chat-spa-memory-add-status" role="status" aria-live="polite">
						{ addStatus }
					</p>
				) }
				<button
					type="submit"
					className="nvoos-chat-spa-memory-add-submit"
					disabled={ addLoading || ! addContent.trim() }
				>
					{ addLoading
						? __( 'Saving…', 'nvoos-chat-spa' )
						: __( 'Save memory', 'nvoos-chat-spa' ) }
				</button>
			</form>
		</div>
	);
}

interface MemoryItemProps {
	memory: MemoryContext;
	onDelete?: () => void;
}

function MemoryItem( { memory, onDelete }: MemoryItemProps ): JSX.Element {
	const title =
		memory.title ||
		( memory.context_data && memory.context_data.title ) ||
		'';
	const content =
		memory.content ||
		( memory.context_data && typeof memory.context_data.content === 'string'
			? memory.context_data.content
			: '' );
	const importance =
		memory.importance ||
		( memory.context_data && memory.context_data.importance ) ||
		'';

	return (
		<li className="nvoos-chat-spa-memory-item" data-testid="nvoos-chat-spa-memory-item">
			{ title && (
				<strong className="nvoos-chat-spa-memory-item-title">{ String( title ) }</strong>
			) }
			<p className="nvoos-chat-spa-memory-item-content">
				{ truncate( String( content ), 160 ) }
			</p>
			{ importance && (
				<span className={ `nvoos-chat-spa-memory-item-importance nvoos-chat-spa-memory-item-importance--${ importance }` }>
					{ String( importance ) }
				</span>
			) }
			{ typeof memory.stored_under === 'string' && memory.stored_under !== '' && (
				<span
					className="nvoos-chat-spa-memory-item-stored-under"
					data-testid="nvoos-chat-spa-memory-stored-under"
				>
					{ __( 'stored under', 'nvoos-chat-spa' ) }: { String( memory.stored_under ) }
				</span>
			) }
			{ onDelete && (
				<button
					type="button"
					className="nvoos-chat-spa-memory-item-delete"
					aria-label={ __( 'Delete memory', 'nvoos-chat-spa' ) }
					onClick={ onDelete }
				>
					×
				</button>
			) }
		</li>
	);
}

interface ScopeTabProps {
	wing: string;
	room: string;
	saved: boolean;
	onWingChange: ( v: string ) => void;
	onRoomChange: ( v: string ) => void;
	onSave: () => void;
}

function ScopeTab( { wing, room, saved, onWingChange, onRoomChange, onSave }: ScopeTabProps ): JSX.Element {
	const handleKey = ( e: KeyboardEvent< HTMLInputElement > ) => {
		if ( e.key === 'Enter' ) onSave();
	};
	return (
		<div className="nvoos-chat-spa-memory-tab-scope">
			<p className="nvoos-chat-spa-memory-scope-hint">
				{ __(
					'Set a wing (project / matter) and room (topic) to focus memory recall on a specific context.',
					'nvoos-chat-spa'
				) }
			</p>
			<label htmlFor="nvoos-memory-wing">
				{ __( 'Wing (project / matter)', 'nvoos-chat-spa' ) }
			</label>
			<input
				id="nvoos-memory-wing"
				type="text"
				className="nvoos-chat-spa-memory-scope-input"
				value={ wing }
				onChange={ ( e ) => onWingChange( e.target.value ) }
				onKeyDown={ handleKey }
				placeholder={ __( 'e.g. my-project', 'nvoos-chat-spa' ) }
			/>
			<label htmlFor="nvoos-memory-room">
				{ __( 'Room (topic)', 'nvoos-chat-spa' ) }
			</label>
			<input
				id="nvoos-memory-room"
				type="text"
				className="nvoos-chat-spa-memory-scope-input"
				value={ room }
				onChange={ ( e ) => onRoomChange( e.target.value ) }
				onKeyDown={ handleKey }
				placeholder={ __( 'e.g. research', 'nvoos-chat-spa' ) }
			/>
			<button
				type="button"
				className="nvoos-chat-spa-memory-scope-save"
				onClick={ onSave }
			>
				{ saved ? __( 'Saved!', 'nvoos-chat-spa' ) : __( 'Save scope', 'nvoos-chat-spa' ) }
			</button>
			{ ( wing || room ) && (
				<p className="nvoos-chat-spa-memory-scope-current">
					{ sprintf(
						/* translators: %s: active scope string. */
						__( 'Active scope: %s', 'nvoos-chat-spa' ),
						[ wing, room ].filter( Boolean ).join( ' / ' )
					) }
				</p>
			) }
		</div>
	);
}

interface AuditTabProps {
	entries: AuditEntry[] | null;
	isLoading: boolean;
	error: string | null;
}

function AuditTab( { entries, isLoading, error }: AuditTabProps ): JSX.Element {
	return (
		<div className="nvoos-chat-spa-memory-tab-audit">
			{ isLoading && (
				<p className="nvoos-chat-spa-memory-empty">
					{ __( 'Loading…', 'nvoos-chat-spa' ) }
				</p>
			) }
			{ ! isLoading && error && (
				<p className="nvoos-chat-spa-memory-error" role="alert">
					{ error }
				</p>
			) }
			{ ! isLoading && ! error && entries !== null && entries.length === 0 && (
				<p className="nvoos-chat-spa-memory-empty">
					{ __( 'No audit entries yet.', 'nvoos-chat-spa' ) }
				</p>
			) }
			{ entries !== null && entries.length > 0 && (
				<ul className="nvoos-chat-spa-memory-audit-list">
					{ entries.map( ( entry, idx ) => (
						// Audit entries are append-only within a session and have no
						// stable server-side ID in the API response, so index is safe.
						<li
							key={ idx }
							className="nvoos-chat-spa-memory-audit-item"
							data-action={ entry.action ?? '' }
						>
							<time className="nvoos-chat-spa-memory-audit-time">
								{ entry.timestamp ?? __( '(no timestamp)', 'nvoos-chat-spa' ) }
							</time>
							<span className="nvoos-chat-spa-memory-audit-action">
								{ entry.action ?? __( 'unknown', 'nvoos-chat-spa' ) }
							</span>
							{ entry.context_id && (
								<span className="nvoos-chat-spa-memory-audit-id">
									{ String( entry.context_id ) }
								</span>
							) }
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function tabLabel( tab: MemoryTab ): string {
	switch ( tab ) {
		case 'memories':
			return __( 'Memories', 'nvoos-chat-spa' );
		case 'scope':
			return __( 'Scope', 'nvoos-chat-spa' );
		case 'audit':
			return __( 'Audit', 'nvoos-chat-spa' );
	}
}

function getContextId( mem: MemoryContext ): string {
	return (
		( typeof mem.context_id === 'string' && mem.context_id ) ||
		( typeof mem.id === 'string' && mem.id ) ||
		( typeof mem.uuid === 'string' && mem.uuid ) ||
		''
	);
}

function truncate( str: string, max: number ): string {
	return str.length <= max ? str : str.slice( 0, max ) + '…';
}
