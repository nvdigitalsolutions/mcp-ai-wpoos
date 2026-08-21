/**
 * Pro SPA v2 — Skills & OKF Knowledge Drawer.
 *
 * Slide-in panel with two tabs:
 *   - Knowledge — browse OKF bundles → concepts → concept detail (with
 *     cross-links), plus cross-bundle search.
 *   - Skills — the current assistant's granted OKF concepts, resolved
 *     skill-shaped through the OKF → Skill bridge (same gates as load_skill).
 *
 * Every concept surfaces its provenance: trust tier, lifecycle status, and
 * staleness, so users can judge reliability before loading it into a chat.
 *
 * Accessibility:
 *   - role="dialog" aria-modal="false" (non-blocking — chat stays live)
 *   - ESC closes, toggle button re-gets focus
 *   - ARIA-live region for status messages
 *
 * @since 2.1.1
 */

import { __, sprintf } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
	type JSX,
} from 'react';
import {
	OkfClient,
	type OkfBundle,
	type OkfConceptDetail,
	type OkfConceptSummary,
	type OkfSearchResult,
	type OkfSkill,
} from '../../api/okf';
import { MarkdownContent } from './MarkdownContent';

export type OkfTab = 'knowledge' | 'skills';

export interface OkfDrawerProps {
	endpoint: string;
	nonce: string;
	assistantId: number | string;
	/** Controlled by the parent — true = drawer is visible. */
	isOpen: boolean;
	onClose: () => void;
	/** Insert a prompt into the composer (autoSubmit = send immediately). */
	onInsertPrompt: ( prompt: string, autoSubmit: boolean ) => void;
	/** The toggle button ref — focus returns here on close. */
	toggleRef?: React.RefObject< HTMLButtonElement | null >;
}

const SEARCH_DEBOUNCE_MS = 300;

// ── Trust / status badge helpers ──────────────────────────────────────────

function trustLabel( tier: string ): string {
	switch ( tier ) {
		case 'human-reviewed':
			return __( 'Human-reviewed', 'nvoos-pro-spa' );
		case 'machine-confirmed':
			return __( 'Machine-confirmed', 'nvoos-pro-spa' );
		default:
			return __( 'Unverified', 'nvoos-pro-spa' );
	}
}

function trustSymbol( tier: string ): string {
	switch ( tier ) {
		case 'human-reviewed':
			return '✓';
		case 'machine-confirmed':
			return '⚙';
		default:
			return '?';
	}
}

function statusLabel( status: string ): string {
	switch ( status ) {
		case 'draft':
			return __( 'Draft', 'nvoos-pro-spa' );
		case 'deprecated':
			return __( 'Deprecated', 'nvoos-pro-spa' );
		default:
			return __( 'Stable', 'nvoos-pro-spa' );
	}
}

// ── Component ──────────────────────────────────────────────────────────────

