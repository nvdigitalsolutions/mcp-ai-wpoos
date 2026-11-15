/**
 * AdvancedFieldsEditor — bespoke editors for the advanced assistant settings
 * shipped by the 090-B assistant-edit parity contract.
 *
 * Accessibility decisions and where they come from:
 *
 *  - **No `role="grid"`.** The W3C ARIA APG defines a grid as a composite
 *    widget that owns roving tabindex and directional navigation, and requires
 *    an Enter/F2 mode switch when cells contain editable content. For a
 *    handful of editable rows the APG-sanctioned simpler path — semantic
 *    `fieldset`/`legend`, native controls, every control in the tab order —
 *    is both more robust and far less code.
 *  - **Unique, descriptive action names** ("Remove shortcut “Summarise”") per
 *    the DWP Design System's *Add another thing* accessibility guidance;
 *    never a column of identical "Remove" buttons.
 *  - **Inline errors wired to their control** (`aria-describedby` +
 *    `aria-invalid` + visually hidden "Error:") per the GOV.UK Design System
 *    validation guidance. The form-level Save button is blocked while any
 *    inline error or unapplied JSON draft exists.
 *  - **Live region present at mount** (`role="status"`, `aria-live="polite"`,
 *    `aria-atomic="true"`) announcing add/remove/move — MDN and Sara
 *    Soueidan's guidance that injecting the region together with its first
 *    message is unreliable.
 *  - **WCAG 2.2**: 2.4.11 focus not obscured, 2.5.8 target size (≥24 px),
 *    3.3.1/3.3.3 error identification and suggestion, 1.4.11 non-text
 *    contrast. Reordering uses buttons (not drag-only) so 2.5.7 has a
 *    non-dragging alternative.
 *
 * Server semantics encoded here (from the CPT sanitizers):
 *  - shortcuts: keeps `label`, `payload`, `description`, `hidden`; keeps
 *    `tool` only when the slug resolves in the tool registry; drops entries
 *    with an empty label *and* payload.
 *  - prebuilt shortcuts: map `tool → { mode: 'custom', shortcuts }`; drops
 *    groups with no shortcuts.
 *  - role rules: `tool` must resolve in the registry; `roles` are filtered to
 *    registered role slugs; `groups` are positive integers; `flags` are
 *    filtered to the allowed flag list; drops rules with no roles/groups/flags.
 *  - datasets: `dataset` is required, max 10 entries.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { ChangeEvent, JSX, ReactNode, SyntheticEvent } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import { normaliseAdvancedFields } from '../../api/assistants';
import { McpAppsClient } from '../../api/mcp-apps';
import type { McpAppRecord } from '../../api/mcp-apps';
import { RemoteSitesClient } from '../../api/remote-sites';
import type { RemoteSiteConnection } from '../../api/remote-sites';
import type { SkillOption } from '../../api/skills';
import type {
	AssistantAdvancedFields,
	AssistantPreferredDataset,
	AssistantPrebuiltShortcuts,
	AssistantShortcut,
	AssistantToolRoleRule,
} from '../../api/assistants';
import type { ToolDefinition } from '../../api/tools';

/** Category values the dataset catalogue ships with. */
const DATASET_CATEGORIES = [ '', 'nlp', 'vision', 'audio', 'multimodal' ];

/** Priority values used by the dataset catalogue (critical/high/medium). */
const DATASET_PRIORITIES = [ '', 'critical', 'high', 'medium' ];

/** Flags the CPT allows by default; filters may add more (preserved on read). */
const ROLE_FLAGS = [ 'allow_authenticated', 'allow_guests', 'allow_all_roles' ];

const MAX_DATASETS = 10;

/* -------------------------------------------------------------------------
 * Small helpers
 * ---------------------------------------------------------------------- */

let keySeed = 0;

function nextKey(): string {
	keySeed += 1;
	return `row-${keySeed}`;
}

/** Move an item within a list, returning a new array. */
function moveItem< T >( items: T[], from: number, to: number ): T[] {
	if ( to < 0 || to >= items.length || from === to ) {
		return items;
	}

	const next = items.slice();
	const [ item ] = next.splice( from, 1 );
	next.splice( to, 0, item );
	return next;
}

/** Split a comma-separated field into trimmed, non-empty entries. */
function parseCsv( value: string ): string[] {
	return value
		.split( ',' )
		.map( ( item ) => item.trim() )
		.filter( Boolean );
}

function parseNumericCsv( value: string ): number[] {
	return parseCsv( value )
		.map( ( item ) => Number.parseInt( item, 10 ) )
		.filter( ( id ) => Number.isInteger( id ) && id > 0 );
}

/**
 * Keep a stable React key per row so reordering does not remount the inputs
 * (and therefore does not steal focus from the button the user pressed).
 */
function useRowKeys( length: number ): [ string[], ( updater: ( keys: string[] ) => string[] ) => void ] {
	const [ keys, setKeys ] = useState< string[] >( () =>
		Array.from( { length }, nextKey )
	);

	useEffect( () => {
		setKeys( ( current ) =>
			current.length === length
				? current
				: Array.from( { length }, ( _, index ) => current[ index ] ?? nextKey() )
		);
	}, [ length ] );

	return [ keys, setKeys ];
}

/**
 * Polite live-region announcer. The region must be in the DOM at mount; the
 * message is injected afterwards (MDN / Sara Soueidan guidance).
 */
function useAnnouncer(): [ string, ( message: string ) => void ] {
	const [ message, setMessage ] = useState( '' );
	const announce = useCallback( ( next: string ) => setMessage( next ), [] );
	return [ message, announce ];
}

function LiveStatus( props: { message: string } ): JSX.Element {
	return (
		<p
			className="nvoos-pro-spa-advanced__status"
			role="status"
			aria-live="polite"
			aria-atomic="true"
		>
			{ props.message }
		</p>
	);
}

/* -------------------------------------------------------------------------
 * Field primitives (GOV.UK-style label / hint / inline error)
 * ---------------------------------------------------------------------- */

interface FieldShellProps {
	id: string;
	label: string;
	hint?: string;
	error?: string;
	required?: boolean;
	children: ReactNode;
}

function describedBy(
	id: string,
	hint: string | undefined,
	error: string | undefined
): string | undefined {
	const ids = [
		hint ? `${ id }-hint` : '',
		error ? `${ id }-error` : '',
	].filter( Boolean );

	return ids.length ? ids.join( ' ' ) : undefined;
}

function FieldShell( props: FieldShellProps ): JSX.Element {
	const { id, label, hint, error, required, children } = props;

	return (
		<div className="nvoos-pro-spa-advanced__field">
			<label htmlFor={ id } className="nvoos-pro-spa-advanced__label">
				{ label }
				{ required && (
					<span aria-hidden="true" className="nvoos-pro-spa-advanced__required">
						{ ' *' }
					</span>
				) }
			</label>
			{ children }
			{ hint && (
				<p id={ `${ id }-hint` } className="nvoos-pro-spa-advanced__hint">
					{ hint }
				</p>
			) }
			{ error && (
				<p
					id={ `${ id }-error` }
					className="nvoos-pro-spa-advanced__error"
					role="alert"
				>
					<span className="nvoos-pro-spa-visually-hidden">
						{ __( 'Error:', 'nvoos-pro-spa' ) }{ ' ' }
					</span>
					{ error }
				</p>
			) }
		</div>
	);
}

interface TextFieldProps {
	id: string;
	label: string;
	value: string;
	onChange: ( value: string ) => void;
	hint?: string;
	error?: string;
	required?: boolean;
	placeholder?: string;
	listId?: string;
	inputMode?: 'text' | 'numeric';
}

function TextField( props: TextFieldProps ): JSX.Element {
	return (
		<FieldShell
			id={ props.id }
			label={ props.label }
			hint={ props.hint }
			error={ props.error }
			required={ props.required }
		>
			<input
				id={ props.id }
				type="text"
				className="nvoos-pro-spa-advanced__input"
				value={ props.value }
				placeholder={ props.placeholder }
				inputMode={ props.inputMode }
				list={ props.listId }
				aria-invalid={ props.error ? true : undefined }
				aria-describedby={ describedBy(
					props.id,
					props.hint,
					props.error
				) }
				onChange={ ( event: ChangeEvent< HTMLInputElement > ) =>
					props.onChange( event.target.value )
				}
			/>
		</FieldShell>
	);
}

function TextAreaField( props: TextFieldProps & { rows?: number } ): JSX.Element {
	return (
		<FieldShell
			id={ props.id }
			label={ props.label }
			hint={ props.hint }
			error={ props.error }
			required={ props.required }
		>
			<textarea
				id={ props.id }
				className="nvoos-pro-spa-advanced__textarea"
				rows={ props.rows ?? 2 }
				value={ props.value }
				placeholder={ props.placeholder }
				aria-invalid={ props.error ? true : undefined }
				aria-describedby={ describedBy(
					props.id,
					props.hint,
					props.error
				) }
				onChange={ ( event: ChangeEvent< HTMLTextAreaElement > ) =>
					props.onChange( event.target.value )
				}
			/>
		</FieldShell>
	);
}

interface SelectFieldProps {
	id: string;
	label: string;
	value: string;
	options: Array< { value: string; label: string } >;
	onChange: ( value: string ) => void;
	hint?: string;
}

