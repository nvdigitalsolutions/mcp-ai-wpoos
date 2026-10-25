/**
 * ToolPicker — registry-backed, ARIA-compliant searchable multiselect for
 * assistant tool slugs (Proposal 088).
 *
 * Implements the WAI-ARIA Authoring Practices combobox + grouped-listbox
 * patterns: the input carries `role="combobox"` with
 * `aria-autocomplete="list"` and `aria-activedescendant`; the popup is a
 * `role="listbox"` with `aria-multiselectable="true"`, options grouped by
 * category via `role="group"`.
 *
 * Selected slugs render as removable chips above the input. Unknown slugs
 * (not present in the registry) are kept — never silently dropped — and
 * flagged. Options whose `required_capability` the current user lacks are
 * disabled unless already selected (removable, not addable). Empty
 * capability list = unknown = fail open.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.1.1
 */

import {
	useState,
	useMemo,
	useRef,
	useEffect,
	useCallback,
	useId,
} from 'react';
import type { JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import type { ToolDefinition } from '../../api/tools';

export interface ToolPickerProps {
	/** Input id (for the label's `htmlFor`). */
	id?: string;
	/** Currently selected tool slugs (controlled). */
	selected: string[];
	/** Change handler — receives the next full selection array. */
	onChange: ( next: string[] ) => void;
	/** Full tool registry (as served by GET mcp-ai/v1/tools). */
	tools: ToolDefinition[];
	loading?: boolean;
	error?: string | null;
	onRetry?: () => void;
	/** Current user capabilities. Empty = unknown = fail open (no gating). */
	capabilities?: string[];
	disabled?: boolean;
}

interface VisibleOption {
	slug: string;
	tool: ToolDefinition;
	disabled: boolean;
}

/**
 * Split a raw slug string (comma/newline separated — the legacy paste
 * format) into trimmed, non-empty slugs. Order and duplicates preserved;
 * deduplication happens at add time.
 */
export function parseToolSlugs( raw: string ): string[] {
	return raw
		.split( /[,\n]+/ )
		.map( ( s ) => s.trim() )
		.filter( ( s ) => s.length > 0 );
}

export type ToolSeverity = 'danger' | 'warning' | 'caution' | 'safe' | 'neutral';

/**
 * Map a tool's capability flags to a severity tier for colour coding.
 *
 * Mirrors the legacy assistant editor's `getChipColours()` precedence in
 * `includes/helpers/class-wp-mcp-ai-tool-presets-helper.php`: danger
 * (data-destruction / irreversible / financial-impact /
 * access-control-change) → warning (state-changing / write) → caution
 * (external-communication) → safe (read-only) → neutral (no flags).
 */
export function toolSeverity( flags: string[] | undefined ): ToolSeverity {
	if ( ! flags || flags.length === 0 ) {
		return 'neutral';
	}
	if (
		flags.includes( 'data-destruction' ) ||
		flags.includes( 'irreversible' ) ||
		flags.includes( 'financial-impact' ) ||
		flags.includes( 'access-control-change' )
	) {
		return 'danger';
	}
	if ( flags.includes( 'state-changing' ) || flags.includes( 'write' ) ) {
		return 'warning';
	}
	if ( flags.includes( 'external-communication' ) ) {
		return 'caution';
	}
	if ( flags.includes( 'read-only' ) ) {
		return 'safe';
	}
	return 'neutral';
}

export function ToolPicker( {
	id,
	selected,
	onChange,
	tools,
	loading = false,
	error = null,
	onRetry,
	capabilities,
	disabled = false,
}: ToolPickerProps ): JSX.Element {
	const generatedId = useId();
	const inputId = id ?? `nvoos-pro-spa-tool-picker-${ generatedId }`;
	const listboxId = `${ inputId }-listbox`;

	const [ open, setOpen ] = useState< boolean >( false );
	const [ query, setQuery ] = useState< string >( '' );
	const [ activeIndex, setActiveIndex ] = useState< number >( -1 );

	const rootRef = useRef< HTMLDivElement >( null );

	const knownSlugs = useMemo( () => {
		const map = new Map< string, ToolDefinition >();
		for ( const tool of tools ) {
			map.set( tool.slug, tool );
		}
		return map;
	}, [ tools ] );

	const capabilitySet = useMemo(
		() => new Set( capabilities ?? [] ),
		[ capabilities ],
	);

	/**
	 * Whether the current user may grant this tool. Empty capability list
	 * means the server did not report capabilities — fail open.
	 */
	const canGrant = useCallback(
		( tool: ToolDefinition ): boolean => {
			if ( ! tool.required_capability || capabilitySet.size === 0 ) {
				return true;
			}
			return capabilitySet.has( tool.required_capability );
		},
		[ capabilitySet ],
	);

	/** Registry entries grouped by category, filtered by the query. */
	const visibleGroups = useMemo( () => {
		const q = query.toLowerCase().trim();
		const groups = new Map< string, VisibleOption[] >();
		for ( const tool of tools ) {
			if (
				q &&
				! tool.name.toLowerCase().includes( q ) &&
				! tool.description.toLowerCase().includes( q ) &&
				! tool.slug.toLowerCase().includes( q )
			) {
				continue;
			}
			const category = tool.category || 'Other';
			const options = groups.get( category ) ?? [];
			// Already-selected tools stay enabled so they can be removed even
			// when the user lacks the required capability.
			options.push( {
				slug: tool.slug,
				tool,
				disabled: ! canGrant( tool ) && ! selected.includes( tool.slug ),
			} );
			groups.set( category, options );
		}
		return Array.from( groups.entries() )
			.sort( ( [ a ], [ b ] ) => a.localeCompare( b ) )
			.map( ( [ category, options ] ) => ( {
				category,
				options: [ ...options ].sort( ( a, b ) =>
					a.tool.name.localeCompare( b.tool.name ),
				),
			} ) );
	}, [ tools, query, canGrant, selected ] );

	/** Flattened option list in render order (group boundaries preserved). */
	const flatOptions = useMemo( () => {
		const list: ( VisibleOption & { index: number } )[] = [];
		for ( const group of visibleGroups ) {
			for ( const option of group.options ) {
				list.push( { ...option, index: list.length } );
			}
		}
		return list;
	}, [ visibleGroups ] );

	const unknownSlugs = useMemo(
		() =>
			! loading && tools.length > 0
				? selected.filter( ( slug ) => ! knownSlugs.has( slug ) )
				: [],
		[ selected, knownSlugs, loading, tools.length ],
	);

	const toggle = useCallback(
		( slug: string, optionDisabled = false ) => {
			if ( optionDisabled ) {
				return;
			}
			const next = selected.includes( slug )
				? selected.filter( ( s ) => s !== slug )
				: [ ...selected, slug ];
			onChange( next );
		},
		[ selected, onChange ],
	);

	/** Commit the raw query as slug(s) — the "creatable" entry path. */
	const commitRaw = useCallback( () => {
		const slugs = parseToolSlugs( query );
		if ( slugs.length === 0 ) {
			return;
		}
		const missing = slugs.filter( ( slug ) => ! selected.includes( slug ) );
		if ( missing.length > 0 ) {
			onChange( [ ...selected, ...missing ] );
		}
		setQuery( '' );
		setActiveIndex( -1 );
	}, [ query, selected, onChange ] );

	const addAllMatching = useCallback( () => {
		const missing = flatOptions
			.filter( ( opt ) => ! opt.disabled && ! selected.includes( opt.slug ) )
			.map( ( opt ) => opt.slug );
		if ( missing.length > 0 ) {
			onChange( [ ...selected, ...missing ] );
		}
	}, [ flatOptions, selected, onChange ] );

	const clearAll = useCallback( () => {
		if ( selected.length > 0 ) {
			onChange( [] );
		}
	}, [ selected.length, onChange ] );

	const handleKeyDown = ( event: React.KeyboardEvent< HTMLInputElement > ) => {
		switch ( event.key ) {
			case 'ArrowDown':
				event.preventDefault();
				setOpen( true );
				setActiveIndex( ( i ) =>
					flatOptions.length === 0
						? -1
						: Math.min( i + 1, flatOptions.length - 1 ),
				);
				break;
			case 'ArrowUp':
				event.preventDefault();
				setOpen( true );
				setActiveIndex( ( i ) =>
					flatOptions.length === 0
						? -1
						: i <= 0
						? flatOptions.length - 1
						: i - 1,
				);
				break;
			case 'Enter':
				// APG select-only combobox: Enter selects the active option;
				// when options exist but none is active, activate the first.
				if ( open && flatOptions.length > 0 ) {
					event.preventDefault();
					if ( activeIndex >= 0 && flatOptions[ activeIndex ] ) {
						const opt = flatOptions[ activeIndex ];
						toggle( opt.slug, opt.disabled );
					} else {
						setActiveIndex( 0 );
					}
				} else if ( query.trim() ) {
					// No matches — commit the typed slug(s) verbatim.
					event.preventDefault();
					commitRaw();
				}
				break;
			case 'Escape':
				event.preventDefault();
				setOpen( false );
				break;
			case 'Tab':
				setOpen( false );
				break;
			case 'Backspace':
				// Token-input convention: empty query + Backspace removes the
				// most recently added chip.
				if ( ! query && selected.length > 0 ) {
					event.preventDefault();
					onChange( selected.slice( 0, -1 ) );
				}
				break;
		}
	};

	// Close on click outside.
	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const handler = ( event: MouseEvent ) => {
			if (
				rootRef.current &&
				event.target instanceof Node &&
				! rootRef.current.contains( event.target )
			) {
				setOpen( false );
			}
		};
		document.addEventListener( 'mousedown', handler );
		return () => document.removeEventListener( 'mousedown', handler );
	}, [ open ] );

	// Keep the active index inside the filtered list.
	useEffect( () => {
		if ( flatOptions.length === 0 ) {
			setActiveIndex( -1 );
		} else if ( activeIndex >= flatOptions.length ) {
			setActiveIndex( flatOptions.length - 1 );
		}
	}, [ flatOptions.length, activeIndex ] );

	// Scroll the active option into view. jsdom does not implement
	// scrollIntoView, so the call is optional-chained (test env no-op).
	useEffect( () => {
		if ( ! open || activeIndex < 0 ) {
			return;
		}
		const el = document.getElementById(
			`${ listboxId }-option-${ activeIndex }`,
		);
		el?.scrollIntoView?.( { block: 'nearest' } );
	}, [ open, activeIndex, listboxId ] );

	const exactMatch =
		query.trim().length > 0 &&
		! flatOptions.some( ( opt ) => opt.slug === query.trim() );

	let groupCursor = 0;

	return (
		<div className="nvoos-pro-spa-tool-picker" ref={ rootRef }>
			{ selected.length > 0 && (
				<ul
					className="nvoos-pro-spa-tool-picker__chips"
					aria-label={ __( 'Selected tools', 'nvoos-pro-spa' ) }
				>
					{ selected.map( ( slug ) => {
						const tool = knownSlugs.get( slug );
						const label = tool?.name ?? slug;
						const isUnknown = ! tool && ! loading;
						const severity = toolSeverity( tool?.flags );
						return (
							<li
								key={ slug }
								className={ [
									'nvoos-pro-spa-tool-picker__chip',
									! isUnknown && tool
										? `nvoos-pro-spa-tool-picker__chip--${ severity }`
										: '',
									isUnknown
										? 'nvoos-pro-spa-tool-picker__chip--unknown'
										: '',
								]
									.filter( Boolean )
									.join( ' ' ) }
							>
								<button
									type="button"
									className="nvoos-pro-spa-tool-picker__chip-remove"
									onClick={ () => toggle( slug ) }
									aria-label={ sprintf(
										/* translators: %s: tool name or slug */
										__( 'Remove %s', 'nvoos-pro-spa' ),
										label,
									) }
								>
									<span className="nvoos-pro-spa-tool-picker__chip-label">
										{ label }
									</span>
									<span
										className="nvoos-pro-spa-tool-picker__chip-x"
										aria-hidden="true"
									>
										×
									</span>
								</button>
							</li>
						);
					} ) }
				</ul>
			) }

			<div className="nvoos-pro-spa-tool-picker__field">
				<input
					id={ inputId }
					type="text"
					className="nvoos-pro-spa-tool-picker__input"
					role="combobox"
					aria-expanded={ open }
					aria-controls={ listboxId }
					aria-haspopup="listbox"
					aria-autocomplete="list"
					aria-activedescendant={
						open && activeIndex >= 0
							? `${ listboxId }-option-${ activeIndex }`
							: undefined
					}
					value={ query }
					onChange={ ( event ) => {
						setQuery( event.target.value );
						setActiveIndex( 0 );
						setOpen( true );
					} }
					onFocus={ () => setOpen( true ) }
					onKeyDown={ handleKeyDown }
					placeholder={ __(
						'Search tools by name, description, or slug — type a slug and press Enter to add it',
						'nvoos-pro-spa',
					) }
					disabled={ disabled }
				/>
				<button
					type="button"
					className="nvoos-pro-spa-tool-picker__toggle"
					aria-label={
						open
							? __( 'Close tool list', 'nvoos-pro-spa' )
							: __( 'Open tool list', 'nvoos-pro-spa' )
					}
					aria-expanded={ open }
					onClick={ () => setOpen( ( o ) => ! o ) }
					disabled={ disabled }
				>
					<span aria-hidden="true">▾</span>
				</button>
			</div>

			<div className="nvoos-pro-spa-tool-picker__summary" aria-live="polite">
				{ sprintf(
					/* translators: %d: number of selected tools */
					__( '%d selected', 'nvoos-pro-spa' ),
					selected.length,
				) }
				{ unknownSlugs.length > 0 && (
					<span className="nvoos-pro-spa-tool-picker__warning">
						{ ' ' }
						{ sprintf(
							/* translators: %d: number of selected slugs not found in the registry */
							__( '(%d not found in the tool registry)', 'nvoos-pro-spa' ),
							unknownSlugs.length,
						) }
					</span>
				) }
			</div>

			{ open && (
				<div className="nvoos-pro-spa-tool-picker__popup">
					{ loading ? (
						<div
							className="nvoos-pro-spa-tool-picker__state"
							role="status"
						>
							{ __( 'Loading tools…', 'nvoos-pro-spa' ) }
						</div>
					) : error ? (
						<div
							className="nvoos-pro-spa-tool-picker__state nvoos-pro-spa-tool-picker__state--error"
							role="alert"
						>
							{ error }
							{ onRetry && (
								<button
									type="button"
									className="nvoos-pro-spa-tool-picker__retry"
									onClick={ onRetry }
								>
									{ __( 'Retry', 'nvoos-pro-spa' ) }
								</button>
							) }
						</div>
					) : flatOptions.length === 0 ? (
						<div className="nvoos-pro-spa-tool-picker__state">
							{ __( 'No matching tools.', 'nvoos-pro-spa' ) }
						</div>
					) : (
						<ul
							id={ listboxId }
							role="listbox"
							aria-multiselectable="true"
							aria-label={ __(
								'Available tools',
								'nvoos-pro-spa',
							) }
							className="nvoos-pro-spa-tool-picker__listbox"
						>
							{ visibleGroups.map( ( group ) => {
									const groupOptions = flatOptions.slice(
										groupCursor,
										groupCursor + group.options.length,
									);
									groupCursor += group.options.length;
									return (
										<li
											key={ group.category }
											role="group"
											aria-label={ group.category }
											className="nvoos-pro-spa-tool-picker__group"
										>
											<div
												role="presentation"
												className="nvoos-pro-spa-tool-picker__group-label"
											>
												{ group.category }
												<span className="nvoos-pro-spa-tool-picker__group-count">
													{ group.options.length }
												</span>
											</div>
											<ul
												role="none"
												className="nvoos-pro-spa-tool-picker__options"
											>
												{ groupOptions.map( ( opt ) => (
													// Keyboard access is provided by the combobox input
													// (ArrowDown/Up + Enter) per the ARIA APG combobox
													// pattern; the option role makes this row interactive.
													// eslint-disable-next-line jsx-a11y/click-events-have-key-events
													<li
														key={ opt.slug }
														id={ `${ listboxId }-option-${ opt.index }` }
														role="option"
														aria-selected={ selected.includes(
															opt.slug,
														) }
														aria-disabled={
															opt.disabled || undefined
														}
														className={ [
															'nvoos-pro-spa-tool-picker__option',
															`nvoos-pro-spa-tool-picker__option--${ toolSeverity( opt.tool.flags ) }`,
															opt.index === activeIndex
																? 'nvoos-pro-spa-tool-picker__option--active'
																: '',
															selected.includes( opt.slug )
																? 'nvoos-pro-spa-tool-picker__option--selected'
																: '',
															opt.disabled
																? 'nvoos-pro-spa-tool-picker__option--disabled'
																: '',
														]
															.filter( Boolean )
															.join( ' ' ) }
														// Keyboard access is provided by the combobox input
													// (ArrowDown/Up + Enter) per the ARIA APG combobox
													// pattern; the option role makes this row interactive.
													// eslint-disable-next-line jsx-a11y/click-events-have-key-events
													onClick={ () =>
														toggle( opt.slug, opt.disabled )
													}
													>
														<span
															className="nvoos-pro-spa-tool-picker__option-check"
															aria-hidden="true"
														>
															{ selected.includes( opt.slug )
																? '✓'
																: '' }
														</span>
														<span className="nvoos-pro-spa-tool-picker__option-body">
															<span className="nvoos-pro-spa-tool-picker__option-name">
																{ opt.tool.name }
															</span>
															{ opt.tool.description && (
																<span className="nvoos-pro-spa-tool-picker__option-desc">
																	{ opt.tool.description }
																</span>
															) }
														</span>
														<span className="nvoos-pro-spa-tool-picker__option-slug">
															{ opt.tool.slug }
														</span>
														{ opt.tool.required_capability && (
															<span className="nvoos-pro-spa-tool-picker__option-cap">
																{ opt.tool.required_capability }
															</span>
														) }
													</li>
												) ) }
											</ul>
										</li>
									);
								} ) }
						</ul>
					) }
					<div className="nvoos-pro-spa-tool-picker__footer">
						{ exactMatch && (
							<button
								type="button"
								className="nvoos-pro-spa-tool-picker__footer-btn"
								onClick={ commitRaw }
							>
								{ sprintf(
									/* translators: %s: typed slug */
									__( 'Add “%s”', 'nvoos-pro-spa' ),
									query.trim(),
								) }
							</button>
						) }
						{ flatOptions.length > 0 && (
							<button
								type="button"
								className="nvoos-pro-spa-tool-picker__footer-btn"
								onClick={ addAllMatching }
							>
								{ __( 'Add all matching', 'nvoos-pro-spa' ) }
							</button>
						) }
						<button
							type="button"
							className="nvoos-pro-spa-tool-picker__footer-btn"
							onClick={ clearAll }
							disabled={ selected.length === 0 }
						>
							{ __( 'Clear all', 'nvoos-pro-spa' ) }
						</button>
					</div>
				</div>
			) }
		</div>
	);
}