export function OkfDrawer( {
	endpoint,
	nonce,
	assistantId,
	isOpen,
	onClose,
	onInsertPrompt,
	toggleRef,
}: OkfDrawerProps ): JSX.Element | null {
	const client = useMemo(
		() => new OkfClient( { endpoint, nonce } ),
		[ endpoint, nonce ]
	);

	const [ activeTab, setActiveTab ] = useState< OkfTab >( 'knowledge' );

	// ── Knowledge tab: bundles ────────────────────────────────────────────
	const [ bundles, setBundles ] = useState< OkfBundle[] | null >( null );
	const [ bundlesError, setBundlesError ] = useState< string | null >( null );
	const [ bundlesLoading, setBundlesLoading ] = useState( false );

	// ── Knowledge tab: selected bundle concepts ───────────────────────────
	const [ selectedBundle, setSelectedBundle ] = useState< string | null >( null );
	const [ concepts, setConcepts ] = useState< OkfConceptSummary[] | null >( null );
	const [ conceptsError, setConceptsError ] = useState< string | null >( null );
	const [ conceptsLoading, setConceptsLoading ] = useState( false );

	// ── Knowledge tab: concept detail ─────────────────────────────────────
	const [ concept, setConcept ] = useState< OkfConceptDetail | null >( null );
	const [ conceptError, setConceptError ] = useState< string | null >( null );
	const [ conceptLoading, setConceptLoading ] = useState( false );

	// ── Search ────────────────────────────────────────────────────────────
	const [ search, setSearch ] = useState( '' );
	const [ searchResults, setSearchResults ] = useState< OkfSearchResult[] | null >( null );
	const [ searchError, setSearchError ] = useState< string | null >( null );
	const [ searchLoading, setSearchLoading ] = useState( false );

	// ── Skills tab ────────────────────────────────────────────────────────
	const [ skills, setSkills ] = useState< OkfSkill[] | null >( null );
	const [ skillsError, setSkillsError ] = useState< string | null >( null );
	const [ skillsLoading, setSkillsLoading ] = useState( false );

	const drawerRef = useRef< HTMLDivElement | null >( null );
	const abortRef = useRef< AbortController | null >( null );
	const searchDebounceRef = useRef< ReturnType< typeof setTimeout > | null >( null );

	const close = useCallback( () => {
		onClose();
		toggleRef?.current?.focus();
	}, [ onClose, toggleRef ] );

	// Close on ESC.
	useEffect( () => {
		if ( ! isOpen ) return;
		const handler = ( e: globalThis.KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				close();
			}
		};
		document.addEventListener( 'keydown', handler );
		return () => document.removeEventListener( 'keydown', handler );
	}, [ isOpen, close ] );

	// Move focus into drawer on open.
	useEffect( () => {
		if ( isOpen && drawerRef.current ) {
			const first = drawerRef.current.querySelector< HTMLElement >(
				'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
			);
			first?.focus();
		}
	}, [ isOpen ] );

	// ── Bundles (lazy, once) ───────────────────────────────────────────────
	useEffect( () => {
		if ( ! isOpen || bundles !== null ) return;
		const controller = new AbortController();
		setBundlesLoading( true );
		setBundlesError( null );
		client
			.listBundles( controller.signal )
			.then( ( list ) => {
				if ( ! controller.signal.aborted ) setBundles( list );
			} )
			.catch( ( err: unknown ) => {
				if ( ! controller.signal.aborted ) {
					setBundlesError( err instanceof Error ? err.message : String( err ) );
				}
			} )
			.finally( () => {
				if ( ! controller.signal.aborted ) setBundlesLoading( false );
			} );
		return () => controller.abort();
	}, [ isOpen, bundles, client ] );

	// ── Concepts for the selected bundle ───────────────────────────────────
	useEffect( () => {
		if ( ! isOpen || ! selectedBundle ) return;
		abortRef.current?.abort();
		const controller = new AbortController();
		abortRef.current = controller;

		setConceptsLoading( true );
		setConceptsError( null );
		setConcepts( null );

		client
			.listConcepts( selectedBundle, {}, controller.signal )
			.then( ( data ) => {
				if ( ! controller.signal.aborted ) setConcepts( data.concepts );
			} )
			.catch( ( err: unknown ) => {
				if ( ! controller.signal.aborted ) {
					setConceptsError( err instanceof Error ? err.message : String( err ) );
				}
			} )
			.finally( () => {
				if ( ! controller.signal.aborted ) setConceptsLoading( false );
			} );

		return () => controller.abort();
	}, [ isOpen, selectedBundle, client ] );

	// ── Debounced search ────────────────────────────────────────────────────
	useEffect( () => {
		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
		}
		if ( ! isOpen || search.trim() === '' ) {
			setSearchResults( null );
			setSearchError( null );
			setSearchLoading( false );
			return;
		}

		searchDebounceRef.current = setTimeout( () => {
			abortRef.current?.abort();
			const controller = new AbortController();
			abortRef.current = controller;

			setSearchLoading( true );
			setSearchError( null );

			const request = selectedBundle
				? client
					.listConcepts( selectedBundle, { q: search.trim() }, controller.signal )
					.then( ( data ) => ( {
						results: data.concepts.map( ( c ) => ( {
							...c,
							bundle: selectedBundle,
						} ) ),
					} ) )
				: client.search( search.trim(), controller.signal );

			request
				.then( ( data ) => {
					if ( ! controller.signal.aborted ) setSearchResults( data.results );
				} )
				.catch( ( err: unknown ) => {
					if ( ! controller.signal.aborted ) {
						setSearchError( err instanceof Error ? err.message : String( err ) );
					}
				} )
				.finally( () => {
					if ( ! controller.signal.aborted ) setSearchLoading( false );
				} );
		}, SEARCH_DEBOUNCE_MS );

		return () => {
			if ( searchDebounceRef.current ) {
				clearTimeout( searchDebounceRef.current );
			}
		};
	}, [ isOpen, search, selectedBundle, client ] );

	// ── Skills (lazy, once per assistant) ───────────────────────────────────
	useEffect( () => {
		if ( ! isOpen || activeTab !== 'skills' || skills !== null ) {
			return;
		}
		const controller = new AbortController();
		setSkillsLoading( true );
		setSkillsError( null );
		client
			.listSkills( assistantId, controller.signal )
			.then( ( list ) => {
				if ( ! controller.signal.aborted ) setSkills( list );
			} )
			.catch( ( err: unknown ) => {
				if ( ! controller.signal.aborted ) {
					setSkillsError( err instanceof Error ? err.message : String( err ) );
				}
			} )
			.finally( () => {
				if ( ! controller.signal.aborted ) setSkillsLoading( false );
			} );
		return () => controller.abort();
	}, [ isOpen, activeTab, skills, assistantId, client ] );

	// ── Concept detail ──────────────────────────────────────────────────────
	const openConcept = useCallback(
		async ( bundle: string, conceptId: string ) => {
			abortRef.current?.abort();
			const controller = new AbortController();
			abortRef.current = controller;

			setConceptLoading( true );
			setConceptError( null );
			setConcept( null );

			try {
				const detail = await client.getConcept( bundle, conceptId, controller.signal );
				if ( ! controller.signal.aborted ) setConcept( detail );
			} catch ( err: unknown ) {
				if ( ! controller.signal.aborted ) {
					setConceptError( err instanceof Error ? err.message : String( err ) );
				}
			} finally {
				if ( ! controller.signal.aborted ) setConceptLoading( false );
			}
		},
		[ client ]
	);

	const closeConcept = useCallback( () => {
		setConcept( null );
		setConceptError( null );
	}, [] );

	const openBundle = useCallback( ( bundle: string ) => {
		setSelectedBundle( bundle );
		setConcepts( null );
		setConcept( null );
		setConceptError( null );
		setSearch( '' );
		setSearchResults( null );
	}, [] );

	const closeBundle = useCallback( () => {
		setSelectedBundle( null );
		setConcepts( null );
		setConceptsError( null );
		setConcept( null );
		setConceptError( null );
		setSearch( '' );
		setSearchResults( null );
	}, [] );

	const askAbout = useCallback(
		( bundle: string, conceptId: string, e?: React.MouseEvent ) => {
			const reference = `${ bundle }:${ conceptId }`;
			const prompt = sprintf(
				/* translators: %s: bundle:concept reference */
				__(
					'Load the OKF knowledge concept "%s" and use it.',
					'nvoos-pro-spa'
				),
				reference
			);
			onInsertPrompt( prompt, !! e?.shiftKey );
		},
		[ onInsertPrompt ]
	);

	const loadSkill = useCallback(
		( skill: OkfSkill, e: React.MouseEvent ) => {
			const prompt = sprintf(
				/* translators: %s: skill name (bundle:concept) */
				__(
					'Load the skill "%s" and follow its instructions.',
					'nvoos-pro-spa'
				),
				skill.name
			);
			onInsertPrompt( prompt, ! e.shiftKey );
		},
		[ onInsertPrompt ]
	);

	const handleTabChange = useCallback( ( tab: OkfTab ) => {
		setActiveTab( tab );
	}, [] );

	if ( ! isOpen ) return null;

	return (
		<div
			ref={ drawerRef }
			className="nvoos-pro-spa-okf-drawer"
			role="dialog"
			aria-modal="false"
			aria-label={ __( 'Skills & Knowledge', 'nvoos-pro-spa' ) }
		>
			{/* Header */}
			<div className="nvoos-pro-spa-okf-drawer-header">
				<h2 className="nvoos-pro-spa-okf-drawer-title">
					{ __( '🧠 Skills & Knowledge', 'nvoos-pro-spa' ) }
				</h2>
				<button
					type="button"
					className="nvoos-pro-spa-okf-drawer-close"
					aria-label={ __( 'Close skills & knowledge drawer', 'nvoos-pro-spa' ) }
					onClick={ close }
				>
					×
				</button>
			</div>

			{/* Tabs */}
			<div className="nvoos-pro-spa-okf-drawer-tabs" role="tablist">
				{ ( [ 'knowledge', 'skills' ] as OkfTab[] ).map( ( tab ) => (
					<button
						key={ tab }
						type="button"
						role="tab"
						aria-selected={ activeTab === tab }
						className={ `nvoos-pro-spa-okf-drawer-tab${
							activeTab === tab ? ' nvoos-pro-spa-okf-drawer-tab--active' : ''
						}` }
						onClick={ () => handleTabChange( tab ) }
					>
						{ tab === 'knowledge'
							? __( 'Knowledge', 'nvoos-pro-spa' )
							: __( 'Skills', 'nvoos-pro-spa' ) }
					</button>
				) ) }
			</div>

			{/* Status messages (ARIA live) */}
			<div
				className="nvoos-pro-spa-screen-reader-only"
				role="status"
				aria-live="polite"
			>
				{ ( bundlesLoading || conceptsLoading || conceptLoading || searchLoading || skillsLoading ) &&
					__( 'Loading…', 'nvoos-pro-spa' ) }
			</div>

			{/* Body */}
			<div className="nvoos-pro-spa-okf-drawer-body" role="tabpanel">
				{ activeTab === 'knowledge' && (
					<KnowledgeTab
						bundles={ bundles }
						bundlesLoading={ bundlesLoading }
						bundlesError={ bundlesError }
						selectedBundle={ selectedBundle }
						concepts={ concepts }
						conceptsLoading={ conceptsLoading }
						conceptsError={ conceptsError }
						concept={ concept }
						conceptLoading={ conceptLoading }
						conceptError={ conceptError }
						search={ search }
						onSearchChange={ setSearch }
						searchResults={ searchResults }
						searchLoading={ searchLoading }
						searchError={ searchError }
						onOpenBundle={ openBundle }
						onCloseBundle={ closeBundle }
						onOpenConcept={ openConcept }
						onCloseConcept={ closeConcept }
						onAskAbout={ askAbout }
					/>
				) }

				{ activeTab === 'skills' && (
					<SkillsTab
						skills={ skills }
						isLoading={ skillsLoading }
						error={ skillsError }
						assistantId={ assistantId }
						onLoadSkill={ loadSkill }
					/>
				) }
			</div>
		</div>
	);
}