function SelectField( props: SelectFieldProps ): JSX.Element {
	return (
		<FieldShell id={ props.id } label={ props.label } hint={ props.hint }>
			<select
				id={ props.id }
				className="nvoos-pro-spa-advanced__select"
				value={ props.value }
				aria-describedby={ describedBy( props.id, props.hint, undefined ) }
				onChange={ ( event: ChangeEvent< HTMLSelectElement > ) =>
					props.onChange( event.target.value )
				}
			>
				{ props.options.map( ( option ) => (
					<option key={ option.value } value={ option.value }>
						{ option.label }
					</option>
				) ) }
			</select>
		</FieldShell>
	);
}

/* -------------------------------------------------------------------------
 * Row card with named reorder/remove actions
 * ---------------------------------------------------------------------- */

interface ItemCardProps {
	id: string;
	title: string;
	index: number;
	count: number;
	/** Omit to hide the reorder buttons (order-independent lists). */
	onMove?: ( direction: -1 | 1 ) => void;
	onRemove: () => void;
	removeLabel: string;
	moveSubject: string;
	children: ReactNode;
}

function ItemCard( props: ItemCardProps ): JSX.Element {
	const titleId = `${ props.id }-title`;

	return (
		<div
			className="nvoos-pro-spa-advanced__item"
			role="group"
			aria-labelledby={ titleId }
		>
			<div className="nvoos-pro-spa-advanced__item-header">
				<h5 id={ titleId } className="nvoos-pro-spa-advanced__item-title">
					{ props.title }
				</h5>
				<div className="nvoos-pro-spa-advanced__item-actions">
					{ props.onMove && (
						<>
							<button
								type="button"
								className="nvoos-pro-spa-advanced__icon-btn"
								onClick={ () => props.onMove?.( -1 ) }
								disabled={ props.index === 0 }
								aria-label={ sprintf(
									/* translators: %s: item description, e.g. shortcut “Summarise”. */
									__( 'Move %s up', 'nvoos-pro-spa' ),
									props.moveSubject
								) }
							>
								<span aria-hidden="true">↑</span>
							</button>
							<button
								type="button"
								className="nvoos-pro-spa-advanced__icon-btn"
								onClick={ () => props.onMove?.( 1 ) }
								disabled={ props.index === props.count - 1 }
								aria-label={ sprintf(
									/* translators: %s: item description. */
									__( 'Move %s down', 'nvoos-pro-spa' ),
									props.moveSubject
								) }
							>
								<span aria-hidden="true">↓</span>
							</button>
						</>
					) }
					<button
						type="button"
						className="nvoos-pro-spa-advanced__link-btn"
						onClick={ props.onRemove }
						aria-label={ props.removeLabel }
					>
						{ __( 'Remove', 'nvoos-pro-spa' ) }
					</button>
				</div>
			</div>
			{ props.children }
		</div>
	);
}

/* -------------------------------------------------------------------------
 * Section shell: fieldset + hint + live region + JSON mode
 * ---------------------------------------------------------------------- */

interface AdvancedSectionProps {
	id: string;
	legend: string;
	hint: string;
	addLabel?: string;
	onAdd?: () => void;
	canAdd?: boolean;
	jsonLabel: string;
	/** Accessible name for the JSON textarea (must be unique per section). */
	jsonFieldLabel: string;
	jsonValue: unknown;
	validateJson: ( parsed: unknown ) => string | null;
	/** Apply the parsed value; return the normalised value so the draft can resync. */
	onApplyJson: ( parsed: unknown ) => unknown;
	onJsonBlockedChange: ( blocked: boolean ) => void;
	children: ReactNode;
}

function AdvancedSection( props: AdvancedSectionProps ): JSX.Element {
	const [ jsonOpen, setJsonOpen ] = useState( false );
	const formatted = useMemo(
		() => JSON.stringify( props.jsonValue, null, 2 ),
		[ props.jsonValue ]
	);
	const [ draft, setDraft ] = useState( formatted );
	const [ error, setError ] = useState< string | null >( null );
	// Only a draft the user actually edited counts as dirty. Comparing the
	// untouched draft with `formatted` would mark the section dirty after any
	// structured edit and permanently block Save.
	const [ jsonTouched, setJsonTouched ] = useState( false );

	const dirty =
		jsonTouched && ( draft.trim() !== formatted.trim() || error !== null );

	// Report the blocked state upward so the form-level Save can gate on it.
	const blockedRef = useRef( props.onJsonBlockedChange );
	blockedRef.current = props.onJsonBlockedChange;
	useEffect( () => {
		blockedRef.current( dirty );
	}, [ dirty ] );

	const toggleJson = useCallback(
		( event: SyntheticEvent< HTMLDetailsElement > ) => {
			const open = event.currentTarget.open;
			setJsonOpen( open );

			if ( open ) {
				// Opening reloads the current value so a stale draft cannot
				// silently overwrite edits made in the structured controls.
				setDraft( formatted );
				setError( null );
				setJsonTouched( false );
			}
		},
		[ formatted ]
	);

	const applyJson = useCallback( () => {
		let parsed: unknown;

		try {
			parsed = JSON.parse( draft );
		} catch {
			setError(
				__( 'This is not valid JSON. Check the brackets and quotes.', 'nvoos-pro-spa' )
			);
			return;
		}

		const validationError = props.validateJson( parsed );

		if ( validationError ) {
			setError( validationError );
			return;
		}

		setError( null );
		const normalised = props.onApplyJson( parsed );
		setDraft( JSON.stringify( normalised ?? parsed, null, 2 ) );
		setJsonTouched( false );
	}, [ draft, props ] );

	const revertJson = useCallback( () => {
		setDraft( formatted );
		setError( null );
		setJsonTouched( false );
	}, [ formatted ] );

	const jsonId = `${ props.id }-json`;

	return (
		<fieldset className="nvoos-pro-spa-advanced__section">
			<legend className="nvoos-pro-spa-advanced__legend">
				{ props.legend }
			</legend>
			<p className="nvoos-pro-spa-advanced__section-hint">
				{ props.hint }
			</p>

			<details
				className="nvoos-pro-spa-advanced__json"
				onToggle={ toggleJson }
			>
				<summary className="nvoos-pro-spa-advanced__json-summary">
					{ props.jsonLabel }
					{ dirty && (
						<span className="nvoos-pro-spa-advanced__badge">
							{ __( 'Unapplied changes', 'nvoos-pro-spa' ) }
						</span>
					) }
				</summary>

				<div className="nvoos-pro-spa-advanced__json-body">
					<label
						htmlFor={ jsonId }
						className="nvoos-pro-spa-advanced__label"
					>
						{ props.jsonFieldLabel }
					</label>
					<textarea
						id={ jsonId }
						className="nvoos-pro-spa-advanced__textarea nvoos-pro-spa-advanced__textarea--code"
						rows={ 6 }
						spellCheck={ false }
						value={ draft }
						aria-invalid={ error ? true : undefined }
						aria-describedby={ error ? `${ jsonId }-error` : undefined }
						onChange={ ( event ) => {
							setDraft( event.target.value );
							setJsonTouched( true );
						} }
					/>
					{ error && (
						<p
							id={ `${ jsonId }-error` }
							className="nvoos-pro-spa-advanced__error"
							role="alert"
						>
							<span className="nvoos-pro-spa-visually-hidden">
								{ __( 'Error:', 'nvoos-pro-spa' ) }{ ' ' }
							</span>
							{ error }
						</p>
					) }
					<div className="nvoos-pro-spa-advanced__json-actions">
						<button
							type="button"
							className="nvoos-pro-spa-advanced__btn"
							onClick={ applyJson }
						>
							{ __( 'Apply JSON', 'nvoos-pro-spa' ) }
						</button>
						<button
							type="button"
							className="nvoos-pro-spa-advanced__btn nvoos-pro-spa-advanced__btn--secondary"
							onClick={ revertJson }
							disabled={ ! dirty }
						>
							{ __( 'Revert', 'nvoos-pro-spa' ) }
						</button>
					</div>
				</div>
			</details>

			{ ! jsonOpen && (
				<>
					<div className="nvoos-pro-spa-advanced__items">
						{ props.children }
					</div>
					{ props.onAdd && (
						<button
							type="button"
							className="nvoos-pro-spa-advanced__btn nvoos-pro-spa-advanced__btn--secondary"
							onClick={ props.onAdd }
							disabled={ props.canAdd === false }
						>
							{ props.addLabel }
						</button>
					) }
				</>
			) }
		</fieldset>
	);
}

/* -------------------------------------------------------------------------
 * Shortcuts (used by the top-level list and per-tool overrides)
 * ---------------------------------------------------------------------- */

interface ShortcutFieldsProps {
	id: string;
	item: AssistantShortcut;
	tools: ToolDefinition[];
	knownTools: Set< string >;
	onChange: ( patch: Partial< AssistantShortcut > ) => void;
}

