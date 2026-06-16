/**
 * Pro SPA v2 — ModelComparisonView.
 *
 * Side-by-side model response comparison panel. Fetches available model
 * alternatives, runs a comparison across them, and lets the user cycle
 * through results with a tab bar.
 *
 * Accessibility:
 *   - role="dialog" + aria-modal="true" for screen-reader recognition.
 *   - ESC key closes the panel.
 *   - Tab and close buttons include explicit aria-labels.
 *
 * @since 2.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback, type JSX } from 'react';

// ── Types ─────────────────────────────────────────────────────────────────────

export interface ModelAlternative {
	provider: string;
	model: string;
}

export interface ModelComparisonResult {
	provider: string;
	model: string;
	content: string;
	time_ms?: number;
	error?: string;
}

export interface ModelComparisonViewProps {
	/** Base URL for the model-comparison REST endpoints. */
	endpoint: string;
	/** WordPress REST nonce. */
	nonce: string;
	/** Active thread ID. */
	threadId?: number;
	/** User message to compare responses for. */
	message?: string;
	/** Called when user selects a result. */
	onSelect?: ( result: ModelComparisonResult ) => void;
	/** Called when user closes the panel. */
	onClose?: () => void;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build request headers shared by all fetch calls.
 */
function headers( nonce: string ): Record< string, string > {
	const h: Record< string, string > = {
		Accept: 'application/json',
	};
	if ( nonce ) {
		h[ 'X-WP-Nonce' ] = nonce;
	}
	return h;
}

// ── Component ─────────────────────────────────────────────────────────────────

/**
 * Modal panel for comparing model responses.
 *
 * @param props           - Component properties.
 * @param props.endpoint  - Base URL for model-comparison REST endpoints.
 * @param props.nonce     - WordPress REST nonce.
 * @param props.threadId  - Active thread ID.
 * @param props.message   - User message to compare responses for.
 * @param props.onSelect  - Called when user selects a result.
 * @param props.onClose   - Called when user closes the panel.
 *
 * @returns The rendered component.
 */