// ── Knowledge tab ───────────────────────────────────────────────────────────

interface KnowledgeTabProps {
	bundles: OkfBundle[] | null;
	bundlesLoading: boolean;
	bundlesError: string | null;
	selectedBundle: string | null;
	concepts: OkfConceptSummary[] | null;
	conceptsLoading: boolean;
	conceptsError: string | null;
	concept: OkfConceptDetail | null;
	conceptLoading: boolean;
	conceptError: string | null;
	search: string;
	onSearchChange: ( q: string ) => void;
	searchResults: OkfSearchResult[] | null;
	searchLoading: boolean;
	searchError: string | null;
	onOpenBundle: ( bundle: string ) => void;
	onCloseBundle: () => void;
	onOpenConcept: ( bundle: string, conceptId: string ) => void;
	onCloseConcept: () => void;
	onAskAbout: ( bundle: string, conceptId: string, e?: React.MouseEvent ) => void;
}

function KnowledgeTab( props: KnowledgeTabProps ): JSX.Element {
	const {
		bundles,
		bundlesLoading,
		bundlesError,
		selectedBundle,
		concepts,
		conceptsLoading,
		conceptsError,
		concept,
		conceptLoading,
		conceptError,
		search,
		onSearchChange,
		searchResults,
		searchLoading,
		searchError,
		onOpenBundle,
		onCloseBundle,
		onOpenConcept,
		onCloseConcept,
		onAskAbout,
	} = props;

	return (
		<>
			{/* Search */}
			<div className="nvoos-pro-spa-okf-drawer-search">
				<input
					type="search"
					className="nvoos-pro-spa-okf-drawer-search-input"
					placeholder={
						selectedBundle
							? __( 'Search this bundle…', 'nvoos-pro-spa' )
							: __( 'Search all knowledge…', 'nvoos-pro-spa' )
					}
					value={ search }
					onChange={ ( e ) => onSearchChange( e.target.value ) }
					aria-label={ __( 'Search OKF knowledge', 'nvoos-pro-spa' ) }
				/>
			</div>

			<div className="nvoos-pro-spa-okf-drawer-content">
				{/* Concept detail */}
				{ concept && (
					<ConceptDetail
						concept={ concept }
						onBack={ onCloseConcept }
						onAskAbout={ onAskAbout }
						onOpenConcept={ onOpenConcept }
					/>
				) }

				{ ! concept && conceptError && (
					<ErrorState message={ conceptError } onRetry={ onCloseConcept } />
				) }

				{ ! concept && ! conceptError && conceptLoading && (
					<div className="nvoos-pro-spa-okf-drawer-loading">
						{ __( 'Loading…', 'nvoos-pro-spa' ) }
					</div>
				) }

				{ ! concept && ! conceptError && ! conceptLoading && search.trim() !== '' && (
					<SearchResults
						results={ searchResults }
						isLoading={ searchLoading }
						error={ searchError }
						query={ search }
						onOpenConcept={ onOpenConcept }
					/>
				) }

				{ ! concept && ! conceptError && ! conceptLoading && search.trim() === '' && selectedBundle && (
					<ConceptList
						concepts={ concepts }
						isLoading={ conceptsLoading }
						error={ conceptsError }
						bundle={ selectedBundle }
						onBack={ onCloseBundle }
						onOpenConcept={ onOpenConcept }
					/>
				) }

				{ ! concept && ! conceptError && ! conceptLoading && search.trim() === '' && ! selectedBundle && (
					<BundleList
						bundles={ bundles }
						isLoading={ bundlesLoading }
						error={ bundlesError }
						onOpenBundle={ onOpenBundle }
					/>
				) }
			</div>
		</>
	);
}