function ShortcutFields( props: ShortcutFieldsProps ): JSX.Element {
	const { id, item, onChange, tools, knownTools } = props;
	const unknownTool =
		item.tool && tools.length > 0 && ! knownTools.has( item.tool );

	return (
		<>
			<div className="nvoos-pro-spa-advanced__row">
				<TextField
					id={ `${ id }-label` }
					label={ __( 'Label', 'nvoos-pro-spa' ) }
					value={ item.label }
					required
					onChange={ ( value ) => onChange( { label: value } ) }
				/>
				<TextField
					id={ `${ id }-tool` }
					label={ __( 'Tool', 'nvoos-pro-spa' ) }
					value={ item.tool ?? '' }
					listId={ `${ id }-tool-options` }
					hint={ __(
						'Optional. Must match a registered tool slug or the server drops it.',
						'nvoos-pro-spa'
					) }
					onChange={ ( value ) => onChange( { tool: value } ) }
				/>
			</div>

			<datalist id={ `${ id }-tool-options` }>
				{ tools.map( ( tool ) => (
					<option key={ tool.slug } value={ tool.slug }>
						{ tool.name }
					</option>
				) ) }
			</datalist>

			{ unknownTool && (
				<p className="nvoos-pro-spa-advanced__warning">
					{ sprintf(
						/* translators: %s: tool slug. */
						__(
							'“%s” is not in the tool registry. The shortcut is kept but the tool assignment is dropped on save.',
							'nvoos-pro-spa'
						),
						item.tool ?? ''
					) }
				</p>
			) }

			<TextField
				id={ `${ id }-description` }
				label={ __( 'Description', 'nvoos-pro-spa' ) }
				value={ item.description ?? '' }
				onChange={ ( value ) => onChange( { description: value } ) }
			/>

			<TextAreaField
				id={ `${ id }-payload` }
				label={ __( 'Payload', 'nvoos-pro-spa' ) }
				value={ item.payload }
				rows={ 2 }
				hint={ __(
					'The text inserted into the prompt when the shortcut is used.',
					'nvoos-pro-spa'
				) }
				onChange={ ( value ) => onChange( { payload: value } ) }
			/>

			<label className="nvoos-pro-spa-advanced__checkbox-row">
				<input
					type="checkbox"
					checked={ Boolean( item.hidden ) }
					onChange={ ( event ) =>
						onChange( { hidden: event.target.checked } )
					}
				/>
				<span>{ __( 'Hide from the shortcut menu', 'nvoos-pro-spa' ) }</span>
			</label>

			{ item.label.trim() === '' && item.payload.trim() === '' && (
				<p className="nvoos-pro-spa-advanced__warning">
					{ __(
						'A shortcut with an empty label and payload is dropped on save.',
						'nvoos-pro-spa'
					) }
				</p>
			) }
		</>
	);
}

interface ShortcutListProps {
	idPrefix: string;
	items: AssistantShortcut[];
	onChange: ( next: AssistantShortcut[] ) => void;
	tools: ToolDefinition[];
	subject: string;
	/** Announced through the owner section's live region. */
	onAnnounce: ( message: string ) => void;
}

function ShortcutList( props: ShortcutListProps ): JSX.Element {
	const { idPrefix, items, onChange, tools, subject, onAnnounce } = props;
	const [ keys, setKeys ] = useRowKeys( items.length );
	const knownTools = useMemo(
		() => new Set( tools.map( ( tool ) => tool.slug ) ),
		[ tools ]
	);

	const update = useCallback(
		( index: number, patch: Partial< AssistantShortcut > ) => {
			onChange(
				items.map( ( item, itemIndex ) =>
					itemIndex === index ? { ...item, ...patch } : item
				)
			);
		},
		[ items, onChange ]
	);

	const remove = useCallback(
		( index: number ) => {
			setKeys( ( current ) => current.filter( ( _, k ) => k !== index ) );
			onChange( items.filter( ( _, itemIndex ) => itemIndex !== index ) );
			onAnnounce( __( 'Shortcut removed.', 'nvoos-pro-spa' ) );
		},
		[ items, onChange, setKeys, onAnnounce ]
	);

	const move = useCallback(
		( index: number, direction: -1 | 1 ) => {
			setKeys( ( current ) => moveItem( current, index, index + direction ) );
			onChange( moveItem( items, index, index + direction ) );
			onAnnounce(
				direction < 0
					? __( 'Shortcut moved up.', 'nvoos-pro-spa' )
					: __( 'Shortcut moved down.', 'nvoos-pro-spa' )
			);
		},
		[ items, onChange, setKeys, onAnnounce ]
	);

	if ( items.length === 0 ) {
		return (
			<p className="nvoos-pro-spa-advanced__empty">
				{ sprintf(
					/* translators: %s: shortcut collection, e.g. "Prompt shortcuts". */
					__( 'No %s yet.', 'nvoos-pro-spa' ),
					subject
				) }
			</p>
		);
	}

	return (
		<>
			{ items.map( ( item, index ) => (
				<ItemCard
					key={ keys[ index ] ?? index }
					id={ `${ idPrefix }-${ index }` }
					index={ index }
					count={ items.length }
					title={
						item.label.trim() ||
						sprintf(
							/* translators: %d: shortcut position. */
							__( 'Shortcut %d', 'nvoos-pro-spa' ),
							index + 1
						)
					}
					moveSubject={ sprintf(
						/* translators: %d: shortcut position. */
						__( 'shortcut %d', 'nvoos-pro-spa' ),
						index + 1
					) }
					removeLabel={ sprintf(
						/* translators: %s: shortcut label. */
						__( 'Remove shortcut “%s”', 'nvoos-pro-spa' ),
						item.label.trim() || String( index + 1 )
					) }
					onMove={ ( direction ) => move( index, direction ) }
					onRemove={ () => remove( index ) }
				>
					<ShortcutFields
						id={ `${ idPrefix }-${ index }` }
						item={ item }
						tools={ tools }
						knownTools={ knownTools }
						onChange={ ( patch ) => update( index, patch ) }
					/>
				</ItemCard>
			) ) }
		</>
	);
}

/* -------------------------------------------------------------------------
 * Per-tool prebuilt shortcut overrides (map editor)
 * ---------------------------------------------------------------------- */

interface PrebuiltRow {
	key: string;
	tool: string;
	shortcuts: AssistantShortcut[];
	error?: string;
}

function rowsFromMap( map: AssistantPrebuiltShortcuts ): PrebuiltRow[] {
	return Object.entries( map ).map( ( [ tool, settings ] ) => ( {
		key: nextKey(),
		tool,
		shortcuts: settings?.shortcuts ?? [],
	} ) );
}

function mapFromRows( rows: PrebuiltRow[] ): AssistantPrebuiltShortcuts {
	const map: AssistantPrebuiltShortcuts = {};

	rows.forEach( ( row ) => {
		const tool = row.tool.trim().toLowerCase().replace( /[^a-z0-9_-]/g, '_' );

		if ( tool === '' || row.error ) {
			return;
		}

		map[ tool ] = { mode: 'custom', shortcuts: row.shortcuts };
	} );

	return map;
}

interface PrebuiltEditorProps {
	idPrefix: string;
	value: AssistantPrebuiltShortcuts;
	onChange: ( next: AssistantPrebuiltShortcuts ) => void;
	tools: ToolDefinition[];
	onJsonBlockedChange: ( blocked: boolean ) => void;
}

