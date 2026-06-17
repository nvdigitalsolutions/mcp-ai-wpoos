/**
 * Pro SPA v2 — DiffReviewPanel.
 *
 * Displays a modal panel listing individual change hunks from a checkpoint
 * diff so the user can inspect before/after values before deciding to
 * restore or discard.
 *
 * Accessibility:
 *   - role="dialog" + aria-modal="true" for screen-reader recognition.
 *   - ESC key closes the panel.
 *   - Close button includes an explicit aria-label.
 *
 * @since 2.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useCallback, type JSX } from 'react';

export interface DiffChange {
	type?: string;
	name?: string;
	id?: string | number;
	before?: unknown;
	after?: unknown;
}

export interface DiffReview {
	changes?: DiffChange[];
}

export interface DiffReviewPanelProps {
	diff: DiffReview | null;
	onClose?: () => void;
}

/**
 * Modal panel for reviewing diff changes.
 *
 * @param props          - Component properties.
 * @param props.diff     - The diff review data, or null.
 * @param props.onClose  - Callback when the panel is dismissed.
 *
 * @returns The rendered component.
 */
export function DiffReviewPanel( {
	diff,
	onClose,
}: DiffReviewPanelProps ): JSX.Element | null {
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

	if ( ! diff?.changes || diff.changes.length === 0 ) {
		return (
			<div
				className="nvoos-pro-spa-diff-panel"
				role="dialog"
				aria-modal="true"
				aria-label={ __( 'Review Changes', 'nvoos-pro-spa' ) }
			>
				<div className="nvoos-pro-spa-diff-panel__header">
					<h3>{ __( 'Review Changes', 'nvoos-pro-spa' ) }</h3>
					<button
						type="button"
						onClick={ onClose }
						className="nvoos-pro-spa-btn nvoos-pro-spa-btn--icon"
						aria-label={ __( 'Close diff review', 'nvoos-pro-spa' ) }
					>
						&times;
					</button>
				</div>
				<p className="nvoos-pro-spa-diff-panel__empty">
					{ __( 'No changes detected.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	const changeCount = diff.changes.length;
	const heading =
		changeCount === 1
			? sprintf(
					/* translators: %d: number of changes */
					__( 'Review Changes (%d change)', 'nvoos-pro-spa' ),
					changeCount
			  )
			: sprintf(
					/* translators: %d: number of changes */
					__( 'Review Changes (%d changes)', 'nvoos-pro-spa' ),
					changeCount
			  );

	return (
		<div
			className="nvoos-pro-spa-diff-panel"
			role="dialog"
			aria-modal="true"
			aria-label={ __( 'Review Changes', 'nvoos-pro-spa' ) }
		>
			<div className="nvoos-pro-spa-diff-panel__header">
				<h3>{ heading }</h3>
				<button
					type="button"
					onClick={ onClose }
					className="nvoos-pro-spa-btn nvoos-pro-spa-btn--icon"
					aria-label={ __( 'Close diff review', 'nvoos-pro-spa' ) }
				>
					&times;
				</button>
			</div>
			<div className="nvoos-pro-spa-diff-panel__list">
				{ diff.changes.map( ( change, i ) => {
					const key = change.id ?? change.name ?? i;
					return (
						<div key={ key } className="nvoos-pro-spa-diff-panel__hunk">
							<div className="nvoos-pro-spa-diff-panel__hunk-header">
								<span className="nvoos-pro-spa-diff-panel__hunk-type">
									{ change.type }
								</span>
								<span className="nvoos-pro-spa-diff-panel__hunk-name">
									{ change.name || change.id }
								</span>
							</div>
							{ change.before !== change.after && (
								<div className="nvoos-pro-spa-diff-panel__hunk-diff">
									<div className="nvoos-pro-spa-diff-panel__hunk-before">
										<strong>
											{ __( 'Before:', 'nvoos-pro-spa' ) }
										</strong>
										<pre>
											{ JSON.stringify( change.before, null, 2 ) }
										</pre>
									</div>
									<div className="nvoos-pro-spa-diff-panel__hunk-after">
										<strong>
											{ __( 'After:', 'nvoos-pro-spa' ) }
										</strong>
										<pre>
											{ JSON.stringify( change.after, null, 2 ) }
										</pre>
									</div>
								</div>
							) }
						</div>
					);
				} ) }
			</div>
		</div>
	);
}