// ── Bundle list ──────────────────────────────────────────────────────────────

interface BundleListProps {
	bundles: OkfBundle[] | null;
	isLoading: boolean;
	error: string | null;
	onOpenBundle: ( bundle: string ) => void;
}

function BundleList( { bundles, isLoading, error, onOpenBundle }: BundleListProps ): JSX.Element {
	if ( isLoading ) {
		return <div className="nvoos-pro-spa-okf-drawer-loading">{ __( 'Loading…', 'nvoos-pro-spa' ) }</div>;
	}

	if ( error ) {
		return <ErrorState message={ error } />;
	}

	if ( bundles && bundles.length === 0 ) {
		return (
			<div className="nvoos-pro-spa-okf-drawer-empty">
				{ __(
					'No OKF knowledge bundles exist yet. Create one from the OKF Bundle Manager.',
					'nvoos-pro-spa'
				) }
			</div>
		);
	}

	return (
		<ul className="nvoos-pro-spa-okf-drawer-list">
			{ bundles?.map( ( bundle ) => (
				<li key={ bundle.name }>
					<button
						type="button"
						className="nvoos-pro-spa-okf-drawer-card"
						data-testid="nvoos-pro-spa-okf-bundle"
						onClick={ () => onOpenBundle( bundle.name ) }
					>
						<span className="nvoos-pro-spa-okf-drawer-card-icon" aria-hidden="true">
							{ bundle.protected ? '🔒' : '📚' }
						</span>
						<span className="nvoos-pro-spa-okf-drawer-card-text">
							<span className="nvoos-pro-spa-okf-drawer-card-label">
								{ bundle.name }
							</span>
							<span className="nvoos-pro-spa-okf-drawer-card-desc">
								{ sprintf(
									/* translators: 1: concept count, 2: stale count, 3: deprecated count */
									__( '%1$d concepts · %2$d stale · %3$d deprecated', 'nvoos-pro-spa' ),
									bundle.concept_count,
									bundle.stale_count,
									bundle.deprecated_count
								) }
								{ bundle.types.length > 0 &&
									` · ${ bundle.types.slice( 0, 3 ).join( ', ' ) }` }
							</span>
						</span>
					</button>
				</li>
			) ) }
		</ul>
	);
}