function PrebuiltShortcutsEditor( props: PrebuiltEditorProps ): JSX.Element {
	const { idPrefix, value, onChange, tools, onJsonBlockedChange } = props;

	const [ rows, setRows ] = useState< PrebuiltRow[] >( () => rowsFromMap( value ) );
	const [ pendingTool, setPendingTool ] = useState( '' );
	const [ addError, setAddError ] = useState< string | null >( null );
	const [ live, announce ] = useAnnouncer();
	const lastEmitted = useRef< string >( JSON.stringify( value ) );

	// External reset (e.g. a different assistant opened): only when the
	// incoming map differs from what this editor last emitted.
	useEffect( () => {
		const incoming = JSON.stringify( value );

		if ( incoming === lastEmitted.current ) {
			return;
		}

		lastEmitted.current = incoming;
		setRows( rowsFromMap( value ) );
	}, [ value ] );

	const commit = useCallback(
		( next: PrebuiltRow[] ) => {
			// Flag duplicates and blank slugs instead of silently merging them.
			const seen = new Set< string >();
			const checked = next.map( ( row ) => {
				const tool = row.tool.trim().toLowerCase().replace( /[^a-z0-9_-]/g, '_' );
				let error: string | undefined;

				if ( tool === '' ) {
					error = __( 'A tool slug is required.', 'nvoos-pro-spa' );
				} else if ( seen.has( tool ) ) {
					error = __( 'Another group already uses this tool.', 'nvoos-pro-spa' );
				}

				if ( tool !== '' ) {
					seen.add( tool );
				}

				return { ...row, error };
			} );

			setRows( checked );
			const map = mapFromRows( checked );
			lastEmitted.current = JSON.stringify( map );
			onChange( map );
		},
		[ onChange ]
	);

	const addRow = useCallback( () => {
		const tool = pendingTool.trim().toLowerCase().replace( /[^a-z0-9_-]/g, '_' );

		if ( tool === '' ) {
			setAddError( __( 'Enter a tool slug first.', 'nvoos-pro-spa' ) );
			return;
		}

		if ( rows.some( ( row ) => row.tool === tool ) ) {
			setAddError( __( 'That tool already has a group.', 'nvoos-pro-spa' ) );
			return;
		}

		setAddError( null );
		setPendingTool( '' );
		commit( [
			...rows,
			{ key: nextKey(), tool, shortcuts: [] },
		] );
		announce( __( 'Tool group added.', 'nvoos-pro-spa' ) );
	}, [ commit, pendingTool, rows, announce ] );

	return (
		<AdvancedSection
			id={ `${ idPrefix }-prebuilt` }
			legend={ __( 'Prebuilt shortcut overrides', 'nvoos-pro-spa' ) }
			hint={ __(
				'One group per tool. A group replaces that tool’s default buttons; groups without shortcuts are dropped on save.',
				'nvoos-pro-spa'
			) }
			jsonLabel={ __( 'Edit as JSON', 'nvoos-pro-spa' ) }
			jsonFieldLabel={ __( 'Prebuilt overrides JSON', 'nvoos-pro-spa' ) }
			jsonValue={ value }
			onJsonBlockedChange={ onJsonBlockedChange }
			validateJson={ ( parsed ) =>
				parsed && typeof parsed === 'object' && ! Array.isArray( parsed )
					? null
					: __( 'Expected a JSON object keyed by tool slug.', 'nvoos-pro-spa' )
			}
			onApplyJson={ ( parsed ) => {
				const next = normaliseAdvancedFields( {
					tool_prebuilt_shortcuts: parsed,
				} ).tool_prebuilt_shortcuts;
				onChange( next );
				return next;
			} }
		>
			{ rows.map( ( row, index ) => (
				<ItemCard
					key={ row.key }
					id={ `${ idPrefix }-prebuilt-${ index }` }
					index={ index }
					count={ rows.length }
					title={
						row.tool ||
						sprintf(
							/* translators: %d: group position. */
							__( 'Tool group %d', 'nvoos-pro-spa' ),
							index + 1
						)
					}
					moveSubject={ sprintf(
						/* translators: %d: group position. */
						__( 'tool group %d', 'nvoos-pro-spa' ),
						index + 1
					) }
					removeLabel={ sprintf(
						/* translators: %s: tool slug. */
						__( 'Remove tool group “%s”', 'nvoos-pro-spa' ),
						row.tool || String( index + 1 )
					) }
					onMove={ ( direction ) => {
						commit( moveItem( rows, index, index + direction ) );
						announce(
							direction < 0
								? __( 'Tool group moved up.', 'nvoos-pro-spa' )
								: __( 'Tool group moved down.', 'nvoos-pro-spa' )
						);
					} }
					onRemove={ () => {
						commit(
							rows.filter( ( _, rowIndex ) => rowIndex !== index )
						);
						announce( __( 'Tool group removed.', 'nvoos-pro-spa' ) );
					} }
				>
					<TextField
						id={ `${ idPrefix }-prebuilt-${ index }-tool` }
						label={ __( 'Tool slug', 'nvoos-pro-spa' ) }
						value={ row.tool }
						listId={ `${ idPrefix }-prebuilt-tools` }
						error={ row.error }
						onChange={ ( nextTool ) =>
							commit(
								rows.map( ( candidate, rowIndex ) =>
									rowIndex === index
										? { ...candidate, tool: nextTool }
										: candidate
								)
							)
						}
					/>

					<div className="nvoos-pro-spa-advanced__nested">
						<ShortcutList
							idPrefix={ `${ idPrefix }-prebuilt-${ index }-shortcut` }
							items={ row.shortcuts }
							tools={ tools }
							subject={ __( 'shortcuts', 'nvoos-pro-spa' ) }
							onAnnounce={ announce }
							onChange={ ( shortcuts ) =>
								commit(
									rows.map( ( candidate, rowIndex ) =>
										rowIndex === index
											? { ...candidate, shortcuts }
											: candidate
									)
								)
							}
						/>
						<button
							type="button"
							className="nvoos-pro-spa-advanced__btn nvoos-pro-spa-advanced__btn--secondary"
							onClick={ () => {
								commit(
									rows.map( ( candidate, rowIndex ) =>
										rowIndex === index
											? {
													...candidate,
													shortcuts: [
														...candidate.shortcuts,
														{ label: '', payload: '' },
													],
											  }
											: candidate
									)
								);
								announce( __( 'Shortcut added.', 'nvoos-pro-spa' ) );
							} }
						>
							{ __( 'Add shortcut', 'nvoos-pro-spa' ) }
						</button>
					</div>

					{ row.shortcuts.length === 0 && (
						<p className="nvoos-pro-spa-advanced__warning">
							{ __(
								'A group without shortcuts is dropped on save.',
								'nvoos-pro-spa'
							) }
						</p>
					) }
				</ItemCard>
			) ) }

			<datalist id={ `${ idPrefix }-prebuilt-tools` }>
				{ tools.map( ( tool ) => (
					<option key={ tool.slug } value={ tool.slug }>
						{ tool.name }
					</option>
				) ) }
			</datalist>

			<div className="nvoos-pro-spa-advanced__add-row">
				<TextField
					id={ `${ idPrefix }-prebuilt-new` }
					label={ __( 'Add a tool group', 'nvoos-pro-spa' ) }
					value={ pendingTool }
					listId={ `${ idPrefix }-prebuilt-tools` }
					error={ addError ?? undefined }
					onChange={ ( nextTool ) => {
						setPendingTool( nextTool );
						setAddError( null );
					} }
				/>
				<button
					type="button"
					className="nvoos-pro-spa-advanced__btn"
					onClick={ addRow }
				>
					{ __( 'Add group', 'nvoos-pro-spa' ) }
				</button>
			</div>

			<LiveStatus message={ live } />
		</AdvancedSection>
	);
}

/* -------------------------------------------------------------------------
 * Tool role rules
 * ---------------------------------------------------------------------- */

interface RoleRulesEditorProps {
	idPrefix: string;
	items: AssistantToolRoleRule[];
	onChange: ( next: AssistantToolRoleRule[] ) => void;
	tools: ToolDefinition[];
	onJsonBlockedChange: ( blocked: boolean ) => void;
}

function RoleRulesEditor( props: RoleRulesEditorProps ): JSX.Element {
	const { idPrefix, items, onChange, tools, onJsonBlockedChange } = props;
	const [ keys, setKeys ] = useRowKeys( items.length );
	const [ live, announce ] = useAnnouncer();
	const knownTools = useMemo(
		() => new Set( tools.map( ( tool ) => tool.slug ) ),
		[ tools ]
	);

	const update = useCallback(
		( index: number, patch: Partial< AssistantToolRoleRule > ) => {
			onChange(
				items.map( ( rule, ruleIndex ) =>
					ruleIndex === index ? { ...rule, ...patch } : rule
				)
			);
		},
		[ items, onChange ]
	);

	const flagsFor = ( rule: AssistantToolRoleRule ): string[] => {
		const known = rule.flags ?? [];
		// Preserve filter-added flags that are not in the default list.
		return Array.from( new Set( [ ...ROLE_FLAGS, ...known ] ) );
	};

	const addRule = useCallback( () => {
		setKeys( ( current ) => [ ...current, nextKey() ] );
		onChange( [ ...items, { tool: '' } ] );
		announce( __( 'Rule added.', 'nvoos-pro-spa' ) );
	}, [ items, onChange, setKeys, announce ] );

	return (
		<AdvancedSection
			id={ `${ idPrefix }-role-rules` }
			legend={ __( 'Tool role rules', 'nvoos-pro-spa' ) }
			hint={ __(
				'Restrict individual tools by WordPress role, group, or access flag. A rule with no roles, groups, or flags is dropped on save.',
				'nvoos-pro-spa'
			) }
			addLabel={ __( 'Add rule', 'nvoos-pro-spa' ) }
			onAdd={ addRule }
			jsonLabel={ __( 'Edit as JSON', 'nvoos-pro-spa' ) }
			jsonFieldLabel={ __( 'Role rules JSON', 'nvoos-pro-spa' ) }
			jsonValue={ items }
			onJsonBlockedChange={ onJsonBlockedChange }
			validateJson={ ( parsed ) =>
				Array.isArray( parsed ) &&
				parsed.every(
					( rule ) =>
						rule &&
						typeof rule === 'object' &&
						typeof ( rule as Record< string, unknown > ).tool === 'string'
				)
					? null
					: __(
							'Expected a JSON array of rules, each with a “tool” string.',
							'nvoos-pro-spa'
					  )
			}
			onApplyJson={ ( parsed ) => {
				const next = normaliseAdvancedFields( {
					tool_role_rules: parsed,
				} ).tool_role_rules;
				onChange( next );
				return next;
			} }
		>
			{ items.length === 0 ? (
				<p className="nvoos-pro-spa-advanced__empty">
					{ __( 'No role rules yet.', 'nvoos-pro-spa' ) }
				</p>
			) : (
				items.map( ( rule, index ) => {
					const flags = flagsFor( rule );
					const selectedFlags = rule.flags ?? [];
					const unknownTool =
						rule.tool !== '' &&
						tools.length > 0 &&
						! knownTools.has( rule.tool );
					const emptyRule =
						( rule.roles?.length ?? 0 ) === 0 &&
						( rule.groups?.length ?? 0 ) === 0 &&
						selectedFlags.length === 0;

					return (
						<ItemCard
							key={ keys[ index ] ?? index }
							id={ `${ idPrefix }-role-rule-${ index }` }
							index={ index }
							count={ items.length }
							title={
								rule.tool ||
								sprintf(
									/* translators: %d: rule position. */
									__( 'Rule %d', 'nvoos-pro-spa' ),
									index + 1
								)
							}
							moveSubject={ sprintf(
								/* translators: %d: rule position. */
								__( 'rule %d', 'nvoos-pro-spa' ),
								index + 1
							) }
							removeLabel={ sprintf(
								/* translators: %s: tool slug. */
								__( 'Remove rule for “%s”', 'nvoos-pro-spa' ),
								rule.tool || String( index + 1 )
							) }
							onMove={ ( direction ) => {
								setKeys( ( current ) =>
									moveItem( current, index, index + direction )
								);
								onChange( moveItem( items, index, index + direction ) );
								announce(
									direction < 0
										? __( 'Rule moved up.', 'nvoos-pro-spa' )
										: __( 'Rule moved down.', 'nvoos-pro-spa' )
								);
							} }
							onRemove={ () => {
								setKeys( ( current ) =>
									current.filter( ( _, k ) => k !== index )
								);
								onChange(
									items.filter(
										( _, ruleIndex ) => ruleIndex !== index
									)
								);
								announce( __( 'Rule removed.', 'nvoos-pro-spa' ) );
							} }
						>
							<TextField
								id={ `${ idPrefix }-role-rule-${ index }-tool` }
								label={ __( 'Tool', 'nvoos-pro-spa' ) }
								value={ rule.tool }
								required
								listId={ `${ idPrefix }-role-rule-tools` }
								onChange={ ( value ) =>
									update( index, { tool: value } )
								}
							/>

							{ unknownTool && (
								<p className="nvoos-pro-spa-advanced__warning">
									{ sprintf(
										/* translators: %s: tool slug. */
										__(
											'“%s” is not in the tool registry. Rules for unknown tools are dropped on save.',
											'nvoos-pro-spa'
										),
										rule.tool
									) }
								</p>
							) }

							<div className="nvoos-pro-spa-advanced__row">
								<TextField
									id={ `${ idPrefix }-role-rule-${ index }-roles` }
									label={ __( 'Roles', 'nvoos-pro-spa' ) }
									value={ ( rule.roles ?? [] ).join( ', ' ) }
									hint={ __(
										'Comma-separated role slugs (administrator, editor, author…). Unknown roles are dropped on save.',
										'nvoos-pro-spa'
									) }
									onChange={ ( value ) =>
										update( index, {
											roles: parseCsv( value ),
										} )
									}
								/>
								<TextField
									id={ `${ idPrefix }-role-rule-${ index }-groups` }
									label={ __( 'Groups', 'nvoos-pro-spa' ) }
									value={ ( rule.groups ?? [] ).join( ', ' ) }
									inputMode="numeric"
									hint={ __(
										'Comma-separated numeric group IDs.',
										'nvoos-pro-spa'
									) }
									onChange={ ( value ) =>
										update( index, {
											groups: parseNumericCsv( value ),
										} )
									}
								/>
							</div>

							<fieldset className="nvoos-pro-spa-advanced__flags">
								<legend className="nvoos-pro-spa-advanced__label">
									{ __( 'Access flags', 'nvoos-pro-spa' ) }
								</legend>
								{ flags.map( ( flag ) => (
									<label
										key={ flag }
										className="nvoos-pro-spa-advanced__checkbox-row"
									>
										<input
											type="checkbox"
											checked={ selectedFlags.includes( flag ) }
											onChange={ ( event ) => {
												const next = event.target.checked
													? [ ...selectedFlags, flag ]
													: selectedFlags.filter(
															( item ) => item !== flag
													  );
												update( index, { flags: next } );
											} }
										/>
										<span>{ flag }</span>
									</label>
								) ) }
							</fieldset>

							{ ( rule.tool === '' || emptyRule ) && (
								<p className="nvoos-pro-spa-advanced__warning">
									{ __(
										'A rule needs a tool and at least one role, group, or flag; otherwise it is dropped on save.',
										'nvoos-pro-spa'
									) }
								</p>
							) }
						</ItemCard>
					);
				} )
			) }

			<datalist id={ `${ idPrefix }-role-rule-tools` }>
				{ tools.map( ( tool ) => (
					<option key={ tool.slug } value={ tool.slug }>
						{ tool.name }
					</option>
				) ) }
			</datalist>

			<LiveStatus message={ live } />
		</AdvancedSection>
	);
}