export function ModelComparisonView( {
	endpoint,
	nonce,
	threadId,
	message,
	onSelect,
	onClose,
}: ModelComparisonViewProps ): JSX.Element {
	const [ results, setResults ] = useState< ModelComparisonResult[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ activeIndex, setActiveIndex ] = useState( 0 );

	// ── Abort controller for fetch calls ──────────────────────────────────

	useEffect( () => {
		const controller = new AbortController();

		const base = endpoint.replace( /\/+$/, '' );
		const reqHeaders = headers( nonce );

		const fetchAlternatives = async (): Promise< ModelAlternative[] > => {
			try {
				const res = await fetch( `${ base }/model-alternatives`, {
					method: 'GET',
					credentials: 'same-origin',
					headers: reqHeaders,
					signal: controller.signal,
				} );

				if ( ! res.ok ) {
					return [];
				}

				const body = ( await res.json() ) as {
					success?: boolean;
					data?: { alternatives?: ModelAlternative[] };
				};

				if ( body.success && body.data?.alternatives ) {
					return body.data.alternatives;
				}

				return [];
			} catch {
				return [];
			}
		};

		const runComparison = async ( alternatives: ModelAlternative[] ) => {
			setLoading( true );
			setError( '' );

			try {
				const res = await fetch(
					`${ base }/threads/${ threadId ?? 0 }/compare-models`,
					{
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							...reqHeaders,
							'Content-Type': 'application/json',
						},
						body: JSON.stringify( { message, models: alternatives } ),
						signal: controller.signal,
					}
				);

				if ( ! res.ok ) {
					setError(
						sprintf(
							/* translators: %d: HTTP status code */
							__(
								'Comparison request failed (status %d).',
								'nvoos-pro-spa'
							),
							res.status
						)
					);
					return;
				}

				const body = ( await res.json() ) as {
					success?: boolean;
					data?: { results?: ModelComparisonResult[] };
					message?: string;
				};

				if ( body.success && body.data?.results ) {
					setResults( body.data.results );
				} else {
					setError(
						body.message ||
							__( 'Comparison failed.', 'nvoos-pro-spa' )
					);
				}
			} catch ( err ) {
				if ( ! controller.signal.aborted ) {
					setError(
						err instanceof Error
							? err.message
							: __( 'Comparison failed.', 'nvoos-pro-spa' )
					);
				}
			} finally {
				if ( ! controller.signal.aborted ) {
					setLoading( false );
				}
			}
		};

		const init = async () => {
			const alternatives = await fetchAlternatives();
			if ( controller.signal.aborted ) return;
			if ( alternatives.length === 0 ) {
				setError( __( 'No model alternatives available.', 'nvoos-pro-spa' ) );
				setLoading( false );
				return;
			}
			await runComparison( alternatives );
		};

		void init();

		return () => {
			controller.abort();
		};
	}, [ endpoint, nonce, threadId, message ] );

	// ── ESC key handler ───────────────────────────────────────────────────

	const handleKeyDown = useCallback(
		( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				onClose?.();
			}
		},
		[ onClose ]
	);

	useEffect( () => {
		document.addEventListener( 'keydown', handleKeyDown );
		return () => document.removeEventListener( 'keydown', handleKeyDown );
	}, [ handleKeyDown ] );

	// ── Callbacks ─────────────────────────────────────────────────────────

	const handleSelect = useCallback(
		( result: ModelComparisonResult ) => {
			onSelect?.( result );
			onClose?.();
		},
		[ onSelect, onClose ]
	);

	// ── Loading state ─────────────────────────────────────────────────────

	if ( loading ) {
		return (
			<div
				className="nvoos-pro-spa-model-compare nvoos-pro-spa-model-compare--loading"
				role="dialog"
				aria-modal="true"
				aria-label={ __( 'Model Comparison', 'nvoos-pro-spa' ) }
				aria-busy="true"
			>
				<div className="nvoos-pro-spa-model-compare__spinner" />
				<p>{ __( 'Comparing models…', 'nvoos-pro-spa' ) }</p>
			</div>
		);
	}

	// ── Error state ───────────────────────────────────────────────────────

	if ( error ) {
		return (
			<div
				className="nvoos-pro-spa-model-compare"
				role="dialog"
				aria-modal="true"
				aria-label={ __( 'Model Comparison', 'nvoos-pro-spa' ) }
			>
				<div className="nvoos-pro-spa-model-compare__error">
					<p>{ error }</p>
					<button
						type="button"
						onClick={ onClose }
						className="nvoos-pro-spa-btn"
						aria-label={ __( 'Close comparison', 'nvoos-pro-spa' ) }
					>
						{ __( 'Close', 'nvoos-pro-spa' ) }
					</button>
				</div>
			</div>
		);
	}

	// ── Empty results state ───────────────────────────────────────────────

	if ( results.length === 0 ) {
		return (
			<div
				className="nvoos-pro-spa-model-compare"
				role="dialog"
				aria-modal="true"
				aria-label={ __( 'Model Comparison', 'nvoos-pro-spa' ) }
			>
				<div className="nvoos-pro-spa-model-compare__header">
					<h3>{ __( 'Model Comparison', 'nvoos-pro-spa' ) }</h3>
					<button
						type="button"
						onClick={ onClose }
						className="nvoos-pro-spa-btn nvoos-pro-spa-btn--icon"
						aria-label={ __( 'Close comparison', 'nvoos-pro-spa' ) }
					>
						&times;
					</button>
				</div>
				<p className="nvoos-pro-spa-model-compare__empty">
					{ __( 'No comparison results.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	// ── Results state ─────────────────────────────────────────────────────

	const activeResult = results[ activeIndex ];

	return (
		<div
			className="nvoos-pro-spa-model-compare"
			role="dialog"
			aria-modal="true"
			aria-label={ __( 'Model Comparison', 'nvoos-pro-spa' ) }
		>
			<div className="nvoos-pro-spa-model-compare__header">
				<h3>{ __( 'Model Comparison', 'nvoos-pro-spa' ) }</h3>
				<button
					type="button"
					onClick={ onClose }
					className="nvoos-pro-spa-btn nvoos-pro-spa-btn--icon"
					aria-label={ __( 'Close comparison', 'nvoos-pro-spa' ) }
				>
					&times;
				</button>
			</div>

			{/* Tab bar for switching between models */}
			<div
				className="nvoos-pro-spa-model-compare__tabs"
				role="tablist"
				aria-label={ __( 'Model results', 'nvoos-pro-spa' ) }
			>
				{ results.map( ( result, i ) => (
					<button
						key={ `${ result.provider }-${ result.model }` }
						type="button"
						className={
							'nvoos-pro-spa-model-compare__tab' +
							( i === activeIndex
								? ' nvoos-pro-spa-model-compare__tab--active'
								: '' )
						}
						role="tab"
						aria-selected={ i === activeIndex }
						aria-label={ sprintf(
							/* translators: 1: provider name, 2: model name */
							__( 'View response from %1$s %2$s', 'nvoos-pro-spa' ),
							result.provider,
							result.model
						) }
						onClick={ () => setActiveIndex( i ) }
					>
						<span className="nvoos-pro-spa-model-compare__tab-label">
							{ result.provider }/{ result.model }
						</span>
						{ result.error ? (
							<span className="nvoos-pro-spa-model-compare__tab-badge nvoos-pro-spa-model-compare__tab-badge--error">
								!
							</span>
						) : (
							<span className="nvoos-pro-spa-model-compare__tab-badge">
								{ result.time_ms ?? '—' }ms
							</span>
						) }
					</button>
				) ) }
			</div>

			{/* Active result content */}
			{ activeResult && (
				<div
					className="nvoos-pro-spa-model-compare__content"
					role="tabpanel"
					aria-label={ sprintf(
						/* translators: 1: provider name, 2: model name */
						__( 'Response from %1$s %2$s', 'nvoos-pro-spa' ),
						activeResult.provider,
						activeResult.model
					) }
				>
					<div className="nvoos-pro-spa-model-compare__meta">
						<strong>
							{ activeResult.provider } / { activeResult.model }
						</strong>
						<span className="nvoos-pro-spa-model-compare__time">
							{ activeResult.time_ms ?? '—' }ms
						</span>
					</div>

					{ activeResult.error ? (
						<div className="nvoos-pro-spa-model-compare__error-text">
							{ sprintf(
								/* translators: %s: error message */
								__( 'Error: %s', 'nvoos-pro-spa' ),
								activeResult.error
							) }
						</div>
					) : (
						<>
							<div className="nvoos-pro-spa-model-compare__text">
								{ activeResult.content }
							</div>
							<button
								type="button"
								onClick={ () => handleSelect( activeResult ) }
								className="nvoos-pro-spa-btn nvoos-pro-spa-btn--primary"
								aria-label={ sprintf(
									/* translators: 1: provider name, 2: model name */
									__(
										'Use response from %1$s %2$s',
										'nvoos-pro-spa'
									),
									activeResult.provider,
									activeResult.model
								) }
							>
								{ __( 'Use This Response', 'nvoos-pro-spa' ) }
							</button>
						</>
					) }
				</div>
			) }
		</div>
	);
}