// ── Concept list ─────────────────────────────────────────────────────────────

interface ConceptListProps {
	concepts: OkfConceptSummary[] | null;
	isLoading: boolean;
	error: string | null;
	bundle: string;
	onBack: () => void;
	onOpenConcept: ( bundle: string, conceptId: string ) => void;
}

function ConceptList( {
	concepts,
	isLoading,
	error,
	bundle,
	onBack,
	onOpenConcept,
}: ConceptListProps ): JSX.Element {
	return (
		<>
			<button
				type="button"
				className="nvoos-pro-spa-okf-drawer-back"
				onClick={ onBack }
			>
				← { __( 'All bundles', 'nvoos-pro-spa' ) }
			</button>

			<h3 className="nvoos-pro-spa-okf-drawer-section-title">{ bundle }</h3>

			{ isLoading && (
				<div className="nvoos-pro-spa-okf-drawer-loading">{ __( 'Loading…', 'nvoos-pro-spa' ) }</div>
			) }

			{ error && <ErrorState message={ error } /> }

			{ ! isLoading && ! error && concepts?.length === 0 && (
				<div className="nvoos-pro-spa-okf-drawer-empty">
					{ __( 'This bundle has no concepts.', 'nvoos-pro-spa' ) }
				</div>
			) }

			<ul className="nvoos-pro-spa-okf-drawer-list">
				{ concepts?.map( ( c ) => (
					<li key={ c.concept_id }>
						<button
							type="button"
							className="nvoos-pro-spa-okf-drawer-card"
							data-testid="nvoos-pro-spa-okf-concept"
							onClick={ () => onOpenConcept( bundle, c.concept_id ) }
						>
							<span className="nvoos-pro-spa-okf-drawer-card-text">
								<span className="nvoos-pro-spa-okf-drawer-card-label">
									{ c.title || c.concept_id }
								</span>
								{ c.description && (
									<span className="nvoos-pro-spa-okf-drawer-card-desc">
										{ c.description }
									</span>
								) }
								<ConceptBadges
									trustTier={ c.trust_tier }
									status={ c.status }
									stale={ c.stale }
								/>
							</span>
						</button>
					</li>
				) ) }
			</ul>
		</>
	);
}