/* -------------------------------------------------------------------------
 * Preferred datasets
 * ---------------------------------------------------------------------- */

interface DatasetsEditorProps {
	idPrefix: string;
	items: AssistantPreferredDataset[];
	onChange: ( next: AssistantPreferredDataset[] ) => void;
	onJsonBlockedChange: ( blocked: boolean ) => void;
}

function DatasetsEditor( props: DatasetsEditorProps ): JSX.Element {
	const { idPrefix, items, onChange, onJsonBlockedChange } = props;
	const [ keys, setKeys ] = useRowKeys( items.length );
	const [ live, announce ] = useAnnouncer();

	const update = useCallback(
		( index: number, patch: Partial< AssistantPreferredDataset > ) => {
			onChange(
				items.map( ( dataset, datasetIndex ) =>
					datasetIndex === index
						? { ...dataset, ...patch }
						: dataset
				)
			);
		},
		[ items, onChange ]
	);

	const add = useCallback( () => {
		setKeys( ( current ) => [ ...current, nextKey() ] );
		onChange( [ ...items, { dataset: '' } ] );
		announce( __( 'Dataset added.', 'nvoos-pro-spa' ) );
	}, [ items, onChange, setKeys, announce ] );

	return (
		<AdvancedSection
			id={ `${ idPrefix }-datasets` }
			legend={ __( 'Preferred datasets', 'nvoos-pro-spa' ) }
			hint={ sprintf(
				/* translators: %d: maximum number of datasets. */
				__(
					'Base-knowledge datasets this assistant prefers (maximum %d). Entries without a dataset name are dropped on save.',
					'nvoos-pro-spa'
				),
				MAX_DATASETS
			) }
			addLabel={ __( 'Add dataset', 'nvoos-pro-spa' ) }
			onAdd={ add }
			canAdd={ items.length < MAX_DATASETS }
			jsonLabel={ __( 'Edit as JSON', 'nvoos-pro-spa' ) }
			jsonFieldLabel={ __( 'Preferred datasets JSON', 'nvoos-pro-spa' ) }
			jsonValue={ items }
			onJsonBlockedChange={ onJsonBlockedChange }
			validateJson={ ( parsed ) =>
				Array.isArray( parsed ) &&
				parsed.every(
					( dataset ) =>
						dataset &&
						typeof dataset === 'object' &&
						typeof ( dataset as Record< string, unknown > ).dataset ===
							'string'
				)
					? null
					: __(
							'Expected a JSON array of datasets, each with a “dataset” string.',
							'nvoos-pro-spa'
					  )
			}
			onApplyJson={ ( parsed ) => {
				const next = normaliseAdvancedFields( {
					preferred_datasets: parsed,
				} ).preferred_datasets;
				onChange( next );
				return next;
			} }
		>
			{ items.length === 0 ? (
				<p className="nvoos-pro-spa-advanced__empty">
					{ __( 'No dataset preferences yet.', 'nvoos-pro-spa' ) }
				</p>
			) : (
				items.map( ( dataset, index ) => (
					<ItemCard
						key={ keys[ index ] ?? index }
						id={ `${ idPrefix }-dataset-${ index }` }
						index={ index }
						count={ items.length }
						title={
							dataset.name ||
							dataset.dataset ||
							sprintf(
								/* translators: %d: dataset position. */
								__( 'Dataset %d', 'nvoos-pro-spa' ),
								index + 1
							)
						}
						moveSubject={ sprintf(
							/* translators: %d: dataset position. */
							__( 'dataset %d', 'nvoos-pro-spa' ),
							index + 1
						) }
						removeLabel={ sprintf(
							/* translators: %s: dataset identifier. */
							__( 'Remove dataset “%s”', 'nvoos-pro-spa' ),
							dataset.dataset || String( index + 1 )
						) }
						onMove={ ( direction ) => {
							setKeys( ( current ) =>
								moveItem( current, index, index + direction )
							);
							onChange( moveItem( items, index, index + direction ) );
							announce(
								direction < 0
									? __( 'Dataset moved up.', 'nvoos-pro-spa' )
									: __( 'Dataset moved down.', 'nvoos-pro-spa' )
							);
						} }
						onRemove={ () => {
							setKeys( ( current ) =>
								current.filter( ( _, k ) => k !== index )
							);
							onChange(
								items.filter(
									( _, datasetIndex ) => datasetIndex !== index
								)
							);
							announce( __( 'Dataset removed.', 'nvoos-pro-spa' ) );
						} }
					>
						<div className="nvoos-pro-spa-advanced__row">
							<TextField
								id={ `${ idPrefix }-dataset-${ index }-id` }
								label={ __( 'Dataset', 'nvoos-pro-spa' ) }
								value={ dataset.dataset }
								required
								placeholder="huggingface/dataset-id"
								onChange={ ( value ) =>
									update( index, { dataset: value } )
								}
							/>
							<TextField
								id={ `${ idPrefix }-dataset-${ index }-name` }
								label={ __( 'Display name', 'nvoos-pro-spa' ) }
								value={ dataset.name ?? '' }
								onChange={ ( value ) =>
									update( index, { name: value } )
								}
							/>
						</div>

						<div className="nvoos-pro-spa-advanced__row">
							<SelectField
								id={ `${ idPrefix }-dataset-${ index }-category` }
								label={ __( 'Category', 'nvoos-pro-spa' ) }
								value={ dataset.category ?? '' }
								options={ DATASET_CATEGORIES.map( ( category ) => ( {
									value: category,
									label: category === ''
										? __( 'No category', 'nvoos-pro-spa' )
										: category,
								} ) ) }
								onChange={ ( value ) =>
									update( index, { category: value } )
								}
							/>
							<SelectField
								id={ `${ idPrefix }-dataset-${ index }-priority` }
								label={ __( 'Priority', 'nvoos-pro-spa' ) }
								value={ dataset.priority ?? '' }
								options={ DATASET_PRIORITIES.map( ( priority ) => ( {
									value: priority,
									label: priority === ''
										? __( 'No priority', 'nvoos-pro-spa' )
										: priority,
								} ) ) }
								onChange={ ( value ) =>
									update( index, { priority: value } )
								}
							/>
						</div>

						{ dataset.dataset.trim() === '' && (
							<p className="nvoos-pro-spa-advanced__warning">
								{ __(
									'A dataset without an identifier is dropped on save.',
									'nvoos-pro-spa'
								) }
							</p>
						) }
					</ItemCard>
				) )
			) }

			<LiveStatus message={ live } />
		</AdvancedSection>
	);
}

/* -------------------------------------------------------------------------
 * Top-level shortcuts section
 * ---------------------------------------------------------------------- */

interface ShortcutsSectionProps {
	idPrefix: string;
	items: AssistantShortcut[];
	onChange: ( next: AssistantShortcut[] ) => void;
	tools: ToolDefinition[];
	onJsonBlockedChange: ( blocked: boolean ) => void;
}

function ShortcutsEditor( props: ShortcutsSectionProps ): JSX.Element {
	const { idPrefix, items, onChange, tools, onJsonBlockedChange } = props;
	const [ live, announce ] = useAnnouncer();

	const add = useCallback( () => {
		onChange( [ ...items, { label: '', payload: '' } ] );
		announce( __( 'Shortcut added.', 'nvoos-pro-spa' ) );
	}, [ items, onChange, announce ] );

	return (
		<AdvancedSection
			id={ `${ idPrefix }-shortcuts` }
			legend={ __( 'Prompt shortcuts', 'nvoos-pro-spa' ) }
			hint={ __(
				'Buttons that insert a prepared prompt. Order is the order they appear in chat.',
				'nvoos-pro-spa'
			) }
			addLabel={ __( 'Add shortcut', 'nvoos-pro-spa' ) }
			onAdd={ add }
			jsonLabel={ __( 'Edit as JSON', 'nvoos-pro-spa' ) }
			jsonFieldLabel={ __( 'Prompt shortcuts JSON', 'nvoos-pro-spa' ) }
			jsonValue={ items }
			onJsonBlockedChange={ onJsonBlockedChange }
			validateJson={ ( parsed ) =>
				Array.isArray( parsed ) &&
				parsed.every(
					( shortcut ) =>
						shortcut &&
						typeof shortcut === 'object' &&
						typeof ( shortcut as Record< string, unknown > ).label ===
							'string'
				)
					? null
					: __(
							'Expected a JSON array of shortcuts, each with a “label” string.',
							'nvoos-pro-spa'
					  )
			}
			onApplyJson={ ( parsed ) => {
				const next = normaliseAdvancedFields( {
					tool_shortcuts: parsed,
				} ).tool_shortcuts;
				onChange( next );
				return next;
			} }
		>
			<ShortcutList
				idPrefix={ `${ idPrefix }-shortcut` }
				items={ items }
				onChange={ onChange }
				tools={ tools }
				subject={ __( 'prompt shortcuts', 'nvoos-pro-spa' ) }
				onAnnounce={ announce }
			/>
			<LiveStatus message={ live } />
		</AdvancedSection>
	);
}

/* -------------------------------------------------------------------------
 * Agent Skills picker
 * ---------------------------------------------------------------------- */

interface SkillsEditorProps {
	value: AssistantAdvancedFields;
	onChange: ( changes: Partial< AssistantAdvancedFields > ) => void;
	skills: SkillOption[];
	loading: boolean;
	error: string | null;
	onRetry?: () => void;
}

function SkillsEditor( props: SkillsEditorProps ): JSX.Element {
	const { value, onChange, skills, loading, error, onRetry } = props;
	const [ search, setSearch ] = useState( '' );
	const searchId = 'nvoos-pro-spa-advanced-skill-search';

	const installed = useMemo(
		() => new Set( skills.map( ( skill ) => skill.name ) ),
		[ skills ]
	);
	const assigned = value.skills;

	// Skills assigned on another runtime (or since uninstalled) stay selected
	// and are flagged rather than silently dropped.
	const missing = assigned.filter(
		( name ) => skills.length > 0 && ! installed.has( name )
	);

	const needle = search.trim().toLowerCase();
	const visible = useMemo(
		() =>
			skills.filter(
				( skill ) =>
					! needle ||
					skill.name.toLowerCase().includes( needle ) ||
					skill.description.toLowerCase().includes( needle )
			),
		[ skills, needle ]
	);

	const toggle = ( name: string, checked: boolean ) => {
		onChange( {
			skills: checked
				? Array.from( new Set( [ ...assigned, name ] ) )
				: assigned.filter( ( item ) => item !== name ),
		} );
	};

	return (
		<fieldset className="nvoos-pro-spa-advanced__section">
			<legend className="nvoos-pro-spa-advanced__legend">
				{ __( 'Agent Skills', 'nvoos-pro-spa' ) }
			</legend>
			<p className="nvoos-pro-spa-advanced__section-hint">
				{ __(
					'Skill instructions are injected into the system prompt. Assigning a skill does not install it.',
					'nvoos-pro-spa'
				) }
			</p>

			{ loading && (
				<p className="nvoos-pro-spa-advanced__hint">
					{ __( 'Loading skills…', 'nvoos-pro-spa' ) }
				</p>
			) }

			{ error && (
				<p className="nvoos-pro-spa-advanced__error" role="alert">
					<span className="nvoos-pro-spa-visually-hidden">
						{ __( 'Error:', 'nvoos-pro-spa' ) }{ ' ' }
					</span>
					{ error }
					{ onRetry && (
						<>
							{ ' ' }
							<button
								type="button"
								className="nvoos-pro-spa-advanced__link-btn"
								onClick={ onRetry }
							>
								{ __( 'Retry', 'nvoos-pro-spa' ) }
							</button>
						</>
					) }
				</p>
			) }

			{ ! loading && ! error && skills.length === 0 && (
				<p className="nvoos-pro-spa-advanced__empty">
					{ __(
						'No skills are installed yet. Add SKILL.md bundles from the Skills manager, then reopen this editor.',
						'nvoos-pro-spa'
					) }
				</p>
			) }

			{ skills.length > 0 && (
				<>
					<FieldShell id={ searchId } label={ __( 'Search skills', 'nvoos-pro-spa' ) }>
						<input
							id={ searchId }
							type="search"
							className="nvoos-pro-spa-advanced__input"
							value={ search }
							onChange={ ( event ) => setSearch( event.target.value ) }
						/>
					</FieldShell>

					{ missing.length > 0 && (
						<p className="nvoos-pro-spa-advanced__warning">
							{ sprintf(
								/* translators: %s: comma-separated skill names. */
								__(
									'Assigned skills that are not installed here: %s. They are kept but have no effect until installed.',
									'nvoos-pro-spa'
								),
								missing.join( ', ' )
							) }
						</p>
					) }

					<div className="nvoos-pro-spa-advanced__skill-list">
						{ visible.map( ( skill ) => (
							<label
								key={ skill.name }
								className="nvoos-pro-spa-advanced__checkbox-row"
							>
								<input
									type="checkbox"
									checked={ assigned.includes( skill.name ) }
									onChange={ ( event ) =>
										toggle( skill.name, event.target.checked )
									}
								/>
								<span>
									<strong>{ skill.name }</strong>
									{ skill.description ? ` — ${ skill.description }` : '' }
									{ skill.license ? ` (${ skill.license })` : '' }
								</span>
							</label>
						) ) }
						{ visible.length === 0 && (
							<p className="nvoos-pro-spa-advanced__empty">
								{ __( 'No skills match your search.', 'nvoos-pro-spa' ) }
							</p>
						) }
					</div>

					<p className="nvoos-pro-spa-advanced__hint">
						{ sprintf(
							/* translators: 1: selected count, 2: installed count. */
							__( '%d selected of %d installed.', 'nvoos-pro-spa' ),
							assigned.length,
							skills.length
						) }
					</p>

					<label className="nvoos-pro-spa-advanced__checkbox-row">
						<input
							type="checkbox"
							checked={ value.skills_progressive }
							onChange={ ( event ) =>
								onChange( {
									skills_progressive: event.target.checked,
								} )
							}
						/>
						<span>
							{ __(
								'Use progressive disclosure (recommended for many skills)',
								'nvoos-pro-spa'
							) }
						</span>
					</label>
					<p className="nvoos-pro-spa-advanced__hint">
						{ __(
							'Only a short skill catalogue is injected into the prompt; the model loads full instructions on demand with the load_skill tool.',
							'nvoos-pro-spa'
						) }
					</p>
				</>
			) }
		</fieldset>
	);
}

/* -------------------------------------------------------------------------
 * MCP Apps panel (self-contained: separate resource, separate save)
 * ---------------------------------------------------------------------- */

interface McpAppsEditorProps {
	endpoint: string;
	remoteSitesEndpoint: string;
	nonce: string;
	assistantId: number | null;
}