// ── Search results ───────────────────────────────────────────────────────────

interface SearchResultsProps {
	results: OkfSearchResult[] | null;
	isLoading: boolean;
	error: string | null;
	query: string;
	onOpenConcept: ( bundle: string, conceptId: string ) => void;
}

function SearchResults( {
	results,
	isLoading,
	error,
	query,
	onOpenConcept,
}: SearchResultsProps ): JSX.Element {
	if ( isLoading ) {
		return <div className="nvoos-pro-spa-okf-drawer-loading">{ __( 'Searching…', 'nvoos-pro-spa' ) }</div>;
	}

	if ( error ) {
		return <ErrorState message={ error } />;
	}

	if ( results && results.length === 0 ) {
		return (
			<div className="nvoos-pro-spa-okf-drawer-empty">
				{ sprintf(
					/* translators: %s: search query */
					__( 'No knowledge matches "%s".', 'nvoos-pro-spa' ),
					query
				) }
			</div>
		);
	}

	return (
		<ul className="nvoos-pro-spa-okf-drawer-list">
			{ results?.map( ( r ) => (
				<li key={ `${ r.bundle }:${ r.concept_id }` }>
					<button
						type="button"
						className="nvoos-pro-spa-okf-drawer-card"
						data-testid="nvoos-pro-spa-okf-search-result"
						onClick={ () => onOpenConcept( r.bundle, r.concept_id ) }
					>
						<span className="nvoos-pro-spa-okf-drawer-card-text">
							<span className="nvoos-pro-spa-okf-drawer-card-label">
								{ r.title || r.concept_id }
							</span>
							<span className="nvoos-pro-spa-okf-drawer-card-desc">
								{ r.bundle }:{ r.concept_id }
								{ r.description && ` — ${ r.description }` }
							</span>
							<ConceptBadges
								trustTier={ r.trust_tier }
								status={ r.status }
								stale={ r.stale }
							/>
						</span>
					</button>
				</li>
			) ) }
		</ul>
	);
}

// ── Concept detail ───────────────────────────────────────────────────────────

interface ConceptDetailProps {
	concept: OkfConceptDetail;
	onBack: () => void;
	onAskAbout: ( bundle: string, conceptId: string, e?: React.MouseEvent ) => void;
	onOpenConcept: ( bundle: string, conceptId: string ) => void;
}

function ConceptDetail( {
	concept,
	onBack,
	onAskAbout,
	onOpenConcept,
}: ConceptDetailProps ): JSX.Element {
	return (
		<div className="nvoos-pro-spa-okf-drawer-detail" data-testid="nvoos-pro-spa-okf-detail">
			<button type="button" className="nvoos-pro-spa-okf-drawer-back" onClick={ onBack }>
				← { __( 'Back', 'nvoos-pro-spa' ) }
			</button>

			<h3 className="nvoos-pro-spa-okf-drawer-detail-title">
				{ concept.frontmatter.title || concept.concept_id }
			</h3>

			<p className="nvoos-pro-spa-okf-drawer-detail-ref">
				{ concept.bundle }:{ concept.concept_id }
			</p>

			<ConceptBadges
				trustTier={ concept.trust_tier }
				status={ concept.frontmatter.status || 'stable' }
				stale={ concept.stale }
			/>

			{ concept.frontmatter.description && (
				<p className="nvoos-pro-spa-okf-drawer-detail-desc">
					{ concept.frontmatter.description }
				</p>
			) }

			<div className="nvoos-pro-spa-okf-drawer-detail-actions">
				<button
					type="button"
					className="nvoos-pro-spa-btn nvoos-pro-spa-btn--small"
					onClick={ ( e ) => onAskAbout( concept.bundle, concept.concept_id, e ) }
					title={ __( 'Shift+Click to submit immediately', 'nvoos-pro-spa' ) }
				>
					{ __( 'Ask the assistant', 'nvoos-pro-spa' ) }
				</button>
			</div>

			<div className="nvoos-pro-spa-okf-drawer-detail-body">
				<MarkdownContent content={ concept.body } />
			</div>

			{ concept.links.length > 0 && (
				<div className="nvoos-pro-spa-okf-drawer-detail-links">
					<h4 className="nvoos-pro-spa-okf-drawer-section-title">
						{ __( 'Related concepts', 'nvoos-pro-spa' ) }
					</h4>
					<ul className="nvoos-pro-spa-okf-drawer-list">
						{ concept.links.map( ( link ) => (
							<li key={ link }>
								<button
									type="button"
									className="nvoos-pro-spa-okf-drawer-card nvoos-pro-spa-okf-drawer-card--link"
									onClick={ () => onOpenConcept( concept.bundle, link ) }
								>
									<span className="nvoos-pro-spa-okf-drawer-card-text">
										<span className="nvoos-pro-spa-okf-drawer-card-label">
											{ link }
										</span>
									</span>
								</button>
							</li>
						) ) }
					</ul>
				</div>
			) }
		</div>
	);
}

// ── Skills tab ───────────────────────────────────────────────────────────────

interface SkillsTabProps {
	skills: OkfSkill[] | null;
	isLoading: boolean;
	error: string | null;
	assistantId: number | string;
	onLoadSkill: ( skill: OkfSkill, e: React.MouseEvent ) => void;
}