function McpAppsEditor( props: McpAppsEditorProps ): JSX.Element | null {
	const { endpoint, remoteSitesEndpoint, nonce, assistantId } = props;
	const [ apps, setApps ] = useState< McpAppRecord[] >( [] );
	const [ connections, setConnections ] = useState< RemoteSiteConnection[] >( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );
	const [ pendingRef, setPendingRef ] = useState( '' );
	const [ pendingLabel, setPendingLabel ] = useState( '' );
	const [ live, announce ] = useAnnouncer();

	const load = useCallback(
		( signal?: AbortSignal ) => {
			if ( ! endpoint || ! assistantId ) {
				return Promise.resolve();
			}

			setLoading( true );
			setError( null );

			const client = new McpAppsClient( { endpoint, nonce } );

			return client
				.list( assistantId, signal )
				.then( ( response ) => {
					setApps( Array.isArray( response.apps ) ? response.apps : [] );
					setDirty( false );
				} )
				.catch( ( err: unknown ) => {
					if ( err instanceof DOMException && err.name === 'AbortError' ) {
						return;
					}
					setError(
						err instanceof Error
							? err.message
							: __( 'Could not load MCP apps.', 'nvoos-pro-spa' )
					);
				} )
				.finally( () => setLoading( false ) );
		},
		[ assistantId, endpoint, nonce ]
	);

	useEffect( () => {
		if ( ! endpoint || ! assistantId ) {
			return;
		}

		const controller = new AbortController();
		void load( controller.signal );

		if ( remoteSitesEndpoint ) {
			const remote = new RemoteSitesClient( {
				endpoint: remoteSitesEndpoint,
				nonce,
			} );

			void remote
				.list( controller.signal )
				.then( ( response ) => {
					setConnections(
						Array.isArray( response.items ) ? response.items : []
					);
				} )
				.catch( () => {
					// Connections are optional for this panel; a failure to list
					// them must not hide the already-assigned apps.
				} );
		}

		return () => controller.abort();
	}, [ assistantId, endpoint, load, nonce, remoteSitesEndpoint ] );

	const updateApp = useCallback(
		( index: number, patch: Partial< McpAppRecord > ) => {
			setApps( ( current ) =>
				current.map( ( app, appIndex ) =>
					appIndex === index ? { ...app, ...patch } : app
				)
			);
			setDirty( true );
		},
		[]
	);

	const removeApp = useCallback( ( index: number ) => {
		setApps( ( current ) => current.filter( ( _, appIndex ) => appIndex !== index ) );
		setDirty( true );
		announce( __( 'MCP app removed. Save to apply.', 'nvoos-pro-spa' ) );
	}, [ announce ] );

	const availableConnections = useMemo(
		() =>
			connections.filter(
				( connection ) =>
					! apps.some(
						( app ) => app.connection_ref === connection.id
					)
			),
		[ apps, connections ]
	);

	const addApp = useCallback( () => {
		if ( '' === pendingRef ) {
			setError( __( 'Choose a connection first.', 'nvoos-pro-spa' ) );
			return;
		}

		const connection = connections.find( ( item ) => item.id === pendingRef );

		setApps( ( current ) => [
			...current,
			{
				label: pendingLabel.trim() || connection?.name || pendingRef,
				// A connection reference resolves its endpoint at chat time;
				// no inline URL or secret is stored here.
				server_url: '',
				connection_ref: pendingRef,
				auth_type: 'none',
				header_name: '',
				enabled: true,
				timeout: 30,
				verify_ssl: true,
			},
		] );
		setPendingRef( '' );
		setPendingLabel( '' );
		setError( null );
		setDirty( true );
		announce( __( 'MCP app added. Save to apply.', 'nvoos-pro-spa' ) );
	}, [ announce, connections, pendingLabel, pendingRef ] );

	const save = useCallback( async () => {
		if ( ! assistantId ) {
			return;
		}

		setSaving( true );
		setError( null );

		try {
			const response = await new McpAppsClient( {
				endpoint,
				nonce,
			} ).save( assistantId, apps );

			setApps( Array.isArray( response.apps ) ? response.apps : [] );
			setDirty( false );
			announce( __( 'MCP apps saved.', 'nvoos-pro-spa' ) );
		} catch ( err ) {
			setError(
				err instanceof Error
					? err.message
					: __( 'Could not save MCP apps.', 'nvoos-pro-spa' )
			);
		} finally {
			setSaving( false );
		}
	}, [ announce, apps, assistantId, endpoint, nonce ] );

	if ( '' === endpoint ) {
		return null;
	}

	if ( assistantId === null ) {
		return (
			<fieldset className="nvoos-pro-spa-advanced__section">
				<legend className="nvoos-pro-spa-advanced__legend">
					{ __( 'MCP apps', 'nvoos-pro-spa' ) }
				</legend>
				<p className="nvoos-pro-spa-advanced__hint">
					{ __(
						'Save this assistant first, then reopen it to pick MCP apps.',
						'nvoos-pro-spa'
					) }
				</p>
			</fieldset>
		);
	}

	return (
		<fieldset className="nvoos-pro-spa-advanced__section">
			<legend className="nvoos-pro-spa-advanced__legend">
				{ __( 'MCP apps', 'nvoos-pro-spa' ) }
			</legend>
			<p className="nvoos-pro-spa-advanced__section-hint">
				{ __(
					'External MCP servers exposed as tools for this assistant. Credentials stay in the connection; this panel never receives them. Changes here apply immediately.',
					'nvoos-pro-spa'
				) }
			</p>

			{ loading && (
				<p className="nvoos-pro-spa-advanced__hint">
					{ __( 'Loading MCP apps…', 'nvoos-pro-spa' ) }
				</p>
			) }

			{ error && (
				<p className="nvoos-pro-spa-advanced__error" role="alert">
					<span className="nvoos-pro-spa-visually-hidden">
						{ __( 'Error:', 'nvoos-pro-spa' ) }{ ' ' }
					</span>
					{ error }
				</p>
			) }

			{ ! loading && apps.length === 0 && (
				<p className="nvoos-pro-spa-advanced__empty">
					{ __( 'No MCP apps assigned.', 'nvoos-pro-spa' ) }
				</p>
			) }

			{ apps.map( ( app, index ) => {
				const target = app.connection_ref
					? `${ __( 'Connection', 'nvoos-pro-spa' ) }: ${ app.connection_ref }`
					: app.server_url;

				return (
					<ItemCard
						key={ `${ app.connection_ref || app.server_url }-${ index }` }
						id={ `nvoos-pro-spa-advanced-mcp-app-${ index }` }
						index={ index }
						count={ apps.length }
						title={
							app.label ||
							app.connection_ref ||
							app.server_url ||
							sprintf(
								/* translators: %d: app position. */
								__( 'App %d', 'nvoos-pro-spa' ),
								index + 1
							)
						}
						moveSubject={ sprintf(
							/* translators: %d: app position. */
							__( 'app %d', 'nvoos-pro-spa' ),
							index + 1
						) }
						removeLabel={ sprintf(
							/* translators: %s: app label or target. */
							__( 'Remove MCP app “%s”', 'nvoos-pro-spa' ),
							app.label || target || String( index + 1 )
						) }
						onRemove={ () => removeApp( index ) }
					>
						<TextField
							id={ `nvoos-pro-spa-advanced-mcp-app-${ index }-label` }
							label={ __( 'Label', 'nvoos-pro-spa' ) }
							value={ app.label }
							onChange={ ( value ) =>
								updateApp( index, { label: value } )
							}
						/>
						<p className="nvoos-pro-spa-advanced__hint">
							{ target }
						</p>
						<label className="nvoos-pro-spa-advanced__checkbox-row">
							<input
								type="checkbox"
								checked={ app.enabled !== false }
								onChange={ ( event ) =>
									updateApp( index, {
										enabled: event.target.checked,
									} )
								}
							/>
							<span>{ __( 'Enabled', 'nvoos-pro-spa' ) }</span>
						</label>
					</ItemCard>
				);
			} ) }

			{ availableConnections.length > 0 ? (
				<div className="nvoos-pro-spa-advanced__add-row">
					<FieldShell
						id="nvoos-pro-spa-advanced-mcp-connection"
						label={ __( 'Add from a connection', 'nvoos-pro-spa' ) }
						hint={ __(
							'Only connections that are not already assigned are listed.',
							'nvoos-pro-spa'
						) }
					>
						<select
							id="nvoos-pro-spa-advanced-mcp-connection"
							className="nvoos-pro-spa-advanced__select"
							value={ pendingRef }
							onChange={ ( event ) =>
								setPendingRef( event.target.value )
							}
						>
							<option value="">
								{ __( 'Choose a connection', 'nvoos-pro-spa' ) }
							</option>
							{ availableConnections.map( ( connection ) => (
								<option key={ connection.id } value={ connection.id }>
									{ connection.name }
									{ connection.connection_type
										? ` — ${ connection.connection_type }`
										: '' }
								</option>
							) ) }
						</select>
					</FieldShell>

					<TextField
						id="nvoos-pro-spa-advanced-mcp-label"
						label={ __( 'Label (optional)', 'nvoos-pro-spa' ) }
						value={ pendingLabel }
						onChange={ setPendingLabel }
					/>

					<button
						type="button"
						className="nvoos-pro-spa-advanced__btn"
						onClick={ addApp }
					>
						{ __( 'Add app', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) : (
				<p className="nvoos-pro-spa-advanced__hint">
					{ __(
						'Every available connection is already assigned. Create another connection under Connections to add more apps.',
						'nvoos-pro-spa'
					) }
				</p>
			) }

			<div className="nvoos-pro-spa-advanced__json-actions">
				<button
					type="button"
					className="nvoos-pro-spa-advanced__btn"
					onClick={ () => void save() }
					disabled={ ! dirty || saving }
				>
					{ saving
						? __( 'Saving…', 'nvoos-pro-spa' )
						: __( 'Save MCP apps', 'nvoos-pro-spa' ) }
				</button>
				<button
					type="button"
					className="nvoos-pro-spa-advanced__btn nvoos-pro-spa-advanced__btn--secondary"
					onClick={ () => void load() }
					disabled={ ! dirty || saving }
				>
					{ __( 'Discard changes', 'nvoos-pro-spa' ) }
				</button>
			</div>

			<LiveStatus message={ live } />
		</fieldset>
	);
}

/* -------------------------------------------------------------------------
 * Public editor
 * ---------------------------------------------------------------------- */

export interface AdvancedFieldsEditorProps {
	/** Current advanced value (controlled). */
	value: AssistantAdvancedFields;
	/** Called with the next full value on every structured or JSON edit. */
	onChange: ( next: AssistantAdvancedFields ) => void;
	/** Tool registry for datalists and unknown-slug warnings. */
	tools?: ToolDefinition[];
	/**
	 * Called when an inline error or unapplied JSON draft exists. The form's
	 * Save button should be disabled while `true`.
	 */
	onBlockedChange?: ( blocked: boolean ) => void;
	/** Unique prefix for control ids (default: `nvoos-pro-spa-advanced`). */
	idPrefix?: string;
	/** Installed Agent Skills catalogue. */
	skills?: SkillOption[];
	/**
	 * Skills catalogue route (`mcp-ai/v1/skills`). Empty hides the picker —
	 * a runtime without the route must not claim that no skills are installed.
	 */
	skillsEndpoint?: string;
	skillsLoading?: boolean;
	skillsError?: string | null;
	onRetrySkills?: () => void;
	/**
	 * MCP apps panel wiring. Omit to hide the panel (Pro inactive, or a
	 * runtime without the route).
	 */
	mcpApps?: {
		endpoint: string;
		remoteSitesEndpoint: string;
		nonce: string;
		assistantId: number | null;
	};
}

export function AdvancedFieldsEditor( props: AdvancedFieldsEditorProps ): JSX.Element {
	const {
		value,
		onChange,
		tools = [],
		onBlockedChange,
		idPrefix = 'nvoos-pro-spa-advanced',
		skills = [],
		skillsEndpoint = '',
		skillsLoading = false,
		skillsError = null,
		onRetrySkills,
		mcpApps,
	} = props;

	const [ blockedJson, setBlockedJson ] = useState< string[] >( [] );

	const reportJsonBlocked = useCallback(
		( field: string, blocked: boolean ) => {
			setBlockedJson( ( current ) => {
				if ( blocked ) {
					return current.includes( field )
						? current
						: [ ...current, field ];
				}

				return current.filter( ( item ) => item !== field );
			} );
		},
		[]
	);

	const blockedRef = useRef( onBlockedChange );
	blockedRef.current = onBlockedChange;
	useEffect( () => {
		blockedRef.current?.( blockedJson.length > 0 );
	}, [ blockedJson ] );

	const patch = useCallback(
		( changes: Partial< AssistantAdvancedFields > ) => {
			onChange( { ...value, ...changes } );
		},
		[ onChange, value ]
	);

	return (
		<details className="nvoos-pro-spa-assistants-form__advanced">
			<summary className="nvoos-pro-spa-assistants-form__advanced-summary">
				{ __( 'Advanced', 'nvoos-pro-spa' ) }
			</summary>
			<p className="nvoos-pro-spa-advanced__intro">
				{ __(
					'Mirrors the WordPress metaboxes: fallback chain, base knowledge, shortcuts, and access control. Empty values clear the stored setting.',
					'nvoos-pro-spa'
				) }
			</p>

			<fieldset className="nvoos-pro-spa-advanced__section">
				<legend className="nvoos-pro-spa-advanced__legend">
					{ __( 'Model & knowledge', 'nvoos-pro-spa' ) }
				</legend>

				<div className="nvoos-pro-spa-advanced__row">
					<TextField
						id={ `${ idPrefix }-fallback` }
						label={ __( 'Fallback chain', 'nvoos-pro-spa' ) }
						value={ value.fallback_chain.join( ', ' ) }
						hint={ __(
							'Comma-separated provider slugs, tried in order.',
							'nvoos-pro-spa'
						) }
						onChange={ ( next ) =>
							patch( { fallback_chain: parseCsv( next ) } )
						}
					/>
					<SelectField
						id={ `${ idPrefix }-adaptive-cap` }
						label={ __( 'Adaptive tool cap', 'nvoos-pro-spa' ) }
						value={ value.adaptive_tool_cap }
						options={ [
							{ value: '', label: __( 'Server default', 'nvoos-pro-spa' ) },
							{ value: 'on', label: __( 'On', 'nvoos-pro-spa' ) },
							{ value: 'off', label: __( 'Off', 'nvoos-pro-spa' ) },
						] }
						onChange={ ( next ) =>
							patch( {
								adaptive_tool_cap:
									next as AssistantAdvancedFields[ 'adaptive_tool_cap' ],
							} )
						}
					/>
				</div>

				<div className="nvoos-pro-spa-advanced__row">
					<TextField
						id={ `${ idPrefix }-memory-files` }
						label={ __( 'Memory file IDs', 'nvoos-pro-spa' ) }
						value={ value.memory_files.join( ', ' ) }
						inputMode="numeric"
						hint={ __(
							'Comma-separated attachment IDs (Base Knowledge).',
							'nvoos-pro-spa'
						) }
						onChange={ ( next ) =>
							patch( { memory_files: parseNumericCsv( next ) } )
						}
					/>
					<TextField
						id={ `${ idPrefix }-vector-store` }
						label={ __( 'Vector store ID', 'nvoos-pro-spa' ) }
						value={ value.vector_store_id }
						placeholder="vs_…"
						onChange={ ( next ) => patch( { vector_store_id: next } ) }
					/>
				</div>

				<div className="nvoos-pro-spa-advanced__row">
					<TextField
						id={ `${ idPrefix }-corpus` }
						label={ __( 'Corpus name', 'nvoos-pro-spa' ) }
						value={ value.corpus_name }
						onChange={ ( next ) => patch( { corpus_name: next } ) }
					/>
					<TextField
						id={ `${ idPrefix }-external-id` }
						label={ __( 'Linked action ID', 'nvoos-pro-spa' ) }
						value={ value.external_action_id }
						onChange={ ( next ) => patch( { external_action_id: next } ) }
					/>
				</div>

				<div className="nvoos-pro-spa-advanced__row">
					<SelectField
						id={ `${ idPrefix }-external-type` }
						label={ __( 'Linked action type', 'nvoos-pro-spa' ) }
						value={ value.external_action_type }
						options={ [
							{ value: '', label: __( 'None', 'nvoos-pro-spa' ) },
							{ value: 'workflow', label: __( 'Workflow', 'nvoos-pro-spa' ) },
							{ value: 'assistant', label: __( 'Assistant', 'nvoos-pro-spa' ) },
						] }
						onChange={ ( next ) =>
							patch( {
								external_action_type:
									next as AssistantAdvancedFields[ 'external_action_type' ],
							} )
						}
					/>
					<TextField
						id={ `${ idPrefix }-required-cap` }
						label={ __( 'Required capability', 'nvoos-pro-spa' ) }
						value={ value.required_capability }
						placeholder="manage_options"
						hint={ __(
							'Capability a user needs to chat with this assistant.',
							'nvoos-pro-spa'
						) }
						onChange={ ( next ) =>
							patch( { required_capability: next } )
						}
					/>
				</div>

				<div className="nvoos-pro-spa-advanced__row">
					<label className="nvoos-pro-spa-advanced__checkbox-row">
						<input
							type="checkbox"
							checked={ value.prompt_caching }
							onChange={ ( event ) =>
								patch( { prompt_caching: event.target.checked } )
							}
						/>
						<span>{ __( 'Enable prompt caching', 'nvoos-pro-spa' ) }</span>
					</label>
					<label className="nvoos-pro-spa-advanced__checkbox-row">
						<input
							type="checkbox"
							checked={ value.disable_tool_shortcuts }
							onChange={ ( event ) =>
								patch( {
									disable_tool_shortcuts: event.target.checked,
								} )
							}
						/>
						<span>{ __( 'Disable prompt shortcuts', 'nvoos-pro-spa' ) }</span>
					</label>
				</div>
			</fieldset>

			<ShortcutsEditor
				idPrefix={ idPrefix }
				items={ value.tool_shortcuts }
				onChange={ ( next ) => patch( { tool_shortcuts: next } ) }
				tools={ tools }
				onJsonBlockedChange={ ( blocked ) =>
					reportJsonBlocked( 'tool_shortcuts', blocked )
				}
			/>

			<PrebuiltShortcutsEditor
				idPrefix={ idPrefix }
				value={ value.tool_prebuilt_shortcuts }
				onChange={ ( next ) => patch( { tool_prebuilt_shortcuts: next } ) }
				tools={ tools }
				onJsonBlockedChange={ ( blocked ) =>
					reportJsonBlocked( 'tool_prebuilt_shortcuts', blocked )
				}
			/>

			<RoleRulesEditor
				idPrefix={ idPrefix }
				items={ value.tool_role_rules }
				onChange={ ( next ) => patch( { tool_role_rules: next } ) }
				tools={ tools }
				onJsonBlockedChange={ ( blocked ) =>
					reportJsonBlocked( 'tool_role_rules', blocked )
				}
			/>

			<DatasetsEditor
				idPrefix={ idPrefix }
				items={ value.preferred_datasets }
				onChange={ ( next ) => patch( { preferred_datasets: next } ) }
				onJsonBlockedChange={ ( blocked ) =>
					reportJsonBlocked( 'preferred_datasets', blocked )
				}
			/>

			{ '' !== skillsEndpoint && (
				<SkillsEditor
					value={ value }
					onChange={ patch }
					skills={ skills }
					loading={ Boolean( skillsLoading ) }
					error={ skillsError }
					onRetry={ onRetrySkills }
				/>
			) }

			{ mcpApps && (
				<McpAppsEditor
					endpoint={ mcpApps.endpoint }
					remoteSitesEndpoint={ mcpApps.remoteSitesEndpoint }
					nonce={ mcpApps.nonce }
					assistantId={ mcpApps.assistantId }
				/>
			) }
		</details>
	);
}

export default AdvancedFieldsEditor;