function SkillsTab( { skills, isLoading, error, assistantId, onLoadSkill }: SkillsTabProps ): JSX.Element {
	if ( isLoading ) {
		return <div className="nvoos-pro-spa-okf-drawer-loading">{ __( 'Loading…', 'nvoos-pro-spa' ) }</div>;
	}

	if ( error ) {
		return <ErrorState message={ error } />;
	}

	if ( ! assistantId ) {
		return (
			<div className="nvoos-pro-spa-okf-drawer-empty">
				{ __( 'Select an assistant to browse its skills.', 'nvoos-pro-spa' ) }
			</div>
		);
	}

	if ( skills && skills.length === 0 ) {
		return (
			<div className="nvoos-pro-spa-okf-drawer-empty">
				{ __(
					'No OKF concepts are granted to this assistant yet. Grant concepts from the assistant editor’s OKF Knowledge Concepts box.',
					'nvoos-pro-spa'
				) }
			</div>
		);
	}

	return (
		<ul className="nvoos-pro-spa-okf-drawer-list">
			{ skills?.map( ( skill ) => (
				<li key={ skill.name }>
					<div className="nvoos-pro-spa-okf-drawer-card" data-testid="nvoos-pro-spa-okf-skill">
						<span className="nvoos-pro-spa-okf-drawer-card-icon" aria-hidden="true">
							{ skill.loadable ? '🛠' : '⛔' }
						</span>
						<span className="nvoos-pro-spa-okf-drawer-card-text">
							<span className="nvoos-pro-spa-okf-drawer-card-label">
								{ skill.title }
							</span>
							<span className="nvoos-pro-spa-okf-drawer-card-desc">
								{ skill.description }
							</span>
							<ConceptBadges
								trustTier={ skill.trust_tier }
								status={ skill.status }
								stale={ skill.stale }
							/>
							{ ! skill.loadable && skill.error && (
								<span className="nvoos-pro-spa-okf-drawer-card-error">
									{ skill.error }
								</span>
							) }
						</span>
						{ skill.loadable && (
							<button
								type="button"
								className="nvoos-pro-spa-btn nvoos-pro-spa-btn--small"
								onClick={ ( e ) => onLoadSkill( skill, e ) }
								title={ __( 'Inserts a load_skill request; Shift+Click to insert without submitting', 'nvoos-pro-spa' ) }
							>
								{ __( 'Load', 'nvoos-pro-spa' ) }
							</button>
						) }
					</div>
				</li>
			) ) }
		</ul>
	);
}

// ── Shared bits ──────────────────────────────────────────────────────────────

interface ConceptBadgesProps {
	trustTier: string;
	status: string;
	stale: boolean;
}

function ConceptBadges( { trustTier, status, stale }: ConceptBadgesProps ): JSX.Element {
	return (
		<span className="nvoos-pro-spa-okf-drawer-badges">
			<span
				className={ `nvoos-pro-spa-okf-badge nvoos-pro-spa-okf-badge--trust-${ trustTier }` }
				title={ __( 'Trust tier', 'nvoos-pro-spa' ) }
			>
				{ trustSymbol( trustTier ) } { trustLabel( trustTier ) }
			</span>
			<span
				className={ `nvoos-pro-spa-okf-badge nvoos-pro-spa-okf-badge--status-${ status }` }
			>
				{ statusLabel( status ) }
			</span>
			{ stale && (
				<span className="nvoos-pro-spa-okf-badge nvoos-pro-spa-okf-badge--stale">
					{ __( 'stale', 'nvoos-pro-spa' ) }
				</span>
			) }
		</span>
	);
}

interface ErrorStateProps {
	message: string;
	onRetry?: () => void;
}

function ErrorState( { message, onRetry }: ErrorStateProps ): JSX.Element {
	return (
		<div className="nvoos-pro-spa-okf-drawer-error">
			<p>{ message }</p>
			{ onRetry && (
				<button
					type="button"
					className="nvoos-pro-spa-btn nvoos-pro-spa-btn--small"
					onClick={ onRetry }
				>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			) }
		</div>
	);
}
